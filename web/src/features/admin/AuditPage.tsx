import { useCallback, useEffect, useState } from 'react'
import { ScrollText } from 'lucide-react'

import { fetchAuditTrail } from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import { useRemote } from '../../app/RemoteProvider'
import EmptyState from '../../components/ui/EmptyState'
import RestrictionNotice from '../../components/ui/RestrictionNotice'
import { PERMISSIONS } from '../../types/remote'
import type { AuditEntry } from '../../types/remote'
import { describeEvent, formatDateTime } from '../../utils/format'

/**
 * The audit trail (§60, §95).
 *
 * Behind `remote.audit.view`, which is also what unlocks the IP address and
 * browser columns. An ordinary user never reaches this page and never sees
 * either.
 *
 * It exists to answer specific questions — who started a session, who joined,
 * which product launched it, when sharing began, what surface was shared,
 * whether policy blocked anything, who ended it — without ever having stored
 * what was on the screen.
 */

const PAGE_SIZE = 50

const EVENT_FILTERS = [
  'SESSION_CREATED',
  'PARTICIPANT_APPROVED',
  'PARTICIPANT_DENIED',
  'SCREEN_SHARE_STARTED',
  'SCREEN_SHARE_STOPPED',
  'POLICY_REJECTED',
  'COMPANY_CONTEXT_MISMATCH',
  'SESSION_ENDED',
  'POLICY_UPDATED',
  'PERMISSION_UPDATED',
]

export default function AuditPage() {
  const { companyId, scopeType, can, policy } = useRemote()

  const [entries, setEntries] = useState<AuditEntry[]>([])
  const [total, setTotal] = useState(0)
  const [offset, setOffset] = useState(0)
  const [event, setEvent] = useState('')
  const [from, setFrom] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<RemoteApiError | null>(null)

  const load = useCallback(async () => {
    if (companyId === null) {
      setLoading(false)

      return
    }

    setLoading(true)

    try {
      const { data, meta } = await fetchAuditTrail(companyId, {
        event: event || undefined,
        from: from || undefined,
        limit: PAGE_SIZE,
        offset,
      })

      setEntries(data ?? [])
      setTotal(Number(meta.total ?? 0))
      setError(null)
    } catch (err) {
      setError(
        err instanceof RemoteApiError
          ? err
          : new RemoteApiError('UNKNOWN', 'The audit trail could not be loaded.', 0),
      )
    } finally {
      setLoading(false)
    }
  }, [companyId, event, from, offset])

  useEffect(() => {
    void load()
  }, [load])

  if (scopeType === 'PERSONAL' || companyId === null) {
    return (
      <div className="page">
        <EmptyState
          title="Choose an organisation"
          description="The audit trail belongs to an organisation. Switch from Personal using the selector at the top of the page."
        />
      </div>
    )
  }

  if (!can(PERMISSIONS.AUDIT_VIEW)) {
    return (
      <div className="page">
        <RestrictionNotice
          error={
            new RemoteApiError(
              'ADMIN_PERMISSION_DENIED',
              'You do not have permission to view the Remote audit trail for this organisation.',
              403,
            )
          }
        />
      </div>
    )
  }

  return (
    <div className="page">
      <div className="stack stack--lg">
        <header className="stack stack--sm">
          <h1>Audit trail</h1>
          <p className="muted">
            Every Remote security event for {policy?.companyName ?? 'this organisation'}. Screen contents are
            never recorded.
          </p>
        </header>

        {error ? <RestrictionNotice error={error} /> : null}

        <section className="card">
          <div className="card__body filters">
            <div className="field">
              <label className="field__label" htmlFor="audit-event">
                Event
              </label>
              <select
                id="audit-event"
                className="select"
                value={event}
                onChange={(changeEvent) => {
                  setOffset(0)
                  setEvent(changeEvent.target.value)
                }}
              >
                <option value="">All events</option>
                {EVENT_FILTERS.map((name) => (
                  <option key={name} value={name}>
                    {describeEvent(name)}
                  </option>
                ))}
              </select>
            </div>

            <div className="field">
              <label className="field__label" htmlFor="audit-from">
                From
              </label>
              <input
                id="audit-from"
                type="date"
                className="input"
                value={from}
                onChange={(changeEvent) => {
                  setOffset(0)
                  setFrom(changeEvent.target.value)
                }}
              />
            </div>
          </div>

          {loading ? (
            <div className="card__body stack stack--sm" aria-busy="true">
              {[0, 1, 2, 3].map((index) => (
                <div key={index} className="skeleton" style={{ height: 44 }} />
              ))}
            </div>
          ) : entries.length === 0 ? (
            <div className="card__body">
              <EmptyState
                icon={<ScrollText size={24} />}
                title="Nothing recorded yet"
                description="Remote security events for this organisation will appear here."
              />
            </div>
          ) : (
            <div className="table-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th scope="col">When</th>
                    <th scope="col">Event</th>
                    <th scope="col">Who</th>
                    <th scope="col">Session</th>
                    <th scope="col">Product</th>
                    <th scope="col">IP</th>
                  </tr>
                </thead>
                <tbody>
                  {entries.map((entry) => (
                    <tr key={entry.uuid}>
                      <td>{formatDateTime(entry.createdAt)}</td>
                      <td>
                        <span className={entry.event === 'POLICY_REJECTED' ? 'audit-event audit-event--blocked' : 'audit-event'}>
                          {describeEvent(entry.event)}
                        </span>
                      </td>
                      <td>
                        {entry.actorName ?? (entry.actorType === 'SYSTEM' ? 'AICOUNTLY Remote' : 'Guest')}
                      </td>
                      <td className="mono tiny">{entry.sessionUuid?.slice(0, 8) ?? '—'}</td>
                      <td>{entry.sourceProduct ?? '—'}</td>
                      <td className="mono tiny">{entry.ip ?? '—'}</td>
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
