/**
 * The browser's half of the remote-control protocol.
 *
 * Mirrors `desktop/crates/remote-protocol` exactly — the same envelope, the
 * same message names, the same version, the same normalised coordinates.
 * Where the two must agree, this file says so and
 * `remoteControl.test.ts` asserts it.
 *
 * # Coordinates are normalised, never pixels
 *
 * The `<video>` element the viewer clicks on is some size the browser chose;
 * the shared monitor is another; the capture is scaled to a third; and every
 * one of them changes during a session. A pixel coordinate would be wrong the
 * moment any of them did. What travels is a fraction of the shared surface,
 * and the agent turns it into a pixel on the right monitor.
 *
 * # The letterbox matters
 *
 * `object-fit: contain` means the video does not fill its element: there are
 * bars, and a click in a bar is not a click on the shared screen. Ignoring
 * that is why remote control in a browser is routinely off by the height of a
 * letterbox. {@link normalisePointer} accounts for it and returns null for a
 * click that landed outside the picture.
 */

/** The wire version. Must equal `remote_protocol::PROTOCOL_VERSION`. */
export const CONTROL_PROTOCOL_VERSION = 1

/** The data channel control runs on. Must equal `CONTROL_CHANNEL_LABEL`. */
export const CONTROL_CHANNEL_LABEL = 'aicountly-remote-control'

/** The largest control message. Must equal `MAX_MESSAGE_BYTES`. */
export const MAX_CONTROL_MESSAGE_BYTES = 96 * 1024

/**
 * How often a pointer move is sent, at most.
 *
 * A `mousemove` handler fires far faster than a remote desktop can act on, and
 * an agent that receives 500 moves a second spends its time on `SendInput`
 * calls nobody asked for. 60 Hz is smoother than any network this runs over.
 */
export const POINTER_THROTTLE_MS = 16

export interface PointerPosition {
  x: number
  y: number
}

export type MouseButtonName = 'left' | 'right' | 'middle'

export interface Modifiers {
  ctrl: boolean
  alt: boolean
  shift: boolean
  meta: boolean
}

/** A key, in the vocabulary `remote_protocol::Key` defines. */
export type ControlKey =
  | { k: 'character'; c: string }
  | { k: 'function'; c: number }
  | {
      k:
        | 'enter'
        | 'tab'
        | 'space'
        | 'backspace'
        | 'delete'
        | 'escape'
        | 'insert'
        | 'arrow_up'
        | 'arrow_down'
        | 'arrow_left'
        | 'arrow_right'
        | 'home'
        | 'end'
        | 'page_up'
        | 'page_down'
        | 'ctrl'
        | 'alt'
        | 'shift'
        | 'meta'
        | 'caps_lock'
        | 'print_screen'
    }

export type ControlMessage =
  | { type: 'mouse_move'; position: PointerPosition }
  | { type: 'mouse_move_relative'; dx: number; dy: number }
  | {
      type: 'mouse_button'
      button: MouseButtonName
      pressed: boolean
      double: boolean
      position?: PointerPosition
    }
  | { type: 'scroll'; delta_x: number; delta_y: number; position?: PointerPosition }
  | { type: 'key'; key: ControlKey; pressed: boolean; modifiers: Modifiers }
  | {
      type: 'clipboard'
      direction: 'to_host' | 'to_controller'
      format: 'text'
      text: string
    }
  | { type: 'monitor_layout'; monitors: MonitorDescription[]; active_monitor_id: number }
  | { type: 'select_monitor'; monitor_id: number }
  | { type: 'control_ended'; reason: string }
  | { type: 'reboot'; session_uuid: string }
  | { type: 'ping'; nonce: number }
  | { type: 'pong'; nonce: number }

export interface MonitorDescription {
  id: number
  name: string
  primary: boolean
  x: number
  y: number
  width: number
  height: number
  scale: number
  refresh_hz?: number
  orientation: string
}

/** One message on the wire. The field names are the Rust struct's. */
export interface ControlEnvelope {
  v: number
  s: string
  p: string
  n: number
  m: ControlMessage
}

