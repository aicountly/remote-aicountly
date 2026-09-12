import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import type { AgentState } from '../../types/agent'
import { UnattendedCard } from './UnattendedCard'

/**
 * Unattended access is its own decision, and the asymmetry is the point:
 * turning it on takes a confirmation, turning it off takes one click.
 */

function state(unattended: Partial<AgentState['unattended']> = {}): AgentState {
  return {
    status: { status: 'online' },
    deviceName: 'WS-01',
    deviceUuid: 'device-uuid',
    companyName: 'Northwind',
    keyFingerprint: 'AAAA BBBB',
    unattended: {
      enabled: false,
      enabledAt: null,
      lastUsedAt: null,
      allowedByPolicy: true,
      ...unattended,
    },
    agentVersion: '1.0.0',
    recentSessions: [],
  }
}

describe('UnattendedCard', () => {
  it('never switches on without an explicit confirmation', async () => {
    const onEnable = vi.fn()

    render(<UnattendedCard state={state()} onEnable={onEnable} onDisable={vi.fn()} />)

    await userEvent.click(screen.getByRole('button', { name: /turn on unattended access/i }))

    // The first click shows the warning; it does not enable anything.
    expect(onEnable).not.toHaveBeenCalled()
    expect(screen.getByText(/when nobody is sitting at it/i)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: /i understand/i }))

    expect(onEnable).toHaveBeenCalledOnce()
  })

  /** Making a machine unreachable should never be obstructed. */
  it('switches off in one click, with no confirmation', async () => {
    const onDisable = vi.fn()

    render(
      <UnattendedCard
        state={state({ enabled: true, enabledAt: '2026-02-10T08:00:00Z' })}
        onEnable={vi.fn()}
        onDisable={onDisable}
      />,
    )

    await userEvent.click(screen.getByRole('button', { name: /turn off unattended access/i }))

    expect(onDisable).toHaveBeenCalledOnce()
  })

  it('shows when it was turned on and when it was last used', () => {
    render(
      <UnattendedCard
        state={state({ enabled: true, enabledAt: '2026-02-10T08:00:00Z', lastUsedAt: null })}
        onEnable={vi.fn()}
        onDisable={vi.fn()}
      />,
    )

    expect(screen.getByText('ON')).toBeInTheDocument()
    expect(screen.getByText('Never')).toBeInTheDocument()
  })

  /** A switch that always refuses is worse than no switch. */
  it('explains rather than offering a switch the organisation forbids', () => {
    render(
      <UnattendedCard
        state={state({ allowedByPolicy: false })}
        onEnable={vi.fn()}
        onDisable={vi.fn()}
      />,
    )

    expect(screen.getByRole('button', { name: /turn on unattended access/i })).toBeDisabled()
    expect(screen.getByText(/has not enabled unattended access/i)).toBeInTheDocument()
  })

  it('says the warning before it is confirmed, not after', async () => {
    render(<UnattendedCard state={state()} onEnable={vi.fn()} onDisable={vi.fn()} />)

    await userEvent.click(screen.getByRole('button', { name: /turn on unattended access/i }))

    const warning = screen.getByText(/when nobody is sitting at it/i)
    expect(warning).toBeInTheDocument()
    expect(screen.getByText(/every connection is recorded/i)).toBeInTheDocument()
  })
})
