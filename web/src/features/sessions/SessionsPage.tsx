import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { History } from 'lucide-react'

import { fetchSessionHistory } from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import { useRemote } from '../../app/RemoteProvider'
import StatusBadge from '../../components/ui/StatusBadge'
import EmptyState from '../../components/ui/EmptyState'
import RestrictionNotice from '../../components/ui/RestrictionNotice'
import type { RemoteSession } from '../../types/remote'
import type { SessionHistoryFilters as Filters } from '../../services/api/remote'
import { describeScope, formatDateTime, formatDuration } from '../../utils/format'

/**
 * Session history (§42).
 *
 * Two lists in one page, because "what is happening now" and "what happened"
 * are different questions: live sessions are pulled to the top with a way back
 * into them, and everything else is the filterable record underneath.
 *
 * IP addresses are absent by design — they belong to the audit trail, behind
 * `remote.audit.view`, not to an ordinary session list.
 */

const LIVE_STATUSES = ['WAITING', 'JOIN_REQUESTED', 'CONNECTING', 'ACTIVE', 'PAUSED', 'RECONNECTING']

const PAGE_SIZE = 25

export default function SessionsPage() {
  const { bootstrap } = useRemote()

  const [sessions, setSessions] = useState<RemoteSession[]>([])
  const [total, setTotal] = useState(0)
  const [offset, setOffset] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<RemoteApiError | null>(null)
  const [filters, setFilters] = useState<Filters>({ limit: PAGE_SIZE, offset: 0 })

  const load = useCallback(async (next: Filters) => {
    setLoading(true)
    setError(null)

    try {
      const { data, meta } = await fetchSessionHistory(next)
      setSessions(data ?? [])
      setTotal(Number(meta.total ?? 0))
    } catch (err) {
      setError(err instanceof RemoteApiError ? err : new RemoteApiError('UNKNOWN', 'History could not be loaded.', 0))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load({ ...filters, offset })
  }, [load, filters, offset])

  const live = sessions.filter((session) => LIVE_STATUSES.includes(session.status))
  const past = sessions.filter((session) => !LIVE_STATUSES.includes(session.status))

  function updateFilter(key: keyof Filters, value: string) {
    setOffset(0)
    setFilters((current) => ({ ...current, [key]: value || undefined, offset: 0 }))
  }

  return (
    <div className="page">
      <div className="stack stack--lg">
        <header className="stack stack--sm">
          <h1>Sessions</h1>
          <p className="muted">Remote sessions you took part in, and those your organisation permits you to see.</p>
        </header>

        {error ? <RestrictionNotice error={error} /> : null}

        {live.length > 0 ? (
          <section className="card">
            <div className="card__header">
              <div>
                <h2 className="card__title">Active now</h2>
                <p className="card__subtitle">Sessions you can rejoin.</p>
              </div>
            </div>

            <ul className="session-list">
              {live.map((session) => (
                <li key={session.uuid} className="session-list__item">
                  <Link to={`/room/${session.uuid}`} className="session-list__link">
                    <div className="session-list__main">
                      <span className="session-list__id mono">{session.displayId}</span>
                      <span className="session-list__context">
                        {describeScope(session.scopeType, session.companyName)}
                      </span>
                    </div>
                    <div className="session-list__meta">
                      <StatusBadge status={session.status} />
                      <span className="btn btn--secondary btn--sm">Rejoin</span>
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        ) : null}

        <section className="card">
          <div className="card__header">
            <div>
              <h2 className="card__title">History</h2>
              <p className="card__subtitle">{total} session{total === 1 ? '' : 's'}</p>
            </div>
          </div>

          <div className="card__body filters">
            <div className="field">
              <label className="field__label" htmlFor="filter-status">
                Status
              </label>
              <select
                id="filter-status"
                className="select"
                value={filters.status ?? ''}
                onChange={(event) => updateFilter('status', event.target.value)}
              >
                <option value="">All</option>
                <option value="ACTIVE">Active</option>
                <option value="ENDED">Completed</option>
                <option value="EXPIRED">Expired</option>
                <option value="DECLINED">Declined</option>
                <option value="FAILED">Failed</option>
              </select>
            </div>

            <div className="field">
              <label className="field__label" htmlFor="filter-scope">
                Scope
              </label>
              <select
                id="filter-scope"
                className="select"
                value={filters.scopeType ?? ''}
                onChange={(event) => updateFilter('scopeType', event.target.value)}
              >
                <option value="">All</option>
                <option value="PERSONAL">Personal</option>
                <option value="COMPANY">Company</option>
                <option value="AICOUNTLY_SUPPORT">AICOUNTLY Support</option>
              </select>
            </div>

            <div className="field">
              <label className="field__label" htmlFor="filter-company">
                Organisation
              </label>
              <select
                id="filter-company"
                className="select"
                value={String(filters.companyId ?? '')}
                onChange={(event) => updateFilter('companyId', event.target.value)}
              >
                <option value="">All</option>
                {(bootstrap?.companies ?? []).map((company) => (
                  <option key={company.companyId} value={company.companyId}>
                    {company.name}
                  </option>
                ))}
              </select>
            </div>

            <div className="field">
              <label className="field__label" htmlFor="filter-product">
                Product
              </label>
              <select
                id="filter-product"
                className="select"
                value={filters.sourceProduct ?? ''}
                onChange={(event) => updateFilter('sourceProduct', event.target.value)}
              >
                <option value="">All</option>
                <option value="BOOKS">AICOUNTLY Books</option>
                <option value="HRMS">AICOUNTLY HRMS</option>
                <option value="AUDITOR">AICOUNTLY Auditor</option>
                <option value="INVENTORY">AICOUNTLY Inventory</option>
                <option value="ADVISOR">AICOUNTLY Advisor</option>
              </select>
            </div>

            <div className="field">
              <label className="field__label" htmlFor="filter-from">
                From
              </label>
              <input
                id="filter-from"
                type="date"
                className="input"
                value={filters.from ?? ''}
                onChange={(event) => updateFilter('from', event.target.value)}
              />
            </div>
          </div>

          {loading ? (
            <div className="card__body stack stack--sm" aria-busy="true">
              {[0, 1, 2, 3].map((index) => (
                <div key={index} className="skeleton" style={{ height: 52 }} />
              ))}
            </div>
          ) : past.length === 0 ? (
            <div className="card__body">
              <EmptyState
                icon={<History size={24} />}
                title="No sessions yet"
                description="Your completed Remote sessions will appear here."
                action={
                  <Link to="/start" className="btn btn--primary">
                    Start a session
                  </Link>
                }
              />
            </div>
          ) : (
            <div className="table-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th scope="col">Session</th>
                    <th scope="col">Organisation</th>
                    <th scope="col">Product</th>
                    <th scope="col">Started</th>
                    <th scope="col">Duration</th>
                    <th scope="col">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {past.map((session) => (
                    <tr key={session.uuid}>
                      <td>
                        <Link className="mono" to={`/sessions/${session.uuid}`}>
                          {session.displayId}
                        </Link>
                      </td>
                      <td>{describeScope(session.scopeType, session.companyName)}</td>
                      <td>{session.sourceProductLabel ?? '—'}</td>
                      <td>{formatDateTime(session.startedAt ?? session.createdAt)}</td>
                      <td>{formatDuration(session.durationSeconds)}</td>
                      <td>
                        <StatusBadge status={session.status} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {total > PAGE_SIZE ? (
            <div className="card__footer row row--between">
              <button
                type="button"
                className="btn btn--secondary btn--sm"
                disabled={offset === 0}
                onClick={() => setOffset((current) => Math.max(0, current - PAGE_SIZE))}
              >
                Previous
              </button>

              <span className="tiny muted">
                {offset + 1}–{Math.min(offset + PAGE_SIZE, total)} of {total}
              </span>

              <button
                type="button"
                className="btn btn--secondary btn--sm"
                disabled={offset + PAGE_SIZE >= total}
                onClick={() => setOffset((current) => current + PAGE_SIZE)}
              >
                Next
              </button>
            </div>
          ) : null}
        </section>
      </div>
    </div>
  )
}
