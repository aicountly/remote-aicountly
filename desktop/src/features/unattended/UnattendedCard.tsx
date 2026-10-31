import type { AgentState } from '../../types/agent'
import { StatusPill } from '../../components/StatusPill'

/**
 * Unattended access — its own screen, because it is its own decision.
 *
 * It is deliberately not a switch inside a session dialog. Turning it on means
 * this computer can be connected to when nobody is sitting at it, and the
 * screen that asks says exactly that, in those words, before anything happens.
 *
 * Turning it **on** is done in Remote → Computers, signed in as the person
 * whose computer it is, behind the API's own confirmation: this window holds
 * no credential of a person's, and the decision is deliberately kept with
 * one. What it means is said here, before anybody goes there.
 *
 * Turning it **off** is one click with no confirmation, made by the machine
 * itself. The asymmetry is the point: making a machine reachable deserves a
 * moment's thought; making it unreachable should never be obstructed.
 */
export function UnattendedCard({
  state,
  onEnable,
  onDisable,
  busy,
  error,
}: {
  state: AgentState
  onEnable: () => void
  onDisable: () => void
  busy?: boolean
  error?: string | null
}) {
  const { unattended } = state

  return (
    <section className="card">
      <h2 className="card__title">Unattended access</h2>
      <p className="card__subtitle">
        Whether this computer can be reached when nobody is at it.
      </p>

      <div className="row">
        <span className="row__label">Status</span>
        <span className="row__value">
          {unattended.enabled ? (
            <StatusPill tone="attention">ON</StatusPill>
          ) : (
            <StatusPill tone="neutral">OFF</StatusPill>
          )}
        </span>
      </div>

      {unattended.enabled ? (
        <>
          <div className="row">
            <span className="row__label">Turned on</span>
            <span className="row__value">{formatDate(unattended.enabledAt)}</span>
          </div>
          <div className="row">
            <span className="row__label">Last used</span>
            <span className="row__value">
              {unattended.lastUsedAt ? formatDate(unattended.lastUsedAt) : 'Never'}
            </span>
          </div>
        </>
      ) : null}

      {error ? (
        <div className="notice notice--danger" style={{ marginTop: 'var(--space-4)' }}>
          {error}
        </div>
      ) : null}

      {!unattended.allowedByPolicy && !unattended.enabled ? (
        <div className="notice notice--info" style={{ marginTop: 'var(--space-4)' }}>
          Unattended access is not available for your account.
        </div>
      ) : null}

      {unattended.enabled ? (
        <div style={{ marginTop: 'var(--space-4)' }}>
          <button type="button" className="btn btn--secondary" onClick={onDisable} disabled={busy}>
            Turn off unattended access
          </button>
          <p className="field__hint" style={{ marginTop: 'var(--space-2)' }}>
            Takes effect immediately. Nobody can connect to this computer without somebody at it
            afterwards.
          </p>
        </div>
      ) : (
        <div className="notice notice--warning" style={{ marginTop: 'var(--space-4)' }}>
          <p className="notice__title">What turning it on means</p>
          <p>
            You will be able to connect to this computer from your other computers, or from a browser signed in as
            yourself, <strong>when nobody is sitting at it</strong> — see the screen and use the keyboard and mouse.
            Nobody else can.
          </p>
          <p>
            Every connection is recorded, this window and the icon beside the clock show one while it is happening,
            and you can turn this off again at any time from here.
          </p>

          <div className="session-banner__actions" style={{ marginTop: 'var(--space-3)' }}>
            <button
              type="button"
              className="btn btn--primary"
              onClick={onEnable}
              disabled={busy || !unattended.allowedByPolicy}
            >
              Turn on in Remote → Computers
            </button>
          </div>
          <p className="field__hint" style={{ marginTop: 'var(--space-2)' }}>
            You confirm it there, signed in as yourself. It shows here within a minute.
          </p>
        </div>
      )}
    </section>
  )
}

export function formatDate(value: string | null): string {
  if (!value) return '—'

  const parsed = Date.parse(value)
  if (Number.isNaN(parsed)) return '—'

  return new Date(parsed).toLocaleString(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  })
}
