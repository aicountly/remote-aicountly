import type { DesktopSignInStart } from '../../types/agent'

/**
 * Waiting for a device-code sign-in to be confirmed (docs/desktop/DEVICE_ENROLMENT.md).
 *
 * This screen is deliberately passive: the code is shown so a person can
 * match it against the confirmation page, and everything else — signing in
 * and confirming — happens on that page. It opens inside this window, below
 * this card (`PortalView`), and the same sign-in then gives the portal's
 * menus in this window too. Some identity providers refuse to sign anybody in
 * from an embedded web view, so the page can also be opened in the person's
 * own browser instead, where the flow is exactly what it always was.
 *
 * There is no organisation to choose: the computer belongs to the person who
 * confirms it. This window only polls.
 */
export function DesktopSignInPending({
  start,
  outcome,
  onCancel,
  onRetry,
  onOpenInBrowser,
  busy,
}: {
  start: DesktopSignInStart
  outcome: 'pending' | 'denied' | 'expired'
  onCancel: () => void
  onRetry: () => void
  /** Confirm in the person's own browser rather than in this window. */
  onOpenInBrowser?: () => void
  busy?: boolean
}) {
  return (
    <section className="card">
      <h2 className="card__title">Sign in to register this device</h2>

      {outcome === 'pending' ? (
        <div className="signin-pending">
          <div className="signin-pending__code">
            <span
              className="mono"
              style={{ fontSize: '1.5rem', letterSpacing: '0.18em', fontWeight: 600 }}
              aria-live="polite"
            >
              {start.userCode}
            </span>
            <span className="field__hint">Waiting for confirmation…</span>
          </div>

          <p className="signin-pending__text">
            Sign in below if you are asked to, check that the page shows this code, then confirm.
            This window picks it up automatically.
          </p>

          <div className="signin-pending__actions">
            {onOpenInBrowser ? (
              <button type="button" className="btn btn--secondary" onClick={onOpenInBrowser} disabled={busy}>
                Open in browser instead
              </button>
            ) : null}
            <button type="button" className="btn btn--secondary" onClick={onCancel} disabled={busy}>
              Cancel
            </button>
          </div>
        </div>
      ) : outcome === 'denied' ? (
        <>
          <div className="notice notice--danger">
            <p className="notice__title">Sign-in declined</p>
            <p>This computer was not registered. Nobody confirmed the code, or somebody declined it.</p>
          </div>
          <div style={{ marginTop: 'var(--space-4)', display: 'flex', gap: 'var(--space-2)' }}>
            <button type="button" className="btn btn--primary" onClick={onRetry} disabled={busy}>
              Try again
            </button>
            <button type="button" className="btn btn--secondary" onClick={onCancel} disabled={busy}>
              Cancel
            </button>
          </div>
        </>
      ) : (
        <>
          <div className="notice notice--danger">
            <p className="notice__title">This code expired</p>
            <p>Codes are only good for a few minutes. Start again to get a new one.</p>
          </div>
          <div style={{ marginTop: 'var(--space-4)', display: 'flex', gap: 'var(--space-2)' }}>
            <button type="button" className="btn btn--primary" onClick={onRetry} disabled={busy}>
              Start again
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
