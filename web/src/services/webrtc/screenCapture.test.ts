import { beforeEach, describe, expect, it, vi } from 'vitest'

import { allowsSurface, requestScreenShare, RemoteCaptureError } from './screenCapture'
import { resetCapabilitiesCache } from '../browser/capabilities'
import type { EffectivePolicy, ShareMode } from '../../types/remote'

/**
 * Client-side sharing-surface enforcement (§16, §85).
 *
 * The behaviour under test is the one that matters most in the whole frontend:
 * when the browser hands back a surface the organisation forbids, **every track
 * is stopped before the stream is returned to anyone**. A stream that escapes
 * this function is a stream that reaches a peer connection.
 */

function policy(overrides: Partial<EffectivePolicy> = {}): EffectivePolicy {
  return {
    remoteEnabled: true,
    scopeType: 'COMPANY',
    companyId: 481,
    companyName: 'ABC Private Limited',
    policyPreset: 'STANDARD',
    allowSafeShare: true,
    allowBrowserTab: true,
    allowApplicationWindow: true,
    allowEntireMonitor: false,
    allowMicrophone: true,
    allowSystemAudio: false,
    allowTextChat: true,
    allowAnnotation: true,
    allowFileTransfer: false,
    allowExternalGuest: false,
    allowInternalSessions: true,
    allowAicountlySupport: true,
    allowRecording: false,
    recordingRequiresConsent: true,
    maxSessionDurationMinutes: 60,
    guestLinkExpiryMinutes: 10,
    allowedShareModes: ['SAFE_SHARE', 'BROWSER_TAB', 'APPLICATION_WINDOW'],
    permissions: {},
    restrictions: [],
    ...overrides,
  }
}

/** A MediaStream stand-in that records whether its tracks were stopped. */
function fakeStream(displaySurface: string | undefined, audio = false) {
  const stopped: string[] = []

  const makeTrack = (kind: string) => ({
    kind,
    stop: () => stopped.push(kind),
    getSettings: () => (displaySurface === undefined ? {} : { displaySurface }),
    addEventListener: () => {},
  })

  const video = makeTrack('video')
  const tracks = audio ? [video, makeTrack('audio')] : [video]

  return {
    stopped,
    stream: {
      getTracks: () => tracks,
      getVideoTracks: () => [video],
      getAudioTracks: () => (audio ? [tracks[1]] : []),
    } as unknown as MediaStream,
  }
}

function grantCapture(getDisplayMedia: () => Promise<MediaStream>) {
  Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true })
  Object.defineProperty(navigator, 'mediaDevices', {
    configurable: true,
    value: {
      getDisplayMedia,
      getUserMedia: vi.fn(),
      getSupportedConstraints: () => ({ displaySurface: true }),
    },
  })
  Object.defineProperty(window, 'RTCPeerConnection', {
    configurable: true,
    value: function RTCPeerConnectionStub() {},
  })

  resetCapabilitiesCache()
}

