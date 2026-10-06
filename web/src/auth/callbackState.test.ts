import { expect, test } from 'vitest'
import { analyticsSafePath, buildPortalUrl, callbackAction, cleanedUrl, consumeState, exchangeRedirectUri, hasCredentialParams, issueState, MAX_AGE_MS, parseAuthCallback, STATE_KEY, verifyCallback } from './callbackState'
import type { LocationLike, StorageLike } from './callbackState'

// The same assertions as the node:test copy kept in the other products.
const assert = {
  equal: (a: unknown, b: unknown, _m?: string) => expect(a).toBe(b),
  deepEqual: (a: unknown, b: unknown, _m?: string) => expect(a).toEqual(b),
}

const memory = (): StorageLike => {
  const m = new Map<string, string>()
  return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => void m.set(k, v), removeItem: (k) => void m.delete(k) }
}
const CB = '/auth/callback'
const loc = (pathname: string, search = '', hash = ''): LocationLike => ({ pathname, search, hash })

test('a nonce is accepted once, whether it comes back as cb_state or as the portal state', () => {
  const s = memory()
  const nonce = issueState(s, 1000)
  assert.equal(nonce.length, 32)
  assert.equal(consumeState([null, nonce], s, 2000), true)
  assert.equal(consumeState([nonce], s, 2001), false, 'spent')
  const again = issueState(s, 1000)
  assert.equal(consumeState([again, 'other'], s, 2000), true)
})

test('a wrong, missing or expired nonce is refused and spent', () => {
  const s = memory()
  const nonce = issueState(s, 1000)
  assert.equal(consumeState(['not-the-nonce', null, undefined], s, 2000), false)
  assert.equal(consumeState([nonce], s, 2000), false, 'a wrong guess spends it')
  const late = issueState(s, 1000)
  assert.equal(consumeState([late], s, 1000 + MAX_AGE_MS + 1), false, 'older than ten minutes')
  assert.equal(consumeState(['x'], memory(), 1000), false, 'nothing was issued')
  const broken: StorageLike = { getItem: () => { throw new Error('denied') }, setItem: () => { throw new Error('denied') }, removeItem: () => {} }
  assert.equal(consumeState(['x'], broken), false, 'unusable storage never verifies')
})

test('a token or code is read on the callback route only (G29#1)', () => {
  const on = parseAuthCallback(loc(CB, '?auth_token=T&state=S&cb_state=C'), CB)
  assert.deepEqual([on.onCallbackPath, on.authToken, on.state, on.cbState, on.hasCredential], [true, 'T', 'S', 'C', true])
  const code = parseAuthCallback(loc('/auth/callback/', '?code=ONE&cb_state=C'), CB)
  assert.deepEqual([code.code, code.authToken, code.hasCredential], ['ONE', null, true])
  for (const path of ['/', '/docs', '/auth/callback/extra', '/desktop-signin']) {
    const off = parseAuthCallback(loc(path, '?auth_token=T&code=C&state=S&cb_state=X'), CB)
    assert.deepEqual([off.onCallbackPath, off.authToken, off.code, off.state, off.hasCredential], [false, null, null, null, false], path)
  }
})

test('a hash-router callback is still understood', () => {
  const p = parseAuthCallback(loc(CB, '', '#/x?auth_token=T&state=S'), CB)
  assert.deepEqual([p.authToken, p.state], ['T', 'S'])
  const q = parseAuthCallback(loc(CB, '', '#/auth/callback#code=ONE&cb_state=C'), CB)
  assert.deepEqual([q.code, q.cbState], ['ONE', 'C'])
})

test('a forged link on any page has its credentials removed and nothing else', () => {
  const l = loc('/engagements', '?cmp_id=7&auth_token=T&cb_state=C&filter=open', '#top')
  assert.equal(hasCredentialParams(l), true)
  assert.equal(cleanedUrl(l), '/engagements?cmp_id=7&filter=open#top')
  assert.equal(hasCredentialParams(loc('/desktop-signin', '?code=ABCD-EFGH')), false, 'a plain code is the app’s own')
  assert.equal(cleanedUrl(loc('/desktop-signin', '?code=ABCD-EFGH')), '/desktop-signin?code=ABCD-EFGH')
  assert.equal(cleanedUrl(loc('/p', '', '#/r?ses_key=K&a=1')), '/p#/r?a=1')
})

