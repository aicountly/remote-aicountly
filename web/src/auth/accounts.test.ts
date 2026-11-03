/**
 * The roster of AICOUNTLY accounts signed in on this browser.
 *
 * The readable half of the portal's roster (`AIC_ACCOUNTS_VIEW`) is the only
 * channel the account menu has — the token half is httpOnly and the portal's
 * API is cross-origin with credentials refused. So what matters here is that a
 * cookie this app did not write is read defensively: a missing one, a corrupt
 * one and a hostile one all have to read as "no accounts" rather than throw,
 * because a render error in the header takes the whole shell with it.
 *
 * `accounts.ts` is a twin of the Notes and Books modules; these checks are the
 * twins of their tests, plus the one thing only this product can get wrong —
 * dropping the remembered organisation on a switch while keeping the tokens.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  MAX_ACCOUNTS,
  buildAddAccountUrl,
  buildChooseAccountUrl,
  buildSwitchAccountUrl,
  canAddAccount,
  performAccountSwitch,
  readAccountRoster,
} from './accounts'
import { STATE_KEY, consumeState } from './callbackState'
import { clearAllTokens, getAuthToken, getSesKey, saveSession, setAuthToken } from './tokens'

/** The URL without its `state`, so the rest of it can be compared literally. */
function withoutState(raw: string): string {
  const url = new URL(raw)
  url.searchParams.delete('state')
  const query = url.searchParams.toString()
  return `${url.origin}${url.pathname}${query ? `?${query}` : ''}`
}

function stateOf(raw: string): string {
  return new URL(raw).searchParams.get('state') || ''
}

