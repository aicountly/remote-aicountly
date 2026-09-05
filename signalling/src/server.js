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
 */
const ALLOWED_ORIGINS = (process.env.REMOTE_SIGNAL_ALLOWED_ORIGINS ?? '')
  .split(',')
  .map((value) => value.trim())
  .filter(Boolean);

/** A signalling message is SDP at worst; anything larger is not one. */
const MAX_MESSAGE_BYTES = 256 * 1024;

/** Dead sockets are indistinguishable from idle ones without a heartbeat. */
const HEARTBEAT_INTERVAL_MS = 30_000;

/** Per-connection budget. Generous for ICE trickle, tight enough to matter. */
const RATE_LIMIT_MESSAGES = 240;
const RATE_LIMIT_WINDOW_MS = 10_000;

/** The only message types a client may send. Anything else is dropped. */
const RELAYABLE = new Set(['offer', 'answer', 'ice-candidate', 'peer-ready', 'renegotiate']);
const BROADCASTABLE = new Set(['presence', 'chat', 'pointer', 'annotation', 'share-state', 'session-ended']);

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

server.on('upgrade', (request, socket, head) => {
  const url = new URL(request.url ?? '/', 'http://localhost');

  if (url.pathname !== '/signal' && url.pathname !== '/') {
    return refuseUpgrade(socket, 404, 'Not found');
  }

  if (ALLOWED_ORIGINS.length > 0) {
    const origin = request.headers.origin ?? '';
    if (!ALLOWED_ORIGINS.includes(origin)) {
      return refuseUpgrade(socket, 403, 'Origin not allowed');
    }
  }

  // The token may arrive as a query parameter or as the WebSocket subprotocol.
  // The browser WebSocket API cannot set an Authorization header, which is why
  // there is no header form: pretending otherwise would just not work.
  const token = url.searchParams.get('token') ?? subprotocolToken(request);

  let participant;
  try {
    participant = verifySignallingToken(token ?? '', SECRET);
  } catch (error) {
    const code = error instanceof TokenError ? error.code : 'INVALID';
    console.warn(`[remote-signal] refused upgrade: ${code}`);

    return refuseUpgrade(socket, 401, 'Unauthorized');
  }

  wss.handleUpgrade(request, socket, head, (ws) => {
    wss.emit('connection', ws, request, participant);
  });
});

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
  ws.rateWindowStart = Date.now();
  ws.rateCount = 0;

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
    if (!withinRateLimit(ws)) {
      close(ws, 4008, 'Too many messages');

      return;
    }

    let message;
    try {
      message = JSON.parse(raw.toString('utf8'));
    } catch {
      send(ws, { type: 'error', code: 'MALFORMED', message: 'Message was not JSON' });

      return;
    }

    handle(ws, message);
  });

  ws.on('close', () => {
    clearTimeout(expiryTimer);
    rooms.leave(participant.room, participant.participantUuid, ws);

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
      payload: message.payload ?? null,
    });

    return;
  }

  send(ws, { type: 'error', code: 'UNSUPPORTED_TYPE', message: `Unsupported message type: ${type}` });
}

function withinRateLimit(ws) {
  const now = Date.now();

  if (now - ws.rateWindowStart > RATE_LIMIT_WINDOW_MS) {
    ws.rateWindowStart = now;
    ws.rateCount = 0;
  }

  ws.rateCount += 1;

  return ws.rateCount <= RATE_LIMIT_MESSAGES;
}

function describe({ participant }) {
  return {
    participantUuid: participant.participantUuid,
    role: participant.role,
    name: participant.name,
    capabilities: participant.capabilities,
    kind: participant.kind ?? 'session',
  };
}

function send(socket, payload) {
  if (socket.readyState === socket.OPEN) {
    socket.send(JSON.stringify(payload));
  }
}

function broadcast(room, exceptParticipantUuid, payload) {
  for (const member of rooms.peers(room, exceptParticipantUuid)) {
    send(member.socket, payload);
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
  for (const client of wss.clients) {
    if (client.isAlive === false) {
      client.terminate();

      continue;
    }

    client.isAlive = false;
    client.ping();
  }
}, HEARTBEAT_INTERVAL_MS);

heartbeat.unref();

// ---------------------------------------------------------------------------
// Lifecycle
// ---------------------------------------------------------------------------

server.listen(PORT, HOST, () => {
  console.log(`[remote-signal] listening on ${HOST}:${PORT}`);
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
