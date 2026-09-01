import { Check, Minus } from 'lucide-react'

import { useRemote } from '../../app/RemoteProvider'
import { APP_ENV, APP_NAME } from '../../config'
import { describePermission } from '../../utils/format'

/**
 * Settings (§88).
 *
 * Not a configuration screen. An ordinary user has nothing to configure here —
 * policy belongs to their organisation and ICE servers belong to the
 * deployment — so this answers the two questions they might actually have:
 * *what can this browser do?* and *what am I allowed to do?*
 *
 * That makes it the first place to look when something is unavailable, which is
 * worth more than a page of switches nobody should be touching.
 */
export default function SettingsPage() {
  const { bootstrap, policy, capabilities } = useRemote()

  const grantedPermissions = Object.entries(policy?.permissions ?? {})
    .filter(([, granted]) => granted)
    .map(([permission]) => permission)

  return (
    <div className="page page--narrow">
      <div className="stack stack--lg">
        <header className="stack stack--sm">
          <h1>Settings</h1>
          <p className="muted">What this browser supports, and what your account can do in Remote.</p>
        </header>

        <section className="card">
          <div className="card__header">
            <div>
              <h2 className="card__title">Your account</h2>
              <p className="card__subtitle">Signed in through AICOUNTLY.</p>
            </div>
          </div>

          <div className="card__body">
            <dl className="detail-list">
              <Row label="Name" value={bootstrap?.user.displayName ?? '—'} />
              <Row label="Email" value={bootstrap?.user.email ?? '—'} />
              <Row
                label="Current context"
                value={policy?.companyName ?? 'Personal (no organisation)'}
              />
              <Row label="Policy preset" value={policy?.policyPreset ?? '—'} />
            </dl>
          </div>
        </section>

        <section className="card">
          <div className="card__header">
            <div>
              <h2 className="card__title">This browser</h2>
              <p className="card__subtitle">
                Remote adapts to what your browser supports rather than assuming.
              </p>
            </div>
          </div>

          <div className="card__body">
            <ul className="capability-list">
              <Capability supported={capabilities.secureContext} label="Secure connection (https)" />
              <Capability supported={capabilities.screenCapture} label="Screen sharing" />
              <Capability supported={capabilities.webRtc} label="Live sessions" />
              <Capability supported={capabilities.dataChannel} label="Chat and annotation over the direct connection" />
              <Capability supported={capabilities.microphone} label="Microphone" />
              <Capability
                supported={capabilities.displaySurfaceDetection}
                label="Sharing-surface verification"
                note="Your browser reports which surface you picked, so your organisation’s sharing rules can be verified. Firefox and Safari do not report this."
              />
              <Capability
                supported={capabilities.safeShareContext}
                label="AICOUNTLY Safe Share verification"
                note="Lets Remote confirm that a shared AICOUNTLY tab belongs to this session’s organisation. Available in Chromium-based browsers."
              />
            </ul>
          </div>
        </section>

        <section className="card">
          <div className="card__header">
            <div>
              <h2 className="card__title">What you can do here</h2>
              <p className="card__subtitle">
                Resolved from your organisation’s policy, your role and your account.
              </p>
            </div>
          </div>

          <div className="card__body">
            {grantedPermissions.length === 0 ? (
              <p className="muted">
                No Remote capabilities are available to you in this context. Contact your AICOUNTLY
                administrator.
              </p>
            ) : (
              <ul className="permission-chips">
                {grantedPermissions.map((permission) => (
                  <li key={permission} className="permission-chip">
                    {describePermission(permission)}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </section>

        <section className="card">
          <div className="card__header">
            <h2 className="card__title">About</h2>
          </div>

          <div className="card__body">
            <dl className="detail-list">
              <Row label="Product" value={`AICOUNTLY ${APP_NAME}`} />
              <Row label="Environment" value={APP_ENV} />
              <Row label="Assistance type" value="Browser assistance — no installation required" />
            </dl>

            {/* §90 — honest about what browser V1 is, without advertising a
                desktop product that does not exist yet. */}
            <p className="tiny muted about-note">
              Remote currently supports attended browser assistance: someone shares a screen and someone else
              views it, with permission. It does not control your computer.
            </p>
          </div>
        </section>
      </div>
    </div>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="detail-list__row">
      <dt>{label}</dt>
      <dd>{value}</dd>
    </div>
  )
}

function Capability({ supported, label, note }: { supported: boolean; label: string; note?: string }) {
  return (
    <li className={supported ? 'capability capability--yes' : 'capability capability--no'}>
      <span className="capability__icon" aria-hidden="true">
        {supported ? <Check size={14} /> : <Minus size={14} />}
      </span>
      <div>
        <p className="capability__label">
          {label}
          <span className="sr-only">{supported ? ': supported' : ': not supported'}</span>
        </p>
        {note ? <p className="capability__note">{note}</p> : null}
      </div>
    </li>
  )
}
