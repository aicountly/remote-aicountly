import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * The portal is a native layer over this window, so what matters here is
 * what the component tells the Rust side: which page, where, and when to get
 * out of the way.
 */

const bridge = vi.hoisted(() => ({
  portalShow: vi.fn<(path: string | null, bounds: unknown) => Promise<void>>(),
  portalSetBounds: vi.fn<(bounds: unknown) => Promise<void>>(),
  portalHide: vi.fn<() => Promise<void>>(),
  portalAction: vi.fn<(action: string) => Promise<void>>(),
}))

vi.mock('../../services/tauri', () => bridge)

const { PortalTools, PortalView, forgetPortalPage } = await import('./PortalView')

const RECT = { left: 0, top: 120, width: 960, height: 600, right: 960, bottom: 720, x: 0, y: 120, toJSON: () => ({}) }

beforeEach(() => {
  forgetPortalPage()
  for (const fn of Object.values(bridge)) {
    fn.mockReset()
    fn.mockResolvedValue(undefined)
  }
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue(RECT as DOMRect)
})

describe('PortalView', () => {
  it('shows the portal at the page asked for, over the space it reserves', async () => {
    render(<PortalView path="/" />)

    await waitFor(() =>
      expect(bridge.portalShow).toHaveBeenCalledWith('/', { x: 0, y: 120, width: 960, height: 600 }),
    )
    expect(screen.getByTestId('portal-frame')).toBeInTheDocument()
  })

  it('opens the confirmation page for a sign-in', async () => {
    render(<PortalView path="/desktop-signin?code=ABCD-1234" />)

    await waitFor(() =>
      expect(bridge.portalShow).toHaveBeenCalledWith('/desktop-signin?code=ABCD-1234', expect.any(Object)),
    )
  })

  it('steps aside for something of the window’s own, and comes back without reloading the page', async () => {
    const { rerender } = render(<PortalView path="/sessions" />)
    await waitFor(() => expect(bridge.portalShow).toHaveBeenCalledTimes(1))

    rerender(<PortalView path="/sessions" hidden />)
    await waitFor(() => expect(bridge.portalHide).toHaveBeenCalled())

    rerender(<PortalView path="/sessions" />)
    await waitFor(() => expect(bridge.portalShow).toHaveBeenCalledTimes(2))
    // Shown again where it was — no page, so whatever the person was doing stays.
    expect(bridge.portalShow).toHaveBeenLastCalledWith(null, expect.any(Object))
  })

  it('opens the page again when asked to, even if it is the same one', async () => {
    const { rerender } = render(<PortalView path="/" navigateKey={0} />)
    await waitFor(() => expect(bridge.portalShow).toHaveBeenCalledTimes(1))

    rerender(<PortalView path="/" navigateKey={1} />)

    await waitFor(() => expect(bridge.portalShow).toHaveBeenCalledTimes(2))
    expect(bridge.portalShow).toHaveBeenLastCalledWith('/', expect.any(Object))
  })

  it('finds the person where they were after leaving the Remote tab and coming back', async () => {
    const first = render(<PortalView path="/" />)
    await waitFor(() => expect(bridge.portalShow).toHaveBeenCalledWith('/', expect.any(Object)))
    first.unmount()

    render(<PortalView path="/" />)

    await waitFor(() => expect(bridge.portalShow).toHaveBeenCalledTimes(2))
    expect(bridge.portalShow).toHaveBeenLastCalledWith(null, expect.any(Object))
  })

  it('takes the portal off the screen when it goes', async () => {
    const { unmount } = render(<PortalView path="/" />)
    await waitFor(() => expect(bridge.portalShow).toHaveBeenCalled())

    unmount()

    expect(bridge.portalHide).toHaveBeenCalled()
  })

  it('follows the window when it is resized', async () => {
    render(<PortalView path="/" />)
    await waitFor(() => expect(bridge.portalShow).toHaveBeenCalled())

    window.dispatchEvent(new Event('resize'))

    await waitFor(() =>
      expect(bridge.portalSetBounds).toHaveBeenCalledWith({ x: 0, y: 120, width: 960, height: 600 }),
    )
  })

  it('offers back, reload and open in browser on a sign-in page', async () => {
    render(<PortalView path="/" signInPage />)

    await userEvent.click(screen.getByRole('button', { name: /back/i }))
    await userEvent.click(screen.getByRole('button', { name: 'Reload' }))
    await userEvent.click(screen.getByRole('button', { name: 'Open in browser' }))

    expect(bridge.portalAction.mock.calls.map(([action]) => action)).toEqual(['back', 'reload', 'open_in_browser'])
  })

  /** Signed in, the portal has its own menus; a row of browser buttons above them is noise. */
  it('shows no browser buttons on the portal’s own pages', () => {
    render(<PortalView path="/" />)

    expect(screen.queryByRole('button', { name: /back/i })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Reload' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Open in browser' })).toBeNull()
  })

  it('keeps reload and open in browser for the header, as two small buttons', async () => {
    render(<PortalTools />)

    await userEvent.click(screen.getByRole('button', { name: 'Reload this page' }))
    await userEvent.click(screen.getByRole('button', { name: 'Open this page in your browser' }))

    expect(bridge.portalAction.mock.calls.map(([action]) => action)).toEqual(['reload', 'open_in_browser'])
  })

  it('says so when the portal could not be opened', async () => {
    bridge.portalShow.mockRejectedValue("AICOUNTLY Remote's address is not set up for this computer.")

    render(<PortalView path="/" />)

    expect(await screen.findByRole('alert')).toHaveTextContent('address is not set up')
  })
})
