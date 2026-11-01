/**
 * Token storage for the two-token AICOUNTLY model.
 *
 * | Token        | Lifetime    | Storage       | Use                          |
 * |--------------|-------------|---------------|------------------------------|
 * | `auth_token` | Long-lived  | localStorage  | Mint / refresh a `ses_key`   |
 * | `ses_key`    | ~15 minutes | Memory ONLY   | `Bearer` on product API calls|
 *
 * `ses_key` must never reach localStorage or sessionStorage — that rule is the
 * whole point of the split, and it is why the key below lives in a module
 * variable that dies with the page.
 *
 * A third, Remote's own: the **two-step verification token** — proof that this
 * browser passed Remote's two-step verification (docs/SECURITY.md). It is in
 * localStorage on purpose, beside the auth_token, because the thing it vouches
 * for is this browser, for up to twelve hours, across reloads and tabs: the
 * ses_key changes every few minutes and on every reload, so a verification
 * that lived with it would ask for a code all day. It is worth nothing on its
 * own — the server honours it only beside a valid portal session of the same
 * person — and signing out removes it with the rest.
 */

import { clearSharedAuthToken } from './sharedAuthCookie'

const AUTH_TOKEN_KEY = 'auth_token'
const TWO_STEP_TOKEN_KEY = 'remote:twoStep'

/** Web Storage throws in private-mode Safari and when quota is exhausted. */
function safeLocalStorage(): Storage | null {
  try {
    return window.localStorage
  } catch {
    return null
  }
}

// ---------- auth_token ----------

let authToken: string | null = null

export function setAuthToken(token: string | null): void {
  authToken = token || null

  const store = safeLocalStorage()
  if (store) {
    try {
      if (token) store.setItem(AUTH_TOKEN_KEY, token)
      else store.removeItem(AUTH_TOKEN_KEY)
    } catch {
      /* ignore quota / private mode */
    }
  }

  if (!token) clearSharedAuthToken()
}

/**
 * Hold a token from a sign-in in memory only — not localStorage — until the
 * portal has accepted it (commitAuthToken). A token the portal refuses is
 * dropped with discardStagedAuthToken and is never stored.
 */
export function stageAuthToken(token: string | null): void {
  authToken = token || null
}

/** Persist the staged token (localStorage). */
export function commitAuthToken(): void {
  if (authToken) setAuthToken(authToken)
}

/** Forget a staged token the portal did not accept; what is stored is untouched. */
export function discardStagedAuthToken(): void {
  authToken = null
}

/**
 * Memory → localStorage. Cross-product sign-in is the portal hand-off, not a
 * shared cookie.
 */
export function getAuthToken(): string | null {
  if (authToken) return authToken

  const store = safeLocalStorage()
  if (store) {
    try {
      const stored = store.getItem(AUTH_TOKEN_KEY)
      if (stored) {
        authToken = stored
        return stored
      }
    } catch {
      /* ignore */
    }
  }

  return null
}

// ---------- ses_key (memory only, never persisted) ----------

let sesKey: string | null = null
let sesExpiry = 0

export function saveSession(key: string, expiresInSeconds: number): void {
  sesKey = key
  sesExpiry = Date.now() + expiresInSeconds * 1000
}

export function getSesKey(): string | null {
  if (sesKey && Date.now() < sesExpiry) return sesKey
  sesKey = null
  sesExpiry = 0
  return null
}

export function clearSession(): void {
  sesKey = null
  sesExpiry = 0
}

// ---------- two-step verification token (Remote's own) ----------

let twoStepToken: string | null = null

export function setTwoStepToken(token: string | null): void {
  twoStepToken = token || null

  const store = safeLocalStorage()
  if (store) {
    try {
      if (token) store.setItem(TWO_STEP_TOKEN_KEY, token)
      else store.removeItem(TWO_STEP_TOKEN_KEY)
    } catch {
      /* private mode — it lasts as long as the page */
    }
  }
}

/**
 * Storage first, every time, not the copy in memory: a code entered in another
 * tab replaces this browser's token there, and this tab must send the new one
 * rather than the one the server has just retired. Memory is the fallback for
 * a browser with no usable storage.
 */
export function getTwoStepToken(): string | null {
  const store = safeLocalStorage()
  if (store) {
    try {
      return store.getItem(TWO_STEP_TOKEN_KEY) ?? null
    } catch {
      /* fall through to memory */
    }
  }

  return twoStepToken
}

/** Full sign-out: drops the session key, the auth token and the two-step token, and purges a leftover legacy cookie (.aicountly.com only). */
export function clearAllTokens(): void {
  clearSession()
  authToken = null
  twoStepToken = null

  const store = safeLocalStorage()
  if (store) {
    try {
      store.removeItem(AUTH_TOKEN_KEY)
      store.removeItem(TWO_STEP_TOKEN_KEY)
    } catch {
      /* ignore */
    }
  }

  clearSharedAuthToken()
}
