import type { AgentCapabilities, PermissionSummary } from '../../types/agent'
import { PermissionPill } from '../../components/StatusPill'

/**
 * What this computer can currently do, and what its organisation allows.
 *
 * Two different things, shown as two columns, because conflating them is how a
 * person ends up staring at a capability that says "Ready" and does not work:
 *
 *   * **This computer** — what Windows and this installation permit. A missing
 *     background service is here.
 *   * **Your organisation** — what the company policy and the plan allow. A
 *     company that has not turned remote control on is here.
 *
 * Both have to say yes. The row is only usable when they agree, and when they
 * do not, the column that said no is the one a person needs to act on.
 */
export function PermissionsCard({
  permissions,
  allowed,
}: {
  permissions: PermissionSummary
  /** What the organisation permits, from `GET /devices/me`. */
  allowed: AgentCapabilities | null
}) {
  return (
    <section className="card">
      <h2 className="card__title">Permissions</h2>
      <p className="card__subtitle">
        A capability works only when this computer and your organisation both allow it.
      </p>

      <div className="card__rows">
        <PermissionRow
          label="Screen capture"
          machine={permissions.screen_capture}
          organisation={allowed?.screen_share}
        />
        <PermissionRow
          label="Remote control"
          machine={permissions.input_injection}
          organisation={allowed?.remote_control}
        />
        <PermissionRow
          label="Clipboard"
          machine={permissions.clipboard}
          organisation={allowed?.clipboard_sync}
        />
        <PermissionRow
          label="Background service"
          machine={permissions.background_service}
          organisation={undefined}
        />
        <PermissionRow
          label="Restart this computer"
          machine={permissions.power}
          organisation={allowed?.reboot}
        />
      </div>
    </section>
  )
}

function PermissionRow({
  label,
  machine,
  organisation,
}: {
  label: string
  machine: PermissionSummary[keyof PermissionSummary]
  organisation: boolean | undefined
}) {
  return (
    <div className="row">
      <span className="row__label">{label}</span>
      <span style={{ display: 'flex', gap: 'var(--space-2)', alignItems: 'center' }}>
        <PermissionPill state={machine} />
        {organisation === undefined ? null : organisation ? (
          <span className="pill pill--neutral">Allowed by policy</span>
        ) : (
          // Named as the organisation's decision rather than as a failure:
          // there is nothing the person at this computer can do about it, and
          // implying otherwise sends them looking for a setting.
          <span className="pill pill--neutral">Off for your organisation</span>
        )}
      </span>
    </div>
  )
}
