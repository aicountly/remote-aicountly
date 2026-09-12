import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import ControlPanel, { controlPhase } from './ControlPanel'
import type { EnginePeer } from '../../services/webrtc/RemoteSessionEngine'
import type { Participant, SessionControlState } from '../../types/remote'

/**
 * Capability-driven control UI (§19, §51).
 *
 * The two rules that matter are asserted directly, because getting either
 * wrong produces a product that lies about what it can do:
 *
 *   * **capability first** — a browser host cannot be controlled however
 *     permissive the organisation is, so there is no Request control button
 *     and the reason is said out loud;
 *   * **policy before intent** — a capable host plus a prohibition is
 *     `not-permitted`, and no amount of local state moves it.
 *
 * Nothing here branches on `clientType`, and neither does the component.
 */

function control(overrides: Partial<SessionControlState> = {}): SessionControlState {
  return {
    controllableHostUuid: 'host-participant',
    controllerUuid: null,
    controllerName: null,
    clipboardEnabled: false,
    pendingRequests: [],
    allowRemoteControl: true,
    allowClipboardSync: true,
    allowDeviceReboot: false,
    restrictions: [],
    ...overrides,
  }
}

function hostPeer(overrides: Partial<EnginePeer> = {}): EnginePeer {
  return {
    participantUuid: 'host-participant',
    role: 'SHARER',
    name: 'AICOUNTLY Remote for Windows on WS-01',
    // The declaration is an upper bound the server intersects with policy.
    // What matters to the panel is that it was declared at all.
    capabilities: { remote_control: true, screen_capture: true },
    connectionState: 'connected',
    dataChannelReady: true,
    controlChannelReady: true,
    ...overrides,
  }
}

function participant(overrides: Partial<Participant> = {}): Participant {
  return {
    uuid: 'host-participant',
    displayName: 'WS-01',
    role: 'SHARER',
    clientType: 'DESKTOP_AGENT',
    status: 'JOINED',
    isHost: true,
    isSharing: true,
    microphoneEnabled: false,
    connectionState: 'CONNECTED',
    capabilities: {
      screenCapture: true,
      microphone: false,
      dataChannel: true,
      remoteControl: true,
    } as Participant['capabilities'],
    deviceUuid: 'device-1',
    controlState: 'NONE',
    clipboardEnabled: false,
    controlRequestedAt: null,
    controlGrantedAt: null,
    requestedAt: null,
    joinedAt: null,
    leftAt: null,
    ...overrides,
  }
}

function panel(props: Partial<Parameters<typeof ControlPanel>[0]> = {}) {
  const onRequest = vi.fn()
  const onStop = vi.fn()
  const onGrant = vi.fn()
  const onDeny = vi.fn()
  const onAllowClipboardChange = vi.fn()

  render(
    <ControlPanel
      phase="idle"
      control={control()}
      host={hostPeer()}
      pending={[]}
      isHost={false}
      hostParticipant={participant()}
      onRequest={onRequest}
      onStop={onStop}
      onGrant={onGrant}
      onDeny={onDeny}
      allowClipboard={false}
      onAllowClipboardChange={onAllowClipboardChange}
      busy={false}
      {...props}
    />,
  )

  return { onRequest, onStop, onGrant, onDeny, onAllowClipboardChange }
}

describe('controlPhase', () => {
  const base = {
    hasControllableHost: true,
    allowedByPolicy: true,
    hasPermission: true,
    controlState: 'NONE' as Participant['controlState'] | null,
    isControlling: false,
  }

  it('is unavailable when no peer declared it can be controlled', () => {
    // A browser reports remote_control: false. This is Browser V1's promise
    // held all the way out to the button.
    expect(controlPhase({ ...base, hasControllableHost: false })).toBe('unavailable')
  })

  it('stays unavailable even when everything else says yes', () => {
    expect(
      controlPhase({
        ...base,
        hasControllableHost: false,
        allowedByPolicy: true,
        hasPermission: true,
        controlState: 'GRANTED',
        isControlling: true,
      }),
    ).toBe('unavailable')
  })

  it('is not-permitted when the organisation forbids control', () => {
    expect(controlPhase({ ...base, allowedByPolicy: false })).toBe('not-permitted')
  })

  it('is not-permitted when this person lacks the permission', () => {
    expect(controlPhase({ ...base, hasPermission: false })).toBe('not-permitted')
  })

  it('does not let a stale grant survive a prohibition', () => {
    // The company switch being turned off mid-session must not leave a
    // Controlling panel on screen. The server stops the session too; this is
    // the interface agreeing rather than arguing.
    expect(
      controlPhase({ ...base, allowedByPolicy: false, controlState: 'GRANTED', isControlling: true }),
    ).toBe('not-permitted')
  })

  it('is controlling only once input is actually being sent', () => {
    expect(controlPhase({ ...base, controlState: 'GRANTED', isControlling: true })).toBe('controlling')

    // Granted by the server but the channel has not opened. "Requested" is the
    // honest reading: nothing is being sent yet.
    expect(controlPhase({ ...base, controlState: 'GRANTED', isControlling: false })).toBe('requested')
  })

  it('keeps denied, revoked and never-asked apart', () => {
    expect(controlPhase({ ...base, controlState: 'NONE' })).toBe('idle')
    expect(controlPhase({ ...base, controlState: 'REQUESTED' })).toBe('requested')
    expect(controlPhase({ ...base, controlState: 'DENIED' })).toBe('denied')
    expect(controlPhase({ ...base, controlState: 'REVOKED' })).toBe('revoked')
    expect(controlPhase({ ...base, controlState: null })).toBe('idle')
  })
})

