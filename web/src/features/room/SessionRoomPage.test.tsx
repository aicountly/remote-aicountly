import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'

import { GuestRemoteProvider } from '../../app/RemoteProvider'
import { RemoteApiError } from '../../services/api/client'
import { installFullscreen } from '../../test/fullscreen'
import type { EnginePeer, EngineSnapshot } from '../../services/webrtc/RemoteSessionEngine'
import type { Participant, SessionDetail } from '../../types/remote'

/**
 * The room's header actions.
 *
 *   * End used to be an unhandled promise: when the API call failed nothing was
 *     said, so the person was left wondering whether they were still sharing;
 *   * a guest was offered End, which the API always refuses a guest — Leave is
 *     what a guest can do;
 *   * Pause, Resume, Allow and Decline were unhandled promises too.
 */

const actions = vi.hoisted(() => ({
  end: vi.fn(),
  leave: vi.fn(async () => undefined),
  pause: vi.fn(),
  resume: vi.fn(),
  approve: vi.fn(),
  deny: vi.fn(),
  stopControl: vi.fn(),
}))
/** The session a test renders, when it is not the default one below — and the live state, when there is one. */
const room = vi.hoisted(() => ({
  session: null as SessionDetail | null,
  live: null as EngineSnapshot | null,
  isControlling: false,
  controllableHost: null as EnginePeer | null,
}))

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
  requestedShareMode: 'BROWSER_TAB',
  actualDisplaySurface: null,
  sourceProduct: null,
  sourceProductLabel: null,
  sourceRoute: null,
  supportTicketId: null,
  issueSummary: null,
  ownerName: 'Asha',
  capabilities: { chat: false, annotation: false, audio: false, fileTransfer: false, externalGuest: false } as SessionDetail['capabilities'],
  maxDurationMinutes: 60,
  startedAt: null,
  endedAt: null,
  expiresAt: null,
  createdAt: null,
  durationSeconds: null,
  endReason: null,
  participants: [],
  isHost: false,
  me: null,
  waiting: [],
}

vi.mock('./useRemoteSession', () => ({
  useRemoteSession: () => ({
    session: room.session ?? session,
    loading: false,
    error: null,
    ended: false,
    messages: [],
    live: room.live,
    shareIntent: { phase: 'idle' },
    control: null,
    controlBusy: false,
    controlNotice: null,
    controllableHost: room.controllableHost,
    isControlling: room.isControlling,
    controlPaused: null,
    actions,
  }),
}))

const { default: SessionRoomPage } = await import('./SessionRoomPage')

function roomTree() {
  return (
    <MemoryRouter initialEntries={['/room/sess-1']}>
      <GuestRemoteProvider>
        <Routes>
          <Route path="/room/:uuid" element={<SessionRoomPage />} />
          <Route path="*" element={<p>left the room</p>} />
        </Routes>
      </GuestRemoteProvider>
    </MemoryRouter>
  )
}

function renderRoom() {
  return render(roomTree())
}

const unreachable = () =>
  new RemoteApiError('NETWORK_ERROR', 'We could not reach AICOUNTLY Remote. Check your connection.', 0)

const knocking = {
  uuid: 'p-guest',
  displayName: 'Bala',
  role: 'GUEST',
  status: 'REQUESTED',
  isHost: false,
  isSharing: false,
  microphoneEnabled: false,
} as Participant

