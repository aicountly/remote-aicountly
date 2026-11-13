import { useEffect, useRef } from 'react'

import type { SessionSummary } from '../../types/agent'

/**
 * The consent dialog for attended remote control.
 *
 * The wording is the security control. Somebody is about to be able to move
 * this person's mouse and type on their keyboard, and the screen that asks has
 * to say exactly that — not "allow access", not "grant permission", but what
 * the other person will be able to do.
 *
 * Four things are always said: **who** is asking, **that this should only be
 * somebody the person knows and asked for help** — remote-control tools are
 * what phone scammers ask their victims to open, and this is the screen such
 * a call ends at — **what control means**, and **how to stop it**. Clipboard
 * sharing is a separate tick, off by default, because control and clipboard
 * are different exposures and starting one must not silently start the other.
 *
 * The keyboard goes to the dialog when it appears, not to either button: no
 * key pressed a moment too late, meant for something else, can answer it.
 * Esc is "Not now".
 */
export function ControlRequest({
  session,
  requesterName,
  computerName = 'computer',
  stopShortcut = null,
  clipboardAllowedByPolicy,
  shareClipboard,
  onShareClipboardChange,
  secureDesktopSupported,
  answerSecureDesktop,
  onAnswerSecureDesktopChange,
  onAllow,
  onDeny,
  busy,
}: {
  session: SessionSummary
  requesterName: string
  /** "Mac" or "computer". */
  computerName?: string
  /** The system-wide Stop control shortcut, as this platform writes it. */
  stopShortcut?: string | null
  clipboardAllowedByPolicy: boolean
  shareClipboard: boolean
  onShareClipboardChange: (value: boolean) => void
  /** Whether this computer can answer the Secure Desktop at all (Windows). */
  secureDesktopSupported: boolean
  answerSecureDesktop: boolean
  onAnswerSecureDesktopChange: (value: boolean) => void
  onAllow: () => void
  onDeny: () => void
  busy?: boolean
}) {
  const dialog = useRef<HTMLElement>(null)

  useEffect(() => {
    dialog.current?.focus()
  }, [])

  return (
    <section
      ref={dialog}
      className="card consent"
      role="alertdialog"
      aria-labelledby="control-request-title"
      aria-describedby="control-request-meaning"
      tabIndex={-1}
      onKeyDown={(event) => {
        if (event.key === 'Escape' && !busy) onDeny()
      }}
    >
      <h2 className="card__title" id="control-request-title">
        {requesterName} is asking to control this {computerName}
      </h2>
      {session.companyName ? <p className="card__subtitle">From {session.companyName}.</p> : null}

      <div className="notice notice--danger consent__caution">
        <p>
          <strong>Only allow this if you know {requesterName} and asked them for help.</strong> AICOUNTLY never calls or
          messages anybody to ask for control of their {computerName}. If you were not expecting this, choose{' '}
          <strong>Not now</strong>.
        </p>
      </div>

      <div
        className="notice notice--warning"
        id="control-request-meaning"
        style={{ marginBottom: 'var(--space-4)' }}
      >
        <p className="notice__title">What allowing this means</p>
        <p>
          {requesterName} will be able to move your mouse, type on your keyboard and open
          anything you can open on this {computerName}.
        </p>
        <p>
          You can stop it at any moment with <strong>Stop control</strong> — at the top of this window, from the
          AICOUNTLY Remote icon {computerName === 'Mac' ? 'in the menu bar' : 'beside the clock'}
          {stopShortcut ? (
            <>
              , or by pressing <kbd>{stopShortcut}</kbd>
            </>
          ) : null}
          . Stopping takes effect immediately and does not need their agreement.
        </p>
      </div>

      {clipboardAllowedByPolicy ? (
        <label className="field field--inline">
          <span>
            <span className="field__label">Also share the clipboard</span>
            <span className="field__hint">
              Text {requesterName} copies can be pasted on this {computerName}. Nothing you copy here is
              sent to them. Off unless you tick it.
            </span>
          </span>
          <input
            type="checkbox"
            checked={shareClipboard}
            onChange={(event) => onShareClipboardChange(event.target.checked)}
          />
        </label>
      ) : null}

      {secureDesktopSupported ? (
        <label className="field field--inline">
          <span>
            <span className="field__label">Also let them answer Windows administrator prompts</span>
            <span className="field__hint">
              Lets {requesterName} see and answer the Windows prompts that normally only you can —
              the “Do you want to allow this app to make changes?” box, and the lock screen. Off
              unless you tick it, and it stops the moment you stop control.
            </span>
          </span>
          <input
            type="checkbox"
            checked={answerSecureDesktop}
            onChange={(event) => onAnswerSecureDesktopChange(event.target.checked)}
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
