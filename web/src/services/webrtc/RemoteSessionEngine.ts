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
 * arrived**, and a renegotiation is asked for rather than offered.
 *
 * ICE recovery (the ice-kit's ConnectionSupervisor) is the one case where both
 * sides notice the same thing at the same moment, so it has its own rule: for
 * each pair exactly one side *drives* — the browser when the peer is a desktop
 * agent (the agent answers restart offers but never makes one), otherwise the
 * lower participant id. The driver restarts ICE itself; the other side asks it
 * to. Fresh TURN credentials are fetched before they expire, and a relayed
 * connection is restarted onto them (docs/ICE_CONTRACT.md).
 *
 * Control companions (docs/desktop/CONTROL_COMPANION.md) are the exception to
 * "the peer already here offers": a CONTROL_COMPANION peer is a person's own
 * desktop app, input-only. Nobody offers it anything when it arrives, nobody
 * ever sends it media, chat or files, and it never becomes the picture, the
 * share state or the connection indicator. Only a viewer whose own control
 * state is REQUESTED or GRANTED connects to it — control-only, one data
 * channel — and drops that connection again when the state moves on.
 */

import {
  ConnectionSupervisor,
  CredentialRefresher,
  IceDiagnostics,
  describeVerdict,
  isRestartDriver,
  normalizeIceConfig,
} from '../../ice-kit/index.js'
import type {
  ConnectivityOutcome,
  IceConfig,
  IceVerdict,
  SelectedPath,
  SupervisorState,
} from '../../ice-kit/index.js'
import {
  abortFileTransfer,
  acceptFileTransfer,
  completeFileTransfer,
  declineFileTransfer,
  fetchIceServers,
  fetchSignallingCredentials,
  fetchTransfers,
  markParticipantJoined,
  offerFileTransfer,
  reportPresence,
  reportShareStarted,
  reportConnectivity,
  reportShareStopped,
  reportTransferProgress,
} from '../api/remote'
import { latencyMeter } from '../api/iceLatency'
import { RemoteSignallingClient } from '../signalling/RemoteSignallingClient'
import { readSurfaceSize } from './screenCapture'
import type { SurfaceSize } from './screenCapture'
import type { SignallingMessage, SignallingPeer, SignallingStatus } from '../signalling/RemoteSignallingClient'
import { RemotePeerConnection } from './RemotePeerConnection'
import type { PeerConnectionQuality, PeerConnectionReading } from './RemotePeerConnection'
import { FileReceiver, FileSender, chunkCount, decodeChunk, safeFileName } from './fileTransfer'
import type { TransferView } from './fileTransfer'
import {
  ControlSender,
  controlKeyFrom,
  decodeControlEnvelope,
  modifiersFrom,
  mouseButtonName,
} from './remoteControl'
// Aliased: this module already has a `PointerPosition`, and it is a different
// thing — the annotation pointer carries a name and a colour, the control one
// is a bare coordinate on the shared surface.
import type { ControlKey, PointerPosition as ControlPoint } from './remoteControl'
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
  /**
   * Whether the control channel to this peer is open.
   *
   * Not the same question as "may I control it" — that is policy, and the API
   * answers it. This is only whether the wire exists.
   */
  controlChannelReady: boolean
}

/**
 * How media is reaching the other side, and why it is not when it is not.
 *
 * Facts from the browser's own statistics — never assumed from configuration.
 * "Relay" is what the Security panel and support need to see: it is the
 * difference between "your network blocked a direct path and AICOUNTLY's relay
 * carried it" and "nothing could carry it".
 */
export interface NetworkStatus {
  path: SelectedPath['path']
  /** The relay node's label (e.g. "Mumbai") when the path is relayed. */
  relayNode: string | null
  relayProtocol: SelectedPath['relayProtocol']
  /** ICE is being restarted on at least one connection. */
  recovering: boolean
  /** ICE restarts since the session opened, recovery and credential renewal alike. */
  restarts: number
  /** Why a connection could not be recovered, once one has given up. */
  verdict: IceVerdict | null
  message: string | null
  /** When the TURN credentials in use stop working (ms epoch), if they expire at all. */
  credentialsExpireAt: number | null
}

/**
 * The figures beside the connection's word (§49): how long a round trip takes, and how many frames a
 * second of the shared screen are arriving — enough to tell a slow network from a slow computer.
 */
export interface ConnectionStats {
  /** The slowest connected peer's round trip, in milliseconds: like the quality word, the worst one counts. */
  roundTripMs: number | null
  /** The frame rate of the picture on screen, as received. Null while no picture is arriving here. */
  framesPerSecond: number | null
}

