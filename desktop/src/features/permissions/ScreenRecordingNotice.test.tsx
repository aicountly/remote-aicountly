import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import { ScreenRecordingNotice } from './ScreenRecordingNotice'

/**
 * The steps a person has to take before a Mac can show its screen in a
 * session — including the restart macOS needs before a grant takes effect,
 * which is the step people miss.
 */
describe('ScreenRecordingNotice', () => {
  it('names the pane, under its macOS 15 name too, and says the app must be reopened', () => {
    render(<ScreenRecordingNotice computerName="Mac" onOpen={vi.fn()} />)

    expect(screen.getByText('Allow AICOUNTLY Remote in Screen Recording')).toBeInTheDocument()
    expect(screen.getByText(/Privacy & Security → Screen Recording/)).toBeInTheDocument()
    expect(screen.getByText(/Screen & System Audio Recording/)).toBeInTheDocument()
    expect(screen.getByText(/Quit and reopen AICOUNTLY Remote/)).toBeInTheDocument()
    expect(screen.getByText(/cannot show its screen/)).toBeInTheDocument()
  })

  it('speaks about the computer it is on', () => {
    render(<ScreenRecordingNotice computerName="MacBook Pro" onOpen={vi.fn()} />)

    expect(screen.getByText(/show this MacBook Pro's screen/)).toBeInTheDocument()
  })

  it('opens the settings exactly once per click', async () => {
    const onOpen = vi.fn()

    render(<ScreenRecordingNotice computerName="Mac" onOpen={onOpen} />)

    await userEvent.click(screen.getByRole('button', { name: /open screen recording settings/i }))
    expect(onOpen).toHaveBeenCalledOnce()
  })

  it('cannot be clicked while a request is already on its way', async () => {
    const onOpen = vi.fn()

    render(<ScreenRecordingNotice computerName="Mac" onOpen={onOpen} busy />)

    const button = screen.getByRole('button', { name: /open screen recording settings/i })
    expect(button).toBeDisabled()

    await userEvent.click(button)
    expect(onOpen).not.toHaveBeenCalled()
  })
})
