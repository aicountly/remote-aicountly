/**
 * Signalling service tests.
 *
 * Two things are worth proving here, and they are the two the rest of the
 * product depends on:
 *
 *   1. a token that was not minted by the API is refused — otherwise host
 *      approval means nothing, because anyone could enter any room;
 *   2. a message is relayed only inside the sender's own room — otherwise a
 *      session's SDP could reach a participant in another session.
 *
 * Run with `npm test` (node:test, no framework).
 */

import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import net from 'node:net';
import { after, before, describe, it } from 'node:test';

import { DEVICE_ROOM_PREFIX, TokenError, verifySignallingToken } from '../src/token.js';
import { Rooms } from '../src/rooms.js';

const SECRET = 'test-signalling-secret';

function b64(value) {
  return Buffer.from(JSON.stringify(value)).toString('base64url');
}

function mintToken(overrides = {}) {
  const now = Math.floor(Date.now() / 1000);

  const header = b64({ alg: 'HS256', typ: 'JWT' });
  const payload = b64({
    iss: 'aicountly-remote-api',
    aud: 'aicountly-remote-signalling',
    room: '11111111-2222-4333-8444-555555555555',
    sub: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    role: 'VIEWER',
    name: 'Test Viewer',
    cap: { screen_view: true },
    iat: now,
    exp: now + 120,
    ...overrides,
  });

  const signature = createHmac('sha256', overrides.secret ?? SECRET)
    .update(`${header}.${payload}`)
    .digest('base64url');

  return `${header}.${payload}.${signature}`;
}

describe('verifySignallingToken', () => {
  it('accepts a token the API minted', () => {
    const claims = verifySignallingToken(mintToken(), SECRET);

    assert.equal(claims.room, '11111111-2222-4333-8444-555555555555');
    assert.equal(claims.role, 'VIEWER');
    assert.equal(claims.capabilities.screen_view, true);
  });

  it('refuses a token signed with a different secret', () => {
    const forged = mintToken({ secret: 'not-the-secret' });

    assert.throws(() => verifySignallingToken(forged, SECRET), (error) => {
      assert.ok(error instanceof TokenError);
      assert.equal(error.code, 'BAD_SIGNATURE');

      return true;
    });
  });

  it('refuses alg: none', () => {
    const now = Math.floor(Date.now() / 1000);
    const header = b64({ alg: 'none', typ: 'JWT' });
    const payload = b64({
      iss: 'aicountly-remote-api',
      aud: 'aicountly-remote-signalling',
      room: '11111111-2222-4333-8444-555555555555',
      sub: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
      iat: now,
      exp: now + 120,
    });

    assert.throws(
      () => verifySignallingToken(`${header}.${payload}.`, SECRET),
      (error) => error.code === 'ALG_UNSUPPORTED',
    );
  });

  it('refuses an expired token', () => {
    const now = Math.floor(Date.now() / 1000);

    assert.throws(
      () => verifySignallingToken(mintToken({ iat: now - 600, exp: now - 300 }), SECRET),
      (error) => error.code === 'EXPIRED',
    );
  });

  it('refuses a token with an implausible lifetime', () => {
    // A correctly signed token the API would never issue: something else made
    // it, whatever the signature says.
    const now = Math.floor(Date.now() / 1000);

    assert.throws(
      () => verifySignallingToken(mintToken({ iat: now, exp: now + 86_400 }), SECRET),
      (error) => error.code === 'LIFETIME_TOO_LONG',
    );
  });

  it('refuses a token minted for something other than this service', () => {
    assert.throws(
      () => verifySignallingToken(mintToken({ aud: 'somewhere-else' }), SECRET),
      (error) => error.code === 'BAD_AUDIENCE',
    );
  });

  it('treats a token with no kind claim as a session token', () => {
    const claims = verifySignallingToken(mintToken(), SECRET);

    // Every token minted before the claim existed keeps working.
    assert.equal(claims.kind, 'session');
  });

  it('accepts a device presence token for its own device room', () => {
    const device = `${DEVICE_ROOM_PREFIX}11111111-2222-4333-8444-555555555555`;

    const claims = verifySignallingToken(
      mintToken({ knd: 'device', room: device, sub: 'device-sub-0001' }),
      SECRET,
    );

    assert.equal(claims.kind, 'device');
    assert.equal(claims.room, device);
  });

  it('refuses a device token that names a session room', () => {
    assert.throws(
      () => verifySignallingToken(mintToken({ knd: 'device' }), SECRET),
      (error) => error instanceof TokenError && error.code === 'BAD_ROOM',
    );
  });

  it('refuses a session token that names a device room', () => {
    assert.throws(
      () => verifySignallingToken(
        mintToken({ room: `${DEVICE_ROOM_PREFIX}11111111-2222-4333-8444-555555555555` }),
        SECRET,
      ),
      (error) => error instanceof TokenError && error.code === 'BAD_ROOM',
    );
  });

  it('refuses an unrecognised connection kind rather than defaulting one', () => {
    assert.throws(
      () => verifySignallingToken(mintToken({ knd: 'admin' }), SECRET),
      (error) => error instanceof TokenError && error.code === 'BAD_KIND',
    );
  });

  it('refuses a token with no secret configured', () => {
    assert.throws(() => verifySignallingToken(mintToken(), ''), (error) => error.code === 'NO_SECRET');
  });
});

