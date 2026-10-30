import { useCallback, useEffect, useMemo, useState } from 'react'
import type { RefObject } from 'react'

/**
 * One element in full screen, with the Fullscreen API (§33).
 *
 * The browser owns the state, not this hook: Esc, the browser's own "exit full
 * screen" and a navigation can all end it without the page being asked, so
 * `active` is read back from `fullscreenchange` rather than remembered from the
 * last click — a button that says "Exit full screen" when it is not would be
 * the interface lying about where the person is.
 */

/**
 * Whether this page may put an element into full screen at all.
 *
 * Detected, like every other capability Remote offers (§50): Safari on an
 * iPhone has no element full screen, and a page framed without
 * `allowfullscreen` is refused it, so both are offered no button rather than a
 * button that does nothing.
 */
export function fullscreenSupported(): boolean {
  if (typeof document === 'undefined') return false

  return document.fullscreenEnabled === true && typeof document.documentElement.requestFullscreen === 'function'
}

export interface Fullscreen {
  supported: boolean
  /** Whether the target is the element in full screen right now. */
  active: boolean
  enter: () => Promise<void>
  /** Leave full screen, if the target is in it. Anything else in full screen is left alone. */
  exit: () => Promise<void>
}

export function useFullscreen(target: RefObject<HTMLElement | null>): Fullscreen {
  const [supported] = useState(fullscreenSupported)
  const [active, setActive] = useState(false)

  useEffect(() => {
    const element = target.current
    if (!supported || !element) return

    const onChange = () => setActive(document.fullscreenElement === element)

    document.addEventListener('fullscreenchange', onChange)
    onChange()

    return () => {
      document.removeEventListener('fullscreenchange', onChange)

      // The room going away — the session ending, the person leaving — must not leave the browser full
      // screen around a page the stage is no longer on.
      if (document.fullscreenElement === element) void document.exitFullscreen().catch(() => undefined)
    }
  }, [supported, target])

  const enter = useCallback(async () => {
    const element = target.current
    if (!supported || !element || document.fullscreenElement === element) return

    try {
      // `hide`: on a phone the browser's own bars would otherwise take back the room full screen gave.
      await element.requestFullscreen({ navigationUI: 'hide' })
    } catch {
      // Refused — no user gesture, a permissions policy. The stage stays where it is, which is a fine
      // place for it to be.
    }
  }, [supported, target])

  const exit = useCallback(async () => {
    if (!supported || target.current === null || document.fullscreenElement !== target.current) return

    try {
      await document.exitFullscreen()
    } catch {
      // Already on its way out.
    }
  }, [supported, target])

  return useMemo(() => ({ supported, active, enter, exit }), [supported, active, enter, exit])
}
