import type { AgentState } from '../../types/agent'
import { isOnline } from '../../types/agent'
import { StatusPill } from '../../components/StatusPill'

/**
 * This device, once it is signed in: what it is called, what it is, and
 * whether it is reachable.
 *
 * A machine that is signed out — or that an administrator revoked — never
 * reaches this card. `SignInScreen` is what it shows instead, so there is no
 * state in which this card has to explain that there is nothing to show.
 *
 * The fingerprint is shown deliberately. It is the one thing an administrator
 * looking at the web console and a person looking at this window can compare
 * to know they are looking at the same machine — and it is a hash of a public
 * key, so showing it costs nothing.
 */
export function DeviceCard({
  state,
  onUnregister,
  busy,
}: {
  state: AgentState
  onUnregister: () => void
  busy?: boolean
}) {
  return (
    <section className="card">
      <h2 className="card__title">This device</h2>
      <p className="card__subtitle">Signed in to AICOUNTLY Remote.</p>

      <div className="card__rows">
        <div className="row">
          <span className="row__label">Device name</span>
          <span className="row__value">{state.deviceName ?? '—'}</span>
        </div>

        <div className="row">
          <span className="row__label">Device ID</span>
          <span className="row__value row__value--mono">{state.deviceUuid ?? '—'}</span>
        </div>

        {/* A personal computer belongs to nobody's organisation, so it has no row for one. */}
        {state.companyName ? (
          <div className="row">
            <span className="row__label">Organisation</span>
            <span className="row__value">{state.companyName}</span>
          </div>
        ) : null}

        <div className="row">
          <span className="row__label">Key fingerprint</span>
          <span className="row__value row__value--mono">{state.keyFingerprint ?? '—'}</span>
        </div>

        <div className="row">
          <span className="row__label">Connection</span>
          <span className="row__value">
            {isOnline(state) ? (
              <StatusPill tone="ready">Online</StatusPill>
            ) : (
              <StatusPill tone="neutral">Offline</StatusPill>
            )}
          </span>
        </div>

        <div className="row">
          <span className="row__label">Registration</span>
          <span className="row__value">
            <StatusPill tone="ready">Protected</StatusPill>
          </span>
        </div>
      </div>

      <div style={{ marginTop: 'var(--space-4)', display: 'flex', gap: 'var(--space-2)' }}>
        <button type="button" className="btn btn--secondary" onClick={onUnregister} disabled={busy}>
          Sign this computer out
        </button>
      </div>
    </section>
  )
}
