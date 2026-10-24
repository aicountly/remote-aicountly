import type { CompanionSummary } from '../../types/agent'
import { AccessibilityNotice } from './AccessibilityNotice'

/**
 * The one question a control companion asks at the machine: may this
 * session's people control this computer while you share it?
 *
 * Asked once per link. After a yes, each person who wants control still has to
 * be granted it by the owner in the browser — this is not that grant, and the
 * wording does not pretend to be.
 */
export function CompanionConfirm({
  companion,
  computerName,
  needsAccessibility,
  onAllow,
  onDecline,
  onOpenAccessibility,
  busy,
}: {
  companion: CompanionSummary
  /** "Mac" or "computer". */
  computerName: string
  /** Whether macOS still has to grant Accessibility before input can land. */
  needsAccessibility: boolean
  onAllow: () => void
  onDecline: () => void
  onOpenAccessibility: () => void
  busy?: boolean
}) {
  const where = companion.companyName
    ? `session ${companion.displayId} (${companion.companyName})`
    : `session ${companion.displayId}`

  return (
    <section className="card" role="alertdialog" aria-label="Allow control while you share">
      <h2 className="card__title">
        Let people in {where} control this {computerName} while you share it?
      </h2>
      <p className="card__subtitle">
        {companion.requestedByName
          ? `${companion.requestedByName} linked this ${computerName} to the screen being shared from the browser.`
          : `This ${computerName} was linked to the screen being shared from the browser.`}
      </p>

      <div className="notice notice--warning" style={{ marginBottom: 'var(--space-4)' }}>
        <p className="notice__title">What allowing this means</p>
        <p>
          Somebody you give control to <strong>in your browser</strong> will be able to move this{' '}
          {computerName}&apos;s mouse and type on its keyboard, on the screen you are sharing. Each
          person still needs your approval there.
        </p>
        <p>
          Using your own mouse or keyboard pauses them straight away. <strong>Stop control</strong>{' '}
          here, in the menu bar or with the shortcut ends it at once, without their agreement.
        </p>
      </div>

      {needsAccessibility ? (
        <AccessibilityNotice computerName={computerName} onOpen={onOpenAccessibility} busy={busy} />
      ) : null}

      <div className="session-banner__actions">
        <button type="button" className="btn btn--primary" onClick={onAllow} disabled={busy}>
          Allow while I share
        </button>
        <button type="button" className="btn btn--secondary" onClick={onDecline} disabled={busy}>
          Not now
        </button>
      </div>
    </section>
  )
}
