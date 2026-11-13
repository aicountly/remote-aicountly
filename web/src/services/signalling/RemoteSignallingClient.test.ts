import { afterEach, describe, expect, it, vi } from 'vitest'

import { RemoteSignallingClient, renewDelayMs } from './RemoteSignallingClient'

/**
 * How the room's socket presents its token. A URL is what reverse proxies log,
 * so the token rides in the subprotocol the service reads and agrees to.
 */

class FakeSocket {
  static readonly CONNECTING = 0
  static readonly OPEN = 1
  static instances: FakeSocket[] = []
  readyState = FakeSocket.CONNECTING
  onopen: (() => void) | null = null
  onmessage: ((event: { data: string }) => void) | null = null
  onerror: (() => void) | null = null
  onclose: ((event: { code: number }) => void) | null = null
  constructor(
    public url: string,
    public protocols?: string | string[],
  ) {
    FakeSocket.instances.push(this)
  }
  send() {}
  closed: number | null = null
  close(code?: number) {
    this.closed = code ?? 1000
    this.readyState = 3
  }
  /** The relay accepted the connection. */
  open() {
    this.readyState = FakeSocket.OPEN
    this.onopen?.()
  }
}

describe('RemoteSignallingClient', () => {
  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
    FakeSocket.instances = []
  })

  it('sends the token as the subprotocol, not in the URL, and re-mints it on reconnect', async () => {
    vi.useFakeTimers()
    vi.stubGlobal('WebSocket', FakeSocket)

    const refreshToken = vi.fn(async () => ({ url: 'wss://signal.test/signal', token: 'fresh.tok.en' }))
    const client = new RemoteSignallingClient({
      url: 'wss://signal.test/signal',
      token: 'first.tok.en',
      refreshToken,
      onMessage: () => {},
      onStatus: () => {},
    })

    client.connect()

    const [first] = FakeSocket.instances
    expect(first.url).toBe('wss://signal.test/signal')
    expect(first.protocols).toEqual(['aicountly-remote.first.tok.en'])

    // The network blinks: the client re-mints and presents the new token the same way.
    first.onclose?.({ code: 1006 })
    await vi.runOnlyPendingTimersAsync()

    expect(refreshToken).toHaveBeenCalledTimes(1)
    const second = FakeSocket.instances[1]
    expect(second.url).toBe('wss://signal.test/signal')
    expect(second.protocols).toEqual(['aicountly-remote.fresh.tok.en'])

    client.close()
  })

  /**
   * The epoch is the opposite case from the token: it has to be in the URL, because the relay reads it
   * at the upgrade and echoes it to the room before any message is exchanged. It authorises nothing, so
   * a proxy log costs nothing — and it must survive a re-mint, or every reconnect would look to the
   * other participants like a page that had been reloaded and they would rebuild for nothing.
   */
  it('puts this page’s epoch in the URL and keeps it across a re-minted token', async () => {
    vi.useFakeTimers()
    vi.stubGlobal('WebSocket', FakeSocket)

    const client = new RemoteSignallingClient({
      url: 'wss://signal.test/signal',
      token: 'first.tok.en',
      refreshToken: async () => ({ url: 'wss://signal.test/signal?room=r', token: 'fresh.tok.en' }),
      onMessage: () => {},
      onStatus: () => {},
      epoch: 'page-one',
    })

    client.connect()
    expect(FakeSocket.instances[0].url).toBe('wss://signal.test/signal?epoch=page-one')

    FakeSocket.instances[0].onclose?.({ code: 1006 })
    await vi.runOnlyPendingTimersAsync()

    // The same epoch, on whatever url the fresh credentials named.
    const second = new URL(FakeSocket.instances[1].url)
    expect(second.searchParams.get('epoch')).toBe('page-one')
    expect(second.searchParams.get('room')).toBe('r')
    expect(second.searchParams.get('token')).toBeNull()

    client.close()
  })

  it('connects without an epoch rather than failing on a url it cannot parse', async () => {
    vi.stubGlobal('WebSocket', FakeSocket)

    const client = new RemoteSignallingClient({
      url: 'not a url',
      token: 'first.tok.en',
      refreshToken: async () => ({ url: 'not a url', token: 'fresh.tok.en' }),
      onMessage: () => {},
      onStatus: () => {},
      epoch: 'page-one',
    })

    client.connect()
    expect(FakeSocket.instances[0].url).toBe('not a url')

    client.close()
  })
  /**
   * The relay closes a socket when its two-minute token expires and tells the room the participant
   * left. A computer hosting the session through the desktop app ended the session on that. So the
   * connection is replaced before the token expires, and the relay announces nothing.
   */
  describe('renewing before the token expires', () => {
    const start = async (expiresAt: string | null, onMessage = vi.fn(), onStatus = vi.fn()) => {
      vi.useFakeTimers()
      vi.setSystemTime(new Date('2026-11-13T10:00:00Z'))
      vi.stubGlobal('WebSocket', FakeSocket)

      const refreshToken = vi.fn(async () => ({
        url: 'wss://signal.test/signal',
        token: 'second.tok.en',
        expiresAt: '2026-11-13T10:03:30Z',
      }))

      const client = new RemoteSignallingClient({
        url: 'wss://signal.test/signal',
        token: 'first.tok.en',
        expiresAt,
        refreshToken,
        onMessage,
        onStatus,
      })

      client.connect()
      FakeSocket.instances[0].open()

      return { client, refreshToken, onMessage, onStatus }
    }

    it('asks thirty seconds before the expiry, and never sooner than a few seconds from now', () => {
      const now = Date.parse('2026-11-13T10:00:00Z')

      expect(renewDelayMs('2026-11-13T10:02:00Z', now)).toBe(90_000)
      // Nothing said when: the server's documented two minutes.
      expect(renewDelayMs(null, now)).toBe(90_000)
      expect(renewDelayMs('not a date', now)).toBe(90_000)
      // Already past, or nearly: soon, but not in a spin.
      expect(renewDelayMs('2026-11-13T09:59:00Z', now)).toBe(5_000)
    })

    it('opens the new connection first, and only then lets go of the old one', async () => {
      const { client, refreshToken, onMessage, onStatus } = await start('2026-11-13T10:02:00Z')
      const [first] = FakeSocket.instances

      await vi.advanceTimersByTimeAsync(89_000)
      expect(refreshToken).not.toHaveBeenCalled()

      await vi.advanceTimersByTimeAsync(1_000)
      expect(refreshToken).toHaveBeenCalledTimes(1)

      const second = FakeSocket.instances[1]
      expect(second.protocols).toEqual(['aicountly-remote.second.tok.en'])
      expect(first.closed).toBeNull()

      // The relay takes the new connection and closes the old one as replaced — in that order.
      first.onclose?.({ code: 4004 })
      second.open()

      expect(first.closed).toBe(1000)
      expect(onStatus).not.toHaveBeenCalledWith('closed')
      expect(onStatus).not.toHaveBeenCalledWith('reconnecting')
      expect(client.currentStatus).toBe('open')

      // The relay's greeting on the renewed connection is not an arrival; everything else is delivered.
      second.onmessage?.({ data: JSON.stringify({ type: 'joined', peers: [] }) })
      second.onmessage?.({ data: JSON.stringify({ type: 'offer', from: 'peer' }) })
      expect(onMessage).toHaveBeenCalledTimes(1)
      expect(onMessage).toHaveBeenCalledWith({ type: 'offer', from: 'peer' })

      // And the renewed connection is renewed in its turn, from its own expiry.
      await vi.advanceTimersByTimeAsync(89_000)
      expect(refreshToken).toHaveBeenCalledTimes(1)
      await vi.advanceTimersByTimeAsync(1_000)
      expect(refreshToken).toHaveBeenCalledTimes(2)

      client.close()
    })

    it('keeps the live connection when a renewal fails, and tries again', async () => {
      const { client, refreshToken } = await start('2026-11-13T10:02:00Z')
      const [first] = FakeSocket.instances

      await vi.advanceTimersByTimeAsync(90_000)
      const second = FakeSocket.instances[1]

      // The replacement never opens.
      second.onclose?.({ code: 1006 })

      expect(first.closed).toBeNull()
      expect(client.currentStatus).toBe('open')

      await vi.advanceTimersByTimeAsync(5_000)
      expect(refreshToken).toHaveBeenCalledTimes(2)
      expect(FakeSocket.instances).toHaveLength(3)

      client.close()
    })

    it('still reconnects when the live connection drops with no renewal under way', async () => {
      const { client, refreshToken } = await start('2026-11-13T10:02:00Z')
      const [first] = FakeSocket.instances

      first.onclose?.({ code: 4003 })
      await vi.advanceTimersByTimeAsync(1_000)

      expect(refreshToken).toHaveBeenCalledTimes(1)
      expect(FakeSocket.instances).toHaveLength(2)

      client.close()
    })
  })
})
