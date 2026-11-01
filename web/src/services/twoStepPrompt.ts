/**
 * The one two-step verification prompt, shared by everything that needs it.
 *
 * A call the API refused with `TWO_STEP_REQUIRED` (services/api/client.ts), a
 * bootstrap that says this browser has not passed it yet (RemoteProvider), and
 * the step-up for turning unattended access on all end up here, and all of
 * them wait on the **same** prompt: five requests refused at once must not
 * open five dialogs, and one code answers every one of them. When it passes,
 * each caller carries on — the client sends the refused request again; when the
 * person cancels (only a step-up can be cancelled), each gets its refusal back.
 *
 * Plain module state with a subscription, rather than a React context, so the
 * API client — which is not a component — can open it. The dialog
 * (features/twoStep/TwoStepPrompt.tsx) says when it is mounted; with nothing
 * mounted to show the prompt (a guest's tab), asking is answered "no" at once
 * instead of waiting for ever.
 */

/**
 * `SESSION`: this browser has not passed two-step verification, so nothing
 * signed-in works until it does — not cancellable. `STEP_UP`: it has, but one
 * action wants a fresher code — cancellable, and the action is simply not done.
 */
export type TwoStepReason = 'SESSION' | 'STEP_UP'

export interface TwoStepPromptState {
  open: boolean
  reason: TwoStepReason
  /** The server's sentence for a step-up — what the code is for. */
  message: string | null
}

export class TwoStepCancelled extends Error {
  constructor() {
    super('Two-step verification was cancelled.')
    this.name = 'TwoStepCancelled'
  }
}

const CLOSED: TwoStepPromptState = { open: false, reason: 'SESSION', message: null }

let state: TwoStepPromptState = CLOSED
let waiting: Array<{ resolve: () => void; reject: (error: Error) => void }> = []
let mounted = 0
const listeners = new Set<() => void>()

function publish(next: TwoStepPromptState): void {
  state = next
  listeners.forEach((listener) => listener())
}

/**
 * Ask for a code, or join the prompt already open. Resolves once this browser
 * has passed; rejects with {@link TwoStepCancelled} when the person cancels,
 * or at once when no prompt can be shown.
 */
export function requestTwoStep(reason: TwoStepReason, message: string | null = null): Promise<void> {
  if (mounted === 0) return Promise.reject(new TwoStepCancelled())

  // A session lock outranks a step-up: once the whole app is waiting for a code,
  // the prompt is no longer one that can be dismissed.
  const nextReason: TwoStepReason = state.open && state.reason === 'SESSION' ? 'SESSION' : reason
  publish({
    open: true,
    reason: nextReason,
    message: nextReason === 'STEP_UP' ? (message ?? state.message) : null,
  })

  return new Promise<void>((resolve, reject) => {
    waiting.push({ resolve, reject })
  })
}

/** The code was accepted: everybody waiting carries on. */
export function completeTwoStep(): void {
  const settled = waiting
  waiting = []
  publish(CLOSED)
  settled.forEach(({ resolve }) => resolve())
}

/** The person chose not to — a step-up only. */
export function cancelTwoStep(): void {
  const settled = waiting
  waiting = []
  publish(CLOSED)
  settled.forEach(({ reject }) => reject(new TwoStepCancelled()))
}

export function subscribeTwoStep(listener: () => void): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

export function twoStepSnapshot(): TwoStepPromptState {
  return state
}

/** The dialog announcing itself; returns the matching "gone". */
export function registerTwoStepPrompt(): () => void {
  mounted += 1
  return () => {
    mounted = Math.max(0, mounted - 1)
    if (mounted === 0 && waiting.length > 0) cancelTwoStep()
  }
}

/** Tests only: back to nothing open, nobody waiting, nothing mounted. */
export function resetTwoStepPrompt(): void {
  waiting = []
  mounted = 0
  publish(CLOSED)
}
