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
  it('names who is asking, and from which organisation', () => {
    render(
      <ControlRequest
        session={session()}
        requesterName="Sam in support"
        clipboardAllowedByPolicy={false}
        shareClipboard={false}
        onShareClipboardChange={vi.fn()}
        secureDesktopSupported={false}
        answerSecureDesktop={false}
        onAnswerSecureDesktopChange={vi.fn()}
        onAllow={vi.fn()}
        onDeny={vi.fn()}
      />,
    )

    expect(
      screen.getByText('Sam in support is asking to control this computer'),
    ).toBeInTheDocument()
    expect(screen.getByText('From Northwind.')).toBeInTheDocument()
    // The session's number is one the person at the computer never saw.
    expect(screen.queryByText(/AR-10282/)).not.toBeInTheDocument()
    // Named after who is asking, for a screen reader as for anybody else.
    expect(
      screen.getByRole('alertdialog', { name: 'Sam in support is asking to control this computer' }),
    ).toBeInTheDocument()
  })

  /**
   * This is the screen a scam phone call ends at: "open the app and press
   * Allow". It has to say, in the dialog and not in small print, who should
   * never be let in.
   */
  it('warns against allowing somebody the person does not know or did not ask', () => {
    render(
      <ControlRequest
        session={session()}
        requesterName="Sam in support"
        clipboardAllowedByPolicy={false}
        shareClipboard={false}
        onShareClipboardChange={vi.fn()}
        secureDesktopSupported={false}
        answerSecureDesktop={false}
        onAnswerSecureDesktopChange={vi.fn()}
        onAllow={vi.fn()}
        onDeny={vi.fn()}
      />,
    )

    expect(screen.getByText(/only allow this if you know Sam in support and asked them for help/i)).toBeInTheDocument()
    expect(screen.getByText(/never calls or messages anybody to ask for control/i)).toBeInTheDocument()
  })

  /** How to stop it is part of what is being agreed to — the shortcut included. */
  it('says every way to stop control, in this platform’s words', () => {
    const { rerender } = render(
      <ControlRequest
        session={session()}
        requesterName="Sam in support"
        stopShortcut="Ctrl+Alt+Shift+S"
        clipboardAllowedByPolicy={false}
        shareClipboard={false}
        onShareClipboardChange={vi.fn()}
        secureDesktopSupported={false}
        answerSecureDesktop={false}
        onAnswerSecureDesktopChange={vi.fn()}
        onAllow={vi.fn()}
        onDeny={vi.fn()}
      />,
    )

    expect(screen.getByText('Ctrl+Alt+Shift+S')).toBeInTheDocument()
    expect(screen.getByText(/beside the clock/)).toBeInTheDocument()

    rerender(
      <ControlRequest
        session={session()}
        requesterName="Sam in support"
        computerName="Mac"
        stopShortcut="⌘⌥⇧S"
        clipboardAllowedByPolicy={false}
        shareClipboard={false}
        onShareClipboardChange={vi.fn()}
        secureDesktopSupported={false}
        answerSecureDesktop={false}
        onAnswerSecureDesktopChange={vi.fn()}
        onAllow={vi.fn()}
        onDeny={vi.fn()}
      />,
    )

    expect(screen.getByText('Sam in support is asking to control this Mac')).toBeInTheDocument()
    expect(screen.getByText(/in the menu bar/)).toBeInTheDocument()
    expect(screen.queryByText(/beside the clock/)).not.toBeInTheDocument()
  })

  /**
   * The keyboard goes to the dialog, not to a button: a key pressed a moment
   * too late, meant for something else, must not be able to allow control.
   * Esc declines.
   */
  it('takes the keyboard without putting it on either answer, and Esc is Not now', async () => {
    const onAllow = vi.fn()
    const onDeny = vi.fn()

    render(
      <ControlRequest
        session={session()}
        requesterName="Sam in support"
        clipboardAllowedByPolicy={false}
        shareClipboard={false}
        onShareClipboardChange={vi.fn()}
        secureDesktopSupported={false}
        answerSecureDesktop={false}
        onAnswerSecureDesktopChange={vi.fn()}
        onAllow={onAllow}
        onDeny={onDeny}
      />,
    )

    expect(screen.getByRole('alertdialog')).toHaveFocus()

    await userEvent.keyboard('{Enter}')
    await userEvent.keyboard(' ')
    expect(onAllow).not.toHaveBeenCalled()
    expect(onDeny).not.toHaveBeenCalled()

    await userEvent.keyboard('{Escape}')
    expect(onDeny).toHaveBeenCalledOnce()
    expect(onAllow).not.toHaveBeenCalled()
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
        secureDesktopSupported={false}
        answerSecureDesktop={false}
        onAnswerSecureDesktopChange={vi.fn()}
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
        secureDesktopSupported={false}
        answerSecureDesktop={false}
        onAnswerSecureDesktopChange={vi.fn()}
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
        secureDesktopSupported={false}
        answerSecureDesktop={false}
        onAnswerSecureDesktopChange={vi.fn()}
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
        secureDesktopSupported={false}
        answerSecureDesktop={false}
        onAnswerSecureDesktopChange={vi.fn()}
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
        secureDesktopSupported={false}
        answerSecureDesktop={false}
        onAnswerSecureDesktopChange={vi.fn()}
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

  it('offers the Windows administrator-prompt tick only on a host that can, and it starts off', async () => {
    const onAnswerSecureDesktopChange = vi.fn()

    const { rerender } = render(
      <ControlRequest
        session={session()}
        requesterName="Sam in support"
        clipboardAllowedByPolicy={false}
        shareClipboard={false}
        onShareClipboardChange={vi.fn()}
        secureDesktopSupported={false}
        answerSecureDesktop={false}
        onAnswerSecureDesktopChange={onAnswerSecureDesktopChange}
        onAllow={vi.fn()}
        onDeny={vi.fn()}
      />,
    )

    // Not offered when the host cannot reach the Secure Desktop.
    expect(screen.queryByText(/answer Windows administrator prompts/i)).not.toBeInTheDocument()

    rerender(
      <ControlRequest
        session={session()}
        requesterName="Sam in support"
        clipboardAllowedByPolicy={false}
        shareClipboard={false}
        onShareClipboardChange={vi.fn()}
        secureDesktopSupported
        answerSecureDesktop={false}
        onAnswerSecureDesktopChange={onAnswerSecureDesktopChange}
        onAllow={vi.fn()}
        onDeny={vi.fn()}
      />,
    )

    const box = screen.getByRole('checkbox', { name: /answer Windows administrator prompts/i })
    expect(box).not.toBeChecked()

    await userEvent.click(box)
    expect(onAnswerSecureDesktopChange).toHaveBeenCalledWith(true)
  })

  it('disables both decisions while a previous one is still in flight', () => {
    render(
      <ControlRequest
        session={session()}
        requesterName="Sam in support"
        clipboardAllowedByPolicy={false}
        shareClipboard={false}
        onShareClipboardChange={vi.fn()}
        secureDesktopSupported={false}
        answerSecureDesktop={false}
        onAnswerSecureDesktopChange={vi.fn()}
        onAllow={vi.fn()}
        onDeny={vi.fn()}
        busy
      />,
    )

    expect(screen.getByRole('button', { name: /allow control/i })).toBeDisabled()
    expect(screen.getByRole('button', { name: /not now/i })).toBeDisabled()
  })
})
