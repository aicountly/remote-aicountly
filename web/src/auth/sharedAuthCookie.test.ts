import { afterEach, expect, test, vi } from 'vitest'
import { clearSharedAuthToken, purgeLegacySharedAuthToken, readSharedAuthToken } from './sharedAuthCookie'
import { clearAllTokens, setAuthToken } from './tokens'

// The shared `auth_token` cookie is retired: never written, never read, purged when found —
// and only on .aicountly.com, where it was written. Elsewhere an `auth_token` cookie belongs to something else.
const hasCookie = () => document.cookie.split(';').some((part) => part.trim().startsWith('auth_token='))

/** Pretend the page is on `hostname` with a legacy cookie present, and record every `document.cookie` write. */
function onHost(hostname: string) {
  vi.spyOn(window, 'location', 'get').mockReturnValue({ hostname, protocol: 'https:' } as Location)
  vi.spyOn(document, 'cookie', 'get').mockReturnValue('auth_token=legacy')
  return vi.spyOn(document, 'cookie', 'set').mockImplementation(() => {})
}

afterEach(() => {
  vi.restoreAllMocks()
  clearAllTokens()
  document.cookie = 'auth_token=; path=/; max-age=0'
})

test('signing in never writes the auth_token cookie', () => {
  setAuthToken('x')
  expect(hasCookie()).toBe(false)
})

test('the cookie is never read, even when one is present', () => {
  document.cookie = 'auth_token=legacy; path=/'
  expect(readSharedAuthToken()).toBeNull()
})

test('off .aicountly.com (localhost) an auth_token cookie is left untouched', () => {
  document.cookie = 'auth_token=someone-elses; path=/'
  purgeLegacySharedAuthToken()
  clearSharedAuthToken()
  expect(hasCookie()).toBe(true)

  const write = onHost('localhost')
  purgeLegacySharedAuthToken()
  clearSharedAuthToken()
  expect(write).not.toHaveBeenCalled()
})

test('on an AICOUNTLY host only the .aicountly.com cookie an older release left is expired', () => {
  const write = onHost('remote.aicountly.com')
  purgeLegacySharedAuthToken()
  expect(write).toHaveBeenCalledTimes(1)
  const cookie = String(write.mock.calls[0][0])
  expect(cookie).toMatch(/^auth_token=;/)
  expect(cookie).toContain('domain=.aicountly.com')
  expect(cookie).toContain('max-age=0')
})
