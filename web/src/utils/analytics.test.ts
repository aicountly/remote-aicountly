/**
 * Analytics is a third party. The portal's sign-in hand-off
 * (/auth/callback?auth_token=…) carries a reusable credential, /join/<token> is
 * a one-time invitation and /desktop-signin?code=… approves a desktop agent:
 * nothing GA is handed — page_location, page_path, page_referrer — may carry
 * any of them, and gtag.js (which reads the address bar itself) is not loaded
 * while the address bar holds one.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const GA_ID = 'G-TEST000000'

/** The app's own source, as text: what the call-site check at the bottom reads. */
const SOURCES = import.meta.glob<string>(['../**/*.{ts,tsx}', '!../**/*.test.{ts,tsx}'], {
  query: '?raw',
  import: 'default',
  eager: true,
})

async function loadAnalytics() {
  vi.resetModules()
  vi.stubEnv('VITE_GA4_SAAS_REMOTE_MEASUREMENT_ID', GA_ID)
  return import('./analytics')
}

/** Every gtag call so far, as plain arrays. */
function calls(): unknown[][] {
  return (window.dataLayer ?? []).map((args) => Array.from(args as ArrayLike<unknown>))
}

function pageViews(): Record<string, unknown>[] {
  return calls()
    .filter(([command, name]) => command === 'event' && name === 'page_view')
    .map(([, , params]) => params as Record<string, unknown>)
}

type RouteLocation = { pathname: string; search: string }

/** A data router as far as page views care: a starting location and a subscription. */
function fakeRouter(pathname: string, search = '') {
  let listener: (state: { location: RouteLocation }) => void = () => {}
  return {
    state: { location: { pathname, search } },
    subscribe: (next: (state: { location: RouteLocation }) => void) => {
      listener = next
      return () => {}
    },
    navigate: (to: RouteLocation) => listener({ location: to }),
  }
}

function setReferrer(value: string) {
  Object.defineProperty(document, 'referrer', { value, configurable: true })
}

beforeEach(() => {
  Reflect.deleteProperty(window, 'dataLayer')
  Reflect.deleteProperty(window, 'gtag')
  window.history.replaceState(null, '', '/')
  vi.spyOn(document.head, 'appendChild').mockImplementation(<T extends Node>(node: T) => node)
})

afterEach(() => {
  Reflect.deleteProperty(document, 'referrer')
  vi.unstubAllEnvs()
  vi.restoreAllMocks()
})

describe('analytics', () => {
  it('sends the landing page and each new path, never a query or an invitation token', async () => {
    const { trackRouterPageViews } = await loadAnalytics()
    const router = fakeRouter('/sessions')

    trackRouterPageViews(router)
    router.navigate({ pathname: '/join/abc', search: '?x=1' })
    router.navigate({ pathname: '/join/abc', search: '?x=2' })
    router.navigate({ pathname: '/auth/callback', search: '?auth_token=DUMMYTOKEN' })

    expect(pageViews().map((view) => view.page_location)).toEqual([
      `${window.location.origin}/sessions`,
      `${window.location.origin}/join/:token`,
    ])
    expect(JSON.stringify(calls())).not.toMatch(/abc|x=1|x=2|DUMMYTOKEN/)
  })

  it('sends nothing, and loads nothing, for the sign-in hand-off', async () => {
    window.history.replaceState(null, '', '/auth/callback?auth_token=DUMMYTOKEN&state=DUMMYSTATE')
    const { initAnalytics, trackRouterPageViews } = await loadAnalytics()

    initAnalytics()
    trackRouterPageViews(fakeRouter('/auth/callback', '?auth_token=DUMMYTOKEN&state=DUMMYSTATE'))

    expect(document.head.appendChild).not.toHaveBeenCalled()
    expect(window.gtag).toBeUndefined()
    expect(JSON.stringify(calls())).not.toMatch(/DUMMYTOKEN|DUMMYSTATE|auth_token/)
  })

  it('is not loaded on an invitation link or a desktop sign-in code, and starts on the next clean page', async () => {
    window.history.replaceState(null, '', '/join/DUMMYINVITE')
    const { initAnalytics, trackPageView, urlCarriesCredential } = await loadAnalytics()

    initAnalytics()
    trackPageView('/join/DUMMYINVITE')
    expect(window.gtag).toBeUndefined()
    expect(urlCarriesCredential({ pathname: '/desktop-signin', search: '?code=ABCD-EFGH', hash: '' })).toBe(true)

    window.history.replaceState(null, '', '/room/0f8fad5b-d9cb-469f-a165-70867728950e')
    trackPageView(window.location.pathname)

    expect(document.head.appendChild).toHaveBeenCalledTimes(1)
    expect(pageViews()).toEqual([
      expect.objectContaining({ page_path: '/room/:id', page_location: `${window.location.origin}/room/:id` }),
    ])
    expect(JSON.stringify(calls())).not.toMatch(/DUMMYINVITE|0f8fad5b/)
  })

  it('sends a replayed /desktop-signin?code=… deep link as its path only', async () => {
    const { trackRouterPageViews } = await loadAnalytics()
    const router = fakeRouter('/')

    trackRouterPageViews(router)
    router.navigate({ pathname: '/desktop-signin', search: '?code=ABCD-EFGH' })

    expect(pageViews().at(-1)).toMatchObject({ page_path: '/desktop-signin' })
    const context = calls().filter(([command]) => command === 'config').at(-1)?.[2]
    expect(context).toMatchObject({ send_page_view: false, page_location: `${window.location.origin}/desktop-signin` })
    expect(JSON.stringify(calls())).not.toContain('ABCD')
  })

  it('reduces the referrer to an origin or a sanitised path', async () => {
    setReferrer('https://my.aicountly.com/login?returnUrl=x&auth_token=DUMMYTOKEN')
    const { sanitizeReferrer, trackPageView } = await loadAnalytics()

    trackPageView('/')
    trackPageView('/sessions')

    const [first, second] = pageViews()
    expect(first.page_referrer).toBe('https://my.aicountly.com/')
    expect(second.page_referrer).toBe(`${window.location.origin}/`)
    expect(sanitizeReferrer(`${window.location.origin}/join/DUMMYINVITE?x=1`)).toBe(
      `${window.location.origin}/join/:token`,
    )
    expect(JSON.stringify(calls())).not.toContain('DUMMYTOKEN')
  })

  it('keeps location fields a caller passes to trackEvent out of GA', async () => {
    const { trackEvent, trackPageView } = await loadAnalytics()
    trackPageView('/')

    trackEvent('session_started', { mode: 'view', page_location: 'https://x.test/?auth_token=DUMMYTOKEN' })

    expect(calls().at(-1)).toEqual(['event', 'session_started', { mode: 'view' }])
  })

  it('is never handed a query string, hash or full URL by a call site', () => {
    const offenders = Object.entries(SOURCES).flatMap(([file, source]) =>
      [...source.matchAll(/trackPageView\(([^)]*)\)/g)]
        .filter(([, args]) => /\.(search|href|hash)\b/.test(args))
        .map(([call]) => `${file}: ${call}`),
    )

    expect(Object.keys(SOURCES)).toContain('../main.tsx')
    expect(offenders).toEqual([])
  })
})
