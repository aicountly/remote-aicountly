import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'

/**
 * The session history, when it cannot be read.
 *
 * A failed read left the page saying "0 sessions" and "No sessions yet" under the error: an
 * answer that had not been given, to somebody looking for last week's session. It says the
 * history is not loaded, and offers to try again.
 */

vi.mock('../../app/RemoteProvider', () => ({
  useRemote: () => ({ bootstrap: { companies: [] } }),
}))

const history = vi.hoisted(() => ({ fetchSessionHistory: vi.fn() }))

vi.mock('../../services/api/remote', () => history)

const { default: SessionsPage } = await import('./SessionsPage')

function renderPage() {
  return render(
    <MemoryRouter>
      <SessionsPage />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  history.fetchSessionHistory.mockReset()
})

describe('the Sessions page', () => {
  it('does not say there are no sessions when the history could not be read', async () => {
    history.fetchSessionHistory.mockRejectedValueOnce(new Error('offline'))
    history.fetchSessionHistory.mockResolvedValue({ data: [], meta: { total: 0 } })

    renderPage()

    expect(await screen.findByText('Your sessions could not be loaded.')).toBeInTheDocument()
    expect(screen.getByText('Not loaded.')).toBeInTheDocument()
    expect(screen.queryByText(/No sessions/)).not.toBeInTheDocument()
    expect(screen.queryByText('0 sessions')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Try again' }))

    // Read this time, and empty: now it is true to say so.
    expect(await screen.findByText('0 sessions')).toBeInTheDocument()
    expect(screen.queryByText('Your sessions could not be loaded.')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument()
    expect(history.fetchSessionHistory).toHaveBeenCalledTimes(2)
  })
})
