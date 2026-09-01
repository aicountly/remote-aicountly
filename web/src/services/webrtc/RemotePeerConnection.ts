/**
 * One `RTCPeerConnection` to one peer, with its data channel.
 *
 * Kept out of React entirely. A peer connection has a lifecycle that does not
 * match a component's — it survives re-renders, and it must be torn down
 * exactly once — so putting it in a hook is how a screen ends up leaking a
 * capture after unmount (§56).
 *
 * Who offers is decided by the caller, not here: the peer already in the room
 * offers to the one that just arrived. That rule has no tie to break, so the
 * "glare" case (both sides offering at once) never arises and there is no need
 * for perfect-negotiation rollback.
 */

export type PeerConnectionQuality = 'good' | 'fair' | 'poor' | 'unknown'

export interface PeerEvents {
  onIceCandidate: (candidate: RTCIceCandidateInit) => void
  onTrack: (stream: MediaStream) => void
  onDataMessage: (message: unknown) => void
  onStateChange: (state: RTCPeerConnectionState) => void
  onDataChannelOpen: () => void
}

/** Label is shared by both ends; a mismatch means no channel at all. */
const DATA_CHANNEL_LABEL = 'aicountly-remote'

export class RemotePeerConnection {
  private pc: RTCPeerConnection
  private channel: RTCDataChannel | null = null
  private disposed = false
  /** Candidates that arrived before the remote description was set. */
  private pendingCandidates: RTCIceCandidateInit[] = []
  private senders: RTCRtpSender[] = []

  constructor(
    readonly peerId: string,
    iceServers: RTCIceServer[],
    private readonly events: PeerEvents,
  ) {
    this.pc = new RTCPeerConnection({
      iceServers,
      // Trickle ICE with a small pool: gathering starts before the offer is
      // created, which measurably shortens time-to-first-frame.
      iceCandidatePoolSize: 2,
      bundlePolicy: 'max-bundle',
    })

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

    // The answering side receives the channel rather than creating one.
    this.pc.ondatachannel = (event) => {
      if (event.channel.label === DATA_CHANNEL_LABEL) {
        this.attachChannel(event.channel)
      }
    }
  }

  get connectionState(): RTCPeerConnectionState {
    return this.pc.connectionState
  }

  get dataChannelReady(): boolean {
    return this.channel?.readyState === 'open'
  }

  /** Create the offer, and with it the data channel this pair will use. */
  async createOffer(): Promise<RTCSessionDescriptionInit> {
    this.attachChannel(
      this.pc.createDataChannel(DATA_CHANNEL_LABEL, {
        // Ordered and reliable: chat and annotation events are meaningless out
        // of order, and pointer updates are throttled rather than dropped.
        ordered: true,
      }),
    )

    const offer = await this.pc.createOffer({ offerToReceiveAudio: true, offerToReceiveVideo: true })
    await this.pc.setLocalDescription(offer)

    return offer
  }

  async acceptOffer(offer: RTCSessionDescriptionInit): Promise<RTCSessionDescriptionInit> {
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

  /** Attach (or replace) the outgoing media for this connection. */
  async setLocalStream(stream: MediaStream | null): Promise<void> {
    if (this.disposed) return

    if (stream === null) {
      for (const sender of this.senders) {
        try {
          this.pc.removeTrack(sender)
        } catch {
          /* already removed */
        }
      }
      this.senders = []

      return
    }

    // replaceTrack where possible: swapping a track on an existing sender does
    // not require renegotiation, so switching what is shared is seamless.
    for (const track of stream.getTracks()) {
      const existing = this.senders.find((sender) => sender.track?.kind === track.kind)

      if (existing) {
        await existing.replaceTrack(track)
      } else {
        this.senders.push(this.pc.addTrack(track, stream))
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

    if (this.channel) {
      this.channel.onmessage = null
      this.channel.onopen = null
      this.channel.onclose = null
      this.channel.onerror = null
      try {
        this.channel.close()
      } catch {
        /* already closed */
      }
      this.channel = null
    }

    this.pc.onicecandidate = null
    this.pc.ontrack = null
    this.pc.onconnectionstatechange = null
    this.pc.ondatachannel = null

    for (const sender of this.senders) {
      try {
        this.pc.removeTrack(sender)
      } catch {
        /* connection may already be closed */
      }
    }
    this.senders = []
    this.pendingCandidates = []

    try {
      this.pc.close()
    } catch {
      /* already closed */
    }
  }

  private attachChannel(channel: RTCDataChannel): void {
    this.channel = channel

    channel.onopen = () => {
      if (!this.disposed) this.events.onDataChannelOpen()
    }

    channel.onmessage = (event) => {
      if (this.disposed) return

      try {
        this.events.onDataMessage(JSON.parse(String(event.data)))
      } catch {
        // Not our message format; ignore rather than tearing down the channel.
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
