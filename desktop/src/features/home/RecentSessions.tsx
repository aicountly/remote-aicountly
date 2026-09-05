import type { SessionSummary } from '../../types/agent'
import { formatDate } from '../unattended/UnattendedCard'

/**
 * The sessions that have happened on this computer.
 *
 * Held in this process for the life of the run rather than fetched. It is a
 * convenience, not the record — the durable one is the session history and the
 * audit trail in AICOUNTLY Remote, which is what an administrator reads and
 * which this application cannot alter.
 */
export function RecentSessions({ sessions }: { sessions: SessionSummary[] }) {
  return (
    <section className="card">
      <h2 className="card__title">Recent sessions</h2>
      <p className="card__subtitle">
        Since this application started. The full record is in AICOUNTLY Remote.
      </p>

      {sessions.length === 0 ? (
        <p className="empty">No sessions yet.</p>
      ) : (
        <div className="card__rows">
          {sessions.map((session) => (
            <div className="row" key={`${session.sessionUuid}-${session.startedAt}`}>
              <span className="row__label">
                <strong style={{ color: 'var(--text-primary)' }}>{session.connectedName}</strong>
                {session.unattended ? ' · unattended' : ''}
                {session.companyName ? ` · ${session.companyName}` : ''}
              </span>
              <span className="row__value" style={{ fontWeight: 400 }}>
                {formatDate(session.startedAt)}
              </span>
            </div>
          ))}
        </div>
      )}
    </section>
  )
}
