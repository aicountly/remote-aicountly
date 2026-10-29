/**
 * The desktop application handing its embedded portal the AICOUNTLY sign-in
 * that confirmed the computer (docs/desktop/PORTAL.md, "Signing the portal in").
 *
 * Confirming a desktop sign-in sends the confirming page's `auth_token` along;
 * the server seals it under a one-time code that only the desktop agent
 * receives. The agent opens this application in its embedded portal at
 *
 *     /auth/desktop#desktop_handoff=<code>&n=<per-launch nonce>
 *
 * (a path of its own, so the page always loads afresh — a fragment-only change
 * to the page already showing would not), which is turned back into `/` here
 * before anything renders,
 * and this redeems the code — once — for the token, which AuthProvider then
 * stages, checks with the portal and stores exactly like any other sign-in.
 * So somebody who confirmed in their browser is not asked to sign in again
 * inside the application.
 *
 * Two rules keep this from being a way to sign somebody in as somebody else
 * (login CSRF):
 *
 *   * it runs only inside the desktop application's own web view, and only
 *     when the `n` in the address is the nonce that web view was started with
 *     (`features/desktop/embedding.ts`) — a link cannot know it;
 *   * the code leaves the address before anything else reads it, and is
 *     carried in the fragment, which is never sent to a server.
 */

import { getApiBaseUrl } from '../config'
import { desktopEmbedding } from '../features/desktop/embedding'
import type { DesktopEmbedding } from '../features/desktop/embedding'

const PARAM = 'desktop_handoff'
/** Where the desktop opens a hand-off; the app itself lives at `/`. */
export const HANDOFF_PATH = '/auth/desktop'
const NONCE_PARAM = 'n'
const REDEEM_TIMEOUT_MS = 15_000

export interface DesktopHandoff {
  code: string
  nonce: string
}

let captured: DesktopHandoff | null = null

/** Parse a hand-off out of a fragment, or null. */
export function parseDesktopHandoff(hash: string): DesktopHandoff | null {
  const params = new URLSearchParams(hash.startsWith('#') ? hash.slice(1) : hash)
  const code = params.get(PARAM)
  const nonce = params.get(NONCE_PARAM)

  if (!code || !/^[0-9a-f]{64}$/.test(code) || !nonce) return null

  return { code, nonce }
}

/**
 * Take a hand-off out of the address, before analytics, the router or
 * anything else sees it. Called first thing in main.tsx.
 */
export function captureDesktopHandoff(
  loc: { pathname: string; search: string; hash: string } = window.location,
  history: Pick<History, 'replaceState'> = window.history,
): void {
  const onHandoffPath = loc.pathname === HANDOFF_PATH
  if (!onHandoffPath && !loc.hash.includes(`${PARAM}=`)) return

  captured = parseDesktopHandoff(loc.hash)

  try {
    history.replaceState(null, '', onHandoffPath ? '/' : `${loc.pathname}${loc.search}`)
  } catch {
    /* the address keeps a spent code; nothing reads it again */
  }
}

/** The captured hand-off, once. */
export function takeDesktopHandoff(): DesktopHandoff | null {
  const handoff = captured
  captured = null
  return handoff
}

/**
 * Redeem a hand-off for the AICOUNTLY sign-in it carries.
 *
 * Null — never a throw — when it does not belong to this web view, was
 * already used, or the server could not be reached: the person is then simply
 * asked to sign in, as they would have been without it.
 */
export async function redeemDesktopHandoff(
  handoff: DesktopHandoff,
  embedding: DesktopEmbedding | null = desktopEmbedding(),
): Promise<string | null> {
  if (!embedding || embedding.nonce !== handoff.nonce) return null

  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), REDEEM_TIMEOUT_MS)

  try {
    const res = await fetch(`${getApiBaseUrl()}/v1/remote/desktop-signin/handoff`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ code: handoff.code }),
      cache: 'no-store',
      credentials: 'omit',
      signal: controller.signal,
    })
    if (!res.ok) return null

    const body = (await res.json().catch(() => null)) as { data?: { authToken?: unknown } } | null
    const token = body?.data?.authToken

    return typeof token === 'string' && token !== '' ? token : null
  } catch {
    return null
  } finally {
    clearTimeout(timer)
  }
}