/**
 * Turn a pointer event on a `<video>` into a normalised position.
 *
 * Returns `null` for a click in the letterbox — outside the picture — because
 * a click that was not on the shared screen must not become one somewhere
 * arbitrary on it.
 */
export function normalisePointer(
  event: { clientX: number; clientY: number },
  video: {
    getBoundingClientRect: () => { left: number; top: number; width: number; height: number }
    videoWidth: number
    videoHeight: number
  },
): PointerPosition | null {
  const rect = video.getBoundingClientRect()

  if (rect.width <= 0 || rect.height <= 0) return null
  if (video.videoWidth <= 0 || video.videoHeight <= 0) return null

  // `object-fit: contain`: the picture is scaled to fit and centred, so the
  // element is wider or taller than the picture by the letterbox.
  const scale = Math.min(rect.width / video.videoWidth, rect.height / video.videoHeight)
  const displayedWidth = video.videoWidth * scale
  const displayedHeight = video.videoHeight * scale

  const offsetX = (rect.width - displayedWidth) / 2
  const offsetY = (rect.height - displayedHeight) / 2

  const x = (event.clientX - rect.left - offsetX) / displayedWidth
  const y = (event.clientY - rect.top - offsetY) / displayedHeight

  if (!Number.isFinite(x) || !Number.isFinite(y)) return null
  if (x < 0 || x > 1 || y < 0 || y > 1) return null

  return { x, y }
}

/** A DOM `MouseEvent.button` as the protocol names it, or null for one it does not carry. */
export function mouseButtonName(button: number): MouseButtonName | null {
  switch (button) {
    case 0:
      return 'left'
    case 1:
      return 'middle'
    case 2:
      return 'right'
    // 3 and 4 are back and forward. They are application navigation, they are
    // not needed to help somebody, and every extra injectable input is extra
    // surface — so they are not carried at all.
    default:
      return null
  }
}

/** The modifier state at the instant of an event. */
export function modifiersFrom(event: {
  ctrlKey: boolean
  altKey: boolean
  shiftKey: boolean
  metaKey: boolean
}): Modifiers {
  return {
    ctrl: event.ctrlKey,
    alt: event.altKey,
    shift: event.shiftKey,
    meta: event.metaKey,
  }
}

/**
 * A `KeyboardEvent` as the protocol names it, or null for a key it does not
 * carry.
 *
 * `event.key` rather than `event.code`, deliberately: `key` is the character
 * the person *intends*, and the agent injects it as Unicode — so a French
 * keyboard controlling a US one produces the character that was typed rather
 * than the one in that physical position.
 *
 * Anything unmapped returns null and is dropped rather than approximated.
 * Approximating a keystroke on somebody else's machine is how a remote-control
 * product presses the wrong thing.
 */
export function controlKeyFrom(event: { key: string }): ControlKey | null {
  const named: Record<string, ControlKey['k']> = {
    Enter: 'enter',
    Tab: 'tab',
    ' ': 'space',
    Backspace: 'backspace',
    Delete: 'delete',
    Escape: 'escape',
    Insert: 'insert',
    ArrowUp: 'arrow_up',
    ArrowDown: 'arrow_down',
    ArrowLeft: 'arrow_left',
    ArrowRight: 'arrow_right',
    Home: 'home',
    End: 'end',
    PageUp: 'page_up',
    PageDown: 'page_down',
    Control: 'ctrl',
    Alt: 'alt',
    Shift: 'shift',
    Meta: 'meta',
    OS: 'meta',
    CapsLock: 'caps_lock',
    PrintScreen: 'print_screen',
  }

  const mapped = named[event.key]
  if (mapped) return { k: mapped } as ControlKey

  const fn = /^F([1-9]|1[0-9]|2[0-4])$/.exec(event.key)
  if (fn) return { k: 'function', c: Number(fn[1]) }

  // A single printable character. `event.key` is one grapheme for a printable
  // key and a word ("Shift", "Unidentified") otherwise, so length is the test.
  if ([...event.key].length === 1) {
    const character = event.key

    // Control characters have named variants above; accepting them here as
    // well would give one keystroke two spellings on the wire.
    // eslint-disable-next-line no-control-regex
    if (/[\u0000-\u001f\u007f]/.test(character)) return null

    return { k: 'character', c: character }
  }

  return null
}

