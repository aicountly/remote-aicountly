import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'

import { GuestRemoteProvider } from '../../app/RemoteProvider'
import { RemoteApiError } from '../../services/api/client'
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
}))
/** The session a test renders, when it is not the default one below. */
const room = vi.hoisted(() => ({ session: null as SessionDetail | null }))

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
    live: null,
    shareIntent: { phase: 'idle' },
    control: null,
    controlBusy: false,
    controlNotice: null,
    controllableHost: null,
    isControlling: false,
    controlPaused: null,
    actions,
  }),
}))

const { default: SessionRoomPage } = await import('./SessionRoomPage')

function renderRoom() {
  return render(
    <MemoryRouter initialEntries={['/room/sess-1']}>
      <GuestRemoteProvider>
        <Routes>
          <Route path="/room/:uuid" element={<SessionRoomPage />} />
          <Route path="*" element={<p>left the room</p>} />
        </Routes>
      </GuestRemoteProvider>
    </MemoryRouter>,
  )
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

  it('says so when declining somebody from the participant list failed', async () => {
    room.session = { ...session, isHost: true, participants: [knocking] }
    actions.deny.mockRejectedValue(new Error('socket hang up'))
    renderRoom()

    await userEvent.click(screen.getByRole('button', { name: 'Decline' }))

    expect(actions.deny).toHaveBeenCalledWith('p-guest')
    expect(await screen.findByText('Declining them did not go through. Try again.')).toBeInTheDocument()
  })
})
