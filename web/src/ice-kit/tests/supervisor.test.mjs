import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ConnectionSupervisor, CredentialRefresher, normalizeIceConfig } from '../index.js';
import { FakePeerConnection, ManualClock, flush, grant } from './fakes.mjs';

function supervised(overrides = {}) {
  const clock = new ManualClock();
  const pc = new FakePeerConnection();
  const network = new EventTarget();
  network.navigator = { onLine: true };
  const log = { restarts: [], states: [], gaveUp: null, recovered: null, refreshes: 0 };

  const supervisor = new ConnectionSupervisor({
    pc,
    clock,
    network,
    restart: (info) => { log.restarts.push(info); },
    refreshCredentials: async () => { log.refreshes += 1; return null; },
    onStateChange: (state) => log.states.push(state),
    onGiveUp: (reason) => { log.gaveUp = reason; },
    onRecovered: (info) => { log.recovered = info; },
    disconnectedGraceMs: 2000,
    attemptTimeoutMs: 5000,
    maxAttempts: 3,
    recoveryWindowMs: 60000,
    ...overrides,
  });

  return { clock, pc, network, log, supervisor };
}

test('a brief disconnection that heals by itself costs no restart', async () => {
  const { clock, pc, log } = supervised();
  pc.set('connected');
  pc.set('disconnected');
  await clock.advance(1500);
  pc.set('connected');
  await clock.advance(10_000);

  assert.equal(log.restarts.length, 0);
  assert.deepEqual(log.states, ['connected', 'unstable', 'connected']);
});

test('a disconnection that outlasts its grace period is restarted', async () => {
  const { clock, pc, log } = supervised();
  pc.set('connected');
  pc.set('disconnected');
  await clock.advance(2000);

  assert.deepEqual(log.restarts, [{ attempt: 1, reason: 'disconnected' }]);
  assert.equal(log.states.at(-1), 'recovering');
});

test('a failure is restarted at once, and recovery is reported', async () => {
  const { clock, pc, log } = supervised();
  pc.set('connected');
  pc.set('failed');
  await flush();

  assert.deepEqual(log.restarts, [{ attempt: 1, reason: 'failed' }]);

  await clock.advance(700);
  pc.set('connected');

  assert.equal(log.recovered.attempts, 1);
  assert.equal(log.recovered.ms, 700);
  assert.equal(log.states.at(-1), 'connected');
});

test('attempts back off, refresh credentials after the first, and give up honestly', async () => {
  const { clock, pc, log } = supervised();
  pc.set('connected');
  pc.set('failed');
  await flush();
  assert.equal(log.restarts.length, 1);
  assert.equal(log.refreshes, 0, 'Fresh credentials are not needed for the first attempt.');

  await clock.advance(5000);
  assert.equal(log.restarts.length, 2);
  assert.equal(log.refreshes, 1, 'The second attempt fetches new credentials first.');

  await clock.advance(9999);
  assert.equal(log.restarts.length, 2, 'Attempt 2 is given twice as long.');
  await clock.advance(1);
  assert.equal(log.restarts.length, 3);

  await clock.advance(15_000);
  assert.equal(log.gaveUp, 'exhausted');
  assert.equal(log.states.at(-1), 'failed');
  assert.equal(clock.pending(), 0, 'Nothing is left running after giving up.');
});

test('the recovery window ends recovery even mid-attempt', async () => {
  const { clock, pc, log } = supervised({ recoveryWindowMs: 7000, attemptTimeoutMs: 60_000 });
  pc.set('connected');
  pc.set('failed');
  await clock.advance(7000);

  assert.equal(log.gaveUp, 'timeout');
});

test('a failure reported during an attempt moves straight to the next one', async () => {
  const { clock, pc, log } = supervised();
  pc.set('connected');
  pc.set('failed');
  await flush();
  pc.set('connecting', 'checking');
  pc.set('failed');
  await flush();

  assert.equal(log.restarts.length, 2);
  await clock.advance(1);
  assert.equal(log.restarts.length, 2);
});

test('offline, attempts wait for the network instead of being spent', async () => {
  const { clock, pc, network, log } = supervised();
  pc.set('connected');
  network.navigator.onLine = false;
  pc.set('failed');
  await clock.advance(20_000);

  assert.equal(log.restarts.length, 0);

  network.navigator.onLine = true;
  network.dispatchEvent(new Event('online'));
  await flush();

  assert.deepEqual(log.restarts, [{ attempt: 1, reason: 'network_changed' }]);
});

test('credentials close to expiry are refreshed before the first attempt', async () => {
  let config = normalizeIceConfig(grant({ expiresIn: 30, refreshAfter: 24 }), 1_000_000);
  const { pc, log } = supervised({ getConfig: () => config });
  pc.set('connected');
  pc.set('failed');
  await flush();

  assert.equal(log.refreshes, 1);
  assert.equal(log.restarts.length, 1);
  config = null;
});

test('a requested restart does not count against recovery', async () => {
  const { pc, log, supervisor } = supervised();
  pc.set('connected');
  await supervisor.requestRestart('credential_refresh');

  assert.deepEqual(log.restarts, [{ attempt: 0, reason: 'credential_refresh' }]);
  assert.equal(supervisor.attempts, 0);
});

