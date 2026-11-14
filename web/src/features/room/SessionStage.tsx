import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import type { ReactElement, ReactNode } from 'react'
import { Keyboard, Maximize, Minimize, Monitor, MonitorOff, MousePointer2, ShieldCheck } from 'lucide-react'

import type { AnnotationShape, EngineSnapshot } from '../../services/webrtc/RemoteSessionEngine'
import { normalisePointer } from '../../services/webrtc/remoteControl'
import type {
  InputRefusedReason,
  KeyCombination,
  KeyboardPlatform,
  MonitorDescription,
  PointerPosition as ControlPoint,
} from '../../services/webrtc/remoteControl'
import type { SessionDetail } from '../../types/remote'
import { formatSessionCode } from '../../utils/format'
import { useFullscreen } from './useFullscreen'

/**
 * The shared screen, with the pointer and annotation overlays on top (§34).
 *
 * The overlays are **overlays**. They are drawn on a canvas that sits above the
 * video and they never touch the shared application — a viewer drawing a circle
 * changes nothing on the sharer's machine, which is the honest limit of what a
 * browser can do and exactly what Remote promises.
 *
 * Coordinates travel normalised (0..1) so a pointer lands in the same place on
 * a 4K sharer and a laptop viewer.
 *
 * A viewer can take the stage full screen, and it brings the room's status and
 * stop controls with it (the Full screen section below).
 */

export type AnnotationTool = 'none' | 'pen' | 'arrow' | 'rectangle' | 'highlight'

/** Nothing needs the person: one shared empty list, so an absent prop is not a new one every render. */
const NOTHING: readonly string[] = []

/** No displays reported: the same, for the switcher. */
const NO_MONITORS: readonly MonitorDescription[] = []

/**
 * The key combinations the room can send for somebody, in the order its menu lists them.
 *
 * These are the ones a keyboard cannot hand over. Ctrl+Alt+Del and the Windows key never reach a
 * web page at all — the helper's own computer takes them — and outside full screen neither do
 * Alt+Tab or Alt+F4. Named for what they do on the computer being controlled, with the keys beside.
 */
type KeyMenu = ReadonlyArray<{ name: KeyCombination; label: string; keys: string }>

const KEY_MENU: KeyMenu = [
  { name: 'ctrl_alt_del', label: 'Ctrl+Alt+Del', keys: 'Sign-in and security screen (Windows)' },
  { name: 'task_manager', label: 'Task Manager', keys: 'Ctrl+Shift+Esc' },
  { name: 'start_menu', label: 'Start menu', keys: 'Windows key' },
  { name: 'run_dialog', label: 'Run…', keys: 'Windows+R' },
  { name: 'file_explorer', label: 'File Explorer', keys: 'Windows+E' },
  { name: 'show_desktop', label: 'Show the desktop', keys: 'Windows+D' },
  { name: 'switch_window', label: 'Switch window', keys: 'Alt+Tab' },
  { name: 'close_window', label: 'Close window', keys: 'Alt+F4' },
  { name: 'escape', label: 'Esc', keys: 'Escape' },
  { name: 'print_screen', label: 'Print Screen', keys: 'PrtSc' },
]

/**
 * The same for a Mac. The Windows list sent to a Mac did other things there — "Run…" was ⌘R, which
 * reloads the page in front — so a Mac is offered its own: the ones macOS keeps from a web page
 * (⌘Tab, ⌘Space, ⌘Q, ⌘W), the one that gets somebody out of a stuck app, and its Control key, which
 * a helper on a PC keyboard cannot type because their Ctrl is sent as ⌘.
 */
const MAC_KEY_MENU: KeyMenu = [
  { name: 'force_quit', label: 'Force Quit…', keys: '⌥⌘Esc' },
  { name: 'spotlight', label: 'Spotlight search', keys: '⌘Space' },
  { name: 'switch_app', label: 'Switch app', keys: '⌘Tab' },
  { name: 'mission_control', label: 'Mission Control', keys: '⌃↑' },
  { name: 'close_window_mac', label: 'Close window', keys: '⌘W' },
  { name: 'quit_app', label: 'Quit app', keys: '⌘Q' },
  { name: 'interrupt', label: 'Interrupt', keys: 'Control+C, in Terminal' },
  { name: 'escape', label: 'Esc', keys: 'Escape' },
]

/** The Keys menu for the computer being controlled. One that has not said what it is gets the Windows list. */
export function keyMenuFor(platform: KeyboardPlatform | null | undefined): KeyMenu {
  return platform === 'mac' ? MAC_KEY_MENU : KEY_MENU
}

/**
 * Why the computer being controlled did not take something, in words the helper can act on.
 *
 * Control was "granted" at both ends and nothing moved, and until the computer said why there was
 * nothing to tell a refused click from a dead connection.
 */
