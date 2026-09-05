import { Laptop, Monitor, Server, Smartphone } from 'lucide-react'

import type { RemoteDevice } from '../../types/remote'
import { formatDateTime, formatRelative } from '../../utils/format'

/**
 * One registered computer.
 *
 * The row answers the three questions somebody actually has about a machine
 * they may be about to connect to: is it reachable, will it answer with nobody
 * there, and what is running on it. The key fingerprint is shown because it is
 * the one thing a person can compare against what the agent displays on the
 * machine itself — which is how you tell a device apart from a name somebody
 * typed.
 */

interface Props {
  device: RemoteDevice
  canManage: boolean
  canConnect: boolean
  /** The organisation's switch. A device switch cannot exceed it (§53). */
  unattendedAllowed: boolean
  busy: boolean
  onConnect: () => void
  onEnableUnattended: () => void
  onDisableUnattended: () => void
  onRevoke: () => void
}

const ICONS = {
  DESKTOP: Monitor,
  LAPTOP: Laptop,
  SERVER: Server,
  MOBILE: Smartphone,
}

export default function DeviceRow({
  device,
  canManage,
  canConnect,
  unattendedAllowed,
  busy,
  onConnect,
  onEnableUnattended,
  onDisableUnattended,
  onRevoke,
}: Props) {
  const Icon = ICONS[device.deviceType] ?? Monitor
  const revoked = device.status === 'REVOKED'
  const suspended = device.status === 'SUSPENDED'

  // Reachable, and allowed to be reached without anybody there. Both, or the
  // Connect button is a button that would fail.
  const connectable = canConnect && device.online && device.unattendedAccessEnabled && !revoked && !suspended

  return (
    <li className={revoked ? 'device device--revoked' : 'device'}>
      <div className="device__icon" aria-hidden="true">
        <Icon size={20} />
      </div>

      <div className="device__body">
        <p className="device__name truncate">
          {device.deviceName}
          <span className={device.online ? 'device__presence device__presence--online' : 'device__presence'}>
            {revoked ? 'Removed' : suspended ? 'Suspended' : device.online ? 'Online' : 'Offline'}
          </span>
        </p>

        <p className="device__meta">
          {[
            device.operatingSystem,
            device.osVersion,
            device.architecture,
            device.agentVersion ? `AICOUNTLY Remote ${device.agentVersion}` : null,
          ]
            .filter(Boolean)
            .join(' · ')}
        </p>

        <dl className="device__facts">
          <div>
            <dt>Registered by</dt>
            <dd>{device.enrolledByName ?? device.ownerName ?? 'Unknown'}</dd>
          </div>
          <div>
            <dt>Last seen</dt>
            <dd title={formatDateTime(device.lastSeenAt)}>{formatRelative(device.lastSeenAt)}</dd>
          </div>
          {device.keyFingerprint ? (
            <div>
              <dt>Key fingerprint</dt>
              {/* Compare this with what the agent shows on the machine. It is
                  the only way to be sure this row is that computer. */}
              <dd className="mono device__fingerprint">{device.keyFingerprint}</dd>
            </div>
          ) : null}
        </dl>

        <p className={device.unattendedAccessEnabled ? 'device__unattended device__unattended--on' : 'device__unattended'}>
          {device.unattendedAccessEnabled ? (
            <>
              <strong>Unattended access is on.</strong> Turned on{' '}
              {formatRelative(device.unattendedEnabledAt)}
              {device.unattendedLastUsedAt
                ? `, last used ${formatRelative(device.unattendedLastUsedAt)}.`
                : ', not used yet.'}
            </>
          ) : (
            <>Unattended access is off. Somebody at the computer has to approve each connection.</>
          )}
        </p>
      </div>

      <div className="device__actions">
        {connectable ? (
          <button type="button" className="btn btn--primary btn--sm" onClick={onConnect} disabled={busy}>
            Connect
          </button>
        ) : null}

        {canManage && !revoked ? (
          device.unattendedAccessEnabled ? (
            <button
              type="button"
              className="btn btn--secondary btn--sm"
              onClick={onDisableUnattended}
              disabled={busy}
            >
              Turn off unattended
            </button>
          ) : unattendedAllowed ? (
            <button
              type="button"
              className="btn btn--secondary btn--sm"
              onClick={onEnableUnattended}
              disabled={busy || device.status !== 'ACTIVE'}
            >
              Allow unattended
            </button>
          ) : (
            // Not hidden: an administrator who cannot find the option assumes
            // the product lacks it rather than that their organisation
            // switched it off.
            <span className="device__blocked">Unattended access is off for this organisation</span>
          )
        ) : null}

        {canManage && !revoked ? (
          <button type="button" className="btn btn--ghost btn--sm" onClick={onRevoke} disabled={busy}>
            Remove
          </button>
        ) : null}
      </div>
    </li>
  )
}
