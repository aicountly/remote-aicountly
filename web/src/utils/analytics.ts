/**
 * Google Analytics 4 (gtag) for remote.aicountly.com.
 *
 * Set at build time: VITE_GA4_SAAS_REMOTE_MEASUREMENT_ID=G-…
 * Flow backend: GA4_PROPERTY_ID_SAAS_REMOTE (numeric property ID).
 *
 * Analytics is a third party: it receives route paths only — never query
 * strings, record ids or anything from the sign-in hand-off — and is not
 * loaded while the address bar still holds /auth/*, ?auth_token= or another
 * credential (gtag.js reads the URL itself).
 */

import { analyticsSafePath } from '../auth/callbackState'

declare global {
  interface Window {
    dataLayer: unknown[]
    gtag: (...args: unknown[]) => void
  }
}

const GA4_ID: string =
  import.meta.env.VITE_GA4_SAAS_REMOTE_MEASUREMENT_ID ||
  import.meta.env.VITE_GA4_MEASUREMENT_ID ||
  ''

let initialized = false
/** The last page_location sent: the next page view's referrer. */
let lastPageLocation = ''

export function getGa4MeasurementId(): string {
  return GA4_ID
}

/** Sign-in hand-off routes carry tokens in the URL. */
export function isUntrackedRoute(pathname = ''): boolean {
  return /^\/auth(\/|$)/.test(String(pathname || ''))
}

const UUID_SEGMENT = /\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?=\/|$)/gi
/** /join/<token> is a one-time invitation: the path itself is the credential. */
const INVITATION_ROUTE = /^\/join\/[^/]+/
/** A query or hash parameter that is a credential: auth_token, a ses_key, a one-time ticket or code. */
const CREDENTIAL_PARAM = /[?&#](?:[\w.-]*token|ses_?key|ticket|code|otp|password|secret|signature|sig)=/i

/** Route path without query/hash, with the invitation token and record ids replaced by placeholders. */
export function sanitizePagePath(pathname = '/'): string {
  const path = String(pathname || '/').split(/[?#]/)[0] || '/'
  return path.replace(INVITATION_ROUTE, '/join/:token').replace(UUID_SEGMENT, '/:id')
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

/** The page fields gtag.js would otherwise take from window.location and document.referrer. */
function pageContext(pagePath: string): { page_location: string; page_referrer: string } {
  return {
    // Never window.location.href: it would carry the query string.
    page_location: `${window.location.origin}${pagePath}`,
    page_referrer: lastPageLocation || sanitizeReferrer(document.referrer),
  }
}

export function initAnalytics(): void {
  if (initialized || !GA4_ID || typeof window === 'undefined') return
  // Not yet: the app has not cleared the hand-off. trackPageView() calls back
  // here on the next route, once it has.
  if (urlCarriesCredential()) return
  initialized = true

  const script = document.createElement('script')
  script.async = true
  script.src = `https://www.googletagmanager.com/gtag/js?id=${GA4_ID}`
  document.head.appendChild(script)

  window.dataLayer = window.dataLayer || []
  window.gtag = function gtag(...args: unknown[]) {
    window.dataLayer.push(args)
  }
  window.gtag('js', new Date())
  window.gtag('config', GA4_ID, {
    send_page_view: false,
    ...pageContext(reportedPath(window.location.pathname)),
  })
}

export function trackPageView(path: string, title?: string): void {
  if (!GA4_ID || typeof window === 'undefined') return
  const pagePath = reportedPath(path)
  if (isUntrackedRoute(pagePath)) return
  initAnalytics()
  if (!initialized) return
  const context = pageContext(pagePath)
  // GA4 requires a `page_view` *event*, not a repeated `config` call: once
  // `send_page_view: false` is set (above), gtag.js suppresses page_view on
  // every subsequent config call for this measurement ID too, so re-calling
  // config here silently sends nothing. See Google's SPA tracking guide.
  // The config call below therefore sends nothing either: it only moves the
  // page fields that automatic events (scroll, engagement) report, so none of
  // them falls back to the raw URL.
  window.gtag('config', GA4_ID, { send_page_view: false, ...context })
  window.gtag('event', 'page_view', {
    ...context,
    page_path: pagePath,
    ...(title ? { page_title: title } : {}),
  })
  lastPageLocation = context.page_location
}

interface RouterLocation {
  pathname: string
}

/**
 * Page views for a data router. Its subscription does not fire for the
 * location it starts on, so the landing page is sent from here too. Only the
 * pathname is read, and a query-only change is not a new page.
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
  router.subscribe((state) => track(state.location))
  track(router.state.location)
}

/** Location fields come from the sanitised page context only, never from a caller. */
const LOCATION_PARAMS = ['page_location', 'page_referrer', 'page_path']

export function trackEvent(eventName: string, params: Record<string, unknown> = {}): void {
  if (!initialized) return
  const safeParams = Object.fromEntries(
    Object.entries(params).filter(([key]) => !LOCATION_PARAMS.includes(key)),
  )
  window.gtag('event', eventName, safeParams)
}
