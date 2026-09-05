import type { AgentState } from '../../types/agent'
import { isEnrolled, isOnline } from '../../types/agent'
import { StatusPill } from '../../components/StatusPill'

/**
 * This device: what it is called, what it is, and whether it is reachable.
 *
 * The fingerprint is shown deliberately. It is the one thing an administrator
 * looking at the web console and a person looking at this window can compare
 * to know they are looking at the same machine — and it is a hash of a public
 * key, so showing it costs nothing.
 */
export function DeviceCard({
  state,
  onRegister,
  onUnregister,
  busy,
}: {
  state: AgentState
  onRegister: () => void
  onUnregister: () => void
  busy?: boolean
}) {
  const enrolled = isEnrolled(state)
  const revoked = state.status.status === 'revoked'

  return (
    <section className="card">
      <h2 className="card__title">This device</h2>
      <p className="card__subtitle">
        {enrolled
          ? 'Registered with AICOUNTLY Remote.'
          : 'Not registered yet. Nobody can connect to this computer.'}
      </p>

      <div className="card__rows">
        <div className="row">
          <span className="row__label">Device name</span>
          <span className="row__value">{state.deviceName ?? '—'}</span>
        </div>

        <div className="row">
          <span className="row__label">Device ID</span>
          <span className="row__value row__value--mono">{state.deviceUuid ?? '—'}</span>
        </div>

        <div className="row">
          <span className="row__label">Organisation</span>
          <span className="row__value">{state.companyName ?? '—'}</span>
        </div>

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
            {revoked ? (
              <StatusPill tone="danger">Removed by an administrator</StatusPill>
            ) : enrolled ? (
              <StatusPill tone="ready">Protected</StatusPill>
            ) : (
              <StatusPill tone="attention">Not registered</StatusPill>
            )}
          </span>
        </div>
      </div>

      {revoked ? (
        <div className="notice notice--danger" style={{ marginTop: 'var(--space-4)' }}>
          <p className="notice__title">This device was removed</p>
          <p>
            An administrator revoked it in AICOUNTLY Remote. Nobody can connect to this computer.
            Register it again to start using it.
          </p>
        </div>
      ) : null}

      <div style={{ marginTop: 'var(--space-4)', display: 'flex', gap: 'var(--space-2)' }}>
        {enrolled && !revoked ? (
          <button type="button" className="btn btn--secondary" onClick={onUnregister} disabled={busy}>
            Unregister this device
          </button>
        ) : (
          <button type="button" className="btn btn--primary" onClick={onRegister} disabled={busy}>
            Register this device
          </button>
        )}
      </div>
    </section>
  )
}
