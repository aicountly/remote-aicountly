/**
 * Estate analytics for the Remote SPA — AICOUNTLY's own view of how the estate is used, for
 * product, marketing and sales (analytics.aicountly.org).
 *
 * Not the Manage usage metering the backend reports to. This is first-party service telemetry
 * about a signed-in session (analytics-aicountly docs/05_dpdp_and_privacy.md).
 *
 * EVERY SUBJECT IS A CUSTOMER, and the backend says so, not this file. Remote is still used as a
 * support tool, but estate analytics draws no staff/customer line any more: every end user, and
 * every user, counts as a customer. The backend records user_kind as the constant 'customer' and
 * no longer looks at the identity's support-agent or platform-admin flags at all. Nothing here
 * needs to know; nothing here could be trusted to say.
 *
 * NO COMPANY TRAVELS FROM HERE, because there is no company to record. Organisations have been
 * wiped from Remote, so estate analytics is always user-wise — the backend attaches no company_id
 * to these events from any source, verified launch context included. So there is no context hook
 * here, and a beacon that invented one would be stripped on the server before it was recorded.
 *
 * Two product-specific details:
 *
 *  1. Page views come from the ROUTER's subscription, not a React effect, mirroring
 *     trackRouterPageViews() in utils/analytics.ts. Remote's /room/:uuid and /join/:token sit
 *     outside AppShell, so an effect in a layout would miss them; both pipelines take the same
 *     path from the same source instead.
 *  2. peekSesKey(), not getSesKey(): the latter drops an expired key as a side effect, and
 *     analytics flushes on a timer and during pagehide, where it must not change session state.
 */
import { analytics } from './aicountly-analytics.js'
import { peekSesKey } from '../auth/tokens'
import { getApiBaseUrl } from '../config'

let started = false

export function initEstateAnalytics(): void {
  if (started) return
  started = true
  try {
    analytics.init({
      app: 'remote',
      endpoint: `${getApiBaseUrl()}/v1/remote/analytics/collect`,
      auth: () => {
        const key = peekSesKey()

        return key ? { Authorization: `Bearer ${key}` } : null
      },
    })
  } catch {
    // Analytics must never stop the app booting.
  }
}

export function recordEstatePage(path: string): void {
  try {
    analytics.page(path)
  } catch {
    // Same.
  }
}

/**
 * Report every navigation the router makes, the landing page included — the same contract as
 * trackRouterPageViews(), so the two pipelines cannot drift apart. Paths only: a query can carry
 * a desktop sign-in code.
 */
export function recordEstateRouterPageViews(router: {
  state: { location: { pathname: string } }
  subscribe: (listener: (state: { location: { pathname: string } }) => void) => unknown
}): void {
  let lastPath: string | null = null
  const record = ({ pathname }: { pathname: string }) => {
    if (pathname === lastPath) return
    lastPath = pathname
    recordEstatePage(pathname)
  }
  router.subscribe((state) => record(state.location))
  record(router.state.location)
}
