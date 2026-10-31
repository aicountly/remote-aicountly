import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import { HelpSomeone, formatSessionCode, sessionCodeDigits } from './ConnectScreen'

describe('session codes', () => {
  it('keeps nine digits of whatever was typed or pasted, and spaces them in threes', () => {
    expect(sessionCodeDigits('123 456-789 0')).toBe('123456789')
    expect(formatSessionCode('123456789')).toBe('123 456 789')
    expect(formatSessionCode('1234')).toBe('123 4')
  })
})

describe('HelpSomeone', () => {
  it('joins only with a whole code, and passes the digits on', async () => {
    const onJoin = vi.fn()

    render(<HelpSomeone onJoin={onJoin} />)

    const join = screen.getByRole('button', { name: 'Join' })
    expect(join).toBeDisabled()

    await userEvent.type(screen.getByLabelText('Session code'), '12345678')
    expect(join).toBeDisabled()

    await userEvent.type(screen.getByLabelText('Session code'), '9')
    expect(screen.getByLabelText('Session code')).toHaveValue('123 456 789')

    await userEvent.click(join)
    expect(onJoin).toHaveBeenCalledWith('123456789')
  })
})