export function inputRefusedMessage(reason: InputRefusedReason): string {
  switch (reason) {
    case 'elevated_window':
      return 'That computer has a window in front that runs as administrator, and Windows will not let your clicks or typing into it. Ask the person there to use that window, or to close it.'
    case 'secure_attention_not_allowed':
      return 'Ctrl+Alt+Del was not sent. It can only be sent when the person at the computer allowed control in the AICOUNTLY Remote app with “Also let them answer Windows administrator prompts” ticked.'
    case 'secure_attention_blocked':
      return 'Ctrl+Alt+Del was not sent: Windows on that computer does not let an app send it. The person there can press it themselves.'
    case 'refused':
      return 'That computer did not take your last click or key. If it keeps happening, ask the person there to check that AICOUNTLY Remote is allowed to control it.'
  }
}

/**
 * Whether a key press is "paste": Ctrl+V, ⌘V, or Shift+Insert. With the clipboard shared, the
 * helper's clipboard has to reach the other computer before the keys that paste it.
 */
export function isPasteKey(event: { key: string; ctrlKey: boolean; metaKey: boolean; altKey: boolean; shiftKey: boolean }): boolean {
  if (event.altKey) return false

  if (event.key === 'Insert') return event.shiftKey && !event.ctrlKey && !event.metaKey

  return (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'v'
}

/** How long the paste key waits for the browser's `paste` event before it is sent without one. */
const PASTE_WAIT_MS = 250

/**
 * The Keyboard Lock API (Chrome and Edge, in full screen): with it, the keys a
 * browser normally keeps for itself — Alt+Tab, the Windows key, Ctrl+W, Ctrl+T,
 * Esc — reach the page, and so the controlled computer.
 */
interface KeyboardLock {
  lock?: (keyCodes?: string[]) => Promise<void>
  unlock?: () => void
}

function keyboardLock(): KeyboardLock | null {
  return (navigator as Navigator & { keyboard?: KeyboardLock }).keyboard ?? null
}

interface Props {
  session: SessionDetail
  live: EngineSnapshot | null
  /** Pointer and annotation are only offered when policy permits them. */
  pointerEnabled: boolean
  annotationTool: AnnotationTool
  annotationColour: string
  onPointerMove: (x: number, y: number) => void
  onAnnotation: (shape: AnnotationShape) => void
  authorName: string
  /**
   * Whether this browser is sending input to the machine on screen.
   *
   * True only after the server recorded a grant and the control channel opened
   * — the hook decides, this renders it. While it is true the stage takes over
   * the pointer and the keyboard, and while it is false none of that is bound
   * at all (§18).
   */
  controlling?: boolean
  onControlPointer?: (position: ControlPoint, force?: boolean) => void
  onControlButton?: (button: number, pressed: boolean, position?: ControlPoint, double?: boolean) => void
  onControlScroll?: (deltaX: number, deltaY: number, position?: ControlPoint) => void
  onControlKey?: (event: KeyboardEvent, pressed: boolean) => boolean
  onControlClipboard?: (text: string) => boolean
  /** Send one of the key combinations the keyboard cannot hand over (the Keys menu). */
  onControlCombination?: (name: KeyCombination) => boolean
  /** Which keyboard the controlled computer has: a Mac is offered a Mac's keys. */
  controlledPlatform?: KeyboardPlatform | null
  /**
   * The keyboard stopped being the stage's — focus went to another window or
   * another part of the page. Whatever is held on the controlled computer is
   * released, because the key-up that would have done it goes elsewhere.
   */
  onControlRelease?: () => void
  /**
   * The controlled computer's displays, when it shares its own screen through
   * the desktop app, and the one on screen now. With more than one, the person
   * controlling gets a switcher — the second screen of a two-screen desk.
   */
  monitors?: readonly MonitorDescription[]
  activeMonitorId?: number | null
  onSelectMonitor?: (monitorId: number) => void
  /** Set when the host granted the clipboard as well. Separate from control. */
  clipboardShared?: boolean
  /**
   * The room's status line (connection, session clock) and its sharing and
   * control banners — the stop buttons among them.
   *
   * Full screen shows nothing outside the stage, so while the stage is full
   * screen it shows these itself, in a strip above the picture rather than over
   * it: §18's indicator stays on screen for as long as input is flowing, full
   * screen or not, and none of the remote screen is covered.
   */
  fullscreenStatus?: ReactNode
  fullscreenBanners?: ReactNode
  /** Told when the stage enters or leaves full screen, so the room does not show those twice. */
  onFullscreenChange?: (fullscreen: boolean) => void
  /**
   * What in the room needs this person, one key each — somebody waiting to be
   * admitted, a request for control, the share consent, a notice. A key that
   * was not there before takes the stage out of full screen, so full screen
   * never hides a consent or a stop control.
   */
  attention?: readonly string[]
}

export default function SessionStage({
  session,
  live,
  pointerEnabled,
  annotationTool,
  annotationColour,
  onPointerMove,
  onAnnotation,
  authorName,
  controlling = false,
  onControlPointer,
  onControlButton,
  onControlScroll,
  onControlKey,
  onControlClipboard,
  onControlCombination,
  controlledPlatform = null,
  onControlRelease,
  monitors = NO_MONITORS,
  activeMonitorId = null,
  onSelectMonitor,
  clipboardShared = false,
  fullscreenStatus = null,
  fullscreenBanners = null,
  onFullscreenChange,
  attention = NOTHING,
}: Props) {
  const stageRef = useRef<HTMLDivElement>(null)
  const videoRef = useRef<HTMLVideoElement>(null)
  const surfaceRef = useRef<HTMLDivElement>(null)
  const [drawing, setDrawing] = useState<AnnotationShape | null>(null)

  const stream = live?.remoteStream ?? live?.localStream ?? null
  const isViewingRemote = Boolean(live?.remoteStream)

  const fullscreen = useFullscreen(stageRef)
  const exitFullscreen = fullscreen.exit

  // A browser will not start sound a person has not asked for: until somebody
  // has clicked or typed on this page, playing a stream that carries sound is
  // refused — and with it the picture. A computer sharing through the desktop
  // app sends its sound beside its screen, so somebody who opened the room
  // from a link and has touched nothing yet would have seen no screen at all.
  // The picture is shown without sound instead, and the sound joins it at the
  // first click or key press.
  const [soundWaiting, setSoundWaiting] = useState(false)

  useEffect(() => {
    const video = videoRef.current
    if (!video) return

    if (video.srcObject !== stream) {
      video.srcObject = stream

      video.play().catch((error: unknown) => {
        // Refused for want of a gesture: play it silently now.
        if ((error as { name?: string } | null)?.name !== 'NotAllowedError') return

        video.muted = true
        setSoundWaiting(true)
        // A stream can arrive before the element is ready to play it; a
        // rejection of the silent attempt is that, and not worth surfacing.
        video.play().catch(() => undefined)
      })
    }
  }, [stream])

  useEffect(() => {
    if (!soundWaiting) return

    const start = () => setSoundWaiting(false)

    window.addEventListener('pointerdown', start, { once: true })
    window.addEventListener('keydown', start, { once: true })

    return () => {
      window.removeEventListener('pointerdown', start)
      window.removeEventListener('keydown', start)
    }
  }, [soundWaiting])

  const toNormalised = useCallback((event: { clientX: number; clientY: number }) => {
    const rect = surfaceRef.current?.getBoundingClientRect()
    if (!rect || rect.width === 0 || rect.height === 0) return null

    return {
      x: Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width)),
      y: Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height)),
    }
  }, [])

  const handlePointerMove = useCallback(
    (event: React.PointerEvent) => {
      const point = toNormalised(event)
      if (!point) return

      if (pointerEnabled && annotationTool === 'none') {
        // Throttling happens in the engine, not here: a React state update per
        // mousemove would be the more expensive half (§65).
        onPointerMove(point.x, point.y)
      }

      if (drawing) {
        setDrawing((current) =>
          current
            ? {
                ...current,
                points:
                  current.tool === 'pen'
                    ? [...current.points, point]
                    : [current.points[0], point],
              }
            : null,
        )
      }
    },
    [toNormalised, pointerEnabled, annotationTool, onPointerMove, drawing],
  )

  const handlePointerDown = useCallback(
    (event: React.PointerEvent) => {
      if (annotationTool === 'none') return

      const point = toNormalised(event)
      if (!point) return

      event.currentTarget.setPointerCapture(event.pointerId)

      setDrawing({
        id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
        tool: annotationTool,
        points: [point, point],
        colour: annotationColour,
        author: authorName,
      })
    },
    [annotationTool, toNormalised, annotationColour, authorName],
  )

  const handlePointerUp = useCallback(() => {
    if (!drawing) return

    // A tap with no movement is not a shape; discard it rather than sending a
    // dot every time someone clicks.
    const [start, end] = [drawing.points[0], drawing.points[drawing.points.length - 1]]
    const moved = Math.abs(start.x - end.x) > 0.005 || Math.abs(start.y - end.y) > 0.005

    if (moved) onAnnotation(drawing)

    setDrawing(null)
  }, [drawing, onAnnotation])

  // --- Remote control input (§18) ------------------------------------------
  //
  // Bound only while `controlling` is true. Nothing here is a permission check
  // — the server made the decision and the agent's own gate is what enforces
  // it — but a browser that keeps listening after a revocation is a browser
  // that will eventually send one event too many, so it stops listening.

  /**
   * A pointer event as a fraction of the **shared screen**.
   *
   * Measured against the `<video>`, not the surface: `object-fit: contain`
   * leaves letterbox bars that belong to neither, and a click in one is not a
   * click on the remote machine. {@link normalisePointer} returns null there,
   * and null is dropped rather than clamped — clamping would land the click at
   * an edge the person never aimed at.
   */
  const controlPoint = useCallback((event: { clientX: number; clientY: number }): ControlPoint | null => {
    const video = videoRef.current
    if (!video) return null

    return normalisePointer(event, video)
  }, [])

  const handleControlPointerMove = useCallback(
    (event: React.PointerEvent) => {
      const point = controlPoint(event)
      if (point) onControlPointer?.(point)
    },
    [controlPoint, onControlPointer],
  )

  const handleControlPointerDown = useCallback(
    (event: React.PointerEvent) => {
      const point = controlPoint(event)
      if (!point) return

      // Focus first: the keyboard handlers are on this element, so a click is
      // also how somebody starts typing on the remote machine.
      surfaceRef.current?.focus()
      event.currentTarget.setPointerCapture(event.pointerId)

      // The move is forced past the throttle, because a click at a stale
      // position is much worse than one a frame late.
      onControlPointer?.(point, true)
      onControlButton?.(event.button, true, point, event.detail >= 2)
    },
    [controlPoint, onControlPointer, onControlButton],
  )

  const handleControlPointerUp = useCallback(
    (event: React.PointerEvent) => {
      const point = controlPoint(event)

      onControlButton?.(event.button, false, point ?? undefined)
    },
    [controlPoint, onControlButton],
  )

  // --- Paste ----------------------------------------------------------------
  //
  // With the clipboard shared, "paste" has to mean the helper's clipboard. The
  // browser only hands a page its clipboard in a `paste` event, and it fires
  // that event only if the key press that causes it is left alone — so the
  // paste key is the one key whose default is not prevented and which is not
  // sent at once. It is held until the `paste` event has delivered the text
  // (or a moment has passed with none: an empty clipboard, a picture), and
  // then sent, so the other computer receives the text first and the keys
  // that paste it second.
  //
  // Preventing the default on every key, as the stage used to, meant the
  // `paste` event never fired: the helper's text was never sent, and Ctrl+V
  // pasted whatever the other computer already had on its own clipboard.
  const heldPaste = useRef<{ event: KeyboardEvent; timer: ReturnType<typeof setTimeout> } | null>(null)

  const sendHeldPaste = useCallback(() => {
    const held = heldPaste.current
    if (!held) return

    clearTimeout(held.timer)
    heldPaste.current = null
    onControlKey?.(held.event, true)
  }, [onControlKey])

  const handleControlKey = useCallback(
    (event: React.KeyboardEvent, pressed: boolean) => {
      const native = event.nativeEvent

      if (clipboardShared && onControlClipboard && isPasteKey(native)) {
        if (pressed) {
          // A key held down repeats; the first press is the one being waited on.
          if (!heldPaste.current) {
            heldPaste.current = { event: native, timer: setTimeout(sendHeldPaste, PASTE_WAIT_MS) }
          }

          return
        }

        // Released before the clipboard arrived: the press goes first, then this.
        sendHeldPaste()
      }

      // Tab would move focus out of the stage and Space would scroll the page,
      // and both are keys somebody controlling a computer means to send.
      if (onControlKey?.(native, pressed)) event.preventDefault()
    },
    [onControlKey, onControlClipboard, clipboardShared, sendHeldPaste],
  )

  useEffect(
    () => () => {
      if (heldPaste.current) clearTimeout(heldPaste.current.timer)
      heldPaste.current = null
    },
    [],
  )

  // Wheel is bound natively rather than through React, because React attaches
  // its own wheel listener passively — `preventDefault()` in a synthetic
  // handler is ignored, and the page would scroll underneath the person.
  useEffect(() => {
    const surface = surfaceRef.current
    if (!controlling || !surface || !onControlScroll) return

    const onWheel = (event: WheelEvent) => {
      event.preventDefault()
      onControlScroll(event.deltaX, event.deltaY, controlPoint(event) ?? undefined)
    }

    surface.addEventListener('wheel', onWheel, { passive: false })

    return () => surface.removeEventListener('wheel', onWheel)
  }, [controlling, onControlScroll, controlPoint])

  // The clipboard travels only when the host granted it — a separate decision
  // from control, and off unless it was ticked (§59).
  useEffect(() => {
    if (!controlling || !clipboardShared || !onControlClipboard) return

    const onPaste = (event: ClipboardEvent) => {
      if (!surfaceRef.current?.contains(document.activeElement)) return

      const text = event.clipboardData?.getData('text/plain')
      if (text) onControlClipboard(text)

      // The text first, then the keys that paste it (see `heldPaste`).
      sendHeldPaste()
    }

    window.addEventListener('paste', onPaste)

    return () => window.removeEventListener('paste', onPaste)
  }, [controlling, clipboardShared, onControlClipboard, sendHeldPaste])

  // --- The Keys menu ----------------------------------------------------------

  const [keysOpen, setKeysOpen] = useState(false)

  // Gone with control, so it is never left open over a screen nobody is controlling.
  useEffect(() => {
    if (!controlling) setKeysOpen(false)
  }, [controlling])

  const sendCombination = useCallback(
    (name: KeyCombination) => {
      onControlCombination?.(name)
      setKeysOpen(false)

      // Keys go to the remote computer only while the surface has focus, and
      // choosing from the menu took it.
      surfaceRef.current?.focus()
    },
    [onControlCombination],
  )

  // --- Full screen -----------------------------------------------------------
  //
  // Only the stage goes full screen: the picture with its overlays, and the
  // control surface with its handlers — which work there exactly as they do
  // here, because the mapping above measures the video wherever it is. The
  // browser hides everything else meanwhile, so the stage leaves full screen
  // whenever something outside it needs the person: control ending, the picture
  // going, or anything new in `attention`. Esc leaves it too, as browsers always
  // allow.

  const toggleFullscreen = useCallback(async () => {
    await (fullscreen.active ? fullscreen.exit() : fullscreen.enter())

    // Keys reach the remote computer only while the surface has focus, and
    // pressing this button took it. Handing it back means typing carries on
    // where it was — and a Space typed next is not this button pressed again.
    if (controlling) surfaceRef.current?.focus()
  }, [fullscreen, controlling])

  // Full screen while controlling takes the whole keyboard. A browser keeps
  // Alt+Tab, the Windows key, Ctrl+W, Ctrl+T and Esc for itself; pressed while
  // helping somebody, they switched the helper's own windows or closed the
  // helper's own tab. Locked, they go to the computer being controlled, and
  // leaving full screen is a long press of Esc — the browser says so itself.
  // Where the API does not exist (Firefox, Safari) nothing changes.
  useEffect(() => {
    if (!fullscreen.active || !controlling) return

    const keyboard = keyboardLock()
    if (!keyboard?.lock) return

    keyboard.lock().catch(() => undefined)

    return () => keyboard.unlock?.()
  }, [fullscreen.active, controlling])

  // The keyboard is somebody else's now — another window, another tab. The
  // key-up for anything held went with it, so say it here.
  useEffect(() => {
    if (!controlling || !onControlRelease) return

    const release = () => onControlRelease()
    const onVisibility = () => {
      if (document.hidden) onControlRelease()
    }

    window.addEventListener('blur', release)
    document.addEventListener('visibilitychange', onVisibility)

    return () => {
      window.removeEventListener('blur', release)
      document.removeEventListener('visibilitychange', onVisibility)
    }
  }, [controlling, onControlRelease])

  // Control ending — stopped here, revoked by the host, ended at the machine —
  // is explained in the room (`controlNotice`), and asking again happens there.
  const wasControlling = useRef(controlling)
  useEffect(() => {
    if (wasControlling.current && !controlling) void exitFullscreen()

    wasControlling.current = controlling
  }, [controlling, exitFullscreen])

  // No picture, nothing to watch: the room says what happened to it.
  useEffect(() => {
    if (!isViewingRemote) void exitFullscreen()
  }, [isViewingRemote, exitFullscreen])

  // Only what is new. Something already on screen when full screen was chosen
  // has been seen; somebody knocking since has not.
  const seenAttention = useRef<ReadonlySet<string>>(new Set(attention))
  useEffect(() => {
    const fresh = attention.some((item) => !seenAttention.current.has(item))
    seenAttention.current = new Set(attention)

    if (fresh) void exitFullscreen()
  }, [attention, exitFullscreen])

  // A layout effect, so the room drops its own copies before the frame in which
  // the stage shows them is painted — never a frame with both, or with neither.
  useLayoutEffect(() => {
    if (!fullscreen.active) return

    onFullscreenChange?.(true)

    return () => onFullscreenChange?.(false)
  }, [fullscreen.active, onFullscreenChange])

  const shapes = useMemo(
    () => (drawing ? [...(live?.annotations ?? []), drawing] : (live?.annotations ?? [])),
    [live?.annotations, drawing],
  )

  // Somebody else who is sharing. Your own row can still say sharing after this
  // page lost the stream (a reload, the browser's own Stop), and "Waiting for
  // <your own name>'s screen" would leave the host waiting for themselves.
  const sharingParticipant = session.participants.find(
    (participant) => participant.isSharing && participant.uuid !== session.me?.uuid,
  )

  // Somebody else's picture only: full screen around your own preview — a
  // screen showing itself — helps nobody.
  const offerFullscreen = fullscreen.supported && isViewingRemote

  return (
    <div ref={stageRef} className={['stage', fullscreen.active ? 'stage--fullscreen' : ''].filter(Boolean).join(' ')}>
      {fullscreen.active ? (
        <div className="stage__chrome">
          {fullscreenStatus ? <div className="stage__status">{fullscreenStatus}</div> : null}
          {fullscreenBanners}
        </div>
      ) : null}

      {stream ? (
        <div
          ref={surfaceRef}
          className={[
            'stage__surface',
            annotationTool !== 'none' ? 'stage__surface--drawing' : '',
            controlling ? 'stage__surface--controlling' : '',
          ]
            .filter(Boolean)
            .join(' ')}
          // While controlling, the stage is a control surface and nothing else:
          // the annotation handlers are not bound, so a drag is a drag on the
          // remote machine rather than a line drawn over it.
          onPointerMove={controlling ? handleControlPointerMove : handlePointerMove}
          onPointerDown={controlling ? handleControlPointerDown : handlePointerDown}
          onPointerUp={controlling ? handleControlPointerUp : handlePointerUp}
          onPointerCancel={controlling ? handleControlPointerUp : handlePointerUp}
          onKeyDown={controlling ? (event) => handleControlKey(event, true) : undefined}
          onKeyUp={controlling ? (event) => handleControlKey(event, false) : undefined}
          onBlur={controlling ? () => onControlRelease?.() : undefined}
          onContextMenu={controlling ? (event) => event.preventDefault() : undefined}
          tabIndex={controlling ? 0 : undefined}
          role={controlling ? 'application' : undefined}
          aria-label={controlling ? 'Remote computer — your keyboard and mouse control it' : undefined}
        >
          <video
            ref={videoRef}
            className="stage__video"
            // What a screen reader is told this is; a <video> with no name is "video", and nothing else.
            aria-label={isViewingRemote ? 'The shared screen' : 'Your own screen, as the others see it'}
            autoPlay
            playsInline
            // Muted for the local preview only: hearing your own shared audio
            // back is feedback, not a feature. And until the browser allows
            // sound on this page (`soundWaiting`).
            muted={!isViewingRemote || soundWaiting}
          />

          <AnnotationLayer shapes={shapes} />

          {/* What the computer being controlled has to say, one under another. */}
          {controlling && (live?.controlPaused || live?.secureDesktop || live?.inputRefused) ? (
            <div className="stage__notices">
              {/* Somebody at the controlled machine is using it (owner decision 7):
                  their input wins, and nothing is sent until they stop. */}
              {live.controlPaused ? (
                <p className="stage__paused" role="status">
                  Paused — someone is using that computer
                </p>
              ) : null}

              {/* A Windows administrator prompt is open on the controlled machine
                  (the Secure Desktop). Say so, and whether it can be answered from
                  here, rather than leaving the controller looking at a frozen frame. */}
              {live.secureDesktop ? (
                <p className="stage__paused" role="status">
                  A Windows administrator prompt is open on that computer.{' '}
                  {live.secureDesktop.reachable
                    ? 'You can answer it.'
                    : 'Ask the person at the computer to answer it.'}
                </p>
              ) : null}

              {/* The computer would not take something that was sent, and said why. Without
                  this, control read as granted at both ends while nothing moved. */}
              {live.inputRefused ? (
                <p className="stage__paused stage__paused--refused" role="status">
                  {inputRefusedMessage(live.inputRefused)}
                </p>
              ) : null}
            </div>
          ) : null}

          {Object.entries(live?.pointers ?? {}).map(([peerId, pointer]) => (
            <span
              key={peerId}
              className="stage__pointer"
              style={{
                left: `${pointer.x * 100}%`,
                top: `${pointer.y * 100}%`,
                color: pointer.colour,
              }}
              aria-hidden="true"
            >
              <MousePointer2 size={18} />
              <span className="stage__pointer-name" style={{ background: pointer.colour }}>
                {pointer.name}
              </span>
            </span>
          ))}
        </div>
      ) : (
        <StagePlaceholder session={session} sharingName={sharingParticipant?.displayName ?? null} />
      )}

      {/* Not while controlling: it sits over the corner of the remote screen where the Start
          button is, and a click on it was a click on this label, not on that computer. */}
      {isViewingRemote && sharingParticipant && !controlling ? (
        <div className="stage__attribution">
          <ShieldCheck size={14} aria-hidden="true" />
          <span>
            <strong>{sharingParticipant.displayName}</strong> is sharing
            {session.sourceProductLabel ? ` · ${session.sourceProductLabel}` : ''}
            {session.companyName ? ` · ${session.companyName}` : ''}
          </span>
        </div>
      ) : null}

      {/* Bottom right, in one row: the corner of a remote desktop least often aimed at. Siblings of
          the surface, never inside it, so a click here is never a click on the remote screen. */}
      <div className="stage__tools">
        {/* The other screens of the computer being controlled. Shown only while
            controlling — the computer switches for the person it gave control to —
            and only when there is more than one to switch between. */}
        {controlling && isViewingRemote && monitors.length > 1 && onSelectMonitor ? (
          <div className="stage__monitors" role="group" aria-label="Screens of the remote computer">
            <Monitor size={14} aria-hidden="true" />
            {monitors.map((monitor, index) => {
              const active = monitor.id === activeMonitorId

              return (
                <button
                  key={monitor.id}
                  type="button"
                  className={['stage__monitor', active ? 'stage__monitor--active' : ''].filter(Boolean).join(' ')}
                  aria-pressed={active}
                  title={`${monitor.name || `Screen ${index + 1}`} · ${monitor.width} × ${monitor.height}${
                    monitor.primary ? ' · main screen' : ''
                  }`}
                  onClick={() => {
                    if (!active) onSelectMonitor(monitor.id)

                    // Keys go to the remote computer only while the surface has
                    // focus, and pressing this took it.
                    surfaceRef.current?.focus()
                  }}
                >
                  {index + 1}
                </button>
              )
            })}
          </div>
        ) : null}

        {/* The keys a keyboard cannot hand over. Beside the screen switcher, and like it a sibling
            of the surface: choosing from it must not be a click on the remote screen. */}
        {controlling && isViewingRemote && onControlCombination ? (
          <div className="stage__keys">
            <button
              type="button"
              className="stage__keys-toggle"
              aria-haspopup="menu"
              aria-expanded={keysOpen}
              onClick={() => setKeysOpen((open) => !open)}
            >
              <Keyboard size={14} aria-hidden="true" />
              Keys
            </button>

            {keysOpen ? (
              <div
                className="stage__keys-menu"
                role="menu"
                aria-label="Send keys to the remote computer"
                onKeyDown={(event) => {
                  if (event.key === 'Escape') {
                    event.stopPropagation()
                    setKeysOpen(false)
                    surfaceRef.current?.focus()
                  }
                }}
              >
                {keyMenuFor(controlledPlatform).map((item) => (
                  <button
                    key={item.name}
                    type="button"
                    role="menuitem"
                    className="stage__keys-item"
                    onClick={() => sendCombination(item.name)}
                  >
                    <span>{item.label}</span>
                    <span className="stage__keys-hint">{item.keys}</span>
                  </button>
                ))}
              </div>
            ) : null}
          </div>
        ) : null}

        {/* A sibling of the surface, never inside it: a click here must not
            reach the control handlers and become a click on the remote screen. */}
        {offerFullscreen ? (
          <button
            type="button"
            className="stage__fullscreen"
            onClick={() => void toggleFullscreen()}
            aria-label={fullscreen.active ? 'Exit full screen' : 'Full screen'}
            title={fullscreen.active ? 'Exit full screen (Esc)' : 'Full screen'}
          >
            {fullscreen.active ? <Minimize size={16} aria-hidden="true" /> : <Maximize size={16} aria-hidden="true" />}
          </button>
        ) : null}
      </div>
    </div>
  )
}

