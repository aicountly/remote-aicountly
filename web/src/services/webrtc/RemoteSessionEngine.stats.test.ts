import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { EngineSnapshot } from './RemoteSessionEngine'

/**
 * The connection's figures (§49): the round trip and the frame rate beside the
 * indicator's word.
 *
 * They come from the engine's one statistics loop — the same `getStats()` that
 * gives the quality word, every two seconds — and only while a connection is
 * up. The round trip is the selected candidate pair's, not whichever pair
 * happened to succeed last; the frame rate is the picture's on screen. Nothing
 * is read while nothing is connected, the last figures are dropped then, and
 * disposing the engine stops the loop.
 */

const signalling = vi.hoisted(() => ({
  options: null as null | { onMessage: (message: unknown) => void },
}))

vi.mock('../signalling/RemoteSignallingClient', () => ({
  RemoteSignallingClient: class {
    constructor(options: { onMessage: (message: unknown) => void }) {
      signalling.options = options
    }
    connect() {}
    close() {}
    send() {
      return true
    }
  },
}))

vi.mock('../api/remote', () => ({
  fetchSignallingCredentials: vi.fn(async () => ({
    token: 't',
    url: 'wss://signal.test',
    room: 'room',
    expiresAt: '',
    participantUuid: 'me',
    role: 'VIEWER',
    iceServers: [{ urls: ['stun:turn-mum.aicountly.com:3478'] }],
    relayAvailable: false,
  })),
  fetchIceServers: vi.fn(),
  reportConnectivity: vi.fn(async () => undefined),
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

/** Enough of RTCPeerConnection for the engine; its state and its statistics are set by the test. */
class FakePeerConnection extends EventTarget {
  static instances: FakePeerConnection[] = []

  configuration: RTCConfiguration
  connectionState: RTCPeerConnectionState = 'new'
  iceConnectionState: RTCIceConnectionState = 'new'
  signalingState: RTCSignalingState = 'stable'
  remoteDescription: RTCSessionDescriptionInit | null = null
  stats = new Map<string, Record<string, unknown>>()
  statsReads = 0
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
    return {} as RTCRtpSender
  }
  removeTrack() {}
  close() {
    this.connectionState = 'closed'
  }

  async getStats() {
    this.statsReads += 1

    return this.stats
  }

  setState(state: RTCPeerConnectionState) {
    this.connectionState = state
    this.iceConnectionState = state === 'connected' ? 'connected' : (state as RTCIceConnectionState)
    this.onconnectionstatechange?.()
    this.dispatchEvent(new Event('iceconnectionstatechange'))
    this.dispatchEvent(new Event('connectionstatechange'))
  }

  /**
   * What Chromium reports for a live viewer: the transport names the selected pair; another pair that
   * also succeeded — listed after it — still carries the round trip from its last check; video and
   * audio arrive.
   */
  reporting({ rttSeconds, fps }: { rttSeconds: number; fps?: number }) {
    this.stats = new Map<string, Record<string, unknown>>([
      ['T', { id: 'T', type: 'transport', selectedCandidatePairId: 'P1' }],
      ['P1', { id: 'P1', type: 'candidate-pair', state: 'succeeded', nominated: true, currentRoundTripTime: rttSeconds, localCandidateId: 'L', remoteCandidateId: 'R' }],
      ['P2', { id: 'P2', type: 'candidate-pair', state: 'succeeded', currentRoundTripTime: 0.9, localCandidateId: 'L', remoteCandidateId: 'R' }],
      ['L', { id: 'L', type: 'local-candidate', candidateType: 'host' }],
      ['R', { id: 'R', type: 'remote-candidate', candidateType: 'host' }],
      ['V', { id: 'V', type: 'inbound-rtp', kind: 'video', packetsReceived: 5000, packetsLost: 0, ...(fps === undefined ? {} : { framesPerSecond: fps }) }],
      ['A', { id: 'A', type: 'inbound-rtp', kind: 'audio', packetsReceived: 900, packetsLost: 0 }],
    ])
  }
}

const flush = async () => {
  for (let i = 0; i < 20; i += 1) await Promise.resolve()
}

const browserPeer = (uuid: string) => ({ participantUuid: uuid, role: 'SHARER', name: 'Asha', capabilities: { remote_control: false } })

/** A viewer whose connection to the sharer is up, with the sharer's picture on screen. */
async function watching() {
  const { RemoteSessionEngine } = await import('./RemoteSessionEngine')
  const snapshots: EngineSnapshot[] = []

  const engine = new RemoteSessionEngine({
    sessionUuid: 'session-1',
    participantUuid: 'b-viewer',
    displayName: 'Bala',
    onSnapshot: (snapshot) => snapshots.push(snapshot),
    onChatMessage: () => {},
    onPeerShareState: () => {},
    onSessionEnded: () => {},
  })
  await engine.start()

  signalling.options?.onMessage({ type: 'peer-joined', peer: browserPeer('a-sharer') })
  await flush()

  const pc = FakePeerConnection.instances[0]
  pc.ontrack?.({ streams: [{ id: 'asha-screen' }] })
  pc.setState('connected')
  await flush()

  return { engine, pc, latest: () => snapshots.at(-1) as EngineSnapshot }
}

describe('the connection figures', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] })
    FakePeerConnection.instances = []
    vi.stubGlobal('RTCPeerConnection', FakePeerConnection)
    vi.stubGlobal('RTCSessionDescription', class { constructor(init: RTCSessionDescriptionInit) { return init } })
    vi.stubGlobal('RTCIceCandidate', class { constructor(init: RTCIceCandidateInit) { return init } })
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('reads the selected pair’s round trip and the picture’s frame rate every two seconds while connected', async () => {
    const { engine, pc, latest } = await watching()
    pc.reporting({ rttSeconds: 0.045, fps: 14.6 })

    await vi.advanceTimersByTimeAsync(2_000)

    // 45 ms from the selected pair, not the 900 ms an older pair last measured — and so "good", not "poor".
    expect(latest().stats).toEqual({ roundTripMs: 45, framesPerSecond: 15 })
    expect(latest().quality).toBe('good')

    pc.reporting({ rttSeconds: 0.32, fps: 4 })
    await vi.advanceTimersByTimeAsync(2_000)

    expect(latest().stats).toEqual({ roundTripMs: 320, framesPerSecond: 4 })
    expect(latest().quality).toBe('fair')

    engine.dispose()
  })

  it('gives the round trip alone while no picture is on screen', async () => {
    const { engine, pc, latest } = await watching()
    // The sharer stopped: the picture is taken off screen, and the track's last report says nothing of frames.
    ;(engine as unknown as { handleDataMessage: (peer: string, payload: unknown) => void }).handleDataMessage('a-sharer', {
      type: 'share-state',
      payload: { sharing: false },
    })
    pc.reporting({ rttSeconds: 0.06 })

    await vi.advanceTimersByTimeAsync(2_000)

    expect(latest().stats).toEqual({ roundTripMs: 60, framesPerSecond: null })

    engine.dispose()
  })

  it('reads nothing while nothing is connected, and drops the last figures', async () => {
    const { engine, pc, latest } = await watching()
    pc.reporting({ rttSeconds: 0.045, fps: 15 })
    await vi.advanceTimersByTimeAsync(2_000)
    expect(latest().stats).not.toBeNull()

    pc.setState('disconnected')
    await flush()
    const reads = pc.statsReads

    await vi.advanceTimersByTimeAsync(6_000)

    expect(pc.statsReads).toBe(reads)
    expect(latest().stats).toBeNull()

    engine.dispose()
  })

  it('stops reading when the engine is disposed', async () => {
    const { engine, pc } = await watching()
    await vi.advanceTimersByTimeAsync(2_000)
    const reads = pc.statsReads
    expect(reads).toBeGreaterThan(0)

    engine.dispose()
    await vi.advanceTimersByTimeAsync(10_000)

    expect(pc.statsReads).toBe(reads)
  })
})
