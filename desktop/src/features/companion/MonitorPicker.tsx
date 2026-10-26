import type { CompanionSummary, MonitorOption } from '../../types/agent'

/**
 * Which screen are you sharing?
 *
 * Shown only when the companion cannot tell by itself — two identical
 * monitors, or a share whose shape matches none of them. Control lands on the
 * one picked, so **Identify** puts each monitor's name on the monitor itself
 * rather than leaving somebody to guess which "Display 2" is.
 */
export function MonitorPicker({
  companion,
  waitingForAccessibility,
  onChoose,
  onIdentify,
  busy,
}: {
  companion: CompanionSummary
  waitingForAccessibility: boolean
  onChoose: (monitor: MonitorOption) => void
  onIdentify: () => void
  busy?: boolean
}) {
  if (companion.monitors.length === 0) {
    return (
      <section className="card" role="status">
        <h2 className="card__title">Getting ready to be controlled</h2>
        <p className="card__subtitle">
          {waitingForAccessibility
            ? 'Waiting for Accessibility permission before anyone can be given control.'
            : 'Finding the screen you are sharing from your browser…'}
        </p>
      </section>
    )
  }

  return (
    <section className="card" role="alertdialog" aria-label="Which screen are you sharing?">
      <h2 className="card__title">Which screen are you sharing?</h2>
      <p className="card__subtitle">
        Control in session {companion.displayId} will land on the screen you pick. Pick the one you
        chose to share in your browser.
      </p>

      <div className="card__rows">
        {companion.monitors.map((monitor) => (
          <div className="row" key={monitor.id}>
            <span className="row__label">
              {monitor.name}
              {monitor.primary ? ' (main display)' : ''}
            </span>
            <span style={{ display: 'flex', gap: 'var(--space-2)', alignItems: 'center' }}>
              <span className="row__value">{describe(monitor)}</span>
              <button
                type="button"
                className="btn btn--primary"
                onClick={() => onChoose(monitor)}
                disabled={busy}
                aria-label={`Share ${monitor.name}`}
              >
                This one
              </button>
            </span>
          </div>
        ))}
      </div>

      <div className="session-banner__actions">
        <button type="button" className="btn btn--secondary" onClick={onIdentify} disabled={busy}>
          Identify screens
        </button>
      </div>
    </section>
  )
}

/** `1512 × 982, Retina` — the size in the platform's own units, and the scale. */
export function describe(monitor: MonitorOption): string {
  const size = `${monitor.width} × ${monitor.height}`

  if (monitor.scalePercent >= 200) return `${size}, Retina`
  if (monitor.scalePercent !== 100) return `${size}, ${monitor.scalePercent}%`

  return size
}
