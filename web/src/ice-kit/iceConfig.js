/**
 * The aicountly.ice/1 contract, client side: what the API returned, made into one shape.
 *
 * Every AICOUNTLY product's API hands out ICE configuration from the same PHP kit (lib/ice-kit), but
 * through its own endpoint and envelope — Remote nests it as `ice` in a signalling-token response,
 * Connect returns it flat from /calls/config and as `ice` from a room join. normalizeIceConfig()
 * accepts all of them and returns one immutable object the rest of this kit understands.
 *
 * Times are kept relative to *this* clock: the server says "expires in 3600 s", and the client counts
 * from when it received that, so a laptop whose clock is wrong by ten minutes still refreshes in time.
 */

export const ICE_CONTRACT = 'aicountly.ice/1';

/** Refresh this long before expiry when the server did not say when. */
const DEFAULT_REFRESH_FRACTION = 0.8;

/**
 * @param {unknown} raw  the API's response body (or its `data`)
 * @param {number} [now] ms epoch on this client's clock
 */
export function normalizeIceConfig(raw, now = Date.now()) {
  const outer = isObject(raw) ? raw : {};
  const src = isObject(outer.ice) ? outer.ice : outer;

  const iceServers = sanitizeServers(src.iceServers);
  const hasTurn = iceServers.some((server) => urlsOf(server).some((url) => /^turns?:/i.test(url)));
  const relayAvailable = typeof src.relayAvailable === 'boolean'
    ? src.relayAvailable
    : typeof src.turnConfigured === 'boolean' ? src.turnConfigured : hasTurn;

  const expiresIn = finiteOrNull(src.expiresIn);
  const refreshAfter = finiteOrNull(src.refreshAfter);

  let expiresAt = null;
  if (expiresIn !== null) {
    expiresAt = now + expiresIn * 1000;
  } else if (typeof src.expiresAt === 'string' && Number.isFinite(Date.parse(src.expiresAt))) {
    expiresAt = Date.parse(src.expiresAt);
  }

  let refreshAt = null;
  if (expiresAt !== null) {
    refreshAt = refreshAfter !== null
      ? now + refreshAfter * 1000
      : now + Math.max(0, expiresAt - now) * DEFAULT_REFRESH_FRACTION;
  }

  return Object.freeze({
    contract: typeof src.contract === 'string' ? src.contract : null,
    iceServers,
    iceTransportPolicy: src.iceTransportPolicy === 'relay' && relayAvailable ? 'relay' : 'all',
    iceCandidatePoolSize: clampInt(src.iceCandidatePoolSize, 0, 10),
    relayAvailable,
    relayNodes: sanitizeNodes(src.relayNodes),
    receivedAt: now,
    expiresAt,
    refreshAt,
    degraded: src.degraded === true,
    failover: src.failover === true,
  });
}

/**
 * An RTCConfiguration from a normalised config. bundle/rtcp-mux are the only sane values for
 * WebRTC between modern endpoints and are fixed here so no caller forgets them.
 *
 * @param {ReturnType<typeof normalizeIceConfig>} config
 * @param {Partial<RTCConfiguration>} [overrides]
 */
export function rtcConfiguration(config, overrides = {}) {
  return {
    iceServers: config.iceServers.map((server) => ({ ...server, urls: [...urlsOf(server)] })),
    iceTransportPolicy: config.iceTransportPolicy,
    iceCandidatePoolSize: config.iceCandidatePoolSize,
    bundlePolicy: 'max-bundle',
    rtcpMuxPolicy: 'require',
    ...overrides,
  };
}

/** Whether it is time to ask for fresh credentials. A static credential never needs it. */
export function needsRefresh(config, now = Date.now()) {
  return Boolean(config) && config.refreshAt !== null && now >= config.refreshAt;
}

/** Whether the credentials have expired, or will within `marginMs`. */
export function isExpired(config, now = Date.now(), marginMs = 0) {
  return Boolean(config) && config.expiresAt !== null && now + marginMs >= config.expiresAt;
}

