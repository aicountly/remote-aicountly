import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'

import DesktopSignInPage from './DesktopSignInPage'
import { RemoteApiError } from '../../services/api/client'
import { setAuthToken } from '../../auth/tokens'
import type { Bootstrap } from '../../types/remote'

const { useRemoteMock, confirmMock, denyMock, pendingMock } = vi.hoisted(() => ({
  useRemoteMock: vi.fn(),
  confirmMock: vi.fn(),
  denyMock: vi.fn(),
  pendingMock: vi.fn(),
}))

vi.mock('../../app/RemoteProvider', () => ({ useRemote: useRemoteMock }))
vi.mock('../../services/api/remote', () => ({
  confirmDesktopSignIn: confirmMock,
  denyDesktopSignIn: denyMock,
  fetchPendingDesktopSignIn: pendingMock,
}))

/** What the server says about the machine that asked. */
const PENDING = {
  deviceLabel: 'PRIYA-DESK',
  operatingSystem: 'Windows 11 24H2',
  architecture: 'x86_64',
  requestedIp: '203.0.113.9',
  requestedAt: '2026-11-05T02:00:00Z',
  expiresAt: '2026-11-05T02:10:00Z',
}

/**
 * Confirming a desktop agent's sign-in code — the browser half of device-code
 * sign-in (docs/desktop/DEVICE_ENROLMENT.md).
 */

function bootstrapWith(companies: Bootstrap['companies']): Bootstrap {
  return {
    user: { uuid: 'u1', displayName: 'Priya', email: 'priya@example.test', isSupportAgent: false },
    companies,
    activeScope: { scopeType: 'PERSONAL', companyId: null },
    launchContext: null,
    policy: {} as Bootstrap['policy'],
    metrics: {} as Bootstrap['metrics'],
    recentSessions: [],
    features: {} as Bootstrap['features'],
    realtime: { signallingConfigured: true, relayAvailable: true },
  }
}

/** The confirm button only appears enabled once the server has said what is asking. */
async function confirmButton() {
  await screen.findByText('PRIYA-DESK')

  return screen.getByRole('button', { name: /this is my computer/i })
}

const northwind = { companyId: 481, name: 'Northwind', isCompanyAdmin: true, roleKey: 'ADMIN', branchId: null, financialYearId: null }

function renderPage(path = '/desktop-signin?code=abcd1234', companies = [northwind]) {
  useRemoteMock.mockReturnValue({ bootstrap: bootstrapWith(companies) })

  return render(
    <MemoryRouter initialEntries={[path]}>
      <DesktopSignInPage />
    </MemoryRouter>,
  )
}

