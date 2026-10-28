import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'

/**
 * Opening an invitation link.
 *
 * "Invite an AICOUNTLY colleague" creates a link the API redeems only for a
 * signed-in AICOUNTLY person — and the page always redeemed anonymously, so a
 * colleague who was signed in was told "Sign in to AICOUNTLY to join this
 * Remote session" and the link never worked.
 */

const auth = vi.hoisted(() => ({ status: 'authenticated' as 'authenticated' | 'guest', signIn: vi.fn() }))

vi.mock('../../auth/AuthProvider', () => ({
  useAuth: () => ({ status: auth.status, message: null, signIn: auth.signIn, signOut: vi.fn(), retry: vi.fn() }),
}))

vi.mock('../../auth/portal', async () => {
  const actual = await vi.importActual<typeof import('../../auth/portal')>('../../auth/portal')
  return { ...actual, ensureSesKey: vi.fn(async () => 'ses-colleague') }
})

const { clearAllTokens, setAuthToken } = await import('../../auth/tokens')
const { setGuestToken } = await import('../../services/api/client')
const { redeemInvitation } = await import('../../services/api/remote')
const { default: GuestJoinPage } = await import('./GuestJoinPage')

function answer(status: number, body: unknown) {
  return vi.fn(async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }))
}

function sentAuthorization(fetchMock: ReturnType<typeof vi.fn>): string | undefined {
  const init = (fetchMock.mock.calls[0] as unknown as [string, RequestInit])[1]

  return (init.headers as Record<string, string>).Authorization
}

const redeemed = {
  data: {
    session: { uuid: 'sess-1' },
    participant: { uuid: 'p-1', status: 'REQUESTED' },
  },
}

describe('redeeming an invitation link', () => {
  beforeEach(() => {
    clearAllTokens()
    setGuestToken(null)
  })
  afterEach(() => {
    vi.unstubAllGlobals()
    clearAllTokens()
    setGuestToken(null)
  })

  it('redeems as the signed-in person, so a colleague link works', async () => {
    setAuthToken('auth-colleague')
    const fetchMock = answer(201, redeemed)
    vi.stubGlobal('fetch', fetchMock)

    await redeemInvitation('secret-link')

    expect(sentAuthorization(fetchMock)).toBe('Bearer ses-colleague')
  })

  it('redeems anonymously for somebody with no AICOUNTLY sign-in (an external guest)', async () => {
    const fetchMock = answer(201, { data: { ...redeemed.data, guestToken: 'guest.abc' } })
    vi.stubGlobal('fetch', fetchMock)

    await redeemInvitation('secret-link', 'Gita')

    expect(sentAuthorization(fetchMock)).toBeUndefined()
  })
})

describe('the invitation page', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    auth.signIn.mockReset()
    clearAllTokens()
  })

  it('offers a way to sign in when a colleague link is opened without one', async () => {
    auth.status = 'guest'
    vi.stubGlobal(
      'fetch',
      answer(401, { error: { code: 'UNAUTHENTICATED', message: 'Sign in to AICOUNTLY to join this Remote session.' } }),
    )

    render(
      <MemoryRouter initialEntries={['/join/secret-link']}>
        <Routes>
          <Route path="/join/:token" element={<GuestJoinPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))

    expect(await screen.findByText('Sign in to join this session')).toBeInTheDocument()
    expect(screen.queryByText('Your session has expired')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Sign in to AICOUNTLY' }))
    expect(auth.signIn).toHaveBeenCalledTimes(1)
  })
})
