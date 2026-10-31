/**
 * macOS's Accessibility permission, explained where it is needed.
 *
 * Without it macOS drops every mouse and keyboard event an application posts,
 * silently — so the companion does not report itself ready until it is
 * granted, and this says how. Screen Recording is never needed: the picture
 * comes from the browser.
 */
export function AccessibilityNotice({
  computerName,
  onOpen,
  busy,
}: {
  computerName: string
  onOpen: () => void
  busy?: boolean
}) {
  return (
    <div className="notice notice--info" role="note" style={{ marginBottom: 'var(--space-4)' }}>
      <p className="notice__title">Allow AICOUNTLY Remote in Accessibility</p>
      <p>
        macOS only lets an app use this {computerName}&apos;s mouse and keyboard after you allow it:
      </p>
      <ol style={{ margin: 0, paddingLeft: '1.25rem' }}>
        <li>Open System Settings → Privacy &amp; Security → Accessibility.</li>
        <li>Turn on <strong>AICOUNTLY Remote</strong>. If it was already on from an earlier version, turn it off and on again.</li>
        <li>Come back here — no restart is needed.</li>
      </ol>
      <p>Screen Recording is not needed for this: the picture comes from your browser&apos;s share.</p>
      <div className="session-banner__actions">
        <button type="button" className="btn btn--secondary" onClick={onOpen} disabled={busy}>
          Open Accessibility settings
        </button>
      </div>
    </div>
  )
}
