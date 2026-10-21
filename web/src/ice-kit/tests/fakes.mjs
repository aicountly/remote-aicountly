/**
 * Test doubles for the kit: a peer connection whose states the test sets, and a clock the test
 * advances. No browser, no WebRTC — the kit's decisions are what is under test.
 */

export class FakePeerConnection extends EventTarget {
  constructor(configuration = {}) {
    super();
    this.configuration = configuration;
    this.connectionState = 'new';
    this.iceConnectionState = 'new';
    this.iceGatheringState = 'new';
    this.signalingState = 'stable';
    this.stats = new Map();
    this.setConfigurationCalls = [];
  }

  getConfiguration() {
    return { ...this.configuration };
  }

  setConfiguration(configuration) {
    this.setConfigurationCalls.push(configuration);
    this.configuration = configuration;
  }

  async getStats() {
    return this.stats;
  }

  /** Move to a state and fire both events, as a browser does. */
  set(connection, ice = connection === 'connected' ? 'connected' : connection) {
    this.connectionState = connection;
    this.iceConnectionState = ice;
    this.dispatchEvent(new Event('iceconnectionstatechange'));
    this.dispatchEvent(new Event('connectionstatechange'));
  }

  candidate(candidate, url) {
    const event = new Event('icecandidate');
    event.candidate = candidate;
    event.url = url;
    this.dispatchEvent(event);
  }

  candidateError(errorCode, url) {
    const event = new Event('icecandidateerror');
    event.errorCode = errorCode;
    event.url = url;
    event.address = '192.0.2.44';
    this.dispatchEvent(event);
  }
}

export class ManualClock {
  constructor(start = 1_000_000) {
    this.time = start;
    this.timers = new Map();
    this.nextId = 1;
  }

  now() {
    return this.time;
  }

  setTimeout(fn, ms) {
    const id = this.nextId++;
    this.timers.set(id, { at: this.time + ms, fn });

    return id;
  }

  clearTimeout(id) {
    this.timers.delete(id);
  }

  /** Advance, running every timer that falls due — in order, including ones they schedule. */
  async advance(ms) {
    const until = this.time + ms;
    for (;;) {
      const due = [...this.timers.entries()].filter(([, t]) => t.at <= until).sort((a, b) => a[1].at - b[1].at)[0];
      if (!due) break;
      this.timers.delete(due[0]);
      this.time = due[1].at;
      due[1].fn();
      await flush();
    }
    this.time = until;
    await flush();
  }

  pending() {
    return this.timers.size;
  }
}

export async function flush() {
  for (let i = 0; i < 10; i += 1) await Promise.resolve();
}

export const grant = (overrides = {}) => ({
  contract: 'aicountly.ice/1',
  iceServers: [
    { urls: ['stun:turn-mum.aicountly.com:3478'] },
    {
      urls: ['turn:turn-mum.aicountly.com:3478?transport=udp', 'turn:turn-mum.aicountly.com:3478?transport=tcp', 'turns:turn-mum.aicountly.com:443?transport=tcp'],
      username: '1800003600:participant-1',
      credential: 'c2VjcmV0LWhtYWM=',
    },
  ],
  iceTransportPolicy: 'all',
  iceCandidatePoolSize: 0,
  relayAvailable: true,
  expiresIn: 3600,
  refreshAfter: 2880,
  expiresAt: '2027-01-15T08:00:00+00:00',
  relayNodes: [{
    id: 'mum', label: 'Mumbai', region: 'in-west', priority: 10, status: 'active', health: 'up',
    urls: ['turn:turn-mum.aicountly.com:3478?transport=udp', 'turn:turn-mum.aicountly.com:3478?transport=tcp', 'turns:turn-mum.aicountly.com:443?transport=tcp'],
  }],
  degraded: false,
  failover: false,
  ...overrides,
});
