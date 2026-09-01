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
  abortFileTransfer,
  acceptFileTransfer,
  completeFileTransfer,
  declineFileTransfer,
  fetchSignallingCredentials,
  fetchTransfers,
  markParticipantJoined,
  offerFileTransfer,
  reportPresence,
  reportShareStarted,
  reportShareStopped,
  reportTransferProgress,
} from '../api/remote'
import { RemoteSignallingClient } from '../signalling/RemoteSignallingClient'
import type { SignallingMessage, SignallingPeer, SignallingStatus } from '../signalling/RemoteSignallingClient'
import { RemotePeerConnection } from './RemotePeerConnection'
import type { PeerConnectionQuality } from './RemotePeerConnection'
import { FileReceiver, FileSender, chunkCount, decodeChunk, safeFileName } from './fileTransfer'
import type { TransferView } from './fileTransfer'
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
  /** Live file transfers, both directions, newest first (§36). */
  transfers: TransferView[]
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

/** How often a running transfer tells the server where it has got to (§36). */
const TRANSFER_REPORT_MS = 2_000

/**
 * How often a running transfer re-renders.
 *
 * A 16 KiB chunk at any usable speed arrives hundreds of times a second. Painting
 * a progress bar that often costs far more than the transfer itself, and nobody
 * can read it — five updates a second looks continuous and is free.
 */
const TRANSFER_RENDER_MS = 200

/** Everything the engine keeps for one transfer; only `view` reaches React. */
interface TransferRecord {
  view: TransferView
  /** Demultiplexes chunks within one peer. Assigned by whoever sends. */
  slot: number
  /** The outgoing file, held until the recipient accepts. */
  file: Blob | null
  sender: FileSender | null
  receiver: FileReceiver | null
  lastReportedAt: number
  lastRenderedAt: number
}

/** A phase nothing further happens from. */
const TERMINAL_PHASES = ['completed', 'declined', 'cancelled', 'failed'] as const

function isTerminal(view: TransferView): boolean {
  return (TERMINAL_PHASES as readonly string[]).includes(view.phase)
}

