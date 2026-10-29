import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * The update check must never be the reason the window fails to start: a
 * check the Rust side could not run, or an invoke that failed outright, is
 * "no update".
 */

const core = vi.hoisted(() => ({ invoke: vi.fn() }))

vi.mock('@tauri-apps/api/core', () => core)
vi.mock('@tauri-apps/api/event', () => ({ listen: vi.fn() }))

const { checkForUpdate, installUpdate } = await import('./tauri')

beforeEach(() => {
  core.invoke.mockReset()
})

describe('checkForUpdate', () => {
  it('passes a published update through', async () => {
    const update = { currentVersion: '1.0.0', latestVersion: '1.1.0', installer: null, downloadPageUrl: 'x' }
    core.invoke.mockResolvedValue(update)

    await expect(checkForUpdate()).resolves.toEqual(update)
    expect(core.invoke).toHaveBeenCalledWith('check_for_update')
  })

  it('is null when up to date', async () => {
    core.invoke.mockResolvedValue(null)

    await expect(checkForUpdate()).resolves.toBeNull()
  })

  it('is null, not a rejection, when the call fails', async () => {
    core.invoke.mockRejectedValue(new Error('network unreachable'))

    await expect(checkForUpdate()).resolves.toBeNull()
  })
})

describe('installUpdate', () => {
  it('passes nothing about what to fetch: the Rust side decides from the manifest', async () => {
    core.invoke.mockResolvedValue({ version: '1.1.0', platform: 'windows', closesInSeconds: 4 })

    await installUpdate()

    expect(core.invoke).toHaveBeenCalledWith('install_update')
  })

  it('reports a failed install, which somebody asked for and is waiting on', async () => {
    core.invoke.mockRejectedValue('The update could not be downloaded')

    await expect(installUpdate()).rejects.toBe('The update could not be downloaded')
  })
})
