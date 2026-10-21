/**
 * "Can this browser get a relay?" — answered by asking the browser, not by assuming.
 *
 * For each relay node the API offered, a throwaway peer connection is built with only that node's
 * servers and `iceTransportPolicy: 'relay'`, and asked to gather. A relay candidate is proof that the
 * node is reachable from this network over at least one transport *and* accepted this credential.
 * Nothing is sent to anyone: there is no remote peer, and every connection is closed afterwards.
 *
 * Used by a "test connection" button and by support; cheap enough to run before a call, but it does
 * make one allocation per transport per node, so it is not something to run on every page load.
 */

import { classifyIceError, parseCandidate } from './diagnostics.js';
import { rtcConfiguration } from './iceConfig.js';

/**
 * @param {ReturnType<import('./iceConfig.js').normalizeIceConfig>} config
 * @param {{timeoutMs?: number, RTCPeerConnection?: typeof RTCPeerConnection}} [options]
 */
export async function probeRelay(config, options = {}) {
  const Peer = options.RTCPeerConnection ?? globalThis.RTCPeerConnection;
  const timeoutMs = options.timeoutMs ?? 8000;
  const started = Date.now();

  if (typeof Peer !== 'function') {
    return { ok: false, verdict: 'unsupported', relayAvailable: config.relayAvailable, nodes: [], durationMs: 0 };
  }
  if (!config.relayAvailable) {
    return { ok: false, verdict: 'no-relay-configured', relayAvailable: false, nodes: [], durationMs: 0 };
  }

  const turnServers = config.iceServers.filter((server) => server.credential !== undefined);
  const targets = config.relayNodes.length > 0
    ? config.relayNodes.map((node) => ({
      node,
      servers: turnServers.filter((server) => server.urls.some((url) => node.urls.includes(url))),
    }))
    : [{ node: { id: 'default', label: 'Relay', region: '' }, servers: turnServers }];

  const nodes = await Promise.all(targets.map(({ node, servers }) => gather(Peer, config, node, servers, timeoutMs)));
  const ok = nodes.some((node) => node.relayCandidates > 0);

  return {
    ok,
    verdict: ok ? 'ok' : nodes.some((n) => n.errors.includes('turn_auth_failed')) ? 'relay-auth-failed' : 'relay-unreachable',
    relayAvailable: true,
    nodes,
    durationMs: Date.now() - started,
  };
}

async function gather(Peer, config, node, servers, timeoutMs) {
  const result = { id: node.id, label: node.label, region: node.region, relayCandidates: 0, protocols: [], errors: [] };
  if (servers.length === 0) {
    result.errors.push('no_servers');

    return result;
  }

  let pc;
  try {
    pc = new Peer(rtcConfiguration({ ...config, iceServers: servers }, { iceTransportPolicy: 'relay', iceCandidatePoolSize: 0 }));
  } catch {
    result.errors.push('invalid_configuration');

    return result;
  }

  try {
    await new Promise((resolve) => {
      const timer = setTimeout(resolve, timeoutMs);
      const done = () => {
        clearTimeout(timer);
        resolve();
      };

      pc.addEventListener('icecandidate', (event) => {
        if (!event.candidate) {
          done();

          return;
        }
        const parsed = parseCandidate(event.candidate);
        if (parsed.type !== 'relay') return;

        result.relayCandidates += 1;
        const protocol = parsed.relayProtocol ?? parsed.protocol;
        if (protocol && !result.protocols.includes(protocol)) result.protocols.push(protocol);
      });
      pc.addEventListener('icecandidateerror', (event) => {
        const { category } = classifyIceError(event);
        if (!result.errors.includes(category)) result.errors.push(category);
      });
      pc.addEventListener('icegatheringstatechange', () => {
        if (pc.iceGatheringState === 'complete') done();
      });

      pc.createDataChannel('aicountly-relay-probe');
      pc.createOffer()
        .then((offer) => pc.setLocalDescription(offer))
        .catch(() => {
          result.errors.push('offer_failed');
          done();
        });
    });
  } finally {
    try {
      pc.close();
    } catch {
      /* already closed */
    }
  }

  return result;
}
