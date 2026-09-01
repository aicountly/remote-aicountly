/**
 * The live session, end to end.
 *
 * Ties together the signalling client, one peer connection per peer, the local
 * capture, the data channel, presence reporting and the connection-quality
 * reading — and exposes a single immutable snapshot for React to render.
 *
 * It is a plain class with a subscription, not a hook, for the reason given in
 * RemotePeerConnection: media and peer connections outlive renders and must be
 * torn down exactly once. `useRemoteSession` is a thin adapter over this.
 *
 * Negotiation rule: **the peer already in the room offers to the one that just
 * arrived.** There is no tie to break, so both sides never offer at once and no
 * rollback handling is needed.
 */

import {
  fetchSignallingCredentials,
  markParticipantJoined,
  reportPresence,
  reportShareStarted,
  reportShareStopped,
} from '../api/remote'
import { RemoteSignallingClient } from '../signalling/RemoteSignallingClient'
import type { SignallingMessage, SignallingPeer, SignallingStatus } from '../signalling/RemoteSignallingClient'
import { RemotePeerConnection } from './RemotePeerConnection'
import type { PeerConnectionQuality } from './RemotePeerConnection'
import type { ChatMessage } from '../../types/remote'

/** What the UI shows, in words a person understands (§49). */
export type LiveConnectionState =
  | 'idle'
  | 'connecting'
  | 'waiting-for-peer'
  | 'connected'
  | 'interrupted'
  | 'reconnecting'
  | 'failed'

export interface PointerPosition {
  /** Normalised 0..1 so the pointer lands correctly at any viewport size. */
  x: number
  y: number
  name: string
  colour: string
}

export interface AnnotationShape {
  id: string
  tool: 'pen' | 'arrow' | 'rectangle' | 'highlight'
  points: Array<{ x: number; y: number }>
  colour: string
  author: string
}

export interface EnginePeer {
  participantUuid: string
  role: string
  name: string
  capabilities: Record<string, boolean>
  connectionState: RTCPeerConnectionState | 'new'
  dataChannelReady: boolean
}

export interface EngineSnapshot {
  connection: LiveConnectionState
  signalling: SignallingStatus
  quality: PeerConnectionQuality
  peers: EnginePeer[]
  remoteStream: MediaStream | null
  localStream: MediaStream | null
  isSharing: boolean
  microphoneOn: boolean
  /** Live pointers from other people, keyed by participant uuid. */
  pointers: Record<string, PointerPosition>
  annotations: AnnotationShape[]
  relayAvailable: boolean
  lastError: string | null
}

interface EngineOptions {
  sessionUuid: string
  participantUuid: string
  displayName: string
  onSnapshot: (snapshot: EngineSnapshot) => void
  onChatMessage: (message: ChatMessage) => void
  /** The peer stopped or started sharing; the session view reacts. */
  onPeerShareState: (peerId: string, sharing: boolean) => void
  onSessionEnded: () => void
}

/** Pointer moves are throttled rather than sent per mousemove (§65). */
const POINTER_THROTTLE_MS = 50

/** How often the API is told this participant is still here. */
const PRESENCE_INTERVAL_MS = 20_000

const QUALITY_INTERVAL_MS = 5_000

/** Distinct, accessible-on-dark colours for each remote pointer. */
const POINTER_COLOURS = ['#25b003', '#2563eb', '#b76e00', '#d92d20', '#7c3aed']

export class RemoteSessionEngine {
  private signalling: RemoteSignallingClient | null = null
  private peers = new Map<string, { connection: RemotePeerConnection; info: EnginePeer }>()
  private localStream: MediaStream | null = null
  private microphoneStream: MediaStream | null = null
  private remoteStream: MediaStream | null = null
  private disposed = false

  private presenceTimer: ReturnType<typeof setInterval> | null = null
  private qualityTimer: ReturnType<typeof setInterval> | null = null
  private lastPointerSentAt = 0
  private lastReportedConnectionState: string | null = null

  private snapshot: EngineSnapshot = {
    connection: 'idle',
    signalling: 'idle',
    quality: 'unknown',
    peers: [],
    remoteStream: null,
    localStream: null,
    isSharing: false,
    microphoneOn: false,
    pointers: {},
    annotations: [],
    relayAvailable: false,
    lastError: null,
  }

