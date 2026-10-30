import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ComponentProps } from 'react'

import SessionStage from './SessionStage'
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
