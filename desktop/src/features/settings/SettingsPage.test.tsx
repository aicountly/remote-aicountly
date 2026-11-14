import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { About, AgentConfig, CodecChoice, CodecStatus, CodecView } from '../../types/agent'

/**
 * The About card is what a support engineer asks for — and, since several
 * builds go out as one version, the only place somebody can see which build
 * they are running.
 *
 * The Video codec section is where Cisco's licence for its OpenH264 binary is
 * met in the window (docs/legal/OPENH264.md): the person can switch it off
 * and on, the switch says "OpenH264 Video Codec provided by Cisco Systems,
 * Inc.", and the licence text is in About.
 */

const bridge = vi.hoisted(() => ({
  openUrl: vi.fn<(url: string) => Promise<void>>(),
  showLogFolder: vi.fn<() => Promise<void>>(),
  codecStatus: vi.fn<() => Promise<CodecView>>(),
  onCodecChange: vi.fn<(handler: (codec: CodecView) => void) => Promise<() => void>>(),
  setCodecEnabled: vi.fn<(enabled: boolean) => Promise<CodecChoice>>(),
  installCodec: vi.fn<() => Promise<CodecView>>(),
  removeCodec: vi.fn<() => Promise<CodecView>>(),
}))

vi.mock('../../services/tauri', () => bridge)

const { SettingsPage } = await import('./SettingsPage')
const { OPENH264_LICENCE } = await import('./licences')

const ATTRIBUTION = 'OpenH264 Video Codec provided by Cisco Systems, Inc.'

const CONFIG: AgentConfig = {
  apiBaseUrl: 'https://remote.aicountly.com/api',
  portalUrl: 'https://my.aicountly.com',
  presenceIntervalSeconds: 60,
  runInBackground: true,
  startAtLogin: true,
  captureQuality: 'adaptive',
  openh264Enabled: true,
  allowSecureDesktopUnattended: false,
  shareSound: false,
}

const READY: CodecStatus = {
  state: 'ready',
  version: '2.6.0',
  path: 'C:\\Users\\rahul\\AppData\\Local\\AICOUNTLY\\Remote\\codecs\\openh264-2.6.0-win64.dll',
  sizeBytes: 1_258_291,
}

