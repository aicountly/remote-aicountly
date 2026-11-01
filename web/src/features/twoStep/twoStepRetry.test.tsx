import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'

/**
 * The whole path a refused call takes, through the real API client: the
 * `/connect/:deviceUuid` page (which the desktop application opens in its
 * window) asks to connect, the API answers TWO_STEP_REQUIRED, the one shared
 * prompt asks for a code, the code is verified, and the same request goes
 * again — now carrying the new token — and lands in the session.
 */

vi.mock('../../auth/portal', async () => {
  const actual = await vi.importActual<typeof import('../../auth/portal')>('../../auth/portal')
  return { ...actual, ensureSesKey: vi.fn(async () => 'ses-key') }
})

const { default: ConnectDevicePage } = await import('../devices/ConnectDevicePage')
const { default: TwoStepPrompt } = await import('./TwoStepPrompt')
const { resetTwoStepPrompt } = await import('../../services/twoStepPrompt')
const { getTwoStepToken, setTwoStepToken } = await import('../../auth/tokens')

type Call = { url: string; init: RequestInit }

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function headerOf(call: Call, name: string): string | undefined {
  return (call.init.headers as Record<string, string>)[name]
}

beforeEach(() => {
  localStorage.clear()
  resetTwoStepPrompt()
})

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

describe('a call refused with TWO_STEP_REQUIRED', () => {
  it('asks for a code once, then sends the same request again and carries on', async () => {
    const calls: Call[] = []
    let connects = 0
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string, init: RequestInit) => {
        calls.push({ url, init })
        if (url.endsWith('/v1/remote/devices/dev-1/connect')) {
          connects += 1
          return connects === 1
            ? json(403, { error: { code: 'TWO_STEP_REQUIRED', message: 'Enter the code.', details: { reason: 'SESSION' } } })
            : json(201, { data: { session: { uuid: 'sess-9' } } })
        }
        if (url.endsWith('/v1/remote/me/two-step/verify')) {
          return json(200, {
            data: { verificationToken: 'fresh-token', verifiedAt: '2026-11-01T09:00:00+00:00', verifiedUntil: '2026-11-01T21:00:00+00:00', method: 'TOTP', recoveryCodesLeft: 10 },
          })
        }
        return json(404, { error: { code: 'NOT_FOUND', message: 'no' } })
      }),
    )

    render(
      <>
        <MemoryRouter initialEntries={['/connect/dev-1']}>
          <Routes>
            <Route path="/connect/:deviceUuid" element={<ConnectDevicePage />} />
            <Route path="/room/:uuid" element={<p>in the room</p>} />
          </Routes>
        </MemoryRouter>
        <TwoStepPrompt onSignOut={() => undefined} />
      </>,
    )

    const input = await screen.findByLabelText('Code from your authenticator app')
    expect(screen.getByRole('dialog', { name: 'Two-step verification' })).toBeInTheDocument()
    await userEvent.type(input, '123 456')
    await userEvent.click(screen.getByRole('button', { name: 'Verify' }))

    expect(await screen.findByText('in the room')).toBeInTheDocument()
    expect(connects).toBe(2)

    const verify = calls.find((call) => call.url.endsWith('/me/two-step/verify'))!
    expect(JSON.parse(String(verify.init.body))).toEqual({ code: '123456' })

    const retried = calls.filter((call) => call.url.endsWith('/devices/dev-1/connect'))
    expect(headerOf(retried[0], 'X-Remote-Two-Step')).toBeUndefined()
    expect(headerOf(retried[1], 'X-Remote-Two-Step')).toBe('fresh-token')
    expect(getTwoStepToken()).toBe('fresh-token')
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('hands the page its refusal when a step-up is cancelled, and sends nothing more', async () => {
    setTwoStepToken('day-old-token')
    let connects = 0
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        connects += 1
        return json(403, {
          error: {
            code: 'TWO_STEP_REQUIRED',
            message: 'Connecting to a computer with nobody at it needs a code from your authenticator app. Enter a code, then try again.',
            details: { reason: 'STEP_UP' },
          },
        })
      }),
    )

    render(
      <>
        <MemoryRouter initialEntries={['/connect/dev-1']}>
          <Routes>
            <Route path="/connect/:deviceUuid" element={<ConnectDevicePage />} />
          </Routes>
        </MemoryRouter>
        <TwoStepPrompt onSignOut={() => undefined} />
      </>,
    )

    const dialog = await screen.findByRole('dialog', { name: 'Confirm it is you' })
    expect(dialog).toHaveTextContent('Connecting to a computer with nobody at it needs a code')
    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(await screen.findByText('A code from your authenticator app is needed')).toBeInTheDocument()
    expect(connects).toBe(1)
    expect(getTwoStepToken()).toBe('day-old-token')
  })
})
