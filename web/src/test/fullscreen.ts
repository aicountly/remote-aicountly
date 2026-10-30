import { vi } from 'vitest'

/**
 * The Fullscreen API, for a test.
 *
 * jsdom has none, which is the "this browser cannot" case the stage has to
 * handle — so, like every other capability (setup.ts), nothing installs it
 * globally. A test that needs it installs this, drives it the way the browser
 * would, and uninstalls it afterwards, which puts back whatever was there.
 */
export function installFullscreen() {
  let element: Element | null = null
  const changed = () => document.dispatchEvent(new Event('fullscreenchange'))

  const requestFullscreen = vi.fn(async function (this: Element) {
    element = this
    changed()
  })

  const exitFullscreen = vi.fn(async () => {
    // What a browser does when asked to leave a full screen it is not in.
    if (element === null) throw new TypeError('Not in full screen.')

    element = null
    changed()
  })

  const restore = [
    replace(document, 'fullscreenEnabled', { get: () => true }),
    replace(document, 'fullscreenElement', { get: () => element }),
    replace(document, 'exitFullscreen', { value: exitFullscreen }),
    replace(Element.prototype, 'requestFullscreen', { value: requestFullscreen }),
  ]

  return {
    requestFullscreen,
    exitFullscreen,
    /** The element in full screen, as the browser would report it. */
    get element(): HTMLElement | null {
      return element as HTMLElement | null
    },
    /** The browser leaving full screen by itself — Esc, or its own exit control — without the page asking. */
    escape() {
      element = null
      changed()
    },
    uninstall() {
      for (const undo of restore) undo()
    },
  }
}

/**
 * Define a property for the length of a test, returning how to put back what
 * was there before — nothing, in jsdom today, but a jsdom that gains the real
 * thing must get it back rather than lose it.
 */
export function replace(target: object, name: string, descriptor: PropertyDescriptor): () => void {
  const original = Object.getOwnPropertyDescriptor(target, name)

  Object.defineProperty(target, name, { configurable: true, ...descriptor })

  return () => {
    if (original) Object.defineProperty(target, name, original)
    else delete (target as Record<string, unknown>)[name]
  }
}
