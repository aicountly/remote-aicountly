/**
 * How far this client is from each relay node, measured — the best signal there is for choosing between
 * regional peers, because it is the real network path rather than a guess from an address.
 *
 * Method: gather a relay candidate from one node alone, with the credential the API just issued for it,
 * and time it. That is a few round trips (the auth challenge, then the allocation), so the number is
 * not the RTT — but it is proportional to it, taken the same way for every node, and that is all a
 * comparison needs. The allocation is released when the connection closes a moment later.
 *
 * The result is sent back with the next request for credentials (`rtt: {nodeId: ms}`), where the server
 * uses it for that client and folds it into what it has learned for the client's region. It is advice:
 * a wrong or invented value changes which node a client is offered first and nothing else.
 *
 * Measure after a call has connected, never while one is being set up.
 */

import { urlsOf } from './iceConfig.js';

const STORAGE_KEY = 'aicountly.ice.rtt';
const DEFAULT_TTL_MS = 6 * 60 * 60 * 1000;

export class LatencyMeter {
  /**
   * @param {object} [options]
   * @param {Storage|null} [options.storage]  where to remember the last measurement (default localStorage if usable)
   * @param {() => number} [options.now]
   * @param {(config: RTCConfiguration) => RTCPeerConnection} [options.createConnection]
   * @param {number} [options.timeoutMs]  per sample
   * @param {number} [options.samples]    per node; the fastest counts
   * @param {number} [options.ttlMs]      how long a stored measurement is trusted
   */
  constructor(options = {}) {
    this.storage = options.storage === undefined ? defaultStorage() : options.storage;
    this.now = options.now ?? Date.now;
    this.createConnection = options.createConnection ?? ((config) => new RTCPeerConnection(config));
    this.timeoutMs = options.timeoutMs ?? 3000;
    this.samples = options.samples ?? 2;
    this.ttlMs = options.ttlMs ?? DEFAULT_TTL_MS;
    this.memory = null;
    this.running = null;
  }

  /** The last fresh measurement as `{nodeId: ms}`, or undefined when there is none worth sending. */
  latest() {
    const entry = this.memory ?? this.restore();
    if (!entry || this.now() - entry.at > this.ttlMs) return undefined;

    return Object.keys(entry.rtt).length > 0 ? { ...entry.rtt } : undefined;
  }

  /**
   * Time every relay node in a config. Nodes that cannot be timed (unreachable, no credential) are left
   * out. Concurrent calls share one run.
   *
   * @param {import('./iceConfig.js').IceConfig|null} config
   * @returns {Promise<Record<string, number>>}
   */
  measure(config) {
    if (!config || config.relayNodes.length === 0) return Promise.resolve({});
    this.running ??= this.run(config).finally(() => { this.running = null; });

    return this.running;
  }

  async run(config) {
    const rtt = {};

    for (const node of config.relayNodes) {
      const server = config.iceServers.find((s) => s.username && urlsOf(s).some((u) => node.urls.includes(u)));
      if (!server) continue;

      // The cheapest transport the node was offered over: UDP, else TCP, else TLS.
      const url = node.urls.find((u) => /transport=udp/i.test(u)) ?? node.urls.find((u) => /^turn:/i.test(u)) ?? node.urls[0];
      let best = null;
      for (let i = 0; i < this.samples; i++) {
        const ms = await this.sample({ ...server, urls: [url] });
        if (ms !== null && (best === null || ms < best)) best = ms;
      }
      if (best !== null) rtt[node.id] = Math.max(1, Math.min(3000, Math.round(best)));
    }

    this.memory = { at: this.now(), rtt };
    this.persist();

    return { ...rtt };
  }

  /** @returns {Promise<number|null>} ms to the first relay candidate, or null */
  async sample(server) {
    let pc;
    try {
      pc = this.createConnection({ iceServers: [server], iceTransportPolicy: 'relay', iceCandidatePoolSize: 0 });
    } catch {
      return null;
    }

    try {
      return await new Promise((resolve) => {
        const timer = setTimeout(() => resolve(null), this.timeoutMs);
        const started = this.now();
        pc.addEventListener('icecandidate', (event) => {
          if (event.candidate && /\btyp relay\b/.test(event.candidate.candidate ?? '')) {
            clearTimeout(timer);
            resolve(this.now() - started);
          }
        });
        pc.createDataChannel('rtt');
        pc.createOffer().then((offer) => pc.setLocalDescription(offer)).catch(() => { clearTimeout(timer); resolve(null); });
      });
    } finally {
      try { pc.close(); } catch { /* already closed */ }
    }
  }

  restore() {
    try {
      const raw = this.storage?.getItem(STORAGE_KEY);
      const entry = raw ? JSON.parse(raw) : null;
      if (entry && typeof entry.at === 'number' && entry.rtt && typeof entry.rtt === 'object') {
        this.memory = { at: entry.at, rtt: Object.fromEntries(Object.entries(entry.rtt).filter(([, v]) => Number.isFinite(v))) };

        return this.memory;
      }
    } catch { /* storage unavailable or corrupt: measure again */ }

    return null;
  }

  persist() {
    try {
      this.storage?.setItem(STORAGE_KEY, JSON.stringify(this.memory));
    } catch { /* private mode: the in-memory value still serves this page */ }
  }
}

function defaultStorage() {
  try {
    return typeof localStorage !== 'undefined' ? localStorage : null;
  } catch {
    return null;
  }
}
