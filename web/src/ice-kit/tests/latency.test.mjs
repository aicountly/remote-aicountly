import { test } from 'node:test';
import assert from 'node:assert/strict';
import { IceDiagnostics, LatencyMeter, describeVerdict, normalizeIceConfig } from '../index.js';
import { FakePeerConnection, grant } from './fakes.mjs';

/** A grant naming two regional peers, each with its own credential. */
const twoNodes = () => grant({
  iceServers: [
    { urls: ['turn:turn-mum.aicountly.com:3478?transport=udp', 'turn:turn-mum.aicountly.com:3478?transport=tcp'], username: '1:p.a', credential: 'x' },
    { urls: ['turns:turn-ncr.aicountly.com:443?transport=tcp'], username: '1:p.a', credential: 'y' },
  ],
  relayNodes: [
    { id: 'mum', label: 'Mumbai', region: 'in-west', priority: 10, status: 'active', health: 'up', load: 'normal', urls: ['turn:turn-mum.aicountly.com:3478?transport=udp', 'turn:turn-mum.aicountly.com:3478?transport=tcp'] },
    { id: 'ncr', label: 'Greater Noida', region: 'in-north', priority: 20, status: 'active', health: 'up', load: 'busy', urls: ['turns:turn-ncr.aicountly.com:443?transport=tcp'] },
  ],
});

/** A connection that "gathers" a relay candidate after a delay chosen by the server it is pointed at. */
function fakeNetwork(delays, clock) {
  return (configuration) => {
    const pc = new FakePeerConnection(configuration);
    pc.closed = false;
    pc.createDataChannel = () => ({});
    pc.createOffer = async () => ({ type: 'offer', sdp: 'x' });
    pc.close = () => { pc.closed = true; };
    pc.setLocalDescription = async () => {
      const host = configuration.iceServers[0].urls[0];
      const delay = Object.entries(delays).find(([needle]) => host.includes(needle))?.[1];
      if (delay === undefined) return;               // unreachable: no candidate ever arrives
      clock.t += delay;
      pc.candidate({ candidate: 'candidate:1 1 udp 41885439 192.0.2.1 50000 typ relay raddr 0.0.0.0 rport 0' }, host);
    };
    return pc;
  };
}

const memoryStorage = () => {
  const data = new Map();

  return { getItem: (k) => data.get(k) ?? null, setItem: (k, v) => data.set(k, v) };
};

test('each node is timed alone with its own credential, and the result is remembered', async () => {
  const clock = { t: 1_000_000 };
  const storage = memoryStorage();
  const meter = new LatencyMeter({ storage, now: () => clock.t, createConnection: fakeNetwork({ mum: 40, ncr: 95 }, clock), samples: 1 });

  const measured = await meter.measure(normalizeIceConfig(twoNodes()));

  assert.deepEqual(measured, { mum: 40, ncr: 95 });
  assert.deepEqual(meter.latest(), { mum: 40, ncr: 95 });
  assert.deepEqual(new LatencyMeter({ storage, now: () => clock.t }).latest(), { mum: 40, ncr: 95 }, 'A new page load starts with last time\'s measurement.');
});

test('an unreachable node is left out rather than guessed', async () => {
  const clock = { t: 5 };
  const meter = new LatencyMeter({ storage: null, now: () => clock.t, createConnection: fakeNetwork({ mum: 30 }, clock), samples: 1, timeoutMs: 20 });

  assert.deepEqual(await meter.measure(normalizeIceConfig(twoNodes())), { mum: 30 });
});

test('a stale measurement is not sent', async () => {
  const clock = { t: 0 };
  const meter = new LatencyMeter({ storage: null, now: () => clock.t, createConnection: fakeNetwork({ mum: 10, ncr: 20 }, clock), samples: 1, ttlMs: 1000 });
  await meter.measure(normalizeIceConfig(twoNodes()));

  clock.t += 2000;
  assert.equal(meter.latest(), undefined);
});

test('nothing to measure, nothing measured, and storage that throws is survivable', async () => {
  const broken = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); } };
  const clock = { t: 0 };
  const meter = new LatencyMeter({ storage: broken, now: () => clock.t, createConnection: fakeNetwork({ mum: 10 }, clock), samples: 1 });

  assert.equal(meter.latest(), undefined);
  assert.deepEqual(await meter.measure(null), {});
  assert.deepEqual(await meter.measure(normalizeIceConfig(grant({ relayNodes: [] }))), {});
  assert.deepEqual(await meter.measure(normalizeIceConfig(twoNodes())), { mum: 10 });
});

test('admission and node load survive normalisation, and unknown values do not', () => {
  const config = normalizeIceConfig(grant({
    admission: { tier: 'paid', outcome: 'admitted', reason: 'ok', mode: 'enforce' },
    relayNodes: twoNodes().relayNodes,
  }));

  assert.deepEqual({ ...config.admission }, { tier: 'paid', outcome: 'admitted', reason: 'ok', mode: 'enforce' });
  assert.equal(config.relayNodes[1].load, 'busy');

  const odd = normalizeIceConfig(grant({ admission: { tier: 'platinum', outcome: 'maybe', reason: 'x'.repeat(200) } }));
  assert.equal(odd.admission.tier, 'free');
  assert.equal(odd.admission.outcome, 'unmetered');
  assert.equal(odd.admission.reason.length, 40);
  assert.equal(normalizeIceConfig(grant()).admission.outcome, 'unmetered', 'An older server says nothing about admission.');
});

test('a client refused for capacity is told so, not that nothing is configured', () => {
  const denied = normalizeIceConfig({
    contract: 'aicountly.ice/1', iceServers: [{ urls: ['stun:s.example:3478'] }], relayAvailable: false, relayNodes: [],
    admission: { tier: 'free', outcome: 'denied', reason: 'free_pool_full', mode: 'enforce' },
  });
  const pc = new FakePeerConnection();
  const diagnostics = new IceDiagnostics(pc, { config: denied });
  pc.set('failed');

  assert.equal(diagnostics.verdict(), 'relay-capacity-limited');
  assert.match(describeVerdict('relay-capacity-limited'), /capacity/);

  const unconfigured = new IceDiagnostics(new FakePeerConnection(), { config: normalizeIceConfig({ iceServers: [], relayAvailable: false }) });
  unconfigured.pc.set('failed');
  assert.equal(unconfigured.verdict(), 'no-relay-configured');
  diagnostics.dispose();
  unconfigured.dispose();
});

test('the connectivity report says what admission decided', async () => {
  const pc = new FakePeerConnection();
  const config = normalizeIceConfig(grant({ admission: { tier: 'free', outcome: 'admitted', reason: 'ok', mode: 'enforce' } }));
  const diagnostics = new IceDiagnostics(pc, { config });

  assert.equal((await diagnostics.report('failed')).admission, 'admitted');
  diagnostics.dispose();
});
