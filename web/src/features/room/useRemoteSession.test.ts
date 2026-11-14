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
const leaveSession = vi.fn(async (..._args: unknown[]) => undefined)
const revokeControl = vi.fn(async (..._args: unknown[]) => ({ control: { pendingRequests: [] } }))
const fetchMessages = vi.fn(async (..._args: unknown[]) => [] as unknown[])
const fetchControlState = vi.fn(async (..._args: unknown[]) => ({ pendingRequests: [] }))

vi.mock('../../services/api/remote', () => ({
  approveParticipant: vi.fn(),
  declareShareIntent: vi.fn(),
  denyControl: vi.fn(),
  denyParticipant: vi.fn(),
  endSession: (...args: unknown[]) => endSession(...args),
  fetchControlState: (...args: unknown[]) => fetchControlState(...args),
  fetchMessages: (...args: unknown[]) => fetchMessages(...args),
  fetchSession: (...args: unknown[]) => fetchSession(...args),
  grantControl: vi.fn(),
  leaveSession: (...args: unknown[]) => leaveSession(...args),
  pauseSession: vi.fn(),
  postMessage: vi.fn(),
  reportContextMismatch: vi.fn(),
  requestControl: vi.fn(),
  requestJoin: vi.fn(),
  resumeSession: vi.fn(),
  revokeControl: (...args: unknown[]) => revokeControl(...args),
}))

vi.mock('../../ice-kit/index.js', () => ({ probeRelay: vi.fn() }))

const { useRemoteSession, effectiveControlState, isRefusal } = await import('./useRemoteSession')
const { RemoteApiError } = await import('../../services/api/client')

type SessionControlStateForTest = import('../../types/remote').SessionControlState

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

describe('useRemoteSession — somebody the host turned away', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    engines.length = 0
    fetchSession.mockReset()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  /**
   * The host pressed Decline. The API then refuses this person the session — and the page, still
   * holding the session it read before, went on saying "Waiting for the host to admit you" for ever,
   * asking again every second and a half and being refused every time.
   */
  it('is told so, and stops asking, instead of waiting for ever', async () => {
    let declined = false
    fetchSession.mockImplementation(async () => {
      if (declined) throw new RemoteApiError('JOIN_DENIED', 'The host declined your request to join this session.', 403)

      return sessionRecord('REQUESTED', false)
    })

    const { result } = renderHook(() => useRemoteSession({ sessionUuid: 'sess-1', policy: null }))
    await flush(2_000)
    expect(result.current.session?.me?.status).toBe('REQUESTED')
    expect(result.current.refused).toBe(false)

    declined = true
    await flush(2_000)

    expect(result.current.session).toBeNull()
    expect(result.current.refused).toBe(true)
    expect(result.current.error?.code).toBe('JOIN_DENIED')

    // And that is the end of the asking.
    const asked = fetchSession.mock.calls.length
    await flush(30_000)
    expect(fetchSession.mock.calls.length).toBe(asked)
  })

  /** Offline, or the server having a bad moment, is not being turned away: what is on screen stays. */
  it('keeps the room through a failure that may pass', async () => {
    let failing = false
    fetchSession.mockImplementation(async () => {
      if (failing) throw new RemoteApiError('NETWORK_ERROR', 'We could not reach AICOUNTLY Remote.', 0)

      return sessionRecord('JOINED', false)
    })

    const { result } = renderHook(() => useRemoteSession({ sessionUuid: 'sess-1', policy: null }))
    await flush(1_000)

    failing = true
    await flush(30_000)

    expect(result.current.session?.me?.status).toBe('JOINED')
    expect(result.current.refused).toBe(false)
    expect(result.current.error?.code).toBe('NETWORK_ERROR')

    failing = false
    await flush(30_000)
    expect(result.current.error).toBeNull()
  })

  it('counts as a refusal only an answer that means this person is not in the session', () => {
    expect(isRefusal(new RemoteApiError('JOIN_DENIED', 'declined', 403))).toBe(true)
    expect(isRefusal(new RemoteApiError('NOT_ADMITTED', 'removed', 403))).toBe(true)
    expect(isRefusal(new RemoteApiError('NOT_FOUND', 'no such session', 404))).toBe(true)

    expect(isRefusal(new RemoteApiError('TWO_STEP_REQUIRED', 'verify', 403))).toBe(false)
    expect(isRefusal(new RemoteApiError('RATE_LIMITED', 'slow down', 429))).toBe(false)
    expect(isRefusal(new RemoteApiError('NETWORK_ERROR', 'offline', 0))).toBe(false)
    expect(isRefusal(new RemoteApiError('UNKNOWN', 'oops', 500))).toBe(false)
  })
})

