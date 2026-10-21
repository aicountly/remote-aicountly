import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * ICE recovery in the live session engine (docs/ICE_CONTRACT.md).
 *
 * The browser's WebRTC is replaced by a peer connection whose states the test
 * sets, the signalling socket by a recorder, the API by stubs — what is under
 * test is the engine's decisions: who restarts ICE for a pair, that the other
 * side asks instead of offering, that fresh credentials reach the connection,
 * and that a relayed connection is moved onto them.
 */

const signalling = vi.hoisted(() => ({
  sent: [] as Array<{ type: string; to?: string; payload?: unknown }>,
  options: null as null | { onMessage: (message: unknown) => void },
}))

const api = vi.hoisted(() => ({
  iceFetches: 0,
  reports: [] as unknown[],
}))

vi.mock('../signalling/RemoteSignallingClient', () => ({
  RemoteSignallingClient: class {
    constructor(options: { onMessage: (message: unknown) => void }) {
      signalling.options = options
    }
    connect() {}
    close() {}
    send(message: { type: string; to?: string; payload?: unknown }) {
      signalling.sent.push(message)

      return true
    }
  },
}))

const grant = (username: string) => ({
  contract: 'aicountly.ice/1',
  iceServers: [
    { urls: ['stun:turn-mum.aicountly.com:3478'] },
    { urls: ['turns:turn-mum.aicountly.com:443?transport=tcp'], username, credential: 'hmac' },
  ],
  iceTransportPolicy: 'all',
  relayAvailable: true,
  expiresIn: 3600,
  refreshAfter: 2880,
  relayNodes: [{ id: 'mum', label: 'Mumbai', region: 'in-west', urls: ['turns:turn-mum.aicountly.com:443?transport=tcp'] }],
})

vi.mock('../api/remote', () => ({
  fetchSignallingCredentials: vi.fn(async () => ({
    token: 't',
    url: 'wss://signal.test',
    room: 'room',
    expiresAt: '',
    participantUuid: 'me',
    role: 'VIEWER',
    iceServers: grant('1:first').iceServers,
    relayAvailable: true,
    ice: grant('1:first'),
  })),
  fetchIceServers: vi.fn(async () => {
    api.iceFetches += 1

    return grant(`2:refresh-${api.iceFetches}`)
  }),
  reportConnectivity: vi.fn(async (_uuid: string, report: unknown) => {
    api.reports.push(report)
  }),
  markParticipantJoined: vi.fn(async () => ({})),
  reportPresence: vi.fn(async () => ({})),
  reportShareStarted: vi.fn(),
  reportShareStopped: vi.fn(),
  abortFileTransfer: vi.fn(),
  acceptFileTransfer: vi.fn(),
  completeFileTransfer: vi.fn(),
  declineFileTransfer: vi.fn(),
  fetchTransfers: vi.fn(async () => []),
  offerFileTransfer: vi.fn(),
  reportTransferProgress: vi.fn(),
}))

/** Enough of RTCPeerConnection for the engine; states are set by the test. */
class FakePeerConnection extends EventTarget {
  static instances: FakePeerConnection[] = []

  configuration: RTCConfiguration
  connectionState: RTCPeerConnectionState = 'new'
  iceConnectionState: RTCIceConnectionState = 'new'
  signalingState: RTCSignalingState = 'stable'
  remoteDescription: RTCSessionDescriptionInit | null = null
  offers: Array<RTCOfferOptions | undefined> = []
  channelsCreated = 0
  rollbacks = 0
  stats = new Map<string, Record<string, unknown>>()
  onconnectionstatechange: (() => void) | null = null
  onicecandidate: ((event: unknown) => void) | null = null
  ontrack: ((event: unknown) => void) | null = null
  ondatachannel: ((event: unknown) => void) | null = null

  constructor(configuration: RTCConfiguration) {
    super()
    this.configuration = configuration
    FakePeerConnection.instances.push(this)
  }

  getConfiguration() {
    return { ...this.configuration }
  }

  setConfiguration(configuration: RTCConfiguration) {
    this.configuration = configuration
  }

  createDataChannel(label: string) {
    this.channelsCreated += 1

    return { label, readyState: 'connecting', close() {}, send() {}, addEventListener() {}, removeEventListener() {} }
  }

  async createOffer(options?: RTCOfferOptions) {
    this.offers.push(options)

    return { type: 'offer' as const, sdp: `offer-${this.offers.length}` }
  }

  async createAnswer() {
    return { type: 'answer' as const, sdp: 'answer' }
  }

  async setLocalDescription(description: RTCSessionDescriptionInit) {
    if (description.type === 'rollback') {
      this.rollbacks += 1
      this.signalingState = 'stable'
    } else if (description.type === 'offer') {
      this.signalingState = 'have-local-offer'
    } else {
      this.signalingState = 'stable'
    }
  }

  async setRemoteDescription(description: RTCSessionDescriptionInit) {
    this.remoteDescription = description
    this.signalingState = description.type === 'offer' ? 'have-remote-offer' : 'stable'
  }