test('the portal URL asks for a one-time code and carries the nonce twice, never a token', () => {
  const url = buildPortalUrl('https://my.example/login/authentication_jump/app', 'returnUrl', 'https://app.example/auth/callback', 'NONCE')
  const q = new URL(url).searchParams
  assert.equal(q.get('returnUrl'), 'https://app.example/auth/callback?cb_state=NONCE')
  assert.equal(q.get('state'), 'NONCE')
  assert.equal(q.get('response_type'), 'code')
  assert.equal(url.includes('auth_token'), false)
  const form = buildPortalUrl('https://my.example/?x=1', 'returnUrl', 'https://app.example/auth/callback', 'N', { prompt: 'login' })
  assert.equal(new URL(form).searchParams.get('prompt'), 'login')
  assert.equal(new URL(form).searchParams.get('x'), '1')
})

test('analytics never receives a credential, a one-time code or the callback query', () => {
  assert.equal(analyticsSafePath('/auth/callback?auth_token=T&state=S'), '/auth/callback')
  assert.equal(analyticsSafePath('/auth/callback'), '/auth/callback')
  assert.equal(analyticsSafePath('/dashboard?auth_token=T&tab=2'), '/dashboard?tab=2')
  assert.equal(analyticsSafePath('/desktop-signin?code=ABCD-EFGH'), '/desktop-signin')
  assert.equal(analyticsSafePath('/x?token=T&cb_state=C&state=S&context=J&page=3'), '/x?page=3')
  assert.equal(analyticsSafePath(''), '/')
})

// --- The registry hand-off (IDN-01): the portal answers this app with `sso_code` -----------------
// The URLs below are what my-aicountly-com's Login::authentication_jump() emits for a registry
// product (docs/contracts/insights/sso_callback.json shapes, product origin substituted).
const ORIGIN = 'https://app.example'
const NONCE = '0123456789abcdef0123456789abcdef'
const SSO = 'a'.repeat(64)

test('the callback matrix: sso_code, sso_error, an empty state, code, nothing', () => {
  const sso = parseAuthCallback(loc(CB, `?cb_state=${NONCE}&sso_code=${SSO}&state=${NONCE}`), CB)
  assert.deepEqual(
    [sso.ssoCode, sso.ssoError, sso.code, sso.authToken, sso.state, sso.cbState, sso.hasCredential, sso.unsolicited],
    [SSO, null, null, null, NONCE, NONCE, true, false],
  )
  const err = parseAuthCallback(loc(CB, `?cb_state=${NONCE}&sso_error=temporarily_unavailable&state=${NONCE}`), CB)
  assert.deepEqual([err.ssoError, err.ssoCode, err.hasCredential, err.unsolicited], ['temporarily_unavailable', null, false, false])
  const launcher = parseAuthCallback(loc(CB, `?sso_code=${SSO}&state=`), CB)
  assert.deepEqual([launcher.ssoCode, launcher.state, launcher.cbState, launcher.hasCredential, launcher.unsolicited], [SSO, null, null, true, true])
  const launcherToken = parseAuthCallback(loc(CB, '?auth_token=T&state='), CB)
  assert.equal(launcherToken.unsolicited, true, 'a launcher token is unsolicited too')
  const code = parseAuthCallback(loc(CB, `?cb_state=${NONCE}&code=ONE&state=${NONCE}`), CB)
  assert.deepEqual([code.code, code.ssoCode, code.hasCredential, code.unsolicited], ['ONE', null, true, false])
  const nothing = parseAuthCallback(loc(CB), CB)
  assert.deepEqual([nothing.onCallbackPath, nothing.hasCredential, nothing.ssoError, nothing.unsolicited], [true, false, null, false])
  const elsewhere = parseAuthCallback(loc('/docs', `?sso_code=${SSO}&state=${NONCE}&cb_state=${NONCE}`), CB)
  assert.deepEqual([elsewhere.ssoCode, elsewhere.hasCredential], [null, false], 'never read off the callback route')
})

