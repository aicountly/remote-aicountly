/**
 * One `RTCPeerConnection` to one peer, with its data channel.
 *
 * Kept out of React entirely. A peer connection has a lifecycle that does not
 * match a component's — it survives re-renders, and it must be torn down
 * exactly once — so putting it in a hook is how a screen ends up leaking a
 * capture after unmount (§56).
 *
 * Who offers is decided by the caller, not here: the peer already in the room
 * offers to the one that just arrived, and a renegotiation is *asked for*
 * rather than offered. Offers can still cross — two people starting to share
 * in the same second — so each side is told whether it is `polite`: the
 * polite side rolls its own offer back and answers, the other ignores the
 * collision. Both converge on one negotiation instead of erroring.
 */

import { applyIceConfig, rtcConfiguration } from '../../ice-kit/index.js'
import type { IceConfig } from '../../ice-kit/index.js'
import { BUFFER_LOW_WATER } from './fileTransfer'
import { CONTROL_CHANNEL_LABEL } from './remoteControl'

export type PeerConnectionQuality = 'good' | 'fair' | 'poor' | 'unknown'

export interface PeerEvents {
  onIceCandidate: (candidate: RTCIceCandidateInit) => void
  onTrack: (stream: MediaStream) => void
  onDataMessage: (message: unknown) => void
  /** A framed file chunk. Binary is kept off the JSON path entirely (§36). */
  onBinaryMessage: (message: ArrayBuffer) => void
  /**
   * A remote-control message from the agent.
   *
   * Its own channel, and its own callback, so a bug in chat or file transfer
   * cannot deliver into the path that moves somebody's mouse.
   */
  onControlMessage: (message: ArrayBuffer) => void
  onStateChange: (state: RTCPeerConnectionState) => void
  onDataChannelOpen: () => void
}

/** Label is shared by both ends; a mismatch means no channel at all. */
const DATA_CHANNEL_LABEL = 'aicountly-remote'

export class RemotePeerConnection {
  private pc: RTCPeerConnection
  private channel: RTCDataChannel | null = null
  /**
   * The remote-control channel, when the peer is a desktop agent.
   *
   * Separate from the collaboration channel above: input and clipboard travel
   * here, chat and file chunks travel there, and neither can deliver into the
   * other's handler.
   */
  private controlChannel: RTCDataChannel | null = null
  private disposed = false
  /** Candidates that arrived before the remote description was set. */
  private pendingCandidates: RTCIceCandidateInit[] = []
  /**
   * One sender per media kind, kept for the life of the connection. A stopped share empties its sender
   * (`replaceTrack(null)`) rather than removing it: a removed sender's transceiver cannot be reused, so the next share
   * would need a new m-line, which only an offer from this side can create — and Remote asks the *other* side to offer.
   */
  private senders = new Map<string, RTCRtpSender>()

  constructor(
    readonly peerId: string,
    ice: IceConfig,
    private readonly events: PeerEvents,
    /**
     * Yields when offers cross. Exactly one side of a pair is polite; the
     * engine sets it once it knows who the peer is.
     */
    public polite = false,
  ) {
    this.pc = new RTCPeerConnection(
      rtcConfiguration(ice, {
        // Trickle ICE with a small pool: gathering starts before the offer is
        // created, which measurably shortens time-to-first-frame.
        iceCandidatePoolSize: Math.max(2, ice.iceCandidatePoolSize),
      }),
    )

    this.pc.onicecandidate = (event) => {
      if (event.candidate && !this.disposed) {
        this.events.onIceCandidate(event.candidate.toJSON())
      }
    }

    this.pc.ontrack = (event) => {
      if (this.disposed) return

      const [stream] = event.streams
      if (stream) this.events.onTrack(stream)
    }

    this.pc.onconnectionstatechange = () => {
      if (this.disposed) return

      this.events.onStateChange(this.pc.connectionState)
    }

    // The answering side receives the channels rather than creating them.
    this.pc.ondatachannel = (event) => {
      if (event.channel.label === DATA_CHANNEL_LABEL) {
        this.attachChannel(event.channel)
      }

      if (event.channel.label === CONTROL_CHANNEL_LABEL) {
        this.attachControlChannel(event.channel)
      }
    }
  }

