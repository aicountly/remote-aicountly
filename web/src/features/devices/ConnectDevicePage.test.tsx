import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'

const api = vi.hoisted(() => ({ connectToDevice: vi.fn() }))
vi.mock('../../services/api/remote', () => api)

const { default: ConnectDevicePage } = await import('./ConnectDevicePage')
const { RemoteApiError } = await import('../../services/api/client')

function renderAt(path: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/connect/:deviceUuid" element={<ConnectDevicePage />} />
        <Route path="/room/:uuid" element={<p>in the room</p>} />
      </Routes>
    </MemoryRouter>,
  )
}

// A block body: an arrow returning the reset mock would hand Vitest the mock as a teardown hook.
beforeEach(() => {
  api.connectToDevice.mockReset()
})

describe('ConnectDevicePage', () => {
  it('connects to the computer once and goes straight to the session', async () => {
    api.connectToDevice.mockResolvedValue({ session: { uuid: 'sess-9' } })

    renderAt('/connect/dev-1')

    expect(await screen.findByText('in the room')).toBeInTheDocument()
    expect(api.connectToDevice).toHaveBeenCalledTimes(1)
    expect(api.connectToDevice).toHaveBeenCalledWith('dev-1')
  })

  it('says why when the computer cannot be reached, with a way back', async () => {
    api.connectToDevice.mockImplementation(() =>
      Promise.reject(new RemoteApiError('DEVICE_OFFLINE', 'That device is not reachable at the moment.', 409)),
    )

    renderAt('/connect/dev-1')

    await waitFor(() => expect(screen.getByRole('link', { name: 'Back to your computers' })).toBeInTheDocument())
  })
})
