import { Keyboard, MousePointerClick, ShieldAlert, ShieldCheck } from 'lucide-react'

import type { EnginePeer } from '../../services/webrtc/RemoteSessionEngine'
import type { Participant, SessionControlState } from '../../types/remote'

/**
 * Remote control, as the browser side of it looks.
 *
 * **The panel is rendered from capabilities and policy, never from
 * `clientType`.** A browser host reports `remote_control: false`, so
 * `controllableHostUuid` is null for a browser-to-browser session and this
 * shows the one honest sentence there is to show — rather than a Request
 * control button that would be refused, or worse, one that silently did
 * nothing.
 *
 * Browser V1's promise is unchanged by any of this: a browser still cannot be
 * controlled, and nothing here implies otherwise.
 */

export type ControlPhase =
  | 'unavailable'
  | 'not-permitted'
  | 'idle'
  | 'requested'
  | 'controlling'
  | 'denied'
  | 'revoked'

interface Props {
  phase: ControlPhase
  control: SessionControlState | null
  /** The peer whose machine can be controlled, from negotiated capabilities. */
  host: EnginePeer | null
  /** The people waiting for this host's answer. Only the host sees these. */
  pending: SessionControlState['pendingRequests']
  isHost: boolean
  hostParticipant: Participant | null
  onRequest: () => void
  onStop: () => void
  onGrant: (participantUuid: string, allowClipboard: boolean) => void
  onDeny: (participantUuid: string) => void
  allowClipboard: boolean
  onAllowClipboardChange: (value: boolean) => void
  busy: boolean
}

export default function ControlPanel({
  phase,
  control,
  host,
  pending,
  isHost,
  hostParticipant,
  onRequest,
  onStop,
  onGrant,
  onDeny,
  allowClipboard,
  onAllowClipboardChange,
  busy,
}: Props) {
  return (
    <div className="control">
      <h3 className="control__heading">
        <MousePointerClick size={15} aria-hidden="true" />
        Remote control
      </h3>

      {/* The host's queue comes first: somebody is waiting for an answer. */}
      {isHost && pending.length > 0
        ? pending.map((request) => (
            <div className="control__request" key={request.participantUuid} role="alertdialog">
              <p className="control__request-title">
                <ShieldAlert size={15} aria-hidden="true" />
                {request.displayName} is asking to control this computer
              </p>
              <p className="control__request-body">
                They will be able to move the mouse, type, and open anything you can open. You can
                stop it at any moment, and stopping does not need their agreement.
              </p>

              {control?.allowClipboardSync ? (
                <label className="control__option">
                  <input
                    type="checkbox"
                    checked={allowClipboard}
                    onChange={(event) => onAllowClipboardChange(event.target.checked)}
                  />
                  <span>
                    Also share the clipboard
                    <span className="control__hint">
                      Off unless you tick it — control and clipboard are separate.
                    </span>
                  </span>
                </label>
              ) : null}

              <div className="row">
                <button
                  type="button"
                  className="btn btn--secondary btn--sm"
                  onClick={() => onDeny(request.participantUuid)}
                  disabled={busy}
                >
                  Not now
                </button>
                <button
                  type="button"
                  className="btn btn--primary btn--sm"
                  onClick={() => onGrant(request.participantUuid, allowClipboard)}
                  disabled={busy}
                >
                  Allow control
                </button>
              </div>
            </div>
          ))
        : null}

      <ControlStatus
        phase={phase}
        control={control}
        host={host}
        hostParticipant={hostParticipant}
        onRequest={onRequest}
        onStop={onStop}
        busy={busy}
      />
    </div>
  )
}

