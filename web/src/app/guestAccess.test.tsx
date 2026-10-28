import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, createMemoryRouter } from 'react-router-dom'

/**
 * An external guest has no AICOUNTLY account, so the app runs without
 * RemoteProvider for them (§23). The session room reads that context for
 * everyone, so every guest invitation used to end on "Unexpected Application
 * Error! useRemote must be used inside <RemoteProvider>" the moment the link
 * was redeemed.
 */

const auth = vi.hoisted(() => ({ status: 'guest' as 'guest' | 'authenticated' }))

vi.mock('../auth/AuthProvider', () => ({
  useAuth: () => ({ status: auth.status, message: null, signIn: vi.fn(), signOut: vi.fn(), retry: vi.fn() }),
}))

// What App renders for a guest: the room route, reading the Remote context exactly as SessionRoomPage does.
vi.mock('./router', async () => {
  const { useRemote } = await import('./RemoteProvider')

  function RoomProbe() {
    const { policy, bootstrap, can, capabilities } = useRemote()

    return (
      <p>
        room for a guest: policy {String(policy)}, bootstrap {String(bootstrap)}, may share{' '}
        {String(can('remote.screen.share'))}, capabilities {capabilities ? 'read' : 'missing'}
      </p>
    )
  }

  return {
    router: createMemoryRouter([{ path: '/room/:uuid', element: <RoomProbe /> }], {
      initialEntries: ['/room/sess-1'],
    }),
  }
})

const { default: App } = await import('../App')
const { default: AppShell } = await import('./AppShell')
const { GuestRemoteProvider, useRemote } = await import('./RemoteProvider')

describe('an external guest', () => {
  it('reaches the session room instead of an error page', () => {
    auth.status = 'guest'
    render(<App />)

    expect(
      screen.getByText(
        'room for a guest: policy null, bootstrap null, may share false, capabilities read',
      ),
    ).toBeInTheDocument()
  })

  it('is granted nothing by the stand-in context', () => {
    function Probe() {
      const remote = useRemote()

      return (
        <p>
          {[
            remote.status,
            remote.scopeType,
            String(remote.companyId),
            String(remote.can('remote.access')),
            String(remote.can('remote.control.request')),
          ].join(' ')}
        </p>
      )
    }

    render(
      <GuestRemoteProvider>
        <Probe />
      </GuestRemoteProvider>,
    )

    expect(screen.getByText('ready PERSONAL null false false')).toBeInTheDocument()
  })

  it('who leaves the room is not shown company navigation', () => {
    auth.status = 'guest'
    render(
      <MemoryRouter initialEntries={['/']}>
        <GuestRemoteProvider>
          <AppShell />
        </GuestRemoteProvider>
      </MemoryRouter>,
    )

    expect(screen.getByRole('heading', { name: 'You are not in a Remote session' })).toBeInTheDocument()
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument()
    expect(screen.queryByText('Dashboard')).not.toBeInTheDocument()
  })
})
