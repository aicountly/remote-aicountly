import { useState } from 'react'

import type { CompanyOption } from '../../types/agent'

/**
 * Registering this machine.
 *
 * Two things the screen has to be honest about, and both are said before
 * anything happens:
 *
 *   * **the keypair is created here.** The private half goes into Windows'
 *     own protected store and is never sent anywhere. Nothing on this screen
 *     transmits a secret, and the text says so;
 *   * **registering does not make the machine reachable when nobody is at it.**
 *     That is unattended access, it is a separate decision, and this screen
 *     does not offer it — precisely so it cannot be ticked by somebody who was
 *     only trying to register.
 */
export function RegisterDevice({
  companies,
  defaultName,
  onRegister,
  onCancel,
  busy,
  error,
}: {
  companies: CompanyOption[]
  defaultName: string
  onRegister: (companyId: number, deviceName: string) => void
  onCancel: () => void
  busy?: boolean
  error?: string | null
}) {
  const enrollable = companies.filter((company) => company.canEnrol)

  const [companyId, setCompanyId] = useState<number | null>(enrollable[0]?.companyId ?? null)
  const [deviceName, setDeviceName] = useState(defaultName)

  return (
    <section className="card">
      <h2 className="card__title">Register this device</h2>
      <p className="card__subtitle">
        So an authorised colleague can help you on this computer during a Remote session.
      </p>

      {error ? (
        <div className="notice notice--danger" style={{ marginBottom: 'var(--space-4)' }}>
          {error}
        </div>
      ) : null}

      {enrollable.length === 0 ? (
        <div className="notice notice--info">
          {companies.length === 0
            ? 'You are not a member of an organisation that uses AICOUNTLY Remote.'
            : 'None of your organisations allows registering a device. An administrator turns this on for the whole organisation.'}
        </div>
      ) : (
        <>
          <div className="field">
            <label className="field__label" htmlFor="company">
              Organisation
            </label>
            <select
              id="company"
              value={companyId ?? ''}
              onChange={(event) => setCompanyId(Number(event.target.value))}
              disabled={busy}
            >
              {enrollable.map((company) => (
                <option key={company.companyId} value={company.companyId}>
                  {company.name}
                </option>
              ))}
            </select>
            <span className="field__hint">
              This device belongs to one organisation. Its policy decides what a colleague may do
              during a session.
            </span>
          </div>

          <div className="field">
            <label className="field__label" htmlFor="device-name">
              Device name
            </label>
            <input
              id="device-name"
              type="text"
              value={deviceName}
              maxLength={160}
              onChange={(event) => setDeviceName(event.target.value)}
              disabled={busy}
            />
            <span className="field__hint">
              What an administrator will see in the device list. Your computer’s own name is
              filled in.
            </span>
          </div>

          <div className="notice notice--info" style={{ marginBottom: 'var(--space-4)' }}>
            <p className="notice__title">What registering does, and does not do</p>
            <p>
              This computer creates a private key and keeps it in Windows’ own protected storage.
              It is never sent to AICOUNTLY and never leaves this machine — only the matching
              public key is registered.
            </p>
            <p>
              Registering <strong>does not</strong> let anybody connect while you are away. That
              is unattended access, and it is a separate choice you make afterwards.
            </p>
          </div>

          <div style={{ display: 'flex', gap: 'var(--space-2)' }}>
            <button
              type="button"
              className="btn btn--primary"
              disabled={busy || companyId === null || deviceName.trim() === ''}
              onClick={() => companyId !== null && onRegister(companyId, deviceName.trim())}
            >
              Register this device
            </button>
            <button type="button" className="btn btn--secondary" onClick={onCancel} disabled={busy}>
              Cancel
            </button>
          </div>
        </>
      )}
    </section>
  )
}
