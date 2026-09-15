import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import type { DesktopSignInStart } from '../../types/agent'
import { DesktopSignInPending } from './DesktopSignInPending'

/**
 * Waiting for a device-code sign-in — the desktop's whole part in it is
 * showing the code and reacting to what the browser tab decided.
 */

function start(overrides: Partial<DesktopSignInStart> = {}): DesktopSignInStart {
  return {
    userCode: 'ABCD-1234',
    deviceCode: 'a'.repeat(64),
    verificationUri: 'https://remote.aicountly.com/desktop-signin',
    verificationUriComplete: 'https://remote.aicountly.com/desktop-signin?code=ABCD-1234',
    expiresAt: '2026-09-15T12:10:00Z',
    intervalSeconds: 2,
    ...overrides,
  }
}

describe('DesktopSignInPending', () => {
  it('shows the code so it can be matched against the browser tab', () => {
    render(
      <DesktopSignInPending start={start()} outcome="pending" onCancel={vi.fn()} onRetry={vi.fn()} />,
    )

    expect(screen.getByText('ABCD-1234')).toBeInTheDocument()
    expect(screen.getByText(/waiting for confirmation/i)).toBeInTheDocument()
  })

  it('lets the person cancel while waiting', async () => {
    const onCancel = vi.fn()
    render(
      <DesktopSignInPending start={start()} outcome="pending" onCancel={onCancel} onRetry={vi.fn()} />,
    )

    await userEvent.click(screen.getByRole('button', { name: /cancel/i }))

    expect(onCancel).toHaveBeenCalledOnce()
  })

  it('says so and offers to try again when the code was declined', async () => {
    const onRetry = vi.fn()
    render(
      <DesktopSignInPending start={start()} outcome="denied" onCancel={vi.fn()} onRetry={onRetry} />,
    )

    expect(screen.getByText(/sign-in declined/i)).toBeInTheDocument()
    expect(screen.queryByText('ABCD-1234')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: /try again/i }))
    expect(onRetry).toHaveBeenCalledOnce()
  })

  it('says so and offers to start again when the code expired', async () => {
    const onRetry = vi.fn()
    render(
      <DesktopSignInPending start={start()} outcome="expired" onCancel={vi.fn()} onRetry={onRetry} />,
    )

    expect(screen.getByText(/this code expired/i)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: /start again/i }))
    expect(onRetry).toHaveBeenCalledOnce()
  })

  it('disables its buttons while busy', () => {
    render(
      <DesktopSignInPending start={start()} outcome="pending" onCancel={vi.fn()} onRetry={vi.fn()} busy />,
    )

    expect(screen.getByRole('button', { name: /cancel/i })).toBeDisabled()
  })
})
