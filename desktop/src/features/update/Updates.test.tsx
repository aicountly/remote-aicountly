import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { UpdateInfo, UpdateStarted } from '../../types/agent'

/**
 * The update check, from the window's side: a prompt when it opens, Skip that
 * really skips, a banner that stays, and nothing at all when there is no
 * update or the check could not be made.
 */

const bridge = vi.hoisted(() => ({
  checkForUpdate: vi.fn<() => Promise<UpdateInfo | null>>(),
  installUpdate: vi.fn<() => Promise<UpdateStarted>>(),
  openUrl: vi.fn<(url: string) => Promise<void>>(),
}))

vi.mock('../../services/tauri', () => bridge)

const { Updates } = await import('./Updates')

const INSTALLER_URL = 'https://downloads.aicountly.com/remote/1.1.0/Aicountly-Remote-1.1.0-x64-Setup.exe'
const DOWNLOAD_PAGE = 'https://aicountly.com/remote#download'

function update(overrides: Partial<UpdateInfo> = {}): UpdateInfo {
  return {
    currentVersion: '1.0.0',
    latestVersion: '1.1.0',
    installer: {
      file: 'Aicountly-Remote-1.1.0-x64-Setup.exe',
      url: INSTALLER_URL,
      size: 123,
      sha256: 'a'.repeat(64),
    },
    downloadPageUrl: DOWNLOAD_PAGE,
    ...overrides,
  }
}

beforeEach(() => {
  bridge.checkForUpdate.mockReset()
  bridge.installUpdate.mockReset()
  bridge.openUrl.mockReset().mockResolvedValue(undefined)
})

describe('the update check when the window opens', () => {
  it('prompts with Install update and Skip when a newer version is published', async () => {
    bridge.checkForUpdate.mockResolvedValue(update())
    render(<Updates sessionActive={false} />)

    const dialog = await screen.findByRole('dialog', { name: 'Update available' })
    expect(dialog).toHaveTextContent('AICOUNTLY Remote 1.1.0 is available. You have 1.0.0.')
    expect(screen.getByRole('button', { name: 'Install update' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Skip' })).toBeInTheDocument()
    expect(bridge.checkForUpdate).toHaveBeenCalledTimes(1)
  })

  it('shows nothing at all when this is the latest version', async () => {
    bridge.checkForUpdate.mockResolvedValue(null)
    const { container } = render(<Updates sessionActive={false} />)

    await waitFor(() => expect(bridge.checkForUpdate).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()
  })

  it('shows nothing, and does not throw, when the check could not be made', async () => {
    // The real bridge turns a failed invoke into null; a rejection reaching
    // here would be a bug there, and must still not take the window down.
    bridge.checkForUpdate.mockResolvedValue(null)
    const { container } = render(<Updates sessionActive={false} />)

    await waitFor(() => expect(bridge.checkForUpdate).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })
})

describe('Skip', () => {
  it('closes the prompt and leaves a banner with Update now', async () => {
    bridge.checkForUpdate.mockResolvedValue(update())
    render(<Updates sessionActive={false} />)

    await userEvent.click(await screen.findByRole('button', { name: 'Skip' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.getByText('A new version of AICOUNTLY Remote is available')).toBeInTheDocument()
    expect(screen.getByText(/Version 1\.1\.0 is ready to install/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Update now' })).toBeInTheDocument()
    expect(bridge.installUpdate).not.toHaveBeenCalled()
  })

  it('still lets the person update later, from the banner', async () => {
    bridge.checkForUpdate.mockResolvedValue(update())
    bridge.installUpdate.mockResolvedValue({ version: '1.1.0', platform: 'windows', closesInSeconds: 4 })
    render(<Updates sessionActive={false} />)

    await userEvent.click(await screen.findByRole('button', { name: 'Skip' }))
    await userEvent.click(screen.getByRole('button', { name: 'Update now' }))

    expect(bridge.installUpdate).toHaveBeenCalledTimes(1)
    expect(await screen.findByText(/The installer is starting/)).toBeInTheDocument()
  })

  it('opens the download page from the banner', async () => {
    bridge.checkForUpdate.mockResolvedValue(update())
    render(<Updates sessionActive={false} />)

    await userEvent.click(await screen.findByRole('button', { name: 'Skip' }))
    await userEvent.click(screen.getByRole('button', { name: 'Download page' }))

    expect(bridge.openUrl).toHaveBeenCalledWith(DOWNLOAD_PAGE)
  })
})

describe('Install update', () => {
  it('downloads and opens the installer, then says what happens next on Windows', async () => {
    bridge.checkForUpdate.mockResolvedValue(update())
    let finish: (value: UpdateStarted) => void = () => undefined
    bridge.installUpdate.mockReturnValue(new Promise((resolve) => (finish = resolve)))
    render(<Updates sessionActive={false} />)

    await userEvent.click(await screen.findByRole('button', { name: 'Install update' }))

    expect(screen.getByRole('button', { name: 'Downloading…' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Skip' })).toBeDisabled()
    expect(screen.getByText(/Downloading AICOUNTLY Remote 1\.1\.0/)).toBeInTheDocument()

    finish({ version: '1.1.0', platform: 'windows', closesInSeconds: 4 })

    expect(await screen.findByText(/AICOUNTLY Remote closes in 4 seconds so it can be updated/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Install update' })).not.toBeInTheDocument()
  })

  it('tells a Mac to drag the app into Applications', async () => {
    bridge.checkForUpdate.mockResolvedValue(update())
    bridge.installUpdate.mockResolvedValue({ version: '1.1.0', platform: 'macos', closesInSeconds: 4 })
    render(<Updates sessionActive={false} />)

    await userEvent.click(await screen.findByRole('button', { name: 'Install update' }))

    expect(await screen.findByText(/Drag AICOUNTLY Remote into Applications/)).toBeInTheDocument()
  })

  it('says what went wrong, offers the download page, and lets the person carry on', async () => {
    bridge.checkForUpdate.mockResolvedValue(update())
    bridge.installUpdate.mockRejectedValue('The update could not be downloaded: connection reset')
    render(<Updates sessionActive={false} />)

    await userEvent.click(await screen.findByRole('button', { name: 'Install update' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('connection reset')

    await userEvent.click(screen.getByRole('button', { name: 'Open the download page' }))
    expect(bridge.openUrl).toHaveBeenCalledWith(DOWNLOAD_PAGE)

    // Skipping after a failure still leaves the application usable, with the banner.
    await userEvent.click(screen.getByRole('button', { name: 'Skip' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Update now' })).toBeInTheDocument()
  })

  it('sends a computer with no installer of its own to the download page', async () => {
    bridge.checkForUpdate.mockResolvedValue(update({ installer: null }))
    render(<Updates sessionActive={false} />)

    await userEvent.click(await screen.findByRole('button', { name: 'Install update' }))

    expect(bridge.installUpdate).not.toHaveBeenCalled()
    expect(bridge.openUrl).toHaveBeenCalledWith(DOWNLOAD_PAGE)
    expect(screen.getByRole('button', { name: 'Update now' })).toBeInTheDocument()
  })
})

describe('during a session', () => {
  it('shows the banner instead of a prompt that could cover a request to control this computer', async () => {
    bridge.checkForUpdate.mockResolvedValue(update())
    render(<Updates sessionActive />)

    expect(await screen.findByRole('button', { name: 'Update now' })).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })
})
