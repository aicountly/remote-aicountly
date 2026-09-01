/**
 * The one place the browser talks to the Remote API.
 *
 * Everything goes through {@link apiFetch}: authentication, the guest token,
 * the launch-context header, timeouts, and — most importantly — turning a
 * structured backend error into a {@link RemoteApiError} carrying its machine
 * code. Screens switch on that code to show a written explanation instead of a
 * status number (§39), which only works because no component is allowed to call
 * `fetch` on its own.
 */

import { getApiBaseUrl } from '../../config'
import { ensureSesKey, AuthError } from '../../auth/portal'

const REQUEST_TIMEOUT_MS = 20_000

/** Where a guest's session credential lives for the life of the tab. */
const GUEST_TOKEN_KEY = 'remote:guestToken'

/**
 * A failure the API described.
 *
 * `code` is the contract — `SURFACE_NOT_ALLOWED`, `INVITATION_EXPIRED`,
 * `COMPANY_ACCESS_DENIED`. `message` is already written for a person to read,
 * so it is safe to show as-is when a screen has nothing more specific.
 */
export class RemoteApiError extends Error {
  readonly code: string
  readonly status: number
  readonly details: Record<string, unknown>

  constructor(code: string, message: string, status: number, details: Record<string, unknown> = {}) {
    super(message)
    this.name = 'RemoteApiError'
    this.code = code
    this.status = status
    this.details = details
  }

  /** True when retrying might genuinely work — used to decide whether to offer it. */
  get isRetryable(): boolean {
    return this.status === 0 || this.status === 429 || this.status >= 500
  }
}

// ---------------------------------------------------------------------------
// Guest credential
// ---------------------------------------------------------------------------

/**
 * A guest has no AICOUNTLY account, so a redeemed invitation returns a token
 * bound to one participant in one session.
 *
 * It lives in `sessionStorage`, not `localStorage`: it is scoped to this one
 * visit, and leaving it behind in a shared browser after the tab closes would
 * be exactly the wrong default for a credential that admits someone to a
 * screen-sharing session.
 */
export function setGuestToken(token: string | null): void {
  try {
    if (token) sessionStorage.setItem(GUEST_TOKEN_KEY, token)
    else sessionStorage.removeItem(GUEST_TOKEN_KEY)
  } catch {
    /* private mode — the token simply does not survive a reload */
  }
}

export function getGuestToken(): string | null {
  try {
    return sessionStorage.getItem(GUEST_TOKEN_KEY)
  } catch {
    return null
  }
}

// ---------------------------------------------------------------------------
// Launch context (§6C)
// ---------------------------------------------------------------------------

let launchContextToken: string | null = null

/**
 * Hold the signed context another AICOUNTLY product sent us.
 *
 * It is kept in memory only and sent on the requests that can consume it. The
 * backend burns its `jti` on first use, so a second request carrying the same
 * token is rejected as a replay — which is why it is cleared as soon as it has
 * been spent.
 */
export function setLaunchContextToken(token: string | null): void {
  launchContextToken = token
}

export function consumeLaunchContextToken(): string | null {
  const token = launchContextToken
  launchContextToken = null

  return token
}

export function peekLaunchContextToken(): string | null {
  return launchContextToken
}

// ---------------------------------------------------------------------------
// Fetch
// ---------------------------------------------------------------------------

interface ApiOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  /** Send (and spend) the launch-context header on this request. */
  withLaunchContext?: boolean
  /** Skip authentication entirely — only the anonymous invitation redemption. */
  anonymous?: boolean
  signal?: AbortSignal
}

interface ApiEnvelope<T> {
  data: T
  meta?: Record<string, unknown>
}

async function authorization(anonymous: boolean): Promise<string | null> {
  const guestToken = getGuestToken()
  if (guestToken) return `Bearer ${guestToken}`

  if (anonymous) return null

  // Minting a ses_key from the long-lived auth_token happens in the portal
  // module; concurrent callers there share one request.
  const sesKey = await ensureSesKey()

  return `Bearer ${sesKey}`
}

