import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'

import { describePermission } from '../../utils/format'

/**
 * The Computers page says how to get the AICOUNTLY Remote app while the
 * deployment offers it (bootstrap `features.desktopAgent`, the API's
 * remote.allowDesktopAgent), and that it is coming soon while it is switched
 * off — never an instruction to install an app nobody can download, and never
 * a pointer at a policy switch the Remote policy page does not have.
 */

const remote = vi.hoisted(() => ({
  scopeType: 'COMPANY' as 'COMPANY' | 'PERSONAL',
  companyId: 481 as number | null,
  desktopAgent: false,
}))

vi.mock('../../app/RemoteProvider', () => ({
  useRemote: () => ({ scopeType: remote.scopeType, companyId: remote.companyId, can: () => false }),
  useDesktopAgentEnabled: () => remote.desktopAgent,
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
  fetchTwoStepStatus: vi.fn(async () => ({ enabled: false, available: true })),
  disableUnattendedAccess: vi.fn(),
  enableUnattendedAccess: vi.fn(),
  revokeDevice: vi.fn(),
}))

const { default: DevicesPage } = await import('./DevicesPage')
const { fetchDevices } = await import('../../services/api/remote')

function renderPage({ scopeType = 'COMPANY', desktopAgent = false }: { scopeType?: 'COMPANY' | 'PERSONAL'; desktopAgent?: boolean } = {}) {
  remote.scopeType = scopeType
  remote.companyId = scopeType === 'COMPANY' ? 481 : null
  remote.desktopAgent = desktopAgent
  listing.devices = []

  return render(
    <MemoryRouter>
      <DevicesPage />
    </MemoryRouter>,
  )
}

const DOWNLOAD = /^Download the AICOUNTLY Remote app/

describe('the Computers page with the desktop agent switched off', () => {
  it('says the app is coming soon, and what works today, when nothing is registered', async () => {
    renderPage()

    expect(await screen.findByText('The AICOUNTLY Remote app is coming soon')).toBeInTheDocument()
    expect(screen.getByText(/Remote works in your browser: share your screen/)).toBeInTheDocument()
    expect(screen.queryByText(/^Install AICOUNTLY Remote/)).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: DOWNLOAD })).not.toBeInTheDocument()
    // No switch for remote control exists on the Remote policy page; nothing sends an administrator there for one.
    expect(screen.queryByText(/change that in Remote policy/)).not.toBeInTheDocument()
  })

  it('says so in a personal scope too', async () => {
    renderPage({ scopeType: 'PERSONAL' })

    expect(await screen.findByText('The AICOUNTLY Remote app is coming soon')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: DOWNLOAD })).not.toBeInTheDocument()
  })
})

describe('the Computers page with the desktop agent on', () => {
  it('says how to get the desktop app, in a new tab, when nothing is registered', async () => {
    renderPage({ desktopAgent: true })

    const link = await screen.findByRole('link', { name: DOWNLOAD })
    expect(link).toHaveAttribute('href', 'https://aicountly.com/remote')
    expect(link).toHaveAttribute('target', '_blank')
    expect(link).toHaveAttribute('rel', expect.stringContaining('noopener'))
    expect(screen.getByText('Get the AICOUNTLY Remote app')).toBeInTheDocument()
    expect(screen.queryByText(/coming soon/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/not available to download/i)).not.toBeInTheDocument()
  })

  it('says so in a personal scope too', async () => {
    renderPage({ scopeType: 'PERSONAL', desktopAgent: true })

    expect(await screen.findByRole('link', { name: DOWNLOAD })).toHaveAttribute('href', 'https://aicountly.com/remote')
    expect(screen.queryByText(/coming soon/i)).not.toBeInTheDocument()
  })
})

describe('the Computers page in a personal scope', () => {
  it("lists the person's own computers without asking for an organisation", async () => {
    vi.mocked(fetchDevices).mockClear()
    renderPage({ scopeType: 'PERSONAL', desktopAgent: true })

    expect(await screen.findByText('Registered computers')).toBeInTheDocument()
    expect(fetchDevices).toHaveBeenCalledWith(null)
    expect(screen.getByText(/Your computers running the AICOUNTLY Remote app, for Windows or Mac/)).toBeInTheDocument()
    expect(screen.queryByText(/belong to an organisation/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/Switch to an organisation/i)).not.toBeInTheDocument()
  })
})

/**
 * A list that could not be read is not an empty list. The page used to say "No computers
 * registered yet" — and, under it, how to download the app — to somebody whose computers
 * simply had not loaded.
 */
describe('the Computers page when the list cannot be read', () => {
  it('says so, offers to try again, and does not claim there are no computers', async () => {
    vi.mocked(fetchDevices).mockClear()
    vi.mocked(fetchDevices).mockRejectedValueOnce(new Error('offline'))
    renderPage({ scopeType: 'PERSONAL', desktopAgent: true })

    expect(await screen.findByText('Your computers could not be loaded.')).toBeInTheDocument()
    expect(screen.getByText('Not loaded.')).toBeInTheDocument()
    expect(screen.queryByText('No computers registered yet')).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: DOWNLOAD })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Try again' }))

    // The second read answers: the ordinary empty state, and the way to get the app.
    expect(await screen.findByText('No computers registered yet')).toBeInTheDocument()
    expect(screen.queryByText('Your computers could not be loaded.')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument()
    expect(fetchDevices).toHaveBeenCalledTimes(2)
  })
})

describe('permission names', () => {
  const DESKTOP = [
    'remote.control.request',
    'remote.control.accept',
    'remote.device.enrol',
    'remote.device.manage',
    'remote.unattended.access',
  ]

  it('names the desktop permissions, and says the desktop app is coming soon while it is switched off', () => {
    for (const permission of DESKTOP) {
      expect(describePermission(permission)).not.toBe(permission)
      expect(describePermission(permission)).toMatch(/desktop app, coming soon/)
      expect(describePermission(permission, false)).toBe(describePermission(permission))
    }
  })

  it('says they need the desktop app, and nothing about coming soon, while it is offered', () => {
    for (const permission of DESKTOP) {
      expect(describePermission(permission, true)).not.toBe(permission)
      expect(describePermission(permission, true)).toMatch(/\(needs the desktop app\)$/)
      expect(describePermission(permission, true)).not.toMatch(/coming soon/i)
    }
    // Nothing else changes with the switch.
    expect(describePermission('remote.screen.share', true)).toBe(describePermission('remote.screen.share'))
  })
})