describe('DesktopSignInPage', () => {
  beforeEach(() => {
    confirmMock.mockReset()
    denyMock.mockReset()
    pendingMock.mockReset()
    pendingMock.mockResolvedValue(PENDING)
  })

  it('asks the person to open the page from the computer when there is no code', () => {
    renderPage('/desktop-signin')

    expect(screen.getByText(/open it from there/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /this is my computer/i })).not.toBeInTheDocument()
  })

  it('shows the code grouped and uppercased, however it arrived in the URL', () => {
    renderPage('/desktop-signin?code=abcd1234')

    expect(screen.getByText('ABCD-1234')).toBeInTheDocument()
  })

  /**
   * The computer belongs to the person confirming it. There is nothing to
   * choose, which is the point: the picker that used to be here listed every
   * organisation somebody belonged to, including the ones that would refuse
   * the registration, and said so only after they had picked one.
   */
  it('asks no organisation, because a computer belongs to the person', async () => {
    renderPage('/desktop-signin?code=ABCD-1234', [
      northwind,
      { companyId: 482, name: 'Southwind', isCompanyAdmin: false, roleKey: 'MEMBER', branchId: null, financialYearId: null },
    ])

    expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
    expect(screen.queryByText(/register it in/i)).not.toBeInTheDocument()
    expect(await confirmButton()).toBeEnabled()
  })

  /** And belonging to no organisation at all is the ordinary case, not a wall. */
  it('confirms for somebody who belongs to no organisation', async () => {
    renderPage('/desktop-signin?code=ABCD-1234', [])

    expect(await confirmButton()).toBeEnabled()
    expect(screen.queryByText(/allows registering a desktop device/i)).not.toBeInTheDocument()
  })

  it('confirms with the code and no organisation, then says to go back to the computer', async () => {
    confirmMock.mockResolvedValue({ status: 'confirmed', deviceLabel: "Priya's laptop" })
    renderPage()

    await userEvent.click(await confirmButton())

    expect(confirmMock).toHaveBeenCalledWith('ABCD-1234', { portalToken: null })
    await waitFor(() => expect(screen.getByText(/you're signed in/i)).toBeInTheDocument())
    expect(screen.getByText(/priya's laptop/i)).toBeInTheDocument()
    expect(screen.getByText(/you can close this tab/i)).toBeInTheDocument()
  })

  /**
   * The page's own sign-in goes along, so the desktop application's
   * embedded portal is signed in as the same person (docs/desktop/PORTAL.md).
   */
  it('hands this page’s AICOUNTLY sign-in to the computer being confirmed', async () => {
    setAuthToken('aic.page-auth-token')
    confirmMock.mockResolvedValue({ status: 'confirmed', deviceLabel: 'PC-22' })
    renderPage()

    await userEvent.click(await confirmButton())

    expect(confirmMock).toHaveBeenCalledWith('ABCD-1234', { portalToken: 'aic.page-auth-token' })
    setAuthToken(null)
  })

  it('does not tell somebody inside the desktop application to close a tab', async () => {
    window.__AICOUNTLY_REMOTE_DESKTOP__ = { nonce: 'a'.repeat(32) }
    confirmMock.mockResolvedValue({ status: 'confirmed', deviceLabel: 'PC-22' })
    renderPage()

    await userEvent.click(await confirmButton())

    expect(await screen.findByText(/continues on its own/i)).toBeInTheDocument()
    expect(screen.queryByText(/close this tab/i)).not.toBeInTheDocument()
    delete window.__AICOUNTLY_REMOTE_DESKTOP__
  })

  it('shows the server’s message and stays on the form when confirming fails', async () => {
    confirmMock.mockRejectedValue(new RemoteApiError('SIGNIN_CODE_ALREADY_HANDLED', 'That code has already been used or denied.', 409))
    renderPage()

    await userEvent.click(await confirmButton())

    await waitFor(() =>
      expect(screen.getByText('That code has already been used or denied.')).toBeInTheDocument(),
    )
    expect(await confirmButton()).toBeInTheDocument()
  })

  it('declines without registering anything', async () => {
    denyMock.mockResolvedValue({ status: 'denied' })
    renderPage()

    await userEvent.click(screen.getByRole('button', { name: /decline/i }))

    expect(denyMock).toHaveBeenCalledWith('ABCD-1234')
    await waitFor(() => expect(screen.getByText(/sign-in declined/i)).toBeInTheDocument())
    expect(confirmMock).not.toHaveBeenCalled()
  })

  // The flow's one human check is "is this request mine?". The agent opens
  // verificationUriComplete, which carries the code already filled in, so whoever called
  // `start` chose the code this page shows — a stranger's link looks exactly like the
  // agent's. The machine's own description is what differs, so it has to be on screen
  // before there is a button to press.
  it('shows which machine is asking, before offering to confirm', async () => {
    renderPage()

    expect(await screen.findByText('PRIYA-DESK')).toBeTruthy()
    expect(screen.getByText(/Windows 11 24H2/)).toBeTruthy()
    expect(screen.getByText(/203\.0\.113\.9/)).toBeTruthy()
    expect(pendingMock).toHaveBeenCalledWith('ABCD-1234')
  })

  it('will not offer to confirm a request it could not read', async () => {
    pendingMock.mockRejectedValue(new RemoteApiError(404, 'NOT_FOUND', 'That code has expired or could not be found.'))
    renderPage()

    await waitFor(() => expect(screen.getByText(/could not be found/i)).toBeTruthy())
    const confirm = screen.getByRole('button', { name: /this is my computer/i })
    expect((confirm as HTMLButtonElement).disabled).toBe(true)

    await userEvent.click(confirm)
    expect(confirmMock).not.toHaveBeenCalled()
  })
})
