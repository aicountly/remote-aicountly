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

describe('a server failure carries a support reference (no blank errors)', () => {
  beforeEach(() => {
    vi.mocked(ensureSesKey).mockResolvedValue('ses-key')
  })
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.resetAllMocks()
  })

  it('sends a fresh X-Request-Id and quotes it when a 500 comes back with no body', async () => {
    const fetchMock = vi.fn(async () => new Response('', { status: 500 }))
    vi.stubGlobal('fetch', fetchMock)
    const error = (await apiFetch('/bootstrap').catch((e: unknown) => e)) as RemoteApiError
    const sent = (fetchMock.mock.calls[0] as unknown as [string, RequestInit])[1].headers as Record<string, string>
    expect(sent['X-Request-Id']).toMatch(/^[0-9a-f]{32}$/)
    expect(error.status).toBe(500)
    expect(error.message).toBe(`Something went wrong. Please try again. (Ref: ${sent['X-Request-Id']})`)
  })

  it("prefers the reference the server logged, and leaves a 4xx's sentence alone", async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(
        async () =>
          new Response(JSON.stringify({ error: { code: 'SERVER_ERROR', message: 'Something went wrong at our end. Please try again.', request_id: 'srv-ref-123456' }, request_id: 'srv-ref-123456' }), {
            status: 500,
          }),
      ),
    )
    await expect(apiFetch('/bootstrap')).rejects.toMatchObject({
      message: 'Something went wrong at our end. Please try again. (Ref: srv-ref-123456)',
    })

    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ error: { code: 'NOT_FOUND', message: 'Not found.' } }), { status: 404 })))
    await expect(apiFetch('/bootstrap')).rejects.toMatchObject({ message: 'Not found.', status: 404 })
  })

  it('reads an HTML error page on the metadata variant as a failure with a reference, not a parse error', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('<html>502 Bad Gateway</html>', { status: 502, headers: { 'X-Request-Id': 'proxy-ref-abcdef' } })))
    const error = (await apiFetchWithMeta('/sessions/history').catch((e: unknown) => e)) as RemoteApiError
    expect(error).toBeInstanceOf(RemoteApiError)
    expect(error.message).toBe('Something went wrong. Please try again. (Ref: proxy-ref-abcdef)')
  })
})
