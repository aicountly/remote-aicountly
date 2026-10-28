import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import CompanionPanel, { blockedMessage, companionView } from './CompanionPanel'
import ControlPanel from './ControlPanel'
import type { CompanionLink, RemoteDevice, SessionControlState } from '../../types/remote'

/**
 * "Enable control from this computer" (docs/desktop/CONTROL_COMPANION.md), and what a viewer sees while a
 * companion is not ready or the machine has paused input.
 */

function link(overrides: Partial<CompanionLink> = {}): CompanionLink {
  return {
    uuid: 'link-1',
    status: 'ACTIVE',
    deviceUuid: 'device-1',
    deviceName: 'Asha MacBook',
    linkedParticipantUuid: 'me',
    participantUuid: 'companion-1',
    monitor: null,
    monitorMatch: null,
    ready: true,
    endReason: null,
    requestedAt: null,
    expiresAt: null,
    joinedAt: null,
    endedAt: null,
    ...overrides,
  }
}

function control(overrides: Partial<SessionControlState> = {}): SessionControlState {
  return {
    controllableHostUuid: null,
    controllerUuid: null,
    controllerName: null,
    clipboardEnabled: false,
    pendingRequests: [],
    allowRemoteControl: true,
    allowClipboardSync: true,
    allowDeviceReboot: false,
    restrictions: [],
    companion: null,
    sharedSurface: { displaySurface: 'monitor', width: 3024, height: 1964, verified: true },
    controlBlockedReason: 'NO_COMPANION',
    grantId: null,
    ...overrides,
  }
}

const device = { uuid: 'device-1', deviceName: 'Asha MacBook' } as RemoteDevice

describe('companionView', () => {
  it('walks through off, waiting, choosing the screen and ready', () => {
    expect(companionView(control())).toBe('off')
    expect(companionView(control({ companion: link({ status: 'DECLINED' }) }))).toBe('off')
    expect(companionView(control({ companion: link({ status: 'PENDING', ready: false }), controlBlockedReason: 'COMPANION_NOT_READY' }))).toBe('waiting')
    expect(companionView(control({ companion: link({ status: 'JOINED', ready: false }), controlBlockedReason: 'COMPANION_NOT_READY' }))).toBe('choosing-screen')
    expect(companionView(control({ companion: link(), controlBlockedReason: null, controllableHostUuid: 'companion-1' }))).toBe('ready')
  })

  it('is blocked, with the reason, when the share is not an entire screen', () => {
    const state = control({
      companion: link({ ready: false }),
      controlBlockedReason: 'SURFACE_NOT_MONITOR',
      sharedSurface: { displaySurface: 'window', width: 800, height: 600, verified: true },
    })

    expect(companionView(state)).toBe('blocked')
    expect(blockedMessage(state, true)).toMatch(/entire screen, not a window or tab/i)
  })

  it('names Chrome or Edge when the browser does not say what it shares', () => {
    const state = control({
      controlBlockedReason: 'SURFACE_NOT_MONITOR',
      sharedSurface: { displaySurface: 'unknown', width: null, height: null, verified: false },
    })

    expect(blockedMessage(state, true)).toMatch(/Chrome or Edge/)
  })

  it('asks for a share first when nothing is shared', () => {
    expect(blockedMessage(control({ sharedSurface: { displaySurface: null, width: null, height: null, verified: false } }), false)).toMatch(
      /share your entire screen/i,
    )
    expect(blockedMessage(control(), true)).toBeNull()
  })
})

