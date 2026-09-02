import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

import App from './App.tsx'
import { AuthProvider } from './auth/AuthProvider.tsx'
import { router } from './app/router'
import { initAnalytics, trackPageView } from './utils/analytics'

import './styles/tokens.css'
import './styles/base.css'
import './styles/app.css'
import './styles/room.css'

// Page-view tracking via the router instance's subscription API: this fires
// on every navigation regardless of route nesting, including /room/:uuid and
// /join/:token, which deliberately sit outside AppShell (see app/router.tsx).
initAnalytics()
let lastTrackedPath: string | null = null
router.subscribe((state) => {
  const path = state.location.pathname + state.location.search
  if (path !== lastTrackedPath) {
    lastTrackedPath = path
    trackPageView(path)
  }
})

const rootElement = document.getElementById('root')
if (!rootElement) throw new Error('Root element #root not found')

createRoot(rootElement).render(
  <StrictMode>
    <AuthProvider>
      <App />
    </AuthProvider>
  </StrictMode>,
)
