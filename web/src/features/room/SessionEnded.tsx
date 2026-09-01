import { useState } from 'react'
import { Link } from 'react-router-dom'
import { CheckCircle2 } from 'lucide-react'

import { submitFeedback } from '../../services/api/remote'
import type { SessionDetail } from '../../types/remote'
import { formatDuration } from '../../utils/format'

/**
 * The end-of-session screen (§72).
 *
 * Closes the loop: what happened, how long it took, and — for a support
 * session — whether it actually helped. The feedback question is optional and
 * asked once; nobody is held hostage by a survey.
 *
 * A guest sees the summary and nothing else: no history link, no dashboard, no
 * way further into AICOUNTLY (§23).
 */

interface Props {
  session: SessionDetail
  isGuest: boolean
}

export default function SessionEnded({ session, isGuest }: Props) {
  const [answered, setAnswered] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  const participants = session.participants.filter((participant) => participant.status !== 'DENIED').length
  const isSupport = session.sessionType === 'SUPPORT'

  async function answer(resolution: 'YES' | 'PARTIALLY' | 'NO') {
    setSubmitting(true)

    try {
      await submitFeedback(session.uuid, resolution)
      setAnswered(true)
    } catch {
      // Feedback is a courtesy; failing to record it must not become the last
      // thing the user sees about their session.
      setAnswered(true)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="room room--message">
      <div className="room__message-card">
        <div className="ended">
          <div className="ended__icon" aria-hidden="true">
            <CheckCircle2 size={26} />
          </div>

          <h1 className="ended__title">Remote session ended</h1>
          <p className="ended__subtitle mono">{session.displayId}</p>

          <dl className="ended__facts">
            <div>
              <dt>Duration</dt>
              <dd>{formatDuration(session.durationSeconds)}</dd>
            </div>
            <div>
              <dt>Participants</dt>
              <dd>{participants}</dd>
            </div>
            <div>
              <dt>Screen sharing</dt>
              <dd>Stopped</dd>
            </div>
          </dl>

          {isSupport && !isGuest ? (
            answered ? (
              <p className="ended__thanks">Thank you — that helps AICOUNTLY Support improve.</p>
            ) : (
              <div className="ended__feedback">
                <p className="ended__feedback-question">Was your issue resolved?</p>
                <div className="row row--wrap">
                  {(['YES', 'PARTIALLY', 'NO'] as const).map((option) => (
                    <button
                      key={option}
                      type="button"
                      className="btn btn--secondary"
                      onClick={() => void answer(option)}
                      disabled={submitting}
                    >
                      {option === 'YES' ? 'Yes' : option === 'PARTIALLY' ? 'Partially' : 'No'}
                    </button>
                  ))}
                </div>
              </div>
            )
          ) : null}

          {isGuest ? (
            <p className="ended__guest-note">
              You can close this tab. This invitation cannot be used again.
            </p>
          ) : (
            <div className="row row--wrap ended__actions">
              <Link to={`/sessions/${session.uuid}`} className="btn btn--secondary">
                View session details
              </Link>
              <Link to="/start" className="btn btn--primary">
                Start another session
              </Link>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
