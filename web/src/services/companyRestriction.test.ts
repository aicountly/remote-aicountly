import { beforeEach, describe, expect, it } from 'vitest'
import {
  __resetCompanyRestrictionsForTests,
  getCompanyRestriction,
  noteCompanyLifecycle,
  noteRestrictedResponse,
  restrictionFromResponse,
  restrictionMessage,
} from './companyRestriction'

/** What the API sends (lib/company-lifecycle ReadOnlyGuard::payload). */
const refused = {
  code: 'COMPANY_RESTRICTED',
  message: 'This company is read-only until 28 Nov 2026 because its subscription ended. Renew to make changes, or export your data.',
  restricted_until: '2026-11-28T00:00:00Z',
  lifecycle: 'restricted',
  error: { code: 'COMPANY_RESTRICTED', message: 'm', restricted_until: '2026-11-28T00:00:00Z' },
}

describe('company lifecycle (Manage): the read-only state', () => {
  beforeEach(() => __resetCompanyRestrictionsForTests())

  it('reads the 423 the API sends, with the server sentence and date', () => {
    const r = restrictionFromResponse(423, refused)
    expect(r?.message).toBe(refused.message)
    expect(r?.restrictedUntil).toBe('2026-11-28T00:00:00Z')
  })

  it('ignores anything that is not a 423 COMPANY_RESTRICTED', () => {
    expect(restrictionFromResponse(403, refused)).toBeNull()
    expect(restrictionFromResponse(423, { error: { code: 'locked' } })).toBeNull()
    expect(noteRestrictedResponse(7, 409, { error: { code: 'conflict' } })).toBe(false)
    expect(getCompanyRestriction(7)).toBeNull()
  })

  it('records a refused write for the company it was made in, and announces it', () => {
    const seen: unknown[] = []
    const listener = (e: Event) => seen.push((e as CustomEvent).detail)
    window.addEventListener('remote:company-restricted', listener)
    expect(noteRestrictedResponse(7, 423, refused)).toBe(true)
    window.removeEventListener('remote:company-restricted', listener)
    expect(getCompanyRestriction(7)?.restrictedUntil).toBe('2026-11-28T00:00:00Z')
    expect(getCompanyRestriction(8)).toBeNull()
    expect(seen).toEqual([{ cmpId: 7, restricted: true, source: 'api', message: refused.message }])
  })

  it('reads companyinfo: restricted and purge_pending show, active clears, absent changes nothing', () => {
    noteCompanyLifecycle(7, { success: '1', data: { comp_id: 7, lifecycle: 'purge_pending', restricted_until: '2026-11-28T00:00:00Z' } })
    expect(getCompanyRestriction(7)?.lifecycle).toBe('purge_pending')
    noteCompanyLifecycle(7, { data: { comp_id: 7 } })
    expect(getCompanyRestriction(7)?.lifecycle).toBe('purge_pending')
    noteCompanyLifecycle(7, { data: { comp_id: 7, lifecycle: 'active' } })
    expect(getCompanyRestriction(7)).toBeNull()
  })

  it('rebuilds the sentence when only the date is known', () => {
    expect(restrictionMessage('2026-11-28T00:00:00Z')).toMatch(/read-only until 28 Nov 2026/)
    expect(restrictionMessage(null, 'purge_pending')).toMatch(/Export what you need now/)
  })
})