describe('ControlPanel', () => {
  it('says a browser cannot be controlled, and offers no button', () => {
    panel({ phase: 'unavailable', host: null, control: control({ controllableHostUuid: null }) })

    expect(screen.getByText(/web browser, which cannot be controlled/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /request control/i })).not.toBeInTheDocument()
  })

  it('names the organisation as the reason when policy is what says no', () => {
    panel({ phase: 'not-permitted', control: control({ allowRemoteControl: false }) })

    expect(screen.getByText(/not enabled for this organisation/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /request control/i })).not.toBeInTheDocument()
  })

  it('says it is the person, not the organisation, when the permission is missing', () => {
    panel({ phase: 'not-permitted', control: control({ allowRemoteControl: true }) })

    expect(screen.getByText(/do not have permission to request control/i)).toBeInTheDocument()
  })

  it('offers control of a capable host', async () => {
    const { onRequest } = panel({ phase: 'idle' })

    await userEvent.click(screen.getByRole('button', { name: /request control/i }))

    expect(onRequest).toHaveBeenCalledOnce()
  })

  it('shows a persistent stop while control is live', async () => {
    const { onStop } = panel({ phase: 'controlling' })

    expect(screen.getByText(/you are controlling this computer/i)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: /stop controlling/i }))

    expect(onStop).toHaveBeenCalledOnce()
  })

  it('says plainly whether the clipboard is shared while controlling', () => {
    panel({ phase: 'controlling', control: control({ clipboardEnabled: false }) })

    expect(screen.getByText(/the clipboard is not shared/i)).toBeInTheDocument()
  })

  it('asks the host about control and the clipboard as two decisions', async () => {
    const { onGrant, onAllowClipboardChange } = panel({
      phase: 'idle',
      isHost: true,
      pending: [{ participantUuid: 'viewer-1', displayName: 'Priya', requestedAt: null }],
    })

    expect(screen.getByText(/Priya is asking to control this computer/i)).toBeInTheDocument()

    // The clipboard is a separate tick, off unless it is ticked — control must
    // not silently start copying what is on the machine's clipboard (§59).
    const clipboard = screen.getByRole('checkbox', { name: /share the clipboard/i })
    expect(clipboard).not.toBeChecked()

    await userEvent.click(clipboard)
    expect(onAllowClipboardChange).toHaveBeenCalledWith(true)

    await userEvent.click(screen.getByRole('button', { name: /allow control/i }))
    expect(onGrant).toHaveBeenCalledWith('viewer-1', false)
  })

  it('does not offer the clipboard when the organisation forbids it', () => {
    panel({
      phase: 'idle',
      isHost: true,
      control: control({ allowClipboardSync: false }),
      pending: [{ participantUuid: 'viewer-1', displayName: 'Priya', requestedAt: null }],
    })

    expect(screen.queryByRole('checkbox', { name: /share the clipboard/i })).not.toBeInTheDocument()
  })

  it('lets the host decline without ceremony', async () => {
    const { onDeny } = panel({
      phase: 'idle',
      isHost: true,
      pending: [{ participantUuid: 'viewer-1', displayName: 'Priya', requestedAt: null }],
    })

    await userEvent.click(screen.getByRole('button', { name: /not now/i }))

    expect(onDeny).toHaveBeenCalledWith('viewer-1')
  })

  it('does not show another participant’s request to a viewer', () => {
    panel({
      phase: 'requested',
      isHost: false,
      pending: [{ participantUuid: 'viewer-1', displayName: 'Priya', requestedAt: null }],
    })

    expect(screen.queryByText(/is asking to control this computer/i)).not.toBeInTheDocument()
    expect(screen.getByText(/nothing is being sent until they do/i)).toBeInTheDocument()
  })
})
