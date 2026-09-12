import type { SessionSummary } from '../../types/agent'

/**
 * The consent dialog for attended remote control.
 *
 * The wording is the security control. Somebody is about to be able to move
 * this person's mouse and type on their keyboard, and the screen that asks has
 * to say exactly that — not "allow access", not "grant permission", but what
 * the other person will be able to do.
 *
 * Three things are always named: **who**, **which organisation**, and **what
 * control means**. Clipboard sharing is a separate tick, off by default,
 * because control and clipboard are different exposures and starting one must
 * not silently start the other.
 */
export function ControlRequest({
  session,
  requesterName,
  clipboardAllowedByPolicy,
  shareClipboard,
  onShareClipboardChange,
  onAllow,
  onDeny,
  busy,
}: {
  session: SessionSummary
  requesterName: string
  clipboardAllowedByPolicy: boolean
  shareClipboard: boolean
  onShareClipboardChange: (value: boolean) => void
  onAllow: () => void
  onDeny: () => void
  busy?: boolean
}) {
  return (
    <section className="card" role="alertdialog" aria-label="Someone is asking to control this computer">
      <h2 className="card__title">{requesterName} is asking to control this computer</h2>
      <p className="card__subtitle">
        {session.companyName
          ? `From ${session.companyName}, in session ${session.displayId}.`
          : `In session ${session.displayId}.`}
      </p>

      <div className="notice notice--warning" style={{ marginBottom: 'var(--space-4)' }}>
        <p className="notice__title">What allowing this means</p>
        <p>
          {requesterName} will be able to move your mouse, type on your keyboard and open
          anything you can open on this computer.
        </p>
        <p>
          You can stop it at any moment with <strong>Stop control</strong> — in this window or
          from the AICOUNTLY Remote icon beside the clock. Stopping takes effect immediately
          and does not need their agreement.
        </p>
      </div>

      {clipboardAllowedByPolicy ? (
        <label className="field field--inline">
          <span>
            <span className="field__label">Also share the clipboard</span>
            <span className="field__hint">
              Text you copy can be pasted by {requesterName}, and text they copy can be pasted
              here. Off unless you tick it.
            </span>
          </span>
          <input
            type="checkbox"
            checked={shareClipboard}
            onChange={(event) => onShareClipboardChange(event.target.checked)}
          />
        </label>
      ) : null}

      <div className="session-banner__actions">
        <button type="button" className="btn btn--primary" onClick={onAllow} disabled={busy}>
          Allow control
        </button>
        <button type="button" className="btn btn--secondary" onClick={onDeny} disabled={busy}>
          Not now
        </button>
      </div>
    </section>
  )
}