/** Encode a roster the way the portal's AccountRoster::summaryCookieValue does. */
function viewCookie(list: Array<Record<string, unknown> | null>): string {
  const json = JSON.stringify({ v: 1, max: MAX_ACCOUNTS, a: list })
  const binary = Array.from(new TextEncoder().encode(json), (byte) => String.fromCharCode(byte)).join('')
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

function withCookie(value: string) {
  vi.spyOn(document, 'cookie', 'get').mockReturnValue(value === '' ? '' : `AIC_ACCOUNTS_VIEW=${value}`)
}

let assigned: string[] = []

function at(hostname: string) {
  vi.spyOn(window, 'location', 'get').mockReturnValue({
    hostname,
    origin: `https://${hostname}`,
    assign: (url: string) => void assigned.push(String(url)),
  } as unknown as Location)
}

beforeEach(() => {
  assigned = []
  localStorage.clear()
  sessionStorage.clear()
  at('remote.aicountly.com')
})

afterEach(() => {
  vi.restoreAllMocks()
  clearAllTokens()
  localStorage.clear()
  sessionStorage.clear()
})

describe('reading the roster of accounts signed in on this browser', () => {
  it('reads the portal’s summary cookie', () => {
    withCookie(
      viewCookie([
        { u: 88, n: 'Rahul Gupta', e: 'ra•••@cagupta.in', m: '+91••••••••78', a: 1 },
        { u: 77, n: 'Priya Sharma', e: 'pr•••@example.com', a: 0 },
      ]),
    )

    const roster = readAccountRoster()
    expect(roster).toHaveLength(2)
    expect(roster[0]).toEqual({
      uuid: 88,
      name: 'Rahul Gupta',
      maskedEmail: 'ra•••@cagupta.in',
      maskedMobile: '+91••••••••78',
      active: true,
    })
    expect(roster[1].active).toBe(false)
  })

  it('puts the active account first however the cookie ordered them', () => {
    // The identity block takes the masked mobile from the active entry, so
    // getting this wrong labels the session with somebody else's number.
    withCookie(viewCookie([{ u: 77, n: 'Priya', a: 0 }, { u: 88, n: 'Rahul', a: 1 }]))
    expect(readAccountRoster().map((account) => account.uuid)).toEqual([88, 77])
  })

  it('keeps the portal’s masking — a full address never reaches the menu', () => {
    withCookie(viewCookie([{ u: 88, n: 'Rahul Gupta', e: 'ra•••@cagupta.in', m: '+91••••••••78', a: 1 }]))
    const [account] = readAccountRoster()
    expect(account.maskedEmail).not.toContain('rahul@')
    expect(account.maskedEmail).toContain('•')
    expect(account.maskedMobile).toContain('•')
  })

  // A browser signed in before the roster shipped, local development, the
  // desktop application's own web view, and a cleared cookie jar all land here.
  // None is an error: the menu falls back to the portal chooser, which always
  // knows the truth.
  it('is empty when there is no cookie', () => {
    withCookie('')
    expect(readAccountRoster()).toEqual([])
  })

  it('is empty rather than throwing when the cookie is corrupt', () => {
    for (const value of ['!!!not-base64!!!', 'YWJj', btoa('{"a":'), btoa('[]'), btoa('null')]) {
      withCookie(value)
      expect(readAccountRoster(), value).toEqual([])
    }
  })

  it('drops entries with no usable subscriber id', () => {
    withCookie(
      viewCookie([
        { u: 0, n: 'Nobody' },
        { n: 'Also nobody' },
        { u: 'not-a-number', n: 'Nor this' },
        { u: -3, n: 'Nor this either' },
        null,
        { u: 88, n: 'Rahul', a: 1 },
      ]),
    )
    expect(readAccountRoster().map((account) => account.uuid)).toEqual([88])
  })

  it('never reports more accounts than the portal allows', () => {
    withCookie(viewCookie(Array.from({ length: 9 }, (_, index) => ({ u: index + 1, n: `User ${index + 1}` }))))
    expect(readAccountRoster()).toHaveLength(MAX_ACCOUNTS)
  })
})

describe('whether another account may be added', () => {
  it('allows one while there is room, and refuses once the browser is full', () => {
    expect(canAddAccount([])).toBe(true)
    expect(canAddAccount(Array.from({ length: MAX_ACCOUNTS - 1 }, () => ({}) as never))).toBe(true)
    expect(canAddAccount(Array.from({ length: MAX_ACCOUNTS }, () => ({}) as never))).toBe(false)
  })

  it('hides "Use another account" when the browser already holds five', () => {
    withCookie(
      viewCookie(Array.from({ length: MAX_ACCOUNTS }, (_, index) => ({ u: index + 1, a: index === 0 ? 1 : 0 }))),
    )
    expect(canAddAccount(readAccountRoster())).toBe(false)
  })
})

describe('the portal URLs the menu navigates to', () => {
  it('names this product in production so the portal hands the user back here', () => {
    at('remote.aicountly.com')
    expect(withoutState(buildSwitchAccountUrl(88))).toBe('https://my.aicountly.com/login/switch_account/remote?uuid=88')
    expect(withoutState(buildAddAccountUrl())).toBe('https://my.aicountly.com/login/add_account/remote')
    expect(withoutState(buildChooseAccountUrl())).toBe('https://my.aicountly.com/login/select_account/remote')
  })

  it('uses the sandbox portal on a sandbox host, and still the `remote` product key', () => {
    // A sandbox build pointed at my.aicountly.com is the classic way to break
    // sandbox sign-in; the product key must not pick up the `gh` label either.
    at('remote.gh.aicountly.com')
    expect(withoutState(buildSwitchAccountUrl(88))).toBe('https://sandbox.aicountly.com/login/switch_account/remote?uuid=88')
    expect(withoutState(buildAddAccountUrl())).toBe('https://sandbox.aicountly.com/login/add_account/remote')
    expect(withoutState(buildChooseAccountUrl())).toBe('https://sandbox.aicountly.com/login/select_account/remote')
  })

  it('treats localhost as sandbox and falls back to the configured product key', () => {
    at('localhost')
    expect(withoutState(buildChooseAccountUrl())).toBe('https://sandbox.aicountly.com/login/select_account/remote')
  })

  it('never puts a token or a ses_key in a portal URL', () => {
    for (const url of [buildSwitchAccountUrl(88), buildAddAccountUrl(), buildChooseAccountUrl()]) {
      expect(url).not.toMatch(/auth_token|ses_key|Bearer/i)
    }
  })
})

/**
 * The whole point of the account menu is the hand-off that comes back, and this
 * app refuses a hand-off it did not ask for (`verifyCallback()`). A screen
 * reached without a `state` therefore ends in a switch that silently does
 * nothing — so each of these URLs has to carry a nonce this tab will accept.
 */
describe('the sign-in nonce the account screens carry', () => {
  it('sends a non-empty `state` on the switch, add and choose URLs', () => {
    for (const url of [buildSwitchAccountUrl(88), buildAddAccountUrl(), buildChooseAccountUrl()]) {
      expect(stateOf(url), url).not.toBe('')
      expect(stateOf(url).length, url).toBeGreaterThanOrEqual(16)
    }
  })

  it('sends the very nonce this app’s own callback check would accept', () => {
    // consumeState() is what verifyCallback() calls on the portal's echo. It
    // reads the pending sign-in issueState() stored for this tab, so a URL
    // whose `state` it accepts is a URL whose answer will be used.
    for (const build of [() => buildSwitchAccountUrl(88), buildAddAccountUrl, buildChooseAccountUrl]) {
      sessionStorage.clear()
      const state = stateOf(build())
      expect(consumeState([null, state])).toBe(true)
    }
  })

  it('stores the pending sign-in at navigation time, not at module load', () => {
    // Issuing once and reusing it would leave the nonce spent (consumeState()
    // removes it) and every later switch unsolicited again.
    sessionStorage.clear()
    expect(sessionStorage.getItem(STATE_KEY)).toBeNull()

    const first = stateOf(buildSwitchAccountUrl(88))
    expect(sessionStorage.getItem(STATE_KEY)).toContain(first)

    const second = stateOf(buildSwitchAccountUrl(88))
    expect(second).not.toBe(first)
    // The freshest one is the one pending, and it verifies.
    expect(consumeState([second])).toBe(true)
  })

  it('carries one on the navigation the menu actually performs', () => {
    performAccountSwitch(77)
    expect(assigned).toHaveLength(1)
    expect(consumeState([stateOf(assigned[0])])).toBe(true)
  })
})

describe('leaving for another account', () => {
  it('navigates to the portal with the account asked for', () => {
    performAccountSwitch(77)
    expect(assigned.map(withoutState)).toEqual(['https://my.aicountly.com/login/switch_account/remote?uuid=77'])
  })

  it('goes to the add-account screen when no account is named', () => {
    performAccountSwitch(undefined)
    expect(assigned.map(withoutState)).toEqual(['https://my.aicountly.com/login/add_account/remote'])
  })

  it('drops the remembered organisation, which belongs to the account being left', () => {
    // RemoteProvider reads this before /bootstrap has answered, so carried
    // across the next account would open on an organisation that is not theirs.
    localStorage.setItem('remote:scope', JSON.stringify({ scopeType: 'COMPANY', companyId: 60 }))

    performAccountSwitch(77)

    expect(localStorage.getItem('remote:scope')).toBeNull()
  })

  it('drops the ses_key of the account being left', () => {
    // It is the Bearer on every call to this product's API.
    saveSession('ses-key-of-the-old-account', 900)
    expect(getSesKey()).toBe('ses-key-of-the-old-account')

    performAccountSwitch(77)

    expect(getSesKey()).toBeNull()
  })

  it('leaves the auth_token alone, so backing out of the chooser is not a sign-out', () => {
    // The portal replaces the tokens on the way back through the auth callback.
    // Clearing them here would strand anyone who cancels at a login form.
    setAuthToken('still-signed-in')

    performAccountSwitch(77)

    expect(getAuthToken()).toBe('still-signed-in')
    expect(localStorage.getItem('auth_token')).toBe('still-signed-in')
  })
})
