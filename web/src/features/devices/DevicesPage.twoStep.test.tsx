import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'

import type { RemoteDevice } from '../../types/remote'

/**
 * Unattended access needs Remote's own two-step verification, and the
 * Computers page says so before anybody presses "Allow unattended" — and,
 * when the server refuses for want of it, links straight to the setting.
 */

vi.mock('../../app/RemoteProvider', () => ({
  useRemote: () => ({ scopeType: 'PERSONAL', companyId: null, can: () => true }),
  useDesktopAgentEnabled: () => true,
}))

const api = vi.hoisted(() => ({
  fetchDevices: vi.fn(),
  fetchTwoStepStatus: vi.fn(),
  connectToDevice: vi.fn(),
  disableUnattendedAccess: vi.fn(),
  enableUnattendedAccess: vi.fn(),
  revokeDevice: vi.fn(),
}))
vi.mock('../../services/api/remote', () => api)

const { default: DevicesPage } = await import('./DevicesPage')
const { RemoteApiError } = await import('../../services/api/client')

function device(overrides: Partial<RemoteDevice> = {}): RemoteDevice {
  return {
    uuid: 'device-1',
    deviceName: 'Meera home PC',
    deviceType: 'DESKTOP',
    companyId: null,
    operatingSystem: 'Windows',
    osVersion: '11 24H2',
    architecture: 'x86_64',
    hostname: 'meera-pc',
    agentVersion: '1.0.0',
    status: 'ACTIVE',
    online: true,
    presenceState: 'ONLINE',
    capabilities: {} as RemoteDevice['capabilities'],
    keyAlgorithm: 'ED25519',
    keyFingerprint: 'A1B2 C3D4 E5F6 0718',
    ownerName: 'Meera',
    enrolledByName: 'Meera',
    unattendedAccessEnabled: false,
    unattendedEnabledAt: null,
    unattendedLastUsedAt: null,
    lastSeenAt: new Date().toISOString(),
    lastAuthenticatedAt: null,
    revokedAt: null,
    createdAt: null,
    ...overrides,
  }
}

function listing() {
  return {
    devices: [device()],
    canEnrol: true,
    canManage: true,
    canConnectUnattended: true,
    policy: {
      allowRemoteControl: true,
      allowUnattendedAccess: true,
      allowClipboardSync: true,
      allowDeviceReboot: false,
      restrictions: [],
    },
  }
}

function renderPage() {
  return render(
    <MemoryRouter>
      <DevicesPage />
    </MemoryRouter>,
  )
}

// A block body: an arrow returning the reset mock would hand Vitest the mock as a teardown hook.
beforeEach(() => {
  Object.values(api).forEach((mock) => mock.mockReset())
  api.fetchDevices.mockResolvedValue(listing())
})

describe('the Computers page and two-step verification', () => {
  it('explains, before anyone presses Allow unattended, that it needs two-step verification', async () => {
    api.fetchTwoStepStatus.mockResolvedValue({ enabled: false, available: true })

    renderPage()

    expect(await screen.findByText('Unattended access needs two-step verification')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Turn on two-step verification/ })).toHaveAttribute('href', '/settings#two-step')
    expect(screen.getByRole('button', { name: 'Allow unattended' })).toBeInTheDocument()
  })

  it('says nothing about it to somebody who already has it on', async () => {
    api.fetchTwoStepStatus.mockResolvedValue({ enabled: true, available: true })

    renderPage()

    expect(await screen.findByRole('button', { name: 'Allow unattended' })).toBeInTheDocument()
    expect(screen.queryByText('Unattended access needs two-step verification')).not.toBeInTheDocument()
  })

  it('tells the person, in the confirmation, that a code will be asked for', async () => {
    api.fetchTwoStepStatus.mockResolvedValue({ enabled: true, available: true })
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: 'Allow unattended' }))

    expect(screen.getByText(/It needs two-step verification: you will be asked for a code/)).toBeInTheDocument()
  })

  it('links to the setting when the server refuses with TWO_STEP_SETUP_REQUIRED', async () => {
    api.fetchTwoStepStatus.mockRejectedValue(new RemoteApiError('NETWORK_ERROR', 'offline', 0))
    api.enableUnattendedAccess.mockRejectedValue(
      new RemoteApiError(
        'TWO_STEP_SETUP_REQUIRED',
        'Unattended access needs two-step verification. Turn it on in Settings, under Two-step verification, then turn unattended access on again.',
        403,
        { setupPath: '/settings#two-step' },
      ),
    )
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: 'Allow unattended' }))
    await userEvent.click(screen.getByRole('button', { name: 'Turn on unattended access' }))

    expect(await screen.findByText('Turn on two-step verification first')).toBeInTheDocument()
    expect(screen.getByText(/Turn it on in Settings, under Two-step verification/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Turn on two-step verification/ })).toHaveAttribute('href', '/settings#two-step')
  })
})
