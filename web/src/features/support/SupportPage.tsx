import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { LifeBuoy } from 'lucide-react'

import {
  acceptSupportRequest,
  cancelSupportRequest,
  createSupportRequest,
  declineSupportRequest,
  fetchSupportRequests,
} from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import { useRemote } from '../../app/RemoteProvider'
import EmptyState from '../../components/ui/EmptyState'
import StatusBadge from '../../components/ui/StatusBadge'
import RestrictionNotice from '../../components/ui/RestrictionNotice'
import ShareModePicker from '../sessions/ShareModePicker'
import { PERMISSIONS } from '../../types/remote'
import type { ShareMode, SupportRequest } from '../../types/remote'
import { formatWaiting } from '../../utils/format'

/**
 * AICOUNTLY Support: asking for help, and answering it (§24).
 *
 * One page with two faces. A customer sees the request form and their own
 * requests; a technician sees the queue. Which one you get is decided by
 * `remote.support.accept`, resolved on the server — not by a role string the
 * browser could change.
 */
export default function SupportPage() {
  const { bootstrap, policy, scopeType, companyId, can } = useRemote()
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()

  const [requests, setRequests] = useState<SupportRequest[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<RemoteApiError | null>(null)
  const [creating, setCreating] = useState(searchParams.get('new') === '1')
  const [shareMode, setShareMode] = useState<ShareMode>('SAFE_SHARE')
  const [summary, setSummary] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const isTechnician = bootstrap?.user.isSupportAgent ?? false

  const load = useCallback(async () => {
    setLoading(true)

    try {
      const { data } = await fetchSupportRequests({ limit: 50 })
      setRequests(data ?? [])
      setError(null)
    } catch (err) {
      setError(
        err instanceof RemoteApiError
          ? err
          : new RemoteApiError('UNKNOWN', 'Support requests could not be loaded.', 0),
      )
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  // A waiting queue is the one place a periodic refresh earns its keep: the
  // customer on the other end is watching a timer.
  useEffect(() => {
    if (!isTechnician) return

    const timer = setInterval(() => void load(), 15_000)

    return () => clearInterval(timer)
  }, [isTechnician, load])

  async function submitRequest(event: React.FormEvent) {
    event.preventDefault()
    if (submitting) return

    setSubmitting(true)
    setError(null)

    try {
      const result = await createSupportRequest({
        companyId: scopeType === 'PERSONAL' ? null : companyId,
        requestedShareMode: shareMode,
        issueSummary: summary.trim() || null,
      })

      navigate(`/room/${result.session.uuid}`)
    } catch (err) {
      setError(
        err instanceof RemoteApiError
          ? err
          : new RemoteApiError('UNKNOWN', 'The support request could not be created.', 0),
      )
      setSubmitting(false)
    }
  }

  async function accept(uuid: string) {
    try {
      const result = await acceptSupportRequest(uuid)
      navigate(`/room/${result.session.uuid}`)
    } catch (err) {
      setError(err instanceof RemoteApiError ? err : null)
      await load()
    }
  }

  const pending = requests.filter((request) => request.status === 'PENDING')
  const rest = requests.filter((request) => request.status !== 'PENDING')

  return (
    <div className="page">
      <div className="stack stack--lg">
        <header className="row row--between row--wrap">
          <div className="stack stack--sm">
            <h1>Support requests</h1>
            <p className="muted">
              {isTechnician
                ? 'Customers waiting for AICOUNTLY Support.'
                : 'Ask AICOUNTLY to look at your screen, with your permission.'}
            </p>
          </div>

          {!creating && can(PERMISSIONS.SUPPORT_REQUEST) ? (
            <button
              type="button"
              className="btn btn--primary"
              onClick={() => {
                setCreating(true)
                setSearchParams({ new: '1' })
              }}
            >
              <LifeBuoy size={16} aria-hidden="true" />
              Request support
            </button>
          ) : null}
        </header>

        {error ? <RestrictionNotice error={error} /> : null}

        {creating && policy ? (
          <section className="card">
            <div className="card__header">
              <div>
                <h2 className="card__title">Request AICOUNTLY Support</h2>
                <p className="card__subtitle">
                  A technician will ask to join, and you decide whether to admit them.
                </p>
              </div>
            </div>

            <form className="card__body stack" onSubmit={submitRequest}>
              <dl className="consent-facts">
                <div>
                  <dt>Product</dt>
                  <dd>{bootstrap?.launchContext?.product ?? 'AICOUNTLY Remote'}</dd>
                </div>
                <div>
                  <dt>Organisation</dt>
                  <dd>{policy.companyName ?? 'Personal (no organisation)'}</dd>
                </div>
                {bootstrap?.launchContext?.route ? (
                  <div>
                    <dt>Current area</dt>
                    <dd>{bootstrap.launchContext.route}</dd>
                  </div>
                ) : null}
              </dl>

              <div className="field">
                <label className="field__label" htmlFor="issue-summary">
                  What do you need help with?
                </label>
                <textarea
                  id="issue-summary"
                  className="textarea"
                  value={summary}
                  onChange={(event) => setSummary(event.target.value)}
                  maxLength={2000}
                  placeholder="A short description helps the technician prepare."
                />
              </div>

              <ShareModePicker policy={policy} value={shareMode} onChange={setShareMode} />

              <div className="row row--between row--wrap">
                <button
                  type="button"
                  className="btn btn--ghost"
                  onClick={() => {
                    setCreating(false)
                    setSearchParams({})
                  }}
                >
                  Cancel
                </button>
                <button type="submit" className="btn btn--primary" disabled={submitting}>
                  {submitting ? 'Creating…' : 'Request support'}
                </button>
              </div>
            </form>
          </section>
        ) : null}

        <section className="card">
          <div className="card__header">
            <div>
              <h2 className="card__title">{isTechnician ? 'Waiting' : 'Your requests'}</h2>
              <p className="card__subtitle">
                {pending.length} waiting{isTechnician ? ' across AICOUNTLY' : ''}
              </p>
            </div>
          </div>

          {loading ? (
            <div className="card__body stack stack--sm" aria-busy="true">
              {[0, 1].map((index) => (
                <div key={index} className="skeleton" style={{ height: 76 }} />
              ))}
            </div>
          ) : pending.length === 0 ? (
            <div className="card__body">
              <EmptyState
                title={isTechnician ? 'You’re all caught up' : 'No open requests'}
                description={
                  isTechnician
                    ? 'There are no Remote support requests waiting for your attention.'
                    : 'When you ask AICOUNTLY for help, your request will appear here.'
                }
              />
            </div>
          ) : (
            <ul className="request-list">
              {pending.map((request) => (
                <li key={request.uuid} className="request">
                  <div className="request__body">
                    <p className="request__company">{request.companyName ?? 'Personal session'}</p>
                    <p className="request__requester">{request.requesterName}</p>
                    <p className="request__context">
                      {request.sourceProductLabel ?? 'AICOUNTLY Remote'}
                      {request.sourceRoute ? ` · ${request.sourceRoute}` : ''}
                    </p>
                    {request.issueSummary ? <p className="request__summary">{request.issueSummary}</p> : null}
                  </div>

                  <div className="request__meta">
                    <span className="request__waiting mono" aria-label="Waiting time">
                      Waiting {formatWaiting(request.createdAt)}
                    </span>

                    {isTechnician ? (
                      <div className="row">
                        <button
                          type="button"
                          className="btn btn--secondary btn--sm"
                          onClick={() => void declineSupportRequest(request.uuid).then(load)}
                        >
                          Decline
                        </button>
                        <button
                          type="button"
                          className="btn btn--primary btn--sm"
                          onClick={() => void accept(request.uuid)}
                        >
                          Accept request
                        </button>
                      </div>
                    ) : (
                      <div className="row">
                        <button
                          type="button"
                          className="btn btn--secondary btn--sm"
                          onClick={() => void cancelSupportRequest(request.uuid).then(load)}
                        >
                          Cancel
                        </button>
                        {request.sessionUuid ? (
                          <button
                            type="button"
                            className="btn btn--primary btn--sm"
                            onClick={() => navigate(`/room/${request.sessionUuid}`)}
                          >
                            Open session
                          </button>
                        ) : null}
                      </div>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </section>

        {rest.length > 0 ? (
          <section className="card">
            <div className="card__header">
              <h2 className="card__title">Earlier requests</h2>
            </div>

            <div className="table-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th scope="col">Organisation</th>
                    <th scope="col">Requested by</th>
                    <th scope="col">Product</th>
                    <th scope="col">Session</th>
                    <th scope="col">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {rest.map((request) => (
                    <tr key={request.uuid}>
                      <td>{request.companyName ?? 'Personal'}</td>
                      <td>{request.requesterName}</td>
                      <td>{request.sourceProductLabel ?? '—'}</td>
                      <td className="mono">{request.sessionDisplayId ?? '—'}</td>
                      <td>
                        <StatusBadge status={request.status} kind="support" />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        ) : null}
      </div>
    </div>
  )
}
