import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'

import DeviceHistory from './DeviceHistory'
import { describeCount } from './DevicesPage'
import type { DeviceHistory as History, RemoteDevice } from '../../types/remote'

const history: History = {
  sessions: [
    {
      uuid: 's-2',
      displayId: 'AR-10002',
      accessMode: 'UNATTENDED',
      role: 'HOST',
      status: 'ENDED',
      startedAt: '2026-10-31T09:00:00Z',
      endedAt: '2026-10-31T09:12:00Z',
      durationSeconds: 720,
      peers: ['Priya'],
      controlled: true,
    },
    {
      uuid: 's-1',
      displayId: 'AR-10001',
      accessMode: 'ATTENDED',
      role: 'COMPANION',
      status: 'EXPIRED',
      startedAt: '2026-10-30T09:00:00Z',
      endedAt: null,
      durationSeconds: null,
      peers: [],
      controlled: false,
    },
  ],
  events: [{ event: 'UNATTENDED_ACCESS_ENABLED', at: '2026-10-30T08:00:00Z', summary: 'Unattended access turned on', actorName: 'Priya' }],
}

describe('DeviceHistory', () => {
  it('lists who was on the computer, how, and what changed', async () => {
    render(<DeviceHistory deviceUuid="d-1" load={vi.fn().mockResolvedValue(history)} />)

    expect(await screen.findByText('Priya', { selector: 'strong' })).toBeInTheDocument()
    expect(screen.getByText(/Unattended, controlled/)).toBeInTheDocument()
    expect(screen.getByText('Nobody joined')).toBeInTheDocument()
    expect(screen.getByText(/Attended, control companion, viewed only/)).toBeInTheDocument()
    expect(screen.getByText('Unattended access turned on')).toBeInTheDocument()
  })

  it('says so when there is nothing yet', async () => {
    render(<DeviceHistory deviceUuid="d-1" load={vi.fn().mockResolvedValue({ sessions: [], events: [] })} />)

    expect(await screen.findByText('No sessions yet.')).toBeInTheDocument()
    expect(screen.getByText('Nothing recorded yet.')).toBeInTheDocument()
  })
})

describe('describeCount', () => {
  const device = (overrides: Partial<RemoteDevice>) => ({ status: 'ACTIVE', online: false, ...overrides }) as RemoteDevice

  it('counts the computers still registered, and how many are online', () => {
    expect(describeCount([])).toBe('None registered yet.')
    expect(describeCount([device({ online: true })])).toBe('1 computer registered · 1 online')
    expect(describeCount([device({ online: true }), device({}), device({ status: 'REVOKED' })])).toBe(
      '2 computers registered · 1 online',
    )
  })
})
