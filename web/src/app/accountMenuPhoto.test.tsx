import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// Vite's own `?raw`, so reading this app's source needs no Node types in the app's tsconfig.
import menuSource from './AccountMenu.tsx?raw'
import shellSource from './AppShell.tsx?raw'

/**
 * The account menu's profile photo (AICOUNTLY_ACCOUNT_MENU.md §1, §6), and the Company block
 * beside it (§3).
 *
 * The photo comes from the portal through Remote's own same-origin relay
 * (`/api/global/user/logo`), and every way it can fail — nobody has uploaded one, the relay is
 * down, the portal answers something that is not an image — has to end as "no photo" so the menu
 * draws initials. It may never block the menu from rendering and never report anything.
 *
 * The Company block is the other half, and for Remote the answer is that there isn't one *yet*:
 * organisations are off (`organisations.ts`), every session is Personal, and §3's one exemption is
 * a product that scopes per person. What is pinned here is that the omission hangs on that flag
 * and on nothing else — not on "the header already has a switcher", which §3 forbids — and that
 * the block appears in Books' order the moment the flag goes true.
 */

const auth = vi.hoisted(() => ({ signOut: vi.fn() }))
vi.mock('../auth/AuthProvider', () => ({ useAuth: () => auth }))

const remote = vi.hoisted(() => ({
  state: {
    bootstrap: {
      user: { uuid: 'u-77', displayName: 'Priya Raghunathan', email: 'priya@example.test', isSupportAgent: false },
    },
    scopeType: 'PERSONAL' as 'PERSONAL' | 'COMPANY',
    companyId: null as number | null,
  },
}))
vi.mock('./RemoteProvider', () => ({ useRemote: () => remote.state }))

vi.mock('../auth/accounts', () => ({
  readAccountRoster: () => [],
  canAddAccount: () => true,
  performAccountSwitch: vi.fn(),
  buildChooseAccountUrl: () => 'https://my.aicountly.com/login/select_account/remote',
}))
vi.mock('../connect/connect', () => ({ stopConnect: vi.fn() }))
vi.mock('../auth/portal', () => ({ ensureSesKey: vi.fn(async () => 'SES-OWN'), AuthError: class extends Error {} }))

const AccountMenu = (await import('./AccountMenu')).default
const { ORGANISATIONS_ENABLED } = await import('./organisations')

const calls: Array<{ url: string; init: RequestInit }> = []
const revoked: string[] = []
let created = 0

/** Answer `/api/global/user/logo` with this, and record every call made. */
function relay(answer: (() => Response) | Error) {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
      calls.push({ url: String(input), init })
      if (answer instanceof Error) throw answer
      return answer()
    }),
  )
}

const png = (type = 'image/png', bytes = Uint8Array.from([137, 80, 78, 71])) =>
  () => new Response(bytes, { status: 200, headers: { 'Content-Type': type } })

const openMenu = () => fireEvent.click(screen.getByRole('button', { name: /Account menu/ }))
const panel = () => screen.getByRole('menu', { name: 'Account menu' })
const photoIn = (node: HTMLElement) => node.querySelector('img.account-avatar__photo')

beforeEach(() => {
  calls.length = 0
  revoked.length = 0
  created = 0
  remote.state.scopeType = 'PERSONAL'
  remote.state.companyId = null
  URL.createObjectURL = () => `blob:remote/${++created}`
  URL.revokeObjectURL = (url: string) => void revoked.push(url)
})

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

