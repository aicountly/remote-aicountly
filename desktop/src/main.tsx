import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

import App from './app/App'
import { IdentifyOverlay, identifyName } from './features/companion/IdentifyOverlay'
import './styles/tokens.css'
import './styles/app.css'

const root = document.getElementById('root')

// An Identify label, in a window of its own on one monitor, renders nothing
// else — and calls nothing.
const identify = identifyName(window.location.hash)

if (root) {
  createRoot(root).render(
    <StrictMode>{identify !== null ? <IdentifyOverlay name={identify} /> : <App />}</StrictMode>,
  )
}
