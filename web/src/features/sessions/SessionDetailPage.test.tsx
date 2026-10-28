import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'

import type { Participant, SessionDetail, SessionEvent } from '../../types/remote'

/**
 * A session after the fact, in words. The page used to print the codes —
 * "ENDED", "PERSONAL", "ASSISTANCE", "ENDED_BY_USER", "SHARER" — and "—" for the
 * host and for what was shared, even when a tab had been shared all along.
 */

const api = vi.hoisted(() => ({
  fetchSession: vi.fn(),
  fetchSessionEvents: vi.fn(),
}))

vi.mock('../../services/api/remote', () => api)
vi.mock('../../app/RemoteProvider', () => ({ useRemote: () => ({ can: () => false }) }))

const { default: SessionDetailPage } = await import('./SessionDetailPage')

function participant(overrides: Partial<Participant>): Participant {
  return {
    uuid: 'p-host',
    displayName: 'Asha Rao',
    role: 'SHARER',
    clientType: 'BROWSER',
    status: 'LEFT',
    isHost: true,
    isSharing: false,
    microphoneEnabled: false,
    joinedAt: null,
    leftAt: null,
    ...overrides,
  } as Participant
}

function session(overrides: Partial<SessionDetail>): SessionDetail {
  return {
    uuid: 'sess-1',
    displayId: 'AR-10001',
    sessionCode: null,
    scopeType: 'PERSONAL',
    companyId: null,
    companyName: null,
    branchId: null,
    financialYearId: null,
    sessionType: 'ASSISTANCE',
    status: 'ENDED',
    requestedShareMode: 'BROWSER_TAB',
    actualDisplaySurface: null,
    sourceProduct: null,
    sourceProductLabel: null,
    sourceRoute: null,
    supportTicketId: null,
    issueSummary: null,
    ownerName: null,
    capabilities: {} as SessionDetail['capabilities'],
    maxDurationMinutes: 60,
    startedAt: null,
    endedAt: null,
    expiresAt: null,
    createdAt: null,
    durationSeconds: null,
    endReason: 'ENDED_BY_USER',
    participants: [
      participant({}),
      participant({ uuid: 'p-viewer', displayName: 'Bala', role: 'VIEWER', isHost: false }),
    ],
    isHost: true,
    me: null,
    ...overrides,
  }
}

function event(eventType: string, overrides: Partial<SessionEvent> = {}): SessionEvent {
  return { eventType, actorType: 'USER', actorName: null, metadata: {}, occurredAt: null, ...overrides }
}

/** The value printed against a label in the Details card. */
function fact(label: string): string | null | undefined {
  return screen.getByText(label, { selector: 'dt' }).nextElementSibling?.textContent
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/sessions/sess-1']}>
      <Routes>
        <Route path="/sessions/:uuid" element={<SessionDetailPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('the session detail page', () => {
  afterEach(() => {
    api.fetchSession.mockReset()
    api.fetchSessionEvents.mockReset()
  })

  it('says what happened in words, not codes', async () => {
    api.fetchSession.mockResolvedValue(session({}))
    api.fetchSessionEvents.mockResolvedValue([
      event('SCREEN_SHARE_STARTED', { metadata: { displaySurface: 'browser' } }),
      event('SCREEN_SHARE_STOPPED'),
      event('SESSION_ENDED', { actorName: 'Asha Rao', metadata: { reason: 'ENDED_BY_USER' } }),
    ])
    renderPage()

    await screen.findByText('Details')
    expect(await screen.findByText('Asha Rao ended it')).toBeInTheDocument()

    expect(fact('Status')).toBe('Completed')
    expect(fact('Scope')).toBe('Personal')
    expect(fact('Session type')).toBe('Assistance')
    // Named from the host's participant row when the API sends no owner name.
    expect(fact('Host')).toBe('Asha Rao')
    // The row only holds what is shared *now*; what was shared is in the timeline.
    expect(fact('Surface shared')).toBe('Browser tab')
    expect(fact('Ended because')).toBe('Asha Rao ended it')

    expect(screen.getByRole('cell', { name: 'Host' })).toBeInTheDocument()
    expect(screen.getByRole('cell', { name: 'Viewer' })).toBeInTheDocument()

    for (const code of ['ENDED', 'PERSONAL', 'ASSISTANCE', 'ENDED_BY_USER', 'SHARER', 'VIEWER']) {
      expect(screen.queryByText(code)).not.toBeInTheDocument()
    }
  })

  it('says an expired session ran out of time, and that nothing was shared', async () => {
    api.fetchSession.mockResolvedValue(session({ status: 'EXPIRED', endReason: 'EXPIRED', ownerName: 'Asha Rao' }))
    api.fetchSessionEvents.mockResolvedValue([event('SESSION_EXPIRED', { actorType: 'SYSTEM' })])
    renderPage()

    expect(await screen.findByText('It reached its time limit')).toBeInTheDocument()
    expect(fact('Status')).toBe('Expired')
    expect(fact('Surface shared')).toBe('Nothing was shared')
  })

  it('does not claim nothing was shared when the timeline could not be loaded', async () => {
    api.fetchSession.mockResolvedValue(session({ ownerName: 'Asha Rao' }))
    api.fetchSessionEvents.mockRejectedValue(new Error('offline'))
    renderPage()

    await screen.findByText('Details')
    expect(fact('Surface shared')).toBe('—')
    expect(fact('Ended because')).toBe('Someone in the session ended it')
  })
})
