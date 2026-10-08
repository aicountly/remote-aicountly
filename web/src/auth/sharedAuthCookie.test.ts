import { afterEach, expect, test } from 'vitest'
import { purgeLegacySharedAuthToken, readSharedAuthToken } from './sharedAuthCookie'
import { clearAllTokens, setAuthToken } from './tokens'

// The shared `auth_token` cookie is retired: never written, never read, purged when found.
const hasCookie = () => document.cookie.split(';').some((part) => part.trim().startsWith('auth_token='))

afterEach(() => {
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

test('purgeLegacySharedAuthToken removes a cookie an older release left', () => {
  document.cookie = 'auth_token=legacy; path=/'
  expect(hasCookie()).toBe(true)
  purgeLegacySharedAuthToken()
  expect(hasCookie()).toBe(false)
})