test('disposing stops everything and a closed connection disposes itself', async () => {
  const { clock, pc, log, supervisor } = supervised();
  pc.set('connected');
  pc.set('disconnected');
  supervisor.dispose();
  await clock.advance(60_000);

  assert.equal(log.restarts.length, 0);
  assert.equal(clock.pending(), 0);

  const other = supervised();
  other.pc.set('closed');
  assert.equal(other.supervisor.state, 'closed');
});

test('the refresher asks again at refreshAfter and shares one request between callers', async () => {
  const clock = new ManualClock(0);
  let calls = 0;
  const refreshed = [];
  const refresher = new CredentialRefresher({
    clock,
    fetch: async () => { calls += 1; return grant({ expiresIn: 600, refreshAfter: 480 }); },
    onRefreshed: (config) => refreshed.push(config),
  }).start(normalizeIceConfig(grant({ expiresIn: 600, refreshAfter: 480 }), 0));

  await clock.advance(479_999);
  assert.equal(calls, 0);
  await clock.advance(1);
  assert.equal(calls, 1);
  assert.equal(refreshed[0].refreshAt, 480_000 + 480_000, 'The next refresh is scheduled from the fresh grant.');

  await Promise.all([refresher.refreshNow(), refresher.refreshNow()]);
  assert.equal(calls, 2);
  refresher.stop();
  assert.equal(clock.pending(), 0);
});

test('a failed refresh is retried with backoff', async () => {
  const clock = new ManualClock(0);
  let calls = 0;
  const refresher = new CredentialRefresher({
    clock,
    retryDelayMs: 1000,
    fetch: async () => { calls += 1; if (calls < 3) throw new Error('503'); return grant(); },
  }).start(normalizeIceConfig(grant({ expiresIn: 100, refreshAfter: 30 }), 0));

  await clock.advance(30_000);
  assert.equal(calls, 1);
  await clock.advance(1000);
  assert.equal(calls, 2);
  await clock.advance(2000);
  assert.equal(calls, 3);
  assert.equal(refresher.current.relayNodes[0].id, 'mum');
  refresher.stop();
});

test('a static credential schedules nothing', () => {
  const clock = new ManualClock(0);
  new CredentialRefresher({ clock, fetch: async () => grant() }).start(normalizeIceConfig(grant({ expiresIn: null, refreshAfter: null, expiresAt: null })));

  assert.equal(clock.pending(), 0);
});

test('after giving up, a person can ask for a fresh round of attempts', async () => {
  const { clock, pc, log, supervisor } = supervised({ maxAttempts: 1 });
  pc.set('connected');
  pc.set('failed');
  await flush();
  await clock.advance(5000);
  assert.equal(log.gaveUp, 'exhausted');

  supervisor.retry();
  await flush();
  assert.deepEqual(log.restarts.at(-1), { attempt: 1, reason: 'manual' });
  assert.equal(supervisor.state, 'recovering');

  pc.set('connected');
  assert.equal(supervisor.state, 'connected');
});

test('a renewal denied for capacity never replaces a working relay configuration', async () => {
  const clock = new ManualClock(0);
  const denied = { contract: 'aicountly.ice/1', iceServers: [{ urls: ['stun:s.example:3478'] }], relayAvailable: false, relayNodes: [], expiresIn: 600, refreshAfter: 480, admission: { tier: 'free', outcome: 'denied', reason: 'node_overloaded', mode: 'enforce' } };
  let calls = 0;
  const refreshed = [];
  const seenDenied = [];
  const refresher = new CredentialRefresher({
    clock,
    retryDelayMs: 1000,
    fetch: async () => { calls += 1; return calls < 3 ? denied : grant({ expiresIn: 600, refreshAfter: 480 }); },
    onRefreshed: (config) => refreshed.push(config),
    onDenied: (config) => seenDenied.push(config),
  }).start(normalizeIceConfig(grant({ expiresIn: 600, refreshAfter: 480 }), 0));
  const original = refresher.current;

  await clock.advance(480_000);
  assert.equal(calls, 1);
  assert.equal(refreshed.length, 0, 'The denied grant is not adopted: a relayed session would be restarted without its relay.');
  assert.equal(seenDenied.length, 1);
  assert.equal(refresher.current, original);

  await clock.advance(30_000);
  assert.equal(calls, 1, 'A denial is retried after a minute, not hammered.');
  await clock.advance(30_000);
  assert.equal(calls, 2);

  await clock.advance(120_000);
  assert.equal(calls, 3);
  assert.equal(refreshed.length, 1, 'Room opened; the fresh grant is adopted.');
  refresher.stop();
});

test('a first grant that is denied is still adopted: there was no relay to lose', async () => {
  const clock = new ManualClock(0);
  const denied = { contract: 'aicountly.ice/1', iceServers: [], relayAvailable: false, relayNodes: [], admission: { tier: 'free', outcome: 'denied', reason: 'free_pool_full', mode: 'enforce' } };
  const refreshed = [];
  const refresher = new CredentialRefresher({ clock, fetch: async () => denied, onRefreshed: (c) => refreshed.push(c) }).start(normalizeIceConfig(denied, 0));

  await refresher.refreshNow();
  assert.equal(refreshed.length, 1);
  refresher.stop();
});
