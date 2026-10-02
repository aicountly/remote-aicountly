/**
 * Google Analytics 4 (gtag) for remote.aicountly.com.
 *
 * Set at build time: VITE_GA4_SAAS_REMOTE_MEASUREMENT_ID=G-…
 * Flow backend: GA4_PROPERTY_ID_SAAS_REMOTE (numeric property ID).
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

export function getGa4MeasurementId(): string {
  return GA4_ID
}

export function initAnalytics(): void {
  if (initialized || !GA4_ID || typeof window === 'undefined') return
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
  // The address bar may hold a sign-in credential at this moment: GA4 is told the
  // route only, never `location.href`, whatever it measures automatically (I-17).
  window.gtag('config', GA4_ID, {
    send_page_view: false,
    page_location: window.location.origin + analyticsSafePath(window.location.pathname),
  })
}

/**
 * Route shape for analytics. Remote carries two secrets in the path itself: the
 * invitation link (`/join/<secret>`, which is a credential to enter a room) and
 * the session ids a host shares. Neither is measured; the route's pattern is.
 */
export function routePattern(path: string): string {
  return path
    .replace(/^\/join\/[^/?#]+/, '/join/:token')
    .replace(/^\/room\/[^/?#]+/, '/room/:uuid')
    .replace(/^\/sessions\/(?!history(?:[/?#]|$))[^/?#]+/, '/sessions/:uuid')
}

export function trackPageView(path: string, title?: string): void {
  if (!GA4_ID || typeof window.gtag !== 'function') return
  // GA4 requires a `page_view` *event*, not a repeated `config` call: once
  // `send_page_view: false` is set (above), gtag.js suppresses page_view on
  // every subsequent config call for this measurement ID too, so re-calling
  // config here silently sends nothing. See Google's SPA tracking guide.
  // Never a sign-in token, one-time code or nonce (I-17, G06-01): the callback
  // route is reported without its query, and no secret on any other route.
  const safe = routePattern(analyticsSafePath(path))
  window.gtag('event', 'page_view', {
    page_location: window.location.origin + safe,
    page_path: safe,
    ...(title ? { page_title: title } : {}),
  })
}

export function trackEvent(eventName: string, params: Record<string, unknown> = {}): void {
  if (!GA4_ID || typeof window.gtag !== 'function') return
  window.gtag('event', eventName, params)
}
