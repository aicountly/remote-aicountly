/**
 * AICOUNTLY Remote — WebRTC signalling service (§19).
 *
 * What it does: relays `offer`, `answer`, `ice-candidate` and a little presence
 * between two browsers that the API has already decided may talk to each other.
 *
 * What it deliberately does not do:
 *   * decide anything — no policy, no permissions, no company context;
 *   * touch the database — it has no connection and no credentials;
 *   * trust a room id from a client — the room is inside the signed token;
 *   * carry media — audio and video go peer-to-peer (or via TURN), never here.
 *
 * Two kinds of room exist, and the token says which:
 *
 *   * a **session room**, named by a session uuid, carrying the handshake and
 *     the collaboration channel between two participants;
 *   * a **device presence room**, named `device-<uuid>`, where a registered
 *     desktop agent holds one outbound connection so that an authorised
 *     colleague can reach it without a port being opened on the machine. It
 *     relays a heartbeat and an invitation to join a session, and refuses
 *     everything else — see DEVICE_ROOM_BROADCASTABLE below.
 *
 * Keeping business logic out is what lets this run as a small always-on process
 * beside a PHP application that has no long-lived processes at all.
 */

import { readFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { WebSocketServer } from 'ws';

import { Rooms } from './rooms.js';
import { TokenError, verifySignallingToken } from './token.js';

// `PORT` takes priority: it is the port Phusion Passenger assigns and expects
// the app to listen on under cPanel's Setup Node.js App, which manages its own
// reverse proxy in front of it. REMOTE_SIGNAL_PORT is for every deployment that
// is not Passenger — systemd, a container, a bare `node src/server.js` — where
// this process owns its own port instead of being handed one.
const PORT = Number(process.env.PORT ?? process.env.REMOTE_SIGNAL_PORT ?? 8787);
const HOST = process.env.REMOTE_SIGNAL_HOST ?? '0.0.0.0';
const SECRET = process.env.REMOTE_SIGNALLING_TOKEN_SECRET ?? '';

/**
 * Origins allowed to open a socket. Empty means "do not check", which is only
 * appropriate behind a reverse proxy that already restricts it — the Origin
 * header is not a security boundary on its own, since a non-browser client can
 * send anything. The token is the real gate.
 *
 * A connection with NO Origin at all is allowed through this check: that is a
 * non-browser client, which is what the desktop agent is, and the check was
 * only ever about a page on another site.
 */
const ALLOWED_ORIGINS = (process.env.REMOTE_SIGNAL_ALLOWED_ORIGINS ?? '')
  .split(',')
  .map((value) => value.trim())
  .filter(Boolean);

/** A signalling message is SDP at worst; anything larger is not one. */
const MAX_MESSAGE_BYTES = 256 * 1024;

/**
 * Whether a connection's Origin is one to accept.
 *
 * The check exists to stop a page on another site opening a socket, and a
 * browser always sends an Origin. An **absent** one means a non-browser client
 * — which the desktop agent is — and that is what this check was never
 * protecting against; refusing it here would lock the agent out of a
 * deployment that sets an allowlist, and gain nothing. The signed token is the
 * real gate for both kinds of client.
 *
 * @param {string|undefined} origin
 * @param {string[]} allowed
 */
export function originAllowed(origin, allowed) {
  if (allowed.length === 0) return true;
  if (typeof origin !== 'string' || origin === '') return true;

  return allowed.includes(origin);
}

/** Dead sockets are indistinguishable from idle ones without a heartbeat. */
const HEARTBEAT_INTERVAL_MS = 30_000;

/**
 * How many sockets are pinged before the event loop is given back.
 *
 * Every socket used to be pinged in one synchronous pass. Measured, that pass
 * held the loop for 125-184 ms at 10,000 sockets and 262-317 ms at 19,900, and
 * a message relayed at that moment waited that long — every thirty seconds, in
 * every room. In batches, nothing waits longer than one batch.
 */
const HEARTBEAT_BATCH = 500;

/**
 * Per-connection budget for each window: messages, and bytes.
 *
 * What is over the budget is **dropped, and the sender told**; the connection
 * stays. It used to be closed at 240 messages, and a close is announced to the
 * room as the participant leaving, so their peers tore down connections that
 * were working. Ordinary use reached it: a pointer sent over the relay at the
 * web's own pace is 200 messages a window, and an offer and its candidates on
 * top of that crossed it, as did a newcomer answering six peers at forty
 * candidates each. 600 leaves room for both together.
 *
 * Bytes are counted as well as messages because the fan-out is what costs: one
 * sender inside the old message budget, sending 250 KB messages into a room of
 * fifty, took 89% of the process's core and held every other room's messages
 * for a quarter to three quarters of a second.
 *
 * A connection several times over either is not a participant with a busy
 * network, and that one is closed.
 */
const RATE_LIMIT_MESSAGES = 600;
const RATE_LIMIT_BYTES = 2 * 1024 * 1024;
const RATE_LIMIT_WINDOW_MS = 10_000;
const RATE_LIMIT_ABUSE_FACTOR = 5;

/**
 * How much may be waiting to be written to one socket before it is given up on.
 *
 * A recipient that has stopped reading — a frozen tab, a network that takes
 * nothing — otherwise has everything sent to it held here in memory until the
 * heartbeat notices, up to a minute later: measured, 5.6 MB a second and 404 MB
 * for one such socket. A megabyte is several seconds of anything this service
 * relays.
 */
const MAX_BUFFERED_BYTES = 1024 * 1024;

/**
 * The most members one room may hold. A session is a handful of people; this is
 * the ceiling on what a broadcast can be multiplied by, not a product limit.
 */
const MAX_ROOM_MEMBERS = positiveInteger(process.env.REMOTE_SIGNAL_MAX_ROOM_MEMBERS, 64);

/** Files kept back for the listening socket, the log, a health check and whatever Node itself has open. */
const OPEN_FILE_HEADROOM = 64;

/**
 * The most connections accepted at once (REMOTE_SIGNAL_MAX_CONNECTIONS), never
 * more than the process's open-file limit leaves room for.
 *
 * Every connection is an open file. At the limit the operating system still
 * completes the TCP handshake and then resets the socket, nothing is logged,
 * and the health check — one more file — fails with it, so the service looks
 * down while its existing rooms are working. Refusing just below the limit with
 * a 503 says what is the matter and leaves the health check answerable. Each
 * registered computer holds one connection here for as long as it is switched
 * on, so this number, not the session count, is what a deployment runs out of.
 */
const MAX_CONNECTIONS = connectionCeiling(
  positiveInteger(process.env.REMOTE_SIGNAL_MAX_CONNECTIONS, 20_000),
  openFileLimit(),
);

/**
 * @param {string|undefined} value
 * @param {number} fallback
 */
function positiveInteger(value, fallback) {
  const parsed = Number(value);

  return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback;
}

/** This process's soft open-file limit, or null where it cannot be read or there is none. */
function openFileLimit() {
  try {
    const match = /^Max open files\s+(\d+|unlimited)\s/m.exec(readFileSync('/proc/self/limits', 'utf8'));

    return match && match[1] !== 'unlimited' ? Number(match[1]) : null;
  } catch {
    return null;
  }
}

/**
 * How many connections to accept: what was configured, or what the open-file
 * limit leaves room for, whichever is less.
 *
 * @param {number} configured
 * @param {number|null} openFiles
 */
export function connectionCeiling(configured, openFiles) {
  if (openFiles === null) return configured;

  return Math.max(1, Math.min(configured, openFiles - OPEN_FILE_HEADROOM));
}

/** The only message types a client may send. Anything else is dropped. */
const RELAYABLE = new Set(['offer', 'answer', 'ice-candidate', 'peer-ready', 'renegotiate']);
const BROADCASTABLE = new Set(['presence', 'chat', 'pointer', 'annotation', 'share-state', 'session-ended', 'control-hint']);

/**
 * `control-hint` is a "poll the API now" nudge (docs/desktop/CONTROL_COMPANION.md): a browser that has
 * just requested, granted or revoked control tells the room, so a control companion re-reads the control
 * state at once instead of on its next poll. It carries nothing and grants nothing — the API is the only
 * source of a control decision — so its payload is dropped here rather than relayed.
 */
const PAYLOADLESS = new Set(['control-hint']);

/**
 * A device's presence room is not a session room, and carries almost nothing.
 *
 * The agent holds one outbound connection here for hours so that an authorised
 * colleague can reach it without a port being opened on the endpoint. What that
 * connection is *for* is a heartbeat and an invitation to join a session — so
 * SDP, ICE, chat, pointers and annotations are all refused in it, and there is
 * no code path by which a presence credential could be used to push media
 * negotiation at a machine.
 *
 * `device-invite` still authorises nothing: it names a session, and the agent
 * re-reads that session from the API before joining it. A fabricated invite
 * reaches an agent that finds no such session and does nothing.
 */
const DEVICE_ROOM_BROADCASTABLE = new Set(['device-invite', 'device-status', 'presence']);

if (!SECRET) {
  console.error(
    '[remote-signal] REMOTE_SIGNALLING_TOKEN_SECRET is not set. Refusing to start: without it every token would verify.',
  );
  process.exit(1);
}

const rooms = new Rooms();

// ---------------------------------------------------------------------------
// HTTP: health only. Everything else is the WebSocket upgrade.
// ---------------------------------------------------------------------------

// Passenger's Application URL prefix is not stripped for a plain Node app the
// way it would be for a framework it understands, so the same request can
// arrive as either '/health' or '/signal/health' depending on the proxy in
// front — matching both is what makes the documented health check work under
// cPanel without caring which convention this particular install uses.
const HEALTH_PATHS = new Set(['/', '/health', '/signal', '/signal/health']);

const server = createServer((req, res) => {
  if (HEALTH_PATHS.has(req.url ?? '')) {
    const body = JSON.stringify({
      status: 'ok',
      service: 'aicountly-remote-signalling',
      rooms: rooms.roomCount,
      connections: rooms.connectionCount,
      time: new Date().toISOString(),
    });

    res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
    res.end(body);

    return;
  }

  res.writeHead(404, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
  res.end('{"error":"not_found"}');
});

const wss = new WebSocketServer({ noServer: true, maxPayload: MAX_MESSAGE_BYTES });

/** When a refusal for being full was last logged: one line a minute says it, a line per attempt would bury it. */
let fullLoggedAt = 0;

server.on('upgrade', (request, socket, head) => {
  // A request line this cannot parse is somebody else's mistake, and must stay
  // theirs. `new URL('//', …)` throws, and thrown from here it took the process
  // down — and with it every connection in every room. Any process on the same
  // server can send that line to the port.
  let url;
  try {
    url = new URL(request.url ?? '/', 'http://localhost');
  } catch {
    return refuseUpgrade(socket, 400, 'Bad request');
  }

  if (url.pathname !== '/signal' && url.pathname !== '/') {
    return refuseUpgrade(socket, 404, 'Not found');
  }

  if (wss.clients.size >= MAX_CONNECTIONS) {
    if (Date.now() - fullLoggedAt > 60_000) {
      fullLoggedAt = Date.now();
      console.error(
        `[remote-signal] refusing new connections: ${wss.clients.size} are open and the limit is ${MAX_CONNECTIONS}. ` +
          'Raise the open-file limit of this process (ulimit -n) and REMOTE_SIGNAL_MAX_CONNECTIONS.',
      );
    }

    return refuseUpgrade(socket, 503, 'Service unavailable');
  }

  if (!originAllowed(request.headers.origin, ALLOWED_ORIGINS)) {
    return refuseUpgrade(socket, 403, 'Origin not allowed');
  }

  // The token may arrive as a query parameter or as the WebSocket subprotocol.
  // The browser WebSocket API cannot set an Authorization header, which is why
  // there is no header form: pretending otherwise would just not work. The web
  // app sends the subprotocol (a URL is what proxies log); the desktop agent
  // still sends the query parameter.
  const token = url.searchParams.get('token') ?? subprotocolToken(request);

  let participant;
  try {
    participant = verifySignallingToken(token ?? '', SECRET);
  } catch (error) {
    const code = error instanceof TokenError ? error.code : 'INVALID';
    console.warn(`[remote-signal] refused upgrade: ${code}`);

    return refuseUpgrade(socket, 401, 'Unauthorized');
  }

  // A room that is full takes nobody new. Somebody already in it — a reconnect,
  // a renewed token — is not new.
  if (
    rooms.size(participant.room) >= MAX_ROOM_MEMBERS &&
    rooms.member(participant.room, participant.participantUuid) === null
  ) {
    console.warn(`[remote-signal] refused upgrade: room ${participant.room} is full (${MAX_ROOM_MEMBERS})`);

    return refuseUpgrade(socket, 409, 'Room is full');
  }

  // One connection's epoch: an opaque id the client makes once per page load
  // and repeats on every reconnect of that same page. It authorises nothing —
  // it is not in the token and this service never reads meaning into it — so
  // unlike the token it is safe in a URL a proxy logs.
  //
  // It exists because a participant uuid outlives a page. A participant who
  // reconnects replaces their socket and no `peer-left` is announced, which is
  // what lets a re-minted token continue a live RTCPeerConnection. A participant
  // who *reloaded* looks identical from here, and their peers would go on
  // offering to an RTCPeerConnection that died with the old page. The epoch is
  // what tells those two apart, at the only end that can act on it.
  const epoch = connectionEpoch(url);

  wss.handleUpgrade(request, socket, head, (ws) => {
    wss.emit('connection', ws, request, epoch === null ? participant : { ...participant, epoch });
  });
});

/**
 * The `epoch` query parameter, or null.
 *
 * Bounded and restricted to url-safe characters: it is echoed to every other
 * participant in the room, so it is treated as untrusted input even though it
 * decides nothing here.
 *
 * @param {URL} url
 * @returns {string|null}
 */
function connectionEpoch(url) {
  const raw = url.searchParams.get('epoch');

  return typeof raw === 'string' && /^[A-Za-z0-9_-]{1,64}$/.test(raw) ? raw : null;
}

function subprotocolToken(request) {
  const protocols = (request.headers['sec-websocket-protocol'] ?? '')
    .split(',')
    .map((value) => value.trim());

  const bearer = protocols.find((value) => value.startsWith('aicountly-remote.'));

  return bearer ? bearer.slice('aicountly-remote.'.length) : null;
}

function refuseUpgrade(socket, status, message) {
  socket.write(`HTTP/1.1 ${status} ${message}\r\nConnection: close\r\n\r\n`);
  socket.destroy();
}

// ---------------------------------------------------------------------------
// WebSocket
// ---------------------------------------------------------------------------

wss.on('connection', (ws, _request, participant) => {
  ws.isAlive = true;
  ws.participant = participant;
  ws.rate = { windowStart: Date.now(), messages: 0, bytes: 0, told: false };

  // A token expires long before a session does; the client re-mints and
  // reconnects. Closing on expiry is what stops a connection outliving the
  // authorisation that created it.
  const expiryTimer = setTimeout(
    () => close(ws, 4003, 'Token expired'),
    Math.max(1000, participant.expiresAt * 1000 - Date.now()),
  );

  const replaced = rooms.join(participant.room, participant, ws);
  if (replaced) {
    close(replaced, 4004, 'Replaced by a newer connection');
  }

  send(ws, {
    type: 'joined',
    participantUuid: participant.participantUuid,
    role: participant.role,
    peers: rooms.peers(participant.room, participant.participantUuid).map(describe),
  });

  // Tell the room who arrived. The peers use this to decide which side makes
  // the offer, so it has to carry the role.
  broadcast(participant.room, participant.participantUuid, {
    type: 'peer-joined',
    from: participant.participantUuid,
    peer: describe({ participant }),
  });

  ws.on('pong', () => {
    ws.isAlive = true;
  });

  ws.on('message', (raw) => {
    const verdict = rateVerdict(ws.rate, raw.length ?? raw.byteLength ?? 0, Date.now());

    if (verdict === 'close') {
      close(ws, 4008, 'Too many messages');

      return;
    }

    if (verdict === 'drop') {
      // Said once a window: a reply to every dropped message would be the same flood, in reverse.
      if (!ws.rate.told) {
        ws.rate.told = true;
        send(ws, { type: 'error', code: 'RATE_LIMITED', message: 'Too many messages: some were not relayed' });
      }

      return;
    }

    let message;
    try {
      message = JSON.parse(raw.toString('utf8'));
    } catch {
      send(ws, { type: 'error', code: 'MALFORMED', message: 'Message was not JSON' });

      return;
    }

    // One message this cannot route must not be the end of the process, and of
    // every room in it.
    try {
      handle(ws, message);
    } catch (error) {
      console.error(`[remote-signal] a message could not be handled in room ${participant.room}: ${error?.message}`);
    }
  });

  ws.on('close', () => {
    clearTimeout(expiryTimer);

    // Only a socket that was still this participant's connection means they left. The close of a socket a newer
    // connection already replaced (a reconnect with a re-minted token) is not a departure, and announcing one made
    // every peer tear down a connection that was about to continue.
    if (!rooms.leave(participant.room, participant.participantUuid, ws)) return;

    broadcast(participant.room, participant.participantUuid, {
      type: 'peer-left',
      from: participant.participantUuid,
    });
  });

  ws.on('error', (error) => {
    console.warn(`[remote-signal] socket error in room ${participant.room}: ${error.message}`);
  });
});

/**
 * Route one client message.
 *
 * Two shapes exist, and the difference is deliberate:
 *
 *   * **Directed** (`offer`, `answer`, `ice-candidate`) — must name a `to`, and
 *     go only to that peer *in the sender's own room*. Cross-room delivery is
 *     impossible because the lookup is scoped to the room from the token.
 *   * **Broadcast** (`presence`, `chat`, `pointer`, …) — go to everyone else in
 *     the room and nobody outside it.
 *
 * The payload itself is passed through untouched. It is SDP and ICE, which this
 * service has no business interpreting; what it does police is *where* it goes.
 */
function handle(ws, message) {
  const { participant } = ws;
  const type = typeof message?.type === 'string' ? message.type : '';

  if (type === 'ping') {
    send(ws, { type: 'pong' });

    return;
  }

  // A device presence room has its own, much shorter, list — and nothing falls
  // through from it into the session handling below.
  if (participant.kind === 'device') {
    if (DEVICE_ROOM_BROADCASTABLE.has(type)) {
      broadcast(participant.room, participant.participantUuid, {
        type,
        from: participant.participantUuid,
        payload: message.payload ?? null,
      });

      return;
    }

    send(ws, { type: 'error', code: 'UNSUPPORTED_IN_DEVICE_ROOM', message: `Not relayed in a device room: ${type}` });

    return;
  }

  if (RELAYABLE.has(type)) {
    const to = typeof message.to === 'string' ? message.to : '';
    const target = to ? rooms.member(participant.room, to) : null;

    if (!target) {
      send(ws, { type: 'peer-unavailable', to, inReplyTo: type });

      return;
    }

    send(target.socket, {
      type,
      from: participant.participantUuid,
      payload: message.payload ?? null,
    });

    return;
  }

  if (BROADCASTABLE.has(type)) {
    broadcast(participant.room, participant.participantUuid, {
      type,
      from: participant.participantUuid,
      payload: PAYLOADLESS.has(type) ? null : (message.payload ?? null),
    });

    return;
  }

  send(ws, { type: 'error', code: 'UNSUPPORTED_TYPE', message: `Unsupported message type: ${type}` });
}

/**
 * Count one message against a connection's budget and say what to do with it:
 * relay it (`ok`), not relay it (`drop`), or end the connection (`close`).
 *
 * @param {{windowStart: number, messages: number, bytes: number, told: boolean}} rate the connection's count, updated
 * @param {number} bytes the size of this message
 * @param {number} now
 * @returns {'ok'|'drop'|'close'}
 */
export function rateVerdict(rate, bytes, now) {
  if (now - rate.windowStart > RATE_LIMIT_WINDOW_MS) {
    rate.windowStart = now;
    rate.messages = 0;
    rate.bytes = 0;
    rate.told = false;
  }

  rate.messages += 1;
  rate.bytes += bytes;

  if (
    rate.messages > RATE_LIMIT_MESSAGES * RATE_LIMIT_ABUSE_FACTOR ||
    rate.bytes > RATE_LIMIT_BYTES * RATE_LIMIT_ABUSE_FACTOR
  ) {
    return 'close';
  }

  return rate.messages > RATE_LIMIT_MESSAGES || rate.bytes > RATE_LIMIT_BYTES ? 'drop' : 'ok';
}

function describe({ participant }) {
  return {
    participantUuid: participant.participantUuid,
    role: participant.role,
    name: participant.name,
    capabilities: participant.capabilities,
    kind: participant.kind ?? 'session',
    // Absent for a client that sends none (the desktop agent, an older web
    // build): peers then behave exactly as they did before.
    ...(participant.epoch ? { epoch: participant.epoch } : {}),
  };
}

function send(socket, payload) {
  sendText(socket, JSON.stringify(payload));
}

/**
 * Write one already-serialised message to a socket — unless it has stopped
 * reading, in which case it is ended instead (MAX_BUFFERED_BYTES). Ending it is
 * announced to its room as that participant leaving, which is what has happened.
 *
 * @param {import('ws').WebSocket} socket
 * @param {string} text
 */
export function sendText(socket, text) {
  if (socket.readyState !== socket.OPEN) return;

  if (socket.bufferedAmount > MAX_BUFFERED_BYTES) {
    console.warn(
      `[remote-signal] a connection in room ${socket.participant?.room ?? '?'} is not reading ` +
        `(${socket.bufferedAmount} bytes waiting); ending it`,
    );
    socket.terminate();

    return;
  }

  socket.send(text);
}

function broadcast(room, exceptParticipantUuid, payload) {
  // Serialised once, not once for each recipient: in a room of fifty that was
  // fifty copies of the same work for every message.
  const text = JSON.stringify(payload);

  for (const member of rooms.others(room, exceptParticipantUuid)) {
    sendText(member.socket, text);
  }
}

function close(socket, code, reason) {
  try {
    socket.close(code, reason);
  } catch {
    socket.terminate();
  }
}

// A socket whose network vanished stays "open" forever without this; the ping
// is what turns a dead peer into a peer-left the other side can react to.
const heartbeat = setInterval(() => {
  const clients = [...wss.clients];
  let next = 0;

  // A batch, then the event loop, then the next batch (HEARTBEAT_BATCH).
  const pingBatch = () => {
    const end = Math.min(next + HEARTBEAT_BATCH, clients.length);

    for (; next < end; next += 1) {
      const client = clients[next];

      if (client.isAlive === false) {
        client.terminate();

        continue;
      }

      client.isAlive = false;

      if (client.readyState === client.OPEN) client.ping();
    }

    if (next < clients.length) setImmediate(pingBatch);
  };

  pingBatch();
}, HEARTBEAT_INTERVAL_MS);

heartbeat.unref();

// ---------------------------------------------------------------------------
// Lifecycle
// ---------------------------------------------------------------------------

server.listen(PORT, HOST, () => {
  console.log(`[remote-signal] listening on ${HOST}:${PORT}, accepting up to ${MAX_CONNECTIONS} connections`);
  if (ALLOWED_ORIGINS.length === 0) {
    console.warn('[remote-signal] REMOTE_SIGNAL_ALLOWED_ORIGINS is empty — any origin may open a socket with a valid token.');
  }
});

function shutdown(signal) {
  console.log(`[remote-signal] ${signal} received, closing ${rooms.connectionCount} connection(s)`);

  clearInterval(heartbeat);

  for (const client of wss.clients) {
    close(client, 1001, 'Server shutting down');
  }

  server.close(() => process.exit(0));

  // Do not hang forever on a socket that will not close.
  setTimeout(() => process.exit(0), 5000).unref();
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

export { server, wss, rooms };