describe('CompanionPanel', () => {
  it('lists my computers and links the one I pick', async () => {
    const onLink = vi.fn()
    const loadDevices = vi.fn(async () => ({ devices: [device], restrictions: [] }))

    render(
      <CompanionPanel sessionUuid="s-1" control={control()} isSharing busy={false} onLink={onLink} onUnlink={() => {}} loadDevices={loadDevices} />,
    )

    await userEvent.click(screen.getByRole('button', { name: /enable control from this computer/i }))
    await userEvent.click(await screen.findByRole('button', { name: 'Use Asha MacBook' }))

    expect(loadDevices).toHaveBeenCalledWith('s-1')
    expect(onLink).toHaveBeenCalledWith('device-1')
  })

  it('says the desktop app is coming soon when there is no computer to use', async () => {
    const loadDevices = vi.fn(async () => ({ devices: [], restrictions: ['NO_ELIGIBLE_DEVICE'] }))

    render(
      <CompanionPanel sessionUuid="s-1" control={control()} isSharing busy={false} onLink={() => {}} onUnlink={() => {}} loadDevices={loadDevices} />,
    )

    await userEvent.click(screen.getByRole('button', { name: /enable control from this computer/i }))

    expect(await screen.findByText(/No AICOUNTLY Remote desktop app is signed in for you/i)).toBeInTheDocument()
    expect(screen.getByText(/desktop app is coming soon; if you already have it/i)).toBeInTheDocument()
  })

  it('never asks for an unreleased app without saying it is coming soon', () => {
    render(
      <CompanionPanel sessionUuid="s-1" control={control()} isSharing busy={false} onLink={() => {}} onUnlink={() => {}} />,
    )

    expect(screen.getByText(/the desktop app is coming soon/i)).toBeInTheDocument()
    expect(screen.getByText(/can point and draw on what you share/i)).toBeInTheDocument()
  })

  it('cannot be enabled before sharing', () => {
    render(
      <CompanionPanel
        sessionUuid="s-1"
        control={control({ sharedSurface: { displaySurface: null, width: null, height: null, verified: false } })}
        isSharing={false}
        busy={false}
        onLink={() => {}}
        onUnlink={() => {}}
      />,
    )

    expect(screen.getByRole('button', { name: /enable control from this computer/i })).toBeDisabled()
    expect(screen.getByText(/share your entire screen to allow control/i)).toBeInTheDocument()
  })

  it('waits for confirmation on the computer, and can be cancelled', async () => {
    const onUnlink = vi.fn()

    render(
      <CompanionPanel
        sessionUuid="s-1"
        control={control({ companion: link({ status: 'PENDING', ready: false }), controlBlockedReason: 'COMPANION_NOT_READY' })}
        isSharing
        busy={false}
        onLink={() => {}}
        onUnlink={onUnlink}
      />,
    )

    expect(screen.getByText(/confirm on Asha MacBook/i)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(onUnlink).toHaveBeenCalled()
  })

  it('asks for the screen to be chosen on the computer, then says ready', () => {
    const { rerender } = render(
      <CompanionPanel
        sessionUuid="s-1"
        control={control({ companion: link({ status: 'JOINED', ready: false }), controlBlockedReason: 'COMPANION_NOT_READY' })}
        isSharing
        busy={false}
        onLink={() => {}}
        onUnlink={() => {}}
      />,
    )
    expect(screen.getByText(/choose the screen you are sharing on Asha MacBook/i)).toBeInTheDocument()

    rerender(
      <CompanionPanel
        sessionUuid="s-1"
        control={control({ companion: link(), controlBlockedReason: null, controllableHostUuid: 'companion-1' })}
        isSharing
        busy={false}
        onLink={() => {}}
        onUnlink={() => {}}
      />,
    )
    expect(screen.getByText(/will carry control you allow/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Turn off' })).toBeInTheDocument()
  })

  it('says when the computer declined', () => {
    render(
      <CompanionPanel sessionUuid="s-1" control={control({ companion: link({ status: 'DECLINED', ready: false }) })} isSharing busy={false} onLink={() => {}} onUnlink={() => {}} />,
    )

    expect(screen.getByText(/declined on that computer/i)).toBeInTheDocument()
  })
})

describe('ControlPanel with a companion', () => {
  const base = {
    host: null,
    pending: [],
    isHost: false,
    hostParticipant: null,
    onRequest: () => {},
    onStop: () => {},
    onGrant: () => {},
    onDeny: () => {},
    allowClipboard: false,
    onAllowClipboardChange: () => {},
    busy: false,
  }

  it('tells a viewer why control is not available yet', () => {
    render(<ControlPanel {...base} phase="unavailable" control={control({ controlBlockedReason: 'SURFACE_NOT_MONITOR', companion: link({ ready: false }) })} />)

    expect(screen.getByText(/only while an entire screen is shared/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /request control/i })).not.toBeInTheDocument()
  })

  it('tells the controller when the machine has paused input', () => {
    render(<ControlPanel {...base} phase="controlling" control={control({ controllableHostUuid: 'companion-1' })} paused="local_input" />)

    expect(screen.getByText(/someone is using that computer/i)).toBeInTheDocument()
  })

  it('does not offer the clipboard for control through a companion', () => {
    render(
      <ControlPanel
        {...base}
        isHost
        phase="unavailable"
        pending={[{ participantUuid: 'b', displayName: 'Bala', requestedAt: null }]}
        control={control({ companion: link(), controllableHostUuid: 'companion-1', controlBlockedReason: null })}
        companion={{ sessionUuid: 's-1', isSharing: true, onLink: () => {}, onUnlink: () => {} }}
      />,
    )

    expect(screen.getByText(/Bala is asking to control this computer/i)).toBeInTheDocument()
    expect(screen.queryByText(/also share the clipboard/i)).not.toBeInTheDocument()
    expect(screen.getByText(/will carry control you allow/i)).toBeInTheDocument()
  })
})
