/**
 * WebSocket client for the signalling service (§19).
 *
 * Responsibilities are deliberately narrow: connect with a token the API
 * minted, relay typed messages, and reconnect when the network blinks. It knows
 * nothing about SDP or ICE — those are payloads it carries.
 *
 * Reconnection uses exponential backoff with jitter. Without the jitter, every
 * client of a signalling service that just restarted reconnects in the same
 * millisecond and knocks it over again.
 */

import { signallingProtocols } from './protocol'

export type SignallingMessageType =
  | 'joined'
  | 'peer-joined'
  | 'peer-left'
  | 'peer-unavailable'
  | 'offer'
  | 'answer'
  | 'ice-candidate'
  | 'peer-ready'
  | 'renegotiate'
  | 'presence'
  | 'chat'
  | 'pointer'
  | 'annotation'
  | 'share-state'
  | 'session-ended'
  | 'control-hint'
  | 'error'
  | 'pong'

export interface SignallingPeer {
  participantUuid: string
  role: string
  name: string
  capabilities: Record<string, boolean>
  /**
   * The peer's connection epoch: one value per page load, repeated on every
   * reconnect of that page. A *different* epoch for a participant uuid already
   * known means that page is gone and a new one took its place — its
   * RTCPeerConnection went with it. Absent from a desktop agent and from an
   * older web build, and two absent epochs compare equal, so neither changes
   * behaviour.
   */
  epoch?: string
}

export interface SignallingMessage {
  type: SignallingMessageType
  from?: string
  to?: string
  payload?: unknown
  peers?: SignallingPeer[]
  peer?: SignallingPeer
  participantUuid?: string
  role?: string
  code?: string
  message?: string
  inReplyTo?: string
}

export type SignallingStatus = 'idle' | 'connecting' | 'open' | 'reconnecting' | 'closed' | 'failed'

interface Options {
  url: string
  token: string
  /** When `token` stops being accepted (ISO 8601), if the API said. */
  expiresAt?: string | null
  /** Mint a fresh token — the old one expires long before a session does. */
  refreshToken: () => Promise<{ url: string; token: string; expiresAt?: string | null }>
  onMessage: (message: SignallingMessage) => void
  onStatus: (status: SignallingStatus) => void
  /**
   * This page's connection epoch, sent to the relay so the other participants
   * can tell a reconnect of this page from a page that was reloaded. One value
   * for the life of this client, including across re-minted tokens — a new one
   * per reconnect would make every peer rebuild for nothing.
   */
  epoch?: string
}

const MAX_RECONNECT_ATTEMPTS = 8
const BASE_BACKOFF_MS = 500
const MAX_BACKOFF_MS = 15_000

/**
 * How long before its token expires the connection is replaced with a fresh one.
 *
 * The relay closes a socket the moment its token expires and tells the room the participant **left**. For
 * a browser peer that was a half-second blip every two minutes; for a computer hosting the session through
 * the desktop app it was the end of the session, because its helper had "left". Replacing the socket first
 * means the relay sees the same participant arrive on a newer connection, closes the old one as replaced,
 * and announces nothing.
 */
const RENEW_BEFORE_EXPIRY_MS = 30_000

/** The room token's lifetime when nothing said when it expires (`remote.signallingTokenTtl`). */
const ASSUMED_TOKEN_LIFETIME_MS = 120_000

/** Never sooner than this, so a short or already-past expiry cannot spin. */
const MIN_RENEW_DELAY_MS = 5_000

/** How long to wait before trying a renewal that failed again. */
const RENEW_RETRY_MS = 5_000

/** How long from now to replace the connection, given when its token expires. */
export function renewDelayMs(expiresAt: string | null | undefined, now = Date.now()): number {
  const expiry = expiresAt ? Date.parse(expiresAt) : Number.NaN
  const lifetime = Number.isFinite(expiry) ? expiry - now : ASSUMED_TOKEN_LIFETIME_MS

  return Math.max(MIN_RENEW_DELAY_MS, lifetime - RENEW_BEFORE_EXPIRY_MS)
}

export class RemoteSignallingClient {
  private socket: WebSocket | null = null
  private attempts = 0
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null
  private status: SignallingStatus = 'idle'
  /** Set by close(); stops the reconnect loop from resurrecting the socket. */
  private disposed = false

