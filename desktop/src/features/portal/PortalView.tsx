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
 * session, Support requests, Sessions, Computers, Settings and, for an
 * administrator, the administration pages — so what a person can do here is
 * exactly what they can do in a browser, with the same permissions.
 */
/**
 * The last page this window asked the portal to open. Kept outside the
 * component because the portal outlives it: leaving the Remote tab and coming
 * back must find the person where they were, not on the dashboard again.
 */
let lastOpened: string | null = null

/** For tests: forget what the portal was last asked to open. */
export function forgetPortalPage(): void {
  lastOpened = null
}

export function PortalView({
  path,
  navigateKey = 0,
  hidden = false,
}: {
  /** The page to open, e.g. `/` or `/desktop-signin?code=ABCD-1234`. */
  path: string
  /** Changing it opens `path` again, even if it has not changed. */
  navigateKey?: number
  /** Something of this window's own is in front: take the portal away. */
  hidden?: boolean
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
