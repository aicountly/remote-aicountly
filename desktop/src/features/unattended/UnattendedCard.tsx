import { useState } from 'react'

import type { AgentState } from '../../types/agent'
import { StatusPill } from '../../components/StatusPill'

/**
 * Unattended access — its own screen, because it is its own decision.
 *
 * It is deliberately not a switch inside a session dialog. Turning it on means
 * this computer can be connected to when nobody is sitting at it, and the
 * screen that asks says exactly that, in those words, before anything happens.
 *
 * Turning it **off** is one click with no confirmation. The asymmetry is the
 * point: making a machine reachable deserves a moment's thought; making it
 * unreachable should never be obstructed.
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
  const [confirming, setConfirming] = useState(false)
  const { unattended } = state

  return (
    <section className="card">
      <h2 className="card__title">Unattended access</h2>
      <p className="card__subtitle">
        Whether an authorised colleague can connect to this computer when nobody is at it.
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
          Your organisation has not enabled unattended access. An administrator turns it on for
          the whole organisation before it can be switched on here.
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
      ) : confirming ? (
        <div className="notice notice--warning" style={{ marginTop: 'var(--space-4)' }}>
          <p className="notice__title">Before you turn this on</p>
          <p>
            An authorised colleague in your organisation will be able to connect to this computer
            <strong> when nobody is sitting at it</strong>, see the screen, and — where your
            organisation allows remote control — use the keyboard and mouse.
          </p>
          <p>
            Every connection is recorded, this window and the icon beside the clock show one while
            it is happening, and you can turn this off again at any time from here.
          </p>

          <div className="session-banner__actions" style={{ marginTop: 'var(--space-3)' }}>
            <button
              type="button"
              className="btn btn--primary"
              onClick={() => {
                setConfirming(false)
                onEnable()
              }}
              disabled={busy}
            >
              I understand — turn it on
            </button>
            <button
              type="button"
              className="btn btn--secondary"
              onClick={() => setConfirming(false)}
              disabled={busy}
            >
              Cancel
            </button>
          </div>
        </div>
      ) : (
        <div style={{ marginTop: 'var(--space-4)' }}>
          <button
            type="button"
            className="btn btn--secondary"
            onClick={() => setConfirming(true)}
            disabled={busy || !unattended.allowedByPolicy}
          >
            Turn on unattended access
          </button>
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
