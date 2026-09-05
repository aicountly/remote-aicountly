import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'

import DeviceRow from './DeviceRow'
import type { RemoteDevice } from '../../types/remote'

/**
 * Unattended access, as the management screen offers it (§53, §54).
 *
 * The rules asserted here are the ones that make unattended access a decision
 * rather than a default:
 *
 *   * a company prohibition beats a device that has it switched on — the row
 *     offers nothing, and says why rather than hiding the option;
 *   * Connect appears only for a machine that is reachable *and* enabled *and*
 *     not revoked, so the button is never one that would fail;
 *   * a revoked device keeps its row, because a list with rows quietly missing
 *     is worse than one that shows what was taken away.
 */

function device(overrides: Partial<RemoteDevice> = {}): RemoteDevice {
  return {
    uuid: 'device-1',
    deviceName: 'WS-01',
    deviceType: 'LAPTOP',
    companyId: 481,
    operatingSystem: 'Windows',
    osVersion: '11 24H2',
    architecture: 'x86_64',
    hostname: 'ws-01',
    agentVersion: '1.0.0',
    status: 'ACTIVE',
    online: true,
    presenceState: 'ONLINE',
    capabilities: {} as RemoteDevice['capabilities'],
    keyAlgorithm: 'ED25519',
    keyFingerprint: 'A1B2 C3D4 E5F6 0718',
    ownerName: 'Priya',
    enrolledByName: 'Priya',
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

function row(props: Partial<Parameters<typeof DeviceRow>[0]> = {}) {
  const handlers = {
    onConnect: vi.fn(),
    onEnableUnattended: vi.fn(),
    onDisableUnattended: vi.fn(),
    onRevoke: vi.fn(),
  }

  render(
    <DeviceRow
      device={device()}
      canManage
      canConnect
      unattendedAllowed
      busy={false}
      {...handlers}
      {...props}
    />,
  )

  return handlers
}

describe('DeviceRow', () => {
  it('says unattended access is off, and offers to turn it on', () => {
    row()

    expect(screen.getByText(/unattended access is off\./i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /allow unattended/i })).toBeInTheDocument()
    // Nothing to connect to yet: the machine still asks each time.
    expect(screen.queryByRole('button', { name: /^connect$/i })).not.toBeInTheDocument()
  })

  it('does not offer unattended access when the organisation forbids it', () => {
    // A company prohibition defeats every lower grant. Explained rather than
    // hidden, so an administrator does not conclude Remote cannot do it.
    row({ unattendedAllowed: false })

    expect(screen.queryByRole('button', { name: /allow unattended/i })).not.toBeInTheDocument()
    expect(screen.getByText(/off for this organisation/i)).toBeInTheDocument()
  })

  it('offers Connect only for a machine that will actually answer', () => {
    row({ device: device({ unattendedAccessEnabled: true, unattendedEnabledAt: '2026-01-04T09:00:00Z' }) })

    expect(screen.getByRole('button', { name: /^connect$/i })).toBeInTheDocument()
    expect(screen.getByText(/unattended access is on\./i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /turn off unattended/i })).toBeInTheDocument()
  })

  it('offers no Connect for an offline machine, however it is configured', () => {
    row({ device: device({ unattendedAccessEnabled: true, online: false }) })

    expect(screen.queryByRole('button', { name: /^connect$/i })).not.toBeInTheDocument()
    expect(screen.getByText('Offline')).toBeInTheDocument()
  })

  it('offers no Connect without the permission, whatever the device allows', () => {
    row({ canConnect: false, device: device({ unattendedAccessEnabled: true }) })

    expect(screen.queryByRole('button', { name: /^connect$/i })).not.toBeInTheDocument()
  })

  it('keeps a removed machine listed, with nothing left to do to it', () => {
    row({ device: device({ status: 'REVOKED', online: false, revokedAt: '2026-02-01T10:00:00Z' }) })

    expect(screen.getByText('WS-01')).toBeInTheDocument()
    expect(screen.getByText('Removed')).toBeInTheDocument()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })

  it('shows the key fingerprint, so a person can check the row is that machine', () => {
    row()

    expect(screen.getByText('A1B2 C3D4 E5F6 0718')).toBeInTheDocument()
  })
})
