import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * The other person refreshed their page (reported from production).
 *
 * A participant uuid belongs to the person, not to the page: somebody signed in who reloads comes back
 * on the same uuid. The relay replaces their socket and deliberately announces no `peer-left` — that is
 * also what a re-minted token looks like, and tearing a live connection down for one would be wrong.
 *
 * So a reload used to arrive here as a bare `peer-joined` for a uuid we already had. The engine handed
 * back the peer entry built for the page that had just died and offered onto its RTCPeerConnection,
 * whose ICE transport had nowhere left to go, while the dead `remoteStream` stayed on screen. The sharer
 * reloaded, shared again, and the other person watched a blank video element.
 *
 * The connection epoch — one value per page load, repeated on every reconnect of that page — is what
 * tells the two apart.
 */

const signalling = vi.hoisted(() => ({
  sent: [] as Array<{ type: string; to?: string; payload?: unknown }>,
  options: null as null | { onMessage: (message: unknown) => void; epoch?: string },
}))

vi.mock('../signalling/RemoteSignallingClient', () => ({
  RemoteSignallingClient: class {
    constructor(options: { onMessage: (message: unknown) => void; epoch?: string }) {
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

const grant = () => ({
  contract: 'aicountly.ice/1',
  iceServers: [{ urls: ['stun:turn-mum.aicountly.com:3478'] }],
  iceTransportPolicy: 'all',
  relayAvailable: true,
  expiresIn: 3600,
  refreshAfter: 2880,
  relayNodes: [],
})

vi.mock('../api/remote', () => ({
  fetchSignallingCredentials: vi.fn(async () => ({
    token: 't',
    url: 'wss://signal.test',
    room: 'room',
    expiresAt: '',
    participantUuid: 'me',
    role: 'VIEWER',
    iceServers: grant().iceServers,
    relayAvailable: true,
    ice: grant(),
  })),
  fetchIceServers: vi.fn(async () => grant()),
  reportConnectivity: vi.fn(async () => {}),
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

const track = (id: string) => ({ id, kind: 'video', stop() {}, addEventListener() {}, removeEventListener() {} }) as unknown as MediaStreamTrack
const stream = (id: string) =>
  ({ id, getTracks: () => [track(id)], getVideoTracks: () => [track(id)] }) as unknown as MediaStream

class FakePeerConnection extends EventTarget {
  static instances: FakePeerConnection[] = []

  connectionState: RTCPeerConnectionState = 'new'
  iceConnectionState: RTCIceConnectionState = 'new'
  signalingState: RTCSignalingState = 'stable'
  remoteDescription: RTCSessionDescriptionInit | null = null
  closed = false
  onconnectionstatechange: (() => void) | null = null
  onicecandidate: ((event: unknown) => void) | null = null
  ontrack: ((event: unknown) => void) | null = null
  ondatachannel: ((event: unknown) => void) | null = null

  constructor(public configuration: RTCConfiguration) {
    super()
    FakePeerConnection.instances.push(this)
  }

  getConfiguration() {
    return { ...this.configuration }
  }
  setConfiguration(configuration: RTCConfiguration) {
    this.configuration = configuration
  }
  createDataChannel(label: string) {
    return { label, readyState: 'connecting', close() {}, send() {}, addEventListener() {}, removeEventListener() {} }
  }
  async createOffer() {
    return { type: 'offer' as const, sdp: 'offer' }
  }
  async createAnswer() {
    return { type: 'answer' as const, sdp: 'answer' }
  }
  async setLocalDescription(description: RTCSessionDescriptionInit) {
    this.signalingState = description.type === 'offer' ? 'have-local-offer' : 'stable'
  }
  async setRemoteDescription(description: RTCSessionDescriptionInit) {
    this.remoteDescription = description
    this.signalingState = description.type === 'offer' ? 'have-remote-offer' : 'stable'
  }
  async addIceCandidate() {}
  addTrack() {
    return { replaceTrack: async () => {}, track: null } as unknown as RTCRtpSender
  }
  removeTrack() {}
  close() {
    this.closed = true
    this.connectionState = 'closed'
  }
  async getStats() {
    return new Map()
  }
}

const flush = async () => {
  for (let i = 0; i < 20; i += 1) await Promise.resolve()
}

async function startEngine() {
  const { RemoteSessionEngine } = await import('./RemoteSessionEngine')
  const snapshots: Array<{ remoteStream: MediaStream | null; connection: string }> = []

  const engine = new RemoteSessionEngine({
    sessionUuid: 'session-1',
    participantUuid: 'b-viewer',
    displayName: 'Me',
    onSnapshot: (snapshot) => snapshots.push(snapshot as never),
    onChatMessage: () => {},
    onPeerShareState: () => {},
    onSessionEnded: () => {},
  })
  await engine.start()

  return { engine, snapshots, signal: (message: unknown) => signalling.options?.onMessage(message) }
}

/** The sharer, as the relay describes them. `epoch` is absent for an agent or an older build. */
const sharer = (epoch?: string) => ({
  participantUuid: 'a-sharer',
  role: 'SHARER',
  name: 'Asha',
  capabilities: { remote_control: false },
  ...(epoch === undefined ? {} : { epoch }),
})

describe('a peer that reloaded its page', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] })
    FakePeerConnection.instances = []
    signalling.sent = []
    signalling.options = null
    vi.stubGlobal('RTCPeerConnection', FakePeerConnection)
    vi.stubGlobal('RTCSessionDescription', class { constructor(init: RTCSessionDescriptionInit) { return init } })
    vi.stubGlobal('RTCIceCandidate', class { constructor(init: RTCIceCandidateInit) { return init } })
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('sends this page’s own epoch to the relay', async () => {
    const { engine } = await startEngine()

    expect(signalling.options?.epoch).toMatch(/^[A-Za-z0-9_-]{1,64}$/)

    engine.dispose()
  })

  it('builds a new connection, and drops the dead picture, when the epoch changes', async () => {
    const { engine, snapshots, signal } = await startEngine()
    const latest = () => snapshots.at(-1)?.remoteStream ?? null

    signal({ type: 'peer-joined', peer: sharer('page-one') })
    await flush()

    const first = FakePeerConnection.instances[0]
    const picture = stream('screen-1')
    first.ontrack?.({ streams: [picture] })
    expect(latest()).toBe(picture)
    expect(FakePeerConnection.instances).toHaveLength(1)

    // They refresh. Same uuid, same room, no `peer-left` — only a new epoch says the page is gone.
    signal({ type: 'peer-joined', peer: sharer('page-two') })
    await flush()

    expect(first.closed).toBe(true)
    expect(FakePeerConnection.instances).toHaveLength(2)
    expect(FakePeerConnection.instances[1].closed).toBe(false)

    // The stream the old page was sending ended with it; keeping it on screen is the blank frame.
    expect(latest()).toBeNull()

    // ...and the offer that follows goes onto the new connection, so the next share has somewhere to land.
    const offers = signalling.sent.filter((message) => message.type === 'offer' && message.to === 'a-sharer')
    expect(offers.length).toBeGreaterThanOrEqual(2)

    const second = stream('screen-2')
    FakePeerConnection.instances[1].ontrack?.({ streams: [second] })
    expect(latest()).toBe(second)

    engine.dispose()
  })

  it('keeps the connection when the same page reconnects with a re-minted token', async () => {
    const { engine, snapshots, signal } = await startEngine()

    signal({ type: 'peer-joined', peer: sharer('page-one') })
    await flush()

    const only = FakePeerConnection.instances[0]
    const picture = stream('screen-1')
    only.ontrack?.({ streams: [picture] })

    // The relay announces the replacement the same way; the epoch is what says it is the same page.
    signal({ type: 'peer-joined', peer: sharer('page-one') })
    await flush()

    expect(only.closed).toBe(false)
    expect(FakePeerConnection.instances).toHaveLength(1)
    expect(snapshots.at(-1)?.remoteStream).toBe(picture)

    engine.dispose()
  })

  it('leaves a peer that sends no epoch exactly as it was', async () => {
    const { engine, signal } = await startEngine()

    signal({ type: 'peer-joined', peer: sharer() })
    await flush()
    signal({ type: 'peer-joined', peer: sharer() })
    await flush()

    expect(FakePeerConnection.instances).toHaveLength(1)
    expect(FakePeerConnection.instances[0].closed).toBe(false)

    engine.dispose()
  })

  it('adopts an epoch on a connection opened by an offer that arrived first', async () => {
    const { engine, signal } = await startEngine()

    // An offer from a peer we have not been told about yet opens the connection.
    signal({ type: 'offer', from: 'a-sharer', payload: { type: 'offer', sdp: 'theirs' } })
    await flush()
    expect(FakePeerConnection.instances).toHaveLength(1)

    // The descriptor naming them must adopt that connection, not tear down a live negotiation.
    signal({ type: 'peer-joined', peer: sharer('page-one') })
    await flush()

    expect(FakePeerConnection.instances).toHaveLength(1)
    expect(FakePeerConnection.instances[0].closed).toBe(false)

    engine.dispose()
  })
})
