import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import { SignInScreen } from './SignInScreen'

/**
 * The screen a signed-out machine lands on, and the promise it makes: the
 * password is not typed here, and this is asked once.
 */
describe('SignInScreen', () => {
  it('offers to sign in, and says the password is not typed here', async () => {
    const onSignIn = vi.fn()
    render(<SignInScreen revoked={false} deviceId="A1B2 C3D4 E5F6 A7B8" onSignIn={onSignIn} />)

    await userEvent.click(screen.getByRole('button', { name: /sign in to aicountly/i }))

    expect(onSignIn).toHaveBeenCalledTimes(1)
    expect(screen.getByText(/password is never typed into this window/i)).toBeTruthy()
    expect(screen.getByText(/will not be asked again/i)).toBeTruthy()
  })

  /// A revoked machine is signed out, and saying so is the difference between
  /// "sign in" and "somebody took this computer away from you".
  /**
   * The ID is readable before anybody signs in — somebody reads it out to a
   * colleague — and the screen says plainly that it reaches nothing yet.
   */
  it('shows the computer ID while still signed out, and says nothing can connect', () => {
    render(<SignInScreen revoked={false} deviceId="A1B2 C3D4 E5F6 A7B8" onSignIn={vi.fn()} />)

    expect(screen.getByText('A1B2 C3D4 E5F6 A7B8')).toBeTruthy()
    expect(screen.getByText(/nobody can connect to this computer until you sign in/i)).toBeTruthy()
  })

  /** Not yet read, or unreadable: a dash, and the screen still works. */
  it('shows a dash when the ID is not available', () => {
    render(<SignInScreen revoked={false} deviceId={null} onSignIn={vi.fn()} />)

    expect(screen.getByText('—')).toBeTruthy()
    expect(screen.getByRole('button', { name: /sign in to aicountly/i })).toBeTruthy()
  })

  it('says when an administrator removed the computer', () => {
    render(<SignInScreen revoked deviceId="A1B2 C3D4 E5F6 A7B8" onSignIn={vi.fn()} />)

    expect(screen.getByText(/administrator removed this computer/i)).toBeTruthy()
  })

  it('cannot be used while something else is in flight', async () => {
    const onSignIn = vi.fn()
    render(<SignInScreen revoked={false} deviceId="A1B2 C3D4 E5F6 A7B8" onSignIn={onSignIn} busy />)

    await userEvent.click(screen.getByRole('button', { name: /sign in to aicountly/i }))

    expect(onSignIn).not.toHaveBeenCalled()
  })
})
