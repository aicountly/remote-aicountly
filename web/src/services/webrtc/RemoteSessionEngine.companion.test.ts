import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Control companions in the live session engine (docs/desktop/CONTROL_COMPANION.md).
 *
 * A CONTROL_COMPANION peer is the sharer's own desktop app, input-only. What is asserted: nobody offers it anything
 * when it arrives; a viewer connects to it (control channel only, no media) only while its own control is
 * REQUESTED or GRANTED, and drops it after; it never becomes the picture, the share state or the connection; an
 * offer it makes unasked is dropped; its pause and resume reach the snapshot; and a control hint goes both ways.
 */

const signalling = vi.hoisted(() => ({
  sent: [] as Array<{ type: string; to?: string; payload?: unknown }>,
  options: null as null | { onMessage: (message: unknown) => void },
}))

const api = vi.hoisted(() => ({ shareStarted: [] as unknown[][] }))

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

const grant = {
  contract: 'aicountly.ice/1',
  iceServers: [{ urls: ['stun:turn-mum.aicountly.com:3478'] }],
  iceTransportPolicy: 'all',
  relayAvailable: false,
  expiresIn: 3600,
  refreshAfter: 2880,
  relayNodes: [],
}

vi.mock('../api/remote', () => ({
  fetchSignallingCredentials: vi.fn(async () => ({
    token: 't',
    url: 'wss://signal.test',
    room: 'room',
    expiresAt: '',
    participantUuid: 'me',
    role: 'VIEWER',
    iceServers: grant.iceServers,
    relayAvailable: false,
    ice: grant,
  })),
  fetchIceServers: vi.fn(async () => grant),
  reportConnectivity: vi.fn(async () => undefined),
  markParticipantJoined: vi.fn(async () => ({})),
  reportPresence: vi.fn(async () => ({})),
  reportShareStarted: vi.fn(async (...args: unknown[]) => {
    api.shareStarted.push(args)
  }),
  reportShareStopped: vi.fn(),
  abortFileTransfer: vi.fn(),
  acceptFileTransfer: vi.fn(),
  completeFileTransfer: vi.fn(),
  declineFileTransfer: vi.fn(),
  fetchTransfers: vi.fn(async () => []),
  offerFileTransfer: vi.fn(),
  reportTransferProgress: vi.fn(),
}))

class FakeChannel {
  readyState: RTCDataChannelState = 'connecting'
  binaryType = 'blob'
  sent: unknown[] = []
  onopen: (() => void) | null = null
  onmessage: ((event: { data: unknown }) => void) | null = null
  constructor(public label: string) {}
  send(data: unknown) {
    this.sent.push(data)
  }
  close() {}
  addEventListener(type: string, listener: () => void) {
    if (type === 'open') this.onopenListeners.push(listener)
  }
  removeEventListener() {}
  onopenListeners: Array<() => void> = []
  open() {
    this.readyState = 'open'
    this.onopen?.()
    for (const listener of this.onopenListeners) listener()
  }
}

class FakePeerConnection extends EventTarget {
  static instances: FakePeerConnection[] = []

  configuration: RTCConfiguration
  connectionState: RTCPeerConnectionState = 'new'
  iceConnectionState: RTCIceConnectionState = 'new'
  signalingState: RTCSignalingState = 'stable'
  remoteDescription: RTCSessionDescriptionInit | null = null
  offers: Array<RTCOfferOptions | undefined> = []
  channels: FakeChannel[] = []
  added: MediaStreamTrack[] = []
  closed = false
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
    const channel = new FakeChannel(label)
    this.channels.push(channel)

    return channel
  }
  async createOffer(options?: RTCOfferOptions) {
    this.offers.push(options)

    return { type: 'offer' as const, sdp: `offer-${this.offers.length}` }
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
  addTrack(track: MediaStreamTrack) {
    this.added.push(track)

    return { track, replaceTrack: async () => undefined } as unknown as RTCRtpSender
  }
  removeTrack() {}
  close() {
    this.closed = true
    this.connectionState = 'closed'
  }
  async getStats() {
    return new Map()
  }
  setState(state: RTCPeerConnectionState) {
    this.connectionState = state
    this.onconnectionstatechange?.()
    this.dispatchEvent(new Event('connectionstatechange'))
  }
}

