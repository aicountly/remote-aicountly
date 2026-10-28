import { RouterProvider } from 'react-router-dom'

import { useAuth } from './auth/AuthProvider'
import type { AuthStatus } from './auth/AuthProvider'
import { GuestRemoteProvider, RemoteProvider } from './app/RemoteProvider'
import { router } from './app/router'
import SignIn from './pages/SignIn'
import AicountlyLogo from './components/brand/AicountlyLogo'
import AnalyticsConsentBanner from './components/AnalyticsConsent'

/**
 * The application root.
 *
 * Three states, and the third is the one worth noting: an external guest has no
 * AICOUNTLY account, so they get the router without {@link RemoteProvider} —
 * there is no bootstrap to fetch, no organisation to select, and no navigation
 * they should see (§23). {@link GuestRemoteProvider} stands in for it, granting
 * nothing, because the session room reads that context for everyone.
 *
 * The analytics consent banner sits beside whichever of them is showing, so it
 * is asked once whatever page somebody lands on; it decides for itself whether
 * that page is one analytics runs on. Not while signing in: that screen is
 * usually on its way to the portal.
 */
export default function App() {
  const { status } = useAuth()

  if (status === 'loading') {
    return (
      <div className="boot">
        <div className="boot__panel">
          <AicountlyLogo />
          <p className="boot__message">Signing you in…</p>
        </div>
      </div>
    )
  }

  return (
    <>
      <AppContent status={status} />
      <AnalyticsConsentBanner />
    </>
  )
}

function AppContent({ status }: { status: Exclude<AuthStatus, 'loading'> }) {
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

  return (
    <RemoteProvider>
      <RouterProvider router={router} />
    </RemoteProvider>
  )
}
