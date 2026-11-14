import type { AgentState } from '../../types/agent'
import { isOnline } from '../../types/agent'
import { StatusPill } from '../../components/StatusPill'

/**
 * This computer, once it is signed in: what it is called and whether it is
 * reachable.
 *
 * A machine that is signed out — or that an administrator revoked — never
 * reaches this card. `SignInScreen` is what it shows instead, so there is no
 * state in which this card has to explain that there is nothing to show.
 *
 * It is "this computer" throughout, as the tab that opens it is. The card
 * used to be titled "This device" and led with a "Device ID", a "Key
 * fingerprint" and "Registration: Protected" — three things nobody at the
 * computer acts on, in a vocabulary the rest of the window does not use. The
 * two identifiers are still here, folded under "Details for support": the
 * fingerprint is the one thing somebody looking at the Computers page and
 * somebody looking at this window can compare to know they are looking at the
 * same machine, and it is a hash of a public key, so showing it costs nothing.
 */
export function DeviceCard({
  state,
  computerName = 'computer',
  onUnregister,
  busy,
}: {
  state: AgentState
  /** "Mac" or "computer". */
  computerName?: string
  onUnregister: () => void
  busy?: boolean
}) {
  return (
    <section className="card">
      <h2 className="card__title">This {computerName}</h2>
      <p className="card__subtitle">Signed in to AICOUNTLY Remote.</p>

      <div className="card__rows">
        <div className="row">
          <span className="row__label">Name</span>
          <span className="row__value">{state.deviceName ?? '—'}</span>
        </div>

        {/* A personal computer belongs to nobody's organisation, so it has no row for one. */}
        {state.companyName ? (
          <div className="row">
            <span className="row__label">Organisation</span>
            <span className="row__value">{state.companyName}</span>
          </div>
        ) : null}

        <div className="row">
          <span className="row__label">Connection</span>
          <span className="row__value">
            {isOnline(state) ? (
              <StatusPill tone="ready">Online</StatusPill>
            ) : state.status.status === 'authenticating' ? (
              <StatusPill tone="neutral">Connecting…</StatusPill>
            ) : (
              <StatusPill tone="attention">Not connected</StatusPill>
            )}
          </span>
        </div>
      </div>

      <details className="details">
        <summary className="details__summary">Details for support</summary>
        <div className="card__rows">
          <div className="row">
            <span className="row__label">Computer ID</span>
            <span className="row__value row__value--mono">{state.deviceUuid ?? '—'}</span>
          </div>
          <div className="row">
            <span className="row__label">Key fingerprint</span>
            <span className="row__value row__value--mono">{state.keyFingerprint ?? '—'}</span>
          </div>
        </div>
      </details>

      <div style={{ marginTop: 'var(--space-4)', display: 'flex', gap: 'var(--space-2)' }}>
        <button type="button" className="btn btn--secondary" onClick={onUnregister} disabled={busy}>
          Sign this computer out
        </button>
      </div>
    </section>
  )
}
