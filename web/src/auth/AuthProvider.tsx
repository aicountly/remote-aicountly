import { createContext, useContext, useEffect, useRef, useState } from 'react'
import type { ReactNode } from 'react'

import {
  AuthError,
  clearCallbackFromUrl,
  clearLogoutFlag,
  clearRedirectGuard,
  ensureSesKey,
  isLogoutInProgress,
  performLogout,
  readAuthCallback,
  redirectToPortalLoginForm,
  redirectToPortalSso,
  URL_TOKEN_FALLBACK,
  dropStrayCredentials,
  exchangeAuthCode,
  exchangeSsoCode,
  allowSsoRetry,
  SSO_RETRY_DELAY_MS,
} from './portal'
import { callbackAction, verifyCallback } from './callbackState'
import { clearAllTokens, commitAuthToken, discardStagedAuthToken, getAuthToken, stageAuthToken } from './tokens'

/**
 * `loading` covers both "starting up" and "leaving for the portal" — in the
 * redirect case the page is about to unload, so it never renders anything else.
 */
export type AuthStatus = 'loading' | 'authenticated' | 'signed-out' | 'guest'

/**
 * Paths an external guest reaches without an AICOUNTLY account (§23).
 *
 * A guest holds a one-time invitation, not a portal session, so bouncing them
 * to the login form would make guest access impossible — the whole point of the
 * invitation is that they do not have an account.
 */
function isGuestPath(pathname: string): boolean {
  return pathname.startsWith('/join/') || pathname.startsWith('/room/')
}

/** A guest token from a redeemed invitation, held for this tab only. */
function hasGuestToken(): boolean {
  try {
    return sessionStorage.getItem('remote:guestToken') !== null
  } catch {
    return false
  }
}

interface AuthState {
  status: AuthStatus
  /** The sign-in service could not be reached; nothing was signed out, and retry() asks again. */
  unavailable?: boolean
  /** Why the user is looking at the signed-out screen, when it was not a plain sign-out. */
  message: string | null
}

