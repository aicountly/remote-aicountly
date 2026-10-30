import type { ShareMode } from '../../types/remote'

/**
 * What the room's Share button proposes.
 *
 * What the session was started for, while the policy still allows it — Start
 * session's choice, which inside the desktop application is "Entire screen",
 * the only share a computer can be controlled through
 * (docs/desktop/CONTROL_COMPANION.md). Otherwise the first thing allowed.
 *
 * The button used to propose the first allowed mode whatever was chosen —
 * Safe Share, for a personal account — so somebody who had started a session
 * to be helped on their computer shared a browser tab, and "Enable control
 * from this computer" then waited on a share that could never carry it. The
 * consent step still lets them change it.
 */
export function proposedShareMode(requested: ShareMode | null | undefined, allowed: readonly ShareMode[] | null | undefined): ShareMode {
  if (requested && allowed?.includes(requested)) return requested

  return allowed?.[0] ?? 'SAFE_SHARE'
}
