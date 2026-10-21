import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import ConnectionIndicator from './ConnectionIndicator'
import type { EngineSnapshot, NetworkStatus } from '../../services/webrtc/RemoteSessionEngine'

/**
 * A failed connection is explained from what the browser saw, and offers to
 * try again — never "reconnecting" forever (§20, docs/ICE_CONTRACT.md).
 */

const network = (overrides: Partial<NetworkStatus> = {}): NetworkStatus => ({
  path: 'unknown',
  relayNode: null,
  relayProtocol: null,
  recovering: false,
  restarts: 0,
  verdict: null,
  message: null,
  credentialsExpireAt: null,
  ...overrides,
})

const live = (overrides: Partial<EngineSnapshot>): EngineSnapshot =>
  ({
    connection: 'connected',
    quality: 'good',
    relayAvailable: true,
    network: network(),
    ...overrides,
  }) as EngineSnapshot

describe('ConnectionIndicator', () => {
  it('says why a connection failed and offers to reconnect', async () => {
    const onReconnect = vi.fn()
    render(
      <ConnectionIndicator
        live={live({
          connection: 'failed',
          network: network({ verdict: 'relay-unreachable', message: 'The relay server could not be reached from this network.' }),
        })}
        sessionStatus="ACTIVE"
        onReconnect={onReconnect}
      />,
    )

    expect(screen.getByText('Unable to reconnect')).toBeInTheDocument()
    expect(screen.getByText(/could not be reached from this network/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Reconnect' }))
    expect(onReconnect).toHaveBeenCalledOnce()
  })

  it('still explains a deployment with no relay at all', () => {
    render(<ConnectionIndicator live={live({ connection: 'failed', relayAvailable: false })} sessionStatus="ACTIVE" />)

    expect(screen.getByText(/direct connection could not be made/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Reconnect' })).not.toBeInTheDocument()
  })

  it('shows recovery as reconnecting, not as failed', () => {
    render(<ConnectionIndicator live={live({ connection: 'reconnecting', network: network({ recovering: true }) })} sessionStatus="ACTIVE" />)

    expect(screen.getByText('Reconnecting…')).toBeInTheDocument()
  })
})