/**
 * Call the Remote API and unwrap `{ data }`.
 *
 * @throws {RemoteApiError} for every failure, including a network one — so a
 *         caller has exactly one thing to catch.
 */
export async function apiFetch<T>(path: string, options: ApiOptions = {}): Promise<T> {
  const { method = 'GET', body, withLaunchContext = false, anonymous = false, signal } = options

  const headers: Record<string, string> = { Accept: 'application/json' }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }

  try {
    const auth = await authorization(anonymous)
    if (auth) headers.Authorization = auth
  } catch (error) {
    // A failure to mint a session key is an authentication failure, not a
    // failure of whatever the caller was trying to do.
    if (error instanceof AuthError) {
      throw new RemoteApiError('UNAUTHENTICATED', 'Your session has expired. Sign in again.', 401)
    }
    throw error
  }

  if (withLaunchContext) {
    const context = consumeLaunchContextToken()
    if (context) headers['X-Remote-Context'] = context
  }

  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS)

  // An external abort (a component unmounting) must also stop the request.
  const onExternalAbort = () => controller.abort()
  signal?.addEventListener('abort', onExternalAbort)

  let response: Response
  try {
    response = await fetch(`${getApiBaseUrl()}/v1/remote${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: controller.signal,
    })
  } catch (error) {
    if (signal?.aborted) {
      throw new RemoteApiError('ABORTED', 'The request was cancelled.', 0)
    }
    if ((error as Error)?.name === 'AbortError') {
      throw new RemoteApiError('TIMEOUT', 'That took too long. Check your connection and try again.', 0)
    }

    throw new RemoteApiError('NETWORK_ERROR', 'We could not reach AICOUNTLY Remote. Check your connection.', 0)
  } finally {
    clearTimeout(timer)
    signal?.removeEventListener('abort', onExternalAbort)
  }

  if (response.status === 204) {
    return undefined as T
  }

  const text = await response.text()
  let payload: unknown = null

  if (text) {
    try {
      payload = JSON.parse(text)
    } catch {
      payload = null
    }
  }

  if (!response.ok) {
    const error = (payload as { error?: { code?: string; message?: string; details?: Record<string, unknown> } })?.error

    throw new RemoteApiError(
      error?.code ?? 'REQUEST_FAILED',
      error?.message ?? 'Something went wrong. Please try again.',
      response.status,
      error?.details ?? {},
    )
  }

  return (payload as ApiEnvelope<T>)?.data as T
}

/** Same as {@link apiFetch}, but also returns the `meta` block (totals, paging). */
export async function apiFetchWithMeta<T>(
  path: string,
  options: ApiOptions = {},
): Promise<{ data: T; meta: Record<string, unknown> }> {
  const { method = 'GET', body, anonymous = false, signal } = options

  const headers: Record<string, string> = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  const auth = await authorization(anonymous)
  if (auth) headers.Authorization = auth

  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS)
  const onExternalAbort = () => controller.abort()
  signal?.addEventListener('abort', onExternalAbort)

  let response: Response
  try {
    response = await fetch(`${getApiBaseUrl()}/v1/remote${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: controller.signal,
    })
  } catch (error) {
    if ((error as Error)?.name === 'AbortError' && !signal?.aborted) {
      throw new RemoteApiError('TIMEOUT', 'That took too long. Check your connection and try again.', 0)
    }
    throw new RemoteApiError('NETWORK_ERROR', 'We could not reach AICOUNTLY Remote. Check your connection.', 0)
  } finally {
    clearTimeout(timer)
    signal?.removeEventListener('abort', onExternalAbort)
  }

  const text = await response.text()
  const payload = text ? (JSON.parse(text) as ApiEnvelope<T> & { error?: { code: string; message: string } }) : null

  if (!response.ok) {
    throw new RemoteApiError(
      payload?.error?.code ?? 'REQUEST_FAILED',
      payload?.error?.message ?? 'Something went wrong. Please try again.',
      response.status,
    )
  }

  return { data: payload?.data as T, meta: payload?.meta ?? {} }
}
