import { test } from 'node:test';
import assert from 'node:assert/strict';
import { IceDiagnostics, classifyIceError, describeVerdict, normalizeIceConfig, parseCandidate, probeRelay } from '../index.js';
import { FakePeerConnection, grant } from './fakes.mjs';

const RELAY = 'candidate:3 1 udp 41885439 148.135.210.65 40123 typ relay raddr 0.0.0.0 rport 0 generation 0';
const HOST = 'candidate:1 1 udp 2122260223 192.168.1.20 54400 typ host generation 0';
const SRFLX = 'candidate:2 1 udp 1686052607 203.0.113.7 54400 typ srflx raddr 192.168.1.20 rport 54400';

test('a candidate is reduced to its type and transport, never its address', () => {
  assert.deepEqual(parseCandidate(HOST), { type: 'host', protocol: 'udp', relayProtocol: null });
  assert.deepEqual(parseCandidate({ candidate: RELAY, relayProtocol: 'tls' }), { type: 'relay', protocol: 'udp', relayProtocol: 'tls' });
  assert.equal(parseCandidate('garbage').type, null);
});

test('ICE errors become categories', () => {
  assert.equal(classifyIceError({ errorCode: 701, url: 'turns:turn-mum.aicountly.com:443?transport=tcp' }).category, 'turn_unreachable');
  assert.equal(classifyIceError({ errorCode: 701, url: 'stun:stun.example:3478' }).category, 'stun_unreachable');
  assert.equal(classifyIceError({ errorCode: 401, url: 'turn:x:3478' }).category, 'turn_auth_failed');
  assert.equal(classifyIceError({ errorCode: 486, url: 'turn:x:3478' }).category, 'turn_capacity');
});

test('gathering is counted and relay candidates are attributed to their node', () => {
  const pc = new FakePeerConnection();
  const diagnostics = new IceDiagnostics(pc, { config: normalizeIceConfig(grant()) });

  pc.candidate({ candidate: HOST });
  pc.candidate({ candidate: SRFLX });
  pc.candidate({ candidate: RELAY, url: 'turn:turn-mum.aicountly.com:3478?transport=udp' });
  pc.candidate(null);

  assert.deepEqual(diagnostics.gathered, { host: 1, srflx: 1, prflx: 0, relay: 1 });
  assert.equal(diagnostics.relayByNode.get('mum'), 1);
});

test('the selected path is read from getStats, Chrome-style and Firefox-style', async () => {
  const pc = new FakePeerConnection();
  const diagnostics = new IceDiagnostics(pc, { config: normalizeIceConfig(grant()) });

  pc.stats = new Map([
    ['T', { id: 'T', type: 'transport', selectedCandidatePairId: 'P' }],
    ['P', { id: 'P', type: 'candidate-pair', localCandidateId: 'L', remoteCandidateId: 'R', currentRoundTripTime: 0.042 }],
    ['L', { id: 'L', type: 'local-candidate', candidateType: 'relay', relayProtocol: 'tls', url: 'turns:turn-mum.aicountly.com:443?transport=tcp', address: '148.135.210.65' }],
    ['R', { id: 'R', type: 'remote-candidate', candidateType: 'srflx', address: '203.0.113.7' }],
  ]);
  assert.deepEqual(await diagnostics.selectedPath(), {
    path: 'relay', localCandidateType: 'relay', remoteCandidateType: 'srflx', relayProtocol: 'tls', relayNode: 'mum', rttMs: 42,
  });

  pc.stats = new Map([
    ['P', { id: 'P', type: 'candidate-pair', selected: true, localCandidateId: 'L', remoteCandidateId: 'R' }],
    ['L', { id: 'L', type: 'local-candidate', candidateType: 'host' }],
    ['R', { id: 'R', type: 'remote-candidate', candidateType: 'host' }],
  ]);
  assert.equal((await diagnostics.selectedPath()).path, 'direct');
});