const flush = async () => {
  for (let i = 0; i < 20; i += 1) await Promise.resolve()
}

const track = (id: string, settings: Record<string, unknown> = {}) =>
  ({ id, kind: 'video', stop() {}, addEventListener() {}, removeEventListener() {}, getSettings: () => settings }) as unknown as MediaStreamTrack
const stream = (...tracks: MediaStreamTrack[]) =>
  ({ id: tracks.map((t) => t.id).join('+'), getTracks: () => tracks, getVideoTracks: () => tracks }) as unknown as MediaStream

const sharerPeer = { participantUuid: 'a-sharer', role: 'SHARER', name: 'Asha', capabilities: { remote_control: false } }
const viewerPeer = { participantUuid: 'b-viewer', role: 'VIEWER', name: 'Bala', capabilities: { remote_control: false } }
const companionPeer = {
  participantUuid: 'c-companion',
  role: 'CONTROL_COMPANION',
  name: 'Asha MacBook (control)',
  capabilities: { remote_control: true },
}

type Snapshot = {
  connection: string
  remoteStream: MediaStream | null
  controlPaused: string | null
  inputRefused: string | null
  secureDesktop: { active: boolean; reachable: boolean } | null
  peers: Array<{ participantUuid: string }>
}

async function startEngine(participantUuid: string) {
  const { RemoteSessionEngine } = await import('./RemoteSessionEngine')
  const snapshots: Snapshot[] = []
  const hints: number[] = []
  const shareStates: Array<[string, boolean]> = []
  const ended: number[] = []

  const engine = new RemoteSessionEngine({
    sessionUuid: 'session-1',
    participantUuid,
    displayName: 'Me',
    onSnapshot: (snapshot) => snapshots.push(snapshot as unknown as Snapshot),
    onChatMessage: () => {},
    onPeerShareState: (peer, sharing) => shareStates.push([peer, sharing]),
    onSessionEnded: () => ended.push(1),
    onControlHint: () => hints.push(1),
  })
  await engine.start()

  return {
    engine,
    latest: () => snapshots.at(-1) as Snapshot,
    hints,
    shareStates,
    ended,
    signal: async (message: unknown) => {
      signalling.options?.onMessage(message)
      await flush()
    },
  }
}

// As text: the channel turns a string frame into bytes itself, which avoids two realms' ArrayBuffers in jsdom.
const envelope = (m: Record<string, unknown>, p = 'c-companion', s = 'session-1') => JSON.stringify({ v: 1, s, p, n: 1, m })