  private url: string
  private token: string
  /** When the token the live socket was opened with expires, if known. */
  private expiresAt: string | null
  private renewTimer: ReturnType<typeof setTimeout> | null = null
  /** The replacement socket, from the moment it is created until it takes over or fails. */
  private successor: WebSocket | null = null

  constructor(private readonly options: Options) {
    this.url = options.url
    this.token = options.token
    this.expiresAt = options.expiresAt ?? null
  }

  get currentStatus(): SignallingStatus {
    return this.status
  }

  /**
   * The current url with this page's epoch on it.
   *
   * Applied here rather than once in the constructor because a re-minted token
   * brings a fresh url with it, and the epoch has to survive that. Carrying it
   * in the query is safe where the token is not: it is opaque, authorises
   * nothing, and the relay reads no meaning into it.
   */
  private endpoint(): string {
    if (this.options.epoch === undefined) return this.url

    try {
      const url = new URL(this.url)
      url.searchParams.set('epoch', this.options.epoch)

      return url.toString()
    } catch {
      // A url this environment will not parse is used exactly as it was given:
      // losing the epoch costs a rebuilt peer connection, a broken url costs
      // the session.
      return this.url
    }
  }

  connect(): void {
    if (this.disposed) return
    if (this.socket && (this.socket.readyState === WebSocket.OPEN || this.socket.readyState === WebSocket.CONNECTING)) {
      return
    }

    this.setStatus(this.attempts === 0 ? 'connecting' : 'reconnecting')

    // The browser WebSocket API cannot set an Authorization header, so the
    // token travels as the subprotocol (./protocol.ts), never in the URL. It is
    // short-lived (two minutes) and the connection is wss in every deployed
    // environment.
    const socket = new WebSocket(this.endpoint(), signallingProtocols(this.token))
    this.socket = socket

    socket.onopen = () => {
      this.attempts = 0
      this.setStatus('open')
      this.scheduleRenewal()
    }

    this.listen(socket)
  }

  /** Deliver `socket`'s messages, and reconnect when it closes for a reason that is not ours. */
  private listen(socket: WebSocket, options: { renewal?: boolean } = {}): void {
    socket.onmessage = (event) => {
      let message: SignallingMessage
      try {
        message = JSON.parse(String(event.data)) as SignallingMessage
      } catch {
        return
      }

      // The relay greets every connection with the room as it stands. On a renewed connection this
      // page is not a newcomer — it has been here all along, with its peer connections — and treating
      // the greeting as an arrival would have the room wait for offers that are not coming.
      if (options.renewal && message.type === 'joined') return

      this.options.onMessage(message)
    }

    socket.onerror = () => {
      // `onclose` always follows, and carries the information worth acting on.
    }

    socket.onclose = (event) => {
      // Not the live socket any more (a renewal already took over): nothing to do.
      if (this.socket !== socket) return

      // The relay closed this one because its replacement arrived. The replacement takes over in its
      // own `onopen`, which may run a moment after this.
      if (event.code === 4004 && this.successor) return

      this.socket = null
      this.cancelRenewal()

      if (this.disposed) {
        this.setStatus('closed')

        return
      }

      // 4003 (token expired) and 4004 (replaced by a newer connection) are
      // ours. An expired token is recoverable — mint another. A replacement
      // means a second tab took over, and reconnecting would fight it.
      if (event.code === 4004) {
        this.setStatus('closed')

        return
      }

      void this.scheduleReconnect()
    }
  }

  // ------------------------------------------------- renewal before expiry

  private scheduleRenewal(delay = renewDelayMs(this.expiresAt)): void {
    if (this.renewTimer) clearTimeout(this.renewTimer)
    if (this.disposed) return

    this.renewTimer = setTimeout(() => {
      this.renewTimer = null
      void this.renew()
    }, delay)
  }

  private cancelRenewal(): void {
    if (this.renewTimer) {
      clearTimeout(this.renewTimer)
      this.renewTimer = null
    }

    if (this.successor) {
      const successor = this.successor
      this.successor = null
      successor.onopen = null
      successor.onclose = null
      successor.onerror = null
      successor.onmessage = null

      try {
        successor.close(1000, 'Renewal abandoned')
      } catch {
        /* already closed */
      }
    }
  }

