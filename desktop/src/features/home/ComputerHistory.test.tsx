import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const bridge = vi.hoisted(() => ({ computerHistory: vi.fn() }))

vi.mock('../../services/tauri', () => bridge)

const { ComputerHistory, describeSession, formatDuration } = await import('./ComputerHistory')

beforeEach(() => {
  bridge.computerHistory.mockReset()
})

describe('formatDuration', () => {
  it('reads like a person would say it', () => {
    expect(formatDuration(null)).toBeNull()
    expect(formatDuration(20)).toBe('under a minute')
    expect(formatDuration(12 * 60)).toBe('12 min')
    expect(formatDuration(3600)).toBe('1 h')
    expect(formatDuration(3725)).toBe('1 h 2 min')
  })
})

describe('describeSession', () => {
  it('says how, who, whether they controlled, and how long', () => {
    expect(
      describeSession({
        uuid: 's-1',
        displayId: 'AR-1',
        accessMode: 'UNATTENDED',
        role: 'HOST',
        status: 'ENDED',
        startedAt: null,
        endedAt: null,
        durationSeconds: 720,
        peers: ['Sam'],
        controlled: true,
      }),
    ).toBe('Unattended · Sam · controlled · 12 min')
  })

  /** "Attended" and "Control companion" are the API's names for them, and nobody's. */
  it('names a session the way the rest of the window does', () => {
    const session = {
      uuid: 's-2',
      displayId: 'AR-2',
      accessMode: 'ATTENDED',
      role: 'HOST',
      status: 'ENDED',
      startedAt: null,
      endedAt: null,
      durationSeconds: null,
      peers: [],
      controlled: false,
    }

    expect(describeSession(session)).toBe('Help session · Nobody joined')
    expect(describeSession({ ...session, role: 'COMPANION', peers: ['Priya'] })).toBe(
      'Shared from the browser · Priya',
    )
  })
})

describe('ComputerHistory', () => {
  it('shows connections and changes from the server’s record', async () => {
    bridge.computerHistory.mockResolvedValue({
      sessions: [
        {
          uuid: 's-1',
          displayId: 'AR-1',
          accessMode: 'ATTENDED',
          role: 'HOST',
          status: 'ENDED',
          startedAt: '2026-10-30T09:00:00Z',
          endedAt: '2026-10-30T09:10:00Z',
          durationSeconds: 600,
          peers: ['Priya'],
          controlled: false,
        },
      ],
      events: [{ event: 'device.unattended_enabled', at: '2026-10-29T09:00:00Z', summary: 'Unattended access turned on', actorName: 'Priya' }],
    })

    render(<ComputerHistory recent={[]} />)

    expect(await screen.findByText('Help session · Priya · 10 min')).toBeInTheDocument()
    expect(screen.getByText('Unattended access turned on · Priya')).toBeInTheDocument()
  })

  it('falls back to what this run has seen when the record cannot be read', async () => {
    bridge.computerHistory.mockRejectedValue('not found')

    render(<ComputerHistory recent={[]} />)

    expect(await screen.findByText('Recent sessions')).toBeInTheDocument()
  })
})
