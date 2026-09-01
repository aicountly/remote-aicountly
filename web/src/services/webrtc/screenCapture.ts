/**
 * Screen capture, and the client half of surface enforcement (§15, §16, §85).
 *
 * The order of operations here is the security property, not an implementation
 * detail:
 *
 *   1. the **server** authorises the sharing mode (`share-intent`) — done by the
 *      caller before this module is reached;
 *   2. the browser's own picker runs, driven by an explicit user gesture;
 *   3. the surface that came back is checked against policy, and a disallowed
 *      one has its tracks stopped **before a single frame is handed to anyone**;
 *   4. only then is the stream returned, and the server told what was picked —
 *      where it is checked a second time.
 *
 * Step 3 is a local courtesy that gives an instant, well-worded refusal. It is
 * not the enforcement: a client that skipped it still cannot get the session
 * marked as sharing, because step 4 happens on the server.
 */

import type { EffectivePolicy, DisplaySurface, ShareMode } from '../../types/remote'
import { getCapabilities } from '../browser/capabilities'

export type CaptureErrorCode =
  | 'SCREEN_CAPTURE_UNSUPPORTED'
  | 'INSECURE_CONTEXT'
  | 'PERMISSION_DENIED'
  | 'NO_VIDEO_TRACK'
  | 'CANCELLED'
  | 'MONITOR_NOT_ALLOWED'
  | 'WINDOW_NOT_ALLOWED'
  | 'BROWSER_TAB_NOT_ALLOWED'
  | 'CAPTURE_FAILED'

export class RemoteCaptureError extends Error {
  readonly code: CaptureErrorCode

  constructor(code: CaptureErrorCode, message: string) {
    super(message)
    this.name = 'RemoteCaptureError'
    this.code = code
  }
}

export interface CaptureResult {
  stream: MediaStream
  /** What the browser said it was — `unknown` when it does not report. */
  surface: DisplaySurface
  /** False when `surface` is `unknown`; the UI must not claim verification. */
  surfaceVerified: boolean
  hasSystemAudio: boolean
}

/**
 * Constraint hints for the mode the user chose.
 *
 * `displaySurface` here is a *preference*, not a guarantee — the browser is
 * free to offer everything anyway, and Firefox and Safari ignore it entirely.
 * That is precisely why the result is checked afterwards rather than trusted.
 */
function constraintsFor(shareMode: ShareMode, allowSystemAudio: boolean): DisplayMediaStreamOptions {
  const video: MediaTrackConstraints & { displaySurface?: string } = {
    // A shared screen is text, not motion: prefer legibility over frame rate.
    frameRate: { ideal: 15, max: 30 },
  }

  switch (shareMode) {
    case 'SAFE_SHARE':
    case 'BROWSER_TAB':
      video.displaySurface = 'browser'
      break
    case 'APPLICATION_WINDOW':
      video.displaySurface = 'window'
      break
    case 'ENTIRE_MONITOR':
      video.displaySurface = 'monitor'
      break
  }

  return { video, audio: allowSystemAudio }
}

function readSurface(track: MediaStreamTrack): DisplaySurface {
  // getSettings() is itself optional on older implementations.
  const settings = typeof track.getSettings === 'function' ? track.getSettings() : {}
  const reported = (settings as MediaTrackSettings & { displaySurface?: string }).displaySurface

  if (reported === 'browser' || reported === 'window' || reported === 'monitor') {
    return reported
  }

  return 'unknown'
}

function refusalFor(surface: DisplaySurface): { code: CaptureErrorCode; message: string } {
  switch (surface) {
    case 'monitor':
      return {
        code: 'MONITOR_NOT_ALLOWED',
        message: 'Your organisation does not allow sharing your entire screen.',
      }
    case 'window':
      return {
        code: 'WINDOW_NOT_ALLOWED',
        message: 'Your organisation does not allow sharing an application window.',
      }
    default:
      return {
        code: 'BROWSER_TAB_NOT_ALLOWED',
        message: 'Your organisation does not allow sharing a browser tab.',
      }
  }
}

function stopEverything(stream: MediaStream): void {
  for (const track of stream.getTracks()) {
    track.stop()
  }
}

/**
 * Open the browser's screen picker and return a stream that policy permits.
 *
 * Must be called from a user gesture — every browser requires one, and Remote
 * never tries to work around that (§15).
 *
 * @throws {RemoteCaptureError} on cancellation, refusal, or an unusable stream
 */
