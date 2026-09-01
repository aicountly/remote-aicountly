import { useCallback, useEffect, useState } from 'react'
import { Check, Copy, Link2, Trash2, UserPlus } from 'lucide-react'

import { createInvitation, fetchInvitations, revokeInvitation } from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import RestrictionNotice from '../../components/ui/RestrictionNotice'
import type { Invitation, SessionDetail } from '../../types/remote'
import { formatRelative } from '../../utils/format'

/**
 * Inviting someone into a live session (§6F, §23).
 *
 * Two kinds, and the difference matters:
 *
 *   * **an AICOUNTLY colleague** — they sign in as themselves, and company
 *     policy applies to them as it always does;
 *   * **an external guest** — no AICOUNTLY account, so the link is the whole
 *     credential. Offered only when the organisation permits guests *and* this
 *     user holds the permission to issue one.
 *
 * The link is shown **once**. Only its hash is stored, so it cannot be
 * retrieved afterwards — the panel says so rather than letting the host
 * discover it by trying.
 */

interface Props {
  session: SessionDetail
  canInviteExternal: boolean
}

export default function InvitePanel({ session, canInviteExternal }: Props) {
  const [invitations, setInvitations] = useState<Invitation[]>(session.invitations ?? [])
  const [freshLink, setFreshLink] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)
  const [creating, setCreating] = useState(false)
  const [error, setError] = useState<RemoteApiError | null>(null)
  const [email, setEmail] = useState('')

  const reload = useCallback(async () => {
    try {
      setInvitations(await fetchInvitations(session.uuid))
    } catch {
      // The list is a convenience; failing to refresh it must not replace the
      // link the host is in the middle of copying.
    }
  }, [session.uuid])

  useEffect(() => {
    void reload()
  }, [reload])

  // The "Copied" confirmation is transient — it says the clipboard write
  // succeeded, and nothing more.
  useEffect(() => {
    if (!copied) return

    const timer = setTimeout(() => setCopied(false), 2000)

    return () => clearTimeout(timer)
  }, [copied])

  async function create(type: 'INTERNAL' | 'EXTERNAL_GUEST') {
    setCreating(true)
    setError(null)
    setFreshLink(null)

    try {
      const result = await createInvitation(session.uuid, type, email.trim() || null)
      setFreshLink(result.url)
      setEmail('')
      await reload()
    } catch (err) {
      setError(
        err instanceof RemoteApiError
          ? err
          : new RemoteApiError('UNKNOWN', 'The invitation could not be created.', 0),
      )
    } finally {
      setCreating(false)
    }
  }

  async function copy(url: string) {
    try {
      await navigator.clipboard.writeText(url)
      setCopied(true)
    } catch {
      // Clipboard access can be refused. The link is on screen and selectable,
      // so this is a missing convenience rather than a failure.
      setCopied(false)
    }
  }

  const live = invitations.filter(
    (invitation) => invitation.revokedAt === null && invitation.usedCount < invitation.maxUses,
  )

  return (
    <div className="invite">
      {error ? <RestrictionNotice error={error} compact /> : null}

      {freshLink ? (
        <div className="invite__fresh">
          <p className="invite__fresh-title">Secure link ready</p>
          <p className="invite__fresh-note">
            This link works once and is shown only now — it is not stored and cannot be shown again.
          </p>

          <div className="invite__link-row">
            <input className="input mono invite__link" readOnly value={freshLink} onFocus={(e) => e.target.select()} />
            <button type="button" className="btn btn--primary btn--sm" onClick={() => void copy(freshLink)}>
              {copied ? <Check size={14} aria-hidden="true" /> : <Copy size={14} aria-hidden="true" />}
              <span className="sr-only">{copied ? 'Copied' : 'Copy link'}</span>
            </button>
          </div>
        </div>
      ) : null}

      <div className="invite__actions">
        <button
          type="button"
          className="btn btn--secondary btn--sm btn--block"
          onClick={() => void create('INTERNAL')}
          disabled={creating}
        >
          <UserPlus size={15} aria-hidden="true" />
          Invite an AICOUNTLY colleague
        </button>

        {canInviteExternal ? (
          <>
            <label className="sr-only" htmlFor="invite-email">
              Guest email (optional)
            </label>
            <input
              id="invite-email"
              className="input"
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              placeholder="guest@example.com (optional)"
              autoComplete="off"
            />
            <button
              type="button"
              className="btn btn--secondary btn--sm btn--block"
              onClick={() => void create('EXTERNAL_GUEST')}
              disabled={creating}
            >
              <Link2 size={15} aria-hidden="true" />
              Invite an external guest
            </button>
          </>
        ) : (
          <p className="invite__restricted">
            {session.companyName ?? 'This organisation'} does not allow people outside AICOUNTLY to join
            Remote sessions.
          </p>
        )}
      </div>

      {live.length > 0 ? (
        <ul className="invite__list">
          {live.map((invitation) => (
            <li key={invitation.uuid} className="invite__item">
              <div className="invite__item-body">
                <p className="invite__item-type">
                  {invitation.invitationType === 'EXTERNAL_GUEST' ? 'External guest' : 'AICOUNTLY colleague'}
                </p>
                <p className="invite__item-meta">
                  {invitation.inviteeEmail ? `${invitation.inviteeEmail} · ` : ''}
                  expires {formatRelative(invitation.expiresAt)}
                </p>
              </div>

              <button
                type="button"
                className="btn btn--ghost btn--sm room__ghost"
                aria-label="Withdraw invitation"
                onClick={() => void revokeInvitation(session.uuid, invitation.uuid).then(reload)}
              >
                <Trash2 size={14} aria-hidden="true" />
              </button>
            </li>
          ))}
        </ul>
      ) : (
        <p className="invite__empty">No invitations are outstanding.</p>
      )}
    </div>
  )
}
