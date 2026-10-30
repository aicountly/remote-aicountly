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

/**
 * The figures beside the word (§49): the round trip and the frame rate
 * arriving, which tell a slow network from a slow computer. Shown while
 * connected and never otherwise, and never read out by a screen reader.
 */
describe('ConnectionIndicator figures', () => {
  it('shows the round trip and the frame rate beside the word while connected', () => {
    render(<ConnectionIndicator live={live({ stats: { roundTripMs: 45, framesPerSecond: 15 } })} sessionStatus="ACTIVE" />)

    expect(screen.getByText('Connected')).toBeInTheDocument()
    expect(screen.getByText('45 ms · 15 fps')).toBeInTheDocument()
  })

  it('shows the round trip alone to the sharer, who receives no picture', () => {
    render(
      <ConnectionIndicator
        live={live({ quality: 'fair', stats: { roundTripMs: 310, framesPerSecond: null } })}
        sessionStatus="ACTIVE"
      />,
    )

    expect(screen.getByText('Connection fair')).toBeInTheDocument()
    expect(screen.getByText('310 ms')).toBeInTheDocument()
    expect(screen.queryByText(/fps/)).not.toBeInTheDocument()
  })

  it('shows no figures while the connection is not up, or the session is paused', () => {
    const stale = { roundTripMs: 45, framesPerSecond: 15 }
    const { rerender } = render(
      <ConnectionIndicator live={live({ connection: 'reconnecting', stats: stale })} sessionStatus="ACTIVE" />,
    )
    expect(screen.queryByText(/ms/)).not.toBeInTheDocument()

    rerender(<ConnectionIndicator live={live({ stats: stale })} sessionStatus="PAUSED" />)
    expect(screen.queryByText(/ms/)).not.toBeInTheDocument()

    rerender(<ConnectionIndicator live={live({ stats: null })} sessionStatus="ACTIVE" />)
    expect(screen.getByText('Connected')).toBeInTheDocument()
    expect(screen.queryByText(/ms/)).not.toBeInTheDocument()
  })

  it('announces the state, never the figures', () => {
    const { rerender } = render(
      <ConnectionIndicator live={live({ stats: { roundTripMs: 45, framesPerSecond: 15 } })} sessionStatus="ACTIVE" />,
    )
    rerender(<ConnectionIndicator live={live({ stats: { roundTripMs: 52, framesPerSecond: 14 } })} sessionStatus="ACTIVE" />)

    expect(screen.getByRole('status')).toHaveTextContent(/^Connected\.$/)
  })
})
