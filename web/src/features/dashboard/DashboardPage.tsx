import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { LifeBuoy, Link2, MonitorUp, ShieldOff } from 'lucide-react'

import { useRemote } from '../../app/RemoteProvider'
import { PERMISSIONS } from '../../types/remote'
import type { RemoteSession } from '../../types/remote'
import StatusBadge from '../../components/ui/StatusBadge'
import EmptyState from '../../components/ui/EmptyState'
import RestrictionNotice from '../../components/ui/RestrictionNotice'
import { RemoteApiError } from '../../services/api/client'
import { describeScope, formatDuration, formatRelative } from '../../utils/format'
import { describeCaptureLimitation } from '../../services/browser/capabilities'
import WelcomeCard from './WelcomeCard'

/**
 * The dashboard (§31, §68, §88).
 *
 * Deliberately operational rather than analytical: three actions, three numbers
 * that mean something, and the sessions this person actually took part in. No
 * decorative charts — a support session that is waiting right now is worth more
 * than a sparkline of last month.
 */
export default function DashboardPage() {
  const { bootstrap, policy, scopeType, capabilities, can, status, error, refresh } = useRemote()
  const navigate = useNavigate()
  const [greeting, setGreeting] = useState('Hello')

  useEffect(() => {
    const hour = new Date().getHours()
    setGreeting(hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening')
  }, [])

  if (status === 'loading') {
    return (
      <div className="page" aria-busy="true">
        <span className="sr-only">Loading your dashboard…</span>
        <div className="stack stack--lg">
          <div className="skeleton" style={{ height: 96 }} />
          <div className="skeleton" style={{ height: 120 }} />
          <div className="skeleton" style={{ height: 260 }} />
        </div>
      </div>
    )
  }

  if (status === 'error') {
    return (
      <div className="page">
        <RestrictionNotice
          error={error ?? new RemoteApiError('UNKNOWN', 'AICOUNTLY Remote could not be loaded.', 0)}
          action={
            <button type="button" className="btn btn--secondary" onClick={() => void refresh()}>
              Try again
            </button>
          }
        />
      </div>
    )
  }

  const metrics = bootstrap?.metrics
  const recent = bootstrap?.recentSessions ?? []
  const firstName = (bootstrap?.user.displayName ?? '').split(' ')[0] || 'there'

  const captureLimitation = describeCaptureLimitation(capabilities)
  const canShare = can(PERMISSIONS.SCREEN_SHARE) && (policy?.allowedShareModes.length ?? 0) > 0

  // §39 — when the organisation has switched Remote off, say so properly rather
  // than showing buttons that will refuse.
  if (policy && !policy.remoteEnabled) {
    return (
      <div className="page">
        <div className="card">
          <div className="card__body">
            <EmptyState
              icon={<ShieldOff size={26} />}
              title="Remote assistance is restricted"
              description={`${policy.companyName ?? 'This organisation'} has turned off AICOUNTLY Remote for your account. You can still contact your administrator or raise a support request through your AICOUNTLY product.`}
            />
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="page">
      <div className="stack stack--lg">
        <WelcomeCard />

        <header className="dashboard__intro">
          <h1>
            {greeting}, {firstName}
          </h1>
          <p className="muted">Secure assistance when you need it.</p>
        </header>

        {captureLimitation ? (
          <div className="notice notice--info" role="status">
            <p className="notice__title">Screen sharing isn’t available in this browser</p>
            <p className="notice__body">
              {captureLimitation} Joining a session, chatting and viewing a shared screen all still work.
            </p>
          </div>
        ) : null}

        <div className="dashboard__actions">
          <button
            type="button"
            className="action-card action-card--primary"
            onClick={() => navigate('/start')}
            disabled={!canShare}
            aria-describedby={!canShare ? 'share-restricted' : undefined}
          >
            <MonitorUp size={20} aria-hidden="true" />
            <span className="action-card__label">Share my screen</span>
            <span className="action-card__hint">
              {canShare ? 'Start a secure session someone can view' : 'Not permitted for your account'}
            </span>
          </button>

          <button type="button" className="action-card" onClick={() => navigate('/join')}>
            <Link2 size={20} aria-hidden="true" />
            <span className="action-card__label">Join session</span>
            <span className="action-card__hint">Enter a session code or open an invitation</span>
          </button>

          {can(PERMISSIONS.SUPPORT_REQUEST) ? (
            <button type="button" className="action-card" onClick={() => navigate('/support?new=1')}>
              <LifeBuoy size={20} aria-hidden="true" />
              <span className="action-card__label">Request AICOUNTLY Support</span>
              <span className="action-card__hint">
                {scopeType === 'PERSONAL' ? 'For your personal workspace' : `For ${policy?.companyName ?? 'your organisation'}`}
              </span>
            </button>
          ) : null}
        </div>

        {!canShare ? (
          <p id="share-restricted" className="sr-only">
            Screen sharing is not permitted for your account in this organisation.
          </p>
        ) : null}

        <section className="metric-row" aria-label="Remote activity">
          <Metric label="Active sessions" value={metrics?.activeSessions ?? 0} />
          <Metric label="Support requests waiting" value={metrics?.pendingSupportRequests ?? 0} />
          <Metric label="Sessions this month" value={metrics?.sessionsThisMonth ?? 0} />
          <Metric
            label="Average duration"
            value={metrics?.averageDurationSeconds ? formatDuration(metrics.averageDurationSeconds) : '—'}
          />
        </section>

        <section className="card">
          <div className="card__header">
            <div>
              <h2 className="card__title">Recent sessions</h2>
              <p className="card__subtitle">The Remote sessions you took part in most recently.</p>
            </div>
            <Link to="/sessions" className="btn btn--secondary btn--sm">
              View all
            </Link>
          </div>

          {recent.length === 0 ? (
            <div className="card__body">
              <EmptyState
                title="No sessions yet"
                description="Your completed Remote sessions will appear here."
                action={
                  canShare ? (
                    <Link to="/start" className="btn btn--primary">
                      Start a session
                    </Link>
                  ) : null
                }
              />
            </div>
          ) : (
            <ul className="session-list">
              {recent.map((session) => (
                <SessionRow key={session.uuid} session={session} />
              ))}
            </ul>
          )}
        </section>
      </div>
    </div>
  )
}

function Metric({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="metric">
      <span className="metric__value">{value}</span>
      <span className="metric__label">{label}</span>
    </div>
  )
}

function SessionRow({ session }: { session: RemoteSession }) {
  const liveStatuses = ['WAITING', 'JOIN_REQUESTED', 'CONNECTING', 'ACTIVE', 'PAUSED', 'RECONNECTING']
  const isLive = liveStatuses.includes(session.status)

  return (
    <li className="session-list__item">
      <Link
        to={isLive ? `/room/${session.uuid}` : `/sessions/${session.uuid}`}
        className="session-list__link"
      >
        <div className="session-list__main">
          <span className="session-list__id mono">{session.displayId}</span>
          <span className="session-list__context">
            {session.sourceProductLabel ? `${session.sourceProductLabel} · ` : ''}
            {describeScope(session.scopeType, session.companyName)}
          </span>
        </div>

        <div className="session-list__meta">
          <StatusBadge status={session.status} />
          <span className="tiny muted">
            {session.durationSeconds !== null
              ? formatDuration(session.durationSeconds)
              : formatRelative(session.createdAt)}
          </span>
        </div>
      </Link>
    </li>
  )
}
