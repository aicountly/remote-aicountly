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
  Reflect.deleteProperty(window, `ga-disable-${GA_ID}`)
  document.cookie = 'aic_consent=; Max-Age=0; Path=/'
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

  it('keeps location fields, a page title and a user id a caller passes to trackEvent out of GA', async () => {
    const { trackEvent, trackPageView } = await loadAnalytics()
    trackPageView('/')

    trackEvent('session_started', {
      mode: 'view',
      page_location: 'https://x.test/?auth_token=DUMMYTOKEN',
      page_title: 'Asha Rao',
      user_id: 'DUMMYUSER',
    })

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

describe('analytics route patterns (I-17)', () => {
  it('never reports an invitation secret, a room id or a session id', async () => {
    const { routePattern } = await loadAnalytics()
    expect(routePattern('/join/Zk3pQ9secretinvitationtoken')).toBe('/join/:token')
    expect(routePattern('/room/6f1b6b0c-0000-4000-8000-000000000001')).toBe('/room/:uuid')
    expect(routePattern('/sessions/6f1b6b0c-0000-4000-8000-000000000001')).toBe('/sessions/:uuid')
  })

  it('keeps routes that carry no secret as they are', async () => {
    const { routePattern } = await loadAnalytics()
    expect(routePattern('/')).toBe('/')
    expect(routePattern('/sessions')).toBe('/sessions')
    expect(routePattern('/sessions/history')).toBe('/sessions/history')
    expect(routePattern('/admin/policy')).toBe('/admin/policy')
    expect(routePattern('/join')).toBe('/join')
  })

  it('sends a session or room id that is not a UUID as a placeholder', async () => {
    const { trackPageView } = await loadAnalytics()

    trackPageView('/sessions/DUMMYSESSION42')
    trackPageView('/room/DUMMYROOM42')

    expect(pageViews().map((view) => view.page_path)).toEqual(['/sessions/:id', '/room/:id'])
    expect(JSON.stringify(calls())).not.toMatch(/DUMMYSESSION|DUMMYROOM/)
  })
})

/** Consent Mode v2 and the shared `aic_consent` choice. */
describe('analytics consent', () => {
  const DEFAULT_BEFORE_A_CHOICE = {
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    analytics_storage: 'denied',
    functionality_storage: 'denied',
    personalization_storage: 'denied',
    security_storage: 'granted',
    wait_for_update: 500,
  }

  function gaDisabled(): unknown {
    return (window as unknown as Record<string, unknown>)[`ga-disable-${GA_ID}`]
  }

  it('sets the consent default before any config, then redaction, and runs cookieless before a choice', async () => {
    let queuedWhenLoaded = -1
    vi.mocked(document.head.appendChild).mockImplementation(<T extends Node>(node: T) => {
      queuedWhenLoaded = window.dataLayer.length
      return node
    })
    const { trackPageView, analyticsChoice } = await loadAnalytics()

    trackPageView('/')

    const commands = calls()
    expect(analyticsChoice()).toBeNull()
    expect(commands[0]).toEqual(['consent', 'default', DEFAULT_BEFORE_A_CHOICE])
    expect(commands[1]).toEqual(['set', 'ads_data_redaction', true])
    expect(commands[2]).toEqual(['set', 'url_passthrough', false])
    expect(commands.findIndex(([command]) => command === 'config')).toBeGreaterThan(2)
    // The tag is loaded (cookieless measurement), after the defaults are queued, and nothing is granted.
    expect(document.head.appendChild).toHaveBeenCalledTimes(1)
    expect(queuedWhenLoaded).toBeGreaterThan(0)
    expect(commands.some(([command, action]) => command === 'consent' && action === 'update')).toBe(false)
    expect(pageViews()).toHaveLength(1)
  })

  it("queues each command as an arguments object, the way Google's snippet does", async () => {
    const { trackPageView } = await loadAnalytics()

    trackPageView('/')

    expect(window.dataLayer.length).toBeGreaterThan(0)
    for (const entry of window.dataLayer) {
      expect(Object.prototype.toString.call(entry)).toBe('[object Arguments]')
    }
  })

  it('configures GA with no Google signals, no ad personalisation, a SameSite=Lax Secure cookie and the product as the title', async () => {
    const { trackPageView } = await loadAnalytics()

    trackPageView('/')
    trackPageView('/sessions')

    const configs = calls().filter(([command]) => command === 'config')
    expect(configs.length).toBeGreaterThan(1)
    for (const [, id, params] of configs) {
      expect(id).toBe(GA_ID)
      expect(params).toMatchObject({
        send_page_view: false,
        allow_google_signals: false,
        allow_ad_personalization_signals: false,
        cookie_flags: 'SameSite=Lax;Secure',
        page_title: 'AICOUNTLY Remote',
      })
    }
    expect(pageViews().map((view) => view.page_title)).toEqual(['AICOUNTLY Remote', 'AICOUNTLY Remote'])
  })

  it('grants analytics storage when the person allows it, and records the choice for every AICOUNTLY app', async () => {
    const { trackPageView, setAnalyticsChoice, analyticsChoice } = await loadAnalytics()
    trackPageView('/')

    setAnalyticsChoice('analytics-granted')

    expect(calls().at(-1)).toEqual(['consent', 'update', { analytics_storage: 'granted' }])
    expect(document.cookie).toContain('aic_consent=analytics-granted')
    expect(analyticsChoice()).toBe('analytics-granted')
  })

  it('starts granted on the next page load after "Allow"', async () => {
    document.cookie = 'aic_consent=analytics-granted; Path=/'
    const { trackPageView } = await loadAnalytics()

    trackPageView('/')

    expect(calls()[0]).toEqual(['consent', 'default', { ...DEFAULT_BEFORE_A_CHOICE, analytics_storage: 'granted' }])
    expect(calls()).toContainEqual(['consent', 'update', { analytics_storage: 'granted' }])
  })

  it('never grants ads consent, whatever the person chooses', async () => {
    document.cookie = 'aic_consent=analytics-granted; Path=/'
    const { trackPageView, setAnalyticsChoice } = await loadAnalytics()
    trackPageView('/')
    setAnalyticsChoice('analytics-denied')
    setAnalyticsChoice('analytics-granted')

    const consent = calls().filter(([command]) => command === 'consent')
    expect(consent.length).toBeGreaterThan(2)
    for (const [, , params] of consent) {
      for (const ads of ['ad_storage', 'ad_user_data', 'ad_personalization']) {
        expect((params as Record<string, unknown>)[ads] ?? 'denied').toBe('denied')
      }
    }
  })

  it('loads nothing at all after "No thanks", and tells GA to send nothing', async () => {
    document.cookie = 'aic_consent=analytics-denied; Path=/'
    const { initAnalytics, trackEvent, trackRouterPageViews } = await loadAnalytics()

    initAnalytics()
    const router = fakeRouter('/sessions')
    trackRouterPageViews(router)
    router.navigate({ pathname: '/start', search: '' })
    trackEvent('session_started', { mode: 'view' })

    expect(document.head.appendChild).not.toHaveBeenCalled()
    expect(window.gtag).toBeUndefined()
    expect(window.dataLayer).toBeUndefined()
    expect(gaDisabled()).toBe(true)
  })

  it('stops at once when the person changes their mind to "No thanks"', async () => {
    const { trackPageView, setAnalyticsChoice } = await loadAnalytics()
    trackPageView('/')

    setAnalyticsChoice('analytics-denied')

    expect(gaDisabled()).toBe(true)
    expect(calls().at(-1)).toEqual(['consent', 'update', { analytics_storage: 'denied' }])
    expect(document.cookie).toContain('aic_consent=analytics-denied')

    const queued = calls().length
    trackPageView('/sessions')
    expect(calls()).toHaveLength(queued)

    // And back again: GA's off switch does not outlive a change of mind.
    setAnalyticsChoice('analytics-granted')
    expect(gaDisabled()).toBeUndefined()
  })

  it("with ANALYTICS_BEFORE_CHOICE = 'none' loads nothing before a choice, and starts once allowed", async () => {
    const analytics = await loadAnalytics()
    expect(analytics.ANALYTICS_BEFORE_CHOICE).toBe('cookieless')

    analytics.initAnalytics({ beforeChoice: 'none' })
    analytics.trackPageView('/sessions')

    expect(document.head.appendChild).not.toHaveBeenCalled()
    expect(window.gtag).toBeUndefined()

    analytics.setAnalyticsChoice('analytics-granted')

    expect(document.head.appendChild).toHaveBeenCalledTimes(1)
    expect(calls()[0]).toEqual(['consent', 'default', { ...DEFAULT_BEFORE_A_CHOICE, analytics_storage: 'granted' }])
    expect(pageViews()).toEqual([expect.objectContaining({ page_location: `${window.location.origin}/` })])
  })

  it('treats a cookie it cannot read as no choice yet', async () => {
    document.cookie = 'aic_consent=v1.1.0; Path=/'
    const { trackPageView, analyticsChoice } = await loadAnalytics()

    trackPageView('/')

    expect(analyticsChoice()).toBeNull()
    expect(calls()[0]).toEqual(['consent', 'default', DEFAULT_BEFORE_A_CHOICE])
  })

  it('does nothing at all in a build with no measurement id', async () => {
    vi.resetModules()
    vi.stubEnv('VITE_GA4_SAAS_REMOTE_MEASUREMENT_ID', '')
    vi.stubEnv('VITE_GA4_MEASUREMENT_ID', '')
    const analytics = await import('./analytics')

    analytics.initAnalytics()
    analytics.trackPageView('/')
    analytics.setAnalyticsChoice('analytics-granted')

    expect(analytics.isAnalyticsConfigured()).toBe(false)
    expect(analytics.shouldShowConsentBanner()).toBe(false)
    expect(document.head.appendChild).not.toHaveBeenCalled()
    expect(window.gtag).toBeUndefined()
  })
})

/** What GA is told about a page: origin and path, no query or fragment, no identifier. */
describe('analytics masking', () => {
  it('replaces every segment that looks like an identifier, and drops the query and fragment', async () => {
    const { sanitizePagePath } = await loadAnalytics()

    expect(sanitizePagePath('/sessions/12345')).toBe('/sessions/:id')
    expect(sanitizePagePath('/x/0f8fad5b-d9cb-469f-a165-70867728950e/y')).toBe('/x/:id/y')
    expect(sanitizePagePath('/x/deadbeefdeadbeef0123')).toBe('/x/:id')
    expect(sanitizePagePath('/x/eyJhbGciOiJIUzI1NiJ9xyz')).toBe('/x/:id')
    expect(sanitizePagePath('/people/asha.rao@example.com')).toBe('/people/:id')
    expect(sanitizePagePath('/people/asha.rao%40example.com')).toBe('/people/:id')
    expect(sanitizePagePath('/sessions?q=asha@example.com#row-42')).toBe('/sessions')
    expect(sanitizePagePath('/sessions#/people/asha@example.com')).toBe('/sessions')
  })

  it("keeps the app's own route names", async () => {
    const { sanitizePagePath } = await loadAnalytics()

    for (const route of ['/', '/start', '/join', '/sessions/history', '/admin/permissions', '/settings/users-access', '/desktop-signin', '/join/:token']) {
      expect(sanitizePagePath(route)).toBe(route)
    }
  })

  it('sends page_location as origin plus the masked path, and masks the referrer the same way', async () => {
    setReferrer(`${window.location.origin}/people/asha.rao@example.com?tab=2#notes`)
    const { trackPageView } = await loadAnalytics()

    trackPageView('/people/asha.rao@example.com/12345?tab=2#notes')

    expect(pageViews().at(-1)).toMatchObject({
      page_location: `${window.location.origin}/people/:id/:id`,
      page_path: '/people/:id/:id',
      page_referrer: `${window.location.origin}/people/:id`,
      page_title: 'AICOUNTLY Remote',
    })
    expect(JSON.stringify(calls())).not.toMatch(/asha|12345|tab=2|notes/)
  })
})
