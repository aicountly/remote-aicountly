import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'

/**
 * AICOUNTLY Remote has no organisations for now (organisations.ts): it belongs
 * to the person signed in, and every session is Personal. These hold that line
 * where it used to be crossed — a company remembered from an earlier visit,
 * and a launch from another AICOUNTLY product that names one.
 */

const api = vi.hoisted(() => ({
  fetchBootstrap: vi.fn(),
  fetchEffectivePolicy: vi.fn(),
}))

vi.mock('../services/api/remote', () => api)

const { RemoteProvider, useRemote } = await import('./RemoteProvider')
const { peekLaunchContextToken, setLaunchContextToken } = await import('../services/api/client')
const { ORGANISATIONS_ENABLED } = await import('./organisations')

const POLICY = { remoteEnabled: true, scopeType: 'PERSONAL', companyId: null, companyName: null, permissions: {} }

function bootstrap(activeScope: { scopeType: string; companyId: number | null }) {
  return {
    user: { uuid: 'u-1', displayName: 'Priya', email: 'priya@example.test', isSupportAgent: false },
    companies: [{ companyId: 7, name: 'Acme Traders', isCompanyAdmin: true }],
    activeScope,
    launchContext: activeScope.companyId === null ? null : { companyId: activeScope.companyId, product: 'books' },
    policy: { ...POLICY, ...activeScope },
    metrics: {},
    recentSessions: [],
    features: {},
    realtime: { signallingConfigured: true, relayAvailable: true },
  }
}

function Probe() {
  const { status, scopeType, companyId } = useRemote()

  return <p data-testid="scope">{`${status} ${scopeType} ${String(companyId)}`}</p>
}

beforeEach(() => {
  localStorage.clear()
  setLaunchContextToken(null)
  api.fetchBootstrap.mockReset()
  api.fetchEffectivePolicy.mockReset().mockResolvedValue(POLICY)
})

afterEach(() => {
  window.history.replaceState(null, '', '/')
  localStorage.clear()
})

describe('a personal-only Remote', () => {
  it('has organisations switched off', () => {
    expect(ORGANISATIONS_ENABLED).toBe(false)
  })

  it('opens Personal even when an organisation was chosen on an earlier visit', async () => {
    localStorage.setItem('remote:scope', JSON.stringify({ scopeType: 'COMPANY', companyId: 7 }))
    api.fetchBootstrap.mockResolvedValue(bootstrap({ scopeType: 'PERSONAL', companyId: null }))

    render(
      <RemoteProvider>
        <Probe />
      </RemoteProvider>,
    )

    await waitFor(() => expect(screen.getByTestId('scope').textContent).toBe('ready PERSONAL null'))
    expect(api.fetchEffectivePolicy).not.toHaveBeenCalledWith('COMPANY', expect.anything())
  })

  it('takes a launch context out of the address without carrying its organisation into Remote', async () => {
    window.history.replaceState(null, '', '/?context=signed-context-token&tab=x')
    api.fetchBootstrap.mockResolvedValue(bootstrap({ scopeType: 'PERSONAL', companyId: null }))

    render(
      <RemoteProvider>
        <Probe />
      </RemoteProvider>,
    )

    await waitFor(() => expect(screen.getByTestId('scope').textContent).toBe('ready PERSONAL null'))
    expect(window.location.search).toBe('?tab=x')
    expect(peekLaunchContextToken()).toBeNull()
  })

  it('stays Personal even if the server answers with an organisation’s scope', async () => {
    api.fetchBootstrap.mockResolvedValue(bootstrap({ scopeType: 'COMPANY', companyId: 7 }))

    render(
      <RemoteProvider>
        <Probe />
      </RemoteProvider>,
    )

    await waitFor(() => expect(screen.getByTestId('scope').textContent).toBe('ready PERSONAL null'))
    expect(api.fetchEffectivePolicy).toHaveBeenCalledWith('PERSONAL', null)
  })
})
