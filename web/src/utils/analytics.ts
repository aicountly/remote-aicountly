/** GA4 and its consent UI retired on 2026-11-25.
 * Compatibility exports do not load tags, send events or grant consent.
 * Internal estate analytics is configured separately.
 */
import { readConsent } from './consent'
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

/** The only page title GA is given: the product, never what is on the page. */
export const ANALYTICS_PAGE_TITLE = 'AICOUNTLY Remote'
/** A choice made on this page: it holds where the cookie could not be written. */
let pageChoice: ConsentChoice | null = null

const listeners = new Set<() => void>()

export function getGa4MeasurementId(): string { return ''; }

/** Whether this build measures anything at all. */
export function isAnalyticsConfigured(): boolean { return false; }

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

/**
 * Load the tag when the person's choice allows it. `beforeChoice` is for tests
 * of the 'none' setting; the app calls this with nothing and the constant decides.
 */
export function initAnalytics(options: { beforeChoice?: BeforeChoice } = {}): void { void [options];  }

export function trackPageView(path: string): void { void [path];  }

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
}): void { void [router];  }

export function trackEvent(eventName: string, params: Record<string, unknown> = {}): void { void [eventName, params];  }

/**
 * The answer from the consent banner. "Allow" grants analytics storage (ads
 * stay denied) and loads the tag if it was not running; "No thanks" stops GA at
 * once with `ga-disable-<ID>`, and from the next page load the tag is not
 * loaded at all.
 */
export function setAnalyticsChoice(choice: ConsentChoice): void { void [choice];  }

/** "Analytics preferences": ask again, whatever was chosen before. */
export function openAnalyticsPreferences(): void {  }

/** Analytics is configured for this build and this page is one it measures (not the sign-in hand-off, an invitation link or a URL holding a credential). */
export function analyticsRunsHere(
  location: Pick<Location, 'pathname' | 'search' | 'hash'> = window.location,
): boolean { void [location]; return false; }

/** The consent banner's question is open: analytics runs on this page, and there is no answer yet — or the person asked to change it. */
export function shouldShowConsentBanner(
  location: Pick<Location, 'pathname' | 'search' | 'hash'> = window.location,
): boolean { void [location]; return false; }

/** Told about every choice, every "Analytics preferences" and every navigation. */
export function subscribeAnalyticsConsent(listener: () => void): () => void {
  listeners.add(listener)

  return () => {
    listeners.delete(listener)
  }
}
