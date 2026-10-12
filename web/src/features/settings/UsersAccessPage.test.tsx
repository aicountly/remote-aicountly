import { render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import type { CompanyRegistryClient } from '../../company-access/index.ts'

const remote = vi.hoisted(() => ({
  value: {
    bootstrap: { companies: [{ companyId: 481, name: 'ABC Private Limited' }] },
    scopeType: 'COMPANY' as 'COMPANY' | 'PERSONAL',
    companyId: 481 as number | null,
    refresh: async () => {},
  },
}))
const registry = vi.hoisted(() => ({
  permissions: vi.fn(async (cmpId: number) => ({
    company: { cmp_id: cmpId, name: 'ABC Private Limited', company_status: 'active' },
    is_owner: false,
    can_manage_access: false,
    app: 'remote',
    applications: [{ key: 'remote', name: 'Remote' }],
    members: [],
  })),
  workspaces: vi.fn(async () => []),
}))

vi.mock('../../app/RemoteProvider', () => ({ useRemote: () => remote.value }))
vi.mock('../../services/companyRegistry', () => ({
  REGISTRY_APP_NAME: 'Aicountly Remote',
  getCompanyRegistry: () =>
    new Proxy(registry, {
      get: (target, key) =>
        key in target ? target[key as keyof typeof target] : key === 'appKey' ? 'remote' : async () => [],
    }) as unknown as CompanyRegistryClient,
}))

import UsersAccessPage from './UsersAccessPage'

describe('Users & Access', () => {
  it('asks Manage about the selected organisation, for Remote', async () => {
    render(<UsersAccessPage />)

    expect(screen.getByRole('heading', { name: 'Users & Access', level: 1 })).toBeInTheDocument()
    expect(screen.getByText('Who can open ABC Private Limited in Remote.')).toBeInTheDocument()
    await waitFor(() => expect(registry.permissions).toHaveBeenCalledWith(481))
  })

  it('asks for an organisation in Personal scope instead of guessing one', () => {
    remote.value = { ...remote.value, scopeType: 'PERSONAL', companyId: null }
    registry.permissions.mockClear()
    render(<UsersAccessPage />)

    expect(screen.getByText('Choose an organisation')).toBeInTheDocument()
    expect(registry.permissions).not.toHaveBeenCalled()
  })
})
