import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, waitFor } from '@testing-library/react'

import { AuthProvider, useAuth } from './AuthProvider'
import {
  captureDesktopHandoff,
  parseDesktopHandoff,
  redeemDesktopHandoff,
  takeDesktopHandoff,
} from './desktopHandoff'
import { clearAllTokens, getAuthToken, setAuthToken } from './tokens'

/**
 * The desktop application handing its embedded portal the sign-in that
 * confirmed the computer, so somebody who confirmed in their browser is not
 * asked to sign in again inside the application — and so a link can never
 * use the same door to sign somebody in as somebody else.
 */

const CODE = 'c'.repeat(64)
const NONCE = 'a1'.repeat(16)

function go(path: string): void {
  window.history.replaceState(null, '', `${window.location.origin}${path}`)
}

function Status() {
  const { status } = useAuth()
  return <p data-testid="status">{status}</p>
}

let replaced: string[] = []
let fetchMock: ReturnType<typeof vi.fn>

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

beforeEach(() => {
  replaced = []
  sessionStorage.clear()
  localStorage.clear()
  clearAllTokens()
  takeDesktopHandoff()

  const real = window.location
  const recording = new Proxy({} as Location, {
    get(_target, prop) {
      if (prop === 'replace') return (url: string | URL) => replaced.push(String(url))
      const value: unknown = Reflect.get(real, prop, real)
      return typeof value === 'function' ? value.bind(real) : value
    },
  })
  vi.spyOn(window, 'location', 'get').mockReturnValue(recording)

  fetchMock = vi.fn(async (url: string) =>
    String(url).includes('/desktop-signin/handoff')
      ? json({ data: { authToken: 'desktop-handed-token' } })
      : json({ ses_key: 'ses-1', expires_in: 900 }),
  )
  vi.stubGlobal('fetch', fetchMock)
  go('/')
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  delete window.__AICOUNTLY_REMOTE_DESKTOP__
  sessionStorage.clear()
  localStorage.clear()
  clearAllTokens()
})

describe('parseDesktopHandoff', () => {
  it('reads a code and the nonce from the fragment', () => {
    expect(parseDesktopHandoff(`#desktop_handoff=${CODE}&n=${NONCE}`)).toEqual({ code: CODE, nonce: NONCE })
  })

  it('ignores anything that is not a 64-hex code with a nonce', () => {
    for (const hash of ['', '#events', `#desktop_handoff=${CODE}`, '#desktop_handoff=short&n=x', `#desktop_handoff=${'Z'.repeat(64)}&n=x`]) {
      expect(parseDesktopHandoff(hash)).toBeNull()
    }
  })
})

describe('captureDesktopHandoff', () => {
  it('takes the code out of the address before anything else reads it, and gives it up once', () => {
    go(`/?tab=x#desktop_handoff=${CODE}&n=${NONCE}`)

    captureDesktopHandoff()

    expect(window.location.hash).toBe('')
    expect(`${window.location.pathname}${window.location.search}`).toBe('/?tab=x')
    expect(takeDesktopHandoff()).toEqual({ code: CODE, nonce: NONCE })
    expect(takeDesktopHandoff()).toBeNull()
  })

  it('turns the desktop’s hand-off page into the app’s start page', () => {
    go(`/auth/desktop#desktop_handoff=${CODE}&n=${NONCE}`)

    captureDesktopHandoff()

    expect(`${window.location.pathname}${window.location.search}${window.location.hash}`).toBe('/')
    expect(takeDesktopHandoff()).toEqual({ code: CODE, nonce: NONCE })
  })

  it('leaves an ordinary fragment alone', () => {
    go('/sessions/1#events')

    captureDesktopHandoff()

    expect(window.location.hash).toBe('#events')
    expect(takeDesktopHandoff()).toBeNull()
  })
})

describe('redeemDesktopHandoff', () => {
  it('redeems a code inside the application that asked for it', async () => {
    await expect(redeemDesktopHandoff({ code: CODE, nonce: NONCE }, { nonce: NONCE })).resolves.toBe('desktop-handed-token')
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining('/v1/remote/desktop-signin/handoff'),
      expect.objectContaining({ method: 'POST', body: JSON.stringify({ code: CODE }), credentials: 'omit' }),
    )
  })

  it('refuses outside the desktop application, or with another launch’s nonce, without asking the server', async () => {
    await expect(redeemDesktopHandoff({ code: CODE, nonce: NONCE }, null)).resolves.toBeNull()
    await expect(redeemDesktopHandoff({ code: CODE, nonce: NONCE }, { nonce: 'b2'.repeat(16) })).resolves.toBeNull()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('is null, not an error, when the code was spent or the server could not be reached', async () => {
    fetchMock.mockResolvedValueOnce(json({ error: { code: 'NOT_FOUND' } }, 404))
    await expect(redeemDesktopHandoff({ code: CODE, nonce: NONCE }, { nonce: NONCE })).resolves.toBeNull()

    fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
    await expect(redeemDesktopHandoff({ code: CODE, nonce: NONCE }, { nonce: NONCE })).resolves.toBeNull()
  })
})

describe('signing the desktop application’s portal in', () => {
  it('signs in with the handed-off sign-in — no login form, no trip to the portal', async () => {
    window.__AICOUNTLY_REMOTE_DESKTOP__ = { nonce: NONCE }
    go(`/auth/desktop#desktop_handoff=${CODE}&n=${NONCE}`)
    captureDesktopHandoff()

    const { getByTestId } = render(
      <AuthProvider>
        <Status />
      </AuthProvider>,
    )

    await waitFor(() => expect(getByTestId('status').textContent).toBe('authenticated'))
    expect(replaced).toEqual([])
    expect(window.location.pathname).toBe('/')
    // Stored only once the portal accepted it, exactly like any other sign-in.
    expect(getAuthToken()).toBe('desktop-handed-token')
    expect(localStorage.getItem('auth_token')).toBe('desktop-handed-token')
    expect(window.location.hash).toBe('')
  })

  it('replaces whoever was signed in here before with the person who confirmed', async () => {
    setAuthToken('somebody-else')
    window.__AICOUNTLY_REMOTE_DESKTOP__ = { nonce: NONCE }
    go(`/#desktop_handoff=${CODE}&n=${NONCE}`)
    captureDesktopHandoff()

    const { getByTestId } = render(
      <AuthProvider>
        <Status />
      </AuthProvider>,
    )

    await waitFor(() => expect(getByTestId('status').textContent).toBe('authenticated'))
    expect(localStorage.getItem('auth_token')).toBe('desktop-handed-token')
  })

  it('never signs anybody in from a link: the wrong nonce falls through to an ordinary sign-in', async () => {
    window.__AICOUNTLY_REMOTE_DESKTOP__ = { nonce: NONCE }
    go(`/#desktop_handoff=${CODE}&n=${'b2'.repeat(16)}`)
    captureDesktopHandoff()

    render(
      <AuthProvider>
        <Status />
      </AuthProvider>,
    )

    await waitFor(() => expect(replaced).toHaveLength(1))
    expect(replaced[0]).toContain('/login/authentication_jump/')
    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/desktop-signin/handoff'))).toBe(false)
    expect(getAuthToken()).toBeNull()
  })
})
