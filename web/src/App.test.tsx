/**
 * The consent banner is mounted beside whatever the app is showing once
 * sign-in has settled — not on the "Signing you in…" screen, which is usually
 * on its way to the portal.
 */

import { render, screen } from '@testing-library/react'
import { afterAll, afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { AuthStatus } from './auth/AuthProvider'

const auth = vi.hoisted(() => ({ status: 'signed-out' as AuthStatus }))

vi.mock('./auth/AuthProvider', () => ({
  useAuth: () => ({ status: auth.status, message: null, signIn: () => {}, signOut: () => {}, retry: () => {} }),
}))
vi.mock('./pages/SignIn', () => ({ default: () => <main>Sign in with AICOUNTLY</main> }))

// A build with analytics on: the measurement id is read once, when the analytics module loads.
vi.stubEnv('VITE_GA4_SAAS_REMOTE_MEASUREMENT_ID', 'G-TEST000000')
const { default: App } = await import('./App')

function renderApp(status: AuthStatus) {
  auth.status = status

  return render(<App />)
}

beforeEach(() => {
  window.history.replaceState(null, '', '/')
})

afterEach(() => {
  document.cookie = 'aic_consent=; Max-Age=0; Path=/'
})

afterAll(() => {
  vi.unstubAllEnvs()
})

describe('App', () => {
  it('asks about analytics beside the signed-out screen', () => {
    renderApp('signed-out')

    expect(screen.getByText('Sign in with AICOUNTLY')).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Analytics cookies' })).toBeInTheDocument()
  })

  it('does not ask while signing in', () => {
    renderApp('loading')

    expect(screen.getByText('Signing you in…')).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Analytics cookies' })).not.toBeInTheDocument()
  })
})