describe('the room header', () => {
  afterEach(() => {
    sessionStorage.clear()
    room.session = null
    room.live = null
    room.isControlling = false
    room.controllableHost = null
    for (const action of Object.values(actions)) action.mockReset()
  })

  it('says so when ending the session failed', async () => {
    actions.end.mockRejectedValue(
      new RemoteApiError('NETWORK_ERROR', 'We could not reach AICOUNTLY Remote. Check your connection.', 0),
    )
    renderRoom()

    await userEvent.click(screen.getByRole('button', { name: 'End' }))

    expect(actions.end).toHaveBeenCalledTimes(1)
    expect(await screen.findByText('We could not reach AICOUNTLY Remote. Check your connection.')).toBeInTheDocument()
  })

  it('offers a guest Leave, not an End the API refuses them', async () => {
    sessionStorage.setItem('remote:guestToken', 'guest.payload.signature')
    renderRoom()

    expect(screen.queryByRole('button', { name: 'End' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Leave' }))

    expect(actions.leave).toHaveBeenCalledTimes(1)
    expect(actions.end).not.toHaveBeenCalled()
    expect(await screen.findByText('left the room')).toBeInTheDocument()
  })

  it('says so when pausing failed', async () => {
    room.session = { ...session, isHost: true }
    actions.pause.mockRejectedValue(unreachable())
    renderRoom()

    await userEvent.click(screen.getByRole('button', { name: 'Pause' }))

    expect(actions.pause).toHaveBeenCalledTimes(1)
    expect(await screen.findByText('We could not reach AICOUNTLY Remote. Check your connection.')).toBeInTheDocument()
  })

  it('says so when admitting somebody from the queue failed', async () => {
    room.session = { ...session, isHost: true, waiting: [knocking] }
    actions.approve.mockRejectedValue(unreachable())
    renderRoom()

    await userEvent.click(screen.getByRole('button', { name: 'Allow' }))

    expect(actions.approve).toHaveBeenCalledWith('p-guest')
    expect(await screen.findByText('We could not reach AICOUNTLY Remote. Check your connection.')).toBeInTheDocument()
  })

  // The list said "Sharing" under the host from the moment the room opened, beside a stage saying nothing was.
  it('names the host\'s role without claiming they are sharing', () => {
    const host = { ...knocking, uuid: 'p-host', displayName: 'Asha', role: 'SHARER', status: 'JOINED', isHost: true } as Participant
    room.session = { ...session, isHost: true, participants: [host] }
    renderRoom()

    expect(screen.getByText('Sharer')).toBeInTheDocument()
    expect(screen.queryByText('Sharing')).not.toBeInTheDocument()
  })

  it('says so when declining somebody from the participant list failed', async () => {
    room.session = { ...session, isHost: true, participants: [knocking] }
    actions.deny.mockRejectedValue(new Error('socket hang up'))
    renderRoom()

    await userEvent.click(screen.getByRole('button', { name: 'Decline' }))

    expect(actions.deny).toHaveBeenCalledWith('p-guest')
    expect(await screen.findByText('Declining them did not go through. Try again.')).toBeInTheDocument()
  })
})

/**
 * Full screen in the room (§18, §30, §33).
 *
 * The browser shows nothing but the stage while it is full screen, so the room
 * moves its status line and its banners onto the stage — the stop control is
 * on screen for as long as input is flowing, exactly once — and a knock at the
 * door takes the stage back out, so the host can answer it.
 */
describe('full screen in the room', () => {
  const sharer = { ...knocking, uuid: 'p-host', displayName: 'Asha', role: 'SHARER', status: 'JOINED', isHost: true, isSharing: true } as Participant
  const viewer = { ...knocking, uuid: 'p-viewer', displayName: 'Bala', role: 'VIEWER', status: 'JOINED' } as Participant

  const agent: EnginePeer = {
    participantUuid: 'p-host',
    role: 'SHARER',
    name: 'WS-01',
    capabilities: { remote_control: true },
    connectionState: 'connected',
    dataChannelReady: true,
    controlChannelReady: true,
  }

  const watching = (): EngineSnapshot => ({
    connection: 'connected',
    signalling: 'open',
    quality: 'good',
    stats: { roundTripMs: 45, framesPerSecond: 15 },
    peers: [agent],
    remoteStream: { id: 'asha-screen' } as unknown as MediaStream,
    localStream: null,
    isSharing: false,
    microphoneOn: false,
    pointers: {},
    annotations: [],
    transfers: [],
    relayAvailable: true,
    relayDenied: false,
    controllingPeerId: null,
    controlPaused: null,
    network: {
      path: 'direct',
      relayNode: null,
      relayProtocol: null,
      recovering: false,
      restarts: 0,
      verdict: null,
      message: null,
      credentialsExpireAt: null,
    },
    lastError: null,
  })

  let fullscreen: ReturnType<typeof installFullscreen>

  beforeEach(() => {
    fullscreen = installFullscreen()
    // jsdom cannot play a stream, and the stage plays the one it is given.
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined)
  })

  afterEach(() => {
    fullscreen.uninstall()
    room.session = null
    room.live = null
    room.isControlling = false
    room.controllableHost = null
    for (const action of Object.values(actions)) action.mockReset()
  })

  it('keeps “Stop controlling” on screen in full screen — on the stage, and only there', async () => {
    room.session = { ...session, participants: [sharer, viewer], me: viewer }
    room.live = { ...watching(), controllingPeerId: 'p-host' }
    room.isControlling = true
    room.controllableHost = agent
    renderRoom()

    await userEvent.click(screen.getByRole('button', { name: 'Full screen' }))

    const stage = within(fullscreen.element as HTMLElement)
    expect(screen.getAllByRole('button', { name: 'Stop controlling' })).toHaveLength(1)
    expect(stage.getByText(/You’re controlling/)).toBeInTheDocument()
    // The connection comes too: a frozen picture is explained where the person is looking.
    expect(stage.getByText('45 ms · 15 fps')).toBeInTheDocument()

    await userEvent.click(stage.getByRole('button', { name: 'Stop controlling' }))
    expect(actions.stopControl).toHaveBeenCalledOnce()

    // Esc, and the banner is back above the stage — still exactly once.
    act(() => fullscreen.escape())

    const stop = screen.getAllByRole('button', { name: 'Stop controlling' })
    expect(stop).toHaveLength(1)
    expect(document.querySelector('.stage')).not.toContainElement(stop[0])
  })

  it('leaves full screen when somebody knocks, so the host can answer', async () => {
    // A host watching a computer in the session share its screen.
    room.session = { ...session, isHost: true, participants: [sharer, { ...viewer, isHost: true }], me: { ...viewer, isHost: true } }
    room.live = watching()
    const view = renderRoom()

    await userEvent.click(screen.getByRole('button', { name: 'Full screen' }))
    expect(fullscreen.element).not.toBeNull()

    room.session = { ...room.session, waiting: [knocking] }
    view.rerender(roomTree())

    expect(fullscreen.element).toBeNull()
    expect(screen.getByRole('alertdialog', { name: 'Someone wants to join' })).toBeInTheDocument()
  })

  it('leaves full screen when the request for control is declined, rather than leave the viewer waiting', async () => {
    room.session = { ...session, participants: [sharer, viewer], me: { ...viewer, controlState: 'REQUESTED' } }
    room.live = watching()
    const view = renderRoom()

    await userEvent.click(screen.getByRole('button', { name: 'Full screen' }))
    expect(fullscreen.element).not.toBeNull()

    room.session = { ...room.session, me: { ...viewer, controlState: 'DENIED' } }
    view.rerender(roomTree())

    expect(fullscreen.element).toBeNull()
  })
})
