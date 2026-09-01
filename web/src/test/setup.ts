import '@testing-library/jest-dom/vitest'
import { afterEach } from 'vitest'
import { cleanup } from '@testing-library/react'

import { resetCapabilitiesCache } from '../services/browser/capabilities'

/**
 * jsdom has no `navigator.mediaDevices`, no `RTCPeerConnection` and no
 * `matchMedia` — which is exactly the "browser cannot do this" case Remote has
 * to handle, so nothing is stubbed globally. Tests that need a capability
 * install it themselves and it is torn down here.
 */
afterEach(() => {
  cleanup()
  resetCapabilitiesCache()

  // Anything a test attached to navigator or window is removed, so a test that
  // grants screen capture cannot make the next one pass by accident.
  delete (navigator as unknown as { mediaDevices?: unknown }).mediaDevices
  delete (window as unknown as { RTCPeerConnection?: unknown }).RTCPeerConnection
  delete (window as unknown as { MediaRecorder?: unknown }).MediaRecorder
})

if (!window.matchMedia) {
  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: (query: string) => ({
      matches: false,
      media: query,
      onchange: null,
      addEventListener: () => {},
      removeEventListener: () => {},
      addListener: () => {},
      removeListener: () => {},
      dispatchEvent: () => false,
    }),
  })
}
