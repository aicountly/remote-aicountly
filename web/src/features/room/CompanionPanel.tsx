import { useState } from 'react'
import { Laptop } from 'lucide-react'

import { fetchCompanionDevices } from '../../services/api/remote'
import type { CompanionLink, RemoteDevice, SessionControlState } from '../../types/remote'

/**
 * "Enable control from this computer" — the sharer's side of a control
 * companion (docs/desktop/CONTROL_COMPANION.md).
 *
 * A browser cannot be controlled. While the person shares an entire screen
 * from Chrome or Edge, their own AICOUNTLY Remote app can join the session to
 * carry input — after they confirm it on that computer, and only for requests
 * they allow here. Everything this shows comes from the API; it decides
 * nothing.
 */

export type CompanionView = 'off' | 'waiting' | 'choosing-screen' | 'ready' | 'blocked'

const LIVE: CompanionLink['status'][] = ['PENDING', 'JOINED', 'ACTIVE']

/** The live link, if there is one. */
export function liveCompanion(control: SessionControlState | null): CompanionLink | null {
  const link = control?.companion ?? null

  return link !== null && LIVE.includes(link.status) ? link : null
}

/** Which state to show. Pulled out so it is tested rather than assumed. */
export function companionView(control: SessionControlState | null): CompanionView {
  const link = liveCompanion(control)
  if (link === null) return 'off'
  if (link.status === 'PENDING') return 'waiting'

  const reason = control?.controlBlockedReason ?? null
  if (reason === 'NOT_SHARING' || reason === 'SURFACE_NOT_MONITOR') return 'blocked'
  if (link.status === 'JOINED') return 'choosing-screen'

  return link.ready ? 'ready' : 'blocked'
}

/** Why control cannot work right now, in one plain sentence, or null. */
export function blockedMessage(control: SessionControlState | null, isSharing: boolean): string | null {
  const surface = control?.sharedSurface?.displaySurface ?? null

  if (!isSharing || surface === null || control?.controlBlockedReason === 'NOT_SHARING') {
    return 'Share your entire screen to allow control.'
  }

  if (surface === 'unknown') {
    return 'This browser does not say what you are sharing, so control is not possible. Use Chrome or Edge.'
  }

  if (surface !== 'monitor' || control?.controlBlockedReason === 'SURFACE_NOT_MONITOR') {
    return 'Control works only when you share your entire screen, not a window or tab.'
  }

  return null
}

function outcomeNote(link: CompanionLink | null): string | null {
  if (link === null) return null
  if (link.status === 'DECLINED') return 'The request was declined on that computer.'
  if (link.status === 'EXPIRED') return 'The request was not confirmed in time.'

  return null
}

function restrictionMessage(restrictions: string[]): string {
  if (restrictions.includes('CONTROL_ACCEPT_DENIED')) return 'You do not have permission to hand over control.'
  if (restrictions.some((r) => r.startsWith('REMOTE_CONTROL'))) return 'Remote control is not enabled for this organisation.'
  if (restrictions.includes('PERSONAL_SCOPE')) return 'Control is available only in organisation sessions.'

  return 'No AICOUNTLY Remote app found for you. Open it on this computer, sign in, and try again.'
}

interface Props {
  sessionUuid: string
  control: SessionControlState | null
  /** Whether this browser is sharing right now. */
  isSharing: boolean
  busy: boolean
  onLink: (deviceUuid: string) => void
  onUnlink: () => void
  /** Injectable for tests. */
  loadDevices?: (sessionUuid: string) => Promise<{ devices: RemoteDevice[]; restrictions: string[] }>
}

export default function CompanionPanel({
  sessionUuid,
  control,
  isSharing,
  busy,
  onLink,
  onUnlink,
  loadDevices = fetchCompanionDevices,
}: Props) {
  const [devices, setDevices] = useState<RemoteDevice[] | null>(null)
  const [restrictions, setRestrictions] = useState<string[]>([])
  const [loading, setLoading] = useState(false)
  const [loadError, setLoadError] = useState<string | null>(null)

  const view = companionView(control)
  const link = liveCompanion(control)
  const deviceName = link?.deviceName ?? 'your computer'
  const blocked = blockedMessage(control, isSharing)

  const showDevices = async () => {
    setLoading(true)
    setLoadError(null)
    try {
      const result = await loadDevices(sessionUuid)
      setDevices(result.devices)
      setRestrictions(result.restrictions)
    } catch {
      setLoadError('Your computers could not be listed. Try again.')
    } finally {
      setLoading(false)
    }
  }

  const stopButton = (label: string) => (
    <button type="button" className="btn btn--secondary btn--sm" onClick={onUnlink} disabled={busy}>
      {label}
    </button>
  )

  return (
    <div className="control__companion" aria-label="Control from this computer">
      <p className="control__companion-title">
        <Laptop size={15} aria-hidden="true" />
        Control from this computer
      </p>

      {view === 'off' ? (
        <>
          <p className="control__empty">
            Let someone you allow control this computer while you share your entire screen. The AICOUNTLY Remote app
            must be open on it.
          </p>
          {outcomeNote(control?.companion ?? null) ? (
            <p className="control__hint">{outcomeNote(control?.companion ?? null)}</p>
          ) : null}
          {blocked ? <p className="control__hint">{blocked}</p> : null}

          {devices === null ? (
            <button
              type="button"
              className="btn btn--primary btn--sm"
              onClick={() => void showDevices()}
              disabled={busy || loading || !isSharing}
            >
              Enable control from this computer
            </button>
          ) : devices.length === 0 ? (
            <p className="control__hint">{restrictionMessage(restrictions)}</p>
          ) : (
            <ul className="control__devices">
              {devices.map((device) => (
                <li key={device.uuid}>
                  <button
                    type="button"
                    className="btn btn--primary btn--sm"
                    onClick={() => onLink(device.uuid)}
                    disabled={busy}
                  >
                    Use {device.deviceName}
                  </button>
                </li>
              ))}
            </ul>
          )}
          {loadError ? <p className="control__hint">{loadError}</p> : null}
        </>
      ) : null}

      {view === 'waiting' ? (
        <>
          <p className="control__empty">Confirm on {deviceName} to continue.</p>
          {stopButton('Cancel')}
        </>
      ) : null}

      {view === 'choosing-screen' ? (
        <>
          <p className="control__empty">Choose the screen you are sharing on {deviceName}.</p>
          {stopButton('Turn off')}
        </>
      ) : null}

      {view === 'ready' ? (
        <>
          <p className="control__active">
            <span>
              <strong>Ready.</strong> {deviceName} will carry control you allow.
            </span>
          </p>
          {stopButton('Turn off')}
        </>
      ) : null}

      {view === 'blocked' ? (
        <>
          <p className="control__empty">{blocked ?? `${deviceName} is not ready yet.`}</p>
          {stopButton('Turn off')}
        </>
      ) : null}
    </div>
  )
}
