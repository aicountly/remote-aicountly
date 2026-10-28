/**
 * Connect inside Remote: when it is mounted and with what, how the Remote scope
 * reaches it, that it is gone in a live session, and that Remote is untouched
 * when Connect never arrives.
 *
 * loader.js is replaced at the module boundary (loader.test.ts covers the
 * script itself); RemoteProvider is replaced with a scope the test controls.
 */

import { act, cleanup, render } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { CompanySummary, ScopeType } from '../types/remote'
import type { ConnectApi, ConnectInitOptions } from './types'

let loaded: ConnectApi | null = null

interface FakeRemote {
  status: 'loading' | 'ready' | 'error'
  scopeType: ScopeType
  companyId: number | null
  bootstrap: { companies: CompanySummary[] } | null
}

const companies: CompanySummary[] = [
  { companyId: 77, name: 'Acme', isCompanyAdmin: true, roleKey: 'admin', branchId: 2, financialYearId: 5 },
  { companyId: 78, name: 'Beta', isCompanyAdmin: false, roleKey: 'member', branchId: null, financialYearId: null },
]

let remote: FakeRemote = { status: 'ready', scopeType: 'PERSONAL', companyId: null, bootstrap: { companies } }

vi.mock('./loader', () => ({
  resolveConnectOrigin: () => 'https://connect.example.test',
  loadConnect: vi.fn(async () => loaded),
}))

vi.mock('../app/RemoteProvider', () => ({
  useRemote: () => remote,
}))

vi.mock('../auth/portal', () => ({ sesKeyForEmbed: vi.fn(async () => 'ses-key') }))

const { loadConnect } = await import('./loader')
const { sesKeyForEmbed } = await import('../auth/portal')
const connect = await import('./connect')
const { ConnectMount, CONNECT_Z_INDEX, isLiveSessionPath } = await import('./ConnectMount')

function fakeApi(mounts = true): ConnectApi & { init: ReturnType<typeof vi.fn> } {
  return {
    init: vi.fn(async () => mounts),
    update: vi.fn(),
    open: vi.fn(),
    close: vi.fn(),
    destroy: vi.fn(),
    isReady: vi.fn(() => mounts),
  }
}

function initOptions(api: ConnectApi, call = 0): ConnectInitOptions {
  return vi.mocked(api.init).mock.calls[call][0]
}

async function settle() {
  await act(async () => {
    await Promise.resolve()
    await Promise.resolve()
    await Promise.resolve()
  })
}

function shell(path = '/') {
  return (
    <MemoryRouter initialEntries={[path]}>
      <ConnectMount />
    </MemoryRouter>
  )
}

beforeEach(() => {
  remote = { status: 'ready', scopeType: 'PERSONAL', companyId: null, bootstrap: { companies } }
  vi.mocked(loadConnect).mockClear()
})

afterEach(() => {
  cleanup()
  connect.stopConnect()
  loaded = null
})

