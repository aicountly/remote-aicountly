/**
 * Shown while a lazy route loads.
 *
 * A skeleton of the shape that is coming rather than a spinner: on a fast
 * connection it never appears, and on a slow one it tells the user what to
 * expect instead of just that something is happening (§45).
 */
export default function RouteFallback() {
  return (
    <div className="page" aria-busy="true" aria-live="polite">
      <span className="sr-only">Loading…</span>

      <div className="stack stack--lg">
        <div className="stack stack--sm">
          <div className="skeleton" style={{ height: 28, width: 240 }} />
          <div className="skeleton" style={{ height: 16, width: 340 }} />
        </div>

        <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))' }}>
          {[0, 1, 2].map((index) => (
            <div key={index} className="skeleton" style={{ height: 108 }} />
          ))}
        </div>

        <div className="skeleton" style={{ height: 280 }} />
      </div>
    </div>
  )
}
