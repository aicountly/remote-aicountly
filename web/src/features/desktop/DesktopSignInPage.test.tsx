import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'

import DesktopSignInPage from './DesktopSignInPage'
import { RemoteApiError } from '../../services/api/client'
import type { Bootstrap } from '../../types/remote'

const { useRemoteMock, confirmMock, denyMock } = vi.hoisted(() => ({
  useRemoteMock: vi.fn(),
  confirmMock: vi.fn(),
  denyMock: vi.fn(),
}))

vi.mock('../../app/RemoteProvider', () => ({ useRemote: useRemoteMock }))
vi.mock('../../services/api/remote', () => ({
  confirmDesktopSignIn: confirmMock,
  denyDesktopSignIn: denyMock,
}))

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
  })

  it('asks the person to open the page from the computer when there is no code', () => {
    renderPage('/desktop-signin')

    expect(screen.getByText(/open it from there/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /confirm and sign in/i })).not.toBeInTheDocument()
  })

  it('shows the code grouped and uppercased, however it arrived in the URL', () => {
    renderPage('/desktop-signin?code=abcd1234')

    expect(screen.getByText('ABCD-1234')).toBeInTheDocument()
  })

  it('lets the person choose which organisation to register the device in', () => {
    renderPage('/desktop-signin?code=ABCD-1234', [
      northwind,
      { companyId: 482, name: 'Southwind', isCompanyAdmin: false, roleKey: 'MEMBER', branchId: null, financialYearId: null },
    ])

    expect(screen.getByRole('option', { name: 'Northwind' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Southwind' })).toBeInTheDocument()
  })

  it('explains there is nothing to register into when there are no companies', () => {
    renderPage('/desktop-signin?code=ABCD-1234', [])

    expect(screen.getByRole('button', { name: /confirm and sign in/i })).toBeDisabled()
    expect(screen.getByText(/allows registering a desktop device/i)).toBeInTheDocument()
  })

  it('confirms with the code and the chosen company, then says to go back to the computer', async () => {
    confirmMock.mockResolvedValue({ status: 'confirmed', deviceLabel: "Priya's laptop" })
    renderPage()

    await userEvent.click(screen.getByRole('button', { name: /confirm and sign in/i }))

    expect(confirmMock).toHaveBeenCalledWith('ABCD-1234', 481)
    await waitFor(() => expect(screen.getByText(/you're signed in/i)).toBeInTheDocument())
    expect(screen.getByText(/priya's laptop/i)).toBeInTheDocument()
  })

  it('shows the server’s message and stays on the form when confirming fails', async () => {
    confirmMock.mockRejectedValue(new RemoteApiError('SIGNIN_CODE_ALREADY_HANDLED', 'That code has already been used or denied.', 409))
    renderPage()

    await userEvent.click(screen.getByRole('button', { name: /confirm and sign in/i }))

    await waitFor(() =>
      expect(screen.getByText('That code has already been used or denied.')).toBeInTheDocument(),
    )
    expect(screen.getByRole('button', { name: /confirm and sign in/i })).toBeInTheDocument()
  })

  it('declines without registering anything', async () => {
    denyMock.mockResolvedValue({ status: 'denied' })
    renderPage()

    await userEvent.click(screen.getByRole('button', { name: /decline/i }))

    expect(denyMock).toHaveBeenCalledWith('ABCD-1234')
    await waitFor(() => expect(screen.getByText(/sign-in declined/i)).toBeInTheDocument())
    expect(confirmMock).not.toHaveBeenCalled()
  })
})
