import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Laptop, MonitorSmartphone, ShieldAlert } from 'lucide-react'

import {
  connectToDevice,
  disableUnattendedAccess,
  enableUnattendedAccess,
  fetchDevices,
  revokeDevice,
} from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import { useRemote } from '../../app/RemoteProvider'
import EmptyState from '../../components/ui/EmptyState'
import Modal from '../../components/ui/Modal'
import RestrictionNotice from '../../components/ui/RestrictionNotice'
import DeviceRow from './DeviceRow'
import type { DeviceListing, RemoteDevice } from '../../types/remote'
import { PERMISSIONS } from '../../types/remote'

/**
 * Registered computers (§52).
 *
 * The page an administrator uses to see which machines can be reached, which
 * of them will answer with nobody sitting at them, and to stop either — from
 * here, immediately, without needing the machine to cooperate.
 *
 * Three properties the screen has to hold on to:
 *
 *   * **unattended access is never implied.** It is a switch of its own, off
 *     until somebody deliberately turns it on, shown with what it costs, and
 *     revocable from this page and from the machine itself (§54);
 *   * **revocation is the server's**, so a revoked device stays revoked
 *     however many times it is reinstalled;
 *   * **what is offered comes from the policy the server returned**, not from
 *     what this browser hopes. The listing carries `canManage`,
 *     `canConnectUnattended` and the organisation's switches, and the buttons
 *     follow them.
 *
 * Devices belong to an organisation, so this is a company-scope screen. In a
 * personal scope there is nothing to list, and it says so rather than showing
 * an empty table.
 */

