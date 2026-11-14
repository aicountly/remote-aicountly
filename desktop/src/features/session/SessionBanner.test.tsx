import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import type { AgentState, SessionSummary } from '../../types/agent'
import { SessionBanner, formatClock, sessionSentence } from './SessionBanner'

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
    control: { state: 'none', clipboard: false, requesterUuid: null, requesterName: null },
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
    allowedCapabilities: null,
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

    // One sentence, and it is what a screen reader is told when it changes.
    expect(screen.getByRole('status')).toHaveTextContent('Sam in support can see this computer’s screen')
    expect(screen.getByText(/Northwind/)).toBeInTheDocument()
    expect(screen.getByText(/Connected for/)).toBeInTheDocument()
  })

  /**
   * A help session whose code has just been read out has nobody in it. The
   * banner used to say "Remote session active", "Connected person" over a
   * blank, and "Viewing only".
   */
  it('says nobody is connected yet rather than describing a person who is not there', () => {
    render(
      <SessionBanner
        state={state({ status: 'in_session', ...session({ connectedName: '', companyName: null }) })}
        onStopControl={vi.fn()}
        onEndSession={vi.fn()}
      />,
    )

    expect(screen.getByRole('status')).toHaveTextContent('Waiting for someone to join — nobody is connected yet')
    expect(screen.queryByText(/can see this/)).not.toBeInTheDocument()
  })

  /** Looking and controlling are different sentences, not one word in a grid. */
  it('says in its first words that somebody is controlling, and on which kind of computer', () => {
    expect(sessionSentence('Sam', false, 'computer')).toBe('Sam can see this computer’s screen')
    expect(sessionSentence('Sam', true, 'computer')).toBe('Sam is controlling this computer')
    expect(sessionSentence('Sam', true, 'Mac')).toBe('Sam is controlling this Mac')
    expect(sessionSentence('', true, 'computer')).toBe('Someone is controlling this computer')
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
    expect(screen.getByRole('status')).toHaveTextContent('Sam in support can see this computer’s screen')

    rerender(
      <SessionBanner
        state={state({
          status: 'in_session',
          ...session({
            control: { state: 'granted', clipboard: false, requesterUuid: null, requesterName: null },
          }),
        })}
        onStopControl={onStopControl}
        onEndSession={vi.fn()}
      />,
    )

    expect(screen.getByRole('status')).toHaveTextContent('Sam in support is controlling this computer')

    const stop = screen.getByRole('button', { name: /stop control/i })
    await userEvent.click(stop)

    expect(onStopControl).toHaveBeenCalledOnce()
  })

  /**
   * The stop buttons take no `busy`: they used to grey out while any other
   * action in the window was waiting on the server.
   */
  it('never disables either stop button, and says the shortcut that stops control from anywhere', () => {
    render(
      <SessionBanner
        state={state({
          status: 'in_session',
          ...session({
            control: { state: 'granted', clipboard: false, requesterUuid: null, requesterName: null },
          }),
        })}
        stopShortcut="Ctrl+Alt+Shift+S"
        onStopControl={vi.fn()}
        onEndSession={vi.fn()}
      />,
    )

    expect(screen.getByRole('button', { name: /stop control/i })).toBeEnabled()
    expect(screen.getByRole('button', { name: /end session/i })).toBeEnabled()
    expect(screen.getByText('Ctrl+Alt+Shift+S')).toBeInTheDocument()
  })

  it('says when somebody is asking to control', () => {
    render(
      <SessionBanner
        state={state({
          status: 'in_session',
          ...session({
            control: { state: 'requested', clipboard: false, requesterUuid: 'p1', requesterName: 'Sam in support' },
          }),
        })}
        onStopControl={vi.fn()}
        onEndSession={vi.fn()}
      />,
    )

    expect(screen.getByText(/Asking to control/)).toBeInTheDocument()
  })

  it('says when the clipboard is being shared as well as control', () => {
    render(
      <SessionBanner
        state={state({
          status: 'in_session',
          ...session({
            control: { state: 'granted', clipboard: true, requesterUuid: null, requesterName: null },
          }),
        })}
        onStopControl={vi.fn()}
        onEndSession={vi.fn()}
      />,
    )

    expect(screen.getByText(/Clipboard shared/)).toBeInTheDocument()
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
