import { useCallback, useEffect, useState } from 'react'

import * as bridge from '../../services/tauri'
import type { OwnComputer, OwnComputers } from '../../types/agent'
import { formatDate } from '../unattended/UnattendedCard'

/** How often the list is read again while it is on screen. */
const REFRESH_MS = 30_000

/** "2 computers registered · 1 online". */
export function describeComputers(list: OwnComputers): string {
  const count = list.count || list.computers.length

  if (count === 0) return 'None registered yet.'

  const online = list.computers.filter((computer) => computer.online).length

  return `${count} ${count === 1 ? 'computer' : 'computers'} registered · ${online} online`
}

/** What a computer row can offer: connect, or why not. */
export function connectability(computer: OwnComputer): { canConnect: boolean; reason: string | null } {
  if (computer.isThisComputer) return { canConnect: false, reason: null }
  if (!computer.unattendedAccessEnabled) {
    return { canConnect: false, reason: 'Unattended access is off. Turn it on at that computer, or in Remote → Computers.' }
  }
  if (!computer.online) {
    return {
      canConnect: false,
      reason: computer.lastSeenAt ? `Offline · last seen ${formatDate(computer.lastSeenAt)}` : 'Offline',
    }
  }

  return { canConnect: true, reason: null }
}

/**
 * The signed-in person's computers, read with this computer's own device
 * credential (`GET /devices/me/computers`), so the list can only ever be its
 * owner's.
 *
 * **Connect** opens the computer's session in Remote, inside this window: the
 * picture and the controls are the web room's, the same as in a browser, and
 * the computer at the other end shows that somebody is connected for as long
 * as they are.
 */
export function YourComputers({
  onConnect,
  onManage,
}: {
  onConnect: (computer: OwnComputer) => void
  onManage: () => void
}) {
  const [list, setList] = useState<OwnComputers | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setList(await bridge.listComputers())
      setError(null)
    } catch (caught) {
      setError(typeof caught === 'string' ? caught : caught instanceof Error ? caught.message : 'Your computers could not be read.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load()
    const timer = setInterval(() => void load(), REFRESH_MS)

    return () => clearInterval(timer)
  }, [load])

  return (
    <section className="card" aria-labelledby="your-computers-title">
      <div className="card__header">
        <div>
          <h2 className="card__title" id="your-computers-title">
            Your computers
          </h2>
          <p className="card__subtitle">{list ? describeComputers(list) : loading ? 'Reading your computers…' : ' '}</p>
        </div>
        <button type="button" className="btn btn--secondary btn--small" onClick={() => void load()} disabled={loading}>
          Refresh
        </button>
      </div>

      {error ? <div className="notice notice--danger">{error}</div> : null}

      {list && list.computers.length > 0 ? (
        <ul className="computer-list">
          {list.computers.map((computer) => {
            const { canConnect, reason } = connectability(computer)

            return (
              <li className="computer" key={computer.uuid}>
                <span
                  className={`computer__dot${computer.online ? ' computer__dot--online' : ''}`}
                  aria-hidden="true"
                />
                <div className="computer__text">
                  <p className="computer__name">
                    {computer.deviceName}
                    {computer.isThisComputer ? <span className="pill pill--info">This computer</span> : null}
                  </p>
                  <p className="computer__facts">
                    {[
                      computer.operatingSystem,
                      computer.online ? 'Online' : 'Offline',
                      computer.unattendedAccessEnabled ? 'Unattended access on' : 'Unattended access off',
                    ].join(' · ')}
                  </p>
                  {reason ? <p className="field__hint">{reason}</p> : null}
                </div>
                {computer.isThisComputer ? null : (
                  <button
                    type="button"
                    className="btn btn--primary btn--small"
                    onClick={() => onConnect(computer)}
                    disabled={!canConnect}
                  >
                    Connect
                  </button>
                )}
              </li>
            )
          })}
        </ul>
      ) : list ? (
        <p className="empty">
          Sign in to AICOUNTLY Remote on another computer you own, and turn on unattended access there, to connect to
          it from here.
        </p>
      ) : null}

      <div className="card__footer">
        <button type="button" className="link-button" onClick={onManage}>
          Rename, see the history of, or remove a computer in Remote → Computers
        </button>
      </div>
    </section>
  )
}
