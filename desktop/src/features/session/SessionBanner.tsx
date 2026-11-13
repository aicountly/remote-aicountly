import { useEffect, useState } from 'react'

import type { AgentState, SessionSummary } from '../../types/agent'
import { activeSession, isBeingControlled } from '../../types/agent'

/**
 * **The most important element in the product.**
 *
 * If somebody is connected to this computer, this is on screen — at the top
 * of the window, on every tab, and still there when the page below scrolls.
 * It says **as a sentence** who is connected and what they can do: looking at
 * the screen is one sentence, using the mouse and keyboard is another, and
 * nobody has to read a grid of labels to tell the two apart. Both stop
 * controls sit beside the sentence, so the answer to "how do I make this
 * stop?" is never more than one click from the answer to "is anything
 * happening?".
 *
 * It is rendered from `activeSession(state)`, which is derived from the
 * status rather than stored beside it, so there is no way to be in a session
 * without this appearing.
 *
 * The stop buttons are **never disabled**. They used to grey out while any
 * other action in the window was in flight — a slow "Turn off unattended
 * access" took Stop control away for as long as the server took to answer.
 */
export function SessionBanner({
  state,
  computerName = 'computer',
  stopShortcut = null,
  onStopControl,
  onEndSession,
}: {
  state: AgentState
  /** "Mac" or "computer". */
  computerName?: string
  /** The system-wide Stop control shortcut, as this platform writes it. */
  stopShortcut?: string | null
  onStopControl: () => void
  onEndSession: () => void
}) {
  const session = activeSession(state)

  if (!session) return null

  const controlled = isBeingControlled(state)
  const name = session.connectedName.trim()
  const asking = session.control.state === 'requested'

  return (
    <section
      className={[
        'session-banner',
        session.unattended ? 'session-banner--unattended' : '',
        controlled ? 'session-banner--controlled' : '',
      ]
        .filter(Boolean)
        .join(' ')}
      aria-label="Remote session"
    >
      <div className="session-banner__text">
        {/* Only the sentence is announced when it changes. The clock below
            ticks every second and must not be read out every second. */}
        <h2 className="session-banner__title" role="status">
          <span className="session-banner__dot" aria-hidden="true" />
          {sessionSentence(name, controlled, computerName)}
        </h2>

        <p className="session-banner__facts">
          {session.companyName ? <>{session.companyName} · </> : null}
          {name ? 'Connected for ' : 'Open for '}
          <Elapsed since={session.startedAt} />
          {controlled && session.control.clipboard ? ' · Clipboard shared' : ''}
          {!controlled && asking ? ' · Asking to control' : ''}
          {controlled && stopShortcut ? (
            <>
              {' · '}
              <kbd>{stopShortcut}</kbd> stops control from anywhere
            </>
          ) : null}
        </p>

        {session.unattended ? (
          <p className="session-banner__note">
            This connection used unattended access, which somebody enabled on this {computerName} earlier. You can end
            it here, and turn unattended access off entirely under <strong>Unattended access</strong>.
          </p>
        ) : null}
      </div>

      <div className="session-banner__actions">
        {controlled ? (
          <button type="button" className="btn btn--stop" onClick={onStopControl}>
            Stop control
          </button>
        ) : null}

        <button type="button" className="btn btn--inverse" onClick={onEndSession}>
          End session
        </button>
      </div>
    </section>
  )
}

/**
 * What is happening, in the words the person at the computer would use.
 *
 * Nobody connected yet — a help session with its code just read out — is its
 * own sentence: the banner used to say "Remote session active", "Connected
 * person" over a blank, and "Viewing only", about a session nobody was in.
 */
export function sessionSentence(connectedName: string, controlled: boolean, computerName: string): string {
  if (controlled) return `${connectedName || 'Someone'} is controlling this ${computerName}`
  if (connectedName) return `${connectedName} can see this ${computerName}’s screen`

  return 'Waiting for someone to join — nobody is connected yet'
}

/**
 * How long the session has been running.
 *
 * A visible clock is part of trusting a session: it says how long this has
 * been going on without anyone having to remember when it started.
 */
export function Elapsed({ since }: { since: string }) {
  const [seconds, setSeconds] = useState(() => elapsedSeconds(since))

  useEffect(() => {
    const tick = () => setSeconds(elapsedSeconds(since))

    tick()
    const timer = setInterval(tick, 1000)

    return () => clearInterval(timer)
  }, [since])

  return <span aria-live="off">{formatClock(seconds)}</span>
}

export function elapsedSeconds(since: string): number {
  const started = Date.parse(since)

  if (Number.isNaN(started)) return 0

  return Math.max(0, Math.floor((Date.now() - started) / 1000))
}

export function formatClock(totalSeconds: number): string {
  const hours = Math.floor(totalSeconds / 3600)
  const minutes = Math.floor((totalSeconds % 3600) / 60)
  const seconds = totalSeconds % 60

  const pad = (value: number) => String(value).padStart(2, '0')

  return hours > 0
    ? `${hours}:${pad(minutes)}:${pad(seconds)}`
    : `${pad(minutes)}:${pad(seconds)}`
}

/** Whether a summary describes a session somebody is controlling. */
export function isControlled(session: SessionSummary): boolean {
  return session.control.state === 'granted'
}