  private iceServers: RTCIceServer[] = []

  constructor(private readonly options: EngineOptions) {}

  getSnapshot(): EngineSnapshot {
    return this.snapshot
  }

  /**
   * Mint a signalling token and open the room.
   *
   * The token is only issued to a participant the host has approved, so
   * reaching this point at all means approval already happened (§19).
   */
  async start(): Promise<void> {
    if (this.disposed) return

    this.patch({ connection: 'connecting', lastError: null })

    let credentials
    try {
      credentials = await fetchSignallingCredentials(this.options.sessionUuid)
    } catch (error) {
      this.patch({
        connection: 'failed',
        lastError: error instanceof Error ? error.message : 'Could not start the secure connection.',
      })

      return
    }

    this.iceServers = credentials.iceServers
    this.patch({ relayAvailable: credentials.relayAvailable })

    this.signalling = new RemoteSignallingClient({
      url: credentials.url,
      token: credentials.token,
      refreshToken: async () => {
        const fresh = await fetchSignallingCredentials(this.options.sessionUuid)
        this.iceServers = fresh.iceServers

        return { url: fresh.url, token: fresh.token }
      },
      onMessage: (message) => void this.handleSignal(message),
      onStatus: (status) => this.handleSignallingStatus(status),
    })

    this.signalling.connect()

    this.presenceTimer = setInterval(() => void this.reportPresenceNow(), PRESENCE_INTERVAL_MS)
    this.qualityTimer = setInterval(() => void this.pollQuality(), QUALITY_INTERVAL_MS)
  }

  // ------------------------------------------------------------- sharing

  /**
   * Attach a capture to every peer connection.
   *
   * `share-started` is reported to the API *first*: the server re-checks the
   * surface against policy and refuses if it is not permitted, and a refusal
   * must stop the stream rather than leave it attached (§16).
   */
  async startSharing(stream: MediaStream, displaySurface: string): Promise<void> {
    await reportShareStarted(this.options.sessionUuid, displaySurface)

    this.localStream = stream

    for (const { connection } of this.peers.values()) {
      await connection.setLocalStream(stream)
    }

    // The browser's own "Stop sharing" bar ends the track without telling the
    // page anything else. Without this listener the session would keep
    // claiming to share a screen that is already black (§86).
    const [videoTrack] = stream.getVideoTracks()
    videoTrack?.addEventListener('ended', () => void this.stopSharing('BROWSER_ENDED'), { once: true })

    this.broadcast({ type: 'share-state', payload: { sharing: true } })
    this.patch({ localStream: stream, isSharing: true })

    // A renegotiation is required for the new media to reach peers that were
    // already connected before sharing started.
    await this.renegotiateAll()
  }

  async stopSharing(reason = 'USER_STOPPED'): Promise<void> {
    if (this.localStream) {
      for (const track of this.localStream.getTracks()) {
        track.stop()
      }
    }

    this.localStream = null

    for (const { connection } of this.peers.values()) {
      await connection.setLocalStream(null)
    }

    this.broadcast({ type: 'share-state', payload: { sharing: false } })
    this.patch({ localStream: null, isSharing: false })

    try {
      await reportShareStopped(this.options.sessionUuid, reason)
    } catch {
      // The session is still usable; the record catches up on the next call.
    }

    await this.renegotiateAll()
  }

  async setMicrophone(stream: MediaStream | null): Promise<void> {
    if (this.microphoneStream && this.microphoneStream !== stream) {
      for (const track of this.microphoneStream.getTracks()) track.stop()
    }

    this.microphoneStream = stream

    for (const { connection } of this.peers.values()) {
      await connection.setLocalStream(stream ?? this.localStream)
    }

    this.patch({ microphoneOn: stream !== null })

    try {
      await reportPresence(
        this.options.sessionUuid,
        this.options.participantUuid,
        'CONNECTED',
        stream !== null,
      )
    } catch {
      /* presence is best-effort */
    }

    await this.renegotiateAll()
  }

  // ------------------------------------------------------- data channel

  /**
   * Send a chat message over the data channel.
   *
   * @returns true when it went peer-to-peer; false means the caller should fall
   *          back to the API relay, which is what the chat panel does.
   */
  sendChat(message: ChatMessage): boolean {
    return this.broadcast({ type: 'chat', payload: message })
  }