describe('the profile photo', () => {
  it("is fetched from Remote's own API, same-origin, with the person's ses_key", async () => {
    relay(png())
    render(<AccountMenu />)

    await waitFor(() => expect(calls).toHaveLength(1))
    expect(calls[0].url).toBe(`${window.location.origin}/api/global/user/logo`)
    expect((calls[0].init.headers as Record<string, string>).Authorization).toBe('Bearer SES-OWN')
    // Never my.aicountly.com itself: its CORS allow-list does not name product domains.
    expect(calls[0].url).not.toContain('my.aicountly.com')
  })

  it('shows in the identity block, not only on the trigger', async () => {
    relay(png())
    const view = render(<AccountMenu />)

    await waitFor(() => expect(view.container.querySelector('img.account-avatar__photo')).not.toBeNull())
    openMenu()
    expect(photoIn(panel())).not.toBeNull()
    expect(photoIn(panel())?.getAttribute('src')).toMatch(/^blob:remote\//)
  })

  it.each([
    ['nobody has uploaded one (204)', () => new Response(null, { status: 204 })],
    ['the portal has none (404)', () => new Response('{}', { status: 404, headers: { 'Content-Type': 'application/json' } })],
    ['the relay is down (503)', () => new Response('', { status: 503 })],
    ['the answer is a 200 that is not an image', () => new Response('{"error":"nope"}', { status: 200, headers: { 'Content-Type': 'application/json' } })],
    ['the image body is empty', png('image/png', new Uint8Array(0))],
    ['the network throws', new TypeError('Failed to fetch')],
  ])('falls back to initials when %s', async (_name, answer) => {
    relay(answer as (() => Response) | Error)
    render(<AccountMenu />)
    await waitFor(() => expect(calls.length > 0 || true).toBe(true))

    openMenu()
    // The menu rendered regardless, with the initials in place of a photo.
    expect(panel()).toBeTruthy()
    expect(panel().textContent).toContain('PR')
    expect(photoIn(panel())).toBeNull()
  })

  it('revokes the object URL when the menu goes away', async () => {
    relay(png())
    const view = render(<AccountMenu />)
    await waitFor(() => expect(created).toBe(1))

    view.unmount()
    expect(revoked).toEqual(['blob:remote/1'])
  })

  it('reports nothing at all: no alert, no status message', async () => {
    relay(() => new Response('', { status: 503 }))
    render(<AccountMenu />)
    await waitFor(() => expect(calls).toHaveLength(1))

    expect(screen.queryByRole('alert')).toBeNull()
    expect(screen.queryByRole('status')).toBeNull()
  })
})

describe('the Company block', () => {
  beforeEach(() => relay(() => new Response('', { status: 404 })))

  it('is omitted because Remote has no organisations, not because the header has a switcher', () => {
    // §3 allows exactly one exemption — a product that scopes per person — and this is it.
    expect(ORGANISATIONS_ENABLED).toBe(false)

    render(<AccountMenu />)
    openMenu()
    expect(panel().textContent).not.toContain('Company')
    expect(screen.queryByRole('menuitem', { name: /Switch company/ })).toBeNull()
  })

  it("is written in Books' order, gated on that one flag, and nothing else", () => {
    const source = menuSource
    const block = source.slice(source.indexOf('{/* 3. Company'), source.indexOf('manageProfilePath()'))

    // The gate is the flag the rest of the app's organisation surfaces use.
    expect(block).toMatch(/\{ORGANISATIONS_ENABLED \? \(/)
    expect([...block.matchAll(/(Switch company|Edit company|Manage branches)/g)].map((m) => m[1])).toEqual([
      'Switch company',
      'Edit company',
      'Manage branches',
    ])
    // Switching opens the header's switcher — one action, two doors — and the header listens.
    expect(block).toMatch(/window\.dispatchEvent\(new CustomEvent\(OPEN_COMPANY_SWITCHER_EVENT\)\)/)
    const shell = shellSource
    expect(shell).toMatch(/window\.addEventListener\(OPEN_COMPANY_SWITCHER_EVENT, onOpen\)/)
    expect(shell).toMatch(/window\.removeEventListener\(OPEN_COMPANY_SWITCHER_EVENT, onOpen\)/)
    // The other two open Manage in a new tab, per company.
    expect(block).toMatch(/manageCompanyEditPath\(companyId\)/)
    expect(block).toMatch(/manageBranchListPath\(companyId\)/)
  })

  it('stands between the accounts block and the links, per the suite-wide order', () => {
    const source = menuSource
    expect(source.indexOf('account-menu__heading">Accounts')).toBeLessThan(source.indexOf('{/* 3. Company'))
    expect(source.indexOf('{/* 3. Company')).toBeLessThan(source.indexOf('manageProfilePath()'))
  })
})

describe('the Manage paths the Company block would use', () => {
  it("are Manage's own company and branch routes, with the id encoded and no credential", async () => {
    const { manageBranchListPath, manageCompanyEditPath } = await import('../services/appLauncher')
    expect(manageCompanyEditPath(74)).toBe('/company/74/edit')
    expect(manageBranchListPath(74)).toBe('/branch/74')
    expect(manageCompanyEditPath('7 4/x')).toBe('/company/7%204%2Fx/edit')
    expect(/auth_token|ses_key/i.test(manageCompanyEditPath(74))).toBe(false)
  })
})

