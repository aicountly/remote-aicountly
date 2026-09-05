import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import type { AgentState, SessionSummary } from '../../types/agent'
import { SessionBanner, formatClock } from './SessionBanner'

/**
 * The property this component exists to hold: **a running session is always
 * visible, and can always be stopped from here.**
 */

function session(overrides: Partial<SessionSummary> = {}): SessionSummary {
  return {
    sessionUuid: 'session-uuid',
    displayId: 'AR-10282',
    connectedName: 'Sam in support',
    companyName: 'Northwind',
    startedAt: new Date(Date.now() - 65_000).toISOString(),
    unattended: false,
    control: { state: 'none', clipboard: false },
    ...overrides,
  }
}

function state(status: AgentState['status']): AgentState {
  return {
    status,
    deviceName: 'WS-01',
    deviceUuid: 'device-uuid',
    companyName: 'Northwind',
    keyFingerprint: 'AAAA BBBB',
    unattended: { enabled: false, enabledAt: null, lastUsedAt: null, allowedByPolicy: true },
    agentVersion: '1.0.0',
    recentSessions: [],
  }
}

describe('SessionBanner', () => {
  it('renders nothing when no session is running', () => {
    const { container } = render(
      <SessionBanner state={state({ status: 'online' })} onStopControl={vi.fn()} onEndSession={vi.fn()} />,
    )

    expect(container).toBeEmptyDOMElement()
  })

  it('names who is connected, from where, and since when', () => {
    render(
      <SessionBanner
        state={state({ status: 'in_session', ...session() })}
        onStopControl={vi.fn()}
        onEndSession={vi.fn()}
      />,
    )

    expect(screen.getByText('Remote session active')).toBeInTheDocument()
    expect(screen.getByText('Sam in support')).toBeInTheDocument()
    expect(screen.getByText('Northwind')).toBeInTheDocument()
    expect(screen.getByRole('status')).toBeInTheDocument()
  })

  /** "Somebody connected while you were away" is materially different. */
  it('says when a connection arrived through unattended access', () => {
    render(
      <SessionBanner
        state={state({ status: 'in_session', ...session({ unattended: true }) })}
        onStopControl={vi.fn()}
        onEndSession={vi.fn()}
      />,
    )

    expect(screen.getByText(/used unattended access/i)).toBeInTheDocument()
  })

  /** The whole reason the banner carries buttons at all. */
  it('offers Stop control exactly while somebody is controlling', async () => {
    const onStopControl = vi.fn()

    const { rerender } = render(
      <SessionBanner
        state={state({ status: 'in_session', ...session() })}
        onStopControl={onStopControl}
        onEndSession={vi.fn()}
      />,
    )

    expect(screen.queryByRole('button', { name: /stop control/i })).not.toBeInTheDocument()
    expect(screen.getByText('Viewing only')).toBeInTheDocument()

    rerender(
      <SessionBanner
        state={state({
          status: 'in_session',
          ...session({ control: { state: 'granted', clipboard: false } }),
        })}
        onStopControl={onStopControl}
        onEndSession={vi.fn()}
      />,
    )

    const stop = screen.getByRole('button', { name: /stop control/i })
    await userEvent.click(stop)

    expect(onStopControl).toHaveBeenCalledOnce()
  })

  it('says when the clipboard is being shared as well as control', () => {
    render(
      <SessionBanner
        state={state({
          status: 'in_session',
          ...session({ control: { state: 'granted', clipboard: true } }),
        })}
        onStopControl={vi.fn()}
        onEndSession={vi.fn()}
      />,
    )

    expect(screen.getByText('Controlling, clipboard shared')).toBeInTheDocument()
  })

  it('always offers End session while one is running', async () => {
    const onEndSession = vi.fn()

    render(
      <SessionBanner
        state={state({ status: 'in_session', ...session() })}
        onStopControl={vi.fn()}
        onEndSession={onEndSession}
      />,
    )

    await userEvent.click(screen.getByRole('button', { name: /end session/i }))

    expect(onEndSession).toHaveBeenCalledOnce()
  })

  it('shows a running clock so nobody has to remember when it started', () => {
    expect(formatClock(0)).toBe('00:00')
    expect(formatClock(65)).toBe('01:05')
    expect(formatClock(3_725)).toBe('1:02:05')
  })
})
