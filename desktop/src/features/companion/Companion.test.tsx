import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import type { AgentState, CompanionSummary, MonitorOption } from '../../types/agent'
import { activeCompanion, isBeingControlled, isOnline } from '../../types/agent'
import { CompanionBanner } from './CompanionBanner'
import { CompanionConfirm } from './CompanionConfirm'
import { identifyName } from './IdentifyOverlay'
import { MonitorPicker, describe as describeMonitor } from './MonitorPicker'

/**
 * The control companion's screens: the one question it asks at the machine,
 * the monitor picker, and the banner that says who is controlling and stops
 * them.
 */

function companion(overrides: Partial<CompanionSummary> = {}): CompanionSummary {
  return {
    companionUuid: 'companion-1',
    sessionUuid: 'session-1',
    displayId: '123-456',
    companyName: 'Northwind',
    requestedByName: 'Rahul',
    expiresAt: null,
    startedAt: new Date(Date.now() - 30_000).toISOString(),
    phase: 'ready',
    monitors: [],
    monitor: { id: 1, name: 'Built-in display', primary: true, width: 1512, height: 982, scalePercent: 200 },
    controllerName: null,
    ...overrides,
  }
}

function state(summary: CompanionSummary | null): AgentState {
  return {
    status: summary ? { status: 'companion', ...summary } : { status: 'online' },
    deviceName: 'MacBook',
    deviceUuid: 'device-1',
    companyName: 'Northwind',
    keyFingerprint: 'AAAA',
    unattended: { enabled: false, enabledAt: null, lastUsedAt: null, allowedByPolicy: false },
    allowedCapabilities: null,
    agentVersion: '1.0.0',
    recentSessions: [],
  }
}

const twin = (id: number): MonitorOption => ({
  id,
  name: `Display ${id}`,
  primary: id === 1,
  width: 2560,
  height: 1440,
  scalePercent: 100,
})

describe('CompanionConfirm', () => {
  it('asks in the words of the decision: which session, which organisation, this Mac', () => {
    render(
      <CompanionConfirm
        companion={companion({ phase: 'awaiting_confirmation', startedAt: null })}
        computerName="Mac"
        needsAccessibility={false}
        onAllow={vi.fn()}
        onDecline={vi.fn()}
        onOpenAccessibility={vi.fn()}
      />,
    )

    expect(
      screen.getByText('Let people in session 123-456 (Northwind) control this Mac while you share it?'),
    ).toBeInTheDocument()
    expect(screen.getByText(/needs your approval there/i)).toBeInTheDocument()
    expect(screen.queryByText(/Accessibility/)).not.toBeInTheDocument()
  })

  it('Allow and Not now each reach exactly one callback', async () => {
    const onAllow = vi.fn()
    const onDecline = vi.fn()

    render(
      <CompanionConfirm
        companion={companion({ phase: 'awaiting_confirmation' })}
        computerName="Mac"
        needsAccessibility={false}
        onAllow={onAllow}
        onDecline={onDecline}
        onOpenAccessibility={vi.fn()}
      />,
    )

    await userEvent.click(screen.getByRole('button', { name: /allow while i share/i }))
    expect(onAllow).toHaveBeenCalledOnce()
    expect(onDecline).not.toHaveBeenCalled()

    await userEvent.click(screen.getByRole('button', { name: /not now/i }))
    expect(onDecline).toHaveBeenCalledOnce()
  })

  it('says how to grant Accessibility when macOS has not, and never asks for Screen Recording', async () => {
    const onOpenAccessibility = vi.fn()

    render(
      <CompanionConfirm
        companion={companion({ phase: 'awaiting_confirmation' })}
        computerName="Mac"
        needsAccessibility
        onAllow={vi.fn()}
        onDecline={vi.fn()}
        onOpenAccessibility={onOpenAccessibility}
      />,
    )

    expect(screen.getByText(/Privacy & Security → Accessibility/)).toBeInTheDocument()
    expect(screen.getByText(/Screen Recording is not needed/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: /open accessibility settings/i }))
    expect(onOpenAccessibility).toHaveBeenCalledOnce()
  })
})