export interface EngineSnapshot {
  connection: LiveConnectionState
  signalling: SignallingStatus
  quality: PeerConnectionQuality
  /**
   * From the same reading as `quality`. Null while nothing is connected, so a figure from before a
   * drop never comes back as current.
   */
  stats: ConnectionStats | null
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
  /**
   * The relay was refused for capacity (this account's licence tier, on an overloaded or full node) — not
   * a missing configuration. Only a direct connection is possible until room opens.
   */
  relayDenied: boolean
  /**
   * The peer this browser is currently sending input to, if any.
   *
   * Set by `startControlling()` after the API has recorded the grant, cleared
   * by `stopControlling()`. Nothing is sent while it is null, which is this
   * side's half of "no grant, no input" — the agent's gate is the half that
   * counts.
   */
  controllingPeerId: string | null
  /**
   * Set while the machine being controlled has paused remote input because
   * somebody there is using its own mouse or keyboard (owner decision 7); the
   * reason the agent gave. Input sent meanwhile would be dropped, so none is.
   */
  controlPaused: string | null
  network: NetworkStatus
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
  /** The agent said control has ended, and why. */
  onControlEnded?: (reason: string) => void
  /** The agent described its displays; the viewer maps clicks against them. */
  onMonitorLayout?: (monitors: unknown[], activeMonitorId: number) => void
  /** Somebody in the room changed control state: re-read it now instead of on the next poll. */
  onControlHint?: () => void
}

/** The participant role of a control companion (docs/desktop/CONTROL_COMPANION.md). */
export const COMPANION_ROLE = 'CONTROL_COMPANION'

/** Pointer moves are throttled rather than sent per mousemove (§65). */
const POINTER_THROTTLE_MS = 50

/** How often the API is told this participant is still here. */
const PRESENCE_INTERVAL_MS = 20_000

/**
 * How often the connections' statistics are read (§49, §65).
 *
 * The indicator shows the round trip and the frame rate from this reading, and a figure five seconds
 * old reads as wrong to somebody watching the picture stutter. Two seconds is current enough, and
 * reading the statistics that often costs nothing beside the media itself. React hears of it in the
 * snapshot, once a reading — never once a frame.
 */
const STATS_INTERVAL_MS = 2_000

/**
 * A request to restart ICE that arrives this soon after one already started is
 * the other side noticing the same failure — answered by the restart in flight.
 */
const RESTART_DEDUPE_MS = 3_000

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

/** Whether two protocol keys are the same key. */
function sameKey(a: ControlKey, b: ControlKey): boolean {
  if (a.k !== b.k) return false

  return 'c' in a && 'c' in b ? a.c === b.c : true
}

/** Everything the engine keeps for one peer; only `info` reaches React. */
interface PeerEntry {
  connection: RemotePeerConnection
  info: EnginePeer
  supervisor: ConnectionSupervisor
  diagnostics: IceDiagnostics
  /** The supervisor's view: connected, unstable, recovering, failed. */
  recovery: SupervisorState
  /** The path last read from getStats, while connected. */
  path: SelectedPath | null
  lastRestartAt: number
  /** Whether the first successful connection has been reported. */
  reportedConnected: boolean
  /** When this connection was set up (Date.now()), so a session record read before it cannot end it. */
  createdAt: number
}

export class RemoteSessionEngine {
  private signalling: RemoteSignallingClient | null = null
  private peers = new Map<string, PeerEntry>()
  private localStream: MediaStream | null = null
  private microphoneStream: MediaStream | null = null
  private remoteStream: MediaStream | null = null
  /**
   * The media each peer last sent us. A share that stops leaves the track in place (muted, see
   * RemotePeerConnection.senders), so the next share arrives on the same stream without a new `ontrack`: it is put
   * back on screen when that peer says it is sharing again.
   */
  private peerStreams = new Map<string, MediaStream>()
  private disposed = false

  private presenceTimer: ReturnType<typeof setInterval> | null = null
  private statsTimer: ReturnType<typeof setInterval> | null = null
  private lastPointerSentAt = 0
  private lastReportedConnectionState: string | null = null

  /** Transfers by their server-issued uuid, in both directions. */
  private transfers = new Map<string, TransferRecord>()
  private nextSlot = 1

  /** The control sender for the peer being controlled, if any. */
  private control: { peerId: string; sender: ControlSender } | null = null

  /** Keys this browser has pressed and not released on the remote machine. */
  private heldKeys: ControlKey[] = []

  /**
   * Control companions present in the room, known from signalling. They get a
   * peer connection only while they are `companionTarget`.
   */
  private companionPeers = new Map<string, SignallingPeer>()

  /** The companion this browser should be connected to: set only while its own control is REQUESTED or GRANTED. */
  private companionTarget: string | null = null

  private snapshot: EngineSnapshot = {
    connection: 'idle',
    signalling: 'idle',
    quality: 'unknown',
    stats: null,
    peers: [],
    remoteStream: null,
    localStream: null,
    isSharing: false,
    microphoneOn: false,
    pointers: {},
    annotations: [],
    transfers: [],
    relayAvailable: false,
    relayDenied: false,
    controllingPeerId: null,
    controlPaused: null,
    network: {
      path: 'unknown',
      relayNode: null,
      relayProtocol: null,
      recovering: false,
      restarts: 0,
      verdict: null,
      message: null,
      credentialsExpireAt: null,
    },
    lastError: null,
  }

  /** The ICE configuration new and restarted connections use (aicountly.ice/1). */
  private ice: IceConfig = normalizeIceConfig(null)
  private refresher: CredentialRefresher | null = null
  private measuredLatency = false

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

    this.adoptIce(normalizeIceConfig(credentials))
    this.patch({ relayAvailable: credentials.relayAvailable, relayDenied: this.ice.admission.outcome === 'denied' })

