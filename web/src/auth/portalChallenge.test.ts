/**
 * The firewall interstitial, and why sign-in used to die on it.
 *
 * Production sits behind a WAF that answers a request it cannot vouch for with
 * an HTML "checking your browser" page carrying **HTTP 200**. The exchange POST
 * was the one same-origin call in the app that sent neither cookies nor an
 * `Accept`, so it was the one the WAF challenged: `res.ok` was true, the body
 * would not parse, and sign-in reported "temporarily unavailable" on every
 * attempt with no way out.
 *
 * These tests pin the three things that fix it: what the request now sends, that
 * a challenge is never read as the portal's answer, and that a user whose
 * sign-in is intercepted keeps their session instead of being told their link
 * is spent.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { AuthError, exchangeSsoCode } from './portal'
import { clearAllTokens } from './tokens'

const SSO_CODE = 'a'.repeat(64)
const CB_STATE = '48d1b984a6f8a735fff1340bf4a44104'

/** The WAF's answer: HTML, and a 200 that makes `res.ok` true. */
function challengePage(): Response {
  return new Response('<!DOCTYPE html><title>One moment, please...</title>', {
    status: 200,
    headers: { 'Content-Type': 'text/html; charset=utf-8' },
  })
}

function exchanged(token: string): Response {
  return new Response(JSON.stringify({ status: 1, auth_token: token }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })
}

let fetchMock: ReturnType<typeof vi.fn>

/** Every call made, as (url, init) pairs. */
function calls(): Array<[string, RequestInit]> {
  return fetchMock.mock.calls.map(([url, init]) => [String(url), init as RequestInit])
}

function headersOf(init: RequestInit): Record<string, string> {
  return (init.headers ?? {}) as Record<string, string>
}

beforeEach(() => {
  clearAllTokens()
  sessionStorage.clear()
  fetchMock = vi.fn()
  vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
  vi.unstubAllGlobals()
  clearAllTokens()
  sessionStorage.clear()
})

describe('the sign-in exchange, behind a WAF', () => {
  it('asks the relay for JSON, as an API call, with this origin’s cookies', async () => {
    fetchMock.mockResolvedValueOnce(exchanged('tok-live'))

    await expect(exchangeSsoCode(SSO_CODE, CB_STATE)).resolves.toBe('tok-live')

    const [url, init] = calls()[0]
    expect(url).toBe(`${window.location.origin}/api/global/sso/exchange`)
    // Accept is the header the WAF reads: it serves its HTML page only to a
    // client that says it renders HTML, and a fetch with no Accept does.
    expect(headersOf(init).Accept).toBe('application/json')
    expect(headersOf(init)['X-Requested-With']).toBe('XMLHttpRequest')
    // Same-origin, so the clearance cookie this page already earned goes with
    // the request the WAF is gating. `omit` here is what broke sign-in.
    expect(init.credentials).toBe('same-origin')
    expect(JSON.parse(String(init.body))).toMatchObject({ sso_code: SSO_CODE, product: 'remote' })
  })

  it('never reads a challenge page as the portal’s answer, and asks the portal itself', async () => {
    fetchMock.mockResolvedValueOnce(challengePage()).mockResolvedValueOnce(exchanged('tok-direct'))

    await expect(exchangeSsoCode(SSO_CODE, CB_STATE)).resolves.toBe('tok-direct')

    const [, direct] = calls()
    expect(direct[0]).toBe('https://my.aicountly.com/api/sso/exchange')
    // Another origin: it authenticates by Bearer token and is never handed this
    // site's cookies.
    expect(direct[1].credentials).toBe('omit')
    expect(headersOf(direct[1]).Accept).toBe('application/json')
  })

  it('reports an intercepted sign-in as a retryable outage, not a spent link', async () => {
    fetchMock.mockResolvedValue(challengePage())

    const err = await exchangeSsoCode(SSO_CODE, CB_STATE).catch((e: unknown) => e)

    expect(err).toBeInstanceOf(AuthError)
    // 503, not 401: the request never reached the portal, so the one-time code
    // is unspent and the person keeps whatever session they had. A 401 here
    // would send them round the login loop that never completes.
    expect((err as AuthError).status).toBe(503)
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })
})
