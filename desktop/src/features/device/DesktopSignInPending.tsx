import type { DesktopSignInStart } from '../../types/agent'

/**
 * Waiting for a device-code sign-in to be confirmed (docs/desktop/DEVICE_ENROLMENT.md).
 *
 * This screen is deliberately passive: the code is shown so a person can
 * match it against the confirmation page, and everything else — choosing the
 * organisation, signing in, confirming — happens over there, in a browser
 * that already knows how to do all three. This window only polls.
 */
export function DesktopSignInPending({
  start,
  outcome,
  onCancel,
  onRetry,
  busy,
}: {
  start: DesktopSignInStart
  outcome: 'pending' | 'denied' | 'expired'
  onCancel: () => void
  onRetry: () => void
  busy?: boolean
}) {
  return (
    <section className="card">
      <h2 className="card__title">Sign in to register this device</h2>

      {outcome === 'pending' ? (
        <>
          <p className="card__subtitle">
            AICOUNTLY Remote opened a browser tab. Check that it shows the same code as below, then
            confirm there.
          </p>

          <div className="field" style={{ alignItems: 'center', textAlign: 'center' }}>
            <span
              className="mono"
              style={{ fontSize: '1.75rem', letterSpacing: '0.18em', fontWeight: 600 }}
              aria-live="polite"
            >
              {start.userCode}
            </span>
            <span className="field__hint">Waiting for confirmation…</span>
          </div>

          <div className="notice notice--info" style={{ marginTop: 'var(--space-4)' }}>
            <p className="notice__title">What happens next</p>
            <p>
              A browser tab opened to AICOUNTLY Remote. Sign in there if you are not already, choose
              which organisation this computer belongs to, and confirm the code matches. This window
              picks it up automatically — nothing to do here.
            </p>
          </div>

          <div style={{ marginTop: 'var(--space-4)' }}>
            <button type="button" className="btn btn--secondary" onClick={onCancel} disabled={busy}>
              Cancel
            </button>
          </div>
        </>
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