    // Credentials are renewed before they expire, not when a relay starts
    // refusing them in the middle of somebody's session.
    this.refresher = new CredentialRefresher({
      fetch: () => fetchIceServers(this.options.sessionUuid),
      onRefreshed: (config) => void this.onIceRefreshed(config),
      // A renewal refused for capacity keeps the relay configuration the live connections already use.
      onDenied: () => this.patch({ relayDenied: true }),
    }).start(this.ice)

    this.signalling = new RemoteSignallingClient({
      url: credentials.url,
      token: credentials.token,
      refreshToken: async () => {
        const fresh = await fetchSignallingCredentials(this.options.sessionUuid)
        this.adoptIce(normalizeIceConfig(fresh))
        this.refresher?.start(this.ice)

        return { url: fresh.url, token: fresh.token }
      },
      onMessage: (message) => void this.handleSignal(message),
      onStatus: (status) => this.handleSignallingStatus(status),
    })

    this.signalling.connect()

    this.presenceTimer = setInterval(() => void this.reportPresenceNow(), PRESENCE_INTERVAL_MS)
    this.statsTimer = setInterval(() => void this.pollStats(), STATS_INTERVAL_MS)
  }

  // ------------------------------------------------------------- sharing

  /**
   * Attach a capture to every peer connection.
   *
   * `share-started` is reported to the API *first*: the server re-checks the
   * surface against policy and refuses if it is not permitted, and a refusal
   * must stop the stream rather than leave it attached (§16).
   */
  async startSharing(stream: MediaStream, displaySurface: string, size?: SurfaceSize | null): Promise<void> {
    const [videoTrack] = stream.getVideoTracks()

    // The captured size goes with it: a control companion matches its monitors
    // against it, and a change of size means matching again.
    await reportShareStarted(
      this.options.sessionUuid,
      displaySurface,
      size === undefined ? (videoTrack ? readSurfaceSize(videoTrack) : null) : size,
    )

    this.localStream = stream

    for (const { connection } of this.mediaEntries()) {
      await connection.setLocalStream(stream)
    }

    // The browser's own "Stop sharing" bar ends the track without telling the
    // page anything else. Without this listener the session would keep
    // claiming to share a screen that is already black (§86).
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

    for (const { connection } of this.mediaEntries()) {
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

    for (const { connection } of this.mediaEntries()) {
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

  /**
   * Tell the room this session has ended.
   *
   * Over the signalling socket as well as the data channel: the connections are
   * closed straight after this, and a socket delivers what was queued on it
   * before it closes, where closing a peer connection can drop a message still
   * on its way.
   */
  announceSessionEnded(): void {
    const message = { type: 'session-ended', payload: {} }

    if (this.broadcast(message)) this.signalling?.send(message)
  }

  /**
   * Close the connections to people the server no longer admits.
   *
   * The signalling service stops a token from being renewed, not a peer
   * connection that already exists: a browser that did not leave the room —
   * one that was closed as the session expired, or a modified one — kept
   * receiving the picture. The room hands over everybody whose participant row
   * is not APPROVED or JOINED, read in a request that started at `since`; a
   * connection set up after that may belong to somebody admitted in between,
   * so it is left alone until a later read says otherwise.
   */
  dropUnadmitted(participantUuids: readonly string[], since: number): void {
    for (const peerId of participantUuids) {
      const entry = this.peers.get(peerId)
      if (!entry || entry.createdAt >= since) continue

      // As if they had left: a companion is not reconnected until it joins again.
      this.companionPeers.delete(peerId)
      this.removePeer(peerId)
    }
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

  // ------------------------------------------------------- remote control

  /**
   * The participant whose machine can be controlled, if any.
   *
   * **Decided from negotiated capabilities, never from `clientType`.** A
   * browser peer reports `remote_control: false`, so this returns null for a
   * browser-to-browser session and the toolbar has nothing to offer — which is
   * the rule the whole desktop story rests on (§51).
   */
  controllableHost(): EnginePeer | null {
    for (const { info } of this.peers.values()) {
      if (info.capabilities.remote_control === true) return info
    }

    return null
  }

  /**
   * Begin sending input to a peer.
   *
   * Called **after** `POST /control/request` has been granted, never instead
   * of it. The agent runs every message through its own gate and drops
   * anything it was not granted, so calling this without a grant achieves
   * nothing — but the browser not offering it in the first place is what stops
   * a person clicking a button that would silently do nothing.
   */
  startControlling(peerId: string): boolean {
    const entry = this.peers.get(peerId)

    if (!entry?.connection.controlChannelReady) return false

    this.control = {
      peerId,
      sender: new ControlSender(this.options.sessionUuid, this.options.participantUuid, (bytes) =>
        entry.connection.sendControl(bytes),
      ),
    }

    this.patch({ controllingPeerId: peerId })

    return true
  }

  /**
   * Stop sending input.
   *
   * Local and unconditional. The API call that records it is separate, and
   * this does not wait for it: if the network is the reason control is being
   * stopped, waiting would be the worst possible behaviour.
   */
  stopControlling(): void {
    // Release anything still held, so a machine is not left with a modifier
    // down because the controller closed the tab mid-chord. Best effort —
    // the agent releases everything on its side too, for exactly this case.
    if (this.control) {
      for (const key of this.heldKeys) {
        this.control.sender.key(key, false, { ctrl: false, alt: false, shift: false, meta: false })
      }
    }

    this.heldKeys = []
    this.control = null

    this.patch({ controllingPeerId: null, controlPaused: null })
  }

  /**
   * Which control companion this browser should be connected to, from the
   * API's control state: the session's controllable host, but only while this
   * participant's own control is REQUESTED or GRANTED. Anything else drops the
   * connection — a companion serves one controller at a time.
   *
   * Has no effect on a host that is not a companion (an agent sharing its own
   * screen), which is connected the ordinary way.
   */
  setControlTarget(controllableHostUuid: string | null, myControlState: string | null): void {
    const wanted =
      controllableHostUuid !== null && (myControlState === 'REQUESTED' || myControlState === 'GRANTED')
        ? controllableHostUuid
        : null

    this.companionTarget = wanted
    void this.syncCompanions()
  }

  /**
   * Tell the room that control state changed, so a companion (and the other
   * browsers) re-read it from the API now. Carries nothing and grants nothing.
   */
  sendControlHint(): void {
    this.signalling?.send({ type: 'control-hint' })
  }

  /** Whether input is currently being sent. */
  get controlling(): boolean {
    return this.control !== null
  }

  /** Move the remote pointer. Throttled; `force` is for a move-and-click. */
  sendControlPointer(position: ControlPoint, force = false): void {
    if (this.snapshot.controlPaused !== null) return

    this.control?.sender.movePointer(position, force)
  }

  /** Press or release a remote mouse button. */
  sendControlButton(
    button: number,
    pressed: boolean,
    position?: ControlPoint,
    double = false,
  ): void {
    const name = mouseButtonName(button)
    if (!name || this.snapshot.controlPaused !== null) return

    this.control?.sender.button(name, pressed, position, double)
  }

  /** Scroll the remote screen. */
  sendControlScroll(deltaX: number, deltaY: number, position?: ControlPoint): void {
    if (this.snapshot.controlPaused !== null) return

    this.control?.sender.scroll(deltaX, deltaY, position)
  }

  /**
   * Press or release a remote key.
   *
   * Held keys are tracked so `stopControlling()` can release them. Without it,
   * a controller whose tab closed mid-chord leaves Ctrl down on somebody
   * else's machine — which reads, to the person sitting there, as a broken
   * keyboard.
   */
  sendControlKey(event: KeyboardEvent, pressed: boolean): boolean {
    if (!this.control || this.snapshot.controlPaused !== null) return false

    const key = controlKeyFrom(event)
    if (!key) return false

    if (pressed) {
      if (!this.heldKeys.some((held) => sameKey(held, key))) this.heldKeys.push(key)
    } else {
      this.heldKeys = this.heldKeys.filter((held) => !sameKey(held, key))
    }

    return this.control.sender.key(key, pressed, modifiersFrom(event))
  }

  /** Push this browser's clipboard text to the machine being controlled. */
  sendControlClipboard(text: string): boolean {
    return this.control?.sender.clipboard(text) ?? false
  }

  /**
   * A control message from the agent.
   *
   * The agent sends few: the monitor layout, and `control_ended` when the
   * person at the machine presses Stop. The second is the one that matters —
   * the agent has *already* stopped accepting input by the time it arrives, so
   * this is the browser catching up rather than the browser deciding.
   */
  private handleControlMessage(peerId: string, message: ArrayBuffer): void {
    const envelope = decodeControlEnvelope(message)

    if (!envelope || envelope.s !== this.options.sessionUuid) return

    // A companion names itself on every message it sends; one that names
    // somebody else is not believed.
    if (this.isCompanion(peerId) && envelope.p !== peerId) return

    if (envelope.m.type === 'control_ended' && this.control?.peerId === peerId) {
      this.stopControlling()
      this.patch({ lastError: null })

      this.options.onControlEnded?.(envelope.m.reason)
    }

    // Somebody at the machine is using it (owner decision 7). The agent has
    // already stopped applying input and released any held keys; this side
    // stops sending until it says it has resumed.
    if (envelope.m.type === 'control_paused' && this.control?.peerId === peerId) {
      this.heldKeys = []
      this.patch({ controlPaused: envelope.m.reason || 'local_input' })
    }

    if (envelope.m.type === 'control_resumed' && this.control?.peerId === peerId) {
      this.patch({ controlPaused: null })
    }

    if (envelope.m.type === 'monitor_layout') {
      this.options.onMonitorLayout?.(envelope.m.monitors, envelope.m.active_monitor_id)
    }
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
    if (this.statsTimer) clearInterval(this.statsTimer)
    this.presenceTimer = null
    this.statsTimer = null

    // Before anything else: stop sending input and release whatever is held.
    // A leaked modifier on somebody else's keyboard outlives this tab.
    this.stopControlling()

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

    this.refresher?.stop()
    this.refresher = null

    for (const { connection, supervisor, diagnostics } of this.peers.values()) {
      // The watchers first, so tearing the connection down is not seen as a
      // failure to recover from.
      supervisor.dispose()
      diagnostics.dispose()
      connection.dispose()
    }
    this.peers.clear()

    this.signalling?.close()
    this.signalling = null

    this.remoteStream = null
    this.peerStreams.clear()
    this.companionPeers.clear()
    this.companionTarget = null
  }

  // ---------------------------------------------------------- internals

  private handleSignallingStatus(status: SignallingStatus): void {
    const connection: LiveConnectionState =
      status === 'open'
        ? this.mediaEntries().length > 0
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
        let mediaPeers = 0
        for (const peer of message.peers ?? []) {
          if (this.notePeer(peer)) mediaPeers += 1
        }

        this.patch({
          connection: mediaPeers > 0 ? 'connecting' : 'waiting-for-peer',
        })

        void this.syncCompanions()

        try {
          await markParticipantJoined(this.options.sessionUuid, this.options.participantUuid)
        } catch {
          /* the room is already open; the record catches up */
        }

        break
      }

      case 'peer-joined': {
        if (!message.peer) break

        // A control companion is never offered anything on arrival: only a
        // viewer whose control is requested or granted connects to it.
        if (!this.notePeer(message.peer)) {
          await this.syncCompanions()

          break
        }

        // We were here first, so we offer.
        await this.offerTo(message.peer.participantUuid)

        break
      }

      case 'offer': {
        if (!message.from) break

        // A companion never makes the first offer. One that arrives anyway
        // from a companion this browser did not connect to is dropped.
        if (this.isCompanion(message.from) && !this.peers.has(message.from)) break

        const entry = this.ensurePeer(message.from)
        const answer = await entry.connection.acceptOffer(message.payload as RTCSessionDescriptionInit)

        // Our own offer crossed theirs and we are the side that keeps it;
        // they will answer ours.
        if (answer === null) break

        // Attach whatever we are already sharing before answering, so the
        // answer describes the media we intend to send — never to a companion.
        if (!this.isCompanion(message.from)) {
          await entry.connection.setLocalStream(this.localStream ?? this.microphoneStream)
        }

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
        if (this.isCompanion(message.from) && !this.peers.has(message.from)) break

        // `iceRestart`: the peer's connection is failing and we drive restarts
        // for this pair. An older client sends no payload and gets a plain
        // renegotiation, exactly as before.
        const iceRestart = (message.payload as { iceRestart?: unknown } | null)?.iceRestart === true
        if (iceRestart) {
          const entry = this.peers.get(message.from)
          if (!entry || Date.now() - entry.lastRestartAt < RESTART_DEDUPE_MS) break

          entry.lastRestartAt = Date.now()
          entry.diagnostics.noteRestart()
        }

        await this.offerTo(message.from, { iceRestart })

        break
      }

      case 'peer-left': {
        if (!message.from) break
        this.companionPeers.delete(message.from)
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
        if (message.from) {
          this.applyPeerShareState(message.from, Boolean((message.payload as { sharing?: boolean })?.sharing))
        }

        break
      }

      case 'control-hint': {
        this.options.onControlHint?.()

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
    // Capabilities are what say a peer is an agent, and so who drives.
    entry.connection.polite = !this.drivesRestartsFor(entry.info)

    this.publishPeers()

    return entry.info
  }

  /**
   * Note a peer from signalling. A control companion is only remembered; anybody
   * else gets a peer entry as before.
   *
   * @returns whether the peer is an ordinary (media) peer
   */
  private notePeer(peer: SignallingPeer): boolean {
    if (peer.role === COMPANION_ROLE) {
      this.companionPeers.set(peer.participantUuid, peer)

      return false
    }

    this.registerPeer(peer)

    return true
  }

  /** Connect to the target companion, and drop every other companion connection. */
  private async syncCompanions(): Promise<void> {
    if (this.disposed) return

    for (const [uuid, peer] of this.companionPeers) {
      const connected = this.peers.has(uuid)

      if (uuid === this.companionTarget) {
        if (!connected) {
          this.registerPeer(peer)
          await this.offerTo(uuid)
        }
      } else if (connected) {
        this.removePeer(uuid)
      }
    }
  }

  private isCompanion(peerId: string): boolean {
    return this.companionPeers.has(peerId)
  }

  /** Every peer connection that carries the session itself — everything but companions. */
  private mediaEntries(): PeerEntry[] {
    return [...this.peers.entries()].filter(([peerId]) => !this.isCompanion(peerId)).map(([, entry]) => entry)
  }

  private ensurePeer(peerId: string): PeerEntry {
    const existing = this.peers.get(peerId)
    if (existing) return existing

    const connection = new RemotePeerConnection(peerId, this.ice, {
      onIceCandidate: (candidate) => {
        this.signalling?.send({ type: 'ice-candidate', to: peerId, payload: candidate })
      },
      onTrack: (stream) => {
        // A companion sends no media; anything it sent would not be the shared screen.
        if (this.isCompanion(peerId)) return

        this.peerStreams.set(peerId, stream)
        this.remoteStream = stream
        this.patch({ remoteStream: stream, connection: 'connected' })
      },
      onDataMessage: (payload) => this.handleDataMessage(peerId, payload),
      onBinaryMessage: (message) => this.handleBinaryMessage(peerId, message),
      onControlMessage: (message) => this.handleControlMessage(peerId, message),
      onStateChange: (state) => this.handlePeerState(peerId, state),
      onDataChannelOpen: () => {
        const entry = this.peers.get(peerId)
        if (entry) {
          entry.info = {
            ...entry.info,
            dataChannelReady: entry.connection.dataChannelReady,
            controlChannelReady: entry.connection.controlChannelReady,
          }
          this.publishPeers()
        }
      },
    })

    const info: EnginePeer = {
      participantUuid: peerId,
      role: 'VIEWER',
      name: 'Participant',
      capabilities: {},
      connectionState: 'new' as const,
      dataChannelReady: false,
      controlChannelReady: false,
    }
    connection.polite = !this.drivesRestartsFor(info)
    connection.controlOnly = this.isCompanion(peerId)

    const pc = connection.peerConnection
    const entry: PeerEntry = {
      connection,
      info,
      diagnostics: new IceDiagnostics(pc, { config: this.ice }),
      supervisor: new ConnectionSupervisor({
        pc,
        restart: ({ reason }) => this.restartIce(peerId, reason),
        refreshCredentials: () => this.refresher?.refreshNow() ?? Promise.resolve(null),
        getConfig: () => this.ice,
        onStateChange: (state) => this.handleRecoveryState(peerId, state),
        onGiveUp: () => this.handleGiveUp(peerId),
        onRecovered: () => void this.sendReport(peerId, 'recovered'),
      }),
      recovery: 'new',
      path: null,
      lastRestartAt: 0,
      reportedConnected: false,
      createdAt: Date.now(),
    }

    this.peers.set(peerId, entry)

    return entry
  }

  private async offerTo(peerId: string, options: { iceRestart?: boolean } = {}): Promise<void> {
    const entry = this.ensurePeer(peerId)

    if (!this.isCompanion(peerId)) {
      await entry.connection.setLocalStream(this.localStream ?? this.microphoneStream)
    }

    const offer = await entry.connection.createOffer(options)
    this.signalling?.send({ type: 'offer', to: peerId, payload: offer })
  }

  /**
   * Whether this browser restarts ICE for the pair, or asks the other side to.
   *
   * A desktop agent answers a restart offer but never makes one, so with an
   * agent the browser always drives. Between two browsers the lower id does —
   * both compute the same answer without asking each other.
   */
  private drivesRestartsFor(peer: EnginePeer): boolean {
    if (peer.capabilities.remote_control === true) return true

    return isRestartDriver(this.options.participantUuid, peer.participantUuid)
  }

  /** The supervisor's `restart`: offer with new ICE credentials, or ask the driver to. */
  private async restartIce(peerId: string, reason: string): Promise<void> {
    const entry = this.peers.get(peerId)
    if (!entry || this.disposed) return

    entry.lastRestartAt = Date.now()
    entry.diagnostics.noteRestart()
    this.publishNetwork()

    if (!this.drivesRestartsFor(entry.info)) {
      this.signalling?.send({ type: 'renegotiate', to: peerId, payload: { iceRestart: true, reason } })

      return
    }

    // Nothing negotiated yet means nothing to restart: the first offer is
    // still on its way and will gather with the current servers anyway.
    if (!entry.connection.negotiated) return

    await this.offerTo(peerId, { iceRestart: true })
  }

  /** Point every connection at a newer grant. Used for the next gathering. */
  private adoptIce(config: IceConfig): void {
    this.ice = config
    if (config.relayAvailable) this.patch({ relayAvailable: true, relayDenied: false })

    for (const entry of this.peers.values()) {
      entry.connection.applyIceConfig(config)
      entry.diagnostics.setConfig(config)
    }

    this.publishNetwork()
  }

  /**
   * Fresh credentials arrived. A connection whose own side is relayed is still
   * using an allocation made with the old credential. A relay always refuses a
   * NEW allocation on an expired credential, and some builds also drop the old
   * one — so by default it is restarted now, while the old path still works,
   * rather than discovering that later. `restartOnRenewal: false` (set once
   * `probe.php --expiry-test` shows the relay keeps live allocations) keeps the
   * new credential for the next gathering and skips the restart.
   */
  private async onIceRefreshed(config: IceConfig): Promise<void> {
    if (this.disposed) return
    this.adoptIce(config)

    for (const entry of this.peers.values()) {
      entry.diagnostics.noteCredentialRefresh()
      // The new credential is already applied (adoptIce); the restart only moves a relayed connection
      // onto it, which an operator can switch off once their relay is known to keep live allocations.
      if (!config.restartOnRenewal) continue
      if (entry.info.connectionState !== 'connected' || entry.supervisor.recovering) continue

      const path = await entry.diagnostics.selectedPath()
      if (path.localCandidateType === 'relay') void entry.supervisor.requestRestart('credential_refresh')
    }
  }

  private handleRecoveryState(peerId: string, state: SupervisorState): void {
    const entry = this.peers.get(peerId)
    if (!entry) return

    entry.recovery = state
    this.recomputeConnection()
  }

  /** The supervisor ran out of attempts: say why, in words, and report it. */
  private handleGiveUp(peerId: string): void {
    const entry = this.peers.get(peerId)
    if (!entry) return

    entry.recovery = 'failed'
    const message = describeVerdict(entry.diagnostics.verdict())

    this.patch({ lastError: message || 'The connection could not be re-established.' })
    this.recomputeConnection()
    void this.sendReport(peerId, 'failed')
  }

  /**
   * Try again after giving up — the "Reconnect" button. Fresh credentials and
   * a fresh round of ICE restarts for every connection that had failed.
   */
  retryConnection(): void {
    let retried = false
    for (const entry of this.peers.values()) {
      if (entry.recovery !== 'failed') continue
      entry.supervisor.retry('manual')
      retried = true
    }

    if (retried) {
      this.patch({ lastError: null })
      this.recomputeConnection()
    }
  }

  /** The ICE configuration in use, for a relay connectivity check. */
  currentIceConfig(): IceConfig {
    return this.ice
  }

  private async sendReport(peerId: string, outcome: ConnectivityOutcome): Promise<void> {
    const entry = this.peers.get(peerId)
    if (!entry) return

    try {
      await reportConnectivity(this.options.sessionUuid, await entry.diagnostics.report(outcome))
    } catch {
      /* diagnostics are best-effort */
    }

    // Once a connection is up — never while one is being set up — time the relay nodes for the next
    // request for credentials. Best effort, and once per page.
    if (outcome === 'connected' && !this.measuredLatency) {
      this.measuredLatency = true
      void latencyMeter.measure(this.ice).catch(() => undefined)
    }
  }

  /**
   * Ask every peer to renegotiate.
   *
   * We ask *them* to offer rather than offering ourselves: the peer that
   * received `peer-joined` is the offerer for that pair, and keeping that rule
   * consistent is what avoids glare on a mid-session media change.
   */
  /**
   * A peer started or stopped sharing. It arrives over the data channel (the usual path) or the signalling socket, and
   * both must do the same: stopping takes the frozen last frame off screen, and starting again puts that peer's stream
   * back — the new share flows on the same track, so there is no `ontrack` to do it.
   */
  private applyPeerShareState(peerId: string, sharing: boolean): void {
    // A companion shares nothing, whatever it says.
    if (this.isCompanion(peerId)) return

    this.options.onPeerShareState(peerId, sharing)

    if (sharing) {
      const stream = this.peerStreams.get(peerId)
      if (stream && this.remoteStream !== stream) {
        this.remoteStream = stream
        this.patch({ remoteStream: stream })
      }

      return
    }

    if (this.remoteStream !== null && (this.remoteStream === this.peerStreams.get(peerId) || this.mediaEntries().length <= 1)) {
      this.remoteStream = null
      this.patch({ remoteStream: null })
    }
  }

  private async renegotiateAll(): Promise<void> {
    for (const peerId of this.peers.keys()) {
      if (this.isCompanion(peerId)) continue

      this.signalling?.send({ type: 'renegotiate', to: peerId })
    }
  }

  private removePeer(peerId: string): void {
    const entry = this.peers.get(peerId)
    if (!entry) return

    entry.supervisor.dispose()
    entry.diagnostics.dispose()
    entry.connection.dispose()
    this.peers.delete(peerId)

    // The machine being controlled has gone. Nothing more will be delivered,
    // and continuing to believe we are controlling it would leave the toolbar
    // showing a Stop button for a session that is over.
    if (this.control?.peerId === peerId) this.stopControlling()

    // A transfer with somebody who has left is not going to finish. Say so
    // rather than leaving a progress bar frozen at 40% forever.
    for (const record of this.transfers.values()) {
      if (record.view.peerId === peerId && !isTerminal(record.view)) {
        this.failTransfer(record.view.uuid, 'PEER_LEFT')
      }
    }

    const { [peerId]: _removed, ...pointers } = this.snapshot.pointers

    if (this.remoteStream !== null && this.remoteStream === this.peerStreams.get(peerId)) this.remoteStream = null
    this.peerStreams.delete(peerId)
    const mediaPeers = this.mediaEntries().length
    this.remoteStream = mediaPeers > 0 ? this.remoteStream : null

    this.patch({
      pointers,
      remoteStream: this.remoteStream,
      connection: mediaPeers === 0 ? 'waiting-for-peer' : this.snapshot.connection,
    })

    this.publishPeers()
  }

  private handlePeerState(peerId: string, state: RTCPeerConnectionState): void {
    const entry = this.peers.get(peerId)
    if (entry) {
      entry.info = { ...entry.info, connectionState: state }
      this.publishPeers()

      if (state === 'connected') {
        void this.refreshPath(entry)

        if (!entry.reportedConnected) {
          entry.reportedConnected = true
          void this.sendReport(peerId, 'connected')
        }
      }
    }

    this.recomputeConnection()
  }

  /**
   * The overall state is the best of the peers: with two viewers, one poor
   * connection should not make the session read as broken. A connection that
   * is being recovered reads as reconnecting, and one that has given up as
   * failed — the browser's own `failed` is no longer the last word, because
   * the supervisor restarts ICE after it.
   */
  private recomputeConnection(): void {
    // A companion's input-only connection is not the session's connection.
    const entries = this.mediaEntries()
    const states = entries.map((peer) => peer.info.connectionState)

    let connection: LiveConnectionState = this.snapshot.connection
    if (states.includes('connected')) connection = 'connected'
    else if (entries.some((peer) => peer.recovery === 'recovering')) connection = 'reconnecting'
    else if (states.includes('connecting') || states.includes('new')) connection = 'connecting'
    else if (states.includes('disconnected')) connection = 'interrupted'
    else if (states.includes('failed') || entries.some((peer) => peer.recovery === 'failed')) connection = 'failed'

    this.patch({ connection })
    this.publishNetwork()

    void this.reportConnectionState(connection)
  }

  private async refreshPath(entry: PeerEntry): Promise<void> {
    entry.path = await entry.diagnostics.selectedPath()
    this.publishNetwork()
  }

  /** Fold every connection's path and recovery into the one status the UI shows. */
  private publishNetwork(): void {
    const entries = this.mediaEntries()
    const carrying = entries.find((peer) => peer.info.connectionState === 'connected' && peer.path !== null)
    const failed = entries.find((peer) => peer.recovery === 'failed')
    const verdict = failed ? failed.diagnostics.verdict() : null
    const path = carrying?.path ?? null
    const node = path?.relayNode ? this.ice.relayNodes.find((n) => n.id === path.relayNode) : null

    this.patch({
      network: {
        path: path?.path ?? 'unknown',
        relayNode: node?.label ?? path?.relayNode ?? null,
        relayProtocol: path?.relayProtocol ?? null,
        recovering: entries.some((peer) => peer.recovery === 'recovering'),
        restarts: entries.reduce((total, peer) => total + peer.diagnostics.restarts, 0),
        verdict,
        message: verdict ? describeVerdict(verdict) || null : null,
        credentialsExpireAt: this.ice.expiresAt,
      },
    })
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
        this.applyPeerShareState(peerId, Boolean((message.payload as { sharing?: boolean })?.sharing))
        break
      // The usual path for it while a data channel is open (broadcast() prefers one). Only the signalling path used
      // to act on it, so the other side heard of an End from its own poll, up to 25 seconds later.
      case 'session-ended':
        this.options.onSessionEnded()
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
      if (this.isCompanion(toParticipantUuid) || !this.peers.get(toParticipantUuid)?.connection.dataChannelReady) {
        throw new Error('PEER_NOT_CONNECTED')
      }

      return toParticipantUuid
    }

    const ready = this.mediaEntries().filter((peer) => peer.connection.dataChannelReady)

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

    for (const { connection } of this.mediaEntries()) {
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

  /**
   * Read every connection's statistics: the quality word, the path, the round trip and the frame rate.
   *
   * This is the one loop that reads them — the indicator's figures come from the same `getStats()` as
   * its word — and it reads only while a connection is up. A reading of a connection that is not
   * describes nothing that is happening, so none is taken, and the last figures are dropped so they
   * cannot come back as current when the connection does.
   */
  private async pollStats(): Promise<void> {
    const media = this.mediaEntries()
    if (this.disposed || media.length === 0) return

    if (!media.some((entry) => entry.info.connectionState === 'connected')) {
      if (this.snapshot.stats !== null) this.patch({ stats: null })

      return
    }

    const readings = await Promise.all(media.map(({ connection }) => connection.readStats()))

    // A path changes under a live connection — an ICE restart, a relay
    // replacing a direct path that broke — so it is re-read with the quality.
    for (const entry of media) {
      if (entry.info.connectionState === 'connected') entry.path = await entry.diagnostics.selectedPath()
    }
    this.publishNetwork()

    const qualities = readings.map((reading) => reading.quality)
    const worst: PeerConnectionQuality = qualities.includes('poor')
      ? 'poor'
      : qualities.includes('fair')
        ? 'fair'
        : qualities.includes('good')
          ? 'good'
          : 'unknown'

    // Asked again: the connection can drop while the reading is on its way back.
    const connected = media.some((entry) => entry.info.connectionState === 'connected')

    this.patch({ quality: worst, stats: connected ? this.statsFrom(media, readings) : null })
  }

  /** The indicator's figures, from one reading of every connection that carries the session. */
  private statsFrom(media: PeerEntry[], readings: PeerConnectionReading[]): ConnectionStats {
    let roundTripMs: number | null = null
    let framesPerSecond: number | null = null

    for (const [index, entry] of media.entries()) {
      const reading = readings[index]

      // The slowest connection that is up, as the quality word is the worst: with two viewers, the one
      // on a poor link is the one worth knowing about.
      if (entry.info.connectionState === 'connected' && reading.roundTripMs !== null) {
        roundTripMs = Math.max(roundTripMs ?? 0, reading.roundTripMs)
      }

      // The frame rate of the picture on screen — the only one anybody here can see.
      if (this.remoteStream !== null && this.peerStreams.get(entry.connection.peerId) === this.remoteStream) {
        framesPerSecond = reading.framesPerSecond
      }
    }

    return { roundTripMs, framesPerSecond }
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