describe('Rooms', () => {
  it('keeps rooms separate', () => {
    const rooms = new Rooms();
    const socketA = { id: 'a' };
    const socketB = { id: 'b' };

    rooms.join('room-1', { participantUuid: 'p1', role: 'SHARER' }, socketA);
    rooms.join('room-2', { participantUuid: 'p2', role: 'VIEWER' }, socketB);

    assert.equal(rooms.peers('room-1', 'p1').length, 0, 'a peer in another room is not a peer');
    assert.equal(rooms.member('room-1', 'p2'), null, 'a room lookup must not reach into another room');
    assert.equal(rooms.roomCount, 2);
  });

  it('replaces a stale connection for the same participant', () => {
    const rooms = new Rooms();
    const first = { id: 'first' };
    const second = { id: 'second' };

    assert.equal(rooms.join('room-1', { participantUuid: 'p1' }, first), null);

    const replaced = rooms.join('room-1', { participantUuid: 'p1' }, second);

    assert.equal(replaced, first, 'the old socket is handed back so it can be closed');
    assert.equal(rooms.connectionCount, 1, 'a reconnect must not double the participant');
    assert.equal(rooms.member('room-1', 'p1').socket, second);
  });

  it('ignores a close from a socket that was already replaced', () => {
    // The old socket's close event arrives after the new one joined. Removing
    // the entry then would disconnect the live connection.
    const rooms = new Rooms();
    const first = { id: 'first' };
    const second = { id: 'second' };

    rooms.join('room-1', { participantUuid: 'p1' }, first);
    rooms.join('room-1', { participantUuid: 'p1' }, second);

    assert.equal(rooms.leave('room-1', 'p1', first), false, 'the replaced socket did not leave');

    assert.equal(rooms.member('room-1', 'p1').socket, second);
    assert.equal(rooms.leave('room-1', 'p1', second), true, 'the live socket did');
  });

  it('drops a room once its last member leaves', () => {
    const rooms = new Rooms();
    const socket = { id: 'a' };

    rooms.join('room-1', { participantUuid: 'p1' }, socket);
    rooms.leave('room-1', 'p1', socket);

    assert.equal(rooms.roomCount, 0);
    assert.equal(rooms.connectionCount, 0);
  });
});

// ---------------------------------------------------------------------------
// End-to-end: two real WebSocket clients through the real server.
// ---------------------------------------------------------------------------

/**
 * The Origin check, on its own.
 *
 * Its purpose is to stop a page on another site opening a socket. A browser
 * always sends an Origin; a desktop agent is not a browser and sends none, and
 * refusing that would lock the agent out of every deployment that sets an
 * allowlist while gaining nothing — the signed token is the gate for both.
 */
