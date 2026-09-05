import type { PermissionState } from '../types/agent'

/**
 * One capability's state, in a word.
 *
 * The wording is the point. `not_applicable` reads "Ready" rather than "Not
 * applicable", because on Windows there is genuinely nothing to grant and
 * telling somebody a permission does not apply invites them to go looking for
 * it. `unsupported` says so plainly rather than pretending.
 */
export function PermissionPill({ state }: { state: PermissionState }) {
  const { tone, label } = describe(state)

  return <span className={`pill pill--${tone}`}>{label}</span>
}

function describe(state: PermissionState): { tone: string; label: string } {
  switch (state) {
    case 'ready':
      return { tone: 'ready', label: 'Ready' }
    case 'not_applicable':
      // Windows asks for no consent to capture the screen or send input. There
      // is nothing for a person to do, so this is not something to flag.
      return { tone: 'ready', label: 'Ready' }
    case 'needs_attention':
      return { tone: 'attention', label: 'Needs attention' }
    case 'denied':
      return { tone: 'danger', label: 'Not allowed' }
    case 'unsupported':
      return { tone: 'neutral', label: 'Not available on this system' }
  }
}

/** A plain labelled pill, for anything that is not a permission. */
export function StatusPill({
  tone,
  children,
}: {
  tone: 'ready' | 'attention' | 'danger' | 'neutral' | 'info'
  children: React.ReactNode
}) {
  return <span className={`pill pill--${tone}`}>{children}</span>
}
