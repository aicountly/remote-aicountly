import { afterEach, describe, expect, it, vi } from 'vitest'
import { getCompanyRegistry } from './companyRegistry'
import { performLogout } from '../auth/portal'
import { clearAllTokens, saveSession, setAuthToken } from '../auth/tokens'

/**
 * The browser reaches Manage's company registry and the portal's sign-out only through Remote's
 * own API. Production hosting answers a browser it distrusts (common Indian CGNAT broadband) with a
 * challenge page that carries no CORS headers, so a call to manage.aicountly.com or
 * my.aicountly.com fails with no response at all; the API relays them (backend
 * ManageRegistryController and PortalRelayController's logout).
 */

type Call = { url: string; init: RequestInit }

function recordFetch(): Call[] {
  const calls: Call[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
      calls.push({ url: String(input), init })
      return new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } })
    }),
  )
  return calls
}

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  clearAllTokens()
  sessionStorage.clear()
})

describe('same-origin relays', () => {
  it('the registry client is based on the app\'s own API and sends the person\'s ses_key', async () => {
    const calls = recordFetch()
    saveSession('ses-1', 900)
    const registry = getCompanyRegistry()
    expect(registry.baseUrl).toBe(`${window.location.origin}/api/manage`)

    await registry.workspaces()
    expect(calls).toHaveLength(1)
    expect(calls[0].url).toBe(`${window.location.origin}/api/manage/v1/workspaces`)
    expect((calls[0].init.headers as Record<string, string>).Authorization).toBe('Bearer ses-1')
  })

  it('sign-out revokes the token through /api/global/logout without waiting for it', () => {
    const calls = recordFetch()
    const replace = vi.fn()
    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, origin: window.location.origin, replace } as Location)
    setAuthToken('auth-1')

    performLogout()

    expect(calls).toHaveLength(1)
    expect(calls[0].url).toBe(`${window.location.origin}/api/global/logout`)
    expect(calls[0].init.method).toBe('POST')
    expect((calls[0].init.headers as Record<string, string>).Authorization).toBe('Bearer auth-1')
    expect(calls[0].init.keepalive).toBe(true)
    expect(replace).toHaveBeenCalledTimes(1)
  })
})