  /**
   * Move the pointer, throttled.
   *
   * A mousemove handler fires far faster than anyone can perceive; without the
   * throttle this is hundreds of data-channel messages a second for no visible
   * benefit (§65).
   */
  sendPointer(x: number, y: number): void {
    const now = Date.now()
    if (now - this.lastPointerSentAt < POINTER_THROTTLE_MS) return

    this.lastPointerSentAt = now

    this.broadcast({
      type: 'pointer',
      payload: { x, y, name: this.options.displayName },
    })
  }

  sendAnnotation(shape: AnnotationShape): void {
    this.broadcast({ type: 'annotation', payload: { action: 'add', shape } })

    this.patch({ annotations: [...this.snapshot.annotations, shape] })
  }

  clearAnnotations(): void {
    this.broadcast({ type: 'annotation', payload: { action: 'clear' } })
    this.patch({ annotations: [] })
  }

  announceSessionEnded(): void {
    this.broadcast({ type: 'session-ended', payload: {} })
  }

  // ---------------------------------------------------------- lifecycle

  /**
   * Stop everything: tracks, channels, peer connections, socket, timers.
   *
   * A missed track here is a camera or screen that keeps capturing after the
   * user thinks they left. It is the single most important method in the file.
   */
  dispose(): void {
    if (this.disposed) return
    this.disposed = true

    if (this.presenceTimer) clearInterval(this.presenceTimer)
    if (this.qualityTimer) clearInterval(this.qualityTimer)
    this.presenceTimer = null
    this.qualityTimer = null

    for (const stream of [this.localStream, this.microphoneStream]) {
      for (const track of stream?.getTracks() ?? []) {
        track.stop()
      }
    }
    this.localStream = null
    this.microphoneStream = null

    for (const { connection } of this.peers.values()) {
      connection.dispose()
    }
    this.peers.clear()

    this.signalling?.close()
    this.signalling = null

    this.remoteStream = null
  }

  // ---------------------------------------------------------- internals

  private handleSignallingStatus(status: SignallingStatus): void {
    const connection: LiveConnectionState =
      status === 'open'
        ? this.peers.size > 0
          ? this.snapshot.connection === 'connected'
            ? 'connected'
            : 'connecting'
          : 'waiting-for-peer'
        : status === 'reconnecting'
          ? 'reconnecting'
          : status === 'failed'
            ? 'failed'
            : status === 'connecting'
              ? 'connecting'
              : this.snapshot.connection

    this.patch({ signalling: status, connection })
  }

  private async handleSignal(message: SignallingMessage): Promise<void> {
    switch (message.type) {
      case 'joined': {
        // We are the newcomer. Everyone already here will offer to us; there is
        // nothing to do but be ready and tell the API we are in.
        for (const peer of message.peers ?? []) {
          this.registerPeer(peer)
        }

        this.patch({
          connection: (message.peers?.length ?? 0) > 0 ? 'connecting' : 'waiting-for-peer',
        })

        try {
          await markParticipantJoined(this.options.sessionUuid, this.options.participantUuid)
        } catch {
          /* the room is already open; the record catches up */
        }

        break
      }

      case 'peer-joined': {
        if (!message.peer) break

        const peer = this.registerPeer(message.peer)
        // We were here first, so we offer.
        await this.offerTo(peer.participantUuid)

        break
      }

      case 'offer': {
        if (!message.from) break

        const entry = this.ensurePeer(message.from)
        const answer = await entry.connection.acceptOffer(message.payload as RTCSessionDescriptionInit)

        // Attach whatever we are already sharing before answering, so the
        // answer describes the media we intend to send.
        await entry.connection.setLocalStream(this.localStream ?? this.microphoneStream)

        this.signalling?.send({ type: 'answer', to: message.from, payload: answer })

        break
      }

      case 'answer': {
        if (!message.from) break

        await this.peers.get(message.from)?.connection.acceptAnswer(
          message.payload as RTCSessionDescriptionInit,
        )

        break
      }

      case 'ice-candidate': {
        if (!message.from) break

        await this.peers.get(message.from)?.connection.addIceCandidate(
          message.payload as RTCIceCandidateInit,
        )

        break
      }

      case 'renegotiate': {
        if (!message.from) break
        await this.offerTo(message.from)

        break
      }

      case 'peer-left': {
        if (!message.from) break
        this.removePeer(message.from)

        break
      }

      case 'peer-unavailable': {
        // The peer we addressed is not in the room any more. Drop them rather
        // than retrying into the void.
        if (message.to) this.removePeer(message.to)

        break
      }

      case 'chat': {
        this.options.onChatMessage(message.payload as ChatMessage)

        break
      }

      case 'pointer': {
        this.applyPointer(message.from ?? '', message.payload as PointerPosition)

        break
      }

      case 'annotation': {
        this.applyAnnotation(message.payload as { action: string; shape?: AnnotationShape })

        break
      }

      case 'share-state': {
        const sharing = Boolean((message.payload as { sharing?: boolean })?.sharing)
        if (message.from) this.options.onPeerShareState(message.from, sharing)

        if (!sharing) {
          // The peer stopped sharing; drop the stale frozen frame rather than
          // leaving the last one on screen looking live.
          this.remoteStream = null
          this.patch({ remoteStream: null })
        }

        break
      }

      case 'session-ended': {
        this.options.onSessionEnded()

        break
      }

      case 'error': {
        this.patch({ lastError: message.message ?? 'The session service reported a problem.' })

        break
      }

      default:
        break
    }
  }

