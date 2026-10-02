import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

import App from './App.tsx'
import { AuthProvider } from './auth/AuthProvider.tsx'
import { router } from './app/router'
import { initAnalytics, trackRouterPageViews } from './utils/analytics'
import { stashDesktopSignInReturnPath } from './features/desktop/returnPath'

import './styles/tokens.css'
import './styles/base.css'
import './styles/app.css'
import './styles/room.css'

// Before AuthProvider's redirect effect can ever run — see returnPath.ts.
stashDesktopSignInReturnPath(window.location)

// Page-view tracking via the router instance's subscription API: this fires
// on every navigation regardless of route nesting, including /room/:uuid and
// /join/:token, which deliberately sit outside AppShell (see app/router.tsx).
// Paths only — a query can be a desktop sign-in code — and the landing page
// too, which the subscription alone never reports.
initAnalytics()
trackRouterPageViews(router)

const rootElement = document.getElementById('root')
if (!rootElement) throw new Error('Root element #root not found')

createRoot(rootElement).render(
  <StrictMode>
    <AuthProvider>
      <App />
    </AuthProvider>
  </StrictMode>,
)
