/**
 * Sign-in hand-off hygiene for the my.aicountly.com round trip (I-17).
 *
 * Two things went wrong with the old callback:
 *
 *   1. It stored ANY `auth_token` found in the address — on the callback and on
 *      every other page — in localStorage and in the cookie every AICOUNTLY
 *      product trusts (`.aicountly.com`). A link carrying someone else's token
 *      signed the visitor in as that person (login CSRF / session fixation).
 *   2. The long-lived token travelled in URLs, and from there into history,
 *      server logs and Google Analytics `page_path`.
 *
 * Now the app issues a single-use nonce before it sends the person to the portal
 * and accepts a sign-in only when that nonce comes back with it:
 *
 *   - inside the callback URL itself (`cb_state`) — the portal validates the
 *     callback by origin and keeps the whole URL, also through its login form;
 *   - and as the portal's own `state`, which authentication_jump echoes back.
 *
 * A credential on any other page, or on the callback without our nonce, is never
 * stored; it is removed from the address and the app starts its own round trip.
 * The portal is asked for a one-time code (response_type=code) that is redeemed
 * once for the token, so no long-lived token appears in a URL at all.
 *
 * This app is a registry product of the portal (ProductRegistry::PRODUCT_CALLBACKS),
 * so the code it gets back is an `sso_code`, redeemed at `POST /api/sso/exchange`
 * with the exact returnUrl it sent (including `?cb_state=`). When the portal could
 * not store a code it answers `sso_error=temporarily_unavailable`. A hand-off with
 * an empty `state` and no `cb_state` is Jump To or another product's launcher: it
 * is never used, and the app starts its own sign-in. Contract:
 * my-aicountly-com docs/contracts/insights/README.md (the same for every registry
 * product). `code` (POST /api/auth/exchange) is the portal's flow for products
 * with their own hand-off branch, still understood here.
 *
 * Pure functions over an injectable storage and location, so they run under
 * `node --test` and vitest alike. Keep this file identical in every product SPA.
 */

export const STATE_KEY = 'aic_auth_state'
/** The nonce as this app puts it in its own callback URL. */
export const CB_PARAM = 'cb_state'
/** A round trip that takes longer than this is not ours any more. */
export const MAX_AGE_MS = 10 * 60 * 1000

export interface StorageLike {
  getItem(key: string): string | null
  setItem(key: string, value: string): void
  removeItem(key: string): void
}

export interface LocationLike {
  pathname: string
  search: string
  hash: string
}

function defaultStorage(): StorageLike | null {
  try {
    return typeof sessionStorage !== 'undefined' ? sessionStorage : null
  } catch {
    return null
  }
}

function randomHex(bytes = 16): string {
  const buf = new Uint8Array(bytes)
  globalThis.crypto.getRandomValues(buf)
  return Array.from(buf, (b) => b.toString(16).padStart(2, '0')).join('')
}

/** A fresh nonce, remembered for this tab only. */
export function issueState(storage: StorageLike | null = defaultStorage(), now: number = Date.now()): string {
  const value = randomHex()
  try {
    storage?.setItem(STATE_KEY, JSON.stringify({ v: value, at: now }))
  } catch {
    // Storage unavailable: nothing will verify, and the person is asked to sign in again.
  }
  return value
}

/**
 * True when one of the returned values is the nonce this tab issued within
 * MAX_AGE_MS. The nonce is spent either way, so a callback can be replayed once
 * at most — and never by a link opened in another tab.
 */
export function consumeState(
  candidates: Array<string | null | undefined>,
  storage: StorageLike | null = defaultStorage(),
  now: number = Date.now(),
): boolean {
  let stored: { v?: unknown; at?: unknown } | null
  try {
    stored = JSON.parse(storage?.getItem(STATE_KEY) || 'null')
    storage?.removeItem(STATE_KEY)
  } catch {
    stored = null
  }
  if (!stored || typeof stored.v !== 'string' || stored.v.length < 16) return false
  if (typeof stored.at !== 'number' || now - stored.at > MAX_AGE_MS || now < stored.at) return false
  const expected = stored.v
  return candidates.some((c) => typeof c === 'string' && c.length === expected.length && c === expected)
}

