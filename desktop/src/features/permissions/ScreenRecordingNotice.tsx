/**
 * macOS's Screen Recording permission, explained where it is needed.
 *
 * A Mac needs it to host a session — to show its own screen to the person
 * helping, attended or unattended. Without it macOS does not refuse a capture:
 * it hands back the wallpaper with every window missing, which is why the
 * agent refuses to start one instead, and this says how to fix it. macOS
 * applies a new grant only when the app next starts, hence the last step.
 *
 * A control companion never needs it — its picture comes from the browser —
 * so this belongs beside hosting, not beside the companion's
 * Accessibility notice.
 */
export function ScreenRecordingNotice({
  computerName,
  onOpen,
  busy,
}: {
  computerName: string
  /** Asks the agent for `screen_capture`, which opens System Settings there. */
  onOpen: () => void
  busy?: boolean
}) {
  return (
    <div className="notice notice--info" role="note" style={{ marginBottom: 'var(--space-4)' }}>
      <p className="notice__title">Allow AICOUNTLY Remote in Screen Recording</p>
      <p>
        macOS only lets an app show this {computerName}&apos;s screen in a session after you allow it:
      </p>
      <ol style={{ margin: 0, paddingLeft: '1.25rem' }}>
        <li>
          Open System Settings → Privacy &amp; Security → Screen Recording (on macOS 15 it is called
          Screen &amp; System Audio Recording).
        </li>
        <li>Turn on <strong>AICOUNTLY Remote</strong>.</li>
        <li>
          Quit and reopen AICOUNTLY Remote when macOS asks — the permission takes effect only once the app
          has restarted.
        </li>
      </ol>
      <p>
        Until then a session on this {computerName} cannot show its screen. macOS 15 may also ask again from
        time to time whether AICOUNTLY Remote can keep recording; choose Allow to keep sessions showing it.
      </p>
      <div className="session-banner__actions">
        <button type="button" className="btn btn--secondary" onClick={onOpen} disabled={busy}>
          Open Screen Recording settings
        </button>
      </div>
    </div>
  )
}
