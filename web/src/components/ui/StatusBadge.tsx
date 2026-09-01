import type { SessionStatus } from '../../types/remote'

/**
 * The status system (§48).
 *
 * Green is reserved for a session that is genuinely running. Everything else
 * gets the tone it deserves — a waiting session is informational, an expired one
 * is neutral, a failed one is a problem — so a list of thirty sessions can be
 * read at a glance instead of decoded.
 */

type Tone = 'success' | 'info' | 'warning' | 'danger' | 'neutral'

interface StatusPresentation {
  label: string
  tone: Tone
  /** A pulsing dot, for the states that mean "right now". */
  live?: boolean
}

const SESSION_STATUS: Record<SessionStatus, StatusPresentation> = {
  CREATED: { label: 'Created', tone: 'neutral' },
  WAITING: { label: 'Waiting', tone: 'info' },
  JOIN_REQUESTED: { label: 'Waiting to admit', tone: 'warning', live: true },
  CONNECTING: { label: 'Connecting', tone: 'info', live: true },
  ACTIVE: { label: 'Active', tone: 'success', live: true },
  PAUSED: { label: 'Paused', tone: 'warning' },
  RECONNECTING: { label: 'Reconnecting', tone: 'warning', live: true },
  ENDED: { label: 'Completed', tone: 'neutral' },
  DECLINED: { label: 'Declined', tone: 'neutral' },
  EXPIRED: { label: 'Expired', tone: 'neutral' },
  FAILED: { label: 'Failed', tone: 'danger' },
}

const PARTICIPANT_STATUS: Record<string, StatusPresentation> = {
  REQUESTED: { label: 'Waiting', tone: 'warning' },
  APPROVED: { label: 'Admitted', tone: 'info' },
  DENIED: { label: 'Declined', tone: 'neutral' },
  JOINED: { label: 'In session', tone: 'success' },
  LEFT: { label: 'Left', tone: 'neutral' },
  REMOVED: { label: 'Removed', tone: 'neutral' },
}

const SUPPORT_STATUS: Record<string, StatusPresentation> = {
  PENDING: { label: 'Waiting', tone: 'warning', live: true },
  ACCEPTED: { label: 'Accepted', tone: 'success' },
  DECLINED: { label: 'Declined', tone: 'neutral' },
  CANCELLED: { label: 'Cancelled', tone: 'neutral' },
  EXPIRED: { label: 'Expired', tone: 'neutral' },
  COMPLETED: { label: 'Completed', tone: 'neutral' },
}

export function statusPresentation(
  value: string,
  kind: 'session' | 'participant' | 'support' = 'session',
): StatusPresentation {
  const table =
    kind === 'participant' ? PARTICIPANT_STATUS : kind === 'support' ? SUPPORT_STATUS : SESSION_STATUS

  return (table as Record<string, StatusPresentation>)[value] ?? { label: value, tone: 'neutral' }
}

interface Props {
  status: string
  kind?: 'session' | 'participant' | 'support'
}

export default function StatusBadge({ status, kind = 'session' }: Props) {
  const { label, tone, live } = statusPresentation(status, kind)

  return (
    <span className={`badge badge--${tone}`}>
      <span className={live ? 'badge__dot badge__dot--live' : 'badge__dot'} aria-hidden="true" />
      {label}
    </span>
  )
}
