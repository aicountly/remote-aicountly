import type { AgentState } from '../../types/agent'
import { activeCompanion } from '../../types/agent'
import { Elapsed } from '../session/SessionBanner'

/**
 * The control companion's own banner — the companion's half of "a running
 * session is always visible, and can always be stopped from here".
 *
 * While somebody controls this computer it says who, and carries **Stop
 * control** (local and immediate, as everywhere) and the system-wide shortcut.
 * While the person here is using their own mouse or keyboard it says that
 * remote input is paused. Otherwise it says the computer can be given control
 * from the browser, and offers to stop that too.
 */
export function CompanionBanner({
  state,
  computerName,
  stopShortcut,
  onStopControl,
  onLeave,
  busy,
}: {
  state: AgentState
  computerName: string
  stopShortcut: string | null
  onStopControl: () => void
  onLeave: () => void
  busy?: boolean
}) {
  const companion = activeCompanion(state)

  if (!companion || companion.phase === 'awaiting_confirmation') return null

  const controller = companion.controllerName ?? 'Someone'
  const controlled = companion.phase === 'controlled' || companion.phase === 'paused'

  const title =
    companion.phase === 'controlled'
      ? `${controller} is controlling this ${computerName}`
      : companion.phase === 'paused'
        ? `Paused while you use this ${computerName}`
        : `This ${computerName} can be controlled while you share`

  return (
    <section className="session-banner" role="status" aria-live="polite">
      <h2 className="session-banner__title">
        <span className="session-banner__dot" aria-hidden="true" />
        {title}
      </h2>

      <div className="session-banner__facts">
        <Fact label="Session" value={companion.displayId} />
        {companion.companyName ? <Fact label="Organisation" value={companion.companyName} /> : null}
        {companion.startedAt ? <Fact label="Since" value={<Elapsed since={companion.startedAt} />} /> : null}
        <Fact label="Screen" value={companion.monitor?.name ?? 'Not chosen yet'} />
      </div>

      {companion.phase === 'paused' ? (
        <p style={{ margin: 0, fontSize: 'var(--text-sm)' }}>
          {controller}&apos;s input waits while you use your own mouse or keyboard, and carries on
          when you stop for a moment.
        </p>
      ) : companion.phase === 'ready' ? (
        <p style={{ margin: 0, fontSize: 'var(--text-sm)' }}>
          Nobody is controlling it. Anyone who asks needs your approval in the browser first.
        </p>
      ) : null}

      <div className="session-banner__actions">
        {controlled ? (
          <button type="button" className="btn btn--inverse" onClick={onStopControl} disabled={busy}>
            Stop control
          </button>
        ) : null}

        <button type="button" className="btn btn--inverse" onClick={onLeave} disabled={busy}>
          Stop allowing control
        </button>
      </div>

      {controlled && stopShortcut ? (
        <p style={{ margin: 0, fontSize: 'var(--text-sm)' }}>
          Or press <kbd>{stopShortcut}</kbd> anywhere.
        </p>
      ) : null}
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
