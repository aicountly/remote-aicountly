import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { AuthError } from '../../auth/portal'

vi.mock('../../auth/portal', async () => {
  const actual = await vi.importActual<typeof import('../../auth/portal')>('../../auth/portal')
  return { ...actual, ensureSesKey: vi.fn() }
})

import { ensureSesKey } from '../../auth/portal'
import { RemoteApiError, apiFetch, apiFetchWithMeta } from './client'

describe('an outage of the sign-in service is not a signed-out person (I-16)', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('{}', { status: 200 })))
  })
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.resetAllMocks()
  })

  it('maps a portal refusal to UNAUTHENTICATED', async () => {
    vi.mocked(ensureSesKey).mockRejectedValue(new AuthError('refused', 401))
    await expect(apiFetch('/bootstrap')).rejects.toMatchObject({ code: 'UNAUTHENTICATED', status: 401 })
  })

  it.each([0, 500, 503, 504, 429])('maps a portal answer of %i to AUTH_UNAVAILABLE and keeps the session', async (status) => {
    vi.mocked(ensureSesKey).mockRejectedValue(new AuthError('down', status))
    const error = await apiFetch('/bootstrap').catch((e: unknown) => e)
    expect(error).toBeInstanceOf(RemoteApiError)
    expect(error).toMatchObject({ code: 'AUTH_UNAVAILABLE', status: 503, details: { retryable: true } })
    expect((error as RemoteApiError).isRetryable).toBe(true)
  })

  it('does the same on the metadata variant', async () => {
    vi.mocked(ensureSesKey).mockRejectedValue(new AuthError('down', 503))
    await expect(apiFetchWithMeta('/sessions/history')).rejects.toMatchObject({ code: 'AUTH_UNAVAILABLE' })
  })

  it("passes the API's own AUTH_UNAVAILABLE 503 through", async () => {
    vi.mocked(ensureSesKey).mockResolvedValue('ses-key')
    vi.stubGlobal(
      'fetch',
      vi.fn(
        async () =>
          new Response(JSON.stringify({ error: { code: 'AUTH_UNAVAILABLE', message: 'later', details: { retryable: true } } }), {
            status: 503,
          }),
      ),
    )
    await expect(apiFetch('/bootstrap')).rejects.toMatchObject({ code: 'AUTH_UNAVAILABLE', status: 503 })
  })
})
