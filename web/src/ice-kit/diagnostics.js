/**
 * What happened to one peer connection's ICE, reduced to facts that are safe to show and to report.
 *
 * It answers the questions support asks — "did it go through the relay, which one, over what, and if
 * it failed, where" — without ever keeping an SDP, a candidate string, an IP address or a credential.
 * It listens with addEventListener, so it sits beside whatever handlers the product already set on the
 * peer connection rather than replacing them.
 */

import { relayNodeForUrl } from './iceConfig.js';

export const CANDIDATE_TYPES = ['host', 'srflx', 'prflx', 'relay'];

/**
 * The type and transport of a candidate, never its address.
 *
 * @param {RTCIceCandidate|RTCIceCandidateInit|string|null} candidate
 * @returns {{type: string|null, protocol: string|null, relayProtocol: string|null}}
 */
export function parseCandidate(candidate) {
  const text = typeof candidate === 'string' ? candidate : candidate?.candidate ?? '';
  const type = (typeof candidate === 'object' && candidate?.type) || /\btyp (host|srflx|prflx|relay)\b/.exec(text)?.[1] || null;
  const protocol = (typeof candidate === 'object' && candidate?.protocol) || /^candidate:\S+ \d+ (udp|tcp)\b/i.exec(text)?.[1]?.toLowerCase() || null;
  const relayProtocol = typeof candidate === 'object' && typeof candidate?.relayProtocol === 'string' ? candidate.relayProtocol : null;

  return { type: CANDIDATE_TYPES.includes(type) ? type : null, protocol, relayProtocol };
}

/**
 * An `icecandidateerror` event, as a category an operator and a person can act on.
 *
 * Code 701 is the browser's own "could not reach that server"; 401/403/437/438/486/508 are the TURN
 * server's answers (RFC 8656). Chrome raises 701 for every local interface that cannot reach a
 * server — an IPv6 address on an IPv4-only network, say — so one 701 is noise and the verdict below
 * only counts it when no relay candidate arrived from that server at all.
 *
 * @returns {{category: string, code: number, url: string|null}}
 */
export function classifyIceError(event) {
  const code = Number(event?.errorCode ?? 0);
  const url = typeof event?.url === 'string' && event.url !== '' ? event.url : null;
  const isTurn = url !== null && /^turns?:/i.test(url);

  let category;
  if (code === 701) category = isTurn ? 'turn_unreachable' : 'stun_unreachable';
  else if (code === 401 || code === 441) category = 'turn_auth_failed';
  else if (code === 403) category = 'turn_forbidden';
  else if (code === 486 || code === 508) category = 'turn_capacity';
  else category = isTurn ? 'turn_error' : 'stun_unreachable';

  return { category, code, url };
}

const MAX_ERRORS = 10;

export class IceDiagnostics {
  /**
   * @param {RTCPeerConnection} pc
   * @param {{config?: object|null, now?: () => number}} [options]
   */
  constructor(pc, options = {}) {
    this.pc = pc;
    this.config = options.config ?? null;
    this.now = options.now ?? (() => Date.now());
    this.startedAt = this.now();
    this.connectedAt = null;
    this.gathered = { host: 0, srflx: 0, prflx: 0, relay: 0 };
    /** Relay candidates per node id — the proof a node allocated. */
    this.relayByNode = new Map();
    /** Errors, de-duplicated by category + node. */
    this.errors = new Map();
    this.restarts = 0;
    this.credentialRefreshes = 0;
    this.lastPath = null;
    this.disposed = false;

    this.onCandidate = (event) => this.recordCandidate(event);
    this.onCandidateError = (event) => this.recordError(event);
    this.onStateChange = () => {
      const state = this.pc.iceConnectionState;
      if ((state === 'connected' || state === 'completed') && this.connectedAt === null) {
        this.connectedAt = this.now();
      }
    };

    pc.addEventListener?.('icecandidate', this.onCandidate);
    pc.addEventListener?.('icecandidateerror', this.onCandidateError);
    pc.addEventListener?.('iceconnectionstatechange', this.onStateChange);
  }

  /** Fresh credentials have been applied; later relay URLs belong to the new config. */
  setConfig(config) {
    this.config = config;
  }

  noteRestart() {
    this.restarts += 1;
  }

  noteCredentialRefresh() {
    this.credentialRefreshes += 1;
  }

  recordCandidate(event) {
    if (this.disposed || !event?.candidate) return;

    const { type } = parseCandidate(event.candidate);
    if (!type) return;
    this.gathered[type] += 1;

    if (type === 'relay') {
      const url = event.candidate.url ?? event.url ?? null;
      const node = relayNodeForUrl(this.config, url);
      const key = node?.id ?? '?';
      this.relayByNode.set(key, (this.relayByNode.get(key) ?? 0) + 1);
    }
  }

  recordError(event) {
    if (this.disposed) return;

    const { category, code, url } = classifyIceError(event);
    const node = relayNodeForUrl(this.config, url)?.id ?? null;
    const key = `${category}|${node ?? ''}`;

    const existing = this.errors.get(key);
    if (existing) {
      existing.count += 1;
    } else if (this.errors.size < MAX_ERRORS) {
      this.errors.set(key, { category, node, code, count: 1 });
    }
  }

