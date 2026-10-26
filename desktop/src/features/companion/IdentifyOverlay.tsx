/**
 * The label **Identify screens** puts on each monitor for a few seconds.
 *
 * Rendered in a small borderless window the Rust side opens on that monitor.
 * It reads its text from the URL fragment and calls nothing: these windows
 * have no access to the agent's commands at all (`capabilities/default.json`
 * names only the main window).
 */
export function IdentifyOverlay({ name }: { name: string }) {
  return (
    <div
      role="status"
      style={{
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        height: '100vh',
        margin: 0,
        background: 'var(--aicountly-green-dark)',
        color: 'var(--text-inverse)',
        fontFamily: 'var(--font-sans)',
        textAlign: 'center',
        padding: '1rem',
      }}
    >
      <div style={{ fontSize: '2.25rem', fontWeight: 700 }}>{name}</div>
      <div style={{ fontSize: '1rem', opacity: 0.85, marginTop: '0.5rem' }}>AICOUNTLY Remote</div>
    </div>
  )
}

/** The monitor name from `#identify=<name>`, or `null` for any other page. */
export function identifyName(hash: string): string | null {
  const prefix = '#identify='

  if (!hash.startsWith(prefix)) return null

  try {
    // Plain text only: rendered as a React text node, never as markup.
    return decodeURIComponent(hash.slice(prefix.length)).slice(0, 120)
  } catch {
    return null
  }
}
