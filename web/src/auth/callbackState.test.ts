import { expect, test } from 'vitest'
import { analyticsSafePath, buildPortalUrl, cleanedUrl, consumeState, hasCredentialParams, issueState, MAX_AGE_MS, parseAuthCallback } from './callbackState'
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
