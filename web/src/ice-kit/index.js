/**
 * AICOUNTLY ICE kit — the browser half of lib/ice-kit. See README.md in this folder.
 *
 * Copied verbatim into every product that uses it (Remote's web/src/ice-kit, Connect's
 * web/src/ice-kit). Fix it here, bump VERSION, copy again. Plain JavaScript with index.d.ts beside it,
 * so a TypeScript product and a JavaScript one carry the same file.
 */

export {
  ICE_CONTRACT,
  applyIceConfig,
  hostPort,
  isExpired,
  needsRefresh,
  normalizeIceConfig,
  relayNodeForUrl,
  rtcConfiguration,
  urlsOf,
} from './iceConfig.js';
export { CANDIDATE_TYPES, IceDiagnostics, classifyIceError, describeVerdict, parseCandidate } from './diagnostics.js';
export { ConnectionSupervisor, defaultClock } from './supervisor.js';
export { CredentialRefresher } from './refresher.js';
export { probeRelay } from './probe.js';
export { LatencyMeter } from './latency.js';

/**
 * Who restarts ICE for a pair of peers, when the product has no perfect negotiation to settle it.
 *
 * Both sides usually notice a failure at the same moment; if both then offered, the offers would
 * cross. The lower id drives — a rule both sides compute identically without talking to each other.
 */
export function isRestartDriver(selfId, peerId) {
  return String(selfId) < String(peerId);
}
