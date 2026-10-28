import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'

import { describePermission } from '../../utils/format'

/**
 * Remote launches on the web first. AICOUNTLY Remote for Windows is not
 * released, so the Computers page must not read as an instruction to install
 * it, and must not point at a policy switch the Remote policy page does not
 * have.
 */

const remote = vi.hoisted(() => ({
  scopeType: 'COMPANY' as 'COMPANY' | 'PERSONAL',
  companyId: 481 as number | null,
}))

vi.mock('../../app/RemoteProvider', () => ({
  useRemote: () => ({ scopeType: remote.scopeType, companyId: remote.companyId, can: () => false }),
}))

const listing = vi.hoisted(() => ({ devices: [] as unknown[] }))

vi.mock('../../services/api/remote', () => ({
  fetchDevices: vi.fn(async () => ({
    devices: listing.devices,
    canEnrol: true,
    canManage: true,
    canConnectUnattended: false,
    policy: {
      allowRemoteControl: false,
      allowUnattendedAccess: false,
      allowClipboardSync: false,
      allowDeviceReboot: false,
      restrictions: [],
    },
  })),
  connectToDevice: vi.fn(),
  disableUnattendedAccess: vi.fn(),
  enableUnattendedAccess: vi.fn(),
  revokeDevice: vi.fn(),
}))

const { default: DevicesPage } = await import('./DevicesPage')

function renderPage() {
  return render(
    <MemoryRouter>
      <DevicesPage />
    </MemoryRouter>,
  )
}

describe('the Computers page before the Windows app is released', () => {
  it('says the app is coming soon, and what works today, when nothing is registered', async () => {
    remote.scopeType = 'COMPANY'
    remote.companyId = 481
    listing.devices = []
    renderPage()

    expect(await screen.findByText('AICOUNTLY Remote for Windows is coming soon')).toBeInTheDocument()
    expect(screen.getByText(/Remote works in your browser: share your screen/)).toBeInTheDocument()
    expect(screen.queryByText(/^Install AICOUNTLY Remote/)).not.toBeInTheDocument()
    // No switch for remote control exists on the Remote policy page; nothing sends an administrator there for one.
    expect(screen.queryByText(/change that in Remote policy/)).not.toBeInTheDocument()
  })

  it('says so in a personal scope too', () => {
    remote.scopeType = 'PERSONAL'
    remote.companyId = null
    renderPage()

    expect(screen.getByText('AICOUNTLY Remote for Windows is coming soon')).toBeInTheDocument()
  })
})

describe('permission names', () => {
  it('names the desktop permissions, and says they need the unreleased Windows app', () => {
    for (const permission of [
      'remote.control.request',
      'remote.control.accept',
      'remote.device.enrol',
      'remote.device.manage',
      'remote.unattended.access',
    ]) {
      expect(describePermission(permission)).not.toBe(permission)
      expect(describePermission(permission)).toMatch(/Windows app, coming soon/)
    }
  })
})