test('only an answer to this tab\'s own sign-in verifies; a launcher hand-off never spends the nonce', () => {
  const s = memory()
  issueState(s, 1000)
  const launcher = parseAuthCallback(loc(CB, `?sso_code=${SSO}&state=`), CB)
  assert.equal(verifyCallback(launcher, s, 2000), false)
  assert.equal(s.getItem(STATE_KEY) !== null, true, 'the outstanding nonce is kept for the real answer')
  assert.equal(verifyCallback(parseAuthCallback(loc(CB), CB), s, 2000), false, 'nothing to verify')
  assert.equal(s.getItem(STATE_KEY) !== null, true)
  const nonce = issueState(s, 1000)
  const own = parseAuthCallback(loc(CB, `?cb_state=${nonce}&sso_code=${SSO}&state=${nonce}`), CB)
  assert.equal(verifyCallback(own, s, 2000), true)
  assert.equal(verifyCallback(own, s, 2001), false, 'once')
  const n2 = issueState(s, 1000)
  assert.equal(verifyCallback(parseAuthCallback(loc(CB, `?cb_state=${n2}&sso_error=temporarily_unavailable&state=${n2}`), CB), s, 2000), true)
  issueState(s, 1000)
  assert.equal(verifyCallback(parseAuthCallback(loc(CB, `?cb_state=${NONCE}&sso_code=${SSO}&state=${NONCE}`), CB), s, 2000), false, 'someone else\'s nonce')
})

test('what the app does with each verified answer', () => {
  const p = (q: string) => parseAuthCallback(loc(CB, q), CB)
  const own = `cb_state=${NONCE}&state=${NONCE}`
  assert.deepEqual(callbackAction(p(`?cb_state=${NONCE}&sso_code=${SSO}&state=${NONCE}`), true, false), { kind: 'exchange-sso', ssoCode: SSO, cbState: NONCE })
  assert.deepEqual(callbackAction(p(`?${own}&sso_error=temporarily_unavailable`), true, false), { kind: 'retry-later' })
  assert.deepEqual(callbackAction(p(`?${own}&code=ONE`), true, false), { kind: 'exchange-code', code: 'ONE' })
  assert.deepEqual(callbackAction(p(`?${own}&auth_token=T`), true, false), { kind: 'retry-later' }, 'URL token refused by default: it means no code could be stored')
  assert.deepEqual(callbackAction(p(`?${own}&auth_token=T`), true, true), { kind: 'token', authToken: 'T' }, 'only with the fallback switched on')
  assert.deepEqual(callbackAction(p(`?${own}&auth_error=access_denied`), true, false), { kind: 'portal-error', error: 'access_denied' })
  assert.deepEqual(callbackAction(p(`?sso_code=${SSO}&state=`), false, true), { kind: 'none' }, 'an unverified answer is never used')
  assert.deepEqual(callbackAction(p(''), false, false), { kind: 'none' })
})

test('the exchange redirect_uri is byte for byte the returnUrl sent, cb_state included', () => {
  const callback = `${ORIGIN}/auth/callback`
  const sent = new URL(buildPortalUrl('https://my.example/login/authentication_jump/app', 'returnUrl', callback, NONCE)).searchParams.get('returnUrl')
  assert.equal(exchangeRedirectUri(callback, NONCE), sent)
  assert.equal(exchangeRedirectUri(callback, NONCE), `${ORIGIN}/auth/callback?cb_state=${NONCE}`)
  // The callback URL minus the `&sso_code=…&state=…` the portal appended is the same string.
  const landed = `${sent}&sso_code=${SSO}&state=${NONCE}`
  assert.equal(landed.replace(/[?&]sso_code=[0-9a-f]{64}&state=[^&]*$/, ''), exchangeRedirectUri(callback, NONCE))
  assert.equal(exchangeRedirectUri(callback, null), callback, 'no cb_state: the portal bound the code to its default callback')
})

test('sso_code and sso_error never stay in the address or reach analytics', () => {
  const l = loc(CB, `?cb_state=${NONCE}&sso_code=${SSO}&state=${NONCE}`)
  assert.equal(hasCredentialParams(l), true)
  assert.equal(cleanedUrl(l), `${CB}?state=${NONCE}`)
  assert.equal(cleanedUrl(loc('/x', `?sso_code=${SSO}&sso_error=temporarily_unavailable&tab=1`)), '/x?tab=1')
  assert.equal(analyticsSafePath(`/x?sso_code=${SSO}&tab=1`), '/x?tab=1')
  assert.equal(analyticsSafePath(`/auth/callback?sso_code=${SSO}`), '/auth/callback')
})
