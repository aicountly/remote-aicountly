/**
 * What an unauthenticated machine shows instead of the application.
 *
 * # Signing in happens on the AICOUNTLY sign-in page
 *
 * This application never sees a password. Signing in opens the ordinary
 * AICOUNTLY sign-in page in a web view inside this window (the portal, see
 * `src-tauri/src/portal.rs`) — the same page, on the same site, as in a
 * browser, and the same sign-in then gives the portal's menus here. Some
 * identity providers refuse to render a login form in anything that looks
 * like an embedded web view, so the confirmation step also offers to open in
 * the person's own browser, where their password manager, second factor and
 * Google, LinkedIn or Outlook all work exactly as they do everywhere else.
 *
 * What comes back is not a browser session. It is an enrolment: this computer
 * generates a keypair, keeps the private half in the platform's key store, and
 * from then on proves who it is by signing a challenge. That is why signing in
 * happens **once** — a cookie would expire, belong to one person, and be
 * useless to the background service, which has no window to hold one.
 */
export function SignInScreen({
  revoked,
  deviceId,
  onSignIn,
  busy,
}: {
  /** The device was enrolled and an administrator removed it. */
  revoked: boolean
  /** This computer's ID, or null while it is still being read. */
  deviceId: string | null
  onSignIn: () => void
  busy?: boolean
}) {
  return (
    <section className="card">
      <h2 className="card__title">Sign in to AICOUNTLY</h2>
      <p className="card__subtitle">
        {revoked
          ? 'This computer was signed out of AICOUNTLY Remote. Sign in again to start using it.'
          : 'Sign in to let the people you approve connect to this computer.'}
      </p>

      {/*
        The ID is shown before anybody signs in, because somebody reading it
        out to a colleague is the first thing that happens with a tool like
        this. It is a hash of a public key and reaches nothing on its own:
        nobody can connect to this computer, and this computer will accept no
        incoming request, until the sign-in below has finished.
      */}
      <div className="card__rows" style={{ marginTop: 'var(--space-4)' }}>
        <div className="row">
          <span className="row__label">This computer's ID</span>
          <span className="row__value row__value--mono">{deviceId ?? '—'}</span>
        </div>
      </div>

      <ol className="card__rows" style={{ marginTop: 'var(--space-4)' }}>
        <li className="row">
          <span className="row__label">1</span>
          <span className="row__value">
            The AICOUNTLY sign-in page opens in this window, where you sign in as you always do — or
            in your browser, if you prefer.
          </span>
        </li>
        <li className="row">
          <span className="row__label">2</span>
          <span className="row__value">
            Check that the code on that page matches the one shown here, and confirm.
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
        Nobody can connect to this computer until you sign in. Your password goes only to the
        AICOUNTLY sign-in page; this application never sees it.
      </p>
    </section>
  )
}
