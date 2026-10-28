import { useEffect, useRef, useState } from 'react'
import { useLocation } from 'react-router-dom'

import { useRemote } from '../app/RemoteProvider'
import { sesKeyForEmbed } from '../auth/portal'
import { buildAppLaunchUrl, getAppById } from '../services/appLauncher'
import { CONNECT_PRODUCT, connectScopeFrom, initialScope, scopeUpdate, startConnect, stopConnect, updateConnect } from './connect'
import { resolveConnectOrigin } from './loader'

/**
 * Stacking level of the whole widget — launcher, panel and its dialogs share
 * one stacking context on their own element under <body>.
 *
 * Above Remote's sticky chrome — the header (30), the mobile navigation and its
 * scrim (24–25) and the organisation menu (40) — so none of them cuts into the
 * open panel; below every Remote dialog (`.modal-backdrop`, 100) and the app
 * launcher's popover (1000), so Connect never covers either.
 */
export const CONNECT_Z_INDEX = 50

/**
 * Paths that host a live remote session: the session room (host and viewer,
 * full screen) and a guest's invitation link. Both sit outside the shell today,
 * so the shell — and this mount with it — is already gone there; the check is
 * kept here so a session surface moved into the shell can never end up with a
 * Connect launcher over its controls. (`/join` alone is the in-shell "Join
 * session" form, not a session.)
 */
const LIVE_SESSION_PATH = /^\/(?:room|join)\/[^/]+/

export function isLiveSessionPath(pathname: string): boolean {
  return LIVE_SESSION_PATH.test(pathname)
}

/**
 * AICOUNTLY Connect — conversations, calls and AI Pulse (pinned first, answered
 * live by Pulse through Connect) — for the signed-in person.
 *
 * Rendered by the app shell, which exists only while somebody is signed in, so
 * mounting here IS "after sign-in", and unmounting (sign-out, or entering the
 * full-screen session room, which sits outside the shell) destroys the widget.
 * It draws nothing itself: the widget lives in a shadow root on its own element
 * under <body>, where Remote's styles cannot reach it and it cannot reach them.
 *
 * On a live session path the widget is not hidden but taken down entirely
 * (destroy()), rather than kept with `launcher: false`: `calling` is read at
 * mount only, and a hidden widget would still run Connect's call engine —
 * polling for calls, ringing an incoming-call dialog over the shared screen,
 * and asking for the microphone — in competition with the session's own media.
 * Leaving the session mounts it again.
 *
 * Nothing here blocks or breaks Remote: if Connect cannot be loaded, the shell
 * is exactly as it was, with no launcher and at most one console warning.
 */
export function ConnectMount() {
  const { status, scopeType, companyId } = useRemote()
  const { pathname } = useLocation()

  const scope = connectScopeFrom(scopeType, companyId)
  const suspended = isLiveSessionPath(pathname)

  // Start once the bootstrap has settled, so the first init() already carries
  // the organisation rather than starting in Personal and switching a moment
  // later. A later refresh (status back to 'loading') does not restart it.
  const [settled, setSettled] = useState(status !== 'loading')
  useEffect(() => {
    if (status !== 'loading') setSettled(true)
  }, [status])

  // The widget is started from an effect; it must start with the scope of the
  // render it is started from, not the first one ever seen.
  const latest = useRef(scope)
  useEffect(() => {
    latest.current = scope
  })

  const active = settled && !suspended
  const scopeKey = `${scope.companyId}`
  /** The scope the widget has now — init() or the last update(). */
  const sent = useRef<string | null>(null)

  useEffect(() => {
    if (!active) return

    sent.current = scopeKey
    const advisor = getAppById('advisor')
    void startConnect(resolveConnectOrigin(), {
      getSesKey: sesKeyForEmbed,
      product: CONNECT_PRODUCT,
      ...initialScope(latest.current),
      advisorUrl: advisor ? buildAppLaunchUrl(advisor) : undefined,
      position: 'bottom-right',
      zIndex: CONNECT_Z_INDEX,
    })

    return () => {
      sent.current = null
      stopConnect()
    }
    // Started per activation; the scope at that moment is read through `latest`.
  }, [active])

  // Organisation switched in the header (or the bootstrap restored another one).
  useEffect(() => {
    if (!active || sent.current === scopeKey) return
    sent.current = scopeKey
    updateConnect(scopeUpdate(latest.current))
  }, [active, scopeKey])

  return null
}