  private registerPeer(peer: SignallingPeer): EnginePeer {
    const entry = this.ensurePeer(peer.participantUuid)

    entry.info = {
      ...entry.info,
      role: peer.role,
      name: peer.name,
      capabilities: peer.capabilities ?? {},
    }

    this.publishPeers()

    return entry.info
  }

  private ensurePeer(peerId: string): { connection: RemotePeerConnection; info: EnginePeer } {
    const existing = this.peers.get(peerId)
    if (existing) return existing

    const connection = new RemotePeerConnection(peerId, this.iceServers, {
      onIceCandidate: (candidate) => {
        this.signalling?.send({ type: 'ice-candidate', to: peerId, payload: candidate })
      },
      onTrack: (stream) => {
        this.remoteStream = stream
        this.patch({ remoteStream: stream, connection: 'connected' })
      },
      onDataMessage: (payload) => this.handleDataMessage(peerId, payload),
      onStateChange: (state) => this.handlePeerState(peerId, state),
      onDataChannelOpen: () => {
        const entry = this.peers.get(peerId)
        if (entry) {
          entry.info = { ...entry.info, dataChannelReady: true }
          this.publishPeers()
        }
      },
    })

    const entry = {
      connection,
      info: {
        participantUuid: peerId,
        role: 'VIEWER',
        name: 'Participant',
        capabilities: {},
        connectionState: 'new' as const,
        dataChannelReady: false,
      },
    }

    this.peers.set(peerId, entry)

    return entry
  }

  private async offerTo(peerId: string): Promise<void> {
    const entry = this.ensurePeer(peerId)

    await entry.connection.setLocalStream(this.localStream ?? this.microphoneStream)

    const offer = await entry.connection.createOffer()
    this.signalling?.send({ type: 'offer', to: peerId, payload: offer })
  }

  /**
   * Ask every peer to renegotiate.
   *
   * We ask *them* to offer rather than offering ourselves: the peer that
   * received `peer-joined` is the offerer for that pair, and keeping that rule
   * consistent is what avoids glare on a mid-session media change.
   */
  private async renegotiateAll(): Promise<void> {
    for (const peerId of this.peers.keys()) {
      this.signalling?.send({ type: 'renegotiate', to: peerId })
    }
  }

  private removePeer(peerId: string): void {
    const entry = this.peers.get(peerId)
    if (!entry) return

    entry.connection.dispose()
    this.peers.delete(peerId)

    const { [peerId]: _removed, ...pointers } = this.snapshot.pointers

    this.remoteStream = this.peers.size > 0 ? this.remoteStream : null

    this.patch({
      pointers,
      remoteStream: this.remoteStream,
      connection: this.peers.size === 0 ? 'waiting-for-peer' : this.snapshot.connection,
    })

    this.publishPeers()
  }