/** The callback URL carrying the nonce. */
export function withCallbackState(callbackUrl: string, state: string): string {
  const sep = callbackUrl.includes('?') ? '&' : '?'
  return `${callbackUrl}${sep}${CB_PARAM}=${encodeURIComponent(state)}`
}

/** The portal URL for this app's own round trip: callback + nonce + a one-time code, never a token. */
export function buildPortalUrl(base: string, returnParam: string, callbackUrl: string, state: string, extra: Record<string, string> = {}): string {
  const params = new URLSearchParams({
    [returnParam]: withCallbackState(callbackUrl, state),
    state,
    response_type: 'code',
    ...extra,
  })
  return `${base}${base.includes('?') ? '&' : '?'}${params.toString()}`
}

export interface ParsedCallback {
  /** The address is this app's callback route (and only there is anything read). */
  onCallbackPath: boolean
  authToken: string | null
  /** The one-time code of the portal's hand-off-branch flow (POST /api/auth/exchange). */
  code: string | null
  /** The registry products' one-time code (POST /api/sso/exchange). */
  ssoCode: string | null
  /** The portal could not issue a one-time code (`temporarily_unavailable`). */
  ssoError: string | null
  /** The portal's echo of the `state` this app sent. */
  state: string | null
  /** The nonce this app put in its own callback URL. */
  cbState: string | null
  authError: string | null
  /** A token or code is present (whether or not it will be trusted). */
  hasCredential: boolean
  /**
   * An answer that carries no nonce at all (empty `state`, no `cb_state`): Jump To
   * or another product's launcher. Never used; the app starts its own sign-in.
   */
  unsolicited: boolean
}

function normalisePath(path: string): string {
  return path.length > 1 ? path.replace(/\/+$/, '') : path
}

/** Query parameters from the address: the query string, and a hash router's `#/route?…`. */
function paramSources(loc: LocationLike): URLSearchParams[] {
  const sources = [new URLSearchParams(loc.search || '')]
  const hash = loc.hash || ''
  const q = hash.indexOf('?')
  if (q !== -1) sources.push(new URLSearchParams(hash.slice(q + 1)))
  const inner = hash.indexOf('#', 1)
  if (inner !== -1) sources.push(new URLSearchParams(hash.slice(inner + 1)))
  return sources
}

/**
 * Read the portal's answer. Only the callback route is read: an `auth_token` or
 * `code` on any other page is ignored (and removed by {@link cleanedUrl}).
 */
export function parseAuthCallback(loc: LocationLike, callbackPath: string): ParsedCallback {
  const onCallbackPath = normalisePath(loc.pathname) === normalisePath(callbackPath)
  if (!onCallbackPath) {
    return {
      onCallbackPath: false, authToken: null, code: null, ssoCode: null, ssoError: null,
      state: null, cbState: null, authError: null, hasCredential: false, unsolicited: false,
    }
  }
  const sources = paramSources(loc)
  const pick = (name: string): string | null => {
    for (const params of sources) {
      const value = params.get(name)
      if (value) return value
    }
    return null
  }
  const authToken = pick('auth_token')
  const code = pick('code')
  const ssoCode = pick('sso_code')
  const ssoError = pick('sso_error')
  const authError = pick('auth_error')
  const state = pick('state')
  const cbState = pick(CB_PARAM)
  const hasCredential = authToken !== null || code !== null || ssoCode !== null
  const answered = hasCredential || ssoError !== null || authError !== null
  return {
    onCallbackPath, authToken, code, ssoCode, ssoError, state, cbState, authError, hasCredential,
    unsolicited: answered && state === null && cbState === null,
  }
}

/**
 * True when this is the portal's answer to a sign-in this tab started: the nonce
 * came back with it (spent here, once). An unsolicited hand-off, or an address
 * with no answer in it, never touches the nonce.
 */
export function verifyCallback(p: ParsedCallback, storage: StorageLike | null = defaultStorage(), now: number = Date.now()): boolean {
  if (!p.onCallbackPath || p.unsolicited) return false
  if (!p.hasCredential && p.ssoError === null && p.authError === null) return false
  return consumeState([p.cbState, p.state], storage, now)
}

