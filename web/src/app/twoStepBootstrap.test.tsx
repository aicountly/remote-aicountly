import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

/**
 * Bootstrap is the one signed-in call let through before this browser has
 * passed two-step verification, so that the web can ask at once: when it says
 * two-step verification is on and this browser is not verified, the prompt
 * covers the app, and once the code is accepted the bootstrap is fetched again
 * — the first one deliberately held nothing of the person's.
 */

const api = vi.hoisted(() => ({
  fetchBootstrap: vi.fn(),
  fetchEffectivePolicy: vi.fn(),
  verifyTwoStep: vi.fn(),
}))
vi.mock('../services/api/remote', () => api)

const { RemoteProvider, useRemote } = await import('./RemoteProvider')
const { default: TwoStepPrompt } = await import('../features/twoStep/TwoStepPrompt')
const { resetTwoStepPrompt } = await import('../services/twoStepPrompt')

const POLICY = { remoteEnabled: true, scopeType: 'PERSONAL', companyId: null, companyName: null, permissions: {} }

function bootstrap(twoStep: { enabled: boolean; sessionVerified: boolean }, recent: unknown[] = []) {
  return {
    user: { uuid: 'u-1', displayName: 'Meera', email: 'meera@example.test', isSupportAgent: false },
    companies: [],
    activeScope: { scopeType: 'PERSONAL', companyId: null },
    launchContext: null,
    policy: POLICY,
    metrics: {},
    recentSessions: recent,
    features: {},
    realtime: { signallingConfigured: true, relayAvailable: true },
    twoStep: { ...twoStep, verifiedUntil: null },
  }
}

function Probe() {
  const { status, bootstrap: data } = useRemote()

  return <p data-testid="state">{`${status} ${data?.recentSessions.length ?? '-'}`}</p>
}

function renderApp() {
  render(
    <RemoteProvider>
      <Probe />
      <TwoStepPrompt onSignOut={() => undefined} />
    </RemoteProvider>,
  )
}

// A block body: an arrow returning the reset mock would hand Vitest the mock as a teardown hook.
beforeEach(() => {
  resetTwoStepPrompt()
  api.fetchBootstrap.mockReset()
  api.fetchEffectivePolicy.mockReset()
  api.verifyTwoStep.mockReset()
})

describe('a bootstrap that says this browser has not passed two-step verification', () => {
  it('asks for the code at once, keeps the app loading behind it, and fetches the bootstrap again after', async () => {
    api.fetchBootstrap
      .mockResolvedValueOnce(bootstrap({ enabled: true, sessionVerified: false }))
      .mockResolvedValueOnce(bootstrap({ enabled: true, sessionVerified: true }, [{ uuid: 's-1' }]))
    api.verifyTwoStep.mockResolvedValue({ verificationToken: 't' })

    renderApp()

    expect(await screen.findByRole('dialog', { name: 'Two-step verification' })).toBeInTheDocument()
    expect(screen.getByTestId('state')).toHaveTextContent('loading -')

    await userEvent.type(screen.getByLabelText('Code from your authenticator app'), '112233')
    await userEvent.click(screen.getByRole('button', { name: 'Verify' }))

    expect(await screen.findByText('ready 1')).toBeInTheDocument()
    expect(api.fetchBootstrap).toHaveBeenCalledTimes(2)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('does not ask anybody who has it off, or who has already passed it', async () => {
    api.fetchBootstrap.mockResolvedValue(bootstrap({ enabled: true, sessionVerified: true }))

    renderApp()

    expect(await screen.findByText('ready 0')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(api.fetchBootstrap).toHaveBeenCalledTimes(1)
  })
})