/**
 * What the stage says while there is no picture: who has to do what next.
 *
 * It used to say one thing to everybody — "No screen is being shared yet" — which told the person
 * being helped nothing about the code they have to give, and told a helper still waiting to be let
 * in that "chat is available" in a room they were not yet in.
 */
function StagePlaceholder({ session, sharingName }: { session: SessionDetail; sharingName: string | null }) {
  const waitingToBeLetIn = session.me?.status === 'REQUESTED'
  const alone = session.isHost && session.participants.filter((participant) => participant.uuid !== session.me?.uuid).length === 0

  if (waitingToBeLetIn) {
    return (
      <div className="stage__placeholder">
        <MonitorOff size={30} aria-hidden="true" />
        <p className="stage__placeholder-title">Waiting to be let in</p>
        <p className="stage__placeholder-body">
          The person who started this session has been asked to let you in. Nothing is shown, and nothing can be
          sent, until they do.
        </p>
      </div>
    )
  }

  if (sharingName) {
    return (
      <div className="stage__placeholder">
        <MonitorOff size={30} aria-hidden="true" />
        <p className="stage__placeholder-title">Waiting for {sharingName}’s screen</p>
        <p className="stage__placeholder-body">It appears here as soon as the connection is made.</p>
      </div>
    )
  }

  if (session.isHost) {
    return (
      <div className="stage__placeholder">
        {/* The one thing the person being helped has to say out loud. It was a small badge in the
            header, beside a session number in bolder type that is not the code. */}
        {alone && session.sessionCode ? (
          <>
            <p className="stage__placeholder-label">Your session code</p>
            <p className="stage__code mono" aria-label={`Session code ${formatSessionCode(session.sessionCode)}`}>
              {formatSessionCode(session.sessionCode)}
            </p>
            <p className="stage__placeholder-body">
              Tell this code to the person helping you. They type it under <strong>Join session</strong>, and you let
              them in here.
            </p>
          </>
        ) : (
          <>
            <MonitorOff size={30} aria-hidden="true" />
            <p className="stage__placeholder-title">No screen is being shared yet</p>
          </>
        )}
        <p className="stage__placeholder-body">
          Choose <strong>Share</strong> below to pick what to show. Nothing is sent until you do.
        </p>
      </div>
    )
  }

  return (
    <div className="stage__placeholder">
      <MonitorOff size={30} aria-hidden="true" />
      <p className="stage__placeholder-title">No screen is being shared yet</p>
      <p className="stage__placeholder-body">It appears here when the other person starts sharing.</p>
    </div>
  )
}