function codec(status: CodecStatus, enabled = status.state !== 'disabled'): CodecView {
  return {
    enabled,
    status,
    attribution: ATTRIBUTION,
    version: '2.6.0',
    file: 'openh264-2.6.0-win64.dll',
  }
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
    supportsSound: true,
    supportsSecureDesktop: true,
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

/** The handler the section registered for the Rust side's codec events. */
let pushCodec: ((codec: CodecView) => void) | null = null

beforeEach(() => {
  pushCodec = null
  bridge.openUrl.mockReset().mockResolvedValue(undefined)
  bridge.showLogFolder.mockReset().mockResolvedValue(undefined)
  bridge.codecStatus.mockReset().mockResolvedValue(codec(READY))
  bridge.onCodecChange.mockReset().mockImplementation(async (handler) => {
    pushCodec = handler
    return () => undefined
  })
  bridge.setCodecEnabled.mockReset()
  bridge.installCodec.mockReset()
  bridge.removeCodec.mockReset()
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

  /**
   * Licence condition 4: the whole of Cisco's text, the condition that asks
   * for it included, where licensing information is shown.
   */
  it('shows Cisco’s licence for OpenH264 in full under Third-party licences', async () => {
    render(<SettingsPage config={CONFIG} about={about()} onSave={vi.fn()} />)

    const licence = screen.getByLabelText('OpenH264 binary licence')
    expect(licence).not.toBeVisible()

    await userEvent.click(screen.getByText('Third-party licences'))

    expect(licence).toBeVisible()
    expect(licence.textContent).toBe(OPENH264_LICENCE)
    for (const passage of [
      'About The Cisco-Provided Binary of OpenH264 Video Codec',
      'Copyright © 2014 Cisco Systems, Inc.',
      'AVC/H.264 Patent Portfolio License Notice',
      'The end user must have the ability to control (e.g., to enable, disable, or re-enable)',
      `"${ATTRIBUTION}"`,
      'must reproduce all of the above text, as well as this last condition',
    ]) {
      expect(licence.textContent).toContain(passage)
    }
  })
})

describe('the Video codec section', () => {
  /** Licence condition 3: the exact sentence, as the switch's own label. */
  it('labels the switch with Cisco’s exact sentence', async () => {
    render(<SettingsPage config={CONFIG} about={about()} onSave={vi.fn()} />)

    const toggle = await screen.findByRole('switch', { name: ATTRIBUTION })
    expect(toggle).toBeChecked()

    const label = document.getElementById('openh264-attribution')
    expect(label?.textContent).toBe(ATTRIBUTION)
    expect(label?.closest('label')).toContainElement(toggle)
  })

  /**
   * Sound is the person at the computer's to switch on, and off — and the sentence beside the switch
   * promises that switching it off stops the sound at once. It used to wait for Save settings. It is
   * saved the moment it is ticked or unticked, onto the settings as they were last saved: whatever
   * else in the form is half typed is not saved with it.
   */
  it('offers the sound switch, off, and saves it the moment it is switched', async () => {
    const onSave = vi.fn()
    render(<SettingsPage config={CONFIG} about={about()} onSave={onSave} />)

    const sound = screen.getByRole('checkbox', { name: /Share this computer’s sound/ })
    expect(sound).not.toBeChecked()

    // Something else in the form, changed and not saved.
    await userEvent.selectOptions(screen.getByLabelText('Screen quality'), 'high_quality')

    await userEvent.click(sound)

    expect(onSave).toHaveBeenCalledTimes(1)
    expect(onSave).toHaveBeenLastCalledWith({ ...CONFIG, shareSound: true })

    // And Save settings then saves the rest, with the switch as it now is.
    await userEvent.click(screen.getByRole('button', { name: 'Save settings' }))

    expect(onSave).toHaveBeenLastCalledWith({ ...CONFIG, captureQuality: 'high_quality', shareSound: true })
  })

  /** A button that changes nothing on screen leaves somebody wondering whether it did anything. */
  it('says Saved once a save has gone through, and not when it has not', async () => {
    const onSave = vi.fn<(config: AgentConfig) => Promise<boolean>>().mockResolvedValue(false)
    render(<SettingsPage config={CONFIG} about={about()} onSave={onSave} />)

    await userEvent.click(screen.getByRole('button', { name: 'Save settings' }))
    expect(screen.queryByText('Saved')).not.toBeInTheDocument()

    onSave.mockResolvedValue(true)
    await userEvent.click(screen.getByRole('button', { name: 'Save settings' }))
    expect(await screen.findByText('Saved')).toBeInTheDocument()
  })

  /** The address that signs the computer out when it is changed is not the first thing on the page. */
  it('keeps the address this computer connects to under Advanced', () => {
    render(<SettingsPage config={CONFIG} about={about()} onSave={vi.fn()} />)

    const address = screen.getByLabelText('AICOUNTLY Remote address')

    expect(address.closest('details')).not.toBeNull()
    expect(address.closest('details')).not.toHaveAttribute('open')
  })

  /**
   * A Mac shows its own screen and has neither Windows' prompts nor its background service, and was
   * offered a setting for the first and told the second was "Not running".
   */
  it('offers a Mac nothing that is Windows’', () => {
    const mac = about({
      platform: 'macOS',
      supportsSecureDesktop: false,
      supportsSound: false,
      service: { ...about().service, running: false, detail: 'the AICOUNTLY Remote background service is not running' },
    })

    const { rerender } = render(<SettingsPage config={CONFIG} about={mac} onSave={vi.fn()} />)

    expect(screen.queryByText(/administrator prompts and the lock screen/)).not.toBeInTheDocument()
    expect(screen.queryByText('Background service')).not.toBeInTheDocument()
    expect(screen.queryByText(/background service is not running/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/Task Manager/)).not.toBeInTheDocument()
    expect(screen.getByText(/in the menu bar/)).toBeInTheDocument()

    rerender(<SettingsPage config={CONFIG} about={about()} onSave={vi.fn()} />)

    expect(screen.getByText(/administrator prompts and the lock screen/)).toBeInTheDocument()
    expect(screen.getByText('Background service')).toBeInTheDocument()
    expect(screen.getByText(/Task Manager/)).toBeInTheDocument()
  })

  /** "Can you send me the log" used to mean finding a path by hand. */
  it('opens the folder the log is in', async () => {
    render(
      <SettingsPage
        config={CONFIG}
        about={about({ logFile: 'C:\\ProgramData\\AICOUNTLY\\Remote\\logs\\agent.log' })}
        onSave={vi.fn()}
      />,
    )

    await userEvent.click(screen.getByRole('button', { name: 'Open the folder' }))

    expect(bridge.showLogFolder).toHaveBeenCalledOnce()
  })

  it('does not offer the sound switch on a computer that cannot send sound', () => {
    render(<SettingsPage config={CONFIG} about={about({ supportsSound: false })} onSave={vi.fn()} />)

    expect(screen.queryByRole('checkbox', { name: /Share this computer’s sound/ })).toBeNull()
  })

  it('shows the installed version and size', async () => {
    render(<SettingsPage config={CONFIG} about={about()} onSave={vi.fn()} />)

    expect(await screen.findByText('Installed')).toBeInTheDocument()
    expect(screen.getByText('OpenH264 2.6.0 · 1.2 MB')).toBeInTheDocument()
  })

  /** Licence condition 2: off, and on again, each through the Rust side. */
  it('switches the codec off and on again through set_codec_enabled', async () => {
    const onConfigChange = vi.fn()
    bridge.setCodecEnabled.mockImplementation(async (enabled) => ({
      config: { ...CONFIG, openh264Enabled: enabled },
      codec: enabled
        ? codec({ state: 'downloading', receivedBytes: 0, totalBytes: null })
        : codec({ state: 'disabled' }, false),
    }))

    render(<SettingsPage config={CONFIG} about={about()} onSave={vi.fn()} onConfigChange={onConfigChange} />)

    const toggle = await screen.findByRole('switch', { name: ATTRIBUTION })
    await userEvent.click(toggle)

    expect(bridge.setCodecEnabled).toHaveBeenLastCalledWith(false)
    await waitFor(() => expect(toggle).not.toBeChecked())
    expect(screen.getByText(/Off, and not on this computer/)).toBeInTheDocument()
    expect(onConfigChange).toHaveBeenLastCalledWith({ ...CONFIG, openh264Enabled: false })

    await userEvent.click(toggle)

    expect(bridge.setCodecEnabled).toHaveBeenLastCalledWith(true)
    await waitFor(() => expect(toggle).toBeChecked())
    expect(screen.getByText(/Downloading from Cisco/)).toBeInTheDocument()
    expect(bridge.setCodecEnabled).toHaveBeenCalledTimes(2)
    expect(bridge.installCodec).not.toHaveBeenCalled()
    expect(bridge.removeCodec).not.toHaveBeenCalled()
  })

  it('follows a download as the Rust side reports it', async () => {
    bridge.codecStatus.mockResolvedValue(codec({ state: 'not_installed' }))
    render(<SettingsPage config={CONFIG} about={about()} onSave={vi.fn()} />)

    expect(await screen.findByText('Not downloaded yet.')).toBeInTheDocument()
    await waitFor(() => expect(pushCodec).not.toBeNull())

    act(() => pushCodec?.(codec({ state: 'downloading', receivedBytes: 300_000, totalBytes: 600_000 })))
    expect(screen.getByText('Downloading from Cisco… 50%')).toBeInTheDocument()

    act(() => pushCodec?.(codec(READY)))
    expect(screen.getByText('Installed')).toBeInTheDocument()
  })

  it('says why a download failed and retries through install_codec', async () => {
    bridge.codecStatus.mockResolvedValue(
      codec({ state: 'failed', reason: 'Cisco’s server could not be reached: it did not answer in time.' }),
    )
    bridge.installCodec.mockResolvedValue(codec({ state: 'downloading', receivedBytes: 0, totalBytes: null }))

    render(<SettingsPage config={CONFIG} about={about()} onSave={vi.fn()} />)

    const failure = await screen.findByRole('alert')
    expect(within(failure).getByText(/did not answer in time/)).toBeInTheDocument()

    await userEvent.click(within(failure).getByRole('button', { name: 'Retry' }))

    expect(bridge.installCodec).toHaveBeenCalledOnce()
    expect(await screen.findByText(/Downloading from Cisco/)).toBeInTheDocument()
  })

  it('offers Download now when it is switched on and not here', async () => {
    bridge.codecStatus.mockResolvedValue(codec({ state: 'not_installed' }))
    bridge.installCodec.mockResolvedValue(codec(READY))

    render(<SettingsPage config={CONFIG} about={about()} onSave={vi.fn()} />)

    await userEvent.click(await screen.findByRole('button', { name: 'Download now' }))

    expect(bridge.installCodec).toHaveBeenCalledOnce()
    expect(await screen.findByText('Installed')).toBeInTheDocument()
  })

  it('shows an error from the switch and where the codec stands now', async () => {
    bridge.setCodecEnabled.mockRejectedValue('the settings could not be saved: disk full')
    bridge.codecStatus
      .mockResolvedValueOnce(codec(READY))
      .mockResolvedValueOnce(codec({ state: 'disabled' }, false))

    render(<SettingsPage config={CONFIG} about={about()} onSave={vi.fn()} />)

    await userEvent.click(await screen.findByRole('switch', { name: ATTRIBUTION }))

    expect(await screen.findByText('the settings could not be saved: disk full')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByRole('switch', { name: ATTRIBUTION })).not.toBeChecked())
  })

  it('has no switch on a computer Cisco publishes nothing for', async () => {
    bridge.codecStatus.mockResolvedValue(codec({ state: 'unsupported', platform: 'linux aarch64' }))

    render(<SettingsPage config={CONFIG} about={about()} onSave={vi.fn()} />)

    expect(await screen.findByText(/does not publish its OpenH264 codec for this computer/)).toBeInTheDocument()
    expect(screen.queryByRole('switch')).not.toBeInTheDocument()
  })
})
