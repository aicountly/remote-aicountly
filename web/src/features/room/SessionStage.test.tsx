import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'

import SessionStage from './SessionStage'
import type { Participant, SessionDetail } from '../../types/remote'

/**
 * The stage before any picture arrives. The host's own row can still say
 * "sharing" after their page lost the stream (a reload, the browser's own
 * Stop), and the stage used to tell them to wait for their own screen.
 */

function participant(overrides: Partial<Participant>): Participant {
  return {
    uuid: 'p-host',
    displayName: 'Asha',
    role: 'SHARER',
    clientType: 'BROWSER',
    status: 'JOINED',
    isHost: true,
    isSharing: false,
    microphoneEnabled: false,
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
    status: 'ACTIVE',
    requestedShareMode: 'BROWSER_TAB',
    actualDisplaySurface: 'browser',
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
    participants: [],
    isHost: false,
    me: null,
    ...overrides,
  }
}

function renderStage(detail: SessionDetail) {
  return render(
    <SessionStage
      session={detail}
      live={null}
      pointerEnabled={false}
      annotationTool="none"
      annotationColour="#000"
      onPointerMove={() => {}}
      onAnnotation={() => {}}
      authorName="Someone"
    />,
  )
}

describe('the stage with no picture yet', () => {
  it('never tells the host to wait for their own screen', () => {
    const host = participant({ isSharing: true })
    renderStage(session({ participants: [host], me: host, isHost: true }))

    expect(screen.queryByText(/Waiting for Asha/)).not.toBeInTheDocument()
    expect(screen.getByText('No screen is being shared yet')).toBeInTheDocument()
    expect(screen.getByText(/Choose Share to pick what to show/)).toBeInTheDocument()
  })

  it('tells a viewer whose screen is on its way', () => {
    const host = participant({ isSharing: true })
    const viewer = participant({ uuid: 'p-viewer', displayName: 'Bala', role: 'VIEWER', isHost: false })
    renderStage(session({ participants: [host, viewer], me: viewer }))

    expect(screen.getByText('Waiting for Asha’s screen')).toBeInTheDocument()
  })
})