/**
 * The `redirect_uri` for `POST /api/sso/exchange`: byte for byte the returnUrl this
 * app sent (its callback with `?cb_state=<nonce>`). Without a `cb_state` the portal
 * bound the code to its default callback, which has no query.
 */
export function exchangeRedirectUri(callbackUrl: string, cbState: string | null): string {
  return cbState ? withCallbackState(callbackUrl, cbState) : callbackUrl
}

export type CallbackAction =
  /** Nothing this app may use: carry on with the stored sign-in, or start one. */
  | { kind: 'none' }
  /** The portal declined a sign-in this tab asked for. */
  | { kind: 'portal-error'; error: string }
  /** The portal could not issue a one-time code: start again after a short wait. */
  | { kind: 'retry-later' }
  | { kind: 'exchange-sso'; ssoCode: string; cbState: string | null }
  | { kind: 'exchange-code'; code: string }
  /** A bare auth_token, only while the URL-token fallback is switched on. */
  | { kind: 'token'; authToken: string }

/**
 * What to do with the portal's answer. Only a verified answer is ever used. A bare
 * `auth_token` with the fallback off is how a registry product hears that no code
 * could be stored (the portal hands the token instead), so it is the same as
 * `sso_error`: the token is not used and sign-in starts again.
 */
export function callbackAction(p: ParsedCallback, verified: boolean, urlTokenFallback: boolean): CallbackAction {
  if (!verified) return { kind: 'none' }
  if (p.authError !== null) return { kind: 'portal-error', error: p.authError }
  if (p.ssoCode !== null) return { kind: 'exchange-sso', ssoCode: p.ssoCode, cbState: p.cbState }
  if (p.code !== null) return { kind: 'exchange-code', code: p.code }
  if (p.authToken !== null) return urlTokenFallback ? { kind: 'token', authToken: p.authToken } : { kind: 'retry-later' }
  if (p.ssoError !== null) return { kind: 'retry-later' }
  return { kind: 'none' }
}

/**
 * Parameters that are credentials or sign-in nonces wherever they appear. Plain
 * `code`, `token` and `state` are left alone in the address bar (an app may use
 * them for itself: Remote's `/desktop-signin?code=`, a `?state=` filter) and are
 * only withheld from analytics.
 */
const CREDENTIAL_PARAMS = ['auth_token', 'sso_code', 'sso_error', 'ses_key', 'access_token', 'id_token', 'pending_token', CB_PARAM, 'auth_error']
const ANALYTICS_HIDDEN_PARAMS = [...CREDENTIAL_PARAMS, 'token', 'code', 'state', 'jti', 'context', 'remote_context']

function without(params: URLSearchParams, names: string[]): URLSearchParams {
  const kept = new URLSearchParams()
  params.forEach((value, key) => {
    if (!names.includes(key)) kept.append(key, value)
  })
  return kept
}

/** True when the address carries a sign-in credential or nonce. */
export function hasCredentialParams(loc: LocationLike): boolean {
  return paramSources(loc).some((p) => CREDENTIAL_PARAMS.some((n) => p.has(n)))
}

/** The address with every credential parameter removed (path, other params and hash kept). */
export function cleanedUrl(loc: LocationLike): string {
  const search = without(new URLSearchParams(loc.search || ''), CREDENTIAL_PARAMS).toString()
  let hash = loc.hash || ''
  const q = hash.indexOf('?')
  if (q !== -1) {
    const kept = without(new URLSearchParams(hash.slice(q + 1)), CREDENTIAL_PARAMS).toString()
    hash = hash.slice(0, q) + (kept ? `?${kept}` : '')
  }
  return `${loc.pathname}${search ? `?${search}` : ''}${hash}`
}

/**
 * A route path safe to send to analytics (G06-01, G28#8): the callback route
 * without its query, and no sign-in or one-time secret on any other route.
 */
export function analyticsSafePath(path: string): string {
  const raw = typeof path === 'string' ? path : ''
  const q = raw.indexOf('?')
  const route = q === -1 ? raw : raw.slice(0, q)
  if (route.startsWith('/auth/')) return route
  if (q === -1) return route || '/'
  const rest = without(new URLSearchParams(raw.slice(q + 1)), ANALYTICS_HIDDEN_PARAMS).toString()
  return (route || '/') + (rest ? `?${rest}` : '')
}
