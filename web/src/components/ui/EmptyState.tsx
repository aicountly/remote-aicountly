import type { ReactNode } from 'react'

/**
 * Empty states (§47).
 *
 * An empty screen is a chance to explain what will appear here and why, and to
 * offer the one action that fills it. "No data" is the version of this that
 * teaches nobody anything.
 */

interface Props {
  icon?: ReactNode
  title: string
  description?: string
  action?: ReactNode
}

export default function EmptyState({ icon, title, description, action }: Props) {
  return (
    <div className="empty-state">
      {icon ? (
        <div className="empty-state__icon" aria-hidden="true">
          {icon}
        </div>
      ) : null}

      <h3 className="empty-state__title">{title}</h3>
      {description ? <p className="empty-state__description">{description}</p> : null}
      {action ? <div className="empty-state__action">{action}</div> : null}
    </div>
  )
}
