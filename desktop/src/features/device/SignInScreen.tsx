/**
 * What an unauthenticated machine shows instead of the application.
 *
 * # Signing in happens in a browser, and that is the point
 *
 * The password is never typed into this window. Signing in opens the ordinary
 * AICOUNTLY sign-in page in the person's own browser, where their password
 * manager, their second factor and Google, LinkedIn or Outlook all work
 * exactly as they do everywhere else — and where identity providers will
 * actually render a login form, which they routinely refuse to do for anything
 * that looks like an embedded web view.
 *
 * What comes back is not a browser session. It is an enrolment: this computer
 * generates a keypair, keeps the private half in the platform's key store, and
 * from then on proves who it is by signing a challenge. That is why signing in
 * happens **once** — a cookie would expire, belong to one person, and be
 * useless to the background service, which has no window to hold one.
 */
export function SignInScreen({
  revoked,
  onSignIn,
  busy,
}: {
  /** The device was enrolled and an administrator removed it. */
  revoked: boolean
  onSignIn: () => void
  busy?: boolean
}) {
  return (
    <section className="card">
      <h2 className="card__title">Sign in to AICOUNTLY</h2>
      <p className="card__subtitle">
        {revoked
          ? 'An administrator removed this computer from AICOUNTLY Remote. Sign in again to start using it.'
          : 'Sign in to let the people you approve connect to this computer.'}
      </p>

      <ol className="card__rows" style={{ marginTop: 'var(--space-4)' }}>
        <li className="row">
          <span className="row__label">1</span>
          <span className="row__value">
            Your browser opens the AICOUNTLY sign-in page, where you sign in as you always do.
          </span>
        </li>
        <li className="row">
          <span className="row__label">2</span>
          <span className="row__value">
            Check that the code in the browser matches the one shown here, and confirm.
          </span>
        </li>
        <li className="row">
          <span className="row__label">3</span>
          <span className="row__value">
            This computer stays signed in. You will not be asked again.
          </span>
        </li>
      </ol>

      <div style={{ marginTop: 'var(--space-4)', display: 'flex', gap: 'var(--space-2)' }}>
        <button type="button" className="btn btn--primary" onClick={onSignIn} disabled={busy}>
          Sign in to AICOUNTLY
        </button>
      </div>

      <p className="card__footnote" style={{ marginTop: 'var(--space-3)' }}>
        Your password is never typed into this window.
      </p>
    </section>
  )
}
