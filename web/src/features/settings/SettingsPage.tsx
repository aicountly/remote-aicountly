import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import { Check, Minus } from 'lucide-react'

import { useDesktopAgentEnabled, useRemote } from '../../app/RemoteProvider'
import { APP_ENV, APP_NAME } from '../../config'
import { describePermission } from '../../utils/format'
import TwoStepSettings from './TwoStepSettings'

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
 *
 * The one thing a person does set here is their own two-step verification,
 * which the Computers page and the connect page send them to
 * (`/settings#two-step`) when unattended access needs it.
 */
export default function SettingsPage() {
  const { bootstrap, policy, capabilities } = useRemote()
  const desktopAgent = useDesktopAgentEnabled()
  const { hash } = useLocation()

  // The router does not scroll to a fragment by itself.
  useEffect(() => {
    if (hash === '#two-step') document.getElementById('two-step')?.scrollIntoView?.({ block: 'start' })
  }, [hash])

  const grantedPermissions = Object.entries(policy?.permissions ?? {})
    .filter(([, granted]) => granted)
    .map(([permission]) => permission)

  return (
    <div className="page page--narrow">
      <div className="stack stack--lg">
        <header className="stack stack--sm">
          <h1>Settings</h1>
          <p className="muted">Your two-step verification, what this browser supports, and what your account can do in Remote.</p>
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
              <Row label="Account" value="Personal" />
            </dl>
          </div>
        </section>

        <TwoStepSettings />

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
                note="Your browser reports which surface you picked, so the sharing rules for this session can be verified. Firefox and Safari do not report this."
              />
              <Capability
                supported={capabilities.safeShareContext}
                label="AICOUNTLY Safe Share verification"
                note="Lets Remote confirm that a shared AICOUNTLY tab belongs to this session. Available in Chromium-based browsers."
              />
            </ul>
          </div>
        </section>

        <section className="card">
          <div className="card__header">
            <div>
              <h2 className="card__title">What you can do here</h2>
              <p className="card__subtitle">
                What your AICOUNTLY account allows in Remote.
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
                    {describePermission(permission, desktopAgent)}
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
              <Row
                label="Works in"
                value={desktopAgent ? 'This browser, and the AICOUNTLY Remote app for Windows and Mac' : 'This browser — nothing to install'}
              />
            </dl>

            {/* §90 — what the product is, said for the deployment it is on. This used to read "It does
                not control your computer" on a deployment where a helper, once allowed, does exactly that. */}
            <p className="tiny muted about-note">
              {desktopAgent
                ? 'In a browser, someone shares a screen and someone else views it. A browser can never let anybody ' +
                  'control a computer: that needs the AICOUNTLY Remote app on the computer being helped, and the ' +
                  'person at it is asked each time and can stop it at any moment. A computer with nobody at it can ' +
                  'be reached only if its owner turned unattended access on.'
                : 'Remote currently supports attended browser assistance: someone shares a screen and someone else ' +
                  'views it, with permission. It does not control your computer.'}
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
