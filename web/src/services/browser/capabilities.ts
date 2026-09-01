/**
 * What this browser can actually do (§50).
 *
 * The UI is built from this rather than from user-agent sniffing, so Remote
 * degrades honestly: a browser that cannot capture a screen is offered joining
 * and chat instead of a Share button that throws (§64), and a browser that does
 * not report `displaySurface` is told that surface verification is unavailable
 * rather than being quietly trusted (§16).
 *
 * Everything here is feature detection. No version numbers, no allowlists — a
 * browser that gains a capability tomorrow gets it without a release here.
 */

export interface RemoteBrowserCapabilities {
  /** `getDisplayMedia` exists *and* we are in a secure context. */
  screenCapture: boolean
  webRtc: boolean
  dataChannel: boolean
  microphone: boolean
  mediaRecorder: boolean
  /** The browser reports which surface was picked, so policy can be verified. */
  displaySurfaceDetection: boolean
  /** Capture Handle — lets a shared AICOUNTLY tab identify itself (§17). */
  safeShareContext: boolean
  /** System audio can be captured alongside the screen. */
  systemAudio: boolean
  secureContext: boolean
  /** Touch-first device: the room layout adapts rather than shrinking (§64). */
  coarsePointer: boolean
}

/**
 * Screen capture requires a secure context in every browser that implements it.
 * Checking it separately means the "not supported" message can say *why*, which
 * on a misconfigured internal deployment is the difference between a five-minute
 * fix and a support ticket.
 */
function isSecureContext(): boolean {
  if (typeof window === 'undefined') return false
  if (window.isSecureContext) return true

  // localhost is a secure context by specification, but some embedded
  // WebViews report otherwise; treat the standard hostnames as secure.
  const { hostname } = window.location

  return hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '[::1]'
}

export function detectCapabilities(): RemoteBrowserCapabilities {
  if (typeof window === 'undefined' || typeof navigator === 'undefined') {
    return {
      screenCapture: false,
      webRtc: false,
      dataChannel: false,
      microphone: false,
      mediaRecorder: false,
      displaySurfaceDetection: false,
      safeShareContext: false,
      systemAudio: false,
      secureContext: false,
      coarsePointer: false,
    }
  }

  const secureContext = isSecureContext()
  const mediaDevices = navigator.mediaDevices as MediaDevices | undefined

  const webRtc = typeof window.RTCPeerConnection === 'function'

  // A data channel is a method on the prototype; probing it without
  // constructing a peer connection avoids an ICE gathering cycle we do not need.
  const dataChannel =
    webRtc && typeof window.RTCPeerConnection.prototype?.createDataChannel === 'function'

  // getSupportedConstraints() is how a browser advertises `displaySurface`.
  // Chromium and Edge report it; Firefox and Safari do not, which is exactly
  // the case the "unverified surface" path exists for.
  const supportedConstraints =
    typeof mediaDevices?.getSupportedConstraints === 'function'
      ? (mediaDevices.getSupportedConstraints() as MediaTrackSupportedConstraints & {
          displaySurface?: boolean
        })
      : {}

  return {
    screenCapture: secureContext && typeof mediaDevices?.getDisplayMedia === 'function',
    webRtc,
    dataChannel,
    microphone: secureContext && typeof mediaDevices?.getUserMedia === 'function',
    mediaRecorder: typeof window.MediaRecorder === 'function',
    displaySurfaceDetection: supportedConstraints.displaySurface === true,
    // Capture Handle is Chromium-only today. Its absence never blocks sharing;
    // it only means a shared AICOUNTLY tab cannot prove which company it is (§17).
    safeShareContext:
      typeof (navigator.mediaDevices as unknown as { setCaptureHandleConfig?: unknown })
        ?.setCaptureHandleConfig === 'function',
    // Sharing system audio needs both the API and the browser's own support;
    // only Chromium offers the checkbox, and only for tabs and whole screens.
    systemAudio: secureContext && typeof mediaDevices?.getDisplayMedia === 'function',
    secureContext,
    coarsePointer: window.matchMedia?.('(pointer: coarse)').matches ?? false,
  }
}

let cached: RemoteBrowserCapabilities | null = null

/** Detection is cheap but not free, and the answer cannot change mid-page. */
export function getCapabilities(): RemoteBrowserCapabilities {
  cached ??= detectCapabilities()

  return cached
}

/** Test seam — the only reason this exists. */
export function resetCapabilitiesCache(): void {
  cached = null
}

/**
 * A short, human explanation of why sharing is unavailable, or null when it is.
 * Used by the share screen so the reason is never a silent disabled button.
 */
export function describeCaptureLimitation(
  capabilities: RemoteBrowserCapabilities = getCapabilities(),
): string | null {
  if (!capabilities.secureContext) {
    return 'Screen sharing needs a secure (https) connection.'
  }

  if (!capabilities.screenCapture) {
    return 'This browser cannot share a screen. You can still join a session, chat, and view a shared screen.'
  }

  if (!capabilities.webRtc) {
    return 'This browser does not support live sessions.'
  }

  return null
}
