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
    allowedCapabilities: null,
    agentVersion: '1.0.0',
    recentSessions: [],
  }
}

describe('UnattendedCard', () => {
  it('is turned on in Remote, where the account confirms it, never here', async () => {
    const onEnable = vi.fn()

    render(<UnattendedCard state={state()} onEnable={onEnable} onDisable={vi.fn()} />)

    // What it means is on screen before anything is pressed.
    expect(screen.getByText(/when nobody is sitting at it/i)).toBeInTheDocument()
    expect(screen.getByText(/you confirm it there/i)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: /turn on in remote/i }))

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
  it('explains rather than offering a switch the account cannot use', () => {
    render(
      <UnattendedCard
        state={state({ allowedByPolicy: false })}
        onEnable={vi.fn()}
        onDisable={vi.fn()}
      />,
    )

    expect(screen.getByRole('button', { name: /turn on in remote/i })).toBeDisabled()
    expect(screen.getByText(/not available for your account/i)).toBeInTheDocument()
  })

  it('says what it means: only you, recorded, shown while it happens', () => {
    render(<UnattendedCard state={state()} onEnable={vi.fn()} onDisable={vi.fn()} />)

    expect(screen.getByText(/nobody else can/i)).toBeInTheDocument()
    expect(screen.getByText(/every connection is recorded/i)).toBeInTheDocument()
  })
})
