import { useEffect } from 'react'
import { isRouteErrorResponse, useRouteError } from 'react-router-dom'

import EmptyState from './EmptyState'

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

  useEffect(() => {
    console.error('A screen in AICOUNTLY Remote failed', error)
  }, [error])

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
