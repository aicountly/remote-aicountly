import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'

vi.mock('../../services/api/remote', () => ({ joinByCode: vi.fn() }))

const { default: JoinSessionPage } = await import('./JoinSessionPage')

describe('JoinSessionPage with ?code=', () => {
  it('arrives with the code typed, so only Join is left to press', () => {
    render(
      <MemoryRouter initialEntries={['/join?code=583-194-726']}>
        <JoinSessionPage />
      </MemoryRouter>,
    )

    expect((screen.getByLabelText(/session code/i) as HTMLInputElement).value.replace(/\D/g, '')).toBe('583194726')
  })
})