  /**
   * A control companion's connection (docs/desktop/CONTROL_COMPANION.md): input only. No media is offered or
   * received and only the control channel is opened — the picture comes from the person's browser, never from
   * the companion, and chat or files have no business going to a machine's input helper.
   */
  controlOnly = false

  get connectionState(): RTCPeerConnectionState {
    return this.pc.connectionState
  }

  /**
   * The underlying connection, for the ICE kit's supervisor and diagnostics —
   * which only listen to it. Everything that changes it stays in this class.
   */
  get peerConnection(): RTCPeerConnection {
    return this.pc
  }

  /** Whether an offer/answer exchange has completed at least once. */
  get negotiated(): boolean {
    return this.pc.remoteDescription !== null
  }

  get dataChannelReady(): boolean {
    return this.channel?.readyState === 'open'
  }

  /** Whether the control channel is open. False for a browser-to-browser peer. */
  get controlChannelReady(): boolean {
    return this.controlChannel?.readyState === 'open'
  }

  /**
   * Create an offer — the first one with the two channels this pair will use,
   * a later one (a renegotiation, an ICE restart) over the channels that
   * already exist. Creating them again on every offer would open a fresh pair
   * of channels each time somebody started sharing.
   */
  async createOffer(options: { iceRestart?: boolean } = {}): Promise<RTCSessionDescriptionInit> {
    if (!this.channel && !this.controlOnly) {
      this.attachChannel(
        this.pc.createDataChannel(DATA_CHANNEL_LABEL, {
          // Ordered and reliable: chat and annotation events are meaningless out
          // of order, and pointer updates are throttled rather than dropped.
          ordered: true,
        }),
      )
    }

    // The control channel is opened whether or not this peer turns out to be
    // controllable. Opening it later would need a renegotiation at the moment
    // somebody asks for control, which is the worst possible moment for one.
    if (!this.controlChannel) {
      this.attachControlChannel(
        this.pc.createDataChannel(CONTROL_CHANNEL_LABEL, {
          // An input event applied out of order is a click somewhere nobody
          // intended, so this is ordered and reliable too.
          ordered: true,
        }),
      )
    }

    // An ICE restart is an offer with new ICE credentials: both sides gather
    // again — with whatever ICE servers they now hold — while media keeps
    // flowing on the old path until a new one is selected.
    const offer = await this.pc.createOffer({
      offerToReceiveAudio: !this.controlOnly,
      offerToReceiveVideo: !this.controlOnly,
      iceRestart: options.iceRestart === true,
    })
    await this.pc.setLocalDescription(offer)

    return offer
  }

  /**
   * Answer an offer, or return null when it collided with ours and we are the
   * side that keeps its own (see `polite`).
   */
  async acceptOffer(offer: RTCSessionDescriptionInit): Promise<RTCSessionDescriptionInit | null> {
    if (this.pc.signalingState === 'have-local-offer') {
      if (!this.polite) return null

      await this.pc.setLocalDescription({ type: 'rollback' })
    }

    await this.pc.setRemoteDescription(new RTCSessionDescription(offer))
    await this.drainCandidates()

    const answer = await this.pc.createAnswer()
    await this.pc.setLocalDescription(answer)

    return answer
  }

  async acceptAnswer(answer: RTCSessionDescriptionInit): Promise<void> {
    // A duplicated answer (a resent signalling message) would throw in
    // `stable`; ignoring it is correct and keeps the connection up.
    if (this.pc.signalingState !== 'have-local-offer') {
      return
    }

    await this.pc.setRemoteDescription(new RTCSessionDescription(answer))
    await this.drainCandidates()
  }

  /**
   * ICE candidates routinely arrive before the description they belong to.
   * Queuing them is the difference between a connection that forms in a second
   * and one that never forms at all.
   */
  async addIceCandidate(candidate: RTCIceCandidateInit): Promise<void> {
    if (!this.pc.remoteDescription) {
      this.pendingCandidates.push(candidate)

      return
    }

    try {
      await this.pc.addIceCandidate(new RTCIceCandidate(candidate))
    } catch {
      // A candidate the browser cannot use is normal during trickle; the
      // connection succeeds on another one.
    }
  }