describe('the lifecycle', () => {
  it("mounts after sign-in, bottom-right, as product 'remote', with the person's session", async () => {
    loaded = fakeApi()
    render(shell())
    await settle()

    expect(loadConnect).toHaveBeenCalledWith('https://connect.example.test', undefined)
    const options = initOptions(loaded)
    expect(options.getSesKey).toBe(sesKeyForEmbed)
    expect(options).toMatchObject({ product: 'remote', position: 'bottom-right', zIndex: CONNECT_Z_INDEX })
    expect(options.advisorUrl).toMatch(/^https:\/\/advisor\.(gh\.)?aicountly\.com$/)
    expect(connect.getConnectStatus()).toBe('ready')
  })

  it('Personal scope passes no company at all', async () => {
    loaded = fakeApi()
    render(shell())
    await settle()

    const options = initOptions(loaded)
    expect(options).not.toHaveProperty('companyId')
    expect(options).not.toHaveProperty('fyId')
    expect(options).not.toHaveProperty('boId')
  })

  it("an organisation scope passes its company only, never the year and branch in the company's bootstrap entry", async () => {
    // Acme's entry carries financial year 5 and branch 2, left from a launch in another product.
    remote = { ...remote, scopeType: 'COMPANY', companyId: 77 }
    loaded = fakeApi()
    render(shell())
    await settle()

    const options = initOptions(loaded)
    expect(options.companyId).toBe(77)
    expect(options).not.toHaveProperty('fyId')
    expect(options).not.toHaveProperty('boId')
  })

  it('waits for the bootstrap, so the first init() already carries the organisation', async () => {
    remote = { status: 'loading', scopeType: 'PERSONAL', companyId: null, bootstrap: null }
    loaded = fakeApi()
    const view = render(shell())
    await settle()
    expect(loadConnect).not.toHaveBeenCalled()

    remote = { status: 'ready', scopeType: 'COMPANY', companyId: 77, bootstrap: { companies } }
    view.rerender(shell())
    await settle()
    expect(initOptions(loaded)).toMatchObject({ companyId: 77 })
    expect(loaded.update).not.toHaveBeenCalled()
  })

  it('passes an organisation switch on with update(): the company, no year and no branch', async () => {
    remote = { ...remote, scopeType: 'COMPANY', companyId: 77 }
    loaded = fakeApi()
    const view = render(shell())
    await settle()
    expect(loaded.update).not.toHaveBeenCalled()

    remote = { ...remote, scopeType: 'COMPANY', companyId: 78 }
    view.rerender(shell())
    await settle()
    expect(loaded.update).toHaveBeenLastCalledWith({ companyId: 78, fyId: null, boId: 0 })

    remote = { ...remote, scopeType: 'PERSONAL', companyId: null }
    view.rerender(shell())
    await settle()
    expect(loaded.update).toHaveBeenLastCalledWith({ companyId: null, fyId: null, boId: 0 })

    // Back to Acme: still no year or branch from its bootstrap entry.
    remote = { ...remote, scopeType: 'COMPANY', companyId: 77 }
    view.rerender(shell())
    await settle()
    expect(loaded.update).toHaveBeenLastCalledWith({ companyId: 77, fyId: null, boId: 0 })

    // A re-render with the same scope is not a change.
    view.rerender(shell())
    await settle()
    expect(loaded.update).toHaveBeenCalledTimes(3)
    expect(loaded.init).toHaveBeenCalledTimes(1)
  })

  it('a scope chosen while loader.js is still loading goes into init()', async () => {
    const api = fakeApi()
    let release: (value: ConnectApi | null) => void = () => undefined
    vi.mocked(loadConnect).mockImplementationOnce(() => new Promise((resolve) => (release = resolve)))

    const start = connect.startConnect('https://connect.example.test', { getSesKey: async () => null, product: 'remote' })
    connect.updateConnect({ companyId: 12, fyId: null, boId: 0 })
    expect(api.update).not.toHaveBeenCalled()
    release(api)

    await expect(start).resolves.toBe(true)
    expect(initOptions(api)).toMatchObject({ companyId: 12, fyId: null, boId: 0 })
  })

  it('destroys the widget on sign-out (the shell unmounting)', async () => {
    loaded = fakeApi()
    const view = render(shell())
    await settle()

    view.unmount()
    expect(loaded.destroy).toHaveBeenCalledTimes(1)
    expect(connect.getConnectStatus()).toBe('off')
  })

  it('a sign-out before Connect arrived never mounts it', async () => {
    const api = fakeApi()
    let release: (value: ConnectApi | null) => void = () => undefined
    vi.mocked(loadConnect).mockImplementationOnce(() => new Promise((resolve) => (release = resolve)))

    const view = render(shell())
    await settle()
    view.unmount()
    release(api)
    await settle()

    expect(api.init).not.toHaveBeenCalled()
    expect(connect.getConnectStatus()).toBe('off')
  })
})

