import { describe, expect, it, vi } from 'vitest'

import { describeCaptureLimitation, detectCapabilities, resetCapabilitiesCache } from './capabilities'

/**
 * Capability detection (§50, §64).
 *
 * The product has to be usable in a browser that cannot share a screen, and
 * honest in one that cannot report which surface was picked. Both of those
 * begin here, so this is where they are asserted.
 */

function setSecureContext(secure: boolean) {
  Object.defineProperty(window, 'isSecureContext', { value: secure, configurable: true })
}

function setMediaDevices(value: unknown) {
  Object.defineProperty(navigator, 'mediaDevices', { value, configurable: true })
}

describe('detectCapabilities', () => {
  it('reports nothing usable in a bare environment', () => {
    setSecureContext(false)
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...window.location, hostname: 'remote.aicountly.com' },
    })
    resetCapabilitiesCache()

    const capabilities = detectCapabilities()

    expect(capabilities.secureContext).toBe(false)
    expect(capabilities.screenCapture).toBe(false)
    expect(capabilities.microphone).toBe(false)
  })

  it('detects a fully capable Chromium-like browser', () => {
    setSecureContext(true)
    setMediaDevices({
      getDisplayMedia: vi.fn(),
      getUserMedia: vi.fn(),
      getSupportedConstraints: () => ({ displaySurface: true }),
      setCaptureHandleConfig: vi.fn(),
    })
    Object.defineProperty(window, 'RTCPeerConnection', {
      configurable: true,
      value: Object.assign(function RTCPeerConnectionStub() {}, {
        prototype: { createDataChannel: () => {} },
      }),
    })
    Object.defineProperty(window, 'MediaRecorder', { configurable: true, value: function () {} })
    resetCapabilitiesCache()

    const capabilities = detectCapabilities()

    expect(capabilities.screenCapture).toBe(true)
    expect(capabilities.webRtc).toBe(true)
    expect(capabilities.dataChannel).toBe(true)
    expect(capabilities.displaySurfaceDetection).toBe(true)
    expect(capabilities.safeShareContext).toBe(true)
    expect(capabilities.mediaRecorder).toBe(true)
  })

  it('reports no surface verification when the browser does not advertise it', () => {
    // Firefox and Safari. Sharing still works; verification does not, and the
    // UI has to be able to say so.
    setSecureContext(true)
    setMediaDevices({
      getDisplayMedia: vi.fn(),
      getUserMedia: vi.fn(),
      getSupportedConstraints: () => ({}),
    })
    Object.defineProperty(window, 'RTCPeerConnection', {
      configurable: true,
      value: function RTCPeerConnectionStub() {},
    })
    resetCapabilitiesCache()

    const capabilities = detectCapabilities()

    expect(capabilities.screenCapture).toBe(true)
    expect(capabilities.displaySurfaceDetection).toBe(false)
    expect(capabilities.safeShareContext).toBe(false)
  })

  it('treats localhost as a secure context', () => {
    setSecureContext(false)
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...window.location, hostname: 'localhost' },
    })
    setMediaDevices({ getDisplayMedia: vi.fn(), getUserMedia: vi.fn(), getSupportedConstraints: () => ({}) })
    resetCapabilitiesCache()

    expect(detectCapabilities().secureContext).toBe(true)
    expect(detectCapabilities().screenCapture).toBe(true)
  })
})

describe('describeCaptureLimitation', () => {
  it('names an insecure connection specifically', () => {
    const message = describeCaptureLimitation({
      screenCapture: false,
      webRtc: true,
      dataChannel: true,
      microphone: false,
      mediaRecorder: false,
      displaySurfaceDetection: false,
      safeShareContext: false,
      systemAudio: false,
      secureContext: false,
      coarsePointer: false,
    })

    expect(message).toMatch(/secure \(https\)/)
  })

  it('offers what still works when capture is unsupported', () => {
    const message = describeCaptureLimitation({
      screenCapture: false,
      webRtc: true,
      dataChannel: true,
      microphone: true,
      mediaRecorder: false,
      displaySurfaceDetection: false,
      safeShareContext: false,
      systemAudio: false,
      secureContext: true,
      coarsePointer: true,
    })

    expect(message).toMatch(/join a session/i)
    expect(message).toMatch(/chat/i)
  })

  it('returns null when everything needed is present', () => {
    expect(
      describeCaptureLimitation({
        screenCapture: true,
        webRtc: true,
        dataChannel: true,
        microphone: true,
        mediaRecorder: true,
        displaySurfaceDetection: true,
        safeShareContext: true,
        systemAudio: true,
        secureContext: true,
        coarsePointer: false,
      }),
    ).toBeNull()
  })
})
