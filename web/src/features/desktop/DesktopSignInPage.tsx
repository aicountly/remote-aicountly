import { useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Laptop, ShieldCheck } from 'lucide-react'

import { useRemote } from '../../app/RemoteProvider'
import { confirmDesktopSignIn, denyDesktopSignIn } from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'

/**
 * Confirming a desktop agent's sign-in code (docs/desktop/DEVICE_ENROLMENT.md).
 *
 * The agent cannot receive a portal redirect the way this tab can — and the
 * loopback pattern that used to stand in for one turned out to depend on the
 * portal accepting a `returnUrl` on a loopback address, which it does not for
 * its own silent sign-in. This page is what replaced it: the agent shows a
 * short code, this tab is already signed in the ordinary way, and a person
 * matching the two is the whole of the security model — nobody who only saw
 * the code over someone's shoulder can reach this page signed in as them.
 *
 * Reached at `/desktop-signin?code=XXXX-XXXX`, opened by the agent in the
 * system browser. If this tab was not already signed in, `AuthProvider` sends
 * it to the portal and back first; `main.tsx` and `AppShell` are what bring it
 * back to this exact page with the code still in the address bar.
 */
export default function DesktopSignInPage() {
  const [params] = useSearchParams()
  const { bootstrap } = useRemote()

  const userCode = useMemo(() => formatCode(params.get('code') ?? ''), [params])
  const companies = bootstrap?.companies ?? []

  const [companyId, setCompanyId] = useState<number | null>(companies[0]?.companyId ?? null)
  const [phase, setPhase] = useState<'idle' | 'busy' | 'confirmed' | 'denied'>('idle')
  const [deviceLabel, setDeviceLabel] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  if (!userCode) {
    return (
      <div className="page page--narrow">
        <div className="stack stack--lg">
          <header className="stack stack--sm">
            <h1>Sign in a computer</h1>
          </header>
          <p className="muted">
            This page confirms a code shown by AICOUNTLY Remote on a computer you are setting up. Open it from
            there — the address it opens already carries the code.
          </p>
        </div>
      </div>
    )
  }

  async function confirm() {
    if (!companyId) {
      setError('Choose which organisation to register this computer in.')
      return
    }

    setPhase('busy')
    setError(null)

    try {
      const result = await confirmDesktopSignIn(userCode, companyId)
      setDeviceLabel(result.deviceLabel)
      setPhase('confirmed')
    } catch (err) {
      setError(err instanceof RemoteApiError ? err.message : 'That could not be confirmed. Please try again.')
      setPhase('idle')
    }
  }

  async function deny() {
    setPhase('busy')
    setError(null)

    try {
      await denyDesktopSignIn(userCode)
      setPhase('denied')
    } catch (err) {
      setError(err instanceof RemoteApiError ? err.message : 'That could not be declined. Please try again.')
      setPhase('idle')
    }
  }

  if (phase === 'confirmed') {
    return (
      <div className="page page--narrow">
        <div className="stack stack--lg">
          <section className="card">
            <div className="card__body stack stack--sm">
              <ShieldCheck size={28} className="tone-success" aria-hidden="true" />
              <h1>You're signed in</h1>
              <p className="muted">
                {deviceLabel ? <>Go back to <strong>{deviceLabel}</strong> — it will finish on its own.</> : 'Go back to your computer — it will finish on its own.'}
              </p>
              <p className="tiny muted">You can close this tab.</p>
            </div>
          </section>
        </div>
      </div>
    )
  }

  if (phase === 'denied') {
    return (
      <div className="page page--narrow">
        <div className="stack stack--lg">
          <section className="card">
            <div className="card__body stack stack--sm">
              <h1>Sign-in declined</h1>
              <p className="muted">
                That computer will not be registered. If this wasn't you, no further action is needed — the code
                has already stopped working.
              </p>
            </div>
          </section>
        </div>
      </div>
    )
  }

  return (
    <div className="page page--narrow">
      <div className="stack stack--lg">
        <header className="stack stack--sm">
          <h1>Sign in a computer</h1>
          <p className="muted">
            Check that this code matches what AICOUNTLY Remote is showing on the computer you are setting up, then
            confirm.
          </p>
        </header>

        <section className="card">
          <div className="card__body stack">
            <div className="stack stack--sm">
              <div className="field__label">
                <Laptop size={14} aria-hidden="true" /> Code shown on the computer
              </div>
              <p className="code-input mono" aria-live="polite">
                {userCode}
              </p>
            </div>

            {companies.length === 0 ? (
              <p className="muted">
                You don't belong to an organisation that allows registering a desktop device. Ask an administrator
                for access, then reopen this page from the computer.
              </p>
            ) : (
              <div className="field">
                <label className="field__label" htmlFor="desktop-signin-company">
                  Register it in
                </label>
                <select
                  id="desktop-signin-company"
                  className="select"
                  value={companyId ?? ''}
                  onChange={(event) => setCompanyId(Number(event.target.value))}
                >
                  {companies.map((company) => (
                    <option key={company.companyId} value={company.companyId}>
                      {company.name}
                    </option>
                  ))}
                </select>
              </div>
            )}

            {error ? (
              <p className="field__error" role="alert">
                {error}
              </p>
            ) : null}

            <div className="stack stack--sm">
              <button
                type="button"
                className="btn btn--primary btn--lg btn--block"
                disabled={phase === 'busy' || companies.length === 0}
                onClick={() => void confirm()}
              >
                {phase === 'busy' ? 'Confirming…' : 'Confirm and sign in'}
              </button>
              <button
                type="button"
                className="btn btn--secondary btn--block"
                disabled={phase === 'busy'}
                onClick={() => void deny()}
              >
                Not me — decline
              </button>
            </div>
          </div>
        </section>

        <p className="tiny muted">Codes expire after a few minutes. If this one has, start again from the computer.</p>
      </div>
    </div>
  )
}

/** Accepts the code with or without its dash, in any case; renders it grouped. */
function formatCode(raw: string): string {
  const stripped = raw.toUpperCase().replace(/[^A-Z0-9]/g, '')
  if (stripped.length !== 8) return ''

  return `${stripped.slice(0, 4)}-${stripped.slice(4)}`
}
