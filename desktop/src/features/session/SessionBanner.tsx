import { useEffect, useState } from 'react'

import type { AgentState, SessionSummary } from '../../types/agent'
import { activeSession, isBeingControlled } from '../../types/agent'

/**
 * **The most important element in the product.**
 *
 * If somebody is connected to this computer, this is on screen. It says who,
 * from which organisation, since when, whether they are controlling, and
 * whether they arrived through unattended access — and it carries both stop
 * controls, so the answer to "how do I make this stop?" is never more than one
 * click from the answer to "is anything happening?".
 *
 * It is rendered from `activeSession(state)`, which is derived from the
 * status rather than stored beside it, so there is no way to be in a session
 * without this appearing.
 */
export function SessionBanner({
  state,
  onStopControl,
  onEndSession,
  busy,
}: {
  state: AgentState
  onStopControl: () => void
  onEndSession: () => void
  busy?: boolean
}) {
  const session = activeSession(state)

  if (!session) return null

  const controlled = isBeingControlled(state)

  return (
    <section
      className={`session-banner${session.unattended ? ' session-banner--unattended' : ''}`}
      role="status"
      aria-live="polite"
    >
      <h2 className="session-banner__title">
        <span className="session-banner__dot" aria-hidden="true" />
        Remote session active
      </h2>

      <div className="session-banner__facts">
        <Fact label="Connected person" value={session.connectedName} />
        <Fact label="Organisation" value={session.companyName ?? 'Not specified'} />
        <Fact label="Started" value={<Elapsed since={session.startedAt} />} />
        <Fact
          label="Control"
          value={
            controlled
              ? session.control.clipboard
                ? 'Controlling, clipboard shared'
                : 'Controlling'
              : session.control.state === 'requested'
                ? 'Control requested'
                : 'Viewing only'
          }
        />
      </div>

      {session.unattended ? (
        <p style={{ margin: 0, fontSize: 'var(--text-sm)' }}>
          This connection used unattended access, which somebody enabled on this computer
          earlier. You can end it here, and turn unattended access off entirely under
          <strong> Unattended access</strong>.
        </p>
      ) : null}

      <div className="session-banner__actions">
        {controlled ? (
          <button type="button" className="btn btn--inverse" onClick={onStopControl} disabled={busy}>
            Stop control
          </button>
        ) : null}

        <button type="button" className="btn btn--inverse" onClick={onEndSession} disabled={busy}>
          End session
        </button>
      </div>
    </section>
  )
}

function Fact({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <div className="session-banner__fact-label">{label}</div>
      <div className="session-banner__fact-value">{value}</div>
    </div>
  )
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

  return <span>{formatClock(seconds)}</span>
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