test('the verdict names the most specific cause', () => {
  const config = normalizeIceConfig(grant());
  const verdictFor = (setup, relayAvailable = true) => {
    const pc = new FakePeerConnection();
    const diagnostics = new IceDiagnostics(pc, { config: relayAvailable ? config : normalizeIceConfig({ iceServers: [] }) });
    setup(pc);
    pc.set('failed');

    return diagnostics.verdict();
  };

  assert.equal(verdictFor(() => {}, false), 'no-relay-configured');
  assert.equal(verdictFor((pc) => pc.candidateError(401, 'turn:turn-mum.aicountly.com:3478?transport=udp')), 'relay-auth-failed');
  assert.equal(verdictFor((pc) => pc.candidateError(701, 'turns:turn-mum.aicountly.com:443?transport=tcp')), 'relay-unreachable');
  assert.equal(verdictFor(() => {}), 'no-relay-candidates');
  assert.equal(verdictFor((pc) => pc.candidate({ candidate: RELAY })), 'ice-failed');
  assert.notEqual(describeVerdict('relay-unreachable'), '');
});

test('a report holds only what the server allow-list accepts — no address anywhere', async () => {
  let t = 0;
  const pc = new FakePeerConnection();
  const diagnostics = new IceDiagnostics(pc, { config: normalizeIceConfig(grant()), now: () => t });

  pc.candidate({ candidate: HOST });
  pc.candidateError(701, 'turns:turn-mum.aicountly.com:443?transport=tcp');
  pc.candidateError(701, 'turns:turn-mum.aicountly.com:443?transport=tcp');
  diagnostics.noteRestart();
  t = 900;
  pc.set('connected');
  t = 5000;

  const report = await diagnostics.report('connected');
  const encoded = JSON.stringify(report);

  assert.deepEqual(Object.keys(report).sort(), [
    'admission', 'credentialRefreshes', 'durationMs', 'gathered', 'iceErrors', 'localCandidateType', 'outcome', 'path',
    'relayNode', 'relayProtocol', 'remoteCandidateType', 'restarts', 'timeToConnectMs',
  ]);
  assert.deepEqual(report.iceErrors, [{ category: 'turn_unreachable', node: 'mum', code: 701 }], 'Repeated errors are counted once.');
  assert.equal(report.timeToConnectMs, 900);
  assert.equal(report.restarts, 1);
  assert.ok(!encoded.includes('192.'), 'No address may be in a report.');
});

test('the relay probe gathers per node with relay-only ICE and closes what it opened', async () => {
  const opened = [];
  class ProbePeer extends FakePeerConnection {
    constructor(configuration) {
      super(configuration);
      opened.push(this);
      this.closed = false;
    }
    createDataChannel() { return {}; }
    async createOffer() { return { type: 'offer', sdp: 'v=0' }; }
    async setLocalDescription() {
      queueMicrotask(() => {
        this.candidate({ candidate: RELAY, relayProtocol: 'udp' });
        this.candidate({ candidate: RELAY, relayProtocol: 'tls' });
        this.iceGatheringState = 'complete';
        this.dispatchEvent(new Event('icegatheringstatechange'));
      });
    }
    close() { this.closed = true; }
  }

  const result = await probeRelay(normalizeIceConfig(grant()), { RTCPeerConnection: ProbePeer, timeoutMs: 1000 });

  assert.equal(result.ok, true);
  assert.deepEqual(result.nodes.map((n) => [n.id, n.relayCandidates, n.protocols]), [['mum', 2, ['udp', 'tls']]]);
  assert.equal(opened[0].configuration.iceTransportPolicy, 'relay');
  assert.equal(opened[0].configuration.iceServers.length, 1, 'Only that node\'s TURN server — no STUN.');
  assert.ok(opened.every((pc) => pc.closed));

  const none = await probeRelay(normalizeIceConfig({ iceServers: [{ urls: ['stun:s:3478'] }] }), { RTCPeerConnection: ProbePeer });
  assert.equal(none.verdict, 'no-relay-configured');
});
