import { useCallback, useEffect, useId, useRef } from 'react'
import type { ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { X } from 'lucide-react'

/**
 * An accessible dialog (§63).
 *
 * Four things a dialog must do, all of which are easy to omit:
 *   * be announced as a dialog, with its title as the accessible name;
 *   * take focus on open and give it back to whatever had it on close;
 *   * trap Tab inside itself, so the page behind cannot be reached blind;
 *   * close on Escape.
 *
 * Rendered through a portal so a parent's `overflow` or stacking context cannot
 * clip it.
 */

interface Props {
  open: boolean
  title: string
  description?: string
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
  /** For a confirmation the user must answer deliberately. */
  dismissible?: boolean
  size?: 'sm' | 'md' | 'lg'
}

const FOCUSABLE =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'

export default function Modal({
  open,
  title,
  description,
  onClose,
  children,
  footer,
  dismissible = true,
  size = 'md',
}: Props) {
  const dialogRef = useRef<HTMLDivElement>(null)
  const previouslyFocused = useRef<HTMLElement | null>(null)
  const titleId = useId()
  const descriptionId = useId()

  const handleKeyDown = useCallback(
    (event: KeyboardEvent) => {
      if (event.key === 'Escape' && dismissible) {
        event.preventDefault()
        onClose()

        return
      }

      if (event.key !== 'Tab') return

      const focusable = dialogRef.current?.querySelectorAll<HTMLElement>(FOCUSABLE)
      if (!focusable || focusable.length === 0) return

      const first = focusable[0]
      const last = focusable[focusable.length - 1]

      // Wrap in both directions, so Tab and Shift+Tab both stay inside.
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    },
    [dismissible, onClose],
  )

  useEffect(() => {
    if (!open) return

    previouslyFocused.current = document.activeElement as HTMLElement | null

    // Focus the first control rather than the dialog itself where there is one:
    // it puts a keyboard user where they can act immediately.
    const focusable = dialogRef.current?.querySelector<HTMLElement>(FOCUSABLE)
    ;(focusable ?? dialogRef.current)?.focus()

    document.addEventListener('keydown', handleKeyDown)

    // The page behind must not scroll under an open dialog.
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.removeEventListener('keydown', handleKeyDown)
      document.body.style.overflow = previousOverflow
      previouslyFocused.current?.focus?.()
    }
  }, [open, handleKeyDown])

  if (!open) return null

  return createPortal(
    <div
      className="modal-backdrop"
      onMouseDown={(event) => {
        if (dismissible && event.target === event.currentTarget) onClose()
      }}
    >
      <div
        ref={dialogRef}
        className={`modal modal--${size}`}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={description ? descriptionId : undefined}
        tabIndex={-1}
      >
        <div className="modal__header">
          <div>
            <h2 id={titleId} className="modal__title">
              {title}
            </h2>
            {description ? (
              <p id={descriptionId} className="modal__description">
                {description}
              </p>
            ) : null}
          </div>

          {dismissible ? (
            <button type="button" className="btn btn--ghost btn--sm modal__close" onClick={onClose} aria-label="Close">
              <X size={16} aria-hidden="true" />
            </button>
          ) : null}
        </div>

        <div className="modal__body">{children}</div>

        {footer ? <div className="modal__footer">{footer}</div> : null}
      </div>
    </div>,
    document.body,
  )
}
