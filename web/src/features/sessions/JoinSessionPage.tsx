import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { KeyRound } from 'lucide-react'

import { joinByCode } from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import RestrictionNotice from '../../components/ui/RestrictionNotice'

/**
 * Joining by session code (§6E).
 *
 * Nine digits, shown in three groups because that is how somebody reads one
 * aloud over the phone. The grouping is presentation only — the API receives
 * the digits.
 *
 * Joining does not admit you: it puts you in the queue, and the host decides
 * (§71). The wording says so, so a wait is expected rather than confusing.
 */
export default function JoinSessionPage() {
  const navigate = useNavigate()
  const [digits, setDigits] = useState('')
  const [joining, setJoining] = useState(false)
  const [error, setError] = useState<RemoteApiError | null>(null)

  const complete = digits.length === 9

  function handleChange(value: string) {
    setError(null)
    setDigits(value.replace(/\D/g, '').slice(0, 9))
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    if (!complete || joining) return

    setJoining(true)
    setError(null)

    try {
      const result = await joinByCode(digits)
      navigate(`/room/${result.session.uuid}`)
    } catch (err) {
      setError(
        err instanceof RemoteApiError ? err : new RemoteApiError('UNKNOWN', 'That code could not be used.', 0),
      )
      setJoining(false)
    }
  }

  return (
    <div className="page page--narrow">
      <div className="stack stack--lg">
        <header className="stack stack--sm">
          <h1>Join a Remote session</h1>
          <p className="muted">
            Enter the nine-digit code the host gave you. They will be asked to admit you before you can see
            anything.
          </p>
        </header>

        {error ? <RestrictionNotice error={error} /> : null}

        <section className="card">
          <form className="card__body stack" onSubmit={submit}>
            <div className="field">
              <label className="field__label" htmlFor="session-code">
                Session code
              </label>

              <input
                id="session-code"
                className="input code-input mono"
                inputMode="numeric"
                autoComplete="one-time-code"
                // The visible value is grouped; the state stays digits-only.
                value={groupDigits(digits)}
                onChange={(event) => handleChange(event.target.value)}
                placeholder="583 194 726"
                aria-describedby="session-code-hint"
                autoFocus
              />

              <p id="session-code-hint" className="field__hint">
                Codes expire when the session ends.
              </p>
            </div>

            <button type="submit" className="btn btn--primary btn--lg btn--block" disabled={!complete || joining}>
              <KeyRound size={16} aria-hidden="true" />
              {joining ? 'Joining…' : 'Join session'}
            </button>
          </form>
        </section>

        <p className="tiny muted">
          Given a link instead of a code? Open it directly — an invitation link takes you straight into the
          session.
        </p>
      </div>
    </div>
  )
}

function groupDigits(digits: string): string {
  return digits.replace(/(\d{3})(?=\d)/g, '$1 ').trim()
}
