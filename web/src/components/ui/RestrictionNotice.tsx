import type { ReactNode } from 'react'
import { ShieldAlert } from 'lucide-react'

import { RemoteApiError } from '../../services/api/client'

/**
 * Turning a backend refusal into something a person can act on (§39).
 *
 * The API answers with a machine code and a written message; this maps the code
 * to a heading and, where there is one, a next step. An ordinary user is never
 * shown "403 permission denied" — they are told what their organisation allows
 * and who can change it.
 */

interface Presentation {
  title: string
  /** What the user *can* still do. Empty when there is genuinely nothing. */
  alternatives?: string[]
  tone: 'restricted' | 'error'
}

const PRESENTATION: Record<string, Presentation> = {
  COMPANY_REMOTE_DISABLED: {
    title: 'Remote assistance is turned off',
    alternatives: ['Contact your AICOUNTLY administrator', 'Use AICOUNTLY support chat'],
    tone: 'restricted',
  },
  COMPANY_ACCESS_DENIED: {
    title: 'You do not have access to this organisation',
    alternatives: ['Switch to an organisation you belong to', 'Contact your AICOUNTLY administrator'],
    tone: 'restricted',
  },
  SESSION_CREATE_DENIED: {
    title: 'You cannot start a Remote session',
    alternatives: ['Ask a colleague to start the session and invite you', 'Contact your administrator'],
    tone: 'restricted',
  },
  SHARE_MODE_NOT_ALLOWED: {
    title: 'That way of sharing is restricted',
    alternatives: ['Choose one of the sharing options your organisation permits'],
    tone: 'restricted',
  },
  SURFACE_NOT_ALLOWED: {
    title: 'That screen cannot be shared',
    alternatives: ['Choose again and pick a permitted option'],
    tone: 'restricted',
  },
  EXTERNAL_GUEST_NOT_ALLOWED: {
    title: 'External guests are not allowed',
    alternatives: ['Invite a colleague with an AICOUNTLY account instead'],
    tone: 'restricted',
  },
  EXTERNAL_INVITE_DENIED: {
    title: 'You cannot invite people outside your organisation',
    alternatives: ['Ask your administrator for the External invite permission'],
    tone: 'restricted',
  },
  SUPPORT_SESSIONS_DISABLED: {
    title: 'AICOUNTLY Support sessions are turned off',
    alternatives: ['Contact your AICOUNTLY administrator'],
    tone: 'restricted',
  },
  SUPPORT_REQUEST_DENIED: {
    title: 'You cannot request AICOUNTLY Support',
    alternatives: ['Ask your administrator to enable it for your account'],
    tone: 'restricted',
  },
  SESSION_QUOTA_REACHED: {
    title: 'This month’s Remote sessions have been used',
    alternatives: ['Contact your AICOUNTLY administrator about your plan'],
    tone: 'restricted',
  },
  ADMIN_PERMISSION_DENIED: {
    title: 'You cannot manage Remote for this organisation',
    alternatives: ['Ask a company administrator to make the change'],
    tone: 'restricted',
  },
  SESSION_ALREADY_ENDED: { title: 'This session has already finished', tone: 'error' },
  SESSION_STATE_CHANGED: { title: 'This session changed while you were working on it', tone: 'error' },
  INVITATION_EXPIRED: {
    title: 'This invitation has expired',
    alternatives: ['Ask the host for a new link'],
    tone: 'error',
  },
  INVITATION_ALREADY_USED: {
    title: 'This invitation has already been used',
    alternatives: ['Ask the host for a new link'],
    tone: 'error',
  },
  INVITATION_REVOKED: { title: 'This invitation was withdrawn', tone: 'error' },
  JOIN_DENIED: { title: 'The host declined your request', tone: 'error' },
  AWAITING_APPROVAL: { title: 'Waiting for the host to admit you', tone: 'error' },
  JOIN_CODE_INVALID: { title: 'That session code is not valid', tone: 'error' },
  NOT_FOUND: { title: 'That Remote session could not be found', tone: 'error' },
  RATE_LIMITED: {
    title: 'Too many attempts',
    alternatives: ['Wait a moment and try again'],
    tone: 'error',
  },
  SIGNALLING_UNCONFIGURED: {
    title: 'Live sessions are not available yet',
    alternatives: ['Contact your AICOUNTLY administrator'],
    tone: 'error',
  },
  NETWORK_ERROR: {
    title: 'We could not reach AICOUNTLY Remote',
    alternatives: ['Check your connection and try again'],
    tone: 'error',
  },
  TIMEOUT: { title: 'That took too long', alternatives: ['Try again'], tone: 'error' },
  UNAUTHENTICATED: { title: 'Your session has expired', alternatives: ['Sign in again'], tone: 'error' },
}

interface Props {
  error: RemoteApiError | null
  /** Extra action — "Choose again", "Contact administrator". */
  action?: ReactNode
  compact?: boolean
}

export default function RestrictionNotice({ error, action, compact = false }: Props) {
  if (!error) return null

  const presentation = PRESENTATION[error.code] ?? {
    title: 'That could not be completed',
    tone: 'error' as const,
  }

  return (
    <div
      className={`restriction restriction--${presentation.tone}${compact ? ' restriction--compact' : ''}`}
      role="alert"
    >
      <div className="restriction__icon" aria-hidden="true">
        <ShieldAlert size={compact ? 16 : 20} />
      </div>

      <div className="restriction__content">
        <p className="restriction__title">{presentation.title}</p>

        {/* The backend's message names the specific organisation and rule; it
            is already written for a person to read. */}
        <p className="restriction__message">{error.message}</p>

        {presentation.alternatives?.length ? (
          <>
            <p className="restriction__alternatives-heading">You can still:</p>
            <ul className="restriction__alternatives">
              {presentation.alternatives.map((alternative) => (
                <li key={alternative}>{alternative}</li>
              ))}
            </ul>
          </>
        ) : null}

        {action ? <div className="restriction__action">{action}</div> : null}
      </div>
    </div>
  )
}
