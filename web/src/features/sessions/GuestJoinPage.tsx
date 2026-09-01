import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { ShieldCheck } from 'lucide-react'

import { redeemInvitation } from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import RestrictionNotice from '../../components/ui/RestrictionNotice'
import AicountlyLogo, { RemoteMark } from '../../components/brand/AicountlyLogo'

/**
 * Opening a one-time invitation link (§6F, §23).
 *
 * Deliberately outside the application shell. A guest gets the session and
 * nothing else: no navigation, no company data, no reusable credential, no
 * history — the minimum capability needed to be helped.
 *
 * The name field is asked for rather than assumed, because the host is about to
 * be shown "X would like to view your shared screen" and needs to recognise who
 * that is.
 */
export default function GuestJoinPage() {
  const { token = '' } = useParams()
  const navigate = useNavigate()

  const [displayName, setDisplayName] = useState('')
  const [joining, setJoining] = useState(false)
  const [error, setError] = useState<RemoteApiError | null>(null)

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    if (joining) return

    setJoining(true)
    setError(null)

    try {
      const result = await redeemInvitation(token, displayName.trim() || undefined)
      navigate(`/room/${result.session.uuid}`, { replace: true })
    } catch (err) {
      setError(
        err instanceof RemoteApiError
          ? err
          : new RemoteApiError('UNKNOWN', 'This invitation could not be opened.', 0),
      )
      setJoining(false)
    }
  }

  return (
    <div className="guest-page">
      <div className="guest-card">
        <div className="guest-card__brand">
          <AicountlyLogo />
          <span className="guest-card__product">
            <RemoteMark size={16} />
            Remote
          </span>
        </div>

        <h1 className="guest-card__title">Join this Remote session</h1>
        <p className="guest-card__body">
          You have been invited to view a shared screen. The person sharing will be asked to admit you first,
          and you will only see what they choose to share.
        </p>

        {error ? <RestrictionNotice error={error} /> : null}

        <form className="stack" onSubmit={submit}>
          <div className="field">
            <label className="field__label" htmlFor="guest-name">
              Your name
            </label>
            <input
              id="guest-name"
              className="input"
              value={displayName}
              onChange={(event) => setDisplayName(event.target.value)}
              placeholder="How should we introduce you?"
              maxLength={120}
              autoComplete="name"
              autoFocus
            />
            <p className="field__hint">Shown to the host when they decide whether to admit you.</p>
          </div>

          <button type="submit" className="btn btn--primary btn--lg btn--block" disabled={joining}>
            {joining ? 'Opening session…' : 'Continue'}
          </button>
        </form>

        <p className="guest-card__note">
          <ShieldCheck size={14} aria-hidden="true" />
          This link works once and expires shortly. It gives access to this session only.
        </p>
      </div>
    </div>
  )
}
