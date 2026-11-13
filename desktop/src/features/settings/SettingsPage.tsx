import { useState } from 'react'

import * as bridge from '../../services/tauri'
import type { About, AgentConfig } from '../../types/agent'
import { OPENH264_ATTRIBUTION } from '../../types/agent'
import { StatusPill } from '../../components/StatusPill'
import { OPENH264_LICENCE } from './licences'
import { VideoCodecSettings } from './VideoCodec'

/**
 * Settings, and the diagnostics a support engineer asks for.
 *
 * What is deliberately absent: anything that is or contains a secret. The
 * device key's *location* is shown; the key is not. There is no field for a
 * token, because the agent has none to store — it proves possession of its key
 * and receives a credential that lives for minutes in memory.
 *
 * The order is what people change, most likely first. The address this
 * computer connects to — which signs it out if it is changed — was the first
 * field on the page; it is under "Advanced" now.
 */
export function SettingsPage({
  config,
  about,
  onSave,
  onConfigChange,
  busy,
}: {
  config: AgentConfig
  about: About | null
  /** Saves the settings. Resolves to whether they were saved, where it knows. */
  onSave: (config: AgentConfig) => Promise<boolean | void> | boolean | void
  /** The configuration changed outside the form — the codec's switch. */
  onConfigChange?: (config: AgentConfig) => void
  busy?: boolean
}) {
  const [draft, setDraft] = useState(config)
  // "Saved", once a save has gone through: a button that changes nothing on
  // screen leaves somebody wondering whether it did anything.
  const [saved, setSaved] = useState(false)

  const save = async (next: AgentConfig) => {
    setSaved(false)

    if ((await onSave(next)) === true) setSaved(true)
  }

  const mac = about?.platform === 'macOS'

  return (
    <>
      <section className="card">
        <h2 className="card__title">Settings</h2>
        <p className="card__subtitle">How AICOUNTLY Remote behaves on this computer.</p>

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
              Closing the window hides it. This computer stays reachable, and the icon{' '}
              {mac ? 'in the menu bar' : 'beside the clock'} stays there.
            </span>
          </span>
          <input
            type="checkbox"
            checked={draft.runInBackground}
            onChange={(event) => setDraft({ ...draft, runInBackground: event.target.checked })}
            disabled={busy}
          />
        </label>

        {about?.supportsSound ? (
          <label className="field field--inline">
            <span>
              <span className="field__label">Share this computer’s sound</span>
              <span className="field__hint">
                When you show this computer’s screen to somebody, let them hear what it is playing
                as well. Off until you turn it on. It takes effect the moment you tick or untick it,
                even during a session — the sound stops at once. Nothing is listened to while it is off.
              </span>
            </span>
            <input
              type="checkbox"
              checked={draft.shareSound}
              onChange={(event) => {
                const shareSound = event.target.checked

                setDraft({ ...draft, shareSound })
                // Now, and by itself: the sentence beside it promises "at
                // once", and it used to wait for Save settings. Saved onto the
                // settings as they were last saved, not onto whatever else in
                // this form is half typed.
                void save({ ...config, shareSound })
              }}
              disabled={busy}
            />
          </label>
        ) : null}

        {/* Windows only: a Mac has neither the prompts nor the lock-screen helper. */}
        {about?.supportsSecureDesktop ? (
          <label className="field field--inline">
            <span>
              <span className="field__label">
                Let my unattended connections answer administrator prompts and the lock screen
              </span>
              <span className="field__hint">
                When you connect to this computer while nobody is here, this lets you answer the
                Windows “Do you want to allow this app to make changes?” prompts and the lock
                screen. Off by default. With somebody here, they are asked each time instead.
              </span>
            </span>
            <input
              type="checkbox"
              checked={draft.allowSecureDesktopUnattended}
              onChange={(event) =>
                setDraft({ ...draft, allowSecureDesktopUnattended: event.target.checked })
              }
              disabled={busy}
            />
          </label>
        ) : null}

        {about?.platform === 'Windows' ? (
          <p className="field__hint" style={{ marginBottom: 'var(--space-4)' }}>
            AICOUNTLY Remote starts quietly in the tray when anyone signs in to Windows. To stop that,
            turn it off in Task Manager under Startup apps.
          </p>
        ) : null}

        <details className="details" style={{ marginBottom: 'var(--space-4)' }}>
          <summary className="details__summary">Advanced</summary>
          <div className="field" style={{ marginBottom: 0 }}>
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
              Leave this as it is unless AICOUNTLY support asks you to change it. It must be an https
              address, and changing it signs this computer out until it is signed in again.
            </span>
          </div>
        </details>

        <div className="session-banner__actions" style={{ alignItems: 'center' }}>
          <button type="button" className="btn btn--primary" onClick={() => void save(draft)} disabled={busy}>
            Save settings
          </button>
          <span className="saved" role="status">
            {saved ? 'Saved' : ''}
          </span>
        </div>
      </section>

      <VideoCodecSettings onConfigChange={onConfigChange} />

      {about ? <Diagnostics about={about} /> : null}
    </>
  )
}