describe('requestScreenShare', () => {
  beforeEach(() => {
    resetCapabilitiesCache()
  })

  it('returns a permitted surface and reports it as verified', async () => {
    const { stream, stopped } = fakeStream('browser')
    grantCapture(async () => stream)

    const result = await requestScreenShare(policy(), 'BROWSER_TAB')

    expect(result.surface).toBe('browser')
    expect(result.surfaceVerified).toBe(true)
    expect(stopped).toEqual([])
  })

  it('stops every track when the surface is forbidden', async () => {
    // The organisation permits tabs and windows but not entire screens; the
    // user picked an entire screen anyway.
    const { stream, stopped } = fakeStream('monitor', true)
    grantCapture(async () => stream)

    await expect(requestScreenShare(policy(), 'BROWSER_TAB')).rejects.toMatchObject({
      code: 'MONITOR_NOT_ALLOWED',
    })

    expect(stopped).toEqual(['video', 'audio'])
  })

  it('refuses a window when the organisation does not permit one', async () => {
    const { stream, stopped } = fakeStream('window')
    grantCapture(async () => stream)

    await expect(
      requestScreenShare(policy({ allowedShareModes: ['SAFE_SHARE'] }), 'SAFE_SHARE'),
    ).rejects.toMatchObject({ code: 'WINDOW_NOT_ALLOWED' })

    expect(stopped).toEqual(['video'])
  })

  it('accepts an unreported surface but marks it unverified', async () => {
    // Firefox and Safari do not implement displaySurface. Refusing outright
    // would make Remote unusable there; claiming verification would be a lie.
    const { stream, stopped } = fakeStream(undefined)
    grantCapture(async () => stream)

    const result = await requestScreenShare(policy(), 'BROWSER_TAB')

    expect(result.surface).toBe('unknown')
    expect(result.surfaceVerified).toBe(false)
    expect(stopped).toEqual([])
  })

  it('reports a cancelled picker distinctly from a refusal', async () => {
    grantCapture(async () => {
      throw Object.assign(new Error('cancelled'), { name: 'NotAllowedError' })
    })

    await expect(requestScreenShare(policy(), 'BROWSER_TAB')).rejects.toMatchObject({
      code: 'PERMISSION_DENIED',
    })
  })

  it('stops the stream when it contains no video track', async () => {
    const stopped: string[] = []
    grantCapture(
      async () =>
        ({
          getTracks: () => [{ kind: 'audio', stop: () => stopped.push('audio') }],
          getVideoTracks: () => [],
          getAudioTracks: () => [],
        }) as unknown as MediaStream,
    )

    await expect(requestScreenShare(policy(), 'BROWSER_TAB')).rejects.toMatchObject({
      code: 'NO_VIDEO_TRACK',
    })

    expect(stopped).toEqual(['audio'])
  })

  it('refuses outright when the page is not in a secure context', async () => {
    Object.defineProperty(window, 'isSecureContext', { value: false, configurable: true })
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...window.location, hostname: 'remote.example.com' },
    })
    resetCapabilitiesCache()

    await expect(requestScreenShare(policy(), 'BROWSER_TAB')).rejects.toBeInstanceOf(RemoteCaptureError)
  })

  it('refuses when the browser has no getDisplayMedia at all', async () => {
    Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true })
    resetCapabilitiesCache()

    await expect(requestScreenShare(policy(), 'BROWSER_TAB')).rejects.toMatchObject({
      code: 'SCREEN_CAPTURE_UNSUPPORTED',
    })
  })
})

describe('allowsSurface', () => {
  it('accepts a browser surface when only Safe Share is permitted', () => {
    // Safe Share *is* a browser tab to the Screen Capture API, so the SAFE
    // preset must not reject its own recommended option (§14).
    const safeOnly = policy({ allowedShareModes: ['SAFE_SHARE'] })

    expect(allowsSurface(safeOnly, 'browser')).toBe(true)
    expect(allowsSurface(safeOnly, 'window')).toBe(false)
    expect(allowsSurface(safeOnly, 'monitor')).toBe(false)
  })

  it('refuses a monitor unless entire-screen sharing is permitted', () => {
    expect(allowsSurface(policy(), 'monitor')).toBe(false)
    expect(
      allowsSurface(policy({ allowedShareModes: ['ENTIRE_MONITOR'] }), 'monitor'),
    ).toBe(true)
  })

  it('leaves an unknown surface to the mode the server already authorised', () => {
    expect(allowsSurface(policy({ allowedShareModes: [] }), 'unknown')).toBe(true)
  })

  it.each<[ShareMode, string]>([
    ['BROWSER_TAB', 'browser'],
    ['APPLICATION_WINDOW', 'window'],
    ['ENTIRE_MONITOR', 'monitor'],
  ])('maps %s to the %s surface', (mode, surface) => {
    const permitted = policy({ allowedShareModes: [mode] })

    expect(allowsSurface(permitted, surface as 'browser' | 'window' | 'monitor')).toBe(true)
  })
})
