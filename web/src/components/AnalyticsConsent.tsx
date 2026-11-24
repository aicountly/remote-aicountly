/** GA4 and its consent UI retired on 2026-11-25.
 * Compatibility exports do not load tags, send events or grant consent.
 * Internal estate analytics is configured separately.
 */
export const PRIVACY_POLICY_URL = 'https://aicountly.com/privacy-policy'

/**
 * The analytics question every AICOUNTLY app asks the same way (one
 * `aic_consent` cookie for all of them): a small card at the bottom left, clear
 * of the AICOUNTLY Connect launcher at the bottom right. It never blocks the
 * page and never takes focus, and it is shown only where analytics runs for
 * this build and page, until the person answers or asks again from "Analytics
 * preferences".
 */
export default function AnalyticsConsentBanner() { return null; }

/**
 * "Analytics preferences": asks the question again. Offered only where the
 * banner can appear — never in a build that measures nothing, or on a page
 * analytics skips, where it would be a button that does nothing.
 */
export function AnalyticsPreferencesButton({ className, onOpen }: { className?: string; onOpen?: () => void }) { void [className, onOpen]; return null; }
