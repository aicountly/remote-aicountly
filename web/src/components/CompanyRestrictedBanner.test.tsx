import { act, cleanup, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { CompanyRestrictedBanner } from './CompanyRestrictedBanner'
import { __resetCompanyRestrictionsForTests, noteCompanyLifecycle, noteRestrictedResponse } from '../services/companyRestriction'

describe('CompanyRestrictedBanner', () => {
  beforeEach(() => __resetCompanyRestrictionsForTests())
  afterEach(() => cleanup())

  it('shows nothing for an active company, the banner from companyinfo, and a toast on a refused write', () => {
    render(<CompanyRestrictedBanner cmpId={7} />)
    expect(screen.queryByTestId('company-restricted-banner')).toBeNull()

    act(() => noteCompanyLifecycle(7, { data: { comp_id: 7, lifecycle: 'restricted', restricted_until: '2026-11-28T00:00:00Z' } }))
    expect(screen.getByTestId('company-restricted-banner').textContent).toMatch(/Read-only until 28 Nov 2026/)
    expect(screen.queryByTestId('company-restricted-toast')).toBeNull()

    act(() => {
      noteRestrictedResponse(7, 423, { code: 'COMPANY_RESTRICTED', message: 'This company is read-only.', restricted_until: '2026-11-28T00:00:00Z' })
    })
    expect(screen.getByTestId('company-restricted-toast').textContent).toMatch(/This company is read-only\./)
  })

  it('ignores another company', () => {
    render(<CompanyRestrictedBanner cmpId={8} />)
    act(() => noteCompanyLifecycle(7, { data: { lifecycle: 'restricted' } }))
    expect(screen.queryByTestId('company-restricted-banner')).toBeNull()
  })
})
