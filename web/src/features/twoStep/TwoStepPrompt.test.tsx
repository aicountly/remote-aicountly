import { beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

/**
 * The verification prompt: one dialog however many calls are waiting, nothing
 * behind it usable, a code or a recovery code, the server's own sentence when
 * a code is refused — and the difference between a session lock (only way out
 * is signing out) and a step-up (which can be cancelled).
 */

const api = vi.hoisted(() => ({ verifyTwoStep: vi.fn() }))
vi.mock('../../services/api/remote', () => api)

const { default: TwoStepPrompt } = await import('./TwoStepPrompt')
const { requestTwoStep, resetTwoStepPrompt, TwoStepCancelled } = await import('../../services/twoStepPrompt')
const { RemoteApiError } = await import('../../services/api/client')

function renderPrompt(onSignOut = vi.fn()) {
  render(
    <>
      <main>
        <button type="button">A button on the page behind</button>
      </main>
      <TwoStepPrompt onSignOut={onSignOut} />
    </>,
  )

  return onSignOut
}

// A block body: an arrow returning the reset mock would hand Vitest the mock as a teardown hook.
beforeEach(() => {
  api.verifyTwoStep.mockReset()
  resetTwoStepPrompt()
})

describe('the two-step verification prompt', () => {
  it('opens once for several refused calls, and lets them all carry on when the code is accepted', async () => {
    api.verifyTwoStep.mockResolvedValue({ verificationToken: 't' })
    renderPrompt()

    let first!: Promise<void>
    let second!: Promise<void>
    act(() => {
      first = requestTwoStep('SESSION')
      second = requestTwoStep('SESSION')
    })

    expect(screen.getAllByRole('dialog')).toHaveLength(1)
    await userEvent.type(screen.getByLabelText('Code from your authenticator app'), '654321')
    await userEvent.click(screen.getByRole('button', { name: 'Verify' }))

    await expect(first).resolves.toBeUndefined()
    await expect(second).resolves.toBeUndefined()
    expect(api.verifyTwoStep).toHaveBeenCalledWith({ code: '654321' })
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('leaves nothing behind it usable while it asks', async () => {
    renderPrompt()

    act(() => {
      void requestTwoStep('SESSION').catch(() => undefined)
    })

    // Everything under <body> but the dialog itself — the page included.
    const others = Array.from(document.body.children).filter((element) => !element.contains(screen.getByRole('dialog')))
    expect(others.length).toBeGreaterThan(0)
    for (const element of others) {
      expect(element).toHaveAttribute('inert')
      expect(element).toHaveAttribute('aria-hidden', 'true')
    }
    expect(screen.getByText('A button on the page behind', { selector: 'button' }).closest('[inert]')).not.toBeNull()
    // And it covers the window, opaque, rather than sitting over a page still in view.
    expect(document.querySelector('.two-step-lock')).not.toBeNull()
  })

  it('offers signing out, not cancelling, when this browser has not passed it', async () => {
    const onSignOut = renderPrompt()
    act(() => {
      void requestTwoStep('SESSION').catch(() => undefined)
    })

    expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Sign out' }))
    expect(onSignOut).toHaveBeenCalled()
    expect(screen.getByText(/Use one of the recovery codes you saved/)).toBeInTheDocument()
  })

  it('can be cancelled when it is a step-up, which hands the refusal back', async () => {
    renderPrompt()

    let outcome!: Promise<unknown>
    act(() => {
      // Settled into a value straight away, so the rejection is never "unhandled" while the test clicks.
      outcome = requestTwoStep('STEP_UP', 'Turning on unattended access needs a code from your authenticator app entered in the last 10 minutes.').then(
        () => 'passed',
        (error: unknown) => error,
      )
    })

    expect(screen.getByRole('dialog', { name: 'Confirm it is you' })).toHaveTextContent('entered in the last 10 minutes')
    expect(document.querySelector('.two-step-lock')).toBeNull()
    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(await outcome).toBeInstanceOf(TwoStepCancelled)
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.getByText('A button on the page behind').closest('main')).not.toHaveAttribute('inert')
  })

  it('takes a recovery code instead, and shows the server’s sentence when one is refused', async () => {
    api.verifyTwoStep
      .mockRejectedValueOnce(
        new RemoteApiError('TWO_STEP_CODE_INVALID', 'That recovery code is not right, or it has been used. Check it and try again.', 400, { attemptsLeft: 4 }),
      )
      .mockResolvedValueOnce({ verificationToken: 't' })
    renderPrompt()

    let waiting!: Promise<void>
    act(() => {
      waiting = requestTwoStep('SESSION')
    })

    await userEvent.click(screen.getByRole('button', { name: 'Use a recovery code' }))
    const input = screen.getByLabelText('Recovery code')
    await userEvent.type(input, 'abcde-23456')
    await userEvent.click(screen.getByRole('button', { name: 'Verify' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('That recovery code is not right, or it has been used.')
    expect(api.verifyTwoStep).toHaveBeenLastCalledWith({ recoveryCode: 'ABCDE-23456' })

    await userEvent.type(screen.getByLabelText('Recovery code'), 'FGHJK-34567')
    await userEvent.click(screen.getByRole('button', { name: 'Verify' }))
    await expect(waiting).resolves.toBeUndefined()
  })

  it('does not let a short code be sent', async () => {
    renderPrompt()
    act(() => {
      void requestTwoStep('SESSION').catch(() => undefined)
    })

    await userEvent.type(screen.getByLabelText('Code from your authenticator app'), '12345')
    expect(screen.getByRole('button', { name: 'Verify' })).toBeDisabled()
  })
})

describe('asking with no prompt to show it', () => {
  it('is answered no at once rather than waiting for ever', async () => {
    await expect(requestTwoStep('SESSION')).rejects.toBeInstanceOf(TwoStepCancelled)
  })
})
