import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { About, AgentConfig } from '../../types/agent'

/**
 * The About card is what a support engineer asks for — and, since several
 * builds go out as one version, the only place somebody can see which build
 * they are running.
 */

const bridge = vi.hoisted(() => ({
  openUrl: vi.fn<(url: string) => Promise<void>>(),
}))

vi.mock('../../services/tauri', () => bridge)

const { SettingsPage } = await import('./SettingsPage')

const CONFIG: AgentConfig = {
  apiBaseUrl: 'https://remote.aicountly.com/api',
  portalUrl: 'https://my.aicountly.com',
  presenceIntervalSeconds: 60,
  runInBackground: true,
  startAtLogin: true,
  captureQuality: 'adaptive',
}

function about(overrides: Partial<About> = {}): About {
  return {
    product: 'AICOUNTLY Remote',
    version: '1.0.0',
    build: '202610301030',
    platform: 'Windows',
    supported: true,
    supportsHosting: true,
    supportsCompanion: true,
    stopShortcut: 'Ctrl+Alt+Shift+S',
    keyStorage: 'Windows DPAPI (machine scope)',
    logFile: null,
    service: {
      running: true,
      version: '1.0.0',
      enrolled: true,
      deviceUuid: null,
      keyFingerprint: null,
      unattendedEnabled: false,
      detail: null,
    },
    privacyPolicyUrl: 'https://aicountly.com/privacy-policy',
    ...overrides,
  }
}

beforeEach(() => {
  bridge.openUrl.mockReset().mockResolvedValue(undefined)
})

describe('the About card', () => {
  it('shows the build, the same number as in the installer’s name', () => {
    render(<SettingsPage config={CONFIG} about={about()} onSave={vi.fn()} />)

    expect(screen.getByText('202610301030')).toBeInTheDocument()
  })

  it('says so when this is a local build with no number', () => {
    render(<SettingsPage config={CONFIG} about={about({ build: null })} onSave={vi.fn()} />)

    expect(screen.getByText('Local build')).toBeInTheDocument()
  })

  it('opens the privacy policy in the browser', async () => {
    render(<SettingsPage config={CONFIG} about={about()} onSave={vi.fn()} />)

    await userEvent.click(screen.getByRole('button', { name: 'Privacy policy' }))

    expect(bridge.openUrl).toHaveBeenCalledWith('https://aicountly.com/privacy-policy')
  })
})
