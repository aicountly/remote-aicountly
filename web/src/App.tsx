import { RouterProvider } from 'react-router-dom'

import { useAuth } from './auth/AuthProvider'
import { GuestRemoteProvider, RemoteProvider } from './app/RemoteProvider'
import { router } from './app/router'
import SignIn from './pages/SignIn'
import AicountlyLogo from './components/brand/AicountlyLogo'

/**
 * The application root.
 *
 * Three states, and the third is the one worth noting: an external guest has no
 * AICOUNTLY account, so they get the router without {@link RemoteProvider} —
 * there is no bootstrap to fetch, no organisation to select, and no navigation
 * they should see (§23). {@link GuestRemoteProvider} stands in for it, granting
 * nothing, because the session room reads that context for everyone.
 */
export default function App() {
  const { status } = useAuth()

  if (status === 'signed-out') {
    return <SignIn />
  }

  if (status === 'guest') {
    return (
      <GuestRemoteProvider>
        <RouterProvider router={router} />
      </GuestRemoteProvider>
    )
  }

  if (status === 'authenticated') {
    return (
      <RemoteProvider>
        <RouterProvider router={router} />
      </RemoteProvider>
    )
  }

  return (
    <div className="boot">
      <div className="boot__panel">
        <AicountlyLogo />
        <p className="boot__message">Signing you in…</p>
      </div>
    </div>
  )
}
