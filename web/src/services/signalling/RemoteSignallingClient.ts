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
  | 'error'
  | 'pong'

export interface SignallingPeer {
  participantUuid: string
  role: string
  name: string
  capabilities: Record<string, boolean>
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
  /** Mint a fresh token — the old one expires long before a session does. */
  refreshToken: () => Promise<{ url: string; token: string }>
  onMessage: (message: SignallingMessage) => void
  onStatus: (status: SignallingStatus) => void
}

const MAX_RECONNECT_ATTEMPTS = 8
const BASE_BACKOFF_MS = 500
const MAX_BACKOFF_MS = 15_000

export class RemoteSignallingClient {
  private socket: WebSocket | null = null
  private attempts = 0
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null
  private status: SignallingStatus = 'idle'
  /** Set by close(); stops the reconnect loop from resurrecting the socket. */
  private disposed = false

  private url: string
  private token: string

  constructor(private readonly options: Options) {
    this.url = options.url
    this.token = options.token
  }

  get currentStatus(): SignallingStatus {
    return this.status
  }

  connect(): void {
    if (this.disposed) return
    if (this.socket && (this.socket.readyState === WebSocket.OPEN || this.socket.readyState === WebSocket.CONNECTING)) {
      return
    }

    this.setStatus(this.attempts === 0 ? 'connecting' : 'reconnecting')

    // The browser WebSocket API cannot set an Authorization header, so the
    // token travels as a query parameter. It is short-lived (two minutes) and
    // the connection is wss in every deployed environment.
    const separator = this.url.includes('?') ? '&' : '?'
    const socket = new WebSocket(`${this.url}${separator}token=${encodeURIComponent(this.token)}`)
    this.socket = socket

    socket.onopen = () => {
      this.attempts = 0
      this.setStatus('open')
    }

    socket.onmessage = (event) => {
      let message: SignallingMessage
      try {
        message = JSON.parse(String(event.data)) as SignallingMessage
      } catch {
        return
      }

      this.options.onMessage(message)
    }

    socket.onerror = () => {
      // `onclose` always follows, and carries the information worth acting on.
    }

    socket.onclose = (event) => {
      this.socket = null

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
