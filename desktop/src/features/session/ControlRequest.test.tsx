import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import type { SessionSummary } from '../../types/agent'
import { ControlRequest } from './ControlRequest'

/**
 * The consent dialog itself. `App.tsx` decides *when* this renders; these
 * tests are about what it says and does once it has — the property that
 * matters is that "Allow" and "Not now" each reach exactly one callback, and
 * that the clipboard tick is off unless somebody ticks it.
 */

function session(overrides: Partial<SessionSummary> = {}): SessionSummary {
  return {
    sessionUuid: 'session-uuid',
    displayId: 'AR-10282',
    connectedName: 'Sam in support',
    companyName: 'Northwind',
    startedAt: new Date().toISOString(),
    unattended: false,
    control: { state: 'requested', clipboard: false, requesterUuid: 'p1', requesterName: 'Sam in support' },
    ...overrides,
  }
}

describe('ControlRequest', () => {
  it('names who is asking, from which organisation, in which session', () => {
    render(
      <ControlRequest
        session={session()}
        requesterName="Sam in support"
        clipboardAllowedByPolicy={false}
        shareClipboard={false}
        onShareClipboardChange={vi.fn()}
        onAllow={vi.fn()}
        onDeny={vi.fn()}
      />,
    )

    expect(
      screen.getByText('Sam in support is asking to control this computer'),
    ).toBeInTheDocument()
    expect(screen.getByText(/From Northwind, in session AR-10282/)).toBeInTheDocument()
  })

  /** Allowing this is a decision about a keyboard and a mouse, said in those words. */
  it('says exactly what allowing means', () => {
    render(
      <ControlRequest
        session={session()}
        requesterName="Sam in support"
        clipboardAllowedByPolicy={false}
        shareClipboard={false}
        onShareClipboardChange={vi.fn()}
        onAllow={vi.fn()}
        onDeny={vi.fn()}
      />,
    )

    expect(screen.getByText(/move your mouse, type on your keyboard/i)).toBeInTheDocument()
  })

  it('offers the clipboard tick only when policy allows it, and it starts off', () => {
    const { rerender } = render(
      <ControlRequest
        session={session()}
        requesterName="Sam in support"
        clipboardAllowedByPolicy={false}
        shareClipboard={false}
        onShareClipboardChange={vi.fn()}
        onAllow={vi.fn()}
        onDeny={vi.fn()}
      />,
    )

    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()

    rerender(
      <ControlRequest
        session={session()}
        requesterName="Sam in support"
        clipboardAllowedByPolicy
        shareClipboard={false}
        onShareClipboardChange={vi.fn()}
        onAllow={vi.fn()}
        onDeny={vi.fn()}
      />,
    )

    expect(screen.getByRole('checkbox')).not.toBeChecked()
  })

  it('reports a tick rather than flipping it, so the parent stays the source of truth', async () => {
    const onShareClipboardChange = vi.fn()

    render(
      <ControlRequest
        session={session()}
        requesterName="Sam in support"
        clipboardAllowedByPolicy
        shareClipboard={false}
        onShareClipboardChange={onShareClipboardChange}
        onAllow={vi.fn()}
        onDeny={vi.fn()}
      />,
    )

    await userEvent.click(screen.getByRole('checkbox'))

    expect(onShareClipboardChange).toHaveBeenCalledWith(true)
  })

  it('Allow control and Not now each reach exactly one callback', async () => {
    const onAllow = vi.fn()
    const onDeny = vi.fn()

    render(
      <ControlRequest
        session={session()}
        requesterName="Sam in support"
        clipboardAllowedByPolicy={false}
        shareClipboard={false}
        onShareClipboardChange={vi.fn()}
        onAllow={onAllow}
        onDeny={onDeny}
      />,
    )

    await userEvent.click(screen.getByRole('button', { name: /allow control/i }))
    expect(onAllow).toHaveBeenCalledOnce()
    expect(onDeny).not.toHaveBeenCalled()

    await userEvent.click(screen.getByRole('button', { name: /not now/i }))
    expect(onDeny).toHaveBeenCalledOnce()
    expect(onAllow).toHaveBeenCalledOnce()
  })

  it('disables both decisions while a previous one is still in flight', () => {
    render(
      <ControlRequest
        session={session()}
        requesterName="Sam in support"
        clipboardAllowedByPolicy={false}
        shareClipboard={false}
        onShareClipboardChange={vi.fn()}
        onAllow={vi.fn()}
        onDeny={vi.fn()}
        busy
      />,
    )

    expect(screen.getByRole('button', { name: /allow control/i })).toBeDisabled()
    expect(screen.getByRole('button', { name: /not now/i })).toBeDisabled()
  })
})
