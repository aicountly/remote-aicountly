import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, waitFor } from '@testing-library/react'
import { RouterProvider, createMemoryRouter, useLocation } from 'react-router-dom'
import { AuthProvider, useAuth } from './AuthProvider'
import {
  RETURN_ROUTE_KEY,
  clearCallbackFromUrl,
  normaliseReturnRoute,
  performLogout,
  redirectToPortalLoginForm,
  redirectToPortalSso,
} from './portal'
import { clearAllTokens } from './tokens'
import { CallbackLanding } from '../app/router'

/**
 * A deep link into Remote (a session's detail page, an admin screen) opened by
 * someone with no Remote session goes through the portal, whose only return
 * address is /auth/callback. Since the shared `.aicountly.com` cookie was
 * retired every first visit takes that round trip, so the destination — path,
 * query and hash — must be the address the app opens afterwards, not "/".
 */

const DEEP_LINK = '/sessions/5b2c?tab=recording&q=a%20b#events'

function here(): string {
  const { pathname, search, hash } = window.location
  return `${pathname}${search}${hash}`
}

function go(path: string): void {
  window.history.replaceState(null, '', `${window.location.origin}${path}`)
}

/** The portal's answer to the last jump: the nonce this tab issued comes back with it. */
function portalAnswer(answer: string): string {
  const state = new URL(replaced[replaced.length - 1]).searchParams.get('state') ?? ''
  return `/auth/callback?cb_state=${state}&state=${state}&${answer}`
}

function Status() {
  const { status, message } = useAuth()
  return (
    <p data-testid="status">
      {status}
      {message ? `: ${message}` : ''}
    </p>
  )
}

let replaced: string[] = []

beforeEach(() => {
  replaced = []
  sessionStorage.clear()
  localStorage.clear()
  clearAllTokens()
  // Leaving for the portal is a real navigation; record it instead. jsdom's
  // location.replace cannot be redefined, so the getter hands out a stand-in
  // that records replace() and reads everything else from the real location.
  const real = window.location
  const recording = new Proxy({} as Location, {
    get(_target, prop) {
      if (prop === 'replace') return (url: string | URL) => replaced.push(String(url))
      const value: unknown = Reflect.get(real, prop, real)
      return typeof value === 'function' ? value.bind(real) : value
    },
  })
  vi.spyOn(window, 'location', 'get').mockReturnValue(recording)
  vi.stubGlobal(
    'fetch',
    vi.fn(async () =>
      new Response(JSON.stringify({ auth_token: 'portal-token', ses_key: 'ses-1', expires_in: 900 }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    ),
  )
  go('/')
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  sessionStorage.clear()
  localStorage.clear()
  clearAllTokens()
})

describe('return route through the portal sign-in', () => {
  it('opens the deep link, query and hash included, after the portal answers', async () => {
    // 1. The reader follows the link with no session: the app jumps to the portal.
    go(DEEP_LINK)
    const first = render(
      <AuthProvider>
        <Status />
      </AuthProvider>,
    )
    await waitFor(() => expect(replaced).toHaveLength(1))
    expect(replaced[0]).toContain('/login/authentication_jump/')
    first.unmount()

    // 2. The portal comes back to the only address it knows, with a one-time code.
    go(portalAnswer('sso_code=one-time'))
    const { getByTestId } = render(
      <AuthProvider>
        <Status />
      </AuthProvider>,
    )
    await waitFor(() => expect(getByTestId('status').textContent).toBe('authenticated'))

    // 3. The address bar holds the original destination, not "/" …
    expect(here()).toBe(DEEP_LINK)
    expect(window.location.search).not.toContain('sso_code')
    expect(window.location.search).not.toContain('cb_state')
    expect(sessionStorage.getItem(RETURN_ROUTE_KEY)).toBeNull()
  })

  it('… and the router, created on /auth/callback, follows the browser there', async () => {
    go(DEEP_LINK)
    function Where() {
      const { pathname, search, hash } = useLocation()
      return <p data-testid="where">{`${pathname}${search}${hash}`}</p>
    }
    const router = createMemoryRouter(
      [
        { path: '/auth/callback', element: <CallbackLanding /> },
        { path: '*', element: <Where /> },
      ],
      { initialEntries: ['/auth/callback'] },
    )
    const { findByTestId } = render(<RouterProvider router={router} />)
    expect((await findByTestId('where')).textContent).toBe(DEEP_LINK)
  })

  it('keeps the destination through the explicit login form too', () => {
    go('/admin/audit?actor=7&from=2026-09-01')
    redirectToPortalLoginForm()
    expect(replaced[0]).toContain('prompt=login')

    go(portalAnswer('sso_code=t'))
    clearCallbackFromUrl()
    expect(here()).toBe('/admin/audit?actor=7&from=2026-09-01')
  })

  it('puts the destination back after a portal error so "Sign in" remembers it again', async () => {
    go(DEEP_LINK)
    redirectToPortalSso()

    go(portalAnswer('auth_error=access_denied'))
    const { getByTestId } = render(
      <AuthProvider>
        <Status />
      </AuthProvider>,
    )
    await waitFor(() => expect(getByTestId('status').textContent).toContain('signed-out'))
    expect(here()).toBe(DEEP_LINK)

    redirectToPortalLoginForm()
    go(portalAnswer('sso_code=t'))
    clearCallbackFromUrl()
    expect(here()).toBe(DEEP_LINK)
  })

  it('never replays a destination from an abandoned attempt', () => {
    go(DEEP_LINK)
    redirectToPortalSso()
    // Abandoned at the portal; later the same tab starts from the app root.
    go('/')
    redirectToPortalSso()
    go(portalAnswer('sso_code=t'))
    clearCallbackFromUrl()
    expect(here()).toBe('/')
  })

  it('forgets the destination on sign-out', () => {
    go(DEEP_LINK)
    redirectToPortalSso()
    performLogout()
    expect(sessionStorage.getItem(RETURN_ROUTE_KEY)).toBeNull()
    sessionStorage.removeItem('aic_logout')
  })

  it('lands on "/" when nothing was remembered (unchanged behaviour)', () => {
    go('/auth/callback?sso_code=t')
    clearCallbackFromUrl()
    expect(here()).toBe('/')
  })
})

describe('normaliseReturnRoute', () => {
  it('keeps an in-app address byte for byte', () => {
    expect(normaliseReturnRoute(DEEP_LINK)).toBe(DEEP_LINK)
    expect(normaliseReturnRoute('/desktop-signin?code=ABCD-1234')).toBe('/desktop-signin?code=ABCD-1234')
  })

  it('refuses anything that is not a path on this origin', () => {
    expect(normaliseReturnRoute('//evil.example/x')).toBeNull()
    expect(normaliseReturnRoute('/\\evil.example/x')).toBeNull()
    expect(normaliseReturnRoute('https://evil.example/x')).toBeNull()
    expect(normaliseReturnRoute('sessions/5b2c')).toBeNull()
    expect(normaliseReturnRoute('')).toBeNull()
    expect(normaliseReturnRoute(null)).toBeNull()
  })

  it('never restores a sign-in path or a sign-in answer', () => {
    expect(normaliseReturnRoute('/auth/callback?auth_token=x')).toBeNull()
    expect(normaliseReturnRoute('/auth/anything')).toBeNull()
    expect(normaliseReturnRoute('/')).toBeNull()
    expect(normaliseReturnRoute('/sessions?auth_token=x&status=open')).toBe('/sessions?status=open')
    expect(normaliseReturnRoute('/sessions?sso_code=abc&cb_state=n')).toBe('/sessions')
  })
})
