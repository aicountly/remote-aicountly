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
    render(<SignInScreen revoked={false} onSignIn={onSignIn} />)

    await userEvent.click(screen.getByRole('button', { name: /sign in to aicountly/i }))

    expect(onSignIn).toHaveBeenCalledTimes(1)
    expect(screen.getByText(/password is never typed into this window/i)).toBeTruthy()
    expect(screen.getByText(/will not be asked again/i)).toBeTruthy()
  })

  /// A revoked machine is signed out, and saying so is the difference between
  /// "sign in" and "somebody took this computer away from you".
  it('says when an administrator removed the computer', () => {
    render(<SignInScreen revoked onSignIn={vi.fn()} />)

    expect(screen.getByText(/administrator removed this computer/i)).toBeTruthy()
  })

  it('cannot be used while something else is in flight', async () => {
    const onSignIn = vi.fn()
    render(<SignInScreen revoked={false} onSignIn={onSignIn} busy />)

    await userEvent.click(screen.getByRole('button', { name: /sign in to aicountly/i }))

    expect(onSignIn).not.toHaveBeenCalled()
  })
})
