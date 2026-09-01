import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'

import { fetchSession, fetchSessionEvents } from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import StatusBadge from '../../components/ui/StatusBadge'
import RestrictionNotice from '../../components/ui/RestrictionNotice'
import type { SessionDetail, SessionEvent } from '../../types/remote'
import {
  describeEvent,
  describeScope,
  describeShareMode,
  describeSurface,
  formatDateTime,
  formatDuration,
} from '../../utils/format'

/**
 * One session, after the fact (§42, §95).
 *
 * Built to answer the questions an administrator actually asks: who started it,
 * who joined, which organisation and product it belonged to, what kind of
 * surface was shared, whether policy blocked anything, how long it lasted and
 * who ended it — without ever storing what was on the screen.
 */
export default function SessionDetailPage() {
  const { uuid = '' } = useParams()

  const [session, setSession] = useState<SessionDetail | null>(null)
  const [events, setEvents] = useState<SessionEvent[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<RemoteApiError | null>(null)

  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)

      try {
        const detail = await fetchSession(uuid)
        if (cancelled) return

        setSession(detail)

        // The timeline is a second request on purpose: it can be long, and the
        // header should not wait for it.
        const timeline = await fetchSessionEvents(uuid).catch(() => [])
        if (!cancelled) setEvents(timeline)
      } catch (err) {
        if (!cancelled) {
          setError(
            err instanceof RemoteApiError
              ? err
              : new RemoteApiError('UNKNOWN', 'This session could not be loaded.', 0),
          )
        }
      } finally {
        if (!cancelled) setLoading(false)
      }
    }

    void load()

    return () => {
      cancelled = true
    }
  }, [uuid])

  if (loading) {
    return (
      <div className="page" aria-busy="true">
        <div className="stack stack--lg">
          <div className="skeleton" style={{ height: 40, width: 200 }} />
          <div className="skeleton" style={{ height: 220 }} />
          <div className="skeleton" style={{ height: 320 }} />
        </div>
      </div>
    )
  }

  if (error || !session) {
    return (
      <div className="page">
        <RestrictionNotice
          error={error ?? new RemoteApiError('NOT_FOUND', 'That Remote session could not be found.', 404)}
          action={
            <Link to="/sessions" className="btn btn--secondary">
              Back to sessions
            </Link>
          }
        />
      </div>
    )
  }

  const policyBlocked = events.filter((event) => event.eventType === 'POLICY_REJECTED')

  return (
    <div className="page">
      <div className="stack stack--lg">
        <header className="stack stack--sm">
          <Link to="/sessions" className="back-link">
            <ArrowLeft size={15} aria-hidden="true" />
            All sessions
          </Link>

          <div className="row row--between row--wrap">
            <h1 className="mono">{session.displayId}</h1>
            <StatusBadge status={session.status} />
          </div>

          <p className="muted">
            {describeScope(session.scopeType, session.companyName)}
            {session.sourceProductLabel ? ` · ${session.sourceProductLabel}` : ''}
          </p>
        </header>

        {policyBlocked.length > 0 ? (
          <div className="notice notice--warning">
            <p className="notice__title">Organisation policy blocked an action in this session</p>
            <p className="notice__body">
              {policyBlocked.length} attempt{policyBlocked.length === 1 ? ' was' : 's were'} refused. The
              timeline below shows what and when.
            </p>
          </div>
        ) : null}

        <section className="card">
          <div className="card__header">
            <h2 className="card__title">Details</h2>
          </div>

          <div className="card__body detail-grid">
            <Fact label="Status" value={session.status} />
            <Fact label="Scope" value={session.scopeType} />
            <Fact label="Organisation" value={session.companyName ?? 'Personal (no organisation)'} />
            <Fact label="Session type" value={session.sessionType} />
            <Fact label="Started from" value={session.sourceProductLabel ?? '—'} />
            <Fact label="Area" value={session.sourceRoute ?? '—'} />
            <Fact label="Support ticket" value={session.supportTicketId ?? '—'} />
            <Fact label="Host" value={session.ownerName ?? '—'} />
            <Fact label="Requested sharing" value={describeShareMode(session.requestedShareMode)} />
            <Fact label="Surface shared" value={describeSurface(session.actualDisplaySurface)} />
            <Fact label="Started" value={formatDateTime(session.startedAt)} />
            <Fact label="Ended" value={formatDateTime(session.endedAt)} />
            <Fact label="Duration" value={formatDuration(session.durationSeconds)} />
            <Fact label="Ended because" value={session.endReason ?? '—'} />

            {/* Only present when the caller holds remote.audit.view (§42). */}
            {session.audit ? (
              <>
                <Fact label="Created from IP" value={session.audit.createdIp ?? '—'} />
                <Fact label="Browser" value={session.audit.createdUserAgent ?? '—'} />
              </>
            ) : null}
          </div>
        </section>

        <section className="card">
          <div className="card__header">
            <h2 className="card__title">Participants</h2>
          </div>

          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th scope="col">Name</th>
                  <th scope="col">Role</th>
                  <th scope="col">Joined</th>
                  <th scope="col">Left</th>
                  <th scope="col">Status</th>
                </tr>
              </thead>
              <tbody>
                {session.participants.map((participant) => (
                  <tr key={participant.uuid}>
                    <td>{participant.displayName}</td>
                    <td>{participant.role}</td>
                    <td>{formatDateTime(participant.joinedAt)}</td>
                    <td>{formatDateTime(participant.leftAt)}</td>
                    <td>
                      <StatusBadge status={participant.status} kind="participant" />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>

        <section className="card">
          <div className="card__header">
            <div>
              <h2 className="card__title">Timeline</h2>
              <p className="card__subtitle">
                What happened, and when. Screen contents are never recorded.
              </p>
            </div>
          </div>

          <div className="card__body">
            <ol className="timeline">
              {events.map((event, index) => (
                <li key={`${event.occurredAt}-${index}`} className="timeline__item">
                  <span className="timeline__marker" aria-hidden="true" />
                  <div className="timeline__body">
                    <p className="timeline__event">{describeEvent(event.eventType)}</p>
                    <p className="timeline__meta">
                      {formatDateTime(event.occurredAt)}
                      {event.actorName ? ` · ${event.actorName}` : ''}
                      {event.actorType === 'SYSTEM' ? ' · automatic' : ''}
                    </p>
                    {renderMetadata(event)}
                  </div>
                </li>
              ))}
            </ol>
          </div>
        </section>
      </div>
    </div>
  )
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div className="detail-grid__item">
      <dt>{label}</dt>
      <dd>{value}</dd>
    </div>
  )
}

/**
 * Event metadata, only where it means something.
 *
 * A raw JSON dump would be faithful and useless; these are the few keys that
 * answer a real question about a session.
 */
function renderMetadata(event: SessionEvent) {
  const parts: string[] = []

  const surface = event.metadata.displaySurface
  if (typeof surface === 'string') parts.push(describeSurface(surface))

  if (event.metadata.verified === false) parts.push('surface not verified by the browser')

  const reason = event.metadata.reason
  if (typeof reason === 'string') parts.push(reason.replace(/_/g, ' ').toLowerCase())

  const role = event.metadata.role
  if (typeof role === 'string') parts.push(role.replace(/_/g, ' ').toLowerCase())

  if (parts.length === 0) return null

  return <p className="timeline__detail">{parts.join(' · ')}</p>
}
