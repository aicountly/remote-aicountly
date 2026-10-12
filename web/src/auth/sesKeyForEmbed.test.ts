/**
 * The ses_key handed to AICOUNTLY Connect: Remote's own key, a fresh one when
 * Connect saw a 401, and null — never a throw, never a navigation — when there
 * is none to give.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { sesKeyForEmbed } from './portal'
import { clearAllTokens, getSesKey, saveSession, stageAuthToken } from './tokens'

function seskeyResponse(key: string): Response {
  return new Response(JSON.stringify({ ses_key: key, expires_in: 900 }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })
}

let fetchMock: ReturnType<typeof vi.fn>

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

describe('sesKeyForEmbed', () => {
  it('is null when nobody is signed in, without asking the portal', async () => {
    await expect(sesKeyForEmbed(false)).resolves.toBeNull()
    await expect(sesKeyForEmbed(true)).resolves.toBeNull()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('is null while a sign-out is in progress', async () => {
    stageAuthToken('auth-token')
    sessionStorage.setItem('aic_logout', '1')
    await expect(sesKeyForEmbed(false)).resolves.toBeNull()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("hands over Remote's cached key normally", async () => {
    stageAuthToken('auth-token')
    saveSession('cached-key', 900)
    await expect(sesKeyForEmbed(false)).resolves.toBe('cached-key')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('mints a fresh key when forced, and Remote uses it too', async () => {
    stageAuthToken('auth-token')
    saveSession('stale-key', 900)
    fetchMock.mockResolvedValueOnce(seskeyResponse('fresh-key'))

    await expect(sesKeyForEmbed(true)).resolves.toBe('fresh-key')
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(getSesKey()).toBe('fresh-key')
  })

  it('is null, not a throw, when the portal refuses or cannot be reached', async () => {
    stageAuthToken('auth-token')
    fetchMock.mockResolvedValue(new Response('no', { status: 401 }))
    await expect(sesKeyForEmbed(true)).resolves.toBeNull()

    fetchMock.mockRejectedValue(new TypeError('offline'))
    await expect(sesKeyForEmbed(false)).resolves.toBeNull()
  })
})
