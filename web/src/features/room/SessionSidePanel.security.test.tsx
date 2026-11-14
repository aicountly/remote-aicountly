import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'

import type { Participant, SessionDetail } from '../../types/remote'
import { SecurityPanel } from './SessionSidePanel'

/**
 * The Security panel says what protects the session to the person reading it.
 *
 * Every line was written to the person sharing — "You chose what to share", "Nobody receives
 * your screen until you allow them" — and was shown, word for word, to the helper looking at
 * somebody else's screen, who chose nothing and admits nobody.
 */

const person = (overrides: Partial<Participant>): Participant =>
  ({
    uuid: 'p-host',
    displayName: 'Asha',
    role: 'SHARER',
    clientType: 'BROWSER',
    status: 'JOINED',
    isHost: true,
    isSharing: true,
    microphoneEnabled: false,
    controlState: 'NONE',
    ...overrides,
  }) as Participant

const asha = person({})
const bala = person({ uuid: 'p-helper', displayName: 'Bala', role: 'VIEWER', isHost: false, isSharing: false })

const session = (overrides: Partial<SessionDetail>): SessionDetail =>
  ({
    uuid: 'sess-1',
    displayId: 'AR-10001',
    scopeType: 'PERSONAL',
    companyId: null,
    companyName: null,
    status: 'ACTIVE',
    actualDisplaySurface: 'monitor',
    participants: [asha, bala],
    isHost: true,
    me: asha,
    waiting: [],
    ...overrides,
  }) as SessionDetail

describe('the Security panel', () => {
  it('speaks to the person sharing about their own screen', () => {
    render(<SecurityPanel session={session({})} live={null} />)

    expect(screen.getByText('You chose what to share, and you can stop at any moment.')).toBeInTheDocument()
    expect(screen.getByText('Nobody receives your screen until you allow them into the session.')).toBeInTheDocument()
    expect(screen.getByText(/Your browser confirmed you are sharing/)).toBeInTheDocument()
    expect(screen.getByText(/The contents of your screen are not\./)).toBeInTheDocument()
  })

  it('speaks to a helper about somebody else’s screen', () => {
    render(<SecurityPanel session={session({ isHost: false, me: bala })} live={null} />)

    expect(screen.getByText(/The person sharing chose what to show, and can stop at any moment/)).toBeInTheDocument()
    expect(screen.getByText('Nobody joins this session until the host allows them in.')).toBeInTheDocument()
    expect(screen.getByText(/The sharer’s browser confirmed what is being shared/)).toBeInTheDocument()
    expect(screen.getByText(/The contents of the screen are not\./)).toBeInTheDocument()

    // Nothing left that is addressed to somebody else.
    expect(screen.queryByText(/You chose what to share/)).not.toBeInTheDocument()
    expect(screen.queryByText(/your screen/)).not.toBeInTheDocument()
  })

  it('does not call a personal account an organisation', () => {
    render(<SecurityPanel session={session({ actualDisplaySurface: null })} live={null} />)

    expect(screen.getByText('Account limits apply')).toBeInTheDocument()
    expect(screen.queryByText(/organisation/i)).not.toBeInTheDocument()
  })

  it('names the organisation whose policy applies', () => {
    render(<SecurityPanel session={session({ scopeType: 'COMPANY', companyId: 481, companyName: 'Verma & Co' })} live={null} />)

    expect(screen.getByText('Company policy protected')).toBeInTheDocument()
    expect(screen.getByText(/Verma & Co controls which sharing options are available/)).toBeInTheDocument()
  })
})
