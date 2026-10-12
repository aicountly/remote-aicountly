/**
 * Loading Connect's loader.js: from the right origin, once, and never at Remote's
 * expense — a failure, a hang or a loader that did not register all resolve
 * null, with one warning at most.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { LOADER_PATH, loadConnect, originOf, readConnectGlobal, resetConnectLoader, resolveConnectOrigin } from './loader'
import type { ConnectApi } from './types'

function fakeApi(): ConnectApi {
  return {
    init: vi.fn(async () => true),
    update: vi.fn(),
    open: vi.fn(),
    close: vi.fn(),
    destroy: vi.fn(),
    isReady: vi.fn(() => true),
    version: '1',
  }
}

/** Scripts the loader tried to add — intercepted, so nothing is fetched. */
let appended: HTMLScriptElement[] = []

beforeEach(() => {
  resetConnectLoader()
  appended = []
  vi.spyOn(document.head, 'appendChild').mockImplementation(<T extends Node>(node: T): T => {
    appended.push(node as unknown as HTMLScriptElement)
    return node
  })
  vi.spyOn(console, 'warn').mockImplementation(() => undefined)
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
  delete window.AICountlyChat
  delete window.AICountlyConnect
  resetConnectLoader()
})

describe('resolveConnectOrigin', () => {
  it('derives Connect from the host, like every sibling product', () => {
    expect(resolveConnectOrigin('remote.aicountly.com', undefined)).toBe('https://connect.aicountly.com')
    expect(resolveConnectOrigin('remote.gh.aicountly.com', undefined)).toBe('https://connect.gh.aicountly.com')
    expect(resolveConnectOrigin('localhost', '')).toBe('https://connect.gh.aicountly.com')
  })

  it('takes VITE_CONNECT_ORIGIN as an origin only, and "off" switches it off', () => {
    expect(resolveConnectOrigin('remote.aicountly.com', 'http://localhost:5174/some/path')).toBe('http://localhost:5174')
    expect(resolveConnectOrigin('remote.aicountly.com', 'off')).toBeNull()
    expect(resolveConnectOrigin('remote.aicountly.com', 'javascript:alert(1)')).toBe('https://connect.aicountly.com')
    expect(originOf('data:text/javascript,1')).toBeNull()
  })
})

describe('loadConnect', () => {
  it('adds one async script for loader.js and resolves with the API it registers', async () => {
    const pending = loadConnect('https://connect.example.test')
    expect(appended).toHaveLength(1)
    expect(appended[0].src).toBe(`https://connect.example.test${LOADER_PATH}`)
    expect(appended[0].async).toBe(true)

    const api = fakeApi()
    window.AICountlyChat = api
    appended[0].onload?.(new Event('load'))

    await expect(pending).resolves.toBe(api)
    // Asked again (a second mount, a StrictMode re-run): the same answer, no second tag.
    await expect(loadConnect('https://connect.example.test')).resolves.toBe(api)
    expect(appended).toHaveLength(1)
    expect(console.warn).not.toHaveBeenCalled()
  })

  it('reads the AICountlyConnect alias too', () => {
    const api = fakeApi()
    window.AICountlyConnect = api
    expect(readConnectGlobal()).toBe(api)
  })

  it('resolves null when the script fails to load, and warns once however often it is asked', async () => {
    const pending = loadConnect('https://connect.example.test')
    appended[0].onerror?.(new Event('error'))

    await expect(pending).resolves.toBeNull()
    await expect(loadConnect('https://connect.example.test')).resolves.toBeNull()
    await expect(loadConnect('https://connect.example.test')).resolves.toBeNull()
    expect(appended).toHaveLength(1)
    expect(console.warn).toHaveBeenCalledTimes(1)
  })

  it('resolves null when loader.js loads but registers nothing', async () => {
    const pending = loadConnect('https://connect.example.test')
    appended[0].onload?.(new Event('load'))
    await expect(pending).resolves.toBeNull()
  })

  it('gives up after the timeout: a hanging Connect is a Connect that is not there', async () => {
    vi.useFakeTimers()
    const pending = loadConnect('https://connect.example.test', 5_000)
    vi.advanceTimersByTime(5_000)
    await expect(pending).resolves.toBeNull()

    // A late arrival changes nothing: the answer was given.
    window.AICountlyChat = fakeApi()
    appended[0].onload?.(new Event('load'))
    await expect(loadConnect('https://connect.example.test')).resolves.toBeNull()
  })

  it('a script that cannot even be created resolves null without throwing', async () => {
    vi.spyOn(document, 'createElement').mockImplementationOnce(() => {
      throw new Error('blocked')
    })
    await expect(loadConnect('https://connect.example.test')).resolves.toBeNull()
    expect(console.warn).toHaveBeenCalledTimes(1)
  })

  it('loads nothing without an origin', async () => {
    await expect(loadConnect(null)).resolves.toBeNull()
    expect(appended).toHaveLength(0)
  })
})
