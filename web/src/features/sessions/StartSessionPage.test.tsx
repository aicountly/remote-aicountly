import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'

import type { EffectivePolicy } from '../../types/remote'

/**
 * Starting a session: what the page says when Remote could not be loaded, and what it says
 * about control.
 *
 *   * With the start-up request failed there is no policy, and the page waited for one: a grey
 *     block, for as long as it was left open. It says what went wrong and offers to try again.
 *   * Whether a helper can control this computer is decided by what is shared, and the page that
 *     asks what to share did not mention it.
 */

const remote = vi.hoisted(() => ({
  status: 'ready' as 'ready' | 'error',
  policy: null as EffectivePolicy | null,
  desktopAgent: true,
  refresh: vi.fn(async () => undefined),
}))

vi.mock('../../app/RemoteProvider', () => ({
  useRemote: () => ({
    bootstrap: { companies: [], launchContext: null },
    policy: remote.policy,
    scopeType: 'PERSONAL',
    companyId: null,
    capabilities: { screenCapture: true, microphone: true, dataChannel: true },
    switchScope: async () => undefined,
    can: () => true,
    status: remote.status,
    error: null,
    refresh: remote.refresh,
  }),
  useDesktopAgentEnabled: () => remote.desktopAgent,
}))

vi.mock('../../services/api/remote', () => ({ createSession: vi.fn() }))

const { default: StartSessionPage, controlHintFor } = await import('./StartSessionPage')

const policy = (overrides: Partial<EffectivePolicy> = {}): EffectivePolicy =>
  ({
    remoteEnabled: true,
    companyName: null,
    allowRemoteControl: true,
    allowMicrophone: false,
    allowSafeShare: true,
    allowBrowserTab: true,
    allowApplicationWindow: true,
    allowEntireMonitor: true,
    allowedShareModes: ['SAFE_SHARE', 'BROWSER_TAB', 'APPLICATION_WINDOW', 'ENTIRE_MONITOR'],
    permissions: {},
    ...overrides,
  }) as EffectivePolicy

function renderPage() {
  return render(
    <MemoryRouter>
      <StartSessionPage />
    </MemoryRouter>,
  )
}

afterEach(() => {
  remote.status = 'ready'
  remote.policy = null
  remote.desktopAgent = true
  remote.refresh.mockClear()
})

describe('the Start page when Remote could not be loaded', () => {
  it('says so and offers to try again, rather than waiting for a policy that is not coming', async () => {
    remote.status = 'error'
    const { container } = renderPage()

    expect(container.querySelector('.skeleton')).toBeNull()

    await userEvent.click(screen.getByRole('button', { name: 'Try again' }))
    expect(remote.refresh).toHaveBeenCalledTimes(1)
  })
})

describe('what the Start page says about control', () => {
  it('says a window or a tab can be looked at but not controlled, and what to choose instead', () => {
    remote.policy = policy()
    renderPage()

    // The page opens on Safe Share.
    expect(screen.getByRole('note')).toHaveTextContent(
      'Shared this way, a helper can look but cannot control this computer. To let them control it, choose Entire screen.',
    )
  })

  it('says nothing about control where control is not offered', () => {
    remote.policy = policy({ allowRemoteControl: false })
    renderPage()
    expect(screen.queryByRole('note')).not.toBeInTheDocument()
  })

  it('says nothing about control while the desktop app is switched off for this deployment', () => {
    remote.policy = policy()
    remote.desktopAgent = false
    renderPage()
    expect(screen.queryByRole('note')).not.toBeInTheDocument()
  })

  it('has a sentence for each case, and none where an entire screen may not be shared', () => {
    const offered = { controlOffered: true, entireScreenAllowed: true, inDesktopApp: false }

    expect(controlHintFor({ ...offered, shareMode: 'APPLICATION_WINDOW' })).toMatch(/cannot control this computer/)
    expect(controlHintFor({ ...offered, shareMode: 'ENTIRE_MONITOR' })).toMatch(/keep the AICOUNTLY Remote app open/)
    expect(controlHintFor({ ...offered, shareMode: 'ENTIRE_MONITOR', inDesktopApp: true })).toMatch(
      /^A helper can ask to control this computer\./,
    )

    // Pointing at an option the account does not have would be sending somebody nowhere.
    expect(controlHintFor({ ...offered, entireScreenAllowed: false, shareMode: 'SAFE_SHARE' })).toBeNull()
    expect(controlHintFor({ ...offered, controlOffered: false, shareMode: 'SAFE_SHARE' })).toBeNull()
  })
})
