import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ComponentProps } from 'react'

import SessionStage, { keyMenuFor } from './SessionStage'
import { installFullscreen, replace } from '../../test/fullscreen'
import type { EngineSnapshot } from '../../services/webrtc/RemoteSessionEngine'
import type { Participant, SessionDetail } from '../../types/remote'

/**
 * Full screen for the viewer (§33).
 *
 *   * offered only where the browser has the Fullscreen API, and only around
 *     somebody else's picture;
 *   * the stage — picture, overlays, control surface — goes full screen and
 *     comes back, by the button or by Esc, and the button always says which;
 *   * remote control carries on in full screen: a click still lands where it
 *     was aimed on the shared screen, the keyboard still goes through;
 *   * full screen never hides a stop control or a consent: the room's banners
 *     come onto the stage, and control ending, the picture going or anything
 *     new needing the person takes the stage out of full screen.
 */

const host = {
  uuid: 'p-host',
  displayName: 'Asha',
  role: 'SHARER',
  clientType: 'DESKTOP_AGENT',
  status: 'JOINED',
  isHost: true,
  isSharing: true,
  microphoneEnabled: false,
} as Participant

const viewer = { ...host, uuid: 'p-viewer', displayName: 'Bala', role: 'VIEWER', clientType: 'BROWSER', isHost: false, isSharing: false } as Participant

const session: SessionDetail = {
  uuid: 'sess-1',
  displayId: 'AR-10001',
  sessionCode: null,
  scopeType: 'PERSONAL',
  companyId: null,
  companyName: null,
  branchId: null,
  financialYearId: null,
  sessionType: 'ASSISTANCE',
  status: 'ACTIVE',
  requestedShareMode: 'ENTIRE_MONITOR',
  actualDisplaySurface: 'monitor',
  sourceProduct: null,
  sourceProductLabel: null,
  sourceRoute: null,
  supportTicketId: null,
  issueSummary: null,
  ownerName: 'Asha',
  capabilities: {} as SessionDetail['capabilities'],
  maxDurationMinutes: 60,
  startedAt: null,
  endedAt: null,
  expiresAt: null,
  createdAt: null,
  durationSeconds: null,
  endReason: null,
  participants: [host, viewer],
  isHost: false,
  me: viewer,
}

const picture = { id: 'asha-screen' } as unknown as MediaStream

const watching = (overrides: Partial<EngineSnapshot> = {}): EngineSnapshot =>
  ({ remoteStream: picture, localStream: null, annotations: [], pointers: {}, controlPaused: null, ...overrides }) as EngineSnapshot

type StageProps = ComponentProps<typeof SessionStage>

let fullscreen: ReturnType<typeof installFullscreen> | null = null
let restorePointerCapture: (() => void) | null = null

function stage(props: Partial<StageProps> = {}) {
  return (
    <SessionStage
      session={session}
      live={watching()}
      pointerEnabled={false}
      annotationTool="none"
      annotationColour="#25b003"
      onPointerMove={() => {}}
      onAnnotation={() => {}}
      authorName="Bala"
      {...props}
    />
  )
}

/** The `<video>` laid out at a size, showing a 1920×1080 screen — what jsdom cannot do on its own. */
function layOut(video: HTMLVideoElement, rect: { left: number; top: number; width: number; height: number }) {
  Object.defineProperty(video, 'videoWidth', { configurable: true, value: 1920 })
  Object.defineProperty(video, 'videoHeight', { configurable: true, value: 1080 })
  vi.spyOn(video, 'getBoundingClientRect').mockReturnValue({ ...rect, x: rect.left, y: rect.top, right: rect.left + rect.width, bottom: rect.top + rect.height, toJSON: () => ({}) })
}

beforeEach(() => {
  // jsdom implements neither, and the stage calls both: playing a stream, and
  // capturing the pointer for a press on the remote screen.
  vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined)
  restorePointerCapture = replace(Element.prototype, 'setPointerCapture', { value: vi.fn() })
})

