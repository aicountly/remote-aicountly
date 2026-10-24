import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import SessionCodeBadge from './SessionCodeBadge'

describe('SessionCodeBadge', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('shows the code where the host can read it out', () => {
    render(<SessionCodeBadge code="482913" />)

    expect(screen.getByText('Session code')).toBeInTheDocument()
    expect(screen.getByText('482913')).toBeInTheDocument()
  })

  it('copies the code and says so', async () => {
    const user = userEvent.setup()
    const writeText = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined)
    render(<SessionCodeBadge code="482913" />)

    await user.click(screen.getByRole('button', { name: 'Copy session code' }))

    expect(writeText).toHaveBeenCalledWith('482913')
    expect(await screen.findByRole('button', { name: 'Session code copied' })).toBeInTheDocument()
  })

  it('keeps the code on screen when the clipboard is refused', async () => {
    const user = userEvent.setup()
    vi.spyOn(navigator.clipboard, 'writeText').mockRejectedValue(new Error('denied'))
    render(<SessionCodeBadge code="482913" />)

    await user.click(screen.getByRole('button', { name: 'Copy session code' }))

    expect(screen.getByRole('button', { name: 'Copy session code' })).toBeInTheDocument()
    expect(screen.getByText('482913')).toBeInTheDocument()
  })
})
