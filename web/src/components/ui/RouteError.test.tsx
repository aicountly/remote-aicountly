import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { RouterProvider, createMemoryRouter } from 'react-router-dom'

import RouteError from './RouteError'

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

  it('calls a page that does not exist what it is', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)

    const router = createMemoryRouter([{ path: '/', element: <p>home</p>, errorElement: <RouteError /> }], {
      initialEntries: ['/nowhere'],
    })
    render(<RouterProvider router={router} />)

    expect(await screen.findByText('That page isn’t part of AICOUNTLY Remote')).toBeInTheDocument()
  })
})