export async function requestScreenShare(
  policy: EffectivePolicy,
  shareMode: ShareMode,
  options: { allowSystemAudio?: boolean } = {},
): Promise<CaptureResult> {
  const capabilities = getCapabilities()

  if (!capabilities.secureContext) {
    throw new RemoteCaptureError(
      'INSECURE_CONTEXT',
      'Screen sharing needs a secure (https) connection.',
    )
  }

  if (!capabilities.screenCapture) {
    throw new RemoteCaptureError(
      'SCREEN_CAPTURE_UNSUPPORTED',
      'This browser cannot share a screen. You can still join the session and chat.',
    )
  }

  const wantsSystemAudio = Boolean(options.allowSystemAudio) && policy.allowSystemAudio

  let stream: MediaStream
  try {
    stream = await navigator.mediaDevices.getDisplayMedia(constraintsFor(shareMode, wantsSystemAudio))
  } catch (error) {
    const name = (error as DOMException)?.name

    // NotAllowedError covers both "the user pressed Cancel" and "the browser
    // refused". They read very differently to a user, and the picker gives us
    // no way to tell them apart — so the wording covers both without guessing.
    if (name === 'NotAllowedError') {
      throw new RemoteCaptureError('PERMISSION_DENIED', 'Screen sharing was not allowed.')
    }
    if (name === 'AbortError') {
      throw new RemoteCaptureError('CANCELLED', 'Screen sharing was cancelled.')
    }
    if (name === 'NotFoundError' || name === 'NotReadableError') {
      throw new RemoteCaptureError('CAPTURE_FAILED', 'That screen could not be captured. Try another one.')
    }

    throw new RemoteCaptureError('CAPTURE_FAILED', 'Screen sharing could not start.')
  }

  const videoTrack = stream.getVideoTracks()[0]

  if (!videoTrack) {
    stopEverything(stream)

    throw new RemoteCaptureError('NO_VIDEO_TRACK', 'No screen was selected.')
  }

  const surface = readSurface(videoTrack)

  // A surface the browser named and policy forbids: stop immediately. Nothing
  // is attached to a peer connection until this function returns, so no frame
  // has left this machine.
  if (surface !== 'unknown' && !allowsSurface(policy, surface)) {
    stopEverything(stream)

    const { code, message } = refusalFor(surface)

    throw new RemoteCaptureError(code, message)
  }

  return {
    stream,
    surface,
    surfaceVerified: surface !== 'unknown',
    hasSystemAudio: stream.getAudioTracks().length > 0,
  }
}

/**
 * Does policy permit this surface?
 *
 * Mirrors `EffectivePolicy::allowsDisplaySurface()` on the server, including
 * the Safe Share nuance: Safe Share *is* a browser tab as far as the Screen
 * Capture API is concerned, so a `browser` surface is acceptable when either
 * Safe Share or plain tab sharing is permitted.
 */
export function allowsSurface(policy: EffectivePolicy, surface: DisplaySurface): boolean {
  switch (surface) {
    case 'browser':
      return policy.allowedShareModes.includes('BROWSER_TAB') || policy.allowedShareModes.includes('SAFE_SHARE')
    case 'window':
      return policy.allowedShareModes.includes('APPLICATION_WINDOW')
    case 'monitor':
      return policy.allowedShareModes.includes('ENTIRE_MONITOR')
    default:
      // An unreported surface cannot be verified, so it is neither approved nor
      // refused here — the mode the server already authorised is what applies.
      return true
  }
}

/**
 * Ask for a microphone. Separate from screen capture on purpose: the two
 * permissions are unrelated and Remote never requests one while asking for the
 * other (§37).
 */
export async function requestMicrophone(): Promise<MediaStream> {
  const capabilities = getCapabilities()

  if (!capabilities.microphone) {
    throw new RemoteCaptureError('SCREEN_CAPTURE_UNSUPPORTED', 'This browser cannot use a microphone.')
  }

  try {
    return await navigator.mediaDevices.getUserMedia({
      audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
      video: false,
    })
  } catch (error) {
    const name = (error as DOMException)?.name

    if (name === 'NotAllowedError') {
      throw new RemoteCaptureError('PERMISSION_DENIED', 'Microphone access was not allowed.')
    }

    throw new RemoteCaptureError('CAPTURE_FAILED', 'Your microphone could not be started.')
  }
}

/**
 * Announce this tab to a Remote viewer that captures it (§17).
 *
 * Capture Handle lets a captured tab expose a small, non-sensitive identifier
 * that the capturing page can read — enough for Remote to confirm that the tab
 * being shared belongs to the same AICOUNTLY company as the session, and to
 * detect it if that stops being true (§12).
 *
 * Chromium-only today. Its absence is not a failure: sharing works exactly as
 * before, and the UI says verification is unavailable rather than implying it
 * happened.
 */
export function publishCaptureHandle(handle: string): boolean {
  const devices = navigator.mediaDevices as unknown as {
    setCaptureHandleConfig?: (config: {
      handle: string
      exposeOrigin: boolean
      permittedOrigins: string[]
    }) => void
  }

  if (typeof devices?.setCaptureHandleConfig !== 'function') {
    return false
  }

  try {
    devices.setCaptureHandleConfig({
      // An opaque identifier, never raw company data (§17).
      handle: handle.slice(0, 1024),
      exposeOrigin: true,
      permittedOrigins: [window.location.origin],
    })

    return true
  } catch {
    return false
  }
}

/** Read the capture handle of a captured tab, when the browser provides one. */
export function readCaptureHandle(track: MediaStreamTrack): { handle: string; origin: string } | null {
  const capturing = track as MediaStreamTrack & {
    getCaptureHandle?: () => { handle?: string; origin?: string } | null
  }

  if (typeof capturing.getCaptureHandle !== 'function') {
    return null
  }

  try {
    const handle = capturing.getCaptureHandle()
    if (!handle?.handle) return null

    return { handle: handle.handle, origin: handle.origin ?? '' }
  } catch {
    return null
  }
}
