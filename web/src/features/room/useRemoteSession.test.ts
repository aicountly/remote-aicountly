import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * The engine owns the socket, the peer connections and the screen capture. It must be built once per admitted
 * participant and survive every poll of the session record: an effect keyed on that record (a new object on every poll)
 * tore the engine down each time, so a share never reached the viewer and every poll was a signalling reconnect.
 */

const engines: Array<{
  dispose: ReturnType<typeof vi.fn>
  start: ReturnType<typeof vi.fn>
  announceSessionEnded: ReturnType<typeof vi.fn>
  dropUnadmitted: ReturnType<typeof vi.fn>
  options: { onSessionEnded: () => void }
}> = []

vi.mock('../../services/webrtc/RemoteSessionEngine', () => ({
  RemoteSessionEngine: vi.fn().mockImplementation(function (this: Record<string, unknown>, options: unknown) {
    this.options = options
    this.start = vi.fn().mockResolvedValue(undefined)
    this.dispose = vi.fn()
    this.announceSessionEnded = vi.fn()
    this.dropUnadmitted = vi.fn()
    this.startSharing = vi.fn().mockResolvedValue(undefined)
    this.stopSharing = vi.fn().mockResolvedValue(undefined)
    this.setControlTarget = vi.fn()
    this.sendControlHint = vi.fn()
    engines.push(this as never)
  }),
  COMPANION_ROLE: 'CONTROL_COMPANION',
}))

const fetchSession = vi.fn()
const endSession = vi.fn()
const fetchControlState = vi.fn(async (..._args: unknown[]) => ({ pendingRequests: [] }))

vi.mock('../../services/api/remote', () => ({
  approveParticipant: vi.fn(),
  declareShareIntent: vi.fn(),
  denyControl: vi.fn(),
  denyParticipant: vi.fn(),
  endSession: (...args: unknown[]) => endSession(...args),
  fetchControlState: (...args: unknown[]) => fetchControlState(...args),
  fetchMessages: vi.fn().mockResolvedValue([]),
  fetchSession: (...args: unknown[]) => fetchSession(...args),
  grantControl: vi.fn(),
  leaveSession: vi.fn().mockResolvedValue(undefined),
  pauseSession: vi.fn(),
  postMessage: vi.fn(),
  reportContextMismatch: vi.fn(),
  requestControl: vi.fn(),
  requestJoin: vi.fn(),
  resumeSession: vi.fn(),
  revokeControl: vi.fn(),
}))

vi.mock('../../ice-kit/index.js', () => ({ probeRelay: vi.fn() }))

const { useRemoteSession } = await import('./useRemoteSession')

/** A fresh object on every call, exactly as the API returns it. */
function sessionRecord(status: string, isHost: boolean) {
  return {
    uuid: 'sess-1',
    status: 'ACTIVE',
    companyId: 7,
    isHost,
    capabilities: { chat: false },
    participants: [],
    waiting: [],
    me: { uuid: 'p-1', displayName: 'Asha', status, controlState: 'NONE' },
  }
}

async function flush(ms = 0) {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(ms)
  })
}

describe('useRemoteSession engine lifetime', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    engines.length = 0
    fetchSession.mockReset()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('keeps one engine across every poll of the session record', async () => {
    fetchSession.mockImplementation(async () => sessionRecord('JOINED', true))

    const { unmount } = renderHook(() => useRemoteSession({ sessionUuid: 'sess-1', policy: null }))
    await flush()
    expect(engines).toHaveLength(1)

    // A minute of polls: every one returns a new record object.
    await flush(60_000)
    expect(fetchSession.mock.calls.length).toBeGreaterThan(5)
    expect(engines).toHaveLength(1)
    expect(engines[0].dispose).not.toHaveBeenCalled()

    unmount()
    expect(engines[0].dispose).toHaveBeenCalled()
  })

  it('keeps the engine when APPROVED becomes JOINED', async () => {
    let status = 'APPROVED'
    fetchSession.mockImplementation(async () => sessionRecord(status, false))

    renderHook(() => useRemoteSession({ sessionUuid: 'sess-1', policy: null }))
    await flush()
    expect(engines).toHaveLength(1)

    status = 'JOINED'
    await flush(30_000)
    expect(engines).toHaveLength(1)
    expect(engines[0].dispose).not.toHaveBeenCalled()
  })

  it('starts the room within two seconds of the host admitting a waiting participant', async () => {
    let status = 'REQUESTED'
    fetchSession.mockImplementation(async () => sessionRecord(status, false))

    renderHook(() => useRemoteSession({ sessionUuid: 'sess-1', policy: null }))
    await flush()
    expect(engines).toHaveLength(0)

    status = 'APPROVED'
    await flush(2_000)
    expect(engines).toHaveLength(1)
  })

  it('notices a new participant waiting within five seconds on the host side', async () => {
    fetchSession.mockImplementation(async () => sessionRecord('JOINED', true))

    renderHook(() => useRemoteSession({ sessionUuid: 'sess-1', policy: null }))
    await flush()
    const before = fetchSession.mock.calls.length

    await flush(5_000)
    expect(fetchSession.mock.calls.length).toBeGreaterThan(before)
  })
})

/**
 * However a session ends, this browser stops capturing and closes its peer connections. An expired session used to
 * keep its participants JOINED, so the engine was never torn down: the screen kept streaming to the viewer under a
 * screen that said "Screen sharing: Stopped".
 */
