import { afterEach, describe, expect, it, vi } from 'vitest'

import { nudgeDevice } from './deviceNudge'

/** The best-effort "look now" sent into a device's presence room after linking a control companion. */

class FakeSocket {
  static last: FakeSocket | null = null
  sent: string[] = []
  closed = false
  onmessage: ((event: { data: string }) => void) | null = null
  onerror: (() => void) | null = null
  onclose: (() => void) | null = null
  constructor(
    public url: string,
    public protocols?: string | string[],
  ) {
    FakeSocket.last = this
  }
  send(data: string) {
    this.sent.push(data)
  }
  close() {
    this.closed = true
  }
}

describe('nudgeDevice', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('says device-invite once the room is joined, then closes', async () => {
    vi.stubGlobal('WebSocket', FakeSocket)

    const done = nudgeDevice({ token: 'tok', url: 'wss://signal.test/signal', sessionUuid: 's-1', companionUuid: 'l-1' })
    const socket = FakeSocket.last as FakeSocket
    socket.onmessage?.({ data: JSON.stringify({ type: 'joined' }) })

    await expect(done).resolves.toBe(true)
    // The token goes in the subprotocol, never the URL a proxy would log.
    expect(socket.url).toBe('wss://signal.test/signal')
    expect(socket.protocols).toEqual(['aicountly-remote.tok'])
    expect(JSON.parse(socket.sent[0])).toEqual({ type: 'device-invite', payload: { sessionUuid: 's-1', companionUuid: 'l-1' } })
    expect(socket.closed).toBe(true)
  })

  it('never throws when the room cannot be reached', async () => {
    vi.stubGlobal('WebSocket', FakeSocket)

    const done = nudgeDevice({ token: 'tok', url: 'wss://signal.test/signal', sessionUuid: 's-1' })
    FakeSocket.last?.onerror?.()

    await expect(done).resolves.toBe(false)
  })
})
