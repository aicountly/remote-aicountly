import { Link } from 'react-router-dom'
import { KeyRound } from 'lucide-react'

/**
 * Where to turn two-step verification on — the link every
 * `TWO_STEP_SETUP_REQUIRED` refusal ends with, so the person is told what to
 * do and taken to it in one step (also inside the desktop application's
 * window, which shows these same pages).
 */
export const TWO_STEP_SETTINGS_PATH = '/settings#two-step'

export default function TwoStepSetupLink({ label = 'Turn on two-step verification' }: { label?: string }) {
  return (
    <Link to={TWO_STEP_SETTINGS_PATH} className="btn btn--primary btn--sm">
      <KeyRound size={14} aria-hidden="true" /> {label}
    </Link>
  )
}
