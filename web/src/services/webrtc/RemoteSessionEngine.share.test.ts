import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Stopping a share and sharing again (found in the sandbox product test).
 *
 * The sharer used to remove its video sender when a share stopped. A removed sender's transceiver cannot be reused, so
 * the next share added a new m-line — which only an offer from the sharer can create, and Remote asks the *viewer* to
 * offer. The new share never reached the viewer, and the viewer, told "stopped" over the data channel, kept the last
 * frame on screen. Now the sender is kept and emptied, the next share fills it, and the viewer restores the peer's
 * stream when it hears "sharing" again.
 */

const signalling = vi.hoisted(() => ({
  sent: [] as Array<{ type: string; to?: string; payload?: unknown }>,
  options: null as null | { onMessage: (message: unknown) => void },
}))

const api = vi.hoisted(() => ({
  iceFetches: 0,
  reports: [] as unknown[],
  restartOnRenewal: true,
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

const grant = (username: string, extra: Record<string, unknown> = {}) => ({
  ...extra,
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

    return grant(`2:refresh-${api.iceFetches}`, { restartOnRenewal: api.restartOnRenewal })
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

class FakeSender {
  replaced: Array<MediaStreamTrack | null> = []
  constructor(public track: MediaStreamTrack | null) {}
  async replaceTrack(track: MediaStreamTrack | null) {
    this.replaced.push(track)
    this.track = track
  }
}

const track = (id: string, kind = 'video') => ({ id, kind, stop() {}, addEventListener() {}, removeEventListener() {} }) as unknown as MediaStreamTrack
const stream = (...tracks: MediaStreamTrack[]) => ({ id: tracks.map((t) => t.id).join('+'), getTracks: () => tracks, getVideoTracks: () => tracks.filter((t) => t.kind === 'video') }) as unknown as MediaStream

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
  added: FakeSender[] = []
  removed = 0
  addTrack(track: MediaStreamTrack) {
    const sender = new FakeSender(track)
    this.added.push(sender)

    return sender as unknown as RTCRtpSender
  }
  removeTrack() {
    this.removed += 1
  }
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

describe('stopping a share and sharing again', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] })
    FakePeerConnection.instances = []
    signalling.sent = []
    vi.stubGlobal('RTCPeerConnection', FakePeerConnection)
    vi.stubGlobal('RTCSessionDescription', class { constructor(init: RTCSessionDescriptionInit) { return init } })
    vi.stubGlobal('RTCIceCandidate', class { constructor(init: RTCIceCandidateInit) { return init } })
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('keeps the video sender when a share stops and fills it with the next share', async () => {
    const { engine, signal } = await startEngine('a-sharer')
    signal({ type: 'peer-joined', peer: browserPeer('b-viewer') })
    await flush()
    const pc = FakePeerConnection.instances[0]

    const first = track('screen-1')
    await engine.startSharing(stream(first), 'monitor')
    expect(pc.added).toHaveLength(1)

    await engine.stopSharing()
    expect(pc.removed).toBe(0)
    expect(pc.added[0].replaced).toEqual([null])

    const second = track('window-2')
    await engine.startSharing(stream(second), 'window')

    // The same sender carries the new share: no new transceiver, nothing that would need an offer from this side.
    expect(pc.added).toHaveLength(1)
    expect(pc.added[0].replaced).toEqual([null, second])
    expect(pc.added[0].track).toBe(second)

    engine.dispose()
  })

  it('takes the frozen frame off the viewer when the share stops, and puts the stream back when it starts again', async () => {
    const { engine, signal, snapshots } = await startEngine('b-viewer')
    signal({ type: 'peer-joined', peer: browserPeer('a-sharer') })
    await flush()
    const pc = FakePeerConnection.instances[0]
    const latest = () => (snapshots.at(-1) as unknown as { remoteStream: MediaStream | null }).remoteStream

    const media = stream(track('screen-1'))
    pc.ontrack?.({ streams: [media] })
    expect(latest()).toBe(media)

    // "Stopped" over the data channel, the usual path.
    ;(engine as unknown as { handleDataMessage: (peer: string, payload: unknown) => void }).handleDataMessage('a-sharer', {
      type: 'share-state',
      payload: { sharing: false },
    })
    expect(latest()).toBeNull()

    // Sharing again arrives on the same track: there is no new ontrack, the engine restores the stream itself.
    ;(engine as unknown as { handleDataMessage: (peer: string, payload: unknown) => void }).handleDataMessage('a-sharer', {
      type: 'share-state',
      payload: { sharing: true },
    })
    expect(latest()).toBe(media)

    // And the signalling path behaves the same.
    signal({ type: 'share-state', from: 'a-sharer', payload: { sharing: false } })
    await flush()
    expect(latest()).toBeNull()
    signal({ type: 'share-state', from: 'a-sharer', payload: { sharing: true } })
    await flush()
    expect(latest()).toBe(media)

    engine.dispose()
  })
})

/**
 * Expiry closes the participants on the server, and the signalling service stops renewing their tokens — but a peer
 * connection that already exists outlives both. The sharer's browser is the one sending the picture, so it is the one
 * that must stop: it drops whoever the session record says is no longer admitted.
 */
describe('somebody the server no longer admits', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] })
    FakePeerConnection.instances = []
    signalling.sent = []
    vi.stubGlobal('RTCPeerConnection', FakePeerConnection)
    vi.stubGlobal('RTCSessionDescription', class { constructor(init: RTCSessionDescriptionInit) { return init } })
    vi.stubGlobal('RTCIceCandidate', class { constructor(init: RTCIceCandidateInit) { return init } })
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('stops sending to them, but not over a connection made after the read that said so', async () => {
    const { engine, signal } = await startEngine('a-sharer')

    // A session record asked for before they connected says nothing about this connection.
    const staleRead = Date.now()
    vi.setSystemTime(staleRead + 1_000)
    signal({ type: 'peer-joined', peer: browserPeer('b-viewer') })
    await flush()
    const pc = FakePeerConnection.instances[0]
    await engine.startSharing(stream(track('screen-1')), 'monitor')

    engine.dropUnadmitted(['b-viewer'], staleRead)
    expect(pc.connectionState).not.toBe('closed')

    // One asked for after it does: the connection, and the picture on it, are closed.
    vi.setSystemTime(staleRead + 2_000)
    engine.dropUnadmitted(['b-viewer'], Date.now())
    expect(pc.connectionState).toBe('closed')
    expect(pc.removed).toBe(1)

    engine.dispose()
  })
})