describe('live sessions', () => {
  it('knows the session room and a guest invitation, not the in-shell join form', () => {
    expect(isLiveSessionPath('/room/0b3c2b8e-1111-4222-8333-444455556666')).toBe(true)
    expect(isLiveSessionPath('/join/abc123')).toBe(true)
    expect(isLiveSessionPath('/join')).toBe(false)
    expect(isLiveSessionPath('/')).toBe(false)
    expect(isLiveSessionPath('/sessions/0b3c2b8e-1111-4222-8333-444455556666')).toBe(false)
  })

  it('never mounts the widget on a session route: no launcher and no call engine over the viewer', async () => {
    loaded = fakeApi()
    render(shell('/room/0b3c2b8e-1111-4222-8333-444455556666'))
    await settle()

    expect(loadConnect).not.toHaveBeenCalled()
    expect(loaded.init).not.toHaveBeenCalled()
    expect(connect.getConnectStatus()).toBe('off')
  })

  it('takes the widget down on entering a session and brings it back on leaving', async () => {
    loaded = fakeApi()
    const view = render(shell('/'))
    await settle()
    expect(loaded.init).toHaveBeenCalledTimes(1)

    view.rerender(
      <MemoryRouter key="room" initialEntries={['/room/0b3c2b8e-1111-4222-8333-444455556666']}>
        <ConnectMount />
      </MemoryRouter>,
    )
    await settle()
    expect(loaded.destroy).toHaveBeenCalledTimes(1)
    expect(connect.getConnectStatus()).toBe('off')

    view.rerender(
      <MemoryRouter key="back" initialEntries={['/sessions']}>
        <ConnectMount />
      </MemoryRouter>,
    )
    await settle()
    expect(loaded.init).toHaveBeenCalledTimes(2)
    expect(connect.getConnectStatus()).toBe('ready')
  })
})

describe('a Connect that is not there', () => {
  it('leaves Remote as it was when loader.js could not be loaded', async () => {
    loaded = null
    expect(() => render(shell())).not.toThrow()
    await settle()
    expect(connect.getConnectStatus()).toBe('unavailable')
  })

  it("is unavailable when the widget's init() says it could not mount", async () => {
    loaded = fakeApi(false)
    render(shell())
    await settle()
    expect(connect.getConnectStatus()).toBe('unavailable')
  })

  it('a widget that throws on update() never throws into Remote', async () => {
    remote = { ...remote, scopeType: 'COMPANY', companyId: 77 }
    loaded = fakeApi()
    vi.mocked(loaded.update).mockImplementation(() => {
      throw new Error('widget broke')
    })
    const view = render(shell())
    await settle()

    remote = { ...remote, companyId: 78 }
    expect(() => view.rerender(shell())).not.toThrow()
    await settle()
  })

  it('gives up on an init() that never settles', async () => {
    vi.useFakeTimers()
    try {
      const api = fakeApi()
      api.init.mockImplementation(() => new Promise<boolean>(() => undefined))
      vi.mocked(loadConnect).mockImplementationOnce(async () => api)

      const start = connect.startConnect('https://connect.example.test', { getSesKey: async () => null })
      await vi.advanceTimersByTimeAsync(connect.INIT_TIMEOUT_MS)
      await expect(start).resolves.toBe(false)
      expect(connect.getConnectStatus()).toBe('unavailable')
    } finally {
      vi.useRealTimers()
    }
  })
})

describe('connectScopeFrom', () => {
  it('only a COMPANY scope carries a company, and it carries nothing else', () => {
    expect(connect.connectScopeFrom('PERSONAL', 77)).toEqual({ companyId: null })
    expect(connect.connectScopeFrom('AICOUNTLY_SUPPORT', 77)).toEqual({ companyId: null })
    expect(connect.connectScopeFrom('COMPANY', null)).toEqual({ companyId: null })
    expect(connect.connectScopeFrom('COMPANY', 77)).toEqual({ companyId: 77 })
  })

  it('init() gets the company alone; update() also clears any year and branch', () => {
    expect(connect.initialScope({ companyId: 77 })).toEqual({ companyId: 77 })
    expect(connect.initialScope({ companyId: null })).toEqual({})
    expect(connect.scopeUpdate({ companyId: 77 })).toEqual({ companyId: 77, fyId: null, boId: 0 })
    expect(connect.scopeUpdate({ companyId: null })).toEqual({ companyId: null, fyId: null, boId: 0 })
  })
})
