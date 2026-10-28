/**
 * The one analytics choice shared by every AICOUNTLY app: the cookie
 * `aic_consent`, `analytics-granted` or `analytics-denied`.
 *
 * On aicountly.com and every *.aicountly.com host it is set for the parent
 * domain, so a choice made in one AICOUNTLY app applies in all of them; on any
 * other host (localhost, aicountly.io) it is host-only. It lasts 180 days, after
 * which the person is asked again. A missing or unreadable cookie means no
 * choice yet. What the choice does to Google Analytics is in analytics.ts.
 */

export const CONSENT_COOKIE = 'aic_consent'

export type ConsentChoice = 'analytics-granted' | 'analytics-denied'

/** 180 days. */
export const CONSENT_MAX_AGE_SECONDS = 15552000

const CHOICES: readonly string[] = ['analytics-granted', 'analytics-denied']

function documentCookies(): string {
  try {
    return typeof document === 'undefined' ? '' : document.cookie
  } catch {
    // Cookies blocked for this page.
    return ''
  }
}

/** The stored choice, or null when there is none: no cookie, or one that does not hold a choice. */
export function readConsent(cookies: string = documentCookies()): ConsentChoice | null {
  for (const part of cookies.split(';')) {
    const eq = part.indexOf('=')
    if (eq < 0 || part.slice(0, eq).trim() !== CONSENT_COOKIE) continue

    const value = part.slice(eq + 1).trim()
    if (CHOICES.includes(value)) return value as ConsentChoice
  }

  return null
}

/** `.aicountly.com` on aicountly.com and its subdomains, so every AICOUNTLY app reads one choice; null (host-only) elsewhere. */
export function consentCookieDomain(hostname: string): string | null {
  const host = String(hostname || '').toLowerCase().replace(/\.$/, '')

  return host === 'aicountly.com' || host.endsWith('.aicountly.com') ? '.aicountly.com' : null
}

/** The cookie that records a choice made on this page. */
export function consentCookie(
  choice: ConsentChoice,
  location: Pick<Location, 'hostname' | 'protocol'> = window.location,
): string {
  const domain = consentCookieDomain(location.hostname)

  return [
    `${CONSENT_COOKIE}=${choice}`,
    ...(domain ? [`Domain=${domain}`] : []),
    'Path=/',
    `Max-Age=${CONSENT_MAX_AGE_SECONDS}`,
    'SameSite=Lax',
    ...(location.protocol === 'https:' ? ['Secure'] : []),
  ].join('; ')
}

export function writeConsent(choice: ConsentChoice): void {
  try {
    document.cookie = consentCookie(choice)
  } catch {
    // Cookies blocked: the choice holds for this page only (analytics.ts keeps it), and is asked again next time.
  }
}
