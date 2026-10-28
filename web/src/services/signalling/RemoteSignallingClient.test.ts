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
})
