import { useEffect } from 'react'
import { isRouteErrorResponse, useRouteError } from 'react-router-dom'

import EmptyState from './EmptyState'

const RELOADED_KEY = 'remote:reloaded-for-new-build'

/** Whether a failure is a screen whose code is from a build that has since been replaced. */
export function isStaleBuild(error: unknown): boolean {
  const message = error instanceof Error ? error.message : typeof error === 'string' ? error : ''

  // Chromium and WebView2, Safari and WKWebView, Firefox.
  return /Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module/i.test(
    message,
  )
}

/** True the first time in this tab, within a minute; false after that. */
function reloadOnce(): boolean {
  try {
    const last = Number(sessionStorage.getItem(RELOADED_KEY) ?? 0)
    if (Date.now() - last < 60_000) return false

    sessionStorage.setItem(RELOADED_KEY, String(Date.now()))
    return true
  } catch {
    // No storage, no way to tell a second attempt from the first: offer the button instead.
    return false
  }
}

/**
 * What a screen that failed shows instead of itself.
 *
 * Without it React Router fell back to its own development page —
 * "Unexpected Application Error!" and a stack trace — which tells a person
 * nothing they can act on and looks like the product is broken rather than
 * one screen. This says what happened in a sentence and offers the two things
 * that help: reload, or go back to the dashboard. A plain link rather than the
 * router's, because the router is what failed.
 *
 * The error itself still goes to the console for whoever is diagnosing it.
 */
export default function RouteError() {
  const error = useRouteError()
  const stale = isStaleBuild(error)

  useEffect(() => {
    console.error('A screen in AICOUNTLY Remote failed', error)

    // A deploy replaces every screen's code, and a page that was open across
    // it — the portal inside the desktop application stays open for days —
    // asks for files that are no longer there. Loading the new build is the
    // whole fix, so it is done rather than offered: once, so a deploy that is
    // itself broken shows this screen instead of reloading for ever.
    if (stale && reloadOnce()) window.location.reload()
  }, [error, stale])

  // A 404 is a page that does not exist; anything else is a screen that
  // broke — most often one whose code changed in a deploy while this tab was
  // open, which a reload fixes.
  const missing = isRouteErrorResponse(error) && error.status === 404

  return (
    <div className="page">
      <EmptyState
        title={missing ? 'That page isn’t part of AICOUNTLY Remote' : 'This screen could not be shown'}
        description={
          missing
            ? 'The link may be out of date, or the session it pointed to may have ended.'
            : 'Something went wrong while opening it. Reloading usually fixes this; if it keeps happening, tell AICOUNTLY support what you were doing.'
        }
        action={
          <span style={{ display: 'inline-flex', gap: 'var(--space-2, 0.5rem)' }}>
            <button type="button" className="btn btn--primary" onClick={() => window.location.reload()}>
              Reload
            </button>
            <a href="/" className="btn btn--secondary">
              Back to Remote
            </a>
          </span>
        }
      />
    </div>
  )
}