describe('the relay’s limits', () => {
  let rateVerdict;
  let sendText;
  let connectionCeiling;

  before(async () => {
    process.env.REMOTE_SIGNALLING_TOKEN_SECRET = SECRET;
    process.env.REMOTE_SIGNAL_PORT = '0';
    process.env.REMOTE_SIGNAL_MAX_ROOM_MEMBERS = '6';
    process.env.REMOTE_SIGNAL_MAX_CONNECTIONS = '40';

    ({ rateVerdict, sendText, connectionCeiling } = await import('../src/server.js'));
  });

  it('relays up to the budget, drops beyond it, and ends a connection far beyond it', () => {
    const rate = { windowStart: 1_000, messages: 0, bytes: 0, told: false };
    const verdicts = [];

    for (let count = 0; count < 3_001; count += 1) verdicts.push(rateVerdict(rate, 100, 2_000));

    assert.equal(verdicts[599], 'ok', 'the 600th');
    assert.equal(verdicts[600], 'drop', 'the 601st');
    assert.equal(verdicts[2_999], 'drop', 'the 3,000th');
    assert.equal(verdicts[3_000], 'close', 'the 3,001st');
  });

  /** One sender of very large messages was enough to hold every other room up. */
  it('counts bytes as well as messages', () => {
    const rate = { windowStart: 1_000, messages: 0, bytes: 0, told: false };

    // 250 KB at a time: eight fit in 2 MB, the ninth does not.
    const verdicts = Array.from({ length: 9 }, () => rateVerdict(rate, 250 * 1024, 2_000));

    assert.deepEqual(verdicts.slice(0, 8), Array(8).fill('ok'));
    assert.equal(verdicts[8], 'drop');
  });

  it('starts again with each window, and may say so again', () => {
    const rate = { windowStart: 1_000, messages: 600, bytes: 0, told: true };

    assert.equal(rateVerdict(rate, 10, 5_000), 'drop', 'still inside the window');
    assert.equal(rateVerdict(rate, 10, 12_000), 'ok', 'a new window');
    assert.equal(rate.told, false);
    assert.equal(rate.messages, 1);
  });

  /**
   * A recipient that has stopped reading had everything sent to it held in
   * memory until the heartbeat noticed: 404 MB for one socket, measured.
   */
  it('ends a connection that has stopped reading instead of queueing for it without limit', () => {
    const calls = [];
    const socket = (bufferedAmount) => ({
      OPEN: 1,
      readyState: 1,
      bufferedAmount,
      participant: { room: 'room' },
      send: (text) => calls.push(['send', text]),
      terminate: () => calls.push(['terminate']),
    });

    sendText(socket(0), 'hello');
    sendText(socket(900 * 1024), 'still reading, slowly');
    sendText(socket(2 * 1024 * 1024), 'not reading');

    assert.deepEqual(calls, [['send', 'hello'], ['send', 'still reading, slowly'], ['terminate']]);
  });

  it('writes nothing to a socket that is not open', () => {
    const calls = [];

    sendText({ OPEN: 1, readyState: 3, bufferedAmount: 0, send: () => calls.push('send'), terminate: () => calls.push('terminate') }, 'x');

    assert.deepEqual(calls, []);
  });

  /** Every connection is an open file, so the limit on those is the limit on these. */
  it('accepts no more connections than the open-file limit leaves room for', () => {
    assert.equal(connectionCeiling(20_000, null), 20_000, 'no limit to read: what was configured');
    assert.equal(connectionCeiling(20_000, 1_024), 960, 'the usual 1,024: sixty-four kept back');
    assert.equal(connectionCeiling(500, 65_536), 500, 'configured lower than the limit allows');
    assert.equal(connectionCeiling(20_000, 32), 1, 'never less than one');
  });
});

describe('originAllowed', () => {
  let originAllowed;

  before(async () => {
    process.env.REMOTE_SIGNALLING_TOKEN_SECRET = SECRET;
    process.env.REMOTE_SIGNAL_PORT = '0';
    // Small enough to reach in a test; read once, when the module loads.
    process.env.REMOTE_SIGNAL_MAX_ROOM_MEMBERS = '6';
    process.env.REMOTE_SIGNAL_MAX_CONNECTIONS = '40';

    ({ originAllowed } = await import('../src/server.js'));
  });

  it('accepts anything when no allowlist is configured', () => {
    assert.equal(originAllowed('https://anywhere.test', []), true);
    assert.equal(originAllowed(undefined, []), true);
  });

  it('accepts an allowed browser origin and refuses another site', () => {
    const allowed = ['https://remote.aicountly.com'];

    assert.equal(originAllowed('https://remote.aicountly.com', allowed), true);
    assert.equal(originAllowed('https://evil.test', allowed), false);
    assert.equal(originAllowed('null', allowed), false);
  });

  it('accepts a client that sends no Origin at all — the desktop agent', () => {
    const allowed = ['https://remote.aicountly.com'];

    assert.equal(originAllowed(undefined, allowed), true);
    assert.equal(originAllowed('', allowed), true);
  });
});

