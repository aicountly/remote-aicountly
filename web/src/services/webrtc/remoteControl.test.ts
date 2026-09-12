import { describe, expect, it } from 'vitest'

import {
  CONTROL_CHANNEL_LABEL,
  CONTROL_PROTOCOL_VERSION,
  ControlSender,
  MAX_CONTROL_MESSAGE_BYTES,
  controlKeyFrom,
  decodeControlEnvelope,
  mouseButtonName,
  normalisePointer,
  pixelsToNotches,
} from './remoteControl'
import type { ControlEnvelope } from './remoteControl'

/**
 * The browser half of the control protocol.
 *
 * These are the properties the Rust side depends on. Where a value must equal
 * one in `desktop/crates/remote-protocol`, the constant is asserted here so a
 * change on either side fails a test rather than producing a session where
 * every click lands somewhere the person never aimed at.
 */

/** A `<video>` stand-in: an element box, and the picture's intrinsic size. */
function video(
  rect: { left: number; top: number; width: number; height: number },
  intrinsic: { width: number; height: number },
) {
  return {
    getBoundingClientRect: () => rect,
    videoWidth: intrinsic.width,
    videoHeight: intrinsic.height,
  }
}

describe('the wire constants match the agent', () => {
  it('carries version 1 on the aicountly-remote-control channel', () => {
    // Duplicated in desktop/crates/remote-protocol/src/lib.rs. They are
    // asserted rather than imported because the two languages cannot share a
    // definition — so this test is the shared definition.
    expect(CONTROL_PROTOCOL_VERSION).toBe(1)
    expect(CONTROL_CHANNEL_LABEL).toBe('aicountly-remote-control')
    expect(MAX_CONTROL_MESSAGE_BYTES).toBe(96 * 1024)
  })
})

describe('normalisePointer', () => {
  it('maps a click to a fraction of the shared screen, not of the element', () => {
    // A 16:9 picture in a 16:9 box: no letterbox, so the middle is the middle.
    const point = normalisePointer(
      { clientX: 100, clientY: 50 },
      video({ left: 0, top: 0, width: 200, height: 100 }, { width: 1920, height: 1080 }),
    )

    expect(point).toEqual({ x: 0.5, y: 0.5 })
  })

  it('corrects for the letterbox object-fit: contain leaves', () => {
    // A 16:9 picture in a square box is 200x112.5, centred, with 43.75px bars
    // top and bottom. The top edge of the *picture* is therefore y=43.75.
    const element = video({ left: 0, top: 0, width: 200, height: 200 }, { width: 1600, height: 900 })

    expect(normalisePointer({ clientX: 0, clientY: 43.75 }, element)).toEqual({ x: 0, y: 0 })
    expect(normalisePointer({ clientX: 200, clientY: 156.25 }, element)).toEqual({ x: 1, y: 1 })
  })

  it('refuses a click in the letterbox rather than clamping it onto the screen', () => {
    // This is the whole reason the function exists. Clamping would land a
    // click at the top edge of somebody's desktop that they never aimed at.
    const element = video({ left: 0, top: 0, width: 200, height: 200 }, { width: 1600, height: 900 })

    expect(normalisePointer({ clientX: 100, clientY: 4 }, element)).toBeNull()
    expect(normalisePointer({ clientX: 100, clientY: 196 }, element)).toBeNull()
  })

  it('refuses to guess before the video has a size', () => {
    expect(
      normalisePointer(
        { clientX: 10, clientY: 10 },
        video({ left: 0, top: 0, width: 200, height: 100 }, { width: 0, height: 0 }),
      ),
    ).toBeNull()

    expect(
      normalisePointer(
        { clientX: 10, clientY: 10 },
        video({ left: 0, top: 0, width: 0, height: 0 }, { width: 1920, height: 1080 }),
      ),
    ).toBeNull()
  })

  it('accounts for an element that is not at the top left of the page', () => {
    const point = normalisePointer(
      { clientX: 340, clientY: 190 },
      video({ left: 240, top: 140, width: 200, height: 100 }, { width: 400, height: 200 }),
    )

    expect(point).toEqual({ x: 0.5, y: 0.5 })
  })
})

describe('mouseButtonName', () => {
  it('carries the three buttons a person points with', () => {
    expect(mouseButtonName(0)).toBe('left')
    expect(mouseButtonName(1)).toBe('middle')
    expect(mouseButtonName(2)).toBe('right')
  })

  it('drops back and forward rather than injecting them', () => {
    // Every injectable input is surface. These are not needed to help
    // somebody, so they are not carried at all.
    expect(mouseButtonName(3)).toBeNull()
    expect(mouseButtonName(4)).toBeNull()
  })
})

describe('controlKeyFrom', () => {
  it('names the keys the protocol has names for', () => {
    expect(controlKeyFrom({ key: 'Enter' })).toEqual({ k: 'enter' })
    expect(controlKeyFrom({ key: 'ArrowLeft' })).toEqual({ k: 'arrow_left' })
    expect(controlKeyFrom({ key: ' ' })).toEqual({ k: 'space' })
    expect(controlKeyFrom({ key: 'F7' })).toEqual({ k: 'function', c: 7 })
  })

  it('sends the character that was typed, not the key that was pressed', () => {
    // `event.key` rather than `event.code`, so a French keyboard controlling a
    // US one produces the character the person meant.
    expect(controlKeyFrom({ key: 'é' })).toEqual({ k: 'character', c: 'é' })
    expect(controlKeyFrom({ key: '€' })).toEqual({ k: 'character', c: '€' })
  })

  it('drops a key it has no name for rather than approximating one', () => {
    expect(controlKeyFrom({ key: 'Unidentified' })).toBeNull()
    expect(controlKeyFrom({ key: 'BrowserHome' })).toBeNull()
    expect(controlKeyFrom({ key: '' })).toBeNull()
  })

  it('refuses a raw control character, which already has a named spelling', () => {
    // Backspace, Escape and Delete arrive as names. Accepting the C0/C1
    // character too would give one keystroke two spellings on the wire.
    expect(controlKeyFrom({ key: '\u0008' })).toBeNull()
    expect(controlKeyFrom({ key: '\u001b' })).toBeNull()
    expect(controlKeyFrom({ key: '\u007f' })).toBeNull()
  })

  it('rejects F0 and F25, which no keyboard has', () => {
    expect(controlKeyFrom({ key: 'F0' })).toBeNull()
    expect(controlKeyFrom({ key: 'F25' })).toBeNull()
    expect(controlKeyFrom({ key: 'F24' })).toEqual({ k: 'function', c: 24 })
  })
})

