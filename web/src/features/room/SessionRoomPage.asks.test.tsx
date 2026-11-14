import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'

import type { Participant, SessionControlState, SessionDetail } from '../../types/remote'

/**
 * What the room does about something that is waiting on somebody.
 *
 *   * A request to control this person's computer was a "1" on a toolbar button, in a tab they are
 *     very often not looking at. It is a strip above the picture, the Control panel opens with the
 *     question in it, and the tab's title says it.
 *   * A helper's own request was said only inside a side panel: no way to take it back, and a "no"
 *     from the other end was silent.
 *   * A session ended at its time limit without a word of warning.
 */

const actions = vi.hoisted(() => ({
  end: vi.fn(),
  leave: vi.fn(async () => undefined),
  pause: vi.fn(),
  resume: vi.fn(),
  approve: vi.fn(),
  deny: vi.fn(),
  stopControl: vi.fn(),
  askForControl: vi.fn(async () => undefined),
  cancelControlRequest: vi.fn(async () => undefined),
  allowControl: vi.fn(),
  refuseControl: vi.fn(),
  dismissControlNotice: vi.fn(),
}))

const room = vi.hoisted(() => ({
  session: null as SessionDetail | null,
  control: null as SessionControlState | null,
  myControlState: null as string | null,
  controlNotice: null as string | null,
}))

const person = (overrides: Partial<Participant>): Participant =>
  ({
    uuid: 'p-host',
    displayName: 'Asha',
    role: 'SHARER',
    clientType: 'BROWSER',
    status: 'JOINED',
    isHost: true,
    isSharing: false,
    microphoneEnabled: false,
    controlState: 'NONE',
    ...overrides,
  }) as Participant

const asha = person({})
const bala = person({ uuid: 'p-helper', displayName: 'Bala', role: 'VIEWER', isHost: false })

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
  participants: [asha, bala],
  isHost: false,
  me: bala,
  waiting: [],
}

const controlState = (overrides: Partial<SessionControlState> = {}): SessionControlState => ({
  controllableHostUuid: 'p-host',
  controllerUuid: null,
  controllerName: null,
  clipboardEnabled: false,
  pendingRequests: [],
  allowRemoteControl: true,
  allowClipboardSync: true,
  allowDeviceReboot: false,
  restrictions: [],
  ...overrides,
})

vi.mock('./useRemoteSession', () => ({
  useRemoteSession: () => ({
    session: room.session ?? session,
    loading: false,
    error: null,
    ended: false,
    refused: false,
    messages: [],
    live: null,
    shareIntent: { phase: 'idle' },
    control: room.control,
    controlBusy: false,
    controlNotice: room.controlNotice,
    controllableHost: null,
    isControlling: false,
    controlPaused: null,
    secureDesktop: null,
    myControlState: room.myControlState ?? (room.session ?? session).me?.controlState ?? null,
    monitors: [],
    activeMonitorId: null,
    actions,
  }),
}))

// Signed in, and permitted everything: what is under test is what the room shows, not who may.
vi.mock('../../app/RemoteProvider', () => ({
  useRemote: () => ({
    status: 'ready',
    error: null,
    bootstrap: null,
    policy: null,
    scopeType: 'PERSONAL',
    companyId: null,
    capabilities: { screenCapture: true, microphone: true, dataChannel: true },
    switchScope: async () => undefined,
    refresh: async () => undefined,
    can: () => true,
  }),
  useDesktopAgentEnabled: () => true,
}))

const { default: SessionRoomPage, minutesUntil } = await import('./SessionRoomPage')

function renderRoom() {
  return render(
    <MemoryRouter initialEntries={['/room/sess-1']}>
      <Routes>
        <Route path="/room/:uuid" element={<SessionRoomPage />} />
        <Route path="*" element={<p>left the room</p>} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  document.title = 'AICOUNTLY Remote'
})

afterEach(() => {
  room.session = null
  room.control = null
  room.myControlState = null
  room.controlNotice = null
  for (const action of Object.values(actions)) action.mockClear()
})