describe('signalling server', () => {
  let server;
  let wss;
  let port;
  let WebSocket;

  before(async () => {
    process.env.REMOTE_SIGNALLING_TOKEN_SECRET = SECRET;
    process.env.REMOTE_SIGNAL_PORT = '0';
    // Small enough to reach in a test; read once, when the module loads.
    process.env.REMOTE_SIGNAL_MAX_ROOM_MEMBERS = '6';
    process.env.REMOTE_SIGNAL_MAX_CONNECTIONS = '40';

    ({ server, wss } = await import('../src/server.js'));
    ({ WebSocket } = await import('ws'));

    await new Promise((resolve) => {
      if (server.listening) return resolve();
      server.once('listening', resolve);
    });

    port = server.address().port;
  });

  after(() => {
    wss.close();
    server.close();
  });

  /**
   * Connect, buffering every frame from the moment the socket exists.
   *
   * The server sends `joined` the instant the connection opens, which can be
   * delivered before a listener attached after `open` would see it. Buffering
   * from creation removes that race from the tests entirely.
   */
  function connect(token, { subprotocol = false, epoch = null } = {}) {
    return new Promise((resolve, reject) => {
      // The epoch rides in the query whichever way the token travels: the relay
      // reads it at the upgrade, before any message is exchanged.
      const query = epoch === null ? '' : `${subprotocol ? '?' : '&'}epoch=${encodeURIComponent(epoch)}`;

      // The browser presents its token as the subprotocol; the desktop agent
      // still sends `?token=`. Both must work.
      const ws = subprotocol
        ? new WebSocket(`ws://127.0.0.1:${port}/signal${query}`, [`aicountly-remote.${token}`])
        : new WebSocket(`ws://127.0.0.1:${port}/signal?token=${encodeURIComponent(token)}${query}`);

      ws.received = [];
      ws.waiters = [];

      ws.on('message', (raw) => {
        const message = JSON.parse(raw.toString('utf8'));
        ws.received.push(message);

        for (const waiter of [...ws.waiters]) {
          if (waiter.predicate(message)) {
            ws.waiters.splice(ws.waiters.indexOf(waiter), 1);
            waiter.resolve(message);
          }
        }
      });

      ws.on('open', () => resolve(ws));
      ws.on('error', reject);
      ws.on('unexpected-response', (_req, res) => reject(new Error(`HTTP ${res.statusCode}`)));
    });
  }

  function nextMessage(ws, predicate = () => true) {
    const buffered = ws.received.findIndex(predicate);
    if (buffered !== -1) {
      return Promise.resolve(ws.received.splice(buffered, 1)[0]);
    }

    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('timed out waiting for a message')), 3000);

      ws.waiters.push({
        predicate,
        resolve: (message) => {
          clearTimeout(timer);
          resolve(message);
        },
      });
    });
  }

  /** A raw upgrade request, for a request line no WebSocket client would send. Resolves with the status line. */
  function rawUpgrade(path) {
    return new Promise((resolve, reject) => {
      const socket = net.connect(port, '127.0.0.1', () => {
        socket.write(
          `GET ${path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: Upgrade\r\nUpgrade: websocket\r\n` +
            'Sec-WebSocket-Version: 13\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n',
        );
      });

      let received = '';
      socket.on('data', (chunk) => {
        received += chunk.toString('utf8');
      });
      socket.on('close', () => resolve(received.split('\r\n')[0]));
      socket.on('error', reject);
      setTimeout(() => socket.destroy(), 2000).unref();
    });
  }

  const uuid = (n) => `aaaaaaaa-bbbb-4ccc-8ddd-${String(n).padStart(12, '0')}`;
  const roomId = (n) => `99999999-0000-4000-8000-${String(n).padStart(12, '0')}`;

  /**
   * `new URL('//', …)` throws. Thrown from the upgrade handler it ended the
   * process, and with it every connection in every room; any process on the
   * same server can send that request line to the port.
   */
  it('answers 400 to a request line it cannot parse, and carries on', async () => {
    assert.equal(await rawUpgrade('//'), 'HTTP/1.1 400 Bad request');

    // Still here, and still serving.
    const ws = await connect(mintToken({ sub: uuid(1), room: roomId(1) }));
    assert.equal((await nextMessage(ws, (m) => m.type === 'joined')).type, 'joined');
    ws.close();
  });

  /**
   * Over the budget used to mean the connection was closed — and a close is
   * announced to the room as that participant leaving, so their peers tore
   * down connections that were working. A pointer sent through the relay plus
   * one negotiation was enough.
   */
  it('drops what is over a connection’s budget and says so, without ending the connection', async () => {
    const busy = await connect(mintToken({ sub: uuid(2), room: roomId(2) }));
    const peer = await connect(mintToken({ sub: uuid(3), room: roomId(2) }));
    await nextMessage(busy, (m) => m.type === 'joined');
    await nextMessage(peer, (m) => m.type === 'joined');

    // 650 where 600 are allowed.
    for (let count = 0; count < 650; count += 1) busy.send(JSON.stringify({ type: 'ping' }));

    // Let everything sent be answered, then count.
    await new Promise((resolve) => setTimeout(resolve, 400));

    const refusals = busy.received.filter((m) => m.type === 'error' && m.code === 'RATE_LIMITED');

    assert.equal(busy.received.filter((m) => m.type === 'pong').length, 600, 'what was within the budget was answered');
    assert.equal(refusals.length, 1, 'and it was told once, not fifty times');
    assert.match(refusals[0].message, /some were not relayed/);
    assert.equal(busy.readyState, WebSocket.OPEN, 'the connection is still up');
    assert.equal(peer.received.filter((m) => m.type === 'peer-left').length, 0, 'and nobody was told it left');

    busy.close();
    peer.close();
  });

  it('ends a connection that is several times over its budget', async () => {
    const flood = await connect(mintToken({ sub: uuid(4), room: roomId(3) }));
    await nextMessage(flood, (m) => m.type === 'joined');

    const closed = new Promise((resolve) => flood.on('close', (code) => resolve(code)));

    for (let count = 0; count < 3100 && flood.readyState === WebSocket.OPEN; count += 1) {
      flood.send(JSON.stringify({ type: 'ping' }));
    }

    assert.equal(await closed, 4008);
  });

  /** A room is a handful of people; the ceiling is on what a broadcast is multiplied by. */
  it('lets nobody new into a full room, and still lets a member reconnect', async () => {
    const room = roomId(4);
    const members = [];

    for (let n = 10; n < 16; n += 1) {
      members.push(await connect(mintToken({ sub: uuid(n), room })));
    }

    await assert.rejects(connect(mintToken({ sub: uuid(16), room })), /HTTP 409/);

    // The same participant on a new connection — a renewed token — is not somebody new.
    const again = await connect(mintToken({ sub: uuid(10), room }));
    assert.equal((await nextMessage(again, (m) => m.type === 'joined')).type, 'joined');

    for (const ws of [...members, again]) ws.close();
  });

  /**
   * Every connection is an open file. At the operating system's limit a new
   * socket is accepted and then reset, nothing is logged, and the health check
   * fails with it; refusing just below says what is the matter.
   */
  it('refuses with 503 once it holds as many connections as it may, and still answers its health check', async () => {
    // Whatever earlier tests left closing has closed.
    await new Promise((resolve) => setTimeout(resolve, 200));

    const held = [];
    while (wss.clients.size < 40) {
      const n = 100 + held.length;
      held.push(await connect(mintToken({ sub: uuid(n), room: roomId(n) })));
    }

    await assert.rejects(connect(mintToken({ sub: uuid(999), room: roomId(999) })), /HTTP 503/);

    const health = await fetch(`http://127.0.0.1:${port}/health`);
    assert.equal(health.status, 200);
    assert.equal((await health.json()).status, 'ok');

    for (const ws of held) ws.close();
    await new Promise((resolve) => setTimeout(resolve, 200));

    // Room again: accepted.
    const ws = await connect(mintToken({ sub: uuid(998), room: roomId(998) }));
    ws.close();
  });

  it('refuses a connection with a forged token', async () => {
    await assert.rejects(
      () => connect(mintToken({ secret: 'wrong' })),
      /HTTP 401|Unexpected server response: 401/,
    );
  });

  // A browser that offered a subprotocol drops the connection unless the
  // server agrees to one, so accepting the token is not enough on its own.
  it('accepts the token as the subprotocol a browser sends, and agrees to it', async () => {
    const token = mintToken({ room: 'room-subp-1111-2222-3333', sub: 'subprotocol-uuid-01' });

    const ws = await connect(token, { subprotocol: true });
    const joined = await nextMessage(ws, (m) => m.type === 'joined');

    assert.equal(joined.participantUuid, 'subprotocol-uuid-01');
    assert.equal(ws.protocol, `aicountly-remote.${token}`);

    ws.close();
  });

  it('refuses a forged token sent as the subprotocol', async () => {
    await assert.rejects(
      () => connect(mintToken({ secret: 'wrong' }), { subprotocol: true }),
      /HTTP 401|Unexpected server response: 401/,
    );
  });

  it('relays an offer to the named peer in the same room', async () => {
    const room = '99999999-8888-4777-8666-555555555555';

    const sharer = await connect(mintToken({ room, sub: 'sharer-uuid-0001', role: 'SHARER' }));
    await nextMessage(sharer, (m) => m.type === 'joined');

    const viewer = await connect(mintToken({ room, sub: 'viewer-uuid-0001', role: 'VIEWER' }));
    const joined = await nextMessage(viewer, (m) => m.type === 'joined');

    assert.equal(joined.peers.length, 1);
    assert.equal(joined.peers[0].participantUuid, 'sharer-uuid-0001');

    const delivered = nextMessage(viewer, (m) => m.type === 'offer');

    sharer.send(JSON.stringify({
      type: 'offer',
      to: 'viewer-uuid-0001',
      payload: { sdp: 'v=0 fake', type: 'offer' },
    }));

    const offer = await delivered;
    assert.equal(offer.from, 'sharer-uuid-0001');
    assert.equal(offer.payload.sdp, 'v=0 fake');

    sharer.close();
    viewer.close();
  });

  // The ICE-restart request (docs/ICE_CONTRACT.md) is a `renegotiate` with a
  // payload. The relay must carry the payload untouched to the named peer — the
  // non-driving side of a pair has no other way to ask for a restart.
  it('relays a renegotiate with its ICE-restart payload to the named peer only', async () => {
    const room = '99999999-8888-4777-8666-444444444444';

    const driver = await connect(mintToken({ room, sub: 'aaaa-driver-0001', role: 'SHARER' }));
    await nextMessage(driver, (m) => m.type === 'joined');
    const other = await connect(mintToken({ room, sub: 'zzzz-other-0001', role: 'VIEWER' }));
    await nextMessage(other, (m) => m.type === 'joined');
    const bystander = await connect(mintToken({ room, sub: 'mmmm-bystander-0001', role: 'VIEWER' }));
    await nextMessage(bystander, (m) => m.type === 'joined');

    const delivered = nextMessage(driver, (m) => m.type === 'renegotiate');

    other.send(JSON.stringify({
      type: 'renegotiate',
      to: 'aaaa-driver-0001',
      payload: { iceRestart: true, reason: 'failed' },
    }));

    const request = await delivered;
    assert.equal(request.from, 'zzzz-other-0001');
    assert.deepEqual(request.payload, { iceRestart: true, reason: 'failed' });

    await new Promise((resolve) => setTimeout(resolve, 100));
    assert.equal(bystander.received.some((m) => m.type === 'renegotiate'), false);

    driver.close();
    other.close();
    bystander.close();
  });

  it('does not deliver across rooms', async () => {
    const insider = await connect(mintToken({ room: 'room-aaaa-1111-2222-3333', sub: 'inside-uuid-001' }));
    await nextMessage(insider, (m) => m.type === 'joined');

    const outsider = await connect(mintToken({ room: 'room-bbbb-4444-5555-6666', sub: 'outside-uuid-01' }));
    await nextMessage(outsider, (m) => m.type === 'joined');

    const unavailable = nextMessage(insider, (m) => m.type === 'peer-unavailable');

    // Naming a participant in another room must not reach them.
    insider.send(JSON.stringify({ type: 'offer', to: 'outside-uuid-01', payload: { sdp: 'leak' } }));

    const reply = await unavailable;
    assert.equal(reply.to, 'outside-uuid-01');

    insider.close();
    outsider.close();
  });

  it('rejects an unsupported message type', async () => {
    const ws = await connect(mintToken({ room: 'room-cccc-1111-2222-3333', sub: 'someone-uuid-01' }));
    await nextMessage(ws, (m) => m.type === 'joined');

    const error = nextMessage(ws, (m) => m.type === 'error');
    ws.send(JSON.stringify({ type: 'drop-database', payload: {} }));

    assert.equal((await error).code, 'UNSUPPORTED_TYPE');

    ws.close();
  });

  it('broadcasts a control-hint to the room with its payload dropped', async () => {
    const room = '99999999-8888-4777-8666-333333333333';

    const viewer = await connect(mintToken({ room, sub: 'viewer-uuid-hint1', role: 'VIEWER' }));
    await nextMessage(viewer, (m) => m.type === 'joined');
    const companion = await connect(mintToken({ room, sub: 'companion-uuid-h1', role: 'CONTROL_COMPANION' }));
    await nextMessage(companion, (m) => m.type === 'joined');

    const hint = nextMessage(companion, (m) => m.type === 'control-hint');
    // A payload cannot ride along on a nudge: the API is the only source of a control decision.
    viewer.send(JSON.stringify({ type: 'control-hint', payload: { grant: true, controllerUuid: 'someone' } }));

    const delivered = await hint;
    assert.equal(delivered.from, 'viewer-uuid-hint1');
    assert.equal(delivered.payload, null);

    viewer.close();
    companion.close();
  });

  it('refuses a control-hint inside a device presence room', async () => {
    const room = `${DEVICE_ROOM_PREFIX}eeeeeeee-1111-4222-8333-444444444444`;
    const ws = await connect(mintToken({ knd: 'device', room, sub: 'agent-uuid-hint01', role: 'DEVICE' }));
    await nextMessage(ws, (m) => m.type === 'joined');

    const error = nextMessage(ws, (m) => m.type === 'error');
    ws.send(JSON.stringify({ type: 'control-hint' }));

    assert.equal((await error).code, 'UNSUPPORTED_IN_DEVICE_ROOM');

    ws.close();
  });

  it('relays an invitation inside a device presence room', async () => {
    const room = `${DEVICE_ROOM_PREFIX}aaaaaaaa-1111-4222-8333-444444444444`;

    const agent = await connect(mintToken({ knd: 'device', room, sub: 'agent-uuid-000001', role: 'DEVICE' }));
    await nextMessage(agent, (m) => m.type === 'joined');

    const controller = await connect(mintToken({ knd: 'device', room, sub: 'invite-uuid-00001', role: 'CONTROLLER' }));
    await nextMessage(controller, (m) => m.type === 'joined');

    const invited = nextMessage(agent, (m) => m.type === 'device-invite');

    controller.send(JSON.stringify({
      type: 'device-invite',
      payload: { sessionUuid: '77777777-8888-4999-8aaa-bbbbbbbbbbbb' },
    }));

    const invite = await invited;
    assert.equal(invite.payload.sessionUuid, '77777777-8888-4999-8aaa-bbbbbbbbbbbb');

    agent.close();
    controller.close();
  });

  it('refuses SDP inside a device presence room', async () => {
    const room = `${DEVICE_ROOM_PREFIX}bbbbbbbb-1111-4222-8333-444444444444`;

    const agent = await connect(mintToken({ knd: 'device', room, sub: 'agent-uuid-000002', role: 'DEVICE' }));
    await nextMessage(agent, (m) => m.type === 'joined');

    const error = nextMessage(agent, (m) => m.type === 'error');

    // A presence credential must not be usable to push media negotiation.
    agent.send(JSON.stringify({ type: 'offer', to: 'agent-uuid-000002', payload: { sdp: 'v=0 nope' } }));

    assert.equal((await error).code, 'UNSUPPORTED_IN_DEVICE_ROOM');

    agent.close();
  });

  it('keeps one device presence room out of another', async () => {
    const first = `${DEVICE_ROOM_PREFIX}cccccccc-1111-4222-8333-444444444444`;
    const second = `${DEVICE_ROOM_PREFIX}dddddddd-1111-4222-8333-444444444444`;

    const mine = await connect(mintToken({ knd: 'device', room: first, sub: 'mine-uuid-0000001', role: 'DEVICE' }));
    const joined = await nextMessage(mine, (m) => m.type === 'joined');
    assert.equal(joined.peers.length, 0);

    const theirs = await connect(mintToken({ knd: 'device', room: second, sub: 'their-uuid-000001', role: 'DEVICE' }));
    await nextMessage(theirs, (m) => m.type === 'joined');

    // Broadcasting in one device's room must not reach another's, and there is
    // no message a client can send that names a room at all.
    theirs.send(JSON.stringify({ type: 'device-invite', payload: { sessionUuid: 'should-not-arrive' } }));

    await assert.rejects(
      () => nextMessage(mine, (m) => m.type === 'device-invite'),
      /timed out/,
    );

    mine.close();
    theirs.close();
  });

  it('tells the room when a peer leaves', async () => {
    const room = 'room-dddd-1111-2222-3333';

    const first = await connect(mintToken({ room, sub: 'first-uuid-00001' }));
    await nextMessage(first, (m) => m.type === 'joined');

    const second = await connect(mintToken({ room, sub: 'second-uuid-0001' }));
    await nextMessage(second, (m) => m.type === 'joined');

    const left = nextMessage(first, (m) => m.type === 'peer-left');
    second.close();

    assert.equal((await left).from, 'second-uuid-0001');

    first.close();
  });

  it('does not announce a departure when a participant reconnects', async () => {
    const room = 'room-eeee-1111-2222-3333';

    const first = await connect(mintToken({ room, sub: 'stays-uuid-00001' }));
    await nextMessage(first, (m) => m.type === 'joined');

    const old = await connect(mintToken({ room, sub: 'moves-uuid-00001' }));
    await nextMessage(old, (m) => m.type === 'joined');

    // The same participant connects again (a re-minted token); the server replaces and closes the old socket.
    const oldClosed = new Promise((resolve) => old.once('close', (code) => resolve(code)));
    const fresh = await connect(mintToken({ room, sub: 'moves-uuid-00001' }));
    await nextMessage(fresh, (m) => m.type === 'joined');
    assert.equal(await oldClosed, 4004);

    await assert.rejects(
      nextMessage(first, (m) => m.type === 'peer-left' && m.from === 'moves-uuid-00001'),
      /timed out/,
      'a replaced socket closing is not the participant leaving',
    );

    const left = nextMessage(first, (m) => m.type === 'peer-left');
    fresh.close();
    assert.equal((await left).from, 'moves-uuid-00001', 'the live connection closing still is');

    first.close();
  });

  /**
   * The connection epoch, which is what lets the test above stay true without costing anybody a working
   * session. No `peer-left` on a replacement is right for a re-minted token and wrong for a reloaded
   * page, and this service cannot tell them apart — so it carries the client's own word for which it is
   * and lets the peers decide.
   */
  it('carries each page’s epoch to the room, so a reload can be told from a reconnect', async () => {
    const room = 'room-ffff-1111-2222-3333';

    const watcher = await connect(mintToken({ room, sub: 'watch-uuid-00001' }));
    await nextMessage(watcher, (m) => m.type === 'joined');

    const firstPage = await connect(mintToken({ room, sub: 'tabby-uuid-00001' }), { epoch: 'pageone' });
    await nextMessage(firstPage, (m) => m.type === 'joined');

    // A message delivered to a waiter stays in `received`, so each read names the epoch it is waiting
    // for rather than reading "the next peer-joined" three times.
    const joinedWith = (epoch) =>
      nextMessage(watcher, (m) => m.type === 'peer-joined' && m.from === 'tabby-uuid-00001' && m.peer.epoch === epoch);

    assert.equal((await joinedWith('pageone')).peer.epoch, 'pageone');

    // The same socket coming back on the same page repeats it: nothing for a peer to rebuild.
    const sameAgain = await connect(mintToken({ room, sub: 'tabby-uuid-00001' }), { epoch: 'pageone' });
    await nextMessage(sameAgain, (m) => m.type === 'joined');
    assert.equal((await joinedWith('pageone')).peer.epoch, 'pageone');

    // A reload: same participant, same room, new page — and the room is told so.
    const reloaded = await connect(mintToken({ room, sub: 'tabby-uuid-00001' }), { epoch: 'pagetwo' });
    const welcome = await nextMessage(reloaded, (m) => m.type === 'joined');
    assert.equal((await joinedWith('pagetwo')).peer.epoch, 'pagetwo');

    // ...and the newcomer is told everyone else's in the same shape, so it works from either side.
    assert.ok(welcome.peers.some((peer) => peer.participantUuid === 'watch-uuid-00001'));

    reloaded.close();
    watcher.close();
  });

  it('drops an epoch that is not one rather than passing it on', async () => {
    const room = 'room-ffff-4444-5555-6666';

    const watcher = await connect(mintToken({ room, sub: 'watch-uuid-00002' }));
    await nextMessage(watcher, (m) => m.type === 'joined');

    // Echoed to every participant in the room, so it is treated as untrusted input.
    const shouty = await connect(mintToken({ room, sub: 'shout-uuid-00001' }), { epoch: '<script>/../' });
    await nextMessage(shouty, (m) => m.type === 'joined');

    const arrived = await nextMessage(watcher, (m) => m.type === 'peer-joined' && m.from === 'shout-uuid-00001');
    assert.equal(arrived.peer.epoch, undefined, 'absent, exactly as from a client that sends none');

    shouty.close();
    watcher.close();
  });
});
