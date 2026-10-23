/**
 * Browser relay check — does a real browser get a relay, and does media-path data flow through it?
 *
 * The PHP kit's probe (lib/ice-kit/bin/probe.php) answers "will this TURN node allocate for a
 * credential the API minted" from the API host. This answers the same question from where it matters,
 * a browser, using this kit end to end:
 *
 *   1. probeRelay()           — relay-only gathering per node: a relay candidate is proof of
 *                               reachability *and* an accepted credential;
 *   2. two relay-only peers   — connected through the TURN server, with a data channel round trip,
 *                               the selected path read back by IceDiagnostics (must be `relay`);
 *   3. an ICE restart         — onto a second, freshly minted grant, driven through
 *                               ConnectionSupervisor.requestRestart(), followed by another round
 *                               trip: what a long relayed session does when its credential renews.
 *   4. media and data         — a synthetic video track (canvas) and audio track (oscillator) must be
 *                               decoded on the far side; data-channel echo RTT; a 1 MiB bulk transfer
 *                               (Remote's file transfer / control channel). ICE_MEDIA=0 skips this.
 *
 * Nothing leaves the machine but STUN/TURN traffic to the relay: both peers are in one page.
 *
 * Usage — with grants taken from a deployed API (the `ice` of a Remote signalling-token response, or
 * Connect's GET /api/calls/config; a second one, fetched a moment later, for the restart):
 *
 *   npm install --no-save playwright
 *   ICE_GRANT=grant.json [ICE_FRESH_GRANT=grant2.json] [CHROMIUM_PATH=/path/to/chrome] \
 *     [ICE_EXPECT_NODE=mum] [ICE_EXPECT_PROTOCOL=udp|tcp|tls] \
 *     node src/ice-kit/tools/relay-check.mjs
 *
 * ICE_EXPECT_NODE / ICE_EXPECT_PROTOCOL turn "a relay carried it" into "THIS node carried it over THIS
 * transport": emit a grant for one transport on the API host (`probe.php --transports=tls
 * --emit-grant=…`) and expect that protocol here. Without them the check proves a relay, not which.
 *
 * ICE_PEER_GRANT=grant2.json gives the SECOND peer its own grant — e.g. peer A relayed by Mumbai, peer B by
 * Noida, as a Mumbai user calling a Delhi user is (each side is offered its nearest node). It also avoids
 * the single-relay limit noted in the runbook. ICE_EXPECT_PEER_NODE names the node that must carry peer B.
 *
 * ICE_TOLERATE_NODES=mum,ncr lists nodes that are EXPECTED to be unreachable (a failover test with the
 * primary deliberately down): they are left out of "every relay node allocated".
 *
 * ICE_REPORT=out.json writes the full result (no credential) for the evidence bundle.
 *
 * Exit 0 only when every step passed. Prints no credential.
 */

import { readFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { dirname, extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const KIT = dirname(dirname(fileURLToPath(import.meta.url)));
const grantFile = process.env.ICE_GRANT;
if (!grantFile) {
  console.error('ICE_GRANT must name a JSON file holding an aicountly.ice/1 grant.');
  process.exit(2);
}
const grant = JSON.parse(readFileSync(grantFile, 'utf8'));
const fresh = process.env.ICE_FRESH_GRANT ? JSON.parse(readFileSync(process.env.ICE_FRESH_GRANT, 'utf8')) : null;
const peerGrant = process.env.ICE_PEER_GRANT ? JSON.parse(readFileSync(process.env.ICE_PEER_GRANT, 'utf8')) : null;
const media = process.env.ICE_MEDIA !== '0';

let chromium;
try {
  ({ chromium } = createRequire(join(process.cwd(), 'package.json'))('playwright'));
} catch {
  console.error('Playwright is needed: npm install --no-save playwright');
  process.exit(2);
}

const PAGE = `<!doctype html><meta charset="utf-8"><script type="module">
import { ConnectionSupervisor, IceDiagnostics, LatencyMeter, applyIceConfig, normalizeIceConfig, probeRelay, rtcConfiguration } from './index.js';

const until = (promise, ms, what) => Promise.race([promise, new Promise((_, reject) => setTimeout(() => reject(new Error(what + ' timed out')), ms))]);

async function negotiate(a, b, options = {}) {
  const offer = await a.createOffer(options);
  await a.setLocalDescription(offer);
  await b.setRemoteDescription(offer);
  const answer = await b.createAnswer();
  await b.setLocalDescription(answer);
  await a.setRemoteDescription(answer);
}

function connected(pc) {
  return new Promise((resolve) => {
    const check = () => { if (pc.connectionState === 'connected') resolve(); };
    pc.addEventListener('connectionstatechange', check);
    check();
  });
}

// A moving canvas and a tone: real encoded video and audio, so "decoded on the far side" means the relay carried media.
function syntheticTracks() {
  const canvas = Object.assign(document.createElement('canvas'), { width: 320, height: 240 });
  const ctx = canvas.getContext('2d');
  let frame = 0;
  const timer = setInterval(() => {
    frame += 1;
    ctx.fillStyle = 'hsl(' + (frame * 7 % 360) + ' 70% 50%)';
    ctx.fillRect(0, 0, 320, 240);
    ctx.fillStyle = '#fff';
    ctx.fillText('relay-check ' + frame, 10 + frame % 200, 120);
  }, 66);
  const audio = new AudioContext();
  const tone = audio.createOscillator();
  const sink = audio.createMediaStreamDestination();
  tone.connect(sink);
  tone.start();
  const stream = new MediaStream([...canvas.captureStream(15).getVideoTracks(), ...sink.stream.getAudioTracks()]);
  return { stream, stop: () => { clearInterval(timer); stream.getTracks().forEach((t) => t.stop()); audio.close(); } };
}

async function inbound(pc) {
  const out = { video: { frames: 0, bytes: 0 }, audio: { bytes: 0 } };
  (await pc.getStats()).forEach((s) => {
    if (s.type !== 'inbound-rtp') return;
    const kind = s.kind ?? s.mediaType;
    if (kind === 'video') { out.video.frames += s.framesDecoded ?? 0; out.video.bytes += s.bytesReceived ?? 0; }
    if (kind === 'audio') { out.audio.bytes += s.bytesReceived ?? 0; }
  });
  return out;
}

window.runRelayCheck = async (rawGrant, rawFresh, rawPeer, withMedia) => {
  const result = {};
  const config = normalizeIceConfig(rawGrant);
  const peerConfig = rawPeer ? normalizeIceConfig(rawPeer) : null;
  result.relayAvailable = config.relayAvailable && (!peerConfig || peerConfig.relayAvailable);
  result.probe = await probeRelay(config, { timeoutMs: 10000 });
  if (peerConfig) result.peerProbe = await probeRelay(peerConfig, { timeoutMs: 10000 });

  const rtc = rtcConfiguration(config, { iceTransportPolicy: 'relay' });
  const a = new RTCPeerConnection(rtc);
  const b = new RTCPeerConnection(peerConfig ? rtcConfiguration(peerConfig, { iceTransportPolicy: 'relay' }) : rtc);
  a.addEventListener('icecandidate', (e) => { if (e.candidate) b.addIceCandidate(e.candidate).catch(() => {}); });
  b.addEventListener('icecandidate', (e) => { if (e.candidate) a.addIceCandidate(e.candidate).catch(() => {}); });
  const diagnostics = new IceDiagnostics(a, { config });
  const peerDiagnostics = new IceDiagnostics(b, { config: peerConfig ?? config });
  const tracks = withMedia ? syntheticTracks() : null;
  if (tracks) tracks.stream.getTracks().forEach((t) => a.addTrack(t, tracks.stream));

  const channel = a.createDataChannel('relay-check');
  const inbox = [];
  const waiters = [];
  let bulkBytes = 0;
  b.addEventListener('datachannel', (event) => {
    event.channel.onmessage = (m) => {
      if (typeof m.data !== 'string') { bulkBytes += m.data.byteLength ?? m.data.size ?? 0; waiters.splice(0).forEach((w) => w()); return; }
      if (m.data.startsWith('echo:')) { event.channel.send(m.data); return; }
      inbox.push(m.data); waiters.splice(0).forEach((w) => w());
    };
  });
  const nextMessage = () => inbox.length ? Promise.resolve(inbox.shift()) : new Promise((r) => waiters.push(() => r(inbox.shift())));

  try {
    await negotiate(a, b);
    await until(connected(a), 15000, 'relay-only connection');
    await until(new Promise((r) => { if (channel.readyState === 'open') r(); else channel.onopen = r; }), 10000, 'data channel');
    channel.send('ping-1');
    result.firstMessage = await until(nextMessage(), 10000, 'first message');
    result.path = await diagnostics.selectedPath();
    result.peerPath = await peerDiagnostics.selectedPath();

    if (withMedia) {
      // Echo round trips through the relay(s), as a control message (Remote's input channel) experiences them.
      const rtts = [];
      for (let i = 0; i < 5; i += 1) {
        const t0 = performance.now();
        const back = new Promise((r) => { channel.onmessage = (m) => r(m.data); });
        channel.send('echo:' + i);
        await until(back, 5000, 'echo ' + i);
        rtts.push(Math.round(performance.now() - t0));
      }
      result.echoRttMs = rtts;

      // 1 MiB in 16 KiB chunks, honouring the send buffer: file transfer over the relay.
      const chunk = new Uint8Array(16384);
      channel.bufferedAmountLowThreshold = 262144;
      const t0 = performance.now();
      for (let sent = 0; sent < 64; sent += 1) {
        if (channel.bufferedAmount > 1048576) await new Promise((r) => { channel.onbufferedamountlow = r; });
        channel.send(chunk);
      }
      await until(new Promise((r) => { const check = () => bulkBytes >= 1048576 ? r() : waiters.push(check); check(); }), 30000, 'bulk transfer');
      const seconds = (performance.now() - t0) / 1000;
      result.bulk = { bytes: bulkBytes, seconds: Math.round(seconds * 100) / 100, mbps: Math.round((8 * bulkBytes / 1e6 / seconds) * 10) / 10 };

      await new Promise((r) => setTimeout(r, 6000));
      result.media = await inbound(b);
    }

    if (rawFresh) {
      const freshConfig = normalizeIceConfig(rawFresh);
      result.applied = applyIceConfig(a, freshConfig) && applyIceConfig(b, freshConfig);
      diagnostics.setConfig(freshConfig);
      const supervisor = new ConnectionSupervisor({ pc: a, network: null, restart: () => negotiate(a, b, { iceRestart: true }) });
      await supervisor.requestRestart('credential_refresh');
      await new Promise((r) => setTimeout(r, 3000));
      await until(connected(a), 15000, 'connection after ICE restart');
      channel.send('ping-2');
      result.secondMessage = await until(nextMessage(), 10000, 'message after restart');
      result.pathAfterRestart = await diagnostics.selectedPath();
      supervisor.dispose();
    }
  } catch (error) {
    result.error = String(error.message || error);
  }

  // How long each served node takes to hand this browser a relay: the figure sent back as rtt.
  try {
    result.latency = await new LatencyMeter({ storage: null, samples: 1, timeoutMs: 8000 }).measure(config);
  } catch (error) {
    result.latencyError = String(error.message || error);
  }

  result.verdict = diagnostics.verdict();
  result.iceErrors = diagnostics.iceErrors();
  result.gathered = diagnostics.gathered;
  diagnostics.dispose();
  peerDiagnostics.dispose();
  tracks?.stop();
  a.close();
  b.close();
  return result;
};
window.ready = true;
</script>`;

const server = createServer((req, res) => {
  const path = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  if (path === '/') {
    res.writeHead(200, { 'content-type': 'text/html' }).end(PAGE);
    return;
  }
  const file = normalize(join(KIT, path));
  if (!file.startsWith(KIT) || extname(file) !== '.js') {
    res.writeHead(404).end();
    return;
  }
  try {
    res.writeHead(200, { 'content-type': 'text/javascript' }).end(readFileSync(file));
  } catch {
    res.writeHead(404).end();
  }
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;

const browser = await chromium.launch({
  ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}),
  args: ['--no-sandbox', '--autoplay-policy=no-user-gesture-required'],
});
let result;
try {
  const page = await browser.newPage();
  await page.goto(origin);
  await page.waitForFunction(() => window.ready === true);
  result = await page.evaluate(([g, f, p, m]) => window.runRelayCheck(g, f, p, m), [grant, fresh, peerGrant, media]);
} finally {
  await browser.close();
  server.close();
}

const tolerated = new Set((process.env.ICE_TOLERATE_NODES ?? '').split(',').map((id) => id.trim()).filter(Boolean));
const expectNode = process.env.ICE_EXPECT_NODE;
const expectProtocol = process.env.ICE_EXPECT_PROTOCOL;
const expectPeerNode = process.env.ICE_EXPECT_PEER_NODE;

const checks = [
  ['the API grant names a relay', result.relayAvailable === true],
  ['every relay node allocated for this browser', result.probe.ok && result.probe.nodes.filter((n) => !tolerated.has(n.id)).every((n) => n.relayCandidates > 0)],
  ['two relay-only peers connected and exchanged data', result.firstMessage === 'ping-1'],
  ['the selected path is the relay', result.path?.path === 'relay'],
  ...(expectNode ? [[`the relay that carried it was node '${expectNode}'`, result.path?.relayNode === expectNode]] : []),
  ...(expectProtocol ? [[`over ${expectProtocol}`, result.path?.relayProtocol === expectProtocol]] : []),
  ...(peerGrant ? [['the second peer allocated on its own relay', result.peerProbe?.ok === true && result.peerProbe.nodes.every((n) => n.relayCandidates > 0)]] : []),
  ...(expectPeerNode ? [[`the second peer was carried by node '${expectPeerNode}'`, result.peerPath?.relayNode === expectPeerNode]] : []),
  ...(media ? [
    ['video was decoded on the far side (frames through the relay)', (result.media?.video.frames ?? 0) >= 30],
    ['audio arrived on the far side', (result.media?.audio.bytes ?? 0) > 0],
    ['data-channel echo round trips completed', (result.echoRttMs ?? []).length === 5],
    ['1 MiB bulk transfer completed', (result.bulk?.bytes ?? 0) >= 1048576],
  ] : []),
  ['the client could time every reachable node (the `rtt` it sends back)', Object.keys(result.latency ?? {}).length === result.probe.nodes.filter((n) => !tolerated.has(n.id)).length && Object.values(result.latency ?? {}).every((ms) => ms > 0)],
  ...(fresh ? [
    ['fresh credentials were applied to both peers', result.applied === true],
    ['after an ICE restart onto them, data still flows', result.secondMessage === 'ping-2'],
    ['and still through the relay', result.pathAfterRestart?.path === 'relay'],
  ] : []),
];

for (const node of result.probe.nodes) {
  console.log(`node ${node.id} (${node.label}): ${node.relayCandidates} relay candidate(s) over ${node.protocols.join(', ') || '-'}${node.errors.length ? `; errors: ${node.errors.join(', ')}` : ''}`);
}
if (result.path) console.log(`path: ${result.path.path} via ${result.path.relayNode ?? '?'} (${result.path.relayProtocol ?? '?'}), rtt ${result.path.rttMs ?? '?'} ms`);
if (peerGrant && result.peerPath) console.log(`peer path: ${result.peerPath.path} via ${result.peerPath.relayNode ?? '?'} (${result.peerPath.relayProtocol ?? '?'})`);
if (result.media) console.log(`media: video ${result.media.video.frames} frames / ${result.media.video.bytes} B, audio ${result.media.audio.bytes} B`);
if (result.echoRttMs) console.log(`echo rtt ms: ${result.echoRttMs.join(', ')}`);
if (result.bulk) console.log(`bulk: ${result.bulk.bytes} B in ${result.bulk.seconds} s = ${result.bulk.mbps} Mbit/s`);
if (result.latency) console.log(`measured: ${Object.entries(result.latency).map(([id, ms]) => `${id} ${ms} ms`).join(', ') || 'nothing'}`);
if (result.pathAfterRestart) console.log(`after restart: ${JSON.stringify(result.pathAfterRestart)}`);
if (result.error) console.log(`error: ${result.error}`);
if (result.iceErrors.length) console.log(`ice errors: ${result.iceErrors.map((e) => `${e.category}${e.node ? `@${e.node}` : ''}×${e.count}`).join(', ')}`);

if (process.env.ICE_REPORT) {
  const { writeFileSync } = await import('node:fs');
  writeFileSync(process.env.ICE_REPORT, JSON.stringify({
    at: new Date().toISOString(),
    expectNode: expectNode ?? null,
    expectProtocol: expectProtocol ?? null,
    result,
    checks: checks.map(([label, ok]) => ({ label, ok })),
  }, null, 2));
}

let failed = 0;
for (const [label, ok] of checks) {
  console.log(`${ok ? 'PASS' : 'FAIL'} ${label}`);
  if (!ok) failed += 1;
}
process.exit(failed === 0 ? 0 : 1);