  /**
   * The candidate pair actually carrying media, from getStats().
   *
   * @returns {Promise<{path: 'direct'|'relay'|'unknown', localCandidateType: string|null, remoteCandidateType: string|null, relayProtocol: string|null, relayNode: string|null, rttMs: number|null}>}
   */
  async selectedPath() {
    const unknown = { path: 'unknown', localCandidateType: null, remoteCandidateType: null, relayProtocol: null, relayNode: null, rttMs: null };
    if (this.disposed || typeof this.pc.getStats !== 'function') return unknown;

    let stats;
    try {
      stats = await this.pc.getStats();
    } catch {
      return unknown;
    }

    const reports = new Map();
    let selectedId = null;
    stats.forEach((report) => {
      reports.set(report.id, report);
      // Chrome names the pair on the transport; Firefox flags the pair itself.
      if (report.type === 'transport' && report.selectedCandidatePairId) selectedId = report.selectedCandidatePairId;
    });

    let pair = selectedId ? reports.get(selectedId) : null;
    if (!pair) {
      for (const report of reports.values()) {
        if (report.type === 'candidate-pair' && (report.selected || (report.nominated && report.state === 'succeeded'))) {
          pair = report;
          break;
        }
      }
    }
    if (!pair) return unknown;

    const local = reports.get(pair.localCandidateId) ?? {};
    const remote = reports.get(pair.remoteCandidateId) ?? {};
    const relayed = local.candidateType === 'relay' || remote.candidateType === 'relay';

    const result = {
      path: relayed ? 'relay' : 'direct',
      localCandidateType: CANDIDATE_TYPES.includes(local.candidateType) ? local.candidateType : null,
      remoteCandidateType: CANDIDATE_TYPES.includes(remote.candidateType) ? remote.candidateType : null,
      relayProtocol: local.candidateType === 'relay' && ['udp', 'tcp', 'tls'].includes(local.relayProtocol) ? local.relayProtocol : null,
      relayNode: local.candidateType === 'relay' ? relayNodeForUrl(this.config, local.url)?.id ?? null : null,
      rttMs: typeof pair.currentRoundTripTime === 'number' ? Math.round(pair.currentRoundTripTime * 1000) : null,
    };
    this.lastPath = result;

    return result;
  }

  /**
   * One word for what went wrong, or `ok`. Ordered from the most specific cause to the least.
   *
   * @returns {'ok'|'no-relay-configured'|'relay-auth-failed'|'relay-unreachable'|'relay-refused'|'no-relay-candidates'|'ice-failed'}
   */
  verdict() {
    const state = this.pc.iceConnectionState;
    if (state === 'connected' || state === 'completed') return 'ok';

    const categories = new Set([...this.errors.values()].map((e) => e.category));
    const relayExpected = Boolean(this.config?.relayAvailable);

    if (state !== 'failed' && state !== 'disconnected') return 'ok';
    if (!relayExpected) return 'no-relay-configured';
    if (this.gathered.relay === 0) {
      if (categories.has('turn_auth_failed')) return 'relay-auth-failed';
      if (categories.has('turn_forbidden') || categories.has('turn_capacity')) return 'relay-refused';
      if (categories.has('turn_unreachable')) return 'relay-unreachable';

      return 'no-relay-candidates';
    }

    return 'ice-failed';
  }

  /** @returns {Array<{category: string, node: string|null, code: number, count: number}>} */
  iceErrors() {
    return [...this.errors.values()].map((error) => ({ ...error }));
  }

  /**
   * A report for the product's connectivity-report endpoint — exactly the fields the server-side
   * allow-list (lib/ice-kit ConnectivityReport) accepts.
   *
   * @param {'connected'|'recovered'|'failed'|'abandoned'} outcome
   */
  async report(outcome) {
    const path = this.disposed ? (this.lastPath ?? { path: 'unknown' }) : await this.selectedPath();
    const errors = this.iceErrors();
    if (outcome === 'failed' && this.gathered.relay === 0 && this.config?.relayAvailable) {
      errors.push({ category: 'no_relay_candidates', node: null, code: 0, count: 1 });
    }

    return {
      outcome,
      path: path.path ?? 'unknown',
      localCandidateType: path.localCandidateType ?? null,
      remoteCandidateType: path.remoteCandidateType ?? null,
      relayProtocol: path.relayProtocol ?? null,
      relayNode: path.relayNode ?? null,
      gathered: { ...this.gathered },
      iceErrors: errors.slice(0, MAX_ERRORS).map(({ category, node, code }) => ({ category, node, code })),
      restarts: this.restarts,
      credentialRefreshes: this.credentialRefreshes,
      timeToConnectMs: this.connectedAt === null ? null : this.connectedAt - this.startedAt,
      durationMs: this.now() - this.startedAt,
    };
  }

  dispose() {
    if (this.disposed) return;
    this.disposed = true;
    this.pc.removeEventListener?.('icecandidate', this.onCandidate);
    this.pc.removeEventListener?.('icecandidateerror', this.onCandidateError);
    this.pc.removeEventListener?.('iceconnectionstatechange', this.onStateChange);
  }
}

/**
 * What to tell a person about a verdict. Plain words, and a next step where there is one.
 */
export function describeVerdict(verdict) {
  switch (verdict) {
    case 'no-relay-configured':
      return 'A direct connection was not possible on this network, and no relay server is configured to carry it instead.';
    case 'relay-auth-failed':
      return 'The relay server refused this connection’s credentials. Reconnecting fetches new ones; if it keeps happening, the relay is misconfigured.';
    case 'relay-unreachable':
      return 'The relay server could not be reached from this network. A firewall may be blocking it — try another network.';
    case 'relay-refused':
      return 'The relay server is at capacity or refused the connection. Try again shortly.';
    case 'no-relay-candidates':
      return 'No relay path could be set up from this network. A firewall or VPN may be blocking WebRTC.';
    case 'ice-failed':
      return 'The network path between the two sides was lost.';
    default:
      return '';
  }
}