  /**
   * Point this connection at fresh ICE servers. They are used from the next
   * gathering on — an ICE restart — and the current path is left alone.
   */
  applyIceConfig(ice: IceConfig): boolean {
    return !this.disposed && applyIceConfig(this.pc, ice)
  }

  /** Attach (or replace) the outgoing media for this connection. */
  async setLocalStream(stream: MediaStream | null): Promise<void> {
    if (this.disposed) return

    if (stream === null) {
      // Stop sending, keep the sender: the next share fills the same transceiver with no renegotiation.
      for (const sender of this.senders.values()) {
        try {
          await sender.replaceTrack(null)
        } catch {
          /* the connection is closing */
        }
      }

      return
    }

    // replaceTrack where possible: swapping a track on an existing sender does
    // not require renegotiation, so switching what is shared is seamless.
    for (const track of stream.getTracks()) {
      const existing = this.senders.get(track.kind)

      if (existing) {
        await existing.replaceTrack(track)
      } else {
        this.senders.set(track.kind, this.pc.addTrack(track, stream))
      }
    }
  }

  send(message: unknown): boolean {
    if (this.channel?.readyState !== 'open') return false

    try {
      this.channel.send(JSON.stringify(message))

      return true
    } catch {
      return false
    }
  }

  /** Push one framed binary chunk. Returns false when the channel is not open. */
  sendBinary(message: ArrayBuffer): boolean {
    if (this.channel?.readyState !== 'open') return false

    try {
      this.channel.send(message)

      return true
    } catch {
      return false
    }
  }

  /**
   * Send one control-protocol message.
   *
   * Returns false when the channel is not open, which the caller shows as
   * "control is not connected" rather than silently dropping a keystroke.
   */
  sendControl(message: ArrayBuffer): boolean {
    if (this.controlChannel?.readyState !== 'open') return false

    try {
      this.controlChannel.send(message)

      return true
    } catch {
      return false
    }
  }

  get bufferedAmount(): number {
    return this.channel?.bufferedAmount ?? 0
  }

  /**
   * Resolve once the outbound buffer has drained.
   *
   * A data channel offers no backpressure of its own: writing faster than the
   * network drains grows `bufferedAmount` without bound until the channel is
   * torn down. `bufferedamountlow` is the event that says it is safe to
   * continue, and the timeout is there so a channel that never drains fails the
   * transfer instead of hanging it forever.
   */
  waitForDrain(timeoutMs = 30_000): Promise<void> {
    const channel = this.channel

    if (!channel || channel.readyState !== 'open') return Promise.resolve()
    if (channel.bufferedAmount <= BUFFER_LOW_WATER) return Promise.resolve()

    return new Promise((resolve, reject) => {
      const settle = (fn: () => void) => {
        clearTimeout(timer)
        channel.removeEventListener('bufferedamountlow', onLow)
        fn()
      }

      const onLow = () => settle(resolve)
      const timer = setTimeout(() => settle(() => reject(new Error('CHANNEL_STALLED'))), timeoutMs)

      channel.bufferedAmountLowThreshold = BUFFER_LOW_WATER
      channel.addEventListener('bufferedamountlow', onLow)
    })
  }

  /**
   * A coarse connection-quality reading for the indicator (§49).
   *
   * Deliberately crude — packet loss and round-trip time, bucketed into three
   * words a person understands. The raw statistics are for diagnostics, not for
   * the interface.
   */
  async readQuality(): Promise<PeerConnectionQuality> {
    if (this.disposed || typeof this.pc.getStats !== 'function') return 'unknown'

    try {
      const stats = await this.pc.getStats()
      let packetsLost = 0
      let packetsReceived = 0
      let roundTripMs = 0

      stats.forEach((report) => {
        if (report.type === 'inbound-rtp' && !(report as { isRemote?: boolean }).isRemote) {
          packetsLost += Number((report as { packetsLost?: number }).packetsLost ?? 0)
          packetsReceived += Number((report as { packetsReceived?: number }).packetsReceived ?? 0)
        }

        if (report.type === 'candidate-pair' && (report as { state?: string }).state === 'succeeded') {
          const rtt = Number((report as { currentRoundTripTime?: number }).currentRoundTripTime ?? 0)
          if (rtt > 0) roundTripMs = rtt * 1000
        }
      })

      const total = packetsLost + packetsReceived
      const lossRatio = total > 0 ? packetsLost / total : 0

      if (lossRatio > 0.08 || roundTripMs > 500) return 'poor'
      if (lossRatio > 0.02 || roundTripMs > 250) return 'fair'
      if (total === 0 && roundTripMs === 0) return 'unknown'

      return 'good'
    } catch {
      return 'unknown'
    }
  }

