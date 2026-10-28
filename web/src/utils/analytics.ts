/**
 * Google Analytics 4 (gtag) for remote.aicountly.com.
 *
 * Set at build time: VITE_GA4_SAAS_REMOTE_MEASUREMENT_ID=G-…
 * Flow backend: GA4_PROPERTY_ID_SAAS_REMOTE (numeric property ID).
 * Without a measurement id (every build but production) nothing here runs.
 *
 * Consent is the one choice every AICOUNTLY app shares, the `aic_consent`
 * cookie (consent.ts), read before anything else here runs. Google Consent
 * Mode v2 defaults are set before any config call, and ads consent is never
 * granted by anything:
 *
 *   * no choice yet — the tag runs cookieless, analytics storage denied, so GA
 *     sets no cookie (ANALYTICS_BEFORE_CHOICE; 'none' loads nothing instead);
 *   * "No thanks" (`analytics-denied`) — the tag is not loaded at all, and
 *     `ga-disable-<ID>` is set;
 *   * "Allow" (`analytics-granted`) — analytics storage is granted.
 *
 * Analytics is a third party: it receives masked route paths only — never
 * query strings, record ids, an e-mail address or anything from the sign-in
 * hand-off — and is not loaded while the address bar still holds /auth/*,
 * ?auth_token= or another credential (gtag.js reads the URL itself). Its page
 * title is the product name, never a person's, company's or document's.
 */

import { analyticsSafePath } from '../auth/callbackState'
import { readConsent, writeConsent } from './consent'
import type { ConsentChoice } from './consent'

declare global {
  interface Window {
    dataLayer: unknown[]
    gtag: (...args: unknown[]) => void
  }
}

export type BeforeChoice = 'cookieless' | 'none'

/**
 * What runs before the person has answered: 'cookieless' loads the tag with
 * analytics storage denied (cookieless measurement — GA sets no cookies);
 * 'none' loads nothing at all until they allow it. The one place to change it.
 */
export const ANALYTICS_BEFORE_CHOICE: BeforeChoice = 'cookieless'

const GA4_ID: string =
  import.meta.env.VITE_GA4_SAAS_REMOTE_MEASUREMENT_ID ||
  import.meta.env.VITE_GA4_MEASUREMENT_ID ||
  ''

/** The only page title GA is given: the product, never what is on the page. */
export const ANALYTICS_PAGE_TITLE = 'AICOUNTLY Remote'

let initialized = false
/** The last page_location sent: the next page view's referrer. */
let lastPageLocation = ''
let beforeChoice: BeforeChoice = ANALYTICS_BEFORE_CHOICE
/** A choice made on this page: it holds where the cookie could not be written. */
let pageChoice: ConsentChoice | null = null
/** The person asked to change their choice ("Analytics preferences"). */
let preferencesOpen = false

const listeners = new Set<() => void>()

function emit(): void {
  for (const listener of listeners) listener()
}

export function getGa4MeasurementId(): string {
  return GA4_ID
}

/** Whether this build measures anything at all. */
export function isAnalyticsConfigured(): boolean {
  return GA4_ID !== ''
}

/** The person's choice, or null before they have made one. */
export function analyticsChoice(): ConsentChoice | null {
  return pageChoice ?? readConsent()
}

/** Sign-in hand-off routes carry tokens in the URL. */
export function isUntrackedRoute(pathname = ''): boolean {
  return /^\/auth(\/|$)/.test(String(pathname || ''))
}