describe('control companions in the engine', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] })
    FakePeerConnection.instances = []
    signalling.sent = []
    api.shareStarted = []
    vi.stubGlobal('RTCPeerConnection', FakePeerConnection)
    vi.stubGlobal('RTCSessionDescription', class { constructor(init: RTCSessionDescriptionInit) { return init } })
    vi.stubGlobal('RTCIceCandidate', class { constructor(init: RTCIceCandidateInit) { return init } })
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('offers nothing to a companion that arrives, and never sends it the share', async () => {
    const { engine, signal, latest } = await startEngine('a-sharer')

    await signal({ type: 'peer-joined', peer: viewerPeer })
    await signal({ type: 'peer-joined', peer: companionPeer })

    // One connection — the viewer's — and one offer, to the viewer.
    expect(FakePeerConnection.instances).toHaveLength(1)
    expect(signalling.sent.filter((m) => m.type === 'offer').map((m) => m.to)).toEqual(['b-viewer'])

    await engine.startSharing(stream(track('screen', { width: 3024, height: 1964 })), 'monitor')

    expect(FakePeerConnection.instances).toHaveLength(1)
    expect(signalling.sent.some((m) => m.to === 'c-companion')).toBe(false)
    expect(latest().peers.map((p) => p.participantUuid)).toEqual(['b-viewer'])

    // The captured size goes with share-started.
    expect(api.shareStarted.at(-1)).toEqual(['session-1', 'monitor', { width: 3024, height: 1964 }])

    engine.dispose()
  })

  it('does not count a companion as somebody to connect to', async () => {
    const { engine, signal, latest } = await startEngine('b-viewer')

    await signal({ type: 'joined', peers: [companionPeer] })

    expect(latest().connection).toBe('waiting-for-peer')
    expect(FakePeerConnection.instances).toHaveLength(0)

    engine.dispose()
  })

  it('connects to the companion only while its own control is requested or granted, control-only', async () => {
    const { engine, signal } = await startEngine('b-viewer')

    await signal({ type: 'joined', peers: [sharerPeer, companionPeer] })
    expect(FakePeerConnection.instances).toHaveLength(1) // the sharer, who will offer to us

    engine.setControlTarget('c-companion', 'NONE')
    await flush()
    expect(FakePeerConnection.instances).toHaveLength(1)

    engine.setControlTarget('c-companion', 'REQUESTED')
    await flush()

    expect(FakePeerConnection.instances).toHaveLength(2)
    const pc = FakePeerConnection.instances[1]
    expect(signalling.sent.filter((m) => m.type === 'offer').map((m) => m.to)).toEqual(['c-companion'])
    // Input only: the control channel and nothing else, no media either way.
    expect(pc.channels.map((c) => c.label)).toEqual(['aicountly-remote-control'])
    expect(pc.offers[0]).toMatchObject({ offerToReceiveAudio: false, offerToReceiveVideo: false })
    expect(pc.added).toHaveLength(0)

    // Granted keeps it; revoked drops it.
    engine.setControlTarget('c-companion', 'GRANTED')
    await flush()
    expect(FakePeerConnection.instances).toHaveLength(2)

    engine.setControlTarget('c-companion', 'REVOKED')
    await flush()
    expect(pc.closed).toBe(true)

    // And asks again connect again.
    engine.setControlTarget('c-companion', 'REQUESTED')
    await flush()
    expect(FakePeerConnection.instances).toHaveLength(3)

    engine.dispose()
  })

  it('connects when the companion arrives after the request', async () => {
    const { engine, signal } = await startEngine('b-viewer')

    await signal({ type: 'joined', peers: [] })
    engine.setControlTarget('c-companion', 'REQUESTED')
    await flush()
    expect(FakePeerConnection.instances).toHaveLength(0)

    await signal({ type: 'peer-joined', peer: companionPeer })

    expect(signalling.sent.filter((m) => m.type === 'offer').map((m) => m.to)).toEqual(['c-companion'])

    engine.dispose()
  })

  it('drops an offer a companion makes unasked', async () => {
    const { engine, signal } = await startEngine('a-sharer')

    await signal({ type: 'peer-joined', peer: companionPeer })
    await signal({ type: 'offer', from: 'c-companion', payload: { type: 'offer', sdp: 'v=0' } })

    expect(FakePeerConnection.instances).toHaveLength(0)
    expect(signalling.sent.some((m) => m.type === 'answer')).toBe(false)

    engine.dispose()
  })

  it('never takes a picture or a share state from a companion', async () => {
    const { engine, signal, latest, shareStates } = await startEngine('b-viewer')

    await signal({ type: 'joined', peers: [sharerPeer, companionPeer] })
    engine.setControlTarget('c-companion', 'REQUESTED')
    await flush()

    const companionPc = FakePeerConnection.instances[1]
    companionPc.ontrack?.({ streams: [stream(track('sneaky'))] })
    await signal({ type: 'share-state', from: 'c-companion', payload: { sharing: true } })

    expect(latest().remoteStream).toBeNull()
    expect(shareStates).toEqual([])

    // Its connection state is not the session's either.
    companionPc.setState('connected')
    expect(latest().connection).not.toBe('connected')

    engine.dispose()
  })

  it('pauses and resumes with the machine, and sends nothing while paused', async () => {
    const { engine, signal, latest } = await startEngine('b-viewer')

    await signal({ type: 'joined', peers: [companionPeer] })
    engine.setControlTarget('c-companion', 'GRANTED')
    await flush()

    const channel = FakePeerConnection.instances[0].channels[0]
    channel.open()
    expect(engine.startControlling('c-companion')).toBe(true)

    const deliver = (frame: string) => channel.onmessage?.({ data: frame })

    // A message claiming to be from somebody else is not believed.
    deliver(envelope({ type: 'control_paused', reason: 'local_input' }, 'someone-else'))
    expect(latest().controlPaused).toBeNull()

    deliver(envelope({ type: 'control_paused', reason: 'local_input' }))
    expect(latest().controlPaused).toBe('local_input')

    const before = channel.sent.length
    engine.sendControlPointer({ x: 0.5, y: 0.5 }, true)
    engine.sendControlScroll(0, 120)
    expect(channel.sent.length).toBe(before)

    deliver(envelope({ type: 'control_resumed' }))
    expect(latest().controlPaused).toBeNull()

    engine.sendControlPointer({ x: 0.5, y: 0.5 }, true)
    expect(channel.sent.length).toBe(before + 1)

    engine.dispose()
  })

  it('shows a Windows administrator prompt, and whether it can be answered here', async () => {
    const { engine, signal, latest } = await startEngine('b-viewer')

    await signal({ type: 'joined', peers: [companionPeer] })
    engine.setControlTarget('c-companion', 'GRANTED')
    await flush()

    const channel = FakePeerConnection.instances[0].channels[0]
    channel.open()
    expect(engine.startControlling('c-companion')).toBe(true)

    const deliver = (frame: string) => channel.onmessage?.({ data: frame })

    // A notice claiming to be from somebody we are not controlling is ignored.
    deliver(envelope({ type: 'secure_desktop', active: true, reachable: true }, 'someone-else'))
    expect(latest().secureDesktop).toBeNull()

    // A prompt that cannot be answered from here.
    deliver(envelope({ type: 'secure_desktop', active: true, reachable: false }))
    expect(latest().secureDesktop).toEqual({ active: true, reachable: false })

    // Then an authorised helper connects: it becomes answerable.
    deliver(envelope({ type: 'secure_desktop', active: true, reachable: true }))
    expect(latest().secureDesktop).toEqual({ active: true, reachable: true })

    // The prompt closes.
    deliver(envelope({ type: 'secure_desktop', active: false, reachable: false }))
    expect(latest().secureDesktop).toBeNull()

    engine.dispose()
  })

  /**
   * Control "granted" at both ends and nothing moving: the computer says why, and the engine holds
   * the reason for as long as the computer goes on saying it.
   */
  it('shows why the computer refused something, and takes it down once it stops saying so', async () => {
    const { engine, signal, latest } = await startEngine('b-viewer')

    await signal({ type: 'joined', peers: [companionPeer] })
    engine.setControlTarget('c-companion', 'GRANTED')
    await flush()

    const channel = FakePeerConnection.instances[0].channels[0]
    channel.open()
    expect(engine.startControlling('c-companion')).toBe(true)

    const deliver = (frame: string) => channel.onmessage?.({ data: frame })

    // Not from the computer being controlled: not believed.
    deliver(envelope({ type: 'input_refused', reason: 'elevated_window' }, 'someone-else'))
    expect(latest().inputRefused).toBeNull()

    deliver(envelope({ type: 'input_refused', reason: 'elevated_window' }))
    expect(latest().inputRefused).toBe('elevated_window')

    // Said again three seconds on, as the agent does while it goes on: still up, not flickering.
    vi.advanceTimersByTime(3_000)
    deliver(envelope({ type: 'input_refused', reason: 'elevated_window' }))
    vi.advanceTimersByTime(5_000)
    expect(latest().inputRefused).toBe('elevated_window')

    // And gone a little after it last said so.
    vi.advanceTimersByTime(2_000)
    expect(latest().inputRefused).toBeNull()

    // A reason this page has not heard of is still a refusal.
    deliver(envelope({ type: 'input_refused', reason: 'something_new' }))
    expect(latest().inputRefused).toBe('refused')

    // Stopping control takes it down at once.
    engine.stopControlling()
    expect(latest().inputRefused).toBeNull()

    engine.dispose()
  })

  /**
   * Ctrl+Alt+Del, as the room's menu sends it: the keys down in order, each carrying the modifiers
   * held by then, and up again in reverse — so nothing is left held on the other computer.
   */
  it('sends a key combination as whole key presses and releases, leaving nothing held', async () => {
    const { engine, signal } = await startEngine('b-viewer')

    await signal({ type: 'joined', peers: [companionPeer] })
    engine.setControlTarget('c-companion', 'GRANTED')
    await flush()

    const channel = FakePeerConnection.instances[0].channels[0]
    channel.open()
    expect(engine.startControlling('c-companion')).toBe(true)

    const before = channel.sent.length
    expect(engine.sendControlCombination('ctrl_alt_del')).toBe(true)

    const keys = channel.sent
      .slice(before)
      .map((frame) => JSON.parse(new TextDecoder().decode(frame as ArrayBuffer)).m as {
        type: string
        key: { k: string }
        pressed: boolean
        modifiers: { ctrl: boolean; alt: boolean; shift: boolean; meta: boolean }
      })

    expect(keys.map((m) => `${m.pressed ? 'down' : 'up'}:${m.key.k}`)).toEqual([
      'down:ctrl',
      'down:alt',
      'down:delete',
      'up:delete',
      'up:alt',
      'up:ctrl',
    ])
    // What the computer reads Ctrl+Alt+Del from: Delete going down with Ctrl and Alt, and nothing else.
    expect(keys[2].modifiers).toEqual({ ctrl: true, alt: true, shift: false, meta: false })
    expect(keys.at(-1)?.modifiers).toEqual({ ctrl: false, alt: false, shift: false, meta: false })

    // Paused: nothing is sent, and it says so.
    channel.onmessage?.({ data: envelope({ type: 'control_paused', reason: 'local_input' }) })
    expect(engine.sendControlCombination('start_menu')).toBe(false)

    engine.dispose()
  })

  /**
   * ⌘C on a Mac and Ctrl+C on a PC are the same act. Sent as typed, a PC helper's Ctrl+C reached a
   * Mac as a Control chord that copies nothing, and a Mac helper's ⌘V reached Windows as the Windows
   * key and V. The helper's key for these goes as the controlled computer's.
   */
  it('sends Ctrl as ⌘ to a Mac from a PC keyboard, and leaves a PC and an unknown computer alone', async () => {
    const { engine, signal } = await startEngine('b-viewer')

    await signal({ type: 'joined', peers: [companionPeer] })
    engine.setControlTarget('c-companion', 'GRANTED')
    await flush()

    const channel = FakePeerConnection.instances[0].channels[0]
    channel.open()
    expect(engine.startControlling('c-companion')).toBe(true)

    type Sent = { type: string; key: { k: string; c?: string }; pressed: boolean; modifiers: Record<string, boolean> }
    const sentSince = (from: number) =>
      channel.sent.slice(from).map((frame) => JSON.parse(new TextDecoder().decode(frame as ArrayBuffer)).m as Sent)
    const press = (key: string, pressed: boolean, modifiers: Partial<KeyboardEventInit> = {}) =>
      engine.sendControlKey(new KeyboardEvent(pressed ? 'keydown' : 'keyup', { key, ...modifiers }), pressed)

    const chord = () => {
      const from = channel.sent.length
      press('Control', true, { ctrlKey: true })
      press('c', true, { ctrlKey: true })
      press('c', false, { ctrlKey: true })
      press('Control', false)

      return sentSince(from)
    }

    // This browser is on a PC keyboard (jsdom is). The computer has not said what it is: as typed.
    expect(chord().map((m) => `${m.pressed ? 'down' : 'up'}:${m.key.k}`)).toEqual(['down:ctrl', 'down:character', 'up:character', 'up:ctrl'])

    // A Mac: Ctrl goes as ⌘, the key and the modifier state both.
    engine.setControlledPlatform('mac')
    const toMac = chord()
    expect(toMac.map((m) => `${m.pressed ? 'down' : 'up'}:${m.key.k}`)).toEqual(['down:meta', 'down:character', 'up:character', 'up:meta'])
    expect(toMac[1].key.c).toBe('c')
    expect(toMac[1].modifiers).toEqual({ ctrl: false, alt: false, shift: false, meta: true })

    // A Windows PC, from a PC keyboard: nothing to change.
    engine.setControlledPlatform('pc')
    const toPc = chord()
    expect(toPc.map((m) => m.key.k)).toEqual(['ctrl', 'character', 'character', 'ctrl'])
    expect(toPc[1].modifiers).toEqual({ ctrl: true, alt: false, shift: false, meta: false })

    // Told which keyboard it is while a key is held: that key is let go as it was sent, not left down.
    press('Control', true, { ctrlKey: true })
    const from = channel.sent.length
    engine.setControlledPlatform('mac')
    expect(sentSince(from).map((m) => `${m.pressed ? 'down' : 'up'}:${m.key.k}`)).toEqual(['up:ctrl'])

    // The menu's combinations are already in the controlled computer's own keys, and are not changed.
    const menuFrom = channel.sent.length
    expect(engine.sendControlCombination('interrupt')).toBe(true)
    expect(sentSince(menuFrom).map((m) => `${m.pressed ? 'down' : 'up'}:${m.key.k}`)).toEqual([
      'down:ctrl',
      'down:character',
      'up:character',
      'up:ctrl',
    ])

    engine.dispose()
  })

  it('sends no combination when nobody is being controlled', async () => {
    const { engine, signal } = await startEngine('b-viewer')

    await signal({ type: 'joined', peers: [companionPeer] })

    expect(engine.sendControlCombination('ctrl_alt_del')).toBe(false)

    engine.dispose()
  })

  it('stops controlling on an enveloped control_ended', async () => {
    const ended: string[] = []
    const { RemoteSessionEngine } = await import('./RemoteSessionEngine')
    const engine = new RemoteSessionEngine({
      sessionUuid: 'session-1',
      participantUuid: 'b-viewer',
      displayName: 'Me',
      onSnapshot: () => {},
      onChatMessage: () => {},
      onPeerShareState: () => {},
      onSessionEnded: () => {},
      onControlEnded: (reason) => ended.push(reason),
    })
    await engine.start()
    signalling.options?.onMessage({ type: 'joined', peers: [companionPeer] })
    await flush()
    engine.setControlTarget('c-companion', 'GRANTED')
    await flush()

    const channel = FakePeerConnection.instances[0].channels[0]
    channel.open()
    engine.startControlling('c-companion')

    channel.onmessage?.({ data: envelope({ type: 'control_ended', reason: 'stopped_locally' }) })

    expect(engine.controlling).toBe(false)
    expect(ended).toEqual(['stopped_locally'])

    engine.dispose()
  })

  it('passes a control hint both ways', async () => {
    const { engine, signal, hints } = await startEngine('b-viewer')

    engine.sendControlHint()
    expect(signalling.sent.at(-1)).toEqual({ type: 'control-hint' })

    await signal({ type: 'control-hint', from: 'a-sharer', payload: null })
    expect(hints).toHaveLength(1)

    engine.dispose()
  })
})

/**
 * Ending a session has to reach the other side. broadcast() prefers the data channel, and only the signalling path
 * acted on "session-ended", so the other person heard of an End from their own poll, up to 25 seconds later.
 */
describe('ending a session in the engine', () => {
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

  it('hears "session-ended" over the data channel', async () => {
    const { engine, signal, ended } = await startEngine('a-sharer')
    await signal({ type: 'peer-joined', peer: viewerPeer })

    const channel = FakePeerConnection.instances[0].channels[0]
    channel.open()
    channel.onmessage?.({ data: JSON.stringify({ type: 'session-ended', payload: {} }) })

    expect(ended).toHaveLength(1)

    engine.dispose()
  })

  it('announces the end on the signalling socket too, which still delivers as the connections close', async () => {
    const { engine, signal } = await startEngine('a-sharer')
    await signal({ type: 'peer-joined', peer: viewerPeer })

    const channel = FakePeerConnection.instances[0].channels[0]
    channel.open()
    engine.announceSessionEnded()

    expect(channel.sent.some((frame) => String(frame).includes('"session-ended"'))).toBe(true)
    expect(signalling.sent.filter((message) => message.type === 'session-ended')).toHaveLength(1)

    engine.dispose()
  })
})