/**
 * The annotation canvas.
 *
 * SVG rather than `<canvas>`: shapes are few, they need to scale with the
 * container without redrawing, and each one stays inspectable in the DOM.
 */
function AnnotationLayer({ shapes }: { shapes: AnnotationShape[] }) {
  if (shapes.length === 0) return null

  return (
    <svg className="stage__annotations" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
      {shapes.map((shape) => (
        <AnnotationShapeElement key={shape.id} shape={shape} />
      ))}
    </svg>
  )
}

function AnnotationShapeElement({ shape }: { shape: AnnotationShape }) {
  const points = shape.points.map((point) => ({ x: point.x * 100, y: point.y * 100 }))
  if (points.length === 0) return null

  const [start] = points
  const end = points[points.length - 1]

  switch (shape.tool) {
    case 'pen':
      return (
        <polyline
          points={points.map((point) => `${point.x},${point.y}`).join(' ')}
          fill="none"
          stroke={shape.colour}
          strokeWidth={0.45}
          strokeLinecap="round"
          strokeLinejoin="round"
          vectorEffect="non-scaling-stroke"
        />
      )

    case 'arrow':
      return (
        <g stroke={shape.colour} strokeWidth={0.45} fill="none" vectorEffect="non-scaling-stroke">
          <line x1={start.x} y1={start.y} x2={end.x} y2={end.y} strokeLinecap="round" />
          {arrowHead(start, end, shape.colour)}
        </g>
      )

    case 'rectangle':
      return (
        <rect
          x={Math.min(start.x, end.x)}
          y={Math.min(start.y, end.y)}
          width={Math.abs(end.x - start.x)}
          height={Math.abs(end.y - start.y)}
          fill="none"
          stroke={shape.colour}
          strokeWidth={0.45}
          vectorEffect="non-scaling-stroke"
        />
      )

    case 'highlight':
      return (
        <rect
          x={Math.min(start.x, end.x)}
          y={Math.min(start.y, end.y)}
          width={Math.abs(end.x - start.x)}
          height={Math.abs(end.y - start.y)}
          fill={shape.colour}
          opacity={0.22}
        />
      )

    default:
      return null
  }
}

function arrowHead(
  start: { x: number; y: number },
  end: { x: number; y: number },
  colour: string,
): ReactElement | null {
  const dx = end.x - start.x
  const dy = end.y - start.y
  const length = Math.hypot(dx, dy)

  if (length < 0.5) return null

  const angle = Math.atan2(dy, dx)
  const size = 2.2

  const left = {
    x: end.x - size * Math.cos(angle - Math.PI / 7),
    y: end.y - size * Math.sin(angle - Math.PI / 7),
  }
  const right = {
    x: end.x - size * Math.cos(angle + Math.PI / 7),
    y: end.y - size * Math.sin(angle + Math.PI / 7),
  }

  return <polygon points={`${end.x},${end.y} ${left.x},${left.y} ${right.x},${right.y}`} fill={colour} stroke="none" />
}
