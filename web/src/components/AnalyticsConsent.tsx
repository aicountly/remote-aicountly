import { useSyncExternalStore } from 'react'

import {
  analyticsRunsHere,
  openAnalyticsPreferences,
  setAnalyticsChoice,
  shouldShowConsentBanner,
  subscribeAnalyticsConsent,
} from '../utils/analytics'

export const PRIVACY_POLICY_URL = 'https://aicountly.com/privacy-policy'

/** The banner's state for the address bar: '' when it is down, otherwise the page's path. */
function bannerPath(): string {
  return shouldShowConsentBanner() ? window.location.pathname : ''
}

/**
 * The analytics question every AICOUNTLY app asks the same way (one
 * `aic_consent` cookie for all of them): a small card at the bottom left, clear
 * of the AICOUNTLY Connect launcher at the bottom right. It never blocks the
 * page and never takes focus, and it is shown only where analytics runs for
 * this build and page, until the person answers or asks again from "Analytics
 * preferences".
 */
export default function AnalyticsConsentBanner() {
  const path = useSyncExternalStore(subscribeAnalyticsConsent, bannerPath, () => '')

  if (!path) return null

  return (
    <div
      className={path.startsWith('/room/') ? 'consent consent--room' : 'consent'}
      role="region"
      aria-label="Analytics cookies"
    >
      <p className="consent__text">
        AICOUNTLY uses privacy-friendly analytics to improve the product — no ads, and nothing that identifies you.
        Allow analytics cookies?
      </p>
      <div className="consent__actions">
        <button type="button" className="btn btn--secondary btn--sm" onClick={() => setAnalyticsChoice('analytics-granted')}>
          Allow
        </button>
        <button type="button" className="btn btn--secondary btn--sm" onClick={() => setAnalyticsChoice('analytics-denied')}>
          No thanks
        </button>
        {/* A new tab: in a session room, leaving the page would leave the session. */}
        <a className="consent__link" href={PRIVACY_POLICY_URL} target="_blank" rel="noopener noreferrer">
          Privacy policy
          <span className="sr-only"> (opens in a new tab)</span>
        </a>
      </div>
    </div>
  )
}

/**
 * "Analytics preferences": asks the question again. Offered only where the
 * banner can appear — never in a build that measures nothing, or on a page
 * analytics skips, where it would be a button that does nothing.
 */
export function AnalyticsPreferencesButton({ className, onOpen }: { className?: string; onOpen?: () => void }) {
  const available = useSyncExternalStore(subscribeAnalyticsConsent, () => analyticsRunsHere(), () => false)

  if (!available) return null

  return (
    <button
      type="button"
      className={className}
      onClick={() => {
        onOpen?.()
        openAnalyticsPreferences()
      }}
    >
      Analytics preferences
    </button>
  )
}