describe('useRemoteSession — an ended session keeps nothing running', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    engines.length = 0
    fetchSession.mockReset()
    endSession.mockReset()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  /** The session's own status this time, the participant still JOINED (as an expiry leaves it). */
  function withStatus(status: string) {
    return { ...sessionRecord('JOINED', true), status }
  }

  it('stops the engine when the server reports the session expired', async () => {
    let status = 'ACTIVE'
    fetchSession.mockImplementation(async () => withStatus(status))

    const { result } = renderHook(() => useRemoteSession({ sessionUuid: 'sess-1', policy: null }))
    await flush()
    expect(engines).toHaveLength(1)

    status = 'EXPIRED'
    await flush(5_000)

    expect(result.current.ended).toBe(true)
    expect(engines[0].dispose).toHaveBeenCalled()

    // And nothing starts again afterwards.
    await flush(60_000)
    expect(engines).toHaveLength(1)
  })

  it('stops the engine when the other person ends the session', async () => {
    let status = 'ACTIVE'
    fetchSession.mockImplementation(async () => withStatus(status))

    const { result } = renderHook(() => useRemoteSession({ sessionUuid: 'sess-1', policy: null }))
    await flush()
    expect(engines).toHaveLength(1)

    // They ended it with the API, then said so in the room.
    status = 'ENDED'
    act(() => engines[0].options.onSessionEnded())
    await flush()

    expect(result.current.ended).toBe(true)
    expect(engines[0].dispose).toHaveBeenCalled()
    expect(engines).toHaveLength(1)
  })

  it('does not end on a participant\'s word that the API contradicts', async () => {
    fetchSession.mockImplementation(async () => withStatus('ACTIVE'))

    const { result } = renderHook(() => useRemoteSession({ sessionUuid: 'sess-1', policy: null }))
    await flush()

    act(() => engines[0].options.onSessionEnded())
    await flush()

    expect(result.current.ended).toBe(false)
    expect(engines[0].dispose).not.toHaveBeenCalled()
  })

  it('End stops this browser\'s capture even when the API call fails', async () => {
    fetchSession.mockImplementation(async () => withStatus('ACTIVE'))
    endSession.mockRejectedValue(new Error('offline'))

    const { result } = renderHook(() => useRemoteSession({ sessionUuid: 'sess-1', policy: null }))
    await flush()
    expect(engines).toHaveLength(1)

    let failure: unknown = null
    await act(async () => {
      await result.current.actions.end().catch((err: unknown) => {
        failure = err
      })
    })

    expect(failure).toBeInstanceOf(Error)
    // The other side is not told a session ended that the API did not end.
    expect(engines[0].announceSessionEnded).not.toHaveBeenCalled()
    expect(engines[0].dispose).toHaveBeenCalled()
    expect(result.current.live).toBeNull()
    // The session itself was not ended, and the page does not claim it was.
    expect(result.current.ended).toBe(false)
  })
})

describe('useRemoteSession — End', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    engines.length = 0
    fetchSession.mockReset()
    endSession.mockReset()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('tells the other side once the API has ended the session, then stops everything here', async () => {
    let status = 'ACTIVE'
    fetchSession.mockImplementation(async () => ({ ...sessionRecord('JOINED', true), status }))
    endSession.mockImplementation(async () => {
      status = 'ENDED'
    })

    const { result } = renderHook(() => useRemoteSession({ sessionUuid: 'sess-1', policy: null }))
    await flush()

    await act(async () => {
      await result.current.actions.end()
    })

    expect(endSession).toHaveBeenCalledWith('sess-1')
    expect(engines[0].announceSessionEnded).toHaveBeenCalled()
    expect(engines[0].dispose).toHaveBeenCalled()
    expect(result.current.ended).toBe(true)
  })
})

describe('useRemoteSession — the waiting room', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    engines.length = 0
    fetchSession.mockReset()
    fetchControlState.mockClear()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('does not ask for control state until the host has admitted us', async () => {
    let status = 'REQUESTED'
    fetchSession.mockImplementation(async () => sessionRecord(status, false))

    renderHook(() => useRemoteSession({ sessionUuid: 'sess-1', policy: null }))
    await flush(30_000)
    expect(fetchControlState).not.toHaveBeenCalled()

    status = 'APPROVED'
    await flush(2_000)
    expect(fetchControlState).toHaveBeenCalled()
  })
})

describe('useRemoteSession — somebody the server no longer admits', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    engines.length = 0
    fetchSession.mockReset()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('hands the engine everybody not admitted, with when the read was asked for', async () => {
    let participants = [{ uuid: 'p-2', status: 'JOINED' }]
    const askedAt: number[] = []
    fetchSession.mockImplementation(async () => {
      askedAt.push(Date.now())
      // The answer takes a while: what counts is when it was asked for, not when it arrived.
      await new Promise((resolve) => setTimeout(resolve, 300))

      return { ...sessionRecord('JOINED', true), participants }
    })

    renderHook(() => useRemoteSession({ sessionUuid: 'sess-1', policy: null }))
    await flush(300)
    expect(engines).toHaveLength(1)
    expect(engines[0].dropUnadmitted).not.toHaveBeenCalled()

    // Closed by the server (the session expired, or they left), though their browser may still be in the room.
    participants = [{ uuid: 'p-2', status: 'LEFT' }, { uuid: 'p-3', status: 'REQUESTED' }]
    await flush(5_000)

    expect(engines[0].dropUnadmitted).toHaveBeenCalled()
    const [uuids, since] = engines[0].dropUnadmitted.mock.calls.at(-1) as [string[], number]
    expect(uuids).toEqual(['p-2', 'p-3'])
    expect(askedAt).toContain(since)
  })
})
