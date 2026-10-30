/**
 * The product mark.
 *
 * **No AICOUNTLY logo is drawn here**, for the reason `docs/BRANDING.md`
 * gives: the real asset is not in this repository, and inventing one would be
 * inventing it. What this renders is the same typographic wordmark the web
 * app falls back to, beside the Remote product mark — a screen outline with a
 * signal arc, which *is* ours and carries no text.
 */
export function Brand({
  version,
  build = null,
  platform = null,
}: {
  version: string
  /** Which build of the version, from the About panel; the installer's name carries the same number. */
  build?: string | null
  /** `Windows` or `macOS`: this is the application, not the web portal it shows. */
  platform?: string | null
}) {
  return (
    <div className="app__brand">
      <span className="app__brand-mark" aria-hidden="true">
        <RemoteMark />
      </span>
      <span>AICOUNTLY Remote</span>
      {platform === 'Windows' || platform === 'macOS' ? (
        <span className="app__platform">{platform === 'macOS' ? 'for Mac' : 'for Windows'}</span>
      ) : null}
      <span className="app__version" title={build ? `Build ${build}` : undefined}>
        {version}
      </span>
    </div>
  )
}

/** The Remote product mark. `currentColor`, legible at 16–22px. */
export function RemoteMark({ size = 20 }: { size?: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <rect x="2.5" y="4.5" width="19" height="12" rx="2" />
      <path d="M8.5 20.5h7" />
      <path d="M12 16.5v4" />
      <path d="M9 10.5a4 4 0 0 1 6 0" />
      <path d="M11 12.6a1.5 1.5 0 0 1 2 0" />
    </svg>
  )
}