export default function DevicesPage() {
  const navigate = useNavigate()
  const { scopeType, companyId, can } = useRemote()

  const [listing, setListing] = useState<DeviceListing | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<RemoteApiError | null>(null)
  const [busyUuid, setBusyUuid] = useState<string | null>(null)
  const [confirming, setConfirming] = useState<{ device: RemoteDevice; action: 'unattended' | 'revoke' } | null>(
    null,
  )

  const load = useCallback(async () => {
    if (scopeType !== 'COMPANY' || companyId === null) {
      setListing(null)
      setLoading(false)

      return
    }

    setLoading(true)
    setError(null)

    try {
      setListing(await fetchDevices(companyId))
    } catch (err) {
      setError(
        err instanceof RemoteApiError
          ? err
          : new RemoteApiError('UNKNOWN', 'Registered computers could not be loaded.', 0),
      )
    } finally {
      setLoading(false)
    }
  }, [scopeType, companyId])

  useEffect(() => {
    void load()
  }, [load])

  /** Run one device action, then re-read the list so the row tells the truth. */
  const run = useCallback(
    async (uuid: string, action: () => Promise<unknown>) => {
      setBusyUuid(uuid)
      setError(null)

      try {
        await action()
        await load()
      } catch (err) {
        setError(
          err instanceof RemoteApiError
            ? err
            : new RemoteApiError('UNKNOWN', 'That could not be done.', 0),
        )
      } finally {
        setBusyUuid(null)
      }
    },
    [load],
  )

  /**
   * Start a session with nobody at the machine.
   *
   * The session that comes back is an ordinary Remote session, so the room is
   * the ordinary room. Nothing about it is hidden from the person whose
   * computer it is: the agent shows a session is running, and it is in their
   * history afterwards.
   */
  async function connect(device: RemoteDevice) {
    setBusyUuid(device.uuid)
    setError(null)

    try {
      const { session } = await connectToDevice(device.uuid)
      navigate(`/room/${session.uuid}`)
    } catch (err) {
      setError(
        err instanceof RemoteApiError
          ? err
          : new RemoteApiError('UNKNOWN', 'That computer could not be reached.', 0),
      )
      setBusyUuid(null)
    }
  }

  if (scopeType !== 'COMPANY' || companyId === null) {
    return (
      <div className="page">
        <div className="stack stack--lg">
          <header className="stack stack--sm">
            <h1>Computers</h1>
            <p className="muted">Machines running AICOUNTLY Remote for Windows.</p>
          </header>

          <EmptyState
            icon={<MonitorSmartphone size={26} />}
            title="Computers belong to an organisation"
            description="Switch to an organisation at the top of the page to see the computers registered to it."
          />
        </div>
      </div>
    )
  }

  const devices = listing?.devices ?? []
  const policy = listing?.policy
  const canManage = listing?.canManage ?? false
  const canConnect = (listing?.canConnectUnattended ?? false) && can(PERMISSIONS.UNATTENDED_ACCESS)

  return (
    <div className="page">
      <div className="stack stack--lg">
        <header className="stack stack--sm">
          <h1>Computers</h1>
          <p className="muted">
            Machines running AICOUNTLY Remote for Windows that are registered to this organisation.
          </p>
        </header>

        {error ? <RestrictionNotice error={error} /> : null}

        {policy && !policy.allowRemoteControl ? (
          <div className="notice notice--info notice--row">
            <ShieldAlert size={16} aria-hidden="true" />
            <span>
              Remote control is turned off for this organisation, so these computers can be seen here
              but not controlled. An administrator can change that in Remote policy.
            </span>
          </div>
        ) : null}

        <section className="card">
          <div className="card__header">
            <div>
              <h2 className="card__title">Registered computers</h2>
              <p className="card__subtitle">
                {devices.length === 0
                  ? 'None yet.'
                  : `${devices.length} computer${devices.length === 1 ? '' : 's'}.`}
              </p>
            </div>
          </div>

          {loading ? (
            <p className="muted">Loading…</p>
          ) : devices.length === 0 ? (
            <EmptyState
              icon={<Laptop size={26} />}
              title="No computers registered yet"
              description="Install AICOUNTLY Remote for Windows on a machine and sign in with an AICOUNTLY account. It appears here once it has registered."
            />
          ) : (
            <ul className="device-list">
              {devices.map((device) => (
                <DeviceRow
                  key={device.uuid}
                  device={device}
                  canManage={canManage}
                  canConnect={canConnect}
                  unattendedAllowed={policy?.allowUnattendedAccess ?? false}
                  busy={busyUuid === device.uuid}
                  onConnect={() => void connect(device)}
                  onEnableUnattended={() => setConfirming({ device, action: 'unattended' })}
                  onDisableUnattended={() =>
                    void run(device.uuid, () => disableUnattendedAccess(device.uuid))
                  }
                  onRevoke={() => setConfirming({ device, action: 'revoke' })}
                />
              ))}
            </ul>
          )}
        </section>
      </div>

      {/* Both confirmations say what actually happens rather than "are you
          sure". Turning unattended access on is the more consequential of the
          two, and it is the one people click through fastest. */}
      <Modal
        open={confirming?.action === 'unattended'}
        title="Allow connections with nobody at this computer"
        onClose={() => setConfirming(null)}
        footer={
          <>
            <button type="button" className="btn btn--secondary" onClick={() => setConfirming(null)}>
              Cancel
            </button>
            <button
              type="button"
              className="btn btn--primary"
              onClick={() => {
                const device = confirming?.device
                setConfirming(null)

                if (device) void run(device.uuid, () => enableUnattendedAccess(device.uuid))
              }}
            >
              Turn on unattended access
            </button>
          </>
        }
      >
        <p>
          People in this organisation who hold unattended access will be able to connect to{' '}
          <strong>{confirming?.device.deviceName}</strong> without anybody approving it at the machine.
        </p>
        <ul className="bullets">
          <li>Every connection is recorded in the audit trail, with who started it and when.</li>
          <li>AICOUNTLY Remote still shows on that computer that a session is running.</li>
          <li>
            It can be turned off again from this page, or by whoever is at the machine, at any time.
          </li>
        </ul>
      </Modal>

      <Modal
        open={confirming?.action === 'revoke'}
        title="Remove this computer"
        onClose={() => setConfirming(null)}
        footer={
          <>
            <button type="button" className="btn btn--secondary" onClick={() => setConfirming(null)}>
              Cancel
            </button>
            <button
              type="button"
              className="btn btn--danger"
              onClick={() => {
                const device = confirming?.device
                setConfirming(null)

                if (device) void run(device.uuid, () => revokeDevice(device.uuid))
              }}
            >
              Remove computer
            </button>
          </>
        }
      >
        <p>
          <strong>{confirming?.device.deviceName}</strong> will stop being able to connect
          immediately, and reinstalling AICOUNTLY Remote on it will not bring it back — somebody has
          to register it again from the machine.
        </p>
      </Modal>
    </div>
  )
}
