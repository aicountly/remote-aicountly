import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * The engine owns the socket, the peer connections and the screen capture. It must be built once per admitted
 * participant and survive every poll of the session record: an effect keyed on that record (a new object on every poll)
 * tore the engine down each time, so a share never reached the viewer and every poll was a signalling reconnect.
 */

const engines: Array<{ dispose: ReturnType<typeof vi.fn>; start: ReturnType<typeof vi.fn> }> = []

vi.mock('../../services/webrtc/RemoteSessionEngine', () => ({
  RemoteSessionEngine: vi.fn().mockImplementation(function (this: Record<string, unknown>) {
    this.start = vi.fn().mockResolvedValue(undefined)
    this.dispose = vi.fn()
    this.startSharing = vi.fn().mockResolvedValue(undefined)
    this.stopSharing = vi.fn().mockResolvedValue(undefined)
    engines.push(this as never)
  }),
}))

const fetchSession = vi.fn()

vi.mock('../../services/api/remote', () => ({
  approveParticipant: vi.fn(),
  declareShareIntent: vi.fn(),
  denyControl: vi.fn(),
  denyParticipant: vi.fn(),
  endSession: vi.fn(),
  fetchControlState: vi.fn().mockResolvedValue({ pendingRequests: [] }),
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
