import { useCallback, useEffect, useRef, useState } from 'react'

import * as bridge from '../../services/tauri'
import type { PortalBounds } from '../../services/tauri'

/**
 * The AICOUNTLY Remote web portal, inside this window (`src-tauri/src/portal.rs`).
 *
 * The portal is drawn by the operating system's web view as a layer **over**
 * this window, not inside its page — so this component's whole job is to keep
 * that layer exactly over the space it reserves: shown with the right page
 * when it mounts, moved whenever the space moves or resizes (a session banner
 * appearing above it, the window resized), and taken off the screen when it
 * unmounts or when something of this window's own must be seen (`hidden`).
 *
 * Its menus are the web portal's own — Dashboard, Start session, Join
 * session, Support requests, Sessions, Computers, Settings — so what a person
 * can do here is exactly what they can do in a browser, with the same
 * permissions.
 *
 * Back, Reload and Open in browser are shown only while the portal is on a
 * sign-in page (`signInPage`): the AICOUNTLY sign-in, or an identity
 * provider's, which is where somebody can get stuck. On the portal's own
 * pages they are signed in, the portal has its own menus, and the window's
 * header carries Reload and Open in browser as two small buttons instead.
 */
/**
 * The last page this window asked the portal to open. Kept outside the
 * component because the portal outlives it: leaving the Remote tab and coming
 * back must find the person where they were, not on the dashboard again.
 */
let lastOpened: string | null = null

/** Reload, and Open in browser, for the window's header while the portal is on its own pages. */
export function PortalTools() {
  return (
    <div className="app__tools" role="group" aria-label="This page">
      <button
        type="button"
        className="app__tool"
        title="Reload this page"
        aria-label="Reload this page"
        onClick={() => void bridge.portalAction('reload').catch(() => undefined)}
      >
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
          <path d="M20 12a8 8 0 1 1-2.34-5.66" />
          <path d="M20 4v5h-5" />
        </svg>
      </button>
      <button
        type="button"
        className="app__tool"
        title="Open this page in your browser"
        aria-label="Open this page in your browser"
        onClick={() => void bridge.portalAction('open_in_browser').catch(() => undefined)}
      >
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
          <path d="M14 4h6v6" />
          <path d="M20 4l-9 9" />
          <path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" />
        </svg>
      </button>
    </div>
  )
}

/** For tests: forget what the portal was last asked to open. */
export function forgetPortalPage(): void {
  lastOpened = null
}

export function PortalView({
  path,
  navigateKey = 0,
  hidden = false,
  signInPage = false,
}: {
  /** The page to open, e.g. `/` or `/desktop-signin?code=ABCD-1234`. */
  path: string
  /** Changing it opens `path` again, even if it has not changed. */
  navigateKey?: number
  /** Something of this window's own is in front: take the portal away. */
  hidden?: boolean
  /** The portal is on a sign-in page, not one of its own. */
  signInPage?: boolean
}) {
  const frame = useRef<HTMLDivElement>(null)
  const [error, setError] = useState<string | null>(null)

  const measure = useCallback((): PortalBounds | null => {
    const rect = frame.current?.getBoundingClientRect()
    if (!rect) return null

    return { x: rect.left, y: rect.top, width: rect.width, height: rect.height }
  }, [])

  // Show it, with the page asked for — opening the page only when it is a new
  // request, so coming back from `hidden` keeps whatever the person was doing.
  useEffect(() => {
    if (hidden) {
      void bridge.portalHide().catch(() => undefined)
      return
    }

    const bounds = measure()
    if (!bounds) return

    const request = `${navigateKey}:${path}`
    const open = lastOpened === request ? null : path
    lastOpened = request

    bridge.portalShow(open, bounds).then(
      () => setError(null),
      (caught: unknown) => setError(typeof caught === 'string' ? caught : 'AICOUNTLY Remote could not be opened here.'),
    )
  }, [path, navigateKey, hidden, measure])

  // Follow the space it reserves.
  useEffect(() => {
    if (hidden) return undefined

    let pending = 0
    const follow = () => {
      cancelAnimationFrame(pending)
      pending = requestAnimationFrame(() => {
        const bounds = measure()
        if (bounds) void bridge.portalSetBounds(bounds).catch(() => undefined)
      })
    }

    const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(follow)
    if (frame.current) observer?.observe(frame.current)
    window.addEventListener('resize', follow)

    return () => {
      cancelAnimationFrame(pending)
      observer?.disconnect()
      window.removeEventListener('resize', follow)
    }
  }, [hidden, measure])

  // Gone with this component; the next one shows it again where it belongs.
  useEffect(
    () => () => {
      void bridge.portalHide().catch(() => undefined)
    },
    [],
  )

  return (
    <section className="portal" aria-label="AICOUNTLY Remote">
      {signInPage ? (
        <div className="portal__toolbar">
          <button type="button" className="portal__tool" onClick={() => void bridge.portalAction('back').catch(() => undefined)}>
            ← Back
          </button>
          <button type="button" className="portal__tool" onClick={() => void bridge.portalAction('reload').catch(() => undefined)}>
            Reload
          </button>
          <span className="portal__spacer" />
          <button
            type="button"
            className="portal__tool"
            onClick={() => void bridge.portalAction('open_in_browser').catch(() => undefined)}
          >
            Open in browser
          </button>
        </div>
      ) : null}

      {error ? (
        <div className="notice notice--danger portal__error" role="alert">
          {error}
        </div>
      ) : null}

      {/* The space the portal is drawn over. Empty on purpose. */}
      <div ref={frame} className="portal__frame" data-testid="portal-frame" />
    </section>
  )
}
