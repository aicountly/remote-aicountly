import { useState } from 'react'

import type { About, AgentConfig } from '../../types/agent'
import { StatusPill } from '../../components/StatusPill'

/**
 * Settings, and the diagnostics a support engineer asks for.
 *
 * What is deliberately absent: anything that is or contains a secret. The
 * device key's *location* is shown; the key is not. There is no field for a
 * token, because the agent has none to store — it proves possession of its key
 * and receives a credential that lives for minutes in memory.
 */
export function SettingsPage({
  config,
  about,
  onSave,
  busy,
  error,
}: {
  config: AgentConfig
  about: About | null
  onSave: (config: AgentConfig) => void
  busy?: boolean
  error?: string | null
}) {
  const [draft, setDraft] = useState(config)

  return (
    <>
      <section className="card">
        <h2 className="card__title">Settings</h2>
        <p className="card__subtitle">How this computer connects to AICOUNTLY Remote.</p>

        {error ? (
          <div className="notice notice--danger" style={{ marginBottom: 'var(--space-4)' }}>
            {error}
          </div>
        ) : null}

        <div className="field">
          <label className="field__label" htmlFor="api">
            AICOUNTLY Remote address
          </label>
          <input
            id="api"
            type="url"
            value={draft.apiBaseUrl}
            onChange={(event) => setDraft({ ...draft, apiBaseUrl: event.target.value })}
            disabled={busy}
          />
          <span className="field__hint">
            Must be an https address. Changing it disconnects this device until it registers
            again.
          </span>
        </div>

        <div className="field">
          <label className="field__label" htmlFor="quality">
            Screen quality
          </label>
          <select
            id="quality"
            value={draft.captureQuality}
            onChange={(event) =>
              setDraft({ ...draft, captureQuality: event.target.value as AgentConfig['captureQuality'] })
            }
            disabled={busy}
          >
            <option value="adaptive">Adaptive — up to 1080p, drops when the network is poor</option>
            <option value="low_bandwidth">Low bandwidth — smaller and slower, for a poor link</option>
            <option value="high_quality">High quality — the best the connection carries</option>
          </select>
        </div>

        <label className="field field--inline">
          <span>
            <span className="field__label">Keep running when the window is closed</span>
            <span className="field__hint">
              Closing the window hides it. This computer stays reachable, and the icon beside the
              clock stays there.
            </span>
          </span>
          <input
            type="checkbox"
            checked={draft.runInBackground}
            onChange={(event) => setDraft({ ...draft, runInBackground: event.target.checked })}
            disabled={busy}
          />
        </label>

        <p className="field__hint" style={{ marginBottom: 'var(--space-4)' }}>
          AICOUNTLY Remote starts quietly in the tray when anyone signs in to Windows. To stop that,
          turn it off in Task Manager under Startup apps.
        </p>

        <button type="button" className="btn btn--primary" onClick={() => onSave(draft)} disabled={busy}>
          Save settings
        </button>
      </section>

      {about ? <Diagnostics about={about} /> : null}
    </>
  )
}

function Diagnostics({ about }: { about: About }) {
  return (
    <section className="card">
      <h2 className="card__title">About</h2>
      <p className="card__subtitle">What a support engineer will ask for.</p>

      <div className="card__rows">
        <div className="row">
          <span className="row__label">Version</span>
          <span className="row__value row__value--mono">{about.version}</span>
        </div>
        {/* The same number as in the installer's name, so somebody can tell which build they have. */}
        <div className="row">
          <span className="row__label">Build</span>
          <span className="row__value row__value--mono">{about.build ?? 'Local build'}</span>
        </div>
        <div className="row">
          <span className="row__label">Platform</span>
          <span className="row__value">
            {about.platform}
            {about.supported ? '' : ' (not supported by this build)'}
          </span>
        </div>
        <div className="row">
          <span className="row__label">Device key storage</span>
          {/* The store's name. Never anything in it. */}
          <span className="row__value">{about.keyStorage}</span>
        </div>
        {about.logFile ? (
          <div className="row">
            <span className="row__label">Log file</span>
            {/* The path. The agent has no command that reads it back. */}
            <span className="row__value row__value--mono">{about.logFile}</span>
          </div>
        ) : null}
        <div className="row">
          <span className="row__label">Background service</span>
          <span className="row__value">
            {about.service.running ? (
              <StatusPill tone="ready">Running{about.service.version ? ` · ${about.service.version}` : ''}</StatusPill>
            ) : (
              <StatusPill tone="attention">Not running</StatusPill>
            )}
          </span>
        </div>
      </div>

      {about.service.detail ? (
        <div className="notice notice--info" style={{ marginTop: 'var(--space-4)' }}>
          {about.service.detail}
        </div>
      ) : null}
    </section>
  )
}