/** /join/<token> is a one-time invitation: the path itself is the credential. */
const INVITATION_ROUTE = /^\/join\/[^/]+/
/** A query or hash parameter that is a credential: auth_token, a ses_key, a one-time ticket or code. */
const CREDENTIAL_PARAM = /[?&#](?:[\w.-]*token|ses_?key|ticket|code|otp|password|secret|signature|sig)=/i

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
/** A hex or base64(url) token: 16 characters or more, nothing but its alphabet. */
const TOKEN = /^[A-Za-z0-9+\/=_-]{16,}$/

/** A path segment that names a record or a person: all digits, a UUID, a long token, anything with an @. */
export function looksLikeIdentifier(segment: string): boolean {
  let value = segment
  try {
    value = decodeURIComponent(segment)
  } catch {
    // Not valid percent-encoding: judged as it is.
  }

  return /^\d+$/.test(value) || UUID.test(value) || TOKEN.test(value) || value.includes('@')
}

/** Route path without query/hash, with the invitation token and every identifier-like segment replaced by a placeholder. */
export function sanitizePagePath(pathname = '/'): string {
  const path = String(pathname || '/').split(/[?#]/)[0] || '/'

  return path
    .replace(INVITATION_ROUTE, '/join/:token')
    .split('/')
    .map((segment) => (segment !== '' && looksLikeIdentifier(segment) ? ':id' : segment))
    .join('/')
}

/**
 * Route shape for analytics. Remote carries two secrets in the path itself: the
 * invitation link (`/join/<secret>`, which is a credential to enter a room) and
 * the session ids a host shares. Neither is measured; the route's pattern is.
 * Covers ids that are not UUIDs, which sanitizePagePath() does not recognise (I-17).
 */
export function routePattern(path: string, idPlaceholder = ':uuid'): string {
  return path
    .replace(/^\/join\/[^/?#]+/, '/join/:token')
    .replace(/^\/room\/[^/?#]+/, `/room/${idPlaceholder}`)
    .replace(/^\/sessions\/(?!history(?:[/?#]|$))[^/?#]+/, `/sessions/${idPlaceholder}`)
}

/**
 * What GA is told about a route: no sign-in token, one-time code or nonce, no
 * query, and no invitation, room or session id (I-17, G06-01, G28#8).
 */
function reportedPath(path: string): string {
  return sanitizePagePath(routePattern(analyticsSafePath(path), ':id'))
}

/** A referrer reduced to another site's origin, or to this site's sanitised path. */
export function sanitizeReferrer(referrer = ''): string {
  if (!referrer) return ''
  try {
    const url = new URL(referrer)
    if (url.origin !== window.location.origin) return `${url.protocol}//${url.host}/`
    return `${url.origin}${sanitizePagePath(routePattern(url.pathname, ':id'))}`
  } catch {
    return String(referrer).split(/[?#]/)[0]
  }
}

/** True while the address bar still holds the sign-in hand-off or another credential. */
export function urlCarriesCredential(
  location: Pick<Location, 'pathname' | 'search' | 'hash'> = window.location,
): boolean {
  const hashRoute = String(location.hash || '').replace(/^#/, '').split(/[?#]/)[0]
  return (
    isUntrackedRoute(location.pathname) ||
    INVITATION_ROUTE.test(location.pathname) ||
    isUntrackedRoute(hashRoute) ||
    CREDENTIAL_PARAM.test(`${location.search || ''}${location.hash || ''}`)
  )
}

/** The page fields gtag.js would otherwise take from window.location, document.referrer and document.title. */
function pageContext(pagePath: string): { page_location: string; page_referrer: string; page_title: string } {
  return {
    // Never window.location.href: it would carry the query string.
    page_location: `${window.location.origin}${pagePath}`,
    page_referrer: lastPageLocation || sanitizeReferrer(document.referrer),
    page_title: ANALYTICS_PAGE_TITLE,
  }
}

/** Every config call carries these: no automatic page view, no Google signals, no ad personalisation. */
function configFor(pagePath: string): Record<string, unknown> {
  return {
    send_page_view: false,
    allow_google_signals: false,
    allow_ad_personalization_signals: false,
    cookie_flags: 'SameSite=Lax;Secure',
    ...pageContext(pagePath),
  }
}

/** GA's own off switch: nothing more is sent, whoever loaded the tag. */
function disableGoogleAnalytics(disabled: boolean): void {
  const flags = window as unknown as Record<string, unknown>
  if (disabled) flags[`ga-disable-${GA4_ID}`] = true
  else delete flags[`ga-disable-${GA4_ID}`]
}

/**
 * Load the tag when the person's choice allows it. `beforeChoice` is for tests
 * of the 'none' setting; the app calls this with nothing and the constant decides.
 */
export function initAnalytics(options: { beforeChoice?: BeforeChoice } = {}): void {
  if (options.beforeChoice) beforeChoice = options.beforeChoice
  if (initialized || !GA4_ID || typeof window === 'undefined') return

  const choice = analyticsChoice()
  // "No thanks": the tag is never loaded.
  if (choice === 'analytics-denied') {
    disableGoogleAnalytics(true)
    return
  }
  if (choice === null && beforeChoice === 'none') return
  // Not yet: the app has not cleared the hand-off. trackPageView() calls back
  // here on the next route, once it has.
  if (urlCarriesCredential()) return
  initialized = true

  window.dataLayer = window.dataLayer || []
  window.gtag = function gtag() {
    // The arguments object itself, as Google's snippet pushes it: gtag.js takes
    // its commands from Arguments objects, and rest parameters make an array.
    window.dataLayer.push(arguments)
  }
  // Consent Mode v2, before any config call. Ads consent is never granted here
  // or anywhere else.
  window.gtag('consent', 'default', {
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    analytics_storage: choice === 'analytics-granted' ? 'granted' : 'denied',
    functionality_storage: 'denied',
    personalization_storage: 'denied',
    security_storage: 'granted',
    wait_for_update: 500,
  })
  window.gtag('set', 'ads_data_redaction', true)
  window.gtag('set', 'url_passthrough', false)
  if (choice === 'analytics-granted') window.gtag('consent', 'update', { analytics_storage: 'granted' })
  window.gtag('js', new Date())
  window.gtag('config', GA4_ID, configFor(reportedPath(window.location.pathname)))

  const script = document.createElement('script')
  script.async = true
  script.src = `https://www.googletagmanager.com/gtag/js?id=${GA4_ID}`
  document.head.appendChild(script)
}

export function trackPageView(path: string): void {
  if (!GA4_ID || typeof window === 'undefined') return
  const pagePath = reportedPath(path)
  if (isUntrackedRoute(pagePath)) return
  initAnalytics()
  if (!initialized || analyticsChoice() === 'analytics-denied') return
  const context = pageContext(pagePath)
  // GA4 requires a `page_view` *event*, not a repeated `config` call: once
  // `send_page_view: false` is set (above), gtag.js suppresses page_view on
  // every subsequent config call for this measurement ID too, so re-calling
  // config here silently sends nothing. See Google's SPA tracking guide.
  // The config call below therefore sends nothing either: it only moves the
  // page fields that automatic events (scroll, engagement) report, so none of
  // them falls back to the raw URL.
  window.gtag('config', GA4_ID, configFor(pagePath))
  window.gtag('event', 'page_view', { ...context, page_path: pagePath })
  lastPageLocation = context.page_location
}

interface RouterLocation {
  pathname: string
}

/**
 * Page views for a data router. Its subscription does not fire for the
 * location it starts on, so the landing page is sent from here too. Only the
 * pathname is read, and a query-only change is not a new page. Every change
 * also reaches the consent banner, which is never shown on a page analytics
 * skips.
 */
export function trackRouterPageViews(router: {
  state: { location: RouterLocation }
  subscribe: (listener: (state: { location: RouterLocation }) => void) => unknown
}): void {
  let lastPath: string | null = null
  const track = ({ pathname }: RouterLocation) => {
    if (pathname === lastPath) return
    lastPath = pathname
    trackPageView(pathname)
  }
  router.subscribe((state) => {
    track(state.location)
    emit()
  })
  track(router.state.location)
}

/** Fields that come from the sanitised page context only, never from a caller — and an identity is never sent. */
const RESERVED_PARAMS = ['page_location', 'page_referrer', 'page_path', 'page_title', 'user_id']

export function trackEvent(eventName: string, params: Record<string, unknown> = {}): void {
  if (!initialized || analyticsChoice() === 'analytics-denied') return
  const safeParams = Object.fromEntries(
    Object.entries(params).filter(([key]) => !RESERVED_PARAMS.includes(key)),
  )
  window.gtag('event', eventName, safeParams)
}

/**
 * The answer from the consent banner. "Allow" grants analytics storage (ads
 * stay denied) and loads the tag if it was not running; "No thanks" stops GA at
 * once with `ga-disable-<ID>`, and from the next page load the tag is not
 * loaded at all.
 */
export function setAnalyticsChoice(choice: ConsentChoice): void {
  pageChoice = choice
  preferencesOpen = false
  writeConsent(choice)

  if (GA4_ID && typeof window !== 'undefined') {
    if (choice === 'analytics-denied') {
      disableGoogleAnalytics(true)
      if (initialized) window.gtag('consent', 'update', { analytics_storage: 'denied' })
    } else {
      disableGoogleAnalytics(false)
      if (initialized) {
        window.gtag('consent', 'update', { analytics_storage: 'granted' })
      } else {
        // Not loaded before ('none', or declined earlier): this page counts from now.
        trackPageView(window.location.pathname)
      }
    }
  }

  emit()
}

/** "Analytics preferences": ask again, whatever was chosen before. */
export function openAnalyticsPreferences(): void {
  preferencesOpen = true
  emit()
}

/** Analytics is configured for this build and this page is one it measures (not the sign-in hand-off, an invitation link or a URL holding a credential). */
export function analyticsRunsHere(
  location: Pick<Location, 'pathname' | 'search' | 'hash'> = window.location,
): boolean {
  return GA4_ID !== '' && !urlCarriesCredential(location)
}

/** The consent banner's question is open: analytics runs on this page, and there is no answer yet — or the person asked to change it. */
export function shouldShowConsentBanner(
  location: Pick<Location, 'pathname' | 'search' | 'hash'> = window.location,
): boolean {
  return analyticsRunsHere(location) && (preferencesOpen || analyticsChoice() === null)
}

/** Told about every choice, every "Analytics preferences" and every navigation. */
export function subscribeAnalyticsConsent(listener: () => void): () => void {
  listeners.add(listener)

  return () => {
    listeners.delete(listener)
  }
}