  async addIceCandidate() {}
  addTrack() {
    return {} as RTCRtpSender
  }
  removeTrack() {}
  close() {
    this.connectionState = 'closed'
  }

  async getStats() {
    return this.stats
  }

  setState(state: RTCPeerConnectionState) {
    this.connectionState = state
    this.iceConnectionState = state === 'connected' ? 'connected' : (state as RTCIceConnectionState)
    this.onconnectionstatechange?.()
    this.dispatchEvent(new Event('iceconnectionstatechange'))
    this.dispatchEvent(new Event('connectionstatechange'))
  }

  relayed() {
    this.stats = new Map([
      ['T', { id: 'T', type: 'transport', selectedCandidatePairId: 'P' }],
      ['P', { id: 'P', type: 'candidate-pair', localCandidateId: 'L', remoteCandidateId: 'R' }],
      ['L', { id: 'L', type: 'local-candidate', candidateType: 'relay', relayProtocol: 'tls', url: 'turns:turn-mum.aicountly.com:443?transport=tcp' }],
      ['R', { id: 'R', type: 'remote-candidate', candidateType: 'host' }],
    ])
  }
}

const flush = async () => {
  for (let i = 0; i < 20; i += 1) await Promise.resolve()
}

async function startEngine(participantUuid: string) {
  const { RemoteSessionEngine } = await import('./RemoteSessionEngine')
  const snapshots: Array<{ connection: string; lastError: string | null; network: { path: string; relayNode: string | null } }> = []

  const engine = new RemoteSessionEngine({
    sessionUuid: 'session-1',
    participantUuid,
    displayName: 'Me',
    onSnapshot: (snapshot) => snapshots.push(snapshot),
    onChatMessage: () => {},
    onPeerShareState: () => {},
    onSessionEnded: () => {},
  })
  await engine.start()

  return { engine, snapshots, signal: (message: unknown) => signalling.options?.onMessage(message) }
}

const browserPeer = (uuid: string) => ({ participantUuid: uuid, role: 'VIEWER', name: 'Them', capabilities: { remote_control: false } })
const agentPeer = (uuid: string) => ({ participantUuid: uuid, role: 'SHARER', name: 'PC', capabilities: { remote_control: true } })

