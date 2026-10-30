import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { RouterProvider, createMemoryRouter } from 'react-router-dom'

import RouteError, { isStaleBuild } from './RouteError'

/**
 * A screen that throws must not take the product down with React Router's
 * development page; it says what happened and offers a way on.
 */

function Broken(): never {
  throw new Error('a screen that cannot render')
}

afterEach(() => {
  vi.restoreAllMocks()
})

describe('RouteError', () => {
  it('replaces a screen that failed with a sentence, Reload and a way back', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)

    const router = createMemoryRouter([{ path: '/', element: <Broken />, errorElement: <RouteError /> }])
    render(<RouterProvider router={router} />)

    expect(await screen.findByText('This screen could not be shown')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reload' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to Remote' })).toHaveAttribute('href', '/')
    expect(screen.queryByText(/Unexpected Application Error/i)).toBeNull()
  })

  /** A page left open across a deploy asks for code that is no longer there. */
  it('loads the new build once when a screen’s code was replaced by a deploy', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    const reload = vi.fn()
    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, reload } as Location)
    sessionStorage.clear()

    function Stale(): never {
      throw new TypeError('Failed to fetch dynamically imported module: https://remote.aicountly.com/assets/SessionRoomPage-old.js')
    }

    const first = createMemoryRouter([{ path: '/', element: <Stale />, errorElement: <RouteError /> }])
    const { unmount } = render(<RouterProvider router={first} />)
    await screen.findByText('This screen could not be shown')
    expect(reload).toHaveBeenCalledTimes(1)
    unmount()

    // A second failure straight after is a broken deploy, not a stale page: no loop.
    const second = createMemoryRouter([{ path: '/', element: <Stale />, errorElement: <RouteError /> }])
    render(<RouterProvider router={second} />)
    await screen.findByText('This screen could not be shown')
    expect(reload).toHaveBeenCalledTimes(1)
  })

  it('tells a stale build from any other failure', () => {
    expect(isStaleBuild(new TypeError('Failed to fetch dynamically imported module: x.js'))).toBe(true)
    expect(isStaleBuild(new TypeError('Importing a module script failed.'))).toBe(true)
    expect(isStaleBuild(new Error('error loading dynamically imported module'))).toBe(true)
    expect(isStaleBuild(new Error('Cannot read properties of undefined'))).toBe(false)
    expect(isStaleBuild(null)).toBe(false)
  })

  it('calls a page that does not exist what it is', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)

    const router = createMemoryRouter([{ path: '/', element: <p>home</p>, errorElement: <RouteError /> }], {
      initialEntries: ['/nowhere'],
    })
    render(<RouterProvider router={router} />)

    expect(await screen.findByText('That page isn’t part of AICOUNTLY Remote')).toBeInTheDocument()
  })
})