/**
 * Point an existing peer connection at fresh ICE servers.
 *
 * Takes effect for the next gathering — an ICE restart — and leaves the current path alone, which is
 * why a relayed connection is restarted after a refresh (see CredentialRefresher). Returns false when
 * the browser refused, which leaves the old servers in place rather than breaking the connection.
 *
 * @param {RTCPeerConnection} pc
 * @param {ReturnType<typeof normalizeIceConfig>} config
 */
export function applyIceConfig(pc, config) {
  if (!pc || typeof pc.setConfiguration !== 'function' || pc.signalingState === 'closed') return false;

  try {
    const current = typeof pc.getConfiguration === 'function' ? pc.getConfiguration() : {};
    pc.setConfiguration({
      ...current,
      iceServers: rtcConfiguration(config).iceServers,
      // New credentials never loosen a relay-only connection: whoever chose relay-only (a forced-relay
      // diagnostic, a relay check) keeps it, or the restart that follows would quietly go direct.
      iceTransportPolicy: current.iceTransportPolicy === 'relay' ? 'relay' : config.iceTransportPolicy,
    });

    return true;
  } catch {
    return false;
  }
}

/**
 * Which relay node a STUN/TURN URL belongs to — for attributing a relay candidate or an ICE error.
 * Matches the exact URL first, then host and port, since browsers report URLs inconsistently.
 *
 * @returns {{id: string, label: string, region: string}|null}
 */
export function relayNodeForUrl(config, url) {
  if (!config || typeof url !== 'string' || url === '') return null;

  const target = hostPort(url);
  for (const node of config.relayNodes) {
    if (node.urls.includes(url)) return node;
  }
  for (const node of config.relayNodes) {
    if (target && node.urls.some((candidate) => hostPort(candidate) === target)) return node;
  }

  return null;
}

/** `turns:host:443?transport=tcp` → `host:443`. */
export function hostPort(url) {
  const match = /^(?:stuns?|turns?):(\[[^\]]+\]|[^:?/]+)(?::(\d+))?/i.exec(String(url));
  if (!match) return null;
  const secure = /^(stuns|turns):/i.test(url);

  return `${match[1].toLowerCase()}:${match[2] ?? (secure ? '5349' : '3478')}`;
}

function sanitizeServers(value) {
  if (!Array.isArray(value)) return [];

  const servers = [];
  for (const entry of value) {
    if (!isObject(entry)) continue;
    const urls = urlsOf(entry).filter((url) => /^(stuns?|turns?):\S+$/i.test(url));
    if (urls.length === 0) continue;

    const server = { urls };
    if (typeof entry.username === 'string') server.username = entry.username;
    if (typeof entry.credential === 'string') server.credential = entry.credential;
    servers.push(Object.freeze(server));
  }

  return Object.freeze(servers);
}

function sanitizeNodes(value) {
  if (!Array.isArray(value)) return Object.freeze([]);

  return Object.freeze(value.filter(isObject).map((node) => Object.freeze({
    id: String(node.id ?? ''),
    label: String(node.label ?? node.id ?? ''),
    region: String(node.region ?? ''),
    priority: Number.isFinite(node.priority) ? node.priority : 100,
    status: String(node.status ?? ''),
    health: String(node.health ?? 'unknown'),
    urls: Object.freeze(Array.isArray(node.urls) ? node.urls.filter((u) => typeof u === 'string') : []),
  })).filter((node) => node.id !== ''));
}

export function urlsOf(server) {
  if (!server) return [];
  if (typeof server.urls === 'string') return [server.urls];

  return Array.isArray(server.urls) ? server.urls.filter((url) => typeof url === 'string') : [];
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function finiteOrNull(value) {
  return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : null;
}

function clampInt(value, min, max) {
  const n = Number.isFinite(value) ? Math.trunc(value) : 0;

  return Math.max(min, Math.min(max, n));
}
