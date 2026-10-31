import { useCallback, useEffect, useState } from 'react'

import * as bridge from '../../services/tauri'
import type { DeviceHistory, HistorySession, SessionSummary } from '../../types/agent'
import { formatDate } from '../unattended/UnattendedCard'
import { RecentSessions } from './RecentSessions'

/** `3725` → `1 h 2 min`; under a minute reads `under a minute`. */
export function formatDuration(seconds: number | null): string | null {
  if (seconds === null || seconds < 0) return null
  if (seconds < 60) return 'under a minute'

  const minutes = Math.round(seconds / 60)
  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60

  if (hours === 0) return `${minutes} min`

  return rest === 0 ? `${hours} h` : `${hours} h ${rest} min`
}

/** "Unattended · Sam · controlled · 12 min" */
export function describeSession(session: HistorySession): string {
  const parts = [
    session.accessMode === 'UNATTENDED' ? 'Unattended' : session.role === 'COMPANION' ? 'Control companion' : 'Attended',
    session.peers.length > 0 ? session.peers.join(', ') : 'Nobody joined',
  ]

  if (session.controlled) parts.push('controlled')

  const duration = formatDuration(session.durationSeconds)
  if (duration) parts.push(duration)

  return parts.join(' · ')
}

/**
 * Who has been on this computer, and what changed about it — from the
 * server's record, which this application cannot alter. Falls back to the
 * sessions this run has seen when the record cannot be read.
 */
export function ComputerHistory({ recent }: { recent: SessionSummary[] }) {
  const [history, setHistory] = useState<DeviceHistory | null>(null)
  const [failed, setFailed] = useState(false)

  const load = useCallback(async () => {
    try {
      setHistory(await bridge.computerHistory(20))
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  if (failed && !history) return <RecentSessions sessions={recent} />

  return (
    <section className="card" aria-labelledby="computer-history-title">
      <div className="card__header">
        <div>
          <h2 className="card__title" id="computer-history-title">
            History
          </h2>
          <p className="card__subtitle">Connections to this computer and changes to it, newest first.</p>
        </div>
        <button type="button" className="btn btn--secondary btn--small" onClick={() => void load()}>
          Refresh
        </button>
      </div>

      {!history ? (
        <p className="empty">Reading the history…</p>
      ) : (
        <>
          <h3 className="help__list-title">Connections</h3>
          {history.sessions.length === 0 ? (
            <p className="empty">Nobody has connected to this computer yet.</p>
          ) : (
            <div className="card__rows">
              {history.sessions.map((session) => (
                <div className="row" key={session.uuid}>
                  <span className="row__label">{describeSession(session)}</span>
                  <span className="row__value" style={{ fontWeight: 400 }}>
                    {formatDate(session.startedAt)}
                  </span>
                </div>
              ))}
            </div>
          )}

          <h3 className="help__list-title">Changes</h3>
          {history.events.length === 0 ? (
            <p className="empty">No changes recorded.</p>
          ) : (
            <div className="card__rows">
              {history.events.map((event, index) => (
                <div className="row" key={`${event.event}-${event.at ?? index}`}>
                  <span className="row__label">
                    {event.summary}
                    {event.actorName ? ` · ${event.actorName}` : ''}
                  </span>
                  <span className="row__value" style={{ fontWeight: 400 }}>
                    {formatDate(event.at)}
                  </span>
                </div>
              ))}
            </div>
          )}
        </>
      )}
    </section>
  )
}
