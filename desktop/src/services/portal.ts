/**
 * The AICOUNTLY portal sign-in exchange — the desktop half.
 *
 * The browser round trip (opening the portal, waiting for its redirect) is a
 * native capability and lives in Rust — see `services/tauri.ts#beginSignIn`
 * and `src-tauri/src/signin.rs`. What crosses back from there is a raw
 * `auth_token`; this module does the one thing only JavaScript needs to:
 * exchange it for a `ses_key` and set it as this window's session, exactly
 * the call `web/src/auth/portal.ts` makes for the browser, with the same
 * relay-then-direct fallback.
 *
 * See docs/auth/AICOUNTLY_AUTH_WORKFLOW.md.
 */

import { setSessionKey } from './api'

/**
 * `seskey`, `seskey/refresh` and `validatesession` always answer on
 * my.aicountly.com in every environment — see "Host mapping" in
 * AICOUNTLY_AUTH_WORKFLOW.md. Calling a sandbox portal host for this is the
 * documented way to break sandbox sign-in, so this is a fixed constant and
 * never `AgentConfig.portalUrl` — that field is the *login* redirect target,
 * and can legitimately point at a sandbox host.
 */
const PORTAL_AUTH_API = 'https://my.aicountly.com'

interface SessionKeyResponse {
  ses_key?: string
  sesKey?: string
  token?: string
  access_token?: string
}

function fetchSessionKey(url: string, authToken: string): Promise<Response> {
  return fetch(url, {
    method: 'POST',
    headers: { Authorization: `Bearer ${authToken}` },
  })
}

function extractSessionKey(data: SessionKeyResponse): string | null {
  return data.ses_key ?? data.sesKey ?? data.token ?? data.access_token ?? null
}

/**
 * Exchange a portal `auth_token` for a `ses_key`, and set it as the session
 * this window's API calls use from here on.
 *
 * Tries this product's own relay first — the same reason the browser does:
 * a new product host is not in the portal's CORS allowlist on day one, and
 * the relay sidesteps that by calling the portal server-to-server. A relay
 * that is missing (404) or broken (5xx) falls back to the portal directly;
 * any other answer, a refusal included, is trusted rather than retried.
 */
export async function signInWithAuthToken(apiBaseUrl: string, authToken: string): Promise<void> {
  const relayUrl = `${apiBaseUrl.replace(/\/$/, '')}/global/seskey`
  const directUrl = `${PORTAL_AUTH_API}/api/global/seskey`

  let response: Response

  try {
    response = await fetchSessionKey(relayUrl, authToken)

    if (!response.ok && (response.status === 404 || response.status >= 500)) {
      response = await fetchSessionKey(directUrl, authToken)
    }
  } catch {
    response = await fetchSessionKey(directUrl, authToken)
  }

  if (!response.ok) {
    throw new Error('AICOUNTLY refused this sign-in. Please try again.')
  }

  const data = (await response.json().catch(() => null)) as SessionKeyResponse | null
  const key = data ? extractSessionKey(data) : null

  if (!key) {
    throw new Error('AICOUNTLY did not return a session key. Please try signing in again.')
  }

  setSessionKey(key)
}