  /**
   * Tear down, once.
   *
   * Order matters: handlers are detached first so a close does not fire a state
   * change into a component that has already unmounted (§56).
   */
  dispose(): void {
    if (this.disposed) return
    this.disposed = true

    for (const channel of [this.channel, this.controlChannel]) {
      if (!channel) continue

      channel.onmessage = null
      channel.onopen = null
      channel.onclose = null
      channel.onerror = null

      try {
        channel.close()
      } catch {
        /* already closed */
      }
    }

    this.channel = null
    this.controlChannel = null

    this.pc.onicecandidate = null
    this.pc.ontrack = null
    this.pc.onconnectionstatechange = null
    this.pc.ondatachannel = null

    for (const sender of this.senders.values()) {
      try {
        this.pc.removeTrack(sender)
      } catch {
        /* connection may already be closed */
      }
    }
    this.senders.clear()
    this.pendingCandidates = []

    try {
      this.pc.close()
    } catch {
      /* already closed */
    }
  }

  private attachChannel(channel: RTCDataChannel): void {
    this.channel = channel

    // Without this, binary frames arrive as Blobs in some browsers and as
    // ArrayBuffers in others — and the file receiver would have to handle both.
    channel.binaryType = 'arraybuffer'
    channel.bufferedAmountLowThreshold = BUFFER_LOW_WATER

    channel.onopen = () => {
      if (!this.disposed) this.events.onDataChannelOpen()
    }

    channel.onmessage = (event) => {
      if (this.disposed) return

      // Binary is a file chunk and never JSON. Keeping the two paths separate
      // means a chunk that happens to start with '{' is not parsed as control.
      if (event.data instanceof ArrayBuffer) {
        this.events.onBinaryMessage(event.data)

        return
      }

      if (typeof Blob !== 'undefined' && event.data instanceof Blob) {
        // A browser that ignored `binaryType`. Convert rather than drop.
        void event.data.arrayBuffer().then((buffer) => {
          if (!this.disposed) this.events.onBinaryMessage(buffer)
        })

        return
      }

      try {
        this.events.onDataMessage(JSON.parse(String(event.data)))
      } catch {
        // Not our message format; ignore rather than tearing down the channel.
      }
    }
  }

  /**
   * Wire the control channel.
   *
   * Deliberately minimal: it hands bytes to the engine and does nothing else.
   * Every decision about whether a message may be acted on happens on the
   * agent, in its own gate — the browser is the side *asking*, and a browser
   * that decided for itself would be a browser worth compromising.
   */
  private attachControlChannel(channel: RTCDataChannel): void {
    this.controlChannel = channel
    channel.binaryType = 'arraybuffer'

    // A control-only connection has no other channel to say the wire is up.
    channel.onopen = () => {
      if (!this.disposed) this.events.onDataChannelOpen()
    }

    channel.onmessage = (event) => {
      if (this.disposed) return

      if (event.data instanceof ArrayBuffer) {
        this.events.onControlMessage(event.data)

        return
      }

      if (typeof Blob !== 'undefined' && event.data instanceof Blob) {
        void event.data.arrayBuffer().then((buffer) => {
          if (!this.disposed) this.events.onControlMessage(buffer)
        })

        return
      }

      if (typeof event.data === 'string') {
        this.events.onControlMessage(new TextEncoder().encode(event.data).buffer as ArrayBuffer)
      }
    }
  }

  private async drainCandidates(): Promise<void> {
    const queued = this.pendingCandidates
    this.pendingCandidates = []

    for (const candidate of queued) {
      try {
        await this.pc.addIceCandidate(new RTCIceCandidate(candidate))
      } catch {
        /* see addIceCandidate */
      }
    }
  }
}