  /**
   * Make before break: open a connection with a fresh token, and let it take over from the live one.
   *
   * The relay closes the old socket as replaced (4004) and tells nobody that this participant left.
   * If anything here fails, the live socket is untouched and the renewal is tried again shortly; if it
   * is still failing when the token expires, the ordinary reconnect path takes over as before.
   */
  private async renew(): Promise<void> {
    if (this.disposed || this.successor) return

    const current = this.socket
    if (!current || current.readyState !== WebSocket.OPEN) return

    let fresh: { url: string; token: string; expiresAt?: string | null }
    try {
      fresh = await this.options.refreshToken()
    } catch {
      this.scheduleRenewal(RENEW_RETRY_MS)

      return
    }

    // The live socket went away while the token was being minted: the reconnect path owns it now.
    if (this.disposed || this.socket !== current) return

    this.url = fresh.url
    this.token = fresh.token
    this.expiresAt = fresh.expiresAt ?? null

    const next = new WebSocket(this.endpoint(), signallingProtocols(this.token))
    this.successor = next

    // From the start: the relay routes this participant's messages to the new socket the moment it
    // connects, which is before `onopen` runs here.
    this.listen(next, { renewal: true })

    const abandon = () => {
      if (this.successor !== next) return

      this.successor = null
      next.onopen = null
      next.onclose = null
      next.onerror = null
      next.onmessage = null

      // The relay may already have closed the old socket in favour of this one. With neither left,
      // this is an ordinary lost connection.
      if (!this.socket || this.socket.readyState !== WebSocket.OPEN) {
        this.socket = null
        void this.scheduleReconnect()

        return
      }

      this.scheduleRenewal(RENEW_RETRY_MS)
    }

    next.onclose = abandon
    next.onerror = abandon

    next.onopen = () => {
      if (this.disposed || this.successor !== next) return

      const old = this.socket
      this.successor = null
      this.socket = next

      // Re-attached now that it is the live socket, so a close of it reconnects.
      this.listen(next, { renewal: true })

      if (old && old !== next) {
        old.onopen = null
        old.onclose = null
        old.onerror = null
        old.onmessage = null

        if (old.readyState === WebSocket.OPEN || old.readyState === WebSocket.CONNECTING) {
          old.close(1000, 'Renewed')
        }
      }

      this.attempts = 0
      this.setStatus('open')
      this.scheduleRenewal()
    }
  }

  send(message: { type: string; to?: string; payload?: unknown }): boolean {
    if (this.socket?.readyState !== WebSocket.OPEN) {
      return false
    }

    this.socket.send(JSON.stringify(message))

    return true
  }

  close(): void {
    this.disposed = true

    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer)
      this.reconnectTimer = null
    }

    this.cancelRenewal()

    if (this.socket) {
      // Detach handlers first: a close we asked for must not trigger the
      // reconnect path on its way out.
      this.socket.onclose = null
      this.socket.onerror = null
      this.socket.onmessage = null
      this.socket.onopen = null

      if (this.socket.readyState === WebSocket.OPEN || this.socket.readyState === WebSocket.CONNECTING) {
        this.socket.close(1000, 'Client closed')
      }

      this.socket = null
    }

    this.setStatus('closed')
  }

  private async scheduleReconnect(): Promise<void> {
    if (this.disposed) return

    if (this.attempts >= MAX_RECONNECT_ATTEMPTS) {
      this.setStatus('failed')

      return
    }

    this.attempts += 1
    this.setStatus('reconnecting')

    // Exponential backoff with jitter, so a signalling service coming back up
    // is not immediately flattened by every client returning at once.
    const backoff = Math.min(BASE_BACKOFF_MS * 2 ** (this.attempts - 1), MAX_BACKOFF_MS)
    const delay = backoff * (0.7 + Math.random() * 0.6)

    this.reconnectTimer = setTimeout(async () => {
      this.reconnectTimer = null
      if (this.disposed) return

      try {
        // Always re-mint: by the time a reconnect happens the previous token
        // has very likely expired, and the server would refuse the upgrade.
        const fresh = await this.options.refreshToken()
        this.url = fresh.url
        this.token = fresh.token
        this.expiresAt = fresh.expiresAt ?? null
      } catch {
        // Could not re-mint — the session may have ended or the host may have
        // removed us. Try again on the next tick of the backoff.
        void this.scheduleReconnect()

        return
      }

      this.connect()
    }, delay)
  }

  private setStatus(status: SignallingStatus): void {
    if (this.status === status) return

    this.status = status
    this.options.onStatus(status)
  }
}
