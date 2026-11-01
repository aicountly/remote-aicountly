import { useEffect, useId, useRef, useSyncExternalStore } from 'react'
import { createPortal } from 'react-dom'
import { ShieldCheck } from 'lucide-react'

import { verifyTwoStep } from '../../services/api/remote'
import {
  cancelTwoStep,
  completeTwoStep,
  registerTwoStepPrompt,
  subscribeTwoStep,
  twoStepSnapshot,
} from '../../services/twoStepPrompt'
import TwoStepCodeForm from './TwoStepCodeForm'

/**
 * The one dialog that asks for a two-step verification code
 * (services/twoStepPrompt.ts decides when).
 *
 * Two kinds of asking, and the difference is whether the person may say no:
 *
 *   * **this browser has not passed it** (`SESSION`) — Remote's pages must not
 *     be usable behind it, so it covers the whole window, opaque, with
 *     everything else on the page made `inert` (no clicks, no focus, nothing
 *     read out) until a code is accepted. The only other way out is signing
 *     out;
 *   * **a step-up** (`STEP_UP`) — one action, such as turning unattended
 *     access on, wants a code from the last few minutes. An ordinary dialog
 *     over the page, which can be cancelled: the action is then not done.
 *
 * Either way, once the code is accepted every request that was refused is sent
 * again by the API client, so the person carries on from where they were.
 */

interface Props {
  /** Signing out is the way out of a session lock (AuthProvider's signOut). */
  onSignOut: () => void
}

export default function TwoStepPrompt({ onSignOut }: Props) {
  const prompt = useSyncExternalStore(subscribeTwoStep, twoStepSnapshot, twoStepSnapshot)
  const containerRef = useRef<HTMLDivElement | null>(null)
  const titleId = useId()
  const descriptionId = useId()

  // Announce that a prompt can be shown, so a refused request waits for it
  // rather than failing — and, when this unmounts, nothing waits for ever.
  useEffect(() => registerTwoStepPrompt(), [])

  // Everything but this dialog is inert while it is open: the shell, the page,
  // and any dialog a page had open (those are portalled to <body> too).
  useEffect(() => {
    if (!prompt.open) return

    const mine = containerRef.current
    const others = Array.from(document.body.children).filter(
      (element): element is HTMLElement => element instanceof HTMLElement && element !== mine && !element.hasAttribute('inert'),
    )
    others.forEach((element) => {
      element.setAttribute('inert', '')
      element.setAttribute('aria-hidden', 'true')
    })

    return () => {
      others.forEach((element) => {
        element.removeAttribute('inert')
        element.removeAttribute('aria-hidden')
      })
    }
  }, [prompt.open])

  useEffect(() => {
    if (!prompt.open || prompt.reason !== 'STEP_UP') return

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        cancelTwoStep()
      }
    }
    document.addEventListener('keydown', onKeyDown)

    return () => document.removeEventListener('keydown', onKeyDown)
  }, [prompt.open, prompt.reason])

  if (!prompt.open) return null

  const locked = prompt.reason === 'SESSION'

  return createPortal(
    <div ref={containerRef} className={locked ? 'two-step-lock' : 'modal-backdrop'}>
      <div
        className="modal modal--sm two-step-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={descriptionId}
      >
        <div className="modal__header">
          <div className="two-step-dialog__heading">
            <ShieldCheck size={22} aria-hidden="true" className="two-step-dialog__icon" />
            <div>
              <h2 id={titleId} className="modal__title">
                {locked ? 'Two-step verification' : 'Confirm it is you'}
              </h2>
              <p id={descriptionId} className="modal__description">
                {locked
                  ? 'Two-step verification is on for your AICOUNTLY Remote account. Enter the code from your authenticator app to continue.'
                  : (prompt.message ?? 'This needs a fresh code from your authenticator app.')}
              </p>
            </div>
          </div>
        </div>

        <div className="modal__body">
          <TwoStepCodeForm
            onSubmit={async (proof) => {
              await verifyTwoStep(proof)
              completeTwoStep()
            }}
            secondary={
              locked ? (
                <button type="button" className="btn btn--ghost" onClick={onSignOut}>
                  Sign out
                </button>
              ) : (
                <button type="button" className="btn btn--secondary" onClick={() => cancelTwoStep()}>
                  Cancel
                </button>
              )
            }
          />
          {locked ? (
            <p className="tiny muted two-step-dialog__note">
              Lost your phone? Use one of the recovery codes you saved when you turned this on. Without either,
              AICOUNTLY support can switch it off for you once they have confirmed who you are.
            </p>
          ) : null}
        </div>
      </div>
    </div>,
    document.body,
  )
}