describe('MonitorPicker', () => {
  it('offers each candidate and reports the one picked', async () => {
    const onChoose = vi.fn()

    render(
      <MonitorPicker
        companion={companion({ phase: 'choosing_monitor', monitors: [twin(1), twin(2)], monitor: null })}
        waitingForAccessibility={false}
        onChoose={onChoose}
        onIdentify={vi.fn()}
      />,
    )

    expect(screen.getByText('Which screen are you sharing?')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Share Display 2' }))
    expect(onChoose).toHaveBeenCalledWith(twin(2))
  })

  it('has an Identify button so identical screens can be told apart', async () => {
    const onIdentify = vi.fn()

    render(
      <MonitorPicker
        companion={companion({ phase: 'choosing_monitor', monitors: [twin(1), twin(2)], monitor: null })}
        waitingForAccessibility={false}
        onChoose={vi.fn()}
        onIdentify={onIdentify}
      />,
    )

    await userEvent.click(screen.getByRole('button', { name: /identify screens/i }))
    expect(onIdentify).toHaveBeenCalledOnce()
  })

  it('says what it is waiting for when there is nothing to pick yet', () => {
    const { rerender } = render(
      <MonitorPicker
        companion={companion({ phase: 'choosing_monitor', monitors: [], monitor: null })}
        waitingForAccessibility={false}
        onChoose={vi.fn()}
        onIdentify={vi.fn()}
      />,
    )

    expect(screen.getByText(/Finding the screen you are sharing/)).toBeInTheDocument()

    rerender(
      <MonitorPicker
        companion={companion({ phase: 'choosing_monitor', monitors: [], monitor: null })}
        waitingForAccessibility
        onChoose={vi.fn()}
        onIdentify={vi.fn()}
      />,
    )

    expect(screen.getByText(/Waiting for Accessibility permission/)).toBeInTheDocument()
  })

  it('describes a monitor by its size and scale', () => {
    expect(describeMonitor({ ...twin(1), width: 1512, height: 982, scalePercent: 200 })).toBe('1512 × 982, Retina')
    expect(describeMonitor({ ...twin(1), scalePercent: 150 })).toBe('2560 × 1440, 150%')
    expect(describeMonitor(twin(1))).toBe('2560 × 1440')
  })
})

describe('CompanionBanner', () => {
  it('renders nothing for an idle machine or one still being asked', () => {
    const { container, rerender } = render(
      <CompanionBanner state={state(null)} computerName="Mac" stopShortcut="⌘⌥⇧S" onStopControl={vi.fn()} onLeave={vi.fn()} />,
    )
    expect(container).toBeEmptyDOMElement()

    rerender(
      <CompanionBanner
        state={state(companion({ phase: 'awaiting_confirmation' }))}
        computerName="Mac"
        stopShortcut="⌘⌥⇧S"
        onStopControl={vi.fn()}
        onLeave={vi.fn()}
      />,
    )
    expect(container).toBeEmptyDOMElement()
  })

  /** The property the banner exists for: who is controlling, and Stop. */
  it('names who is controlling and offers Stop control and the shortcut', async () => {
    const onStopControl = vi.fn()

    render(
      <CompanionBanner
        state={state(companion({ phase: 'controlled', controllerName: 'Sam' }))}
        computerName="Mac"
        stopShortcut="⌘⌥⇧S"
        onStopControl={onStopControl}
        onLeave={vi.fn()}
      />,
    )

    expect(screen.getByText('Sam is controlling this Mac')).toBeInTheDocument()
    expect(screen.getByText('⌘⌥⇧S')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Stop control' }))
    expect(onStopControl).toHaveBeenCalledOnce()
  })

  it('says remote input is paused while the person here is using the computer', () => {
    render(
      <CompanionBanner
        state={state(companion({ phase: 'paused', controllerName: 'Sam' }))}
        computerName="Mac"
        stopShortcut="⌘⌥⇧S"
        onStopControl={vi.fn()}
        onLeave={vi.fn()}
      />,
    )

    expect(screen.getByText('Paused while you use this Mac')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Stop control' })).toBeInTheDocument()
  })

  it('offers no Stop control while nobody is controlling, but can always stop allowing it', async () => {
    const onLeave = vi.fn()

    render(
      <CompanionBanner
        state={state(companion({ phase: 'ready' }))}
        computerName="Mac"
        stopShortcut="⌘⌥⇧S"
        onStopControl={vi.fn()}
        onLeave={onLeave}
      />,
    )

    expect(screen.getByText('This Mac can be controlled while you share')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Stop control' })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: /stop allowing control/i }))
    expect(onLeave).toHaveBeenCalledOnce()
  })
})

describe('companion state helpers', () => {
  it('count a controlled or paused companion as being controlled', () => {
    expect(isBeingControlled(state(companion({ phase: 'controlled' })))).toBe(true)
    expect(isBeingControlled(state(companion({ phase: 'paused' })))).toBe(true)
    expect(isBeingControlled(state(companion({ phase: 'ready' })))).toBe(false)
    expect(isOnline(state(companion()))).toBe(true)
    expect(activeCompanion(state(null))).toBeNull()
  })

  it('read an Identify label from its URL fragment and nothing else', () => {
    expect(identifyName('#identify=Display%202')).toBe('Display 2')
    expect(identifyName('#settings')).toBeNull()
    expect(identifyName('')).toBeNull()
    expect(identifyName('#identify=%E0%A4%A')).toBeNull()
  })
})
