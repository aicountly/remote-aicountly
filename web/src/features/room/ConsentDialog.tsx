import { useState } from 'react'
import { ShieldCheck } from 'lucide-react'

import Modal from '../../components/ui/Modal'
import ShareModePicker from '../sessions/ShareModePicker'
import type { EffectivePolicy, SessionDetail, ShareMode } from '../../types/remote'
import type { ShareIntentState } from './useRemoteSession'

/**
 * Informed consent, before any capture (§30).
 *
 * Capture is **never** started automatically. This dialog is the deliberate
 * step between deciding to share and the browser's own picker, and it says
 * three things a person needs before agreeing: what will be visible, which
 * organisation the session belongs to, and which session it is.
 *
 * It is not dismissible by clicking away — agreeing to share your screen should
 * take a decision, not a stray click.
 */

interface Props {
  state: ShareIntentState
  session: SessionDetail
  policy: EffectivePolicy | null
  onConfirm: () => void
  onCancel: () => void
  onChangeMode: (mode: ShareMode) => void
}

export default function ConsentDialog({ state, session, policy, onConfirm, onCancel, onChangeMode }: Props) {
  const [picking, setPicking] = useState(false)

  const open = state.phase === 'consenting' || state.phase === 'picking'
  if (!open || !policy) return null

  const shareMode = state.phase === 'consenting' || state.phase === 'picking' ? state.shareMode : 'SAFE_SHARE'
  const waitingForPicker = state.phase === 'picking'

  const viewers = session.participants.filter(
    (participant) => !participant.isHost && ['APPROVED', 'JOINED'].includes(participant.status),
  )

  return (
    <Modal
      open={open}
      title="You’re about to share your screen"
      onClose={onCancel}
      dismissible={!waitingForPicker}
      size="md"
      footer={
        <div className="row row--between row--wrap">
          <button type="button" className="btn btn--ghost" onClick={onCancel} disabled={waitingForPicker}>
            Cancel
          </button>

          <div className="row">
            {!picking && policy.allowedShareModes.length > 1 ? (
              <button
                type="button"
                className="btn btn--secondary"
                onClick={() => setPicking(true)}
                disabled={waitingForPicker}
              >
                Change what to share
              </button>
            ) : null}

            <button type="button" className="btn btn--primary" onClick={onConfirm} disabled={waitingForPicker}>
              {waitingForPicker ? 'Opening your browser’s picker…' : 'Continue to choose screen'}
            </button>
          </div>
        </div>
      }
    >
      <div className="stack">
        <p>
          People in this Remote session will be able to see the content you choose in your browser’s sharing
          window. Nothing else on your device is visible, and you can stop at any time.
        </p>

        <dl className="consent-facts">
          <div>
            <dt>Sharing</dt>
            <dd>{shareModeLabel(shareMode)}</dd>
          </div>
          <div>
            <dt>Organisation</dt>
            <dd>{session.companyName ?? 'Personal (no organisation)'}</dd>
          </div>
          <div>
            <dt>Session</dt>
            <dd className="mono">{session.displayId}</dd>
          </div>
          <div>
            <dt>Who can see it</dt>
            <dd>
              {viewers.length === 0
                ? 'Nobody yet — you approve each person'
                : viewers.map((viewer) => viewer.displayName).join(', ')}
            </dd>
          </div>
        </dl>

        {picking ? (
          <ShareModePicker policy={policy} value={shareMode} onChange={onChangeMode} />
        ) : null}

        <p className="tiny muted row">
          <ShieldCheck size={14} aria-hidden="true" />
          Your screen is streamed live to the people in this session. It is not recorded or stored.
        </p>
      </div>
    </Modal>
  )
}

function shareModeLabel(mode: ShareMode): string {
  return (
    {
      SAFE_SHARE: 'AICOUNTLY Safe Share — your AICOUNTLY workspace',
      BROWSER_TAB: 'A browser tab you pick',
      APPLICATION_WINDOW: 'An application window you pick',
      ENTIRE_MONITOR: 'An entire screen, including apps outside AICOUNTLY',
    }[mode] ?? 'A screen you pick'
  )
}
