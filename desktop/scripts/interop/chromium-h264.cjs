#!/usr/bin/env node
// ---------------------------------------------------------------------------
// Browser interop for the agent's screen track: does a real Chromium decode
// the H.264 the desktop agent sends?
//
// The agent side is the Rust test `crates/remote-webrtc/tests/browser_interop.rs`,
// which starts a tiny HTTP server, streams a synthetic moving pattern through
// the real `WebRtcSession`, and runs this script against it. Run it from
// there, not on its own:
//
//   cd desktop
//   cargo test -p remote-webrtc --release --test browser_interop -- --ignored --nocapture --test-threads=1
//
// What this does, in a headless Chromium:
//
//   * negotiates with the agent through the server — the agent's offer or
//     Chromium's, as the test asks — and trickles ICE candidates both ways,
//     exactly as the signalling relay would carry them;
//   * plays the received track in a <video>;
//   * waits for frames to decode, then reports `RTCPeerConnection.getStats()`
//     for the inbound video, the <video>'s own size, and four colour bars
//     sampled off the decoded picture through a canvas.
//
// It passes when frames decoded, the codec is H.264, the <video> is the size
// the agent should have encoded at, and the bars are the colours the agent
// drew — which is the end-to-end check on the BT.601 conversion and on the
// channel order a BGRA capture comes in.
//
// Chromium is launched with `--disable-features=WebRtcHideLocalIpsWithMdns`
// so its host candidates are real addresses the agent can reach rather than
// `.local` names it would have to resolve.
//
// Prints progress on stderr and one line `INTEROP_RESULT <json>` on stdout.
// Exits 0 on a pass, 1 on a failure, 2 if the harness itself broke.
// ---------------------------------------------------------------------------

'use strict';

const { chromium } = require('playwright');

function parseArguments(argv) {
  const options = {
    url: null,
    mode: 'agent-offers',
    width: 0,
    height: 0,
    minFrames: 30,
    timeoutMs: 60_000,
  };

  for (let index = 0; index < argv.length; index += 2) {
    const [flag, value] = [argv[index], argv[index + 1]];
    switch (flag) {
      case '--url': options.url = value; break;
      case '--mode': options.mode = value; break;
      case '--width': options.width = Number(value); break;
      case '--height': options.height = Number(value); break;
      case '--min-frames': options.minFrames = Number(value); break;
      case '--timeout-ms': options.timeoutMs = Number(value); break;
      default: throw new Error(`unknown argument ${flag}`);
    }
  }

  if (!options.url || !options.width || !options.height) {
    throw new Error('usage: chromium-h264.cjs --url URL --mode agent-offers|browser-offers --width W --height H');
  }
  if (!['agent-offers', 'browser-offers'].includes(options.mode)) {
    throw new Error(`unknown mode ${options.mode}`);
  }

  return options;
}