function Diagnostics({ about }: { about: About }) {
  // The background service is Windows' — it is what answers while nobody is
  // signed in, and what a Mac does not have. Its row used to say "Not running"
  // on every Mac, beside a card that said the computer was ready.
  const hasService = about.platform === 'Windows'

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
            <span className="row__value">
              {/* The path. The agent has no command that reads it back. */}
              <span className="row__value--mono" style={{ display: 'block', fontWeight: 400 }}>
                {about.logFile}
              </span>
              {/*
                For "can you send me the log": the folder opens in Explorer or
                Finder and the person attaches the file themselves. Nothing is
                read or sent from here.
              */}
              <button
                type="button"
                className="link-button"
                onClick={() => void bridge.showLogFolder().catch(() => undefined)}
              >
                Open the folder
              </button>
            </span>
          </div>
        ) : null}
        {hasService ? (
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
        ) : null}
        <div className="row">
          <span className="row__label">Privacy</span>
          <span className="row__value">
            <button
              type="button"
              className="link-button"
              onClick={() => void bridge.openUrl(about.privacyPolicyUrl).catch(() => undefined)}
            >
              Privacy policy
            </button>
          </span>
        </div>
      </div>

      {hasService && about.service.detail ? (
        <div className="notice notice--info" style={{ marginTop: 'var(--space-4)' }}>
          {sentenceCase(about.service.detail)}
        </div>
      ) : null}

      <ThirdPartyLicences />
    </section>
  )
}

/** The service's own words, which begin in lower case, as a sentence. */
function sentenceCase(text: string): string {
  const trimmed = text.trim()
  const capitalised = trimmed.charAt(0).toUpperCase() + trimmed.slice(1)

  return /[.!?]$/.test(capitalised) ? capitalised : `${capitalised}.`
}

/**
 * Licences of software this application uses that is not AICOUNTLY's — where
 * "licensing information is presented to the end user". Cisco's licence for
 * its OpenH264 binary asks for its whole text to be reproduced here, the
 * condition that asks for that included, so it is shown in full, as Cisco
 * publishes it, and not summarised.
 */
function ThirdPartyLicences() {
  return (
    <details className="licences">
      <summary className="licences__summary">Third-party licences</summary>
      <h3 className="licences__title">{OPENH264_ATTRIBUTION}</h3>
      <p className="field__hint">
        Cisco&rsquo;s OpenH264 binary is downloaded from Cisco onto this computer, not supplied with AICOUNTLY Remote,
        and Cisco licenses it on these terms.
      </p>
      <pre className="licences__text" aria-label="OpenH264 binary licence">
        {OPENH264_LICENCE}
      </pre>
    </details>
  )
}