afterEach(() => {
  fullscreen?.uninstall()
  fullscreen = null
  restorePointerCapture?.()
  restorePointerCapture = null
})

describe('full screen on the stage', () => {
  it('is not offered where the browser has no Fullscreen API', () => {
    render(stage())

    expect(screen.queryByRole('button', { name: 'Full screen' })).not.toBeInTheDocument()
  })

  it('is not offered around the sharer’s own preview', () => {
    fullscreen = installFullscreen()
    render(stage({ live: watching({ remoteStream: null, localStream: picture }), session: { ...session, isHost: true, me: host } }))

    expect(screen.queryByRole('button', { name: 'Full screen' })).not.toBeInTheDocument()
  })

  it('takes the stage — picture and overlays — full screen and back, by the button or by Esc', async () => {
    fullscreen = installFullscreen()
    const onFullscreenChange = vi.fn()
    const { container } = render(stage({ onFullscreenChange }))
    const video = container.querySelector('video') as HTMLVideoElement

    await userEvent.click(screen.getByRole('button', { name: 'Full screen' }))

    expect(fullscreen.requestFullscreen).toHaveBeenCalledOnce()
    expect(fullscreen.element).toHaveClass('stage', 'stage--fullscreen')
    expect(fullscreen.element).toContainElement(video)
    expect(onFullscreenChange).toHaveBeenLastCalledWith(true)

    await userEvent.click(screen.getByRole('button', { name: 'Exit full screen' }))

    expect(fullscreen.exitFullscreen).toHaveBeenCalledOnce()
    expect(fullscreen.element).toBeNull()
    expect(screen.getByRole('button', { name: 'Full screen' })).toBeInTheDocument()
    expect(onFullscreenChange).toHaveBeenLastCalledWith(false)

    // Esc is the browser's, not the page's: the stage follows what the browser did.
    await userEvent.click(screen.getByRole('button', { name: 'Full screen' }))
    act(() => fullscreen?.escape())

    expect(screen.getByRole('button', { name: 'Full screen' })).toBeInTheDocument()
    expect(container.querySelector('.stage')).not.toHaveClass('stage--fullscreen')
    expect(onFullscreenChange).toHaveBeenLastCalledWith(false)
  })

  it('keeps remote control working in full screen', async () => {
    fullscreen = installFullscreen()
    const onControlButton = vi.fn()
    const onControlPointer = vi.fn()
    const onControlKey = vi.fn(() => true)
    const { container } = render(stage({ controlling: true, onControlButton, onControlPointer, onControlKey }))

    await userEvent.click(screen.getByRole('button', { name: 'Full screen' }))

    // The click on the button took focus; the stage hands it back, so typing carries on.
    const surface = screen.getByRole('application')
    expect(surface).toHaveFocus()

    // Full screen lays the picture out at another size, with the status strip above it and bars at the sides.
    layOut(container.querySelector('video') as HTMLVideoElement, { left: 0, top: 40, width: 1920, height: 1040 })

    fireEvent.pointerDown(surface, { clientX: 960, clientY: 560, button: 0, pointerId: 1 })

    expect(onControlButton).toHaveBeenCalledWith(0, true, { x: expect.closeTo(0.5, 5), y: expect.closeTo(0.5, 5) }, false)

    // A press in a letterbox bar is on nothing of the shared screen, here as anywhere.
    onControlButton.mockClear()
    fireEvent.pointerDown(surface, { clientX: 10, clientY: 560, button: 0, pointerId: 1 })
    expect(onControlButton).not.toHaveBeenCalled()

    fireEvent.keyDown(surface, { key: 'a' })
    expect(onControlKey).toHaveBeenCalledWith(expect.objectContaining({ key: 'a' }), true)

    // And the button is not part of the remote screen: pressing it sends nothing there.
    onControlButton.mockClear()
    await userEvent.click(screen.getByRole('button', { name: 'Exit full screen' }))
    expect(onControlButton).not.toHaveBeenCalled()
    expect(surface).toHaveFocus()
  })

  it('brings the room’s status and stop controls onto the stage while full screen', async () => {
    fullscreen = installFullscreen()
    const onStop = vi.fn()
    render(
      stage({
        controlling: true,
        fullscreenStatus: <span>Connected · 45 ms · 15 fps</span>,
        fullscreenBanners: (
          <button type="button" onClick={onStop}>
            Stop controlling
          </button>
        ),
      }),
    )

    // Out of full screen the room shows them, above the stage — not the stage.
    expect(screen.queryByRole('button', { name: 'Stop controlling' })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Full screen' }))

    const shown = within(fullscreen.element as HTMLElement)
    expect(shown.getByText('Connected · 45 ms · 15 fps')).toBeInTheDocument()

    await userEvent.click(shown.getByRole('button', { name: 'Stop controlling' }))
    expect(onStop).toHaveBeenCalledOnce()
  })

  it('leaves full screen when control ends, so the room can say why', async () => {
    fullscreen = installFullscreen()
    const { rerender } = render(stage({ controlling: true }))

    await userEvent.click(screen.getByRole('button', { name: 'Full screen' }))
    expect(fullscreen.element).not.toBeNull()

    rerender(stage({ controlling: false }))

    expect(fullscreen.exitFullscreen).toHaveBeenCalledOnce()
    expect(fullscreen.element).toBeNull()
    expect(screen.getByRole('button', { name: 'Full screen' })).toBeInTheDocument()
  })

  it('leaves full screen when the picture goes', async () => {
    fullscreen = installFullscreen()
    const { rerender } = render(stage())

    await userEvent.click(screen.getByRole('button', { name: 'Full screen' }))
    rerender(stage({ live: watching({ remoteStream: null }) }))

    expect(fullscreen.element).toBeNull()
    expect(screen.getByText('Waiting for Asha’s screen')).toBeInTheDocument()
  })

  it('leaves full screen for something new that needs the person, not for what was already there', async () => {
    fullscreen = installFullscreen()
    const { rerender } = render(stage({ attention: ['notice:Remote control stopped.'] }))

    await userEvent.click(screen.getByRole('button', { name: 'Full screen' }))

    // The same notice, as the room renders it again a second later: already seen.
    rerender(stage({ attention: ['notice:Remote control stopped.'] }))
    expect(fullscreen.element).not.toBeNull()

    // Somebody knocking since is not.
    rerender(stage({ attention: ['notice:Remote control stopped.', 'waiting:p-guest'] }))
    expect(fullscreen.exitFullscreen).toHaveBeenCalledOnce()
    expect(fullscreen.element).toBeNull()
  })

  it('does not leave the browser full screen when the room goes away', async () => {
    fullscreen = installFullscreen()
    const { unmount } = render(stage())

    await userEvent.click(screen.getByRole('button', { name: 'Full screen' }))
    unmount()

    expect(fullscreen.exitFullscreen).toHaveBeenCalledOnce()
    expect(fullscreen.element).toBeNull()
  })
})

/**
 * What the stage does for whoever is controlling, beyond passing the mouse and keyboard through.
 */
describe('the stage while controlling', () => {
  const controllingProps = (props: Partial<StageProps> = {}): Partial<StageProps> => ({
    controlling: true,
    onControlKey: vi.fn(() => true),
    onControlRelease: vi.fn(),
    ...props,
  })

  /**
   * Ctrl+Alt+Del and the Windows key never reach a web page — the helper's own computer takes them —
   * so they are sent from a menu.
   */
  it('sends the keys a keyboard cannot hand over from the Keys menu, and gives the keyboard back', async () => {
    const onControlCombination = vi.fn(() => true)
    const { container } = render(stage(controllingProps({ onControlCombination })))

    expect(screen.queryByRole('menu')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Keys' }))
    const menu = screen.getByRole('menu', { name: 'Send keys to the remote computer' })

    await userEvent.click(within(menu).getByRole('menuitem', { name: /Ctrl\+Alt\+Del/ }))

    expect(onControlCombination).toHaveBeenCalledWith('ctrl_alt_del')
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
    // Typing carries on on the remote computer, not in the menu.
    expect(container.querySelector('.stage__surface')).toHaveFocus()

    await userEvent.click(screen.getByRole('button', { name: 'Keys' }))
    await userEvent.click(screen.getByRole('menuitem', { name: /Task Manager/ }))
    expect(onControlCombination).toHaveBeenLastCalledWith('task_manager')
  })

  /**
   * The Windows list did other things on a Mac: "Run…" is ⌘R there, which reloads the page in front.
   * A Mac is offered a Mac's keys.
   */
  it('offers a Mac its own keys, and a computer that has not said what it is the Windows ones', async () => {
    const onControlCombination = vi.fn(() => true)
    const view = render(stage(controllingProps({ onControlCombination, controlledPlatform: 'mac' })))

    await userEvent.click(screen.getByRole('button', { name: 'Keys' }))
    const menu = screen.getByRole('menu', { name: 'Send keys to the remote computer' })

    expect(within(menu).queryByRole('menuitem', { name: /Ctrl\+Alt\+Del/ })).not.toBeInTheDocument()
    expect(within(menu).queryByRole('menuitem', { name: /Run…/ })).not.toBeInTheDocument()
    expect(within(menu).getByRole('menuitem', { name: /Spotlight search/ })).toBeInTheDocument()
    // The Mac's own Control key: a helper on a PC keyboard cannot type it, because their Ctrl is sent as ⌘.
    expect(within(menu).getByRole('menuitem', { name: /Interrupt/ })).toBeInTheDocument()

    await userEvent.click(within(menu).getByRole('menuitem', { name: /Force Quit/ }))
    expect(onControlCombination).toHaveBeenCalledWith('force_quit')

    view.unmount()

    // Windows, and a computer that has not said: the Windows list.
    expect(keyMenuFor('pc').map((item) => item.name)).toContain('ctrl_alt_del')
    expect(keyMenuFor(null)).toBe(keyMenuFor('pc'))
    expect(keyMenuFor('mac').map((item) => item.name)).toEqual([
      'force_quit',
      'spotlight',
      'switch_app',
      'mission_control',
      'close_window_mac',
      'quit_app',
      'interrupt',
      'escape',
    ])
  })

  it('offers no Keys menu to somebody who is only watching', () => {
    render(stage({ onControlCombination: vi.fn() }))

    expect(screen.queryByRole('button', { name: 'Keys' })).not.toBeInTheDocument()
  })

  /**
   * "Granted" at both ends and nothing moving: the computer now says why, and the stage says it to
   * whoever is controlling.
   */
  it('says why the computer would not take a click, in words that say what to do', () => {
    const { rerender } = render(
      stage(controllingProps({ live: watching({ inputRefused: 'elevated_window' } as Partial<EngineSnapshot>) })),
    )

    expect(screen.getByText(/runs as administrator/)).toBeInTheDocument()

    rerender(
      stage(controllingProps({ live: watching({ inputRefused: 'secure_attention_not_allowed' } as Partial<EngineSnapshot>) })),
    )
    expect(screen.getByText(/Ctrl\+Alt\+Del was not sent/)).toBeInTheDocument()
    expect(screen.getByText(/Also let them answer Windows administrator prompts/)).toBeInTheDocument()

    rerender(stage(controllingProps({ live: watching({ inputRefused: null } as Partial<EngineSnapshot>) })))
    expect(screen.queryByText(/was not sent/)).not.toBeInTheDocument()
  })

  it('shows what the computer has to say one under another, not on top of each other', () => {
    const { container } = render(
      stage(
        controllingProps({
          live: watching({
            controlPaused: 'local_input',
            secureDesktop: { active: true, reachable: false },
            inputRefused: 'refused',
          } as Partial<EngineSnapshot>),
        }),
      ),
    )

    expect(container.querySelectorAll('.stage__notices > .stage__paused')).toHaveLength(3)
  })

  /**
   * With the clipboard shared, Ctrl+V has to paste the helper's clipboard. Preventing the key's
   * default — as the stage did for every key — stops the browser firing `paste`, so the text was
   * never sent and the other computer pasted its own clipboard.
   */
  describe('pasting with the clipboard shared', () => {
    const pasteProps = () => {
      const sent: string[] = []
      const props = controllingProps({
        clipboardShared: true,
        onControlClipboard: vi.fn((text: string) => {
          sent.push(`clipboard:${text}`)

          return true
        }),
        onControlKey: vi.fn((event: KeyboardEvent, pressed: boolean) => {
          sent.push(`${pressed ? 'down' : 'up'}:${event.key}`)

          return true
        }),
      })

      return { sent, props }
    }

    const paste = (text: string) => {
      const event = new Event('paste') as ClipboardEvent
      Object.defineProperty(event, 'clipboardData', { value: { getData: () => text } })
      window.dispatchEvent(event)
    }

    it('sends the clipboard first and the keys that paste it second', () => {
      const { sent, props } = pasteProps()
      const { container } = render(stage(props))
      const surface = container.querySelector<HTMLElement>('.stage__surface')!
      surface.focus()

      fireEvent.keyDown(surface, { key: 'Control', ctrlKey: true })
      const pressed = fireEvent.keyDown(surface, { key: 'v', ctrlKey: true })

      // Left alone, so the browser fires `paste`; and not sent yet.
      expect(pressed).toBe(true)
      expect(sent).toEqual(['down:Control'])

      act(() => paste('from the helper'))
      fireEvent.keyUp(surface, { key: 'v', ctrlKey: true })
      fireEvent.keyUp(surface, { key: 'Control' })

      expect(sent).toEqual(['down:Control', 'clipboard:from the helper', 'down:v', 'up:v', 'up:Control'])
    })

    it('still sends the keys when the browser hands over nothing to paste', () => {
      vi.useFakeTimers()

      try {
        const { sent, props } = pasteProps()
        const { container } = render(stage(props))
        const surface = container.querySelector<HTMLElement>('.stage__surface')!
        surface.focus()

        fireEvent.keyDown(surface, { key: 'v', ctrlKey: true })
        expect(sent).toEqual([])

        act(() => {
          vi.advanceTimersByTime(300)
        })

        expect(sent).toEqual(['down:v'])
      } finally {
        vi.useRealTimers()
      }
    })

    it('sends the press before the release when the key is let go before the clipboard arrives', () => {
      const { sent, props } = pasteProps()
      const { container } = render(stage(props))
      const surface = container.querySelector<HTMLElement>('.stage__surface')!
      surface.focus()

      fireEvent.keyDown(surface, { key: 'v', ctrlKey: true })
      fireEvent.keyUp(surface, { key: 'v', ctrlKey: true })

      expect(sent).toEqual(['down:v', 'up:v'])
    })

    it('treats Ctrl+V as any other key while the clipboard is not shared', () => {
      const { sent, props } = pasteProps()
      const { container } = render(stage({ ...props, clipboardShared: false }))
      const surface = container.querySelector<HTMLElement>('.stage__surface')!
      surface.focus()

      const pressed = fireEvent.keyDown(surface, { key: 'v', ctrlKey: true })

      expect(pressed).toBe(false)
      expect(sent).toEqual(['down:v'])
    })
  })

  /** The label sat over the corner of the remote screen where the Start button is, and took the click. */
  it('takes the “is sharing” label off the picture while controlling', () => {
    const { rerender } = render(stage())
    expect(screen.getByText(/is sharing/)).toBeInTheDocument()

    rerender(stage(controllingProps()))
    expect(screen.queryByText(/is sharing/)).not.toBeInTheDocument()
  })
})
