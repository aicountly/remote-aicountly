/**
 * The analytics consent banner: asked only where analytics runs and only until
 * the person answers, it never blocks the page or traps focus, and its two
 * buttons write the shared `aic_consent` cookie. "Analytics preferences" asks
 * again.
 */

import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const GA_ID = 'G-TEST000000'
const QUESTION =
  'AICOUNTLY uses privacy-friendly analytics to improve the product — no ads, and nothing that identifies you. Allow analytics cookies?'

async function load(measurementId = GA_ID) {
  vi.resetModules()
  vi.stubEnv('VITE_GA4_SAAS_REMOTE_MEASUREMENT_ID', measurementId)
  vi.stubEnv('VITE_GA4_MEASUREMENT_ID', '')
  const banner = await import('./AnalyticsConsent')
  const analytics = await import('../utils/analytics')

  return { Banner: banner.default, PreferencesButton: banner.AnalyticsPreferencesButton, analytics }
}

function banner() {
  return screen.queryByRole('region', { name: 'Analytics cookies' })
}

/** A data router as far as analytics cares: a starting location and a subscription. */
function fakeRouter(pathname: string) {
  let listener: (state: { location: { pathname: string } }) => void = () => {}
  return {
    state: { location: { pathname } },
    subscribe: (next: typeof listener) => {
      listener = next
      return () => {}
    },
    navigate: (to: string) => {
      window.history.replaceState(null, '', to)
      listener({ location: { pathname: to } })
    },
  }
}

beforeEach(() => {
  Reflect.deleteProperty(window, 'dataLayer')
  Reflect.deleteProperty(window, 'gtag')
  window.history.replaceState(null, '', '/')
  vi.spyOn(document.head, 'appendChild').mockImplementation(<T extends Node>(node: T) => node)
})

afterEach(() => {
  Reflect.deleteProperty(window, `ga-disable-${GA_ID}`)
  document.cookie = 'aic_consent=; Max-Age=0; Path=/'
  vi.unstubAllEnvs()
  vi.restoreAllMocks()
})

describe('the analytics consent banner', () => {
  it('asks when analytics is on and nobody has chosen, as a named region that takes no focus', async () => {
    const { Banner } = await load()
    render(<Banner />)

    const region = banner()
    expect(region).toBeInTheDocument()
    expect(region).toHaveClass('consent')
    expect(region).not.toHaveClass('consent--room')
    expect(region).toHaveTextContent(QUESTION)
    expect(screen.getByRole('button', { name: 'Allow' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'No thanks' })).toBeInTheDocument()
    const policy = screen.getByRole('link', { name: /^Privacy policy/ })
    expect(policy).toHaveAttribute('href', 'https://aicountly.com/privacy-policy')
    expect(policy).toHaveAttribute('target', '_blank')
    expect(region).not.toContainElement(document.activeElement as HTMLElement | null)
  })

  it('is reachable by keyboard, and lets focus go on past it', async () => {
    const { Banner } = await load()
    render(
      <>
        <Banner />
        <button type="button">After</button>
      </>,
    )

    await userEvent.tab()
    expect(screen.getByRole('button', { name: 'Allow' })).toHaveFocus()
    await userEvent.tab()
    expect(screen.getByRole('button', { name: 'No thanks' })).toHaveFocus()
    await userEvent.tab()
    expect(screen.getByRole('link', { name: /^Privacy policy/ })).toHaveFocus()
    await userEvent.tab()
    expect(screen.getByRole('button', { name: 'After' })).toHaveFocus()
  })

  it('"Allow" stores analytics-granted and puts the banner away', async () => {
    const { Banner, analytics } = await load()
    render(<Banner />)

    await userEvent.click(screen.getByRole('button', { name: 'Allow' }))

    expect(document.cookie).toContain('aic_consent=analytics-granted')
    expect(analytics.analyticsChoice()).toBe('analytics-granted')
    expect(banner()).not.toBeInTheDocument()
  })

  it('"No thanks" stores analytics-denied, switches GA off at once and puts the banner away', async () => {
    const { Banner } = await load()
    render(<Banner />)

    await userEvent.click(screen.getByRole('button', { name: 'No thanks' }))

    expect(document.cookie).toContain('aic_consent=analytics-denied')
    expect((window as unknown as Record<string, unknown>)[`ga-disable-${GA_ID}`]).toBe(true)
    expect(banner()).not.toBeInTheDocument()
  })

  it('is not shown in a build where analytics is off', async () => {
    const { Banner } = await load('')
    render(<Banner />)

    expect(banner()).not.toBeInTheDocument()
  })

  it('is not shown once there is a choice, made here or in another AICOUNTLY app', async () => {
    document.cookie = 'aic_consent=analytics-denied; Path=/'
    const { Banner } = await load()
    render(<Banner />)

    expect(banner()).not.toBeInTheDocument()
  })

  it('is never shown on a page analytics skips, and appears on the next page it measures', async () => {
    window.history.replaceState(null, '', '/join/DUMMYINVITE')
    const { Banner, analytics } = await load()
    const router = fakeRouter('/join/DUMMYINVITE')
    analytics.trackRouterPageViews(router)
    render(<Banner />)

    expect(banner()).not.toBeInTheDocument()

    act(() => router.navigate('/auth/callback?sso_code=DUMMYCODE'))
    expect(banner()).not.toBeInTheDocument()

    // A guest admitted to the room: above the session toolbar, not over it.
    act(() => router.navigate('/room/0f8fad5b-d9cb-469f-a165-70867728950e'))
    expect(banner()).toBeInTheDocument()
    expect(banner()).toHaveClass('consent--room')
  })
})

describe('"Analytics preferences"', () => {
  it('asks again after a choice, and the new answer replaces the old one', async () => {
    document.cookie = 'aic_consent=analytics-granted; Path=/'
    const { Banner, PreferencesButton } = await load()
    render(
      <>
        <PreferencesButton />
        <Banner />
      </>,
    )
    expect(banner()).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Analytics preferences' }))
    expect(banner()).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'No thanks' }))
    expect(document.cookie).toContain('aic_consent=analytics-denied')
    expect(banner()).not.toBeInTheDocument()
  })

  it('runs what it is given first, so a phone navigation drawer can close', async () => {
    const { PreferencesButton } = await load()
    const onOpen = vi.fn()
    render(<PreferencesButton onOpen={onOpen} />)

    await userEvent.click(screen.getByRole('button', { name: 'Analytics preferences' }))

    expect(onOpen).toHaveBeenCalledTimes(1)
  })

  it('is not offered where the banner cannot appear', async () => {
    const off = await load('')
    const { unmount } = render(<off.PreferencesButton />)
    expect(screen.queryByRole('button', { name: 'Analytics preferences' })).not.toBeInTheDocument()
    unmount()

    window.history.replaceState(null, '', '/desktop-signin?code=ABCD-EFGH')
    const on = await load()
    render(<on.PreferencesButton />)
    expect(screen.queryByRole('button', { name: 'Analytics preferences' })).not.toBeInTheDocument()
  })
})
