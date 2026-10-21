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
 *
 * Nothing leaves the machine but STUN/TURN traffic to the relay: both peers are in one page.
 *
 * Usage — with grants taken from a deployed API (the `ice` of a Remote signalling-token response, or
 * Connect's GET /api/calls/config; a second one, fetched a moment later, for the restart):
 *
 *   npm install --no-save playwright
 *   ICE_GRANT=grant.json [ICE_FRESH_GRANT=grant2.json] [CHROMIUM_PATH=/path/to/chrome] \
 *     node src/ice-kit/tools/relay-check.mjs
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

let chromium;
try {
  ({ chromium } = createRequire(join(process.cwd(), 'package.json'))('playwright'));
} catch {
  console.error('Playwright is needed: npm install --no-save playwright');
  process.exit(2);
}

const PAGE = `<!doctype html><meta charset="utf-8"><script type="module">
import { ConnectionSupervisor, IceDiagnostics, applyIceConfig, normalizeIceConfig, probeRelay, rtcConfiguration } from './index.js';

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

window.runRelayCheck = async (rawGrant, rawFresh) => {
  const result = {};
  const config = normalizeIceConfig(rawGrant);
  result.relayAvailable = config.relayAvailable;
  result.probe = await probeRelay(config, { timeoutMs: 10000 });

  const rtc = rtcConfiguration(config, { iceTransportPolicy: 'relay' });
  const a = new RTCPeerConnection(rtc);
  const b = new RTCPeerConnection(rtc);
  a.addEventListener('icecandidate', (e) => { if (e.candidate) b.addIceCandidate(e.candidate).catch(() => {}); });
  b.addEventListener('icecandidate', (e) => { if (e.candidate) a.addIceCandidate(e.candidate).catch(() => {}); });
  const diagnostics = new IceDiagnostics(a, { config });

  const channel = a.createDataChannel('relay-check');
  const inbox = [];
  const waiters = [];
  b.addEventListener('datachannel', (event) => {
    event.channel.onmessage = (m) => { inbox.push(m.data); waiters.splice(0).forEach((w) => w()); };
  });
  const nextMessage = () => inbox.length ? Promise.resolve(inbox.shift()) : new Promise((r) => waiters.push(() => r(inbox.shift())));

  try {
    await negotiate(a, b);
    await until(connected(a), 15000, 'relay-only connection');
    await until(new Promise((r) => { if (channel.readyState === 'open') r(); else channel.onopen = r; }), 10000, 'data channel');
    channel.send('ping-1');
    result.firstMessage = await until(nextMessage(), 10000, 'first message');
    result.path = await diagnostics.selectedPath();

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

  result.verdict = diagnostics.verdict();
  result.iceErrors = diagnostics.iceErrors();
  result.gathered = diagnostics.gathered;
  diagnostics.dispose();
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
  args: ['--no-sandbox'],
});
let result;
try {
  const page = await browser.newPage();
  await page.goto(origin);
  await page.waitForFunction(() => window.ready === true);
  result = await page.evaluate(([g, f]) => window.runRelayCheck(g, f), [grant, fresh]);
} finally {
  await browser.close();
  server.close();
}

const checks = [
  ['the API grant names a relay', result.relayAvailable === true],
  ['every relay node allocated for this browser', result.probe.ok && result.probe.nodes.every((n) => n.relayCandidates > 0)],
  ['two relay-only peers connected and exchanged data', result.firstMessage === 'ping-1'],
  ['the selected path is the relay', result.path?.path === 'relay'],
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
if (result.pathAfterRestart) console.log(`after restart: ${JSON.stringify(result.pathAfterRestart)}`);
if (result.error) console.log(`error: ${result.error}`);
if (result.iceErrors.length) console.log(`ice errors: ${result.iceErrors.map((e) => `${e.category}${e.node ? `@${e.node}` : ''}×${e.count}`).join(', ')}`);

let failed = 0;
for (const [label, ok] of checks) {
  console.log(`${ok ? 'PASS' : 'FAIL'} ${label}`);
  if (!ok) failed += 1;
}
process.exit(failed === 0 ? 0 : 1);