/**
 * Sends control messages, in order, with a monotonic sequence.
 *
 * The sequence is what makes the agent able to drop a replayed or reordered
 * event: it admits only messages strictly newer than the last one it acted on.
 * Starting at 1 and never reusing a number is this side's half of that.
 */
export class ControlSender {
  private sequence = 0
  private lastPointerAt = 0

  constructor(
    private readonly sessionUuid: string,
    private readonly participantUuid: string,
    private readonly transmit: (bytes: ArrayBuffer) => boolean,
  ) {}

  /** How many messages have been sent. For the diagnostics panel. */
  get sent(): number {
    return this.sequence
  }

  /** Send one message. Returns false when the channel would not take it. */
  send(message: ControlMessage): boolean {
    const envelope: ControlEnvelope = {
      v: CONTROL_PROTOCOL_VERSION,
      s: this.sessionUuid,
      p: this.participantUuid,
      n: this.sequence + 1,
      m: message,
    }

    const bytes = new TextEncoder().encode(JSON.stringify(envelope))

    if (bytes.byteLength > MAX_CONTROL_MESSAGE_BYTES) return false

    // The sequence advances only for a message that actually went, so a
    // channel that refused one does not leave a gap the agent reads as a
    // dropped event.
    if (!this.transmit(bytes.buffer as ArrayBuffer)) return false

    this.sequence += 1

    return true
  }

  /**
   * Move the pointer, throttled.
   *
   * `force` is for a move that is part of a click: those must not be dropped,
   * because a click at the wrong place is much worse than a slightly late one.
   */
  movePointer(position: PointerPosition, force = false): boolean {
    const now = Date.now()

    if (!force && now - this.lastPointerAt < POINTER_THROTTLE_MS) return false

    this.lastPointerAt = now

    return this.send({ type: 'mouse_move', position })
  }

  button(
    button: MouseButtonName,
    pressed: boolean,
    position?: PointerPosition,
    double = false,
  ): boolean {
    return this.send({ type: 'mouse_button', button, pressed, double, position })
  }

  scroll(deltaX: number, deltaY: number, position?: PointerPosition): boolean {
    return this.send({
      type: 'scroll',
      // The browser reports pixels; the protocol carries notches, because a
      // notch is the unit every platform can express and converting pixels to
      // notches needs the *host's* scroll settings, which we do not have.
      delta_x: pixelsToNotches(deltaX),
      delta_y: pixelsToNotches(deltaY),
      position,
    })
  }

  key(key: ControlKey, pressed: boolean, modifiers: Modifiers): boolean {
    return this.send({ type: 'key', key, pressed, modifiers })
  }

  clipboard(text: string): boolean {
    return this.send({
      type: 'clipboard',
      direction: 'to_host',
      format: 'text',
      text,
    })
  }
}

/**
 * Browser wheel pixels to protocol notches.
 *
 * A browser's `deltaMode` is usually pixels and one notch is conventionally
 * 100 of them in Chromium; the result is bounded because a trackpad's
 * momentum can produce a delta nothing sane would scroll by, and a scroll the
 * person at the machine cannot undo is worse than one that was too small.
 */
export function pixelsToNotches(pixels: number): number {
  if (!Number.isFinite(pixels)) return 0

  return Math.max(-64, Math.min(64, pixels / 100))
}

/** Parse a control message from the agent. Null for anything that is not one. */
export function decodeControlEnvelope(data: ArrayBuffer | string): ControlEnvelope | null {
  try {
    const text = typeof data === 'string' ? data : new TextDecoder().decode(data)

    if (text.length > MAX_CONTROL_MESSAGE_BYTES) return null

    const envelope = JSON.parse(text) as ControlEnvelope

    if (envelope?.v !== CONTROL_PROTOCOL_VERSION) return null
    if (typeof envelope.s !== 'string' || typeof envelope.m !== 'object') return null

    return envelope
  } catch {
    return null
  }
}
