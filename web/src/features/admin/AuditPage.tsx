import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Download, ScrollText } from 'lucide-react'

import { exportAuditTrail, fetchAuditTrail } from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import { useRemote } from '../../app/RemoteProvider'
import EmptyState from '../../components/ui/EmptyState'
import Modal from '../../components/ui/Modal'
import RestrictionNotice from '../../components/ui/RestrictionNotice'
import { PERMISSIONS } from '../../types/remote'
import type { AuditChange, AuditEntry } from '../../types/remote'
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
 * whether policy blocked anything, who changed a setting or a permission and
 * from what to what — without ever having stored what was on the screen.
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
  'DEVICE_ENROLLED',
  'DEVICE_UPDATED',
  'DEVICE_REVOKED',
  'UNATTENDED_ACCESS_ENABLED',
  'UNATTENDED_ACCESS_DISABLED',
  'AUDIT_EXPORTED',
]

const SEVERITIES = ['security', 'material', 'info']

function changesOf(entry: AuditEntry): AuditChange[] {
  const changes = entry.metadata.changes
  return Array.isArray(changes) ? (changes as unknown[]).filter((c): c is AuditChange => typeof c === 'object' && c !== null && 'field' in c) : []
}

function show(value: unknown): string {
  if (value === null || value === undefined) return '(none)'
  if (typeof value === 'boolean') return value ? 'yes' : 'no'
  return String(value)
}

function snapshot(entry: AuditEntry, key: 'before' | 'after'): [string, unknown][] {
  const value = entry.metadata[key]
  return value && typeof value === 'object' && !Array.isArray(value) ? Object.entries(value as Record<string, unknown>) : []
}

