import { useEffect, useState } from 'react'

import { fetchDeviceHistory } from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import type { DeviceHistory as History } from '../../types/remote'
import { formatDateTime, formatDuration } from '../../utils/format'

/**
 * What a computer has been through: every session it took part in, and every
 * thing that happened to it — registered, unattended access switched on or
 * off, signed out.
 *
 * Two lists rather than one merged timeline, because they answer different
 * questions. "Who has been on this computer, and did they control it?" is the
 * one somebody asks after the fact; "when was unattended access turned on,
 * and by whom?" is the one they ask when something surprised them.
 */
export default function DeviceHistory({
  deviceUuid,
  load = fetchDeviceHistory,
}: {
  deviceUuid: string
  /** Injectable for tests. */
  load?: (uuid: string) => Promise<History>
}) {
  const [history, setHistory] = useState<History | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    setHistory(null)
    setError(null)

    load(deviceUuid).then(
      (found) => {
        if (!cancelled) setHistory(found)
      },
      (err: unknown) => {
        if (!cancelled) setError(err instanceof RemoteApiError ? err.message : 'The history could not be loaded.')
      },
    )

    return () => {
      cancelled = true
    }
  }, [deviceUuid, load])

  if (error) return <p className="notice notice--danger">{error}</p>
  if (!history) return <p className="muted">Loading…</p>

  return (
    <div className="stack">
      <section aria-labelledby="device-history-sessions">
        <h3 id="device-history-sessions" className="device-history__title">
          Connections
        </h3>
        {history.sessions.length === 0 ? (
          <p className="muted">No sessions yet.</p>
        ) : (
          <ul className="device-history__list">
            {history.sessions.map((session) => (
              <li key={session.uuid} className="device-history__item">
                <span className="device-history__when">{formatDateTime(session.startedAt)}</span>
                <span>
                  <strong>{session.peers.length > 0 ? session.peers.join(', ') : 'Nobody joined'}</strong>
                  {' · '}
                  {session.accessMode === 'UNATTENDED' ? 'Unattended' : 'Attended'}
                  {session.role === 'COMPANION' ? ', control companion' : ''}
                  {session.controlled ? ', controlled' : ', viewed only'}
                </span>
                <span className="muted">
                  {session.durationSeconds !== null ? formatDuration(session.durationSeconds) : session.status.toLowerCase()}
                  {' · '}
                  <span className="mono">{session.displayId}</span>
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section aria-labelledby="device-history-events">
        <h3 id="device-history-events" className="device-history__title">
          Changes
        </h3>
        {history.events.length === 0 ? (
          <p className="muted">Nothing recorded yet.</p>
        ) : (
          <ul className="device-history__list">
            {history.events.map((event, index) => (
              <li key={`${event.event}-${event.at}-${index}`} className="device-history__item">
                <span className="device-history__when">{formatDateTime(event.at)}</span>
                <span>{event.summary}</span>
                {event.actorName ? <span className="muted">{event.actorName}</span> : null}
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}