  private handlePeerState(peerId: string, state: RTCPeerConnectionState): void {
    const entry = this.peers.get(peerId)
    if (entry) {
      entry.info = { ...entry.info, connectionState: state }
      this.publishPeers()
    }

    // The overall state is the best of the peers: with two viewers, one poor
    // connection should not make the session read as broken.
    const states = [...this.peers.values()].map((peer) => peer.info.connectionState)

    let connection: LiveConnectionState = this.snapshot.connection
    if (states.includes('connected')) connection = 'connected'
    else if (states.includes('connecting') || states.includes('new')) connection = 'connecting'
    else if (states.includes('disconnected')) connection = 'interrupted'
    else if (states.includes('failed')) connection = 'failed'

    this.patch({ connection })

    void this.reportConnectionState(connection)
  }

  private handleDataMessage(peerId: string, payload: unknown): void {
    const message = payload as { type?: string; payload?: unknown }

    switch (message?.type) {
      case 'chat':
        this.options.onChatMessage(message.payload as ChatMessage)
        break
      case 'pointer':
        this.applyPointer(peerId, message.payload as PointerPosition)
        break
      case 'annotation':
        this.applyAnnotation(message.payload as { action: string; shape?: AnnotationShape })
        break
      case 'share-state':
        this.options.onPeerShareState(peerId, Boolean((message.payload as { sharing?: boolean })?.sharing))
        break
      default:
        break
    }
  }

  private applyPointer(peerId: string, pointer: PointerPosition | undefined): void {
    if (!peerId || !pointer) return

    const index = [...this.peers.keys()].indexOf(peerId)

    this.patch({
      pointers: {
        ...this.snapshot.pointers,
        [peerId]: {
          x: Math.min(1, Math.max(0, Number(pointer.x) || 0)),
          y: Math.min(1, Math.max(0, Number(pointer.y) || 0)),
          name: String(pointer.name ?? 'Participant').slice(0, 60),
          colour: POINTER_COLOURS[Math.max(0, index) % POINTER_COLOURS.length],
        },
      },
    })
  }

  private applyAnnotation(payload: { action: string; shape?: AnnotationShape } | undefined): void {
    if (!payload) return

    if (payload.action === 'clear') {
      this.patch({ annotations: [] })

      return
    }

    if (payload.action === 'add' && payload.shape) {
      this.patch({ annotations: [...this.snapshot.annotations, payload.shape] })
    }
  }

  /**
   * Send over the data channel where it is open, and over the signalling
   * service otherwise.
   *
   * The data channel is preferred because it is peer-to-peer and does not touch
   * our infrastructure; the relay exists so a message sent while the channel is
   * still opening is not simply lost.
   */
  private broadcast(message: { type: string; payload: unknown }): boolean {
    let deliveredOverDataChannel = false

    for (const { connection } of this.peers.values()) {
      if (connection.send(message)) deliveredOverDataChannel = true
    }

    if (!deliveredOverDataChannel) {
      this.signalling?.send(message)
    }

    return deliveredOverDataChannel
  }

  private async reportPresenceNow(): Promise<void> {
    if (this.disposed) return

    try {
      await reportPresence(this.options.sessionUuid, this.options.participantUuid, 'CONNECTED')
    } catch {
      /* best-effort */
    }
  }

  private async reportConnectionState(state: LiveConnectionState): Promise<void> {
    const mapped =
      state === 'connected' ? 'CONNECTED' : state === 'interrupted' || state === 'failed' ? 'INTERRUPTED' : null

    // Only transitions are worth a request; the heartbeat covers the rest.
    if (mapped === null || mapped === this.lastReportedConnectionState) return

    this.lastReportedConnectionState = mapped

    try {
      await reportPresence(this.options.sessionUuid, this.options.participantUuid, mapped)
    } catch {
      /* best-effort */
    }
  }

  private async pollQuality(): Promise<void> {
    if (this.disposed || this.peers.size === 0) return

    const readings = await Promise.all(
      [...this.peers.values()].map(({ connection }) => connection.readQuality()),
    )

    const worst: PeerConnectionQuality = readings.includes('poor')
      ? 'poor'
      : readings.includes('fair')
        ? 'fair'
        : readings.includes('good')
          ? 'good'
          : 'unknown'

    this.patch({ quality: worst })
  }

  private publishPeers(): void {
    this.patch({ peers: [...this.peers.values()].map((peer) => peer.info) })
  }

  private patch(changes: Partial<EngineSnapshot>): void {
    if (this.disposed) return

    this.snapshot = { ...this.snapshot, ...changes }
    this.options.onSnapshot(this.snapshot)
  }
}
