import { useState } from 'react'
import { X } from 'lucide-react'

import { RemoteMark } from '../../components/brand/AicountlyLogo'

/**
 * First-run introduction (§89, §90).
 *
 * One card, dismissed permanently on the first close — not a wizard. The real
 * teaching happens contextually the first time somebody shares, which is where
 * the consent step already explains what is about to happen (§30).
 *
 * It is also where Remote is honest about what browser V1 is: attended
 * assistance, no installation. It does not advertise desktop features that do
 * not exist yet.
 */

const DISMISSED_KEY = 'remote:welcomeDismissed'

function alreadyDismissed(): boolean {
  try {
    return localStorage.getItem(DISMISSED_KEY) === '1'
  } catch {
    return false
  }
}

export default function WelcomeCard() {
  const [dismissed, setDismissed] = useState(alreadyDismissed)

  if (dismissed) return null

  function dismiss() {
    setDismissed(true)
    try {
      localStorage.setItem(DISMISSED_KEY, '1')
    } catch {
      /* private mode — it reappears next visit, which is acceptable */
    }
  }

  return (
    <aside className="welcome-card">
      <div className="welcome-card__mark" aria-hidden="true">
        <RemoteMark size={22} />
      </div>

      <div className="welcome-card__content">
        <h2 className="welcome-card__title">Welcome to AICOUNTLY Remote</h2>
        <p className="welcome-card__body">
          Securely share your screen and get assistance without installing anything. Remote works in your
          browser and shows exactly what you choose to share — you approve every viewer, and you can stop
          at any moment.
        </p>
      </div>

      <button type="button" className="btn btn--ghost btn--sm" onClick={dismiss} aria-label="Dismiss introduction">
        <X size={16} aria-hidden="true" />
      </button>
    </aside>
  )
}