function percentOf(bytes: number, total: number): number {
  return total > 0 ? Math.min(100, Math.round((bytes / total) * 100)) : 0
}

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

  /** Transfers by their server-issued uuid, in both directions. */
  private transfers = new Map<string, TransferRecord>()
  private nextSlot = 1

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
    transfers: [],
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

  // ------------------------------------------------------- file transfer

  /**
   * Offer a file to one peer (§36).
   *
   * The order is deliberate and is the whole of the security model:
   *
   *   1. the **server** registers the offer, which is where the organisation's
   *      switch, this user's permission, the size ceiling and the recipient's
   *      membership of this session are all enforced;
   *   2. only then is the peer told, over the data channel;
   *   3. and not a single byte moves until the recipient accepts.
   *
   * @throws Error with a machine code (`PEER_NOT_CONNECTED`, `RECIPIENT_REQUIRED`)
   *         or a RemoteApiError from the offer call, for the caller to show.
   */
  async offerFile(file: File, toParticipantUuid?: string | null): Promise<void> {
    const peerId = this.resolveTransferPeer(toParticipantUuid)
    const entry = this.peers.get(peerId)

    if (!entry || !entry.connection.dataChannelReady) {
      throw new Error('PEER_NOT_CONNECTED')
    }

    const transfer = await offerFileTransfer(this.options.sessionUuid, {
      toParticipantUuid: peerId,
      fileName: file.name,
      fileSize: file.size,
      mimeType: file.type || null,
    })

    const slot = this.nextSlot++

    this.transfers.set(transfer.uuid, {
      view: {
        uuid: transfer.uuid,
        direction: 'outgoing',
        // The server's stored name, not the local one: what is shown here is
        // what an administrator will see in the audit trail.
        fileName: transfer.fileName,
        fileSize: transfer.fileSize,
        mimeType: transfer.mimeType,
        phase: 'offered',
        bytesTransferred: 0,
        progress: 0,
        peerId,
        peerName: entry.info.name,
        error: null,
        blob: null,
      },
      slot,
      file,
      sender: null,
      receiver: null,
      lastReportedAt: 0,
      lastRenderedAt: 0,
    })

    this.publishTransfers()

    // The peer cannot attribute a chunk it was never told about, so this has to
    // arrive before any of them do. If it does not arrive at all, the offer is
    // dead on the wire — close the ledger row rather than leaving a row nobody
    // will ever answer, holding one of this sender's two outstanding slots.
    const announced = this.sendTo(peerId, {
      type: 'file-offer',
      payload: { transferUuid: transfer.uuid, slot },
    })

    if (!announced) {
      this.failTransfer(transfer.uuid, 'CHANNEL_CLOSED')

      throw new Error('PEER_NOT_CONNECTED')
    }
  }

  /**
   * Accept an incoming file. **This is the gate.**
   *
   * The size and name used from here on are the server's, not the sender's: the
   * ledger row is what was checked against the size ceiling, and holding the
   * sender to it is what stops a peer that claimed 1 KB from pushing megabytes.
   */
  async acceptTransfer(uuid: string): Promise<void> {
    const record = this.transfers.get(uuid)
    if (!record || record.view.direction !== 'incoming' || record.view.phase !== 'offered') return

    const transfer = await acceptFileTransfer(this.options.sessionUuid, uuid)
    const fileName = safeFileName(transfer.fileName)

    record.receiver = new FileReceiver(uuid, fileName, transfer.fileSize, chunkCount(transfer.fileSize))
    record.view = { ...record.view, fileName, fileSize: transfer.fileSize, phase: 'accepted' }

    this.publishTransfers()

    this.sendTo(record.view.peerId, { type: 'file-accept', payload: { transferUuid: uuid } })
  }

  /** Refuse an incoming file. Nothing was sent, so nothing has to be undone. */
  async declineTransfer(uuid: string): Promise<void> {
    const record = this.transfers.get(uuid)
    if (!record || record.view.direction !== 'incoming' || isTerminal(record.view)) return

    record.view = { ...record.view, phase: 'declined' }
    this.publishTransfers()

    this.sendTo(record.view.peerId, { type: 'file-decline', payload: { transferUuid: uuid } })

    await declineFileTransfer(this.options.sessionUuid, uuid).catch(() => undefined)
  }

  /** Either side stops a transfer that is offered, accepted or running. */
  async cancelTransfer(uuid: string): Promise<void> {
    const record = this.transfers.get(uuid)
    if (!record || isTerminal(record.view)) return

    // Cancel the loop before anything else: a 25 MB transfer must stop reading
    // slices now, not when the current pass finishes.
    record.sender?.cancel()
    record.receiver?.discard()

    record.view = { ...record.view, phase: 'cancelled' }
    this.publishTransfers()

    this.sendTo(record.view.peerId, { type: 'file-abort', payload: { transferUuid: uuid, status: 'CANCELLED' } })

    await abortFileTransfer(this.options.sessionUuid, uuid, 'CANCELLED').catch(() => undefined)
  }

  /**
   * Drop a finished transfer from the list.
   *
   * Also the only thing that releases a received file's memory, which is why
   * saving does not do it — a person may well save the same file twice.
   */
  dismissTransfer(uuid: string): void {
    const record = this.transfers.get(uuid)
    if (!record || !isTerminal(record.view)) return

    this.transfers.delete(uuid)
    this.publishTransfers()
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

    // Stop every transfer loop and release the buffers. A received file that
    // was never saved is dropped here, which is the correct outcome: it only
    // ever existed in this tab's memory.
    for (const record of this.transfers.values()) {
      record.sender?.cancel()
      record.receiver?.discard()
    }
    this.transfers.clear()

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
      onBinaryMessage: (message) => this.handleBinaryMessage(peerId, message),
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

    // A transfer with somebody who has left is not going to finish. Say so
    // rather than leaving a progress bar frozen at 40% forever.
    for (const record of this.transfers.values()) {
      if (record.view.peerId === peerId && !isTerminal(record.view)) {
        this.failTransfer(record.view.uuid, 'PEER_LEFT')
      }
    }

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

      // File transfer control. None of these carry file content — the bytes are
      // on the binary path, which never reaches this method (§36).
      case 'file-offer':
        void this.onFileOffered(peerId, message.payload)
        break
      case 'file-accept':
        void this.onFileAccepted(peerId, message.payload)
        break
      case 'file-decline':
        this.onFileDeclined(peerId, message.payload)
        break
      case 'file-abort':
        this.onFileAborted(peerId, message.payload)
        break
      case 'file-complete':
        this.onFileCompleted(peerId, message.payload)
        break

      default:
        break
    }
  }

  // ------------------------------------------------- file transfer internals

  /**
   * A peer says it has offered us a file.
   *
   * Nothing in this message is believed. The server's ledger is read instead,
   * and the offer is only shown if a row exists, is still awaiting an answer,
   * and is addressed to *this* participant. A peer that fabricates an offer —
   * or replays one meant for somebody else in the session — gets nothing.
   */
  private async onFileOffered(peerId: string, payload: unknown): Promise<void> {
    const { transferUuid, slot } = this.readTransferFrame(payload)
    if (transferUuid === null || slot === null) return
    if (this.transfers.has(transferUuid)) return

    let ledger
    try {
      ledger = (await fetchTransfers(this.options.sessionUuid)).find((row) => row.uuid === transferUuid)
    } catch {
      // Without the ledger there is nothing to verify against, so there is
      // nothing to show. The sender's own copy will time out.
      return
    }

    if (!ledger || ledger.status !== 'OFFERED') return
    if (ledger.to.uuid !== this.options.participantUuid) return
    if (this.disposed || this.transfers.has(transferUuid)) return

    this.transfers.set(transferUuid, {
      view: {
        uuid: transferUuid,
        direction: 'incoming',
        // Sanitised here as well as on the server: this is the value the Save
        // button turns into a path, and it arrived from another browser.
        fileName: safeFileName(ledger.fileName),
        fileSize: ledger.fileSize,
        mimeType: ledger.mimeType,
        phase: 'offered',
        bytesTransferred: 0,
        progress: 0,
        peerId,
        peerName: ledger.from.name ?? this.peers.get(peerId)?.info.name ?? 'Participant',
        error: null,
        blob: null,
      },
      slot,
      file: null,
      sender: null,
      receiver: null,
      lastReportedAt: 0,
      lastRenderedAt: 0,
    })

    this.publishTransfers()
  }

  /** The recipient said yes. This is the only place a send ever starts. */
  private async onFileAccepted(peerId: string, payload: unknown): Promise<void> {
    const { transferUuid } = this.readTransferFrame(payload)
    if (transferUuid === null) return

    const record = this.transfers.get(transferUuid)
    if (!record || record.view.direction !== 'outgoing' || record.view.phase !== 'offered') return
    if (record.view.peerId !== peerId || record.file === null) return

    const entry = this.peers.get(peerId)
    if (!entry) return

    const sender = new FileSender(record.file, record.slot, {
      send: (message) => entry.connection.sendBinary(message),
      bufferedAmount: () => entry.connection.bufferedAmount,
      waitForDrain: () => entry.connection.waitForDrain(),
    })

    record.sender = sender
    record.view = { ...record.view, phase: 'transferring' }
    this.publishTransfers()

    try {
      await sender.run((bytesSent) => this.onSendProgress(transferUuid, bytesSent))

      // Every byte is on the wire — but "arrived" is the recipient's word, not
      // ours, so the transfer stays running until `file-complete` comes back.
      this.onSendProgress(transferUuid, record.view.fileSize, true)
    } catch (error) {
      const code = error instanceof Error ? error.message : 'TRANSFER_FAILED'

      // A cancel from this side has already reported itself; do not report twice.
      if (code === 'CANCELLED') return

      this.failTransfer(transferUuid, code)
    }
  }

  private onFileDeclined(peerId: string, payload: unknown): void {
    const record = this.recordFromFrame(peerId, payload)
    if (!record || isTerminal(record.view)) return

    record.sender?.cancel()
    record.view = { ...record.view, phase: 'declined' }
    this.publishTransfers()
  }

  private onFileAborted(peerId: string, payload: unknown): void {
    const record = this.recordFromFrame(peerId, payload)
    if (!record || isTerminal(record.view)) return

    const status = String((payload as { status?: unknown })?.status ?? 'CANCELLED')

    record.sender?.cancel()
    record.receiver?.discard()
    record.view = {
      ...record.view,
      phase: status === 'FAILED' ? 'failed' : 'cancelled',
      blob: null,
    }

    this.publishTransfers()
  }

  private onFileCompleted(peerId: string, payload: unknown): void {
    const record = this.recordFromFrame(peerId, payload)
    if (!record || record.view.direction !== 'outgoing' || isTerminal(record.view)) return

    record.view = {
      ...record.view,
      phase: 'completed',
      bytesTransferred: record.view.fileSize,
      progress: 100,
      // The file is delivered; there is no reason to keep holding it.
      blob: null,
    }
    record.file = null

    this.publishTransfers()
  }

  /**
   * One framed chunk arrived.
   *
   * The guard that matters is the first one: a chunk whose slot has no accepted
   * receiver is dropped without allocating anything. Bytes are only ever
   * buffered for a transfer this person said yes to.
   */
  private handleBinaryMessage(peerId: string, message: ArrayBuffer): void {
    const chunk = decodeChunk(message)
    if (!chunk) return

    const record = this.receivingRecord(peerId, chunk.slot)
    if (!record?.receiver) return

    const outcome = record.receiver.accept(chunk.index, chunk.payload)

    if (outcome === 'ALREADY_DONE') return

    if (outcome === 'OUT_OF_ORDER' || outcome === 'TOO_LARGE') {
      // The channel is ordered and reliable, and the size was agreed. Either of
      // these means the sender is not doing what it said it would.
      record.receiver.discard()
      record.receiver = null
      void this.abortReceive(record.view.uuid, outcome)

      return
    }

    const received = record.receiver.bytesReceived

    record.view = {
      ...record.view,
      phase: 'transferring',
      bytesTransferred: received,
      progress: percentOf(received, record.view.fileSize),
    }

    if (outcome === 'complete') {
      record.view = {
        ...record.view,
        phase: 'completed',
        progress: 100,
        blob: record.receiver.toBlob(),
      }

      this.publishTransfers()
      void this.confirmReceive(record.view.uuid)

      return
    }

    const now = Date.now()
    if (now - record.lastRenderedAt >= TRANSFER_RENDER_MS) {
      record.lastRenderedAt = now
      this.publishTransfers()
    }
  }

  /** Tell the server, and the sender, that every byte arrived. */
  private async confirmReceive(uuid: string): Promise<void> {
    const record = this.transfers.get(uuid)
    if (!record) return

    this.sendTo(record.view.peerId, { type: 'file-complete', payload: { transferUuid: uuid } })

    await completeFileTransfer(this.options.sessionUuid, uuid).catch(() => undefined)
  }

  private async abortReceive(uuid: string, errorCode: string): Promise<void> {
    const record = this.transfers.get(uuid)
    if (!record) return

    record.view = { ...record.view, phase: 'failed', error: errorCode, blob: null }
    this.publishTransfers()

    this.sendTo(record.view.peerId, { type: 'file-abort', payload: { transferUuid: uuid, status: 'FAILED' } })

    await abortFileTransfer(this.options.sessionUuid, uuid, 'FAILED', errorCode).catch(() => undefined)
  }

  /**
   * Progress from the sending side.
   *
   * Two separate throttles, because they are paced by different things: the
   * screen by what a person can read, and the ledger by what is worth a request.
   */
  private onSendProgress(uuid: string, bytesSent: number, force = false): void {
    const record = this.transfers.get(uuid)
    if (!record || isTerminal(record.view)) return

    const now = Date.now()

    record.view = {
      ...record.view,
      bytesTransferred: bytesSent,
      progress: percentOf(bytesSent, record.view.fileSize),
    }

    if (force || now - record.lastRenderedAt >= TRANSFER_RENDER_MS) {
      record.lastRenderedAt = now
      this.publishTransfers()
    }

    if (force || now - record.lastReportedAt >= TRANSFER_REPORT_MS) {
      record.lastReportedAt = now

      void reportTransferProgress(this.options.sessionUuid, uuid, bytesSent).catch(() => undefined)
    }
  }

  private failTransfer(uuid: string, errorCode: string): void {
    const record = this.transfers.get(uuid)
    if (!record || isTerminal(record.view)) return

    record.sender?.cancel()
    record.receiver?.discard()
    record.view = { ...record.view, phase: 'failed', error: errorCode, blob: null }

    this.publishTransfers()

    this.sendTo(record.view.peerId, { type: 'file-abort', payload: { transferUuid: uuid, status: 'FAILED' } })

    void abortFileTransfer(this.options.sessionUuid, uuid, 'FAILED', errorCode).catch(() => undefined)
  }

  /** Which peer a file is going to, when the caller did not say. */
  private resolveTransferPeer(toParticipantUuid?: string | null): string {
    if (toParticipantUuid) {
      if (!this.peers.get(toParticipantUuid)?.connection.dataChannelReady) {
        throw new Error('PEER_NOT_CONNECTED')
      }

      return toParticipantUuid
    }

    const ready = [...this.peers.values()].filter((peer) => peer.connection.dataChannelReady)

    if (ready.length === 0) throw new Error('PEER_NOT_CONNECTED')
    // Guessing between two people is how a file reaches the wrong one.
    if (ready.length > 1) throw new Error('RECIPIENT_REQUIRED')

    return ready[0].info.participantUuid
  }

  private receivingRecord(peerId: string, slot: number): TransferRecord | null {
    for (const record of this.transfers.values()) {
      if (
        record.view.direction === 'incoming' &&
        record.view.peerId === peerId &&
        record.slot === slot &&
        record.receiver !== null
      ) {
        return record
      }
    }

    return null
  }

  /** @return the record only when this peer is really a party to it. */
  private recordFromFrame(peerId: string, payload: unknown): TransferRecord | null {
    const { transferUuid } = this.readTransferFrame(payload)
    if (transferUuid === null) return null

    const record = this.transfers.get(transferUuid)

    return record && record.view.peerId === peerId ? record : null
  }

  private readTransferFrame(payload: unknown): { transferUuid: string | null; slot: number | null } {
    const frame = payload as { transferUuid?: unknown; slot?: unknown } | null

    const transferUuid = typeof frame?.transferUuid === 'string' && frame.transferUuid.length <= 64
      ? frame.transferUuid
      : null

    const slot = Number(frame?.slot)

    return {
      transferUuid,
      slot: Number.isInteger(slot) && slot >= 0 && slot <= 0xffffffff ? slot : null,
    }
  }

  /** Point-to-point, over the data channel only: a file has one recipient. */
  private sendTo(peerId: string, message: { type: string; payload: unknown }): boolean {
    return this.peers.get(peerId)?.connection.send(message) ?? false
  }

  private publishTransfers(): void {
    this.patch({ transfers: [...this.transfers.values()].map((record) => record.view).reverse() })
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