describe('ICE recovery in the session engine', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] })
    FakePeerConnection.instances = []
    signalling.sent = []
    api.iceFetches = 0
    api.reports = []
    vi.stubGlobal('RTCPeerConnection', FakePeerConnection)
    vi.stubGlobal('RTCSessionDescription', class { constructor(init: RTCSessionDescriptionInit) { return init } })
    vi.stubGlobal('RTCIceCandidate', class { constructor(init: RTCIceCandidateInit) { return init } })
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('builds connections from the grant, with bundle and a candidate pool', async () => {
    const { engine, signal } = await startEngine('a-me')
    signal({ type: 'peer-joined', peer: browserPeer('b-them') })
    await flush()

    const pc = FakePeerConnection.instances[0]
    expect(pc.configuration.bundlePolicy).toBe('max-bundle')
    expect(pc.configuration.iceCandidatePoolSize).toBe(2)
    expect(pc.configuration.iceServers?.[1].username).toBe('1:first')
    engine.dispose()
  })

  it('drives the restart itself when it is the lower id: an ICE-restart offer, no new channels', async () => {
    const { engine, signal } = await startEngine('a-me')
    signal({ type: 'peer-joined', peer: browserPeer('b-them') })
    await flush()
    const pc = FakePeerConnection.instances[0]
    signal({ type: 'answer', from: 'b-them', payload: { type: 'answer', sdp: 'x' } })
    await flush()
    pc.setState('connected')
    await flush()

    pc.setState('failed')
    await flush()

    expect(pc.offers.at(-1)).toMatchObject({ iceRestart: true })
    expect(pc.channelsCreated).toBe(2)
    expect(signalling.sent.filter((m) => m.type === 'offer')).toHaveLength(2)
    expect(signalling.sent.some((m) => m.type === 'renegotiate')).toBe(false)
    engine.dispose()
  })

  it('asks the driver instead of offering when it is the higher id', async () => {
    const { engine, signal } = await startEngine('z-me')
    signal({ type: 'joined', peers: [browserPeer('a-them')] })
    await flush()
    signal({ type: 'offer', from: 'a-them', payload: { type: 'offer', sdp: 'o' } })
    await flush()
    const pc = FakePeerConnection.instances[0]
    pc.setState('connected')
    await flush()

    pc.setState('failed')
    await flush()

    expect(signalling.sent.at(-1)).toEqual({
      type: 'renegotiate',
      to: 'a-them',
      payload: { iceRestart: true, reason: 'failed' },
    })
    expect(pc.offers).toHaveLength(0)
    engine.dispose()
  })

  it('always drives toward a desktop agent, which answers restarts but never makes them', async () => {
    const { engine, signal } = await startEngine('z-me')
    signal({ type: 'joined', peers: [agentPeer('a-agent')] })
    await flush()
    signal({ type: 'offer', from: 'a-agent', payload: { type: 'offer', sdp: 'o' } })
    await flush()
    const pc = FakePeerConnection.instances[0]
    pc.setState('connected')
    await flush()

    pc.setState('failed')
    await flush()

    expect(pc.offers.at(-1)).toMatchObject({ iceRestart: true })
    expect(signalling.sent.some((m) => m.type === 'renegotiate')).toBe(false)
    engine.dispose()
  })

  it('answers a restart request once, ignoring the echo from the same failure', async () => {
    const { engine, signal } = await startEngine('a-me')
    signal({ type: 'peer-joined', peer: browserPeer('b-them') })
    await flush()
    const pc = FakePeerConnection.instances[0]
    signal({ type: 'answer', from: 'b-them', payload: { type: 'answer', sdp: 'x' } })
    await flush()

    signal({ type: 'renegotiate', from: 'b-them', payload: { iceRestart: true } })
    await flush()
    pc.signalingState = 'stable'
    signal({ type: 'renegotiate', from: 'b-them', payload: { iceRestart: true } })
    await flush()

    expect(pc.offers.filter((o) => o?.iceRestart)).toHaveLength(1)

    // An older client's plain renegotiate still gets a plain offer.
    signal({ type: 'renegotiate', from: 'b-them' })
    await flush()
    expect(pc.offers.at(-1)).toMatchObject({ iceRestart: false })
    engine.dispose()
  })

  it('resolves crossed offers: the polite side rolls back, the other keeps its own', async () => {
    const polite = await startEngine('z-me')
    polite.signal({ type: 'joined', peers: [browserPeer('a-them')] })
    await flush()
    const politePc = FakePeerConnection.instances[0]
    politePc.signalingState = 'have-local-offer'
    polite.signal({ type: 'offer', from: 'a-them', payload: { type: 'offer', sdp: 'o' } })
    await flush()
    expect(politePc.rollbacks).toBe(1)
    expect(signalling.sent.at(-1)?.type).toBe('answer')
    polite.engine.dispose()

    signalling.sent = []
    const impolite = await startEngine('a-me')
    impolite.signal({ type: 'joined', peers: [browserPeer('b-them')] })
    await flush()
    const impolitePc = FakePeerConnection.instances[1]
    impolitePc.signalingState = 'have-local-offer'
    impolite.signal({ type: 'offer', from: 'b-them', payload: { type: 'offer', sdp: 'o' } })
    await flush()
    expect(impolitePc.rollbacks).toBe(0)
    expect(signalling.sent.some((m) => m.type === 'answer')).toBe(false)
    impolite.engine.dispose()
  })

  it('renews credentials before they expire and moves a relayed connection onto them', async () => {
    const { engine, signal, snapshots } = await startEngine('a-me')
    signal({ type: 'peer-joined', peer: browserPeer('b-them') })
    await flush()
    const pc = FakePeerConnection.instances[0]
    signal({ type: 'answer', from: 'b-them', payload: { type: 'answer', sdp: 'x' } })
    await flush()
    pc.relayed()
    pc.setState('connected')
    await flush()

    expect(snapshots.at(-1)?.network).toMatchObject({ path: 'relay', relayNode: 'Mumbai' })

    pc.signalingState = 'stable'
    const offersBefore = pc.offers.length
    await vi.advanceTimersByTimeAsync(2880 * 1000)
    await flush()

    expect(api.iceFetches).toBe(1)
    expect(pc.configuration.iceServers?.[1].username).toBe('2:refresh-1')
    expect(pc.offers.length).toBe(offersBefore + 1)
    expect(pc.offers.at(-1)).toMatchObject({ iceRestart: true })
    engine.dispose()
  })

  it('gives up honestly, explains why, reports it, and can be asked to try again', async () => {
    const { engine, signal, snapshots } = await startEngine('a-me')
    signal({ type: 'peer-joined', peer: browserPeer('b-them') })
    await flush()
    const pc = FakePeerConnection.instances[0]
    signal({ type: 'answer', from: 'b-them', payload: { type: 'answer', sdp: 'x' } })
    await flush()
    pc.setState('connected')
    await flush()

    pc.setState('failed')
    await vi.advanceTimersByTimeAsync(60_000)
    await flush()

    const last = snapshots.at(-1)
    expect(last?.connection).toBe('failed')
    expect(last?.lastError).toMatch(/relay/i)
    expect(api.reports.some((r) => (r as { outcome: string }).outcome === 'failed')).toBe(true)
    expect(api.iceFetches).toBeGreaterThan(0)

    const offers = pc.offers.length
    pc.signalingState = 'stable'
    engine.retryConnection()
    await flush()
    expect(pc.offers.length).toBe(offers + 1)
    expect(snapshots.at(-1)?.connection).toBe('reconnecting')
    engine.dispose()
  })
})