export default function AuditPage() {
  const { companyId, scopeType, can, policy } = useRemote()
  const [params] = useSearchParams()

  const [entries, setEntries] = useState<AuditEntry[]>([])
  const [total, setTotal] = useState(0)
  const [offset, setOffset] = useState(0)
  const [event, setEvent] = useState('')
  const [severity, setSeverity] = useState('')
  const [q, setQ] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  // A "History" link on a record arrives here with the record already chosen.
  const [entityType] = useState(params.get('entityType') ?? '')
  const [entityId] = useState(params.get('entityId') ?? '')
  const [loading, setLoading] = useState(true)
  const [exporting, setExporting] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)
  const [error, setError] = useState<RemoteApiError | null>(null)
  const [selected, setSelected] = useState<AuditEntry | null>(null)

  const filters = useCallback(
    () => ({
      event: event || undefined,
      severity: severity || undefined,
      q: q || undefined,
      from: from || undefined,
      to: to || undefined,
      entityType: entityType || undefined,
      entityId: entityId || undefined,
    }),
    [event, severity, q, from, to, entityType, entityId],
  )

  const load = useCallback(async () => {
    if (companyId === null) {
      setLoading(false)

      return
    }

    setLoading(true)

    try {
      const { data, meta } = await fetchAuditTrail(companyId, { ...filters(), limit: PAGE_SIZE, offset })

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
  }, [companyId, filters, offset])

  useEffect(() => {
    void load()
  }, [load])

  const download = useCallback(async () => {
    if (companyId === null) return

    setExporting(true)
    setNotice(null)

    try {
      const { text, partial } = await exportAuditTrail(companyId, filters())
      const url = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }))
      const link = document.createElement('a')
      link.href = url
      link.download = `remote-audit-trail-${new Date().toISOString().slice(0, 10)}.csv`
      link.click()
      URL.revokeObjectURL(url)
      if (partial) setNotice('The export was cut at its row limit. Narrow the filters to get the rest.')
    } catch (err) {
      setError(
        err instanceof RemoteApiError
          ? err
          : new RemoteApiError('UNKNOWN', 'The audit trail could not be exported.', 0),
      )
    } finally {
      setExporting(false)
    }
  }, [companyId, filters])

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

  const reset = (apply: () => void) => {
    setOffset(0)
    apply()
  }

  return (
    <div className="page">
      <div className="stack stack--lg">
        <header className="row row--between row--wrap">
          <div className="stack stack--sm">
            <h1>Audit trail</h1>
            <p className="muted">
              Every Remote security event for {policy?.companyName ?? 'this organisation'}. Screen contents are
              never recorded.
              {entityType && entityId ? ` Showing the history of one ${entityType.replace(/_/g, ' ')}.` : ''}
            </p>
          </div>
          <button type="button" className="btn btn--secondary" disabled={exporting} onClick={() => void download()}>
            <Download size={15} aria-hidden="true" />
            {exporting ? 'Exporting…' : 'Export CSV'}
          </button>
        </header>

        {error ? <RestrictionNotice error={error} /> : null}
        {notice ? (
          <div className="notice notice--warning" role="status">
            <p className="notice__body">{notice}</p>
          </div>
        ) : null}

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
                onChange={(changeEvent) => reset(() => setEvent(changeEvent.target.value))}
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
              <label className="field__label" htmlFor="audit-severity">
                Importance
              </label>
              <select
                id="audit-severity"
                className="select"
                value={severity}
                onChange={(changeEvent) => reset(() => setSeverity(changeEvent.target.value))}
              >
                <option value="">All</option>
                {SEVERITIES.map((name) => (
                  <option key={name} value={name}>
                    {name.charAt(0).toUpperCase() + name.slice(1)}
                  </option>
                ))}
              </select>
            </div>

            <div className="field">
              <label className="field__label" htmlFor="audit-q">
                Search
              </label>
              <input
                id="audit-q"
                type="search"
                className="input"
                placeholder="Summary, name, device…"
                value={q}
                onChange={(changeEvent) => reset(() => setQ(changeEvent.target.value))}
              />
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
                onChange={(changeEvent) => reset(() => setFrom(changeEvent.target.value))}
              />
            </div>

            <div className="field">
              <label className="field__label" htmlFor="audit-to">
                To
              </label>
              <input
                id="audit-to"
                type="date"
                className="input"
                value={to}
                onChange={(changeEvent) => reset(() => setTo(changeEvent.target.value))}
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
                    <th scope="col">What</th>
                    <th scope="col">Who</th>
                    <th scope="col">
                      <span className="sr-only">Details</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {entries.map((entry) => (
                    <tr key={entry.uuid}>
                      <td>{formatDateTime(entry.createdAt)}</td>
                      <td>
                        <span
                          className={
                            entry.event === 'POLICY_REJECTED' || entry.severity === 'security'
                              ? 'audit-event audit-event--blocked'
                              : 'audit-event'
                          }
                        >
                          {describeEvent(entry.event)}
                        </span>
                      </td>
                      <td>
                        {entry.summary || '—'}
                        {entry.entityLabel ? <span className="tiny muted"> · {entry.entityLabel}</span> : null}
                      </td>
                      <td>
                        {entry.actorName ?? (entry.actorType === 'SYSTEM' ? 'AICOUNTLY Remote' : 'Guest')}
                      </td>
                      <td>
                        <button type="button" className="btn btn--secondary btn--sm" onClick={() => setSelected(entry)}>
                          Details
                        </button>
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

      <Modal
        open={selected !== null}
        title={selected ? describeEvent(selected.event) : ''}
        description={selected?.summary}
        onClose={() => setSelected(null)}
        size="lg"
      >
        {selected ? <AuditDetail entry={selected} /> : null}
      </Modal>
    </div>
  )
}

function AuditDetail({ entry }: { entry: AuditEntry }) {
  const changes = changesOf(entry)
  const before = snapshot(entry, 'before')
  const after = snapshot(entry, 'after')

  const facts: [string, string | null][] = [
    ['When', formatDateTime(entry.createdAt)],
    ['Who', entry.actorName ?? (entry.actorType === 'SYSTEM' ? 'AICOUNTLY Remote' : 'Guest')],
    ['Record', [entry.entityType, entry.entityLabel ?? entry.entityId].filter(Boolean).join(' · ') || null],
    ['Importance', entry.severity],
    ['Product', entry.sourceProduct],
    ['IP address', entry.ip],
    ['Request', entry.requestId],
  ]

  return (
    <div className="stack stack--md">
      <dl className="detail-list">
        {facts
          .filter(([, value]) => value)
          .map(([label, value]) => (
            <div key={label} className="detail-list__row">
              <dt className="muted">{label}</dt>
              <dd>{value}</dd>
            </div>
          ))}
      </dl>

      {changes.length > 0 ? (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th scope="col">Setting</th>
                <th scope="col">Was</th>
                <th scope="col">Now</th>
              </tr>
            </thead>
            <tbody>
              {changes.map((change) => (
                <tr key={change.field}>
                  <td>{change.label || change.field}</td>
                  <td>{show(change.old)}</td>
                  <td>{show(change.new)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <p className="muted tiny">No field-level changes were recorded for this event.</p>
      )}

      {before.length + after.length > 0 ? (
        <div className="row row--wrap" style={{ alignItems: 'flex-start' }}>
          {[
            ['Before', before],
            ['After', after],
          ].map(([title, rows]) => (
            <div key={title as string} className="stack stack--sm" style={{ flex: 1, minWidth: 200 }}>
              <h3>{title as string}</h3>
              <dl className="detail-list">
                {(rows as [string, unknown][]).map(([key, value]) => (
                  <div key={key} className="detail-list__row">
                    <dt className="muted">{key}</dt>
                    <dd>{show(value)}</dd>
                  </div>
                ))}
              </dl>
            </div>
          ))}
        </div>
      ) : null}
    </div>
  )
}
