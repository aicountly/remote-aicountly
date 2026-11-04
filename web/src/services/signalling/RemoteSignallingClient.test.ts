import { afterEach, describe, expect, it, vi } from 'vitest'

import { RemoteSignallingClient } from './RemoteSignallingClient'

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
  close() {}
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
})
