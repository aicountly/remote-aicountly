import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'

import type { AuditEntry } from '../../types/remote'

const entry: AuditEntry = {
  uuid: 'a1',
  event: 'POLICY_UPDATED',
  actorType: 'USER',
  actorName: 'Asha Rao',
  actorUuid: 'u-1',
  companyId: 481,
  severity: 'security',
  summary: 'Changed Remote policy (1 setting)',
  entityType: 'company_policy',
  entityId: '481',
  entityLabel: 'Custom',
  requestId: 'req-1',
  sessionUuid: null,
  sourceProduct: null,
  ip: '10.0.0.1',
  userAgent: null,
  metadata: { changes: [{ field: 'allow_entire_monitor', label: 'Allow entire monitor', old: false, new: true }] },
  createdAt: '2026-10-16T05:00:00Z',
}

const api = vi.hoisted(() => ({
  fetchAuditTrail: vi.fn(),
  exportAuditTrail: vi.fn(),
}))

vi.mock('../../services/api/remote', () => api)
vi.mock('../../app/RemoteProvider', () => ({
  useRemote: () => ({
    companyId: 481,
    scopeType: 'COMPANY',
    can: () => true,
    policy: { companyName: 'ABC' },
  }),
}))

import AuditPage from './AuditPage'

describe('Audit trail page', () => {
  it('lists entries and shows a change as old -> new in the details', async () => {
    api.fetchAuditTrail.mockResolvedValue({ data: [entry], meta: { total: 1 } })

    render(
      <MemoryRouter>
        <AuditPage />
      </MemoryRouter>,
    )

    expect(await screen.findByText('Changed Remote policy (1 setting)')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Details' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('Allow entire monitor')).toBeInTheDocument()
    expect(within(dialog).getByText('no')).toBeInTheDocument()
    expect(within(dialog).getByText('yes')).toBeInTheDocument()
  })

  it('sends the chosen filters to the export as well as the list', async () => {
    api.fetchAuditTrail.mockResolvedValue({ data: [entry], meta: { total: 1 } })
    api.exportAuditTrail.mockResolvedValue({ text: 'a,b', partial: true })
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    render(
      <MemoryRouter>
        <AuditPage />
      </MemoryRouter>,
    )
    await screen.findByText('Changed Remote policy (1 setting)')

    await userEvent.selectOptions(screen.getByLabelText('Importance'), 'security')
    await userEvent.click(screen.getByRole('button', { name: /Export CSV/ }))

    await waitFor(() => expect(api.exportAuditTrail).toHaveBeenCalled())
    expect(api.exportAuditTrail.mock.calls[0][1]).toMatchObject({ severity: 'security' })
    expect(await screen.findByText(/cut at its row limit/)).toBeInTheDocument()
  })
})
