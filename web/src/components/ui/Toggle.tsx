import { useId } from 'react'

/**
 * A labelled switch for the policy screens (§40).
 *
 * Built on a real checkbox with `role="switch"` so it is operable and
 * announced correctly without any keyboard handling of its own. `explanation`
 * is the sentence that sits under a security-sensitive option — the spec asks
 * for it, and it is the difference between an administrator understanding what
 * "Entire Screen" costs and simply switching it on.
 */

interface Props {
  checked: boolean
  onChange: (checked: boolean) => void
  label: string
  explanation?: string
  disabled?: boolean
  /** Why it is disabled — shown instead of leaving a dead control unexplained. */
  disabledReason?: string
}

export default function Toggle({
  checked,
  onChange,
  label,
  explanation,
  disabled = false,
  disabledReason,
}: Props) {
  const id = useId()
  const describedBy = explanation || disabledReason ? `${id}-description` : undefined

  return (
    <div className={disabled ? 'toggle toggle--disabled' : 'toggle'}>
      <div className="toggle__text">
        <label className="toggle__label" htmlFor={id}>
          {label}
        </label>
        {explanation || disabledReason ? (
          <p id={describedBy} className="toggle__explanation">
            {disabled && disabledReason ? disabledReason : explanation}
          </p>
        ) : null}
      </div>

      <input
        id={id}
        type="checkbox"
        role="switch"
        className="toggle__input"
        checked={checked}
        disabled={disabled}
        aria-describedby={describedBy}
        onChange={(event) => onChange(event.target.checked)}
      />
      <span className="toggle__track" aria-hidden="true">
        <span className="toggle__thumb" />
      </span>
    </div>
  )
}
