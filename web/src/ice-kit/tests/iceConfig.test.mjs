import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  applyIceConfig, isExpired, isRestartDriver, needsRefresh, normalizeIceConfig, relayNodeForUrl, rtcConfiguration,
} from '../index.js';
import { FakePeerConnection, grant } from './fakes.mjs';

test('a grant becomes one normalised config, timed on this client\'s clock', () => {
  const config = normalizeIceConfig(grant(), 1_000);

  assert.equal(config.contract, 'aicountly.ice/1');
  assert.equal(config.relayAvailable, true);
  assert.equal(config.iceServers.length, 2);
  // Relative times win over the server's absolute expiresAt, so clock skew cannot break a refresh.
  assert.equal(config.expiresAt, 1_000 + 3_600_000);
  assert.equal(config.refreshAt, 1_000 + 2_880_000);
  assert.equal(config.relayNodes[0].id, 'mum');
});

test('every product envelope is accepted', () => {
  // Remote: the grant nested in a signalling-token response.
  const remote = normalizeIceConfig({ token: 't', iceServers: [], ice: grant() }, 0);
  assert.equal(remote.relayNodes[0].id, 'mum');

  // Connect before the contract: turnConfigured, expiresIn, no relayNodes.
  const legacy = normalizeIceConfig({
    iceServers: [{ urls: ['turn:t.example:3478'], username: '1:u', credential: 'c' }],
    turnConfigured: true,
    expiresIn: 600,
    iceTransportPolicy: 'relay',
  }, 0);
  assert.equal(legacy.relayAvailable, true);
  assert.equal(legacy.iceTransportPolicy, 'relay');
  assert.equal(legacy.refreshAt, 480_000, 'Without refreshAfter, refresh at 80% of the lifetime.');

  assert.equal(normalizeIceConfig(null).iceServers.length, 0);
});

test('restart-on-renewal is on unless the server turns it off', () => {
  assert.equal(normalizeIceConfig(grant()).restartOnRenewal, true);
  assert.equal(normalizeIceConfig(grant({ restartOnRenewal: false })).restartOnRenewal, false);
  assert.equal(normalizeIceConfig({ iceServers: [] }).restartOnRenewal, true, 'An older server that does not say keeps the safe default.');
});

test('anything that is not a STUN/TURN server is dropped', () => {
  const config = normalizeIceConfig({
    iceServers: [{ urls: ['https://evil.example', 'stun:ok.example:3478'] }, { urls: 'javascript:alert(1)' }, 'nope', null],
  });

  assert.deepEqual(config.iceServers.map((s) => s.urls), [['stun:ok.example:3478']]);
});

test('relay-only is never kept without a relay to use', () => {
  const config = normalizeIceConfig({ iceServers: [{ urls: ['stun:s:3478'] }], relayAvailable: false, iceTransportPolicy: 'relay' });

  assert.equal(config.iceTransportPolicy, 'all');
});

test('a static credential never needs refreshing', () => {
  const config = normalizeIceConfig(grant({ expiresIn: null, refreshAfter: null, expiresAt: null }), 0);

  assert.equal(config.expiresAt, null);
  assert.equal(needsRefresh(config, Number.MAX_SAFE_INTEGER), false);
  assert.equal(isExpired(config, Number.MAX_SAFE_INTEGER), false);
});

test('refresh and expiry are judged against the client clock', () => {
  const config = normalizeIceConfig(grant(), 0);

  assert.equal(needsRefresh(config, 2_879_999), false);
  assert.equal(needsRefresh(config, 2_880_000), true);
  assert.equal(isExpired(config, 3_540_000, 60_000), true);
});

test('an RTCConfiguration carries bundle and rtcp-mux, and overrides apply', () => {
  const rtc = rtcConfiguration(normalizeIceConfig(grant()), { iceTransportPolicy: 'relay' });

  assert.equal(rtc.bundlePolicy, 'max-bundle');
  assert.equal(rtc.rtcpMuxPolicy, 'require');
  assert.equal(rtc.iceTransportPolicy, 'relay');
  assert.equal(rtc.iceServers[1].username, '1800003600:participant-1');
});

test('fresh credentials are applied to a live connection without touching the rest of its configuration', () => {
  const pc = new FakePeerConnection({ bundlePolicy: 'max-bundle', iceServers: [] });

  assert.equal(applyIceConfig(pc, normalizeIceConfig(grant())), true);
  assert.equal(pc.configuration.bundlePolicy, 'max-bundle');
  assert.equal(pc.configuration.iceServers.length, 2);

  const relayOnly = new FakePeerConnection({ iceTransportPolicy: 'relay', iceServers: [] });
  applyIceConfig(relayOnly, normalizeIceConfig(grant({ iceTransportPolicy: 'all' })));
  assert.equal(relayOnly.configuration.iceTransportPolicy, 'relay', 'A refresh never loosens relay-only.');

  const loose = new FakePeerConnection({ iceTransportPolicy: 'all', iceServers: [] });
  applyIceConfig(loose, normalizeIceConfig(grant({ iceTransportPolicy: 'relay' })));
  assert.equal(loose.configuration.iceTransportPolicy, 'relay', 'A grant may tighten to relay-only.');

  pc.signalingState = 'closed';
  assert.equal(applyIceConfig(pc, normalizeIceConfig(grant())), false, 'A closed connection is left alone.');

  const refusing = new FakePeerConnection();
  refusing.setConfiguration = () => { throw new Error('InvalidModificationError'); };
  assert.equal(applyIceConfig(refusing, normalizeIceConfig(grant())), false);
});

test('a URL is attributed to its relay node, exactly or by host and port', () => {
  const config = normalizeIceConfig(grant());

  assert.equal(relayNodeForUrl(config, 'turns:turn-mum.aicountly.com:443?transport=tcp')?.id, 'mum');
  assert.equal(relayNodeForUrl(config, 'turn:TURN-MUM.aicountly.com:3478')?.id, 'mum');
  assert.equal(relayNodeForUrl(config, 'turn:turn-ncr.aicountly.com:3478'), null);
  assert.equal(relayNodeForUrl(config, null), null);
});

test('exactly one side of a pair drives an ICE restart, and both agree which', () => {
  assert.equal(isRestartDriver('a1', 'b2'), true);
  assert.equal(isRestartDriver('b2', 'a1'), false);
});
