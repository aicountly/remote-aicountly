import { useId, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'

import { RemoteApiError } from '../../services/api/client'
import type { TwoStepProof } from '../../types/remote'

/**
 * Where a person types a code: the six digits from their authenticator app,
 * or — when the form allows it — one of their recovery codes instead.
 *
 * One form for every place that asks (the verification prompt, confirming a
 * new authenticator, switching it off), so they all read codes the same way
 * and show the server's refusal the same way: its sentence already says
 * whether the code was wrong, already used, or the account is locked for a
 * while, and how many tries are left.
 */

interface Props {
  onSubmit: (proof: TwoStepProof) => Promise<void>
  /** Offer "Use a recovery code" (not while confirming a new authenticator — there are none yet). */
  allowRecoveryCode?: boolean
  submitLabel?: string
  /** Extra buttons beside submit — Cancel, Sign out. */
  secondary?: ReactNode
  autoFocus?: boolean
}

export default function TwoStepCodeForm({
  onSubmit,
  allowRecoveryCode = true,
  submitLabel = 'Verify',
  secondary,
  autoFocus = true,
}: Props) {
  const [useRecovery, setUseRecovery] = useState(false)
  const [value, setValue] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<RemoteApiError | null>(null)
  const inputId = useId()
  const errorId = useId()

  const digits = value.replace(/\D/g, '')
  const ready = useRecovery ? value.replace(/[\s-]/g, '').length === 10 : digits.length === 6

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!ready || busy) return

    setBusy(true)
    setError(null)
    try {
      await onSubmit(useRecovery ? { recoveryCode: value.trim() } : { code: digits })
    } catch (err) {
      setError(err instanceof RemoteApiError ? err : new RemoteApiError('UNKNOWN', 'That could not be checked. Please try again.', 0))
      setValue('')
    } finally {
      setBusy(false)
    }
  }

  function switchMode() {
    setUseRecovery((current) => !current)
    setValue('')
    setError(null)
  }

  return (
    <form className="stack two-step-form" onSubmit={(event) => void submit(event)} noValidate>
      <div className="field">
        <label className="field__label" htmlFor={inputId}>
          {useRecovery ? 'Recovery code' : 'Code from your authenticator app'}
        </label>
        {useRecovery ? (
          <input
            id={inputId}
            className="input two-step-form__input"
            value={value}
            onChange={(event) => setValue(event.target.value.toUpperCase())}
            autoComplete="off"
            autoCapitalize="characters"
            spellCheck={false}
            maxLength={16}
            placeholder="ABCDE-23456"
            aria-invalid={error ? true : undefined}
            aria-describedby={error ? errorId : undefined}
            autoFocus={autoFocus}
          />
        ) : (
          <input
            id={inputId}
            className="input two-step-form__input"
            value={value}
            onChange={(event) => setValue(event.target.value.replace(/[^\d\s]/g, ''))}
            inputMode="numeric"
            autoComplete="one-time-code"
            maxLength={7}
            placeholder="123456"
            aria-invalid={error ? true : undefined}
            aria-describedby={error ? errorId : undefined}
            autoFocus={autoFocus}
          />
        )}
        <span className="field__hint">
          {useRecovery
            ? 'Each recovery code works once. Capitals and the dash do not matter.'
            : 'Six digits. A new one appears every 30 seconds.'}
        </span>
        {error ? (
          <span id={errorId} className="field__error" role="alert">
            {error.message}
          </span>
        ) : null}
      </div>

      <div className="row row--wrap two-step-form__actions">
        <button type="submit" className="btn btn--primary" disabled={!ready || busy}>
          {busy ? 'Checking…' : submitLabel}
        </button>
        {secondary}
      </div>

      {allowRecoveryCode ? (
        <button type="button" className="btn btn--ghost btn--sm two-step-form__switch" onClick={switchMode}>
          {useRecovery ? 'Use your authenticator app instead' : 'Use a recovery code'}
        </button>
      ) : null}
    </form>
  )
}