describe('useRemoteSession — what the room asks the server for', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    engines.length = 0
    fetchSession.mockReset()
    fetchMessages.mockClear()
    leaveSession.mockClear()
    revokeControl.mockClear()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  /**
   * On a slow connection a read took longer than the gap between two and they piled up: answers
   * arriving out of order, and every one behind the first a request made for nothing.
   */
  it('reads the session one at a time, however slow the answer', async () => {
    let answer: (() => void) | null = null
    let asked = 0
    fetchSession.mockImplementation(async () => {
      asked += 1
      if (asked === 1) return sessionRecord('JOINED', true)

      // Every later read hangs until released.
      await new Promise<void>((resolve) => {
        answer = resolve
      })

      return sessionRecord('JOINED', true)
    })

    renderHook(() => useRemoteSession({ sessionUuid: 'sess-1', policy: null }))
    await flush(100)
    expect(asked).toBe(1)

    // A host is read every four seconds: a minute is fifteen of them, and the first never answers.
    for (let step = 0; step < 6; step += 1) await flush(10_000)
    expect(asked).toBe(2)

    // Answered: the next one is asked on the next tick, and only that one.
    await act(async () => {
      answer?.()
      await vi.advanceTimersByTimeAsync(4_000)
    })
    expect(asked).toBe(3)
  })

  /** The whole chat history used to be fetched again after every poll of the session. */
  it('fetches the chat history once, not after every read of the session', async () => {
    fetchSession.mockImplementation(async () => ({ ...sessionRecord('JOINED', true), capabilities: { chat: true } }))

    renderHook(() => useRemoteSession({ sessionUuid: 'sess-1', policy: null }))
    // In steps: a render — and with it the host's four-second pace — only happens between them here.
    for (let step = 0; step < 6; step += 1) await flush(10_000)

    expect(fetchSession.mock.calls.length).toBeGreaterThan(5)
    expect(fetchMessages).toHaveBeenCalledTimes(1)
  })

  /**
   * Closing the tab told the server nothing — a browser cancels an ordinary request with the page
   * that made it — so the person stayed "joined" until the session expired.
   */
  it('tells the server with a request that outlives the page when the page is going away', async () => {
    fetchSession.mockImplementation(async () => sessionRecord('JOINED', false))

    const { result } = renderHook(() => useRemoteSession({ sessionUuid: 'sess-1', policy: null }))
    await flush(100)
    expect(engines).toHaveLength(1)

    await act(async () => {
      await result.current.actions.leave(true)
    })

    expect(leaveSession).toHaveBeenCalledWith('sess-1', 'p-1', { keepalive: true })
    expect(engines[0].dispose).toHaveBeenCalled()

    // An ordinary leave — the button — is an ordinary request.
    leaveSession.mockClear()
    await act(async () => {
      await result.current.actions.leave()
    })
    expect(leaveSession).toHaveBeenCalledWith('sess-1', 'p-1', { keepalive: false })
  })

  /** A request for control could not be taken back: the only way out was to wait for an answer. */
  it('takes back this person’s own request for control', async () => {
    fetchSession.mockImplementation(async () => sessionRecord('JOINED', false))

    const { result } = renderHook(() => useRemoteSession({ sessionUuid: 'sess-1', policy: null }))
    await flush(100)

    await act(async () => {
      await result.current.actions.cancelControlRequest()
    })

    expect(revokeControl).toHaveBeenCalledWith('sess-1', 'p-1')
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

describe('effectiveControlState', () => {
  const control = (overrides: Partial<SessionControlStateForTest>) =>
    ({
      controllableHostUuid: 'host',
      controllerUuid: null,
      controllerName: null,
      clipboardEnabled: false,
      pendingRequests: [],
      allowRemoteControl: true,
      allowClipboardSync: false,
      allowDeviceReboot: false,
      restrictions: [],
      ...overrides,
    }) as SessionControlStateForTest

  it('is GRANTED the moment the control read names this participant, whatever the session record still says', () => {
    // The person at the computer pressed Allow. The session record is re-read every twenty-five
    // seconds for a viewer; the control read is what knows.
    expect(effectiveControlState('REQUESTED', control({ controllerUuid: 'me' }), 'me')).toBe('GRANTED')
    expect(effectiveControlState('NONE', control({ controllerUuid: 'me' }), 'me')).toBe('GRANTED')
  })

  it('is REQUESTED while this participant is in the queue', () => {
    const waiting = control({ pendingRequests: [{ participantUuid: 'me', displayName: 'Me', requestedAt: null }] })

    expect(effectiveControlState('NONE', waiting, 'me')).toBe('REQUESTED')
  })

  it('drops a grant the control read no longer shows, and leaves an answer of no to the record', () => {
    expect(effectiveControlState('GRANTED', control({}), 'me')).toBe('NONE')
    expect(effectiveControlState('GRANTED', control({ controllerUuid: 'somebody-else' }), 'me')).toBe('NONE')
    expect(effectiveControlState('DENIED', control({}), 'me')).toBe('DENIED')
    expect(effectiveControlState('REVOKED', control({}), 'me')).toBe('REVOKED')
  })

  it('is the record’s until there is a control read, or for somebody with no participant', () => {
    expect(effectiveControlState('GRANTED', null, 'me')).toBe('GRANTED')
    expect(effectiveControlState('REQUESTED', control({ controllerUuid: 'me' }), null)).toBe('REQUESTED')
  })
})
