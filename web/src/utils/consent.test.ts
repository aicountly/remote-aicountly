/**
 * The one analytics choice every AICOUNTLY app shares: `aic_consent`, set for
 * .aicountly.com on any AICOUNTLY host so a choice made in one app holds in all
 * of them, host-only anywhere else, for 180 days.
 */

import { afterEach, describe, expect, it } from 'vitest'

import { CONSENT_MAX_AGE_SECONDS, consentCookie, consentCookieDomain, readConsent, writeConsent } from './consent'

declare const jsdom: { reconfigure: (options: { url: string }) => void }

afterEach(() => {
  document.cookie = 'aic_consent=; Max-Age=0; Path=/'
})

describe('the aic_consent cookie', () => {
  it('is shared by aicountly.com and every subdomain, and Secure over https', () => {
    expect(consentCookie('analytics-granted', { hostname: 'remote.aicountly.com', protocol: 'https:' })).toBe(
      'aic_consent=analytics-granted; Domain=.aicountly.com; Path=/; Max-Age=15552000; SameSite=Lax; Secure',
    )
    expect(consentCookie('analytics-denied', { hostname: 'aicountly.com', protocol: 'https:' })).toBe(
      'aic_consent=analytics-denied; Domain=.aicountly.com; Path=/; Max-Age=15552000; SameSite=Lax; Secure',
    )
    expect(consentCookieDomain('remote.gh.aicountly.com')).toBe('.aicountly.com')
    expect(consentCookieDomain('REMOTE.AICOUNTLY.COM.')).toBe('.aicountly.com')
  })

  it('is host-only on any other host, and not Secure over plain http', () => {
    expect(consentCookie('analytics-granted', { hostname: 'aicountly.io', protocol: 'https:' })).toBe(
      'aic_consent=analytics-granted; Path=/; Max-Age=15552000; SameSite=Lax; Secure',
    )
    expect(consentCookie('analytics-granted', { hostname: 'localhost', protocol: 'http:' })).toBe(
      'aic_consent=analytics-granted; Path=/; Max-Age=15552000; SameSite=Lax',
    )
    for (const host of ['notaicountly.com', 'aicountly.com.example.org', 'aicountly.co', 'aicountly.io', 'localhost']) {
      expect(consentCookieDomain(host)).toBeNull()
    }
  })

  it('lasts 180 days, after which the person is asked again', () => {
    expect(CONSENT_MAX_AGE_SECONDS).toBe(180 * 24 * 60 * 60)
  })

  it('reads a choice, and anything else as no choice yet', () => {
    expect(readConsent('')).toBeNull()
    expect(readConsent('a=1; aic_consent=analytics-granted; b=2')).toBe('analytics-granted')
    expect(readConsent('aic_consent=analytics-denied')).toBe('analytics-denied')
    // Another product's older format, an empty value, a lookalike name, a longer value.
    expect(readConsent('aic_consent=v1.1.0')).toBeNull()
    expect(readConsent('aic_consent=')).toBeNull()
    expect(readConsent('x_aic_consent=analytics-granted')).toBeNull()
    expect(readConsent('aic_consent=analytics-granted-forever')).toBeNull()
  })

  it('is written where this page reads it back', () => {
    writeConsent('analytics-denied')
    expect(readConsent()).toBe('analytics-denied')

    writeConsent('analytics-granted')
    expect(readConsent()).toBe('analytics-granted')
  })

  it('is accepted on a real AICOUNTLY host with its domain and Secure attributes', () => {
    const original = window.location.href
    jsdom.reconfigure({ url: 'https://remote.aicountly.com/sessions' })
    try {
      expect(consentCookie('analytics-granted')).toContain('Domain=.aicountly.com')
      writeConsent('analytics-granted')
      expect(readConsent()).toBe('analytics-granted')
      document.cookie = 'aic_consent=; Domain=.aicountly.com; Max-Age=0; Path=/; Secure'
    } finally {
      jsdom.reconfigure({ url: original })
    }
  })
})
