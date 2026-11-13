/**
 * macOS's Accessibility permission, explained where it is needed.
 *
 * Without it macOS drops every mouse and keyboard event an application posts,
 * silently — so the companion does not report itself ready until it is
 * granted, and this says how.
 *
 * It says nothing about Screen Recording. It used to add that Screen Recording
 * "is not needed", which is true of a companion and false of a Mac showing its
 * own screen — and both notices can be on screen together, one saying it is
 * not needed and the next asking for it.
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
      <div className="session-banner__actions">
        <button type="button" className="btn btn--secondary" onClick={onOpen} disabled={busy}>
          Open Accessibility settings
        </button>
      </div>
    </div>
  )
}
