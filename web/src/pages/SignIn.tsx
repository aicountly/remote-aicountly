import { ShieldCheck } from 'lucide-react'

import { useAuth } from '../auth/AuthProvider'
import AicountlyLogo, { RemoteMark } from '../components/brand/AicountlyLogo'

/**
 * Shown only when the automatic portal jump did not happen: after a deliberate
 * sign-out, or when sign-in failed and bouncing back to the portal would just
 * repeat it.
 *
 * Signing in is the portal's job — Remote issues no credential of its own for
 * an AICOUNTLY user — so this screen only points at it.
 */
export default function SignIn() {
  const { message, signIn } = useAuth()

  return (
    <main className="guest-page">
      <div className="guest-card">
        <div className="guest-card__brand">
          <AicountlyLogo />
          <span className="guest-card__product">
            <RemoteMark size={16} />
            Remote
          </span>
        </div>

        <h1 className="guest-card__title">Secure browser assistance</h1>
        <p className="guest-card__body">
          {message ?? 'You have been signed out.'}
        </p>

        <button type="button" className="btn btn--primary btn--lg btn--block" onClick={signIn}>
          Sign in with AICOUNTLY
        </button>

        <p className="guest-card__note">
          <ShieldCheck size={14} aria-hidden="true" />
          Remote never asks for your password. You sign in through AICOUNTLY, the same as every other
          AICOUNTLY product.
        </p>
      </div>
    </main>
  )
}
