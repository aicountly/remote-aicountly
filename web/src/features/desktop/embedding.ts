/**
 * Whether this page is the portal inside the AICOUNTLY Remote desktop
 * application (docs/desktop/PORTAL.md).
 *
 * The application's embedded web view defines `__AICOUNTLY_REMOTE_DESKTOP__`
 * before any page script runs (`desktop/src-tauri/src/portal.rs`), carrying a
 * random value that is new every time the application starts. A browser tab
 * never has it, and no link can set it, which is what lets the sign-in
 * hand-off (`auth/desktopHandoff.ts`) be bound to the application that asked
 * for it.
 */

export interface DesktopEmbedding {
  /** Random per launch of the application. */
  nonce: string
}

declare global {
  interface Window {
    __AICOUNTLY_REMOTE_DESKTOP__?: unknown
  }
}

export function desktopEmbedding(scope: { __AICOUNTLY_REMOTE_DESKTOP__?: unknown } = window): DesktopEmbedding | null {
  const value = scope.__AICOUNTLY_REMOTE_DESKTOP__
  if (typeof value !== 'object' || value === null) return null

  const nonce = (value as { nonce?: unknown }).nonce
  return typeof nonce === 'string' && /^[0-9a-f]{32,128}$/.test(nonce) ? { nonce } : null
}

/** Inside the desktop application, rather than a browser tab. */
export function isDesktopApp(): boolean {
  return desktopEmbedding() !== null
}
