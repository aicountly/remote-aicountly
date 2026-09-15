/**
 * Preserving a `/desktop-signin?code=…` deep link across the portal sign-in
 * round trip.
 *
 * A person not yet signed in who opens the desktop agent's confirmation link
 * is about to be bounced to the portal and back by `AuthProvider`, which
 * always returns to a bare `/auth/callback` with no query string (see
 * `auth/portal.ts`'s `buildCallbackUrl`). `stashDesktopSignInReturnPath` is
 * called from `main.tsx`, before that redirect effect can ever run, so the
 * original path survives in `sessionStorage`; `consumeDesktopSignInReturnPath`
 * is called once from `AppShell`, which only mounts once signed in, to send
 * the person back to it.
 */

const RETURN_PATH_KEY = 'remote:desktopSignInReturnPath'

/** Call unconditionally on every page load — it only ever writes when the path matches. */
export function stashDesktopSignInReturnPath(location: Pick<Location, 'pathname' | 'search'>): void {
  if (location.pathname !== '/desktop-signin' || !location.search) return

  try {
    sessionStorage.setItem(RETURN_PATH_KEY, location.pathname + location.search)
  } catch {
    /* private mode — the deep link simply does not survive the round trip */
  }
}

/** Reads and clears the stash. Returns null when there was nothing to return to. */
export function consumeDesktopSignInReturnPath(): string | null {
  try {
    const stashed = sessionStorage.getItem(RETURN_PATH_KEY)
    if (stashed) sessionStorage.removeItem(RETURN_PATH_KEY)

    return stashed
  } catch {
    return null
  }
}