describe('a request to control this person’s computer', () => {
  const asHost = () => {
    room.session = { ...session, isHost: true, me: asha }
    room.control = controlState({
      pendingRequests: [{ participantUuid: 'p-helper', displayName: 'Bala', requestedAt: null }],
    })
  }

  it('is said above the picture, in the tab’s title, and in the Control panel, which opens by itself', () => {
    asHost()
    const view = renderRoom()

    // The strip.
    expect(screen.getByRole('alert')).toHaveTextContent('Bala is asking to control this computer.')

    // The panel, opened without being asked, with the question and its two answers.
    expect(screen.getByRole('button', { name: 'Allow control' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Not now' })).toBeInTheDocument()

    // The title: what shows in the tab strip when this tab is not the one in front.
    expect(document.title).toBe('● Bala is asking to control this computer — AICOUNTLY Remote')

    // Answered: nothing is left saying so.
    room.control = controlState()
    view.rerender(
      <MemoryRouter initialEntries={['/room/sess-1']}>
        <Routes>
          <Route path="/room/:uuid" element={<SessionRoomPage />} />
        </Routes>
      </MemoryRouter>,
    )

    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(document.title).toBe('AICOUNTLY Remote')
  })

  it('offers to reopen the panel when it has been closed', async () => {
    asHost()
    renderRoom()

    // Closed by the person: the request is still there, and so is a way back to it.
    await userEvent.click(screen.getByRole('button', { name: /^Control/ }))
    expect(screen.queryByRole('button', { name: 'Allow control' })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Answer' }))
    expect(screen.getByRole('button', { name: 'Allow control' })).toBeInTheDocument()
  })

  it('is never this person’s own request, even when the session is theirs', () => {
    // Connected to a computer with nobody at it: the session is the helper's, and so is the request.
    room.session = { ...session, isHost: true, me: bala }
    room.control = controlState({
      pendingRequests: [{ participantUuid: 'p-helper', displayName: 'Bala', requestedAt: null }],
    })
    room.myControlState = 'REQUESTED'
    renderRoom()

    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Allow control' })).not.toBeInTheDocument()
    expect(document.title).toBe('AICOUNTLY Remote')
    // What they are told is that they are waiting.
    expect(screen.getByRole('button', { name: 'Cancel request' })).toBeInTheDocument()
  })

  it('is not shown to somebody who is not the one to answer it', () => {
    room.control = controlState({
      pendingRequests: [{ participantUuid: 'p-other', displayName: 'Chitra', requestedAt: null }],
    })
    renderRoom()

    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(document.title).toBe('AICOUNTLY Remote')
  })
})

describe('this person’s own request for control', () => {
  it('says who it is waiting on, and can be taken back', async () => {
    room.control = controlState({
      pendingRequests: [{ participantUuid: 'p-helper', displayName: 'Bala', requestedAt: null }],
    })
    room.myControlState = 'REQUESTED'
    renderRoom()

    // Named: "Waiting for Asha to answer…", not "the host".
    const strip = screen.getByText(/to answer your request to control their computer/)
    expect(strip).toHaveTextContent('Waiting for Asha to answer your request to control their computer.')

    await userEvent.click(screen.getByRole('button', { name: 'Cancel request' }))
    expect(actions.cancelControlRequest).toHaveBeenCalledTimes(1)
  })

  it('says when the answer was no, and offers to ask again', async () => {
    room.control = controlState()
    room.myControlState = 'DENIED'
    renderRoom()

    expect(screen.getByText(/said not now to your request/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Ask again' }))
    expect(actions.askForControl).toHaveBeenCalledTimes(1)
  })

  it('says why control stopped above the picture, not under the toolbar', () => {
    room.control = controlState()
    room.controlNotice = 'The person at the computer stopped remote control.'
    const { container } = renderRoom()

    const notice = screen.getByText('The person at the computer stopped remote control.')
    const stage = container.querySelector('.stage')
    const toolbar = container.querySelector('.room__toolbar')

    expect(stage).not.toBeNull()
    // Before the stage in the page, and so before the toolbar under it.
    expect(notice.compareDocumentPosition(stage as Element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(notice.compareDocumentPosition(toolbar as Element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
  })
})

describe('the session’s time limit', () => {
  it('is said in the room once five minutes or fewer are left', () => {
    room.session = { ...session, expiresAt: new Date(Date.now() + 3 * 60_000 - 5_000).toISOString() }
    renderRoom()

    expect(screen.getByText(/reaches its time limit in 3 minutes/)).toBeInTheDocument()
    expect(screen.getByText(/start a new session/)).toBeInTheDocument()
  })

  it('is not mentioned while there is plenty of time, or when the session has no limit', () => {
    room.session = { ...session, expiresAt: new Date(Date.now() + 40 * 60_000).toISOString() }
    const view = renderRoom()
    expect(screen.queryByText(/time limit/)).not.toBeInTheDocument()
    view.unmount()

    room.session = { ...session, expiresAt: null }
    renderRoom()
    expect(screen.queryByText(/time limit/)).not.toBeInTheDocument()
  })

  it('counts whole minutes up, so "1 minute" is never said with ninety seconds to go', () => {
    const now = Date.parse('2026-11-14T10:00:00Z')

    expect(minutesUntil('2026-11-14T10:30:00Z', now)).toBeNull()
    expect(minutesUntil('2026-11-14T10:05:01Z', now)).toBeNull()
    expect(minutesUntil('2026-11-14T10:05:00Z', now)).toBe(5)
    expect(minutesUntil('2026-11-14T10:01:30Z', now)).toBe(2)
    expect(minutesUntil('2026-11-14T10:00:20Z', now)).toBe(1)
    expect(minutesUntil('2026-11-14T09:59:00Z', now)).toBe(0)
    expect(minutesUntil(null, now)).toBeNull()
    expect(minutesUntil('not a date', now)).toBeNull()
  })
})
