import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import { connectToDevice } from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import RestrictionNotice from '../../components/ui/RestrictionNotice'
import TwoStepSetupLink from '../twoStep/TwoStepSetupLink'

/**
 * `/connect/:deviceUuid` — connect to one of your own computers with nobody
 * at it, and go straight to the session.
 *
 * The desktop application's "Your computers" list opens this in its portal:
 * the window holds no AICOUNTLY sign-in of its own, so the connection is made
 * here, by the person signed in to the portal, exactly as the Connect button
 * on the Computers page makes it. Once, even under StrictMode: a second
 * request would start a second session.
 *
 * Connecting with nobody at the computer needs Remote's own two-step
 * verification. When this browser's has run out, the API client asks for a
 * code in the one prompt the whole app shares and sends the request again, so
 * the person lands in the session as before. When they have never turned it
 * on, the refusal says so and this page links to the setting.
 */
export default function ConnectDevicePage() {
  const { deviceUuid = '' } = useParams()
  const navigate = useNavigate()
  const [error, setError] = useState<RemoteApiError | null>(null)
  const started = useRef(false)

  useEffect(() => {
    if (started.current) return
    started.current = true

    connectToDevice(deviceUuid).then(
      ({ session }) => navigate(`/room/${session.uuid}`, { replace: true }),
      (err: unknown) =>
        setError(
          err instanceof RemoteApiError ? err : new RemoteApiError('UNKNOWN', 'That computer could not be reached.', 0),
        ),
    )
  }, [deviceUuid, navigate])

  return (
    <div className="page">
      <div className="stack">
        <h1>Connecting…</h1>
        {error ? (
          <>
            <RestrictionNotice
              error={error}
              action={error.code === 'TWO_STEP_SETUP_REQUIRED' ? <TwoStepSetupLink /> : undefined}
            />
            <p>
              <Link to="/devices" className="btn btn--secondary">
                Back to your computers
              </Link>
            </p>
          </>
        ) : (
          <p className="muted">Asking the computer to share its screen. It joins within a few seconds when it is online.</p>
        )}
      </div>
    </div>
  )
}