interface AuthContextValue extends AuthState {
  signIn: () => void
  signOut: () => void
  /** Ask the sign-in service again after an outage (the session was kept). */
  retry: () => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

const PORTAL_ERROR_MESSAGES: Record<string, string> = {
  access_denied: 'The portal declined the sign-in request.',
  redirect_loop: 'Sign-in kept looping. Clear this site’s data, then try again.',
}

export const UNAVAILABLE_MESSAGE =
  'The AICOUNTLY sign-in service is temporarily unavailable. You are still signed in — try again in a moment.'

/** The portal could not issue a one-time sign-in code (`sso_error`). */
export const SIGN_IN_RETRYING_MESSAGE = 'The AICOUNTLY sign-in service is temporarily unavailable. Trying again…'
export const SIGN_IN_UNAVAILABLE_MESSAGE = 'The AICOUNTLY sign-in service is temporarily unavailable. Try again in a moment.'

/**
 * A sign-in that did not complete. A 4xx from the portal is a definite answer
 * (spent or expired link) and is explained; anything else — a timeout, the
 * network, a 5xx — is an outage, and says so.
 */
function failedSignIn(err: unknown): AuthState {
  const definite = err instanceof AuthError && err.status >= 400 && err.status < 500 && err.status !== 429 && err.status !== 408
  return definite
    ? { status: 'signed-out', message: (err as AuthError).message }
    : { status: 'signed-out', message: UNAVAILABLE_MESSAGE, unavailable: true }
}

function describePortalError(code: string): string {
  return PORTAL_ERROR_MESSAGES[code] ?? `The portal reported an error (${code}).`
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>({ status: 'loading', message: null })

  // StrictMode runs effects twice in development. Booting twice would send two
  // portal jumps and burn two of the three tries the redirect guard allows, so
  // the second pass is skipped outright.
  const booted = useRef(false)

  useEffect(() => {
    if (booted.current) return
    booted.current = true

    // No "cancelled" flag and no cleanup here. StrictMode's simulated unmount
    // would set it before the real mount re-ran, and because `booted` correctly
    // suppresses the second run, nothing would ever clear it again — the app
    // would sit on "Signing you in…" forever in development.
    const settle = setState

    async function boot() {
      const callback = readAuthCallback()

      // Credentials never stay in the address bar (history, analytics, screenshots).
      // One on any page but the callback is a forged or stray link: dropped, not stored.
      if (callback.hasCredential || callback.authError || callback.onCallbackPath) {
        clearCallbackFromUrl()
      } else {
        dropStrayCredentials()
      }

      // Only a sign-in this tab started is accepted: the nonce must come back with it (I-17).
      // A launcher's hand-off (empty state, no cb_state) is never used: the app starts its own.
      const action = callbackAction(callback, verifyCallback(callback), URL_TOKEN_FALLBACK)

      if (action.kind === 'portal-error') {
        // The portal declined a sign-in this tab asked for. No session is touched:
        // an error in an address is not a reason to sign anyone out (I-16).
        settle({ status: 'signed-out', message: describePortalError(action.error) })
        return
      }

      if (action.kind === 'retry-later') {
        // The portal could not issue a one-time code (sso_error). Say so, and start
        // again shortly — a bounded number of times, then leave it to Retry.
        if (!allowSsoRetry()) {
          settle({ status: 'signed-out', message: SIGN_IN_UNAVAILABLE_MESSAGE, unavailable: true })
          return
        }
        settle({ status: 'signed-out', message: SIGN_IN_RETRYING_MESSAGE, unavailable: true })
        setTimeout(() => {
          if (!redirectToPortalSso()) settle({ status: 'signed-out', message: SIGN_IN_UNAVAILABLE_MESSAGE, unavailable: true })
        }, SSO_RETRY_DELAY_MS)
        return
      }

      // The one-time code is redeemed for the token, which is held in memory and
      // reaches localStorage only once the portal accepts it.
      let staged = false
      if (action.kind === 'exchange-sso' || action.kind === 'exchange-code' || action.kind === 'token') {
        try {
          const token =
            action.kind === 'exchange-sso' ? await exchangeSsoCode(action.ssoCode, action.cbState)
              : action.kind === 'exchange-code' ? await exchangeAuthCode(action.code)
                : action.authToken
          if (!token) throw new AuthError('This sign-in link is no longer supported. Sign in again.', 400)
          stageAuthToken(token)
          staged = true
        } catch (err) {
          // A refused sso_code is spent: start again with a fresh nonce (bounded, loop-guarded).
          if (action.kind === 'exchange-sso' && err instanceof AuthError && err.status === 401 && allowSsoRetry() && redirectToPortalSso()) return
          settle(failedSignIn(err))
          return
        }
      }

      if (!getAuthToken()) {
        // A deliberate sign-out must not be undone by the automatic jump below.
        if (isLogoutInProgress()) {
          settle({ status: 'signed-out', message: null })
          return
        }

        // An external guest opening an invitation link has no AICOUNTLY
        // account and must not be sent to the portal (§23). They continue as a
        // guest, and the API authenticates them by their invitation token.
        if (isGuestPath(window.location.pathname) || hasGuestToken()) {
          settle({ status: 'guest', message: null })
          return
        }
        if (!redirectToPortalSso()) {
          settle({
            status: 'signed-out',
            message: 'Sign-in kept looping. Clear this site’s data, then try again.',
          })
        }
        return
      }

      try {
        await ensureSesKey()
        if (staged) {
          // The portal accepted the token: now it may reach localStorage.
          commitAuthToken()
          clearLogoutFlag()
          clearRedirectGuard()
        }
        settle({ status: 'authenticated', message: null })
      } catch (err) {
        if (staged) {
          // Nothing stored was touched; the refused token is simply dropped.
          discardStagedAuthToken()
          settle(failedSignIn(err))
          return
        }
        // 401 means the stored auth_token is spent. Anything else — the portal
        // being down, a timeout — must not silently discard a good token, so it
        // is reported instead of bounced.
        if (err instanceof AuthError && err.status === 401) {
          clearAllTokens()
          if (!redirectToPortalSso()) {
            settle({ status: 'signed-out', message: 'Your session has expired.' })
          }
          return
        }
        // The portal could not answer (down, slow, 5xx): the session is kept, and the
        // person is told the service is unavailable and can retry (I-16, spec 3.8).
        settle({ status: 'signed-out', message: UNAVAILABLE_MESSAGE, unavailable: true })
      }
    }

    void boot()
  }, [])

  const value: AuthContextValue = {
    ...state,
    retry: () => window.location.reload(),
    signIn: () => {
      clearLogoutFlag()
      clearRedirectGuard()
      setState({ status: 'loading', message: null })
      redirectToPortalLoginForm()
    },
    signOut: () => {
      setState({ status: 'loading', message: null })
      performLogout()
    },
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>')
  return ctx
}