// Runs inside the page. Everything it needs comes in through `options`;
// everything it learns goes back as the resolved value.
async function inPage(options) {
  const log = (message) => console.log(`[page] ${message}`);
  const post = (path, body) => fetch(path, { method: 'POST', body: JSON.stringify(body) });

  const pc = new RTCPeerConnection({ iceServers: [] });
  const video = document.createElement('video');
  video.muted = true;
  video.autoplay = true;
  video.playsInline = true;
  document.body.appendChild(video);

  pc.onicecandidate = (event) => {
    if (event.candidate) post('/candidate', event.candidate.toJSON());
  };
  pc.onconnectionstatechange = () => log(`connection ${pc.connectionState}`);
  pc.ontrack = (event) => {
    log(`track ${event.track.kind}`);
    video.srcObject = event.streams[0] || new MediaStream([event.track]);
    video.play().catch((error) => log(`play() refused: ${error}`));
  };

  if (options.mode === 'browser-offers') {
    // What the web client does when it arrives first: receive-only video and
    // the collaboration channel.
    pc.addTransceiver('video', { direction: 'recvonly' });
    pc.createDataChannel('aicountly-remote', { ordered: true });
    await pc.setLocalDescription(await pc.createOffer());
    const answer = await (await post('/offer', pc.localDescription)).json();
    await pc.setRemoteDescription(answer);
  } else {
    const offer = await (await fetch('/offer')).json();
    await pc.setRemoteDescription(offer);
    await pc.setLocalDescription(await pc.createAnswer());
    await post('/answer', pc.localDescription);
  }

  const remoteSdp = pc.remoteDescription.sdp;
  const localSdp = pc.localDescription.sdp;

  let trickling = true;
  (async () => {
    while (trickling) {
      try {
        const candidates = await (await fetch('/candidates')).json();
        for (const candidate of candidates) {
          await pc.addIceCandidate(candidate).catch((error) => log(`candidate refused: ${error}`));
        }
      } catch (error) {
        log(`candidate poll failed: ${error}`);
      }
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
  })();

  async function inboundVideo() {
    const report = await pc.getStats();
    let inbound = null;
    let pair = null;
    report.forEach((entry) => {
      if (entry.type === 'inbound-rtp' && entry.kind === 'video') inbound = entry;
      if (entry.type === 'candidate-pair' && entry.nominated && entry.state === 'succeeded') pair = entry;
    });
    const codec = inbound && inbound.codecId ? report.get(inbound.codecId) : null;
    const local = pair ? report.get(pair.localCandidateId) : null;
    const remote = pair ? report.get(pair.remoteCandidateId) : null;
    return { inbound, codec, local, remote, pair };
  }

  // The bars the agent draws: eight across the top three quarters, colour
  // from the bar index's bits (1 blue, 2 green, 4 red), each channel 230 on
  // and 20 off. Sampled a tenth of the way down, clear of the moving square.
  function sampleBars() {
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const context = canvas.getContext('2d', { willReadFrequently: true });
    context.drawImage(video, 0, 0);
    const y = Math.floor(canvas.height * 0.1);
    return [0, 1, 2, 4, 7].map((bar) => {
      const x = Math.floor(((bar + 0.5) * canvas.width) / 8);
      const [r, g, b] = context.getImageData(x, y, 1, 1).data;
      const on = (bit) => (bar & bit ? 230 : 20);
      return { bar, got: [r, g, b], want: [on(4), on(2), on(1)] };
    });
  }

  const started = performance.now();
  let last = null;
  let lastLogged = 0;

  while (performance.now() - started < options.timeoutMs) {
    last = await inboundVideo();
    const decoded = last.inbound ? last.inbound.framesDecoded || 0 : 0;

    if (performance.now() - lastLogged > 2000) {
      lastLogged = performance.now();
      log(`t=${Math.round(performance.now() - started)}ms state=${pc.connectionState} ` +
          `decoded=${decoded} video=${video.videoWidth}x${video.videoHeight}`);
    }

    if (decoded >= options.minFrames && video.videoWidth === options.width &&
        video.videoHeight === options.height) {
      break;
    }
    await new Promise((resolve) => setTimeout(resolve, 250));
  }

  // One more frame's worth, so the canvas has a picture to read.
  await new Promise((resolve) => setTimeout(resolve, 200));
  const bars = video.videoWidth ? sampleBars() : [];
  last = await inboundVideo();
  trickling = false;

  const inbound = last.inbound || {};
  const quality = video.getVideoPlaybackQuality ? video.getVideoPlaybackQuality() : {};
  const result = {
    mode: options.mode,
    elapsedMs: Math.round(performance.now() - started),
    connectionState: pc.connectionState,
    videoWidth: video.videoWidth,
    videoHeight: video.videoHeight,
    expected: `${options.width}x${options.height}`,
    codec: last.codec ? `${last.codec.mimeType} ${last.codec.sdpFmtpLine || ''}`.trim() : null,
    payloadType: last.codec ? last.codec.payloadType : null,
    framesReceived: inbound.framesReceived,
    framesDecoded: inbound.framesDecoded,
    keyFramesDecoded: inbound.keyFramesDecoded,
    framesDropped: inbound.framesDropped,
    frameWidth: inbound.frameWidth,
    frameHeight: inbound.frameHeight,
    framesPerSecond: inbound.framesPerSecond,
    packetsReceived: inbound.packetsReceived,
    packetsLost: inbound.packetsLost,
    nackCount: inbound.nackCount,
    pliCount: inbound.pliCount,
    firCount: inbound.firCount,
    decoderImplementation: inbound.decoderImplementation,
    averageDecodeMs: inbound.framesDecoded
      ? Math.round((inbound.totalDecodeTime / inbound.framesDecoded) * 10000) / 10 : null,
    averageJitterBufferMs: inbound.jitterBufferEmittedCount
      ? Math.round((inbound.jitterBufferDelay / inbound.jitterBufferEmittedCount) * 10000) / 10 : null,
    renderedFrames: quality.totalVideoFrames,
    candidatePair: last.local && last.remote
      ? `${last.local.candidateType} ${last.local.address}:${last.local.port} -> ` +
        `${last.remote.candidateType} ${last.remote.address}:${last.remote.port}`
      : null,
    bars,
    negotiatedVideo: [remoteSdp, localSdp].map((sdp) =>
      sdp.split('\r\n').filter((line) => /^a=(rtpmap|fmtp):\d+ .*(H264|packetization)/.test(line))),
  };

  pc.close();
  return result;
}

function verdict(result, options) {
  const failures = [];

  if (!(result.framesDecoded > 0)) failures.push('no frames decoded');
  if (result.videoWidth !== options.width || result.videoHeight !== options.height) {
    failures.push(`<video> is ${result.videoWidth}x${result.videoHeight}, expected ${options.width}x${options.height}`);
  }
  if (!result.codec || !/^video\/H264/i.test(result.codec)) failures.push(`codec is ${result.codec}`);
  if (result.codec && !/packetization-mode=1/.test(result.codec)) failures.push('not packetization mode 1');

  for (const { bar, got, want } of result.bars) {
    const off = got.map((value, channel) => Math.abs(value - want[channel]));
    if (Math.max(...off) > 40) failures.push(`bar ${bar} is rgb(${got}) where rgb(${want}) was drawn`);
  }
  if (result.bars.length === 0) failures.push('no picture to sample');

  return failures;
}

async function main() {
  let options;
  try {
    options = parseArguments(process.argv.slice(2));
  } catch (error) {
    console.error(String(error));
    process.exit(2);
  }

  const browser = await chromium.launch({
    args: ['--disable-features=WebRtcHideLocalIpsWithMdns', '--autoplay-policy=no-user-gesture-required'],
  });
  console.error(`chromium ${browser.version()}`);

  try {
    const page = await browser.newPage();
    page.on('console', (message) => console.error(message.text()));
    page.on('pageerror', (error) => console.error(`[page error] ${error}`));

    await page.goto(`${options.url}/`);
    const result = await page.evaluate(inPage, options);
    const failures = verdict(result, options);

    result.pass = failures.length === 0;
    result.failures = failures;
    console.log(`INTEROP_RESULT ${JSON.stringify(result)}`);

    process.exitCode = result.pass ? 0 : 1;
  } finally {
    await browser.close();
  }
}

main().catch((error) => {
  console.error(error && error.stack ? error.stack : String(error));
  process.exit(2);
});