function ControlStatus({
  phase,
  control,
  host,
  hostParticipant,
  onRequest,
  onStop,
  busy,
}: {
  phase: ControlPhase
  control: SessionControlState | null
  host: EnginePeer | null
  hostParticipant: Participant | null
  onRequest: () => void
  onStop: () => void
  busy: boolean
}) {
  switch (phase) {
    // A browser cannot be controlled. Saying so plainly is more useful than a
    // greyed-out button, and it is what §2 asks for: the limitation is never
    // misrepresented in the interface.
    case 'unavailable':
      return (
        <p className="control__empty">
          The computer in this session is a web browser, which cannot be controlled. AICOUNTLY
          Remote for Windows is needed at that end.
        </p>
      )

    // The organisation, the plan or this person's permission says no. Which
    // one is in `restrictions`, and the wording avoids implying the person can
    // fix it themselves.
    case 'not-permitted':
      return (
        <p className="control__empty">
          {control?.allowRemoteControl === false
            ? 'Remote control is not enabled for this organisation.'
            : 'You do not have permission to request control of a computer.'}
        </p>
      )

    case 'idle':
      return (
        <>
          <p className="control__empty">
            {hostParticipant?.displayName ?? host?.name ?? 'This computer'} can be controlled with
            permission from the person at it.
          </p>
          <button type="button" className="btn btn--primary btn--sm" onClick={onRequest} disabled={busy}>
            Request control
          </button>
        </>
      )

    case 'requested':
      return (
        <p className="control__empty">
          Waiting for {hostParticipant?.displayName ?? 'the person at the computer'} to answer.
          Nothing is being sent until they do.
        </p>
      )

    case 'controlling':
      return (
        <>
          <p className="control__active">
            <ShieldCheck size={15} aria-hidden="true" />
            <span>
              <strong>You are controlling this computer.</strong>
              <span className="control__hint">
                Click and type on the shared screen. {control?.clipboardEnabled
                  ? 'The clipboard is shared in both directions.'
                  : 'The clipboard is not shared.'}
              </span>
            </span>
          </p>
          <p className="control__hint">
            <Keyboard size={13} aria-hidden="true" /> Keyboard input goes to the remote computer
            while the shared screen has focus.
          </p>
          <button type="button" className="btn btn--danger btn--sm" onClick={onStop} disabled={busy}>
            Stop controlling
          </button>
        </>
      )

    case 'denied':
      return (
        <>
          <p className="control__empty">
            {hostParticipant?.displayName ?? 'The person at the computer'} declined. You can ask
            again.
          </p>
          <button type="button" className="btn btn--secondary btn--sm" onClick={onRequest} disabled={busy}>
            Ask again
          </button>
        </>
      )

    case 'revoked':
      return (
        <>
          <p className="control__empty">
            Control was stopped. Nothing more is being sent to that computer.
          </p>
          <button type="button" className="btn btn--secondary btn--sm" onClick={onRequest} disabled={busy}>
            Request control again
          </button>
        </>
      )
  }
}

/**
 * Which phase to show.
 *
 * Pulled out so the decision is tested rather than assumed — in particular the
 * two cases that matter most: a browser host is `unavailable` whatever the
 * policy says, and a policy that forbids control is `not-permitted` whatever
 * the host can do.
 */
export function controlPhase({
  hasControllableHost,
  allowedByPolicy,
  hasPermission,
  controlState,
  isControlling,
}: {
  hasControllableHost: boolean
  allowedByPolicy: boolean
  hasPermission: boolean
  controlState: Participant['controlState'] | null
  isControlling: boolean
}): ControlPhase {
  // Capability first. A browser cannot be controlled however permissive the
  // organisation is, and offering it would be the interface lying about what
  // the product can do.
  if (!hasControllableHost) return 'unavailable'

  if (!allowedByPolicy || !hasPermission) return 'not-permitted'

  if (isControlling && controlState === 'GRANTED') return 'controlling'

  switch (controlState) {
    case 'REQUESTED':
      return 'requested'
    case 'GRANTED':
      // Granted by the server but the browser is not sending yet — the channel
      // is still opening. "Requested" is the honest reading: nothing is being
      // sent.
      return 'requested'
    case 'DENIED':
      return 'denied'
    case 'REVOKED':
      return 'revoked'
    default:
      return 'idle'
  }
}
