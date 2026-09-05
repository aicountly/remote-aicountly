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

    rooms.leave('room-1', 'p1', first);

    assert.equal(rooms.member('room-1', 'p1').socket, second);
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

describe('signalling server', () => {
  let server;
  let wss;
  let port;
  let WebSocket;

  before(async () => {
    process.env.REMOTE_SIGNALLING_TOKEN_SECRET = SECRET;
    process.env.REMOTE_SIGNAL_PORT = '0';

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
  function connect(token) {
    return new Promise((resolve, reject) => {
      const ws = new WebSocket(`ws://127.0.0.1:${port}/signal?token=${encodeURIComponent(token)}`);

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

  it('refuses a connection with a forged token', async () => {
    await assert.rejects(
      () => connect(mintToken({ secret: 'wrong' })),
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
});