describe('pixelsToNotches', () => {
  it('converts a browser wheel delta to notches', () => {
    expect(pixelsToNotches(100)).toBe(1)
    expect(pixelsToNotches(-250)).toBe(-2.5)
  })

  it('bounds a trackpad flick, because an unscrollable page is not undoable', () => {
    expect(pixelsToNotches(1_000_000)).toBe(64)
    expect(pixelsToNotches(-1_000_000)).toBe(-64)
  })

  it('sends nothing for a delta that is not a number', () => {
    expect(pixelsToNotches(Number.NaN)).toBe(0)
    expect(pixelsToNotches(Number.POSITIVE_INFINITY)).toBe(0)
  })
})

describe('ControlSender', () => {
  function sender() {
    const sent: ControlEnvelope[] = []

    const instance = new ControlSender('session-1', 'participant-1', (bytes) => {
      sent.push(JSON.parse(new TextDecoder().decode(bytes)) as ControlEnvelope)

      return true
    })

    return { instance, sent }
  }

  it('numbers messages from 1 and never reuses a number', () => {
    // The agent admits only messages strictly newer than the last it acted on,
    // so a gap-free monotonic sequence is this side's half of replay defence.
    const { instance, sent } = sender()

    instance.send({ type: 'ping', nonce: 1 })
    instance.send({ type: 'ping', nonce: 2 })
    instance.send({ type: 'ping', nonce: 3 })

    expect(sent.map((envelope) => envelope.n)).toEqual([1, 2, 3])
    expect(instance.sent).toBe(3)
  })

  it('does not advance the sequence for a message the channel refused', () => {
    // A gap would read to the agent as a dropped event.
    const sent: ControlEnvelope[] = []
    let accept = false

    const instance = new ControlSender('session-1', 'participant-1', (bytes) => {
      if (!accept) return false

      sent.push(JSON.parse(new TextDecoder().decode(bytes)) as ControlEnvelope)

      return true
    })

    expect(instance.send({ type: 'ping', nonce: 1 })).toBe(false)
    expect(instance.sent).toBe(0)

    accept = true

    expect(instance.send({ type: 'ping', nonce: 2 })).toBe(true)
    expect(sent[0].n).toBe(1)
  })

  it('stamps every message with the session and participant it belongs to', () => {
    const { instance, sent } = sender()

    instance.movePointer({ x: 0.25, y: 0.75 }, true)

    expect(sent[0]).toMatchObject({
      v: CONTROL_PROTOCOL_VERSION,
      s: 'session-1',
      p: 'participant-1',
      m: { type: 'mouse_move', position: { x: 0.25, y: 0.75 } },
    })
  })

  it('throttles pointer moves but never a move that is part of a click', () => {
    const { instance, sent } = sender()

    instance.movePointer({ x: 0.1, y: 0.1 }, true)
    // Immediately after: dropped, because a remote desktop cannot act on 500
    // moves a second and the agent should not be asked to.
    expect(instance.movePointer({ x: 0.2, y: 0.2 })).toBe(false)
    // Forced: a click at a stale position is much worse than a late one.
    expect(instance.movePointer({ x: 0.3, y: 0.3 }, true)).toBe(true)

    expect(sent).toHaveLength(2)
  })

  it('refuses a message larger than the agent will accept', () => {
    const { instance, sent } = sender()

    expect(instance.clipboard('a'.repeat(MAX_CONTROL_MESSAGE_BYTES))).toBe(false)
    expect(sent).toHaveLength(0)
  })

  it('sends the clipboard towards the host, and says so on the wire', () => {
    const { instance, sent } = sender()

    instance.clipboard('an invoice number')

    expect(sent[0].m).toEqual({
      type: 'clipboard',
      direction: 'to_host',
      format: 'text',
      text: 'an invoice number',
    })
  })
})

describe('decodeControlEnvelope', () => {
  it('reads a message from the agent', () => {
    const envelope = decodeControlEnvelope(
      JSON.stringify({
        v: 1,
        s: 'session-1',
        p: 'device-1',
        n: 4,
        m: { type: 'control_ended', reason: 'stopped_locally' },
      }),
    )

    expect(envelope?.m).toEqual({ type: 'control_ended', reason: 'stopped_locally' })
  })

  it('refuses another protocol version rather than guessing at it', () => {
    expect(decodeControlEnvelope(JSON.stringify({ v: 2, s: 'a', p: 'b', n: 1, m: {} }))).toBeNull()
  })

  it('refuses anything that is not a message at all', () => {
    expect(decodeControlEnvelope('not json')).toBeNull()
    expect(decodeControlEnvelope(JSON.stringify({ v: 1, s: 1, m: {} }))).toBeNull()
    expect(decodeControlEnvelope(JSON.stringify({ v: 1, s: 'a', p: 'b', n: 1, m: 'nope' }))).toBeNull()
  })
})
