import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'

import { RemoteApiError } from '../../services/api/client'
import type { SupportRequest } from '../../types/remote'

/**
 * Answering a support request. Decline and Cancel were `.then(load)` with no
 * catch, so a refusal vanished; Accept's refusal was set and then cleared by the
 * re-read that followed it.
 */

const api = vi.hoisted(() => ({
  fetchSupportRequests: vi.fn(),
  acceptSupportRequest: vi.fn(),
  declineSupportRequest: vi.fn(),
  cancelSupportRequest: vi.fn(),
  createSupportRequest: vi.fn(),
}))
const remote = vi.hoisted(() => ({ isSupportAgent: true }))

vi.mock('../../services/api/remote', () => api)
vi.mock('../../app/RemoteProvider', () => ({
  useRemote: () => ({
    bootstrap: { user: { isSupportAgent: remote.isSupportAgent } },
    policy: null,
    scopeType: 'PERSONAL',
    companyId: null,
    can: () => false,
  }),
}))

const { default: SupportPage } = await import('./SupportPage')

const request: SupportRequest = {
  uuid: 'req-1',
  status: 'PENDING',
  priority: 'NORMAL',
  requesterName: 'Asha Rao',
  companyId: null,
  companyName: null,
  sourceProduct: null,
  sourceProductLabel: null,
  sourceRoute: null,
  supportTicketId: null,
  issueSummary: null,
  requestedShareMode: 'SAFE_SHARE',
  sessionUuid: 'sess-1',
  sessionDisplayId: 'AR-10001',
  sessionStatus: 'WAITING',
  acceptedAt: null,
  expiresAt: null,
  createdAt: null,
}

const answered = new RemoteApiError('SUPPORT_REQUEST_CLOSED', 'That request has already been answered.', 409)

function renderPage() {
  api.fetchSupportRequests.mockResolvedValue({ data: [request], meta: {} })

  return render(
    <MemoryRouter>
      <SupportPage />
    </MemoryRouter>,
  )
}

describe('answering a support request', () => {
  afterEach(() => {
    vi.clearAllMocks()
    remote.isSupportAgent = true
  })

  it('says so when declining is refused', async () => {
    api.declineSupportRequest.mockRejectedValue(answered)
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: 'Decline' }))

    expect(await screen.findByText('That request has already been answered.')).toBeInTheDocument()
    expect(api.declineSupportRequest).toHaveBeenCalledWith('req-1')
  })

  it('says so when accepting is refused, after re-reading the queue', async () => {
    api.acceptSupportRequest.mockRejectedValue(answered)
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: 'Accept request' }))

    expect(await screen.findByText('That request has already been answered.')).toBeInTheDocument()
    expect(api.fetchSupportRequests).toHaveBeenCalledTimes(2)
  })

  it('says so when the customer cannot cancel', async () => {
    remote.isSupportAgent = false
    api.cancelSupportRequest.mockRejectedValue(
      new RemoteApiError('NETWORK_ERROR', 'We could not reach AICOUNTLY Remote. Check your connection.', 0),
    )
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: 'Cancel' }))

    expect(await screen.findByText('We could not reach AICOUNTLY Remote. Check your connection.')).toBeInTheDocument()
  })
})
