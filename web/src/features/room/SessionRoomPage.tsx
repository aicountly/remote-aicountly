import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import {
  ArrowLeft,
  Circle,
  Highlighter,
  Info,
  MessageSquare,
  Mic,
  MicOff,
  MonitorUp,
  MousePointer2,
  Pause,
  Pencil,
  Play,
  Shield,
  Square,
  StopCircle,
  Users,
} from 'lucide-react'

import { useRemote } from '../../app/RemoteProvider'
import { useRemoteSession } from './useRemoteSession'
import type { AnnotationTool } from './SessionStage'
import SessionStage from './SessionStage'
import SessionSidePanel from './SessionSidePanel'
import ConsentDialog from './ConsentDialog'
import SessionEnded from './SessionEnded'
import ConnectionIndicator from './ConnectionIndicator'
import RestrictionNotice from '../../components/ui/RestrictionNotice'
import { RemoteApiError } from '../../services/api/client'
import { RemoteCaptureError } from '../../services/webrtc/screenCapture'
import { PERMISSIONS } from '../../types/remote'
import { describeScope, formatClock } from '../../utils/format'
import { getGuestToken } from '../../services/api/client'

/**
 * The live session workspace (§33).
 *
 * Full screen, dark, with the shared screen as the only thing that matters and
 * every control secondary to it. The toolbar shows exactly the tools this
 * person may use — browser capability ∧ company policy ∧ role ∧ user permission
 * ∧ session capability (§87) — so a disabled button is never a surprise.
 */
export default function SessionRoomPage() {
  const { uuid = '' } = useParams()
  const navigate = useNavigate()
  const { policy, can, capabilities } = useRemote()

  const isGuest = Boolean(getGuestToken())

  const { session, loading, error, ended, messages, live, shareIntent, actions } = useRemoteSession({
    sessionUuid: uuid,
    policy,
    guestParticipantUuid: isGuest ? readGuestParticipantUuid() : null,
  })

  const [panel, setPanel] = useState<'participants' | 'chat' | 'details' | 'security' | null>('participants')
  const [annotationTool, setAnnotationTool] = useState<AnnotationTool>('none')
  const [pointerOn, setPointerOn] = useState(false)
  const [elapsed, setElapsed] = useState(0)

  // A visible clock is part of trusting a screen-sharing session: it says how
  // long this has been going on without anyone having to remember.
  useEffect(() => {
    if (!session?.startedAt) return

    const started = new Date(session.startedAt).getTime()
    const tick = () => setElapsed(Math.max(0, Math.floor((Date.now() - started) / 1000)))

    tick()
    const timer = setInterval(tick, 1000)

    return () => clearInterval(timer)
  }, [session?.startedAt])

  // Leaving the page must not leave a capture running. `pagehide` fires in
  // cases `beforeunload` does not, including a mobile tab being discarded.
  useEffect(() => {
    const onHide = () => void actions.leave()
    window.addEventListener('pagehide', onHide)

    return () => window.removeEventListener('pagehide', onHide)
  }, [actions])

  if (loading) {
    return (
      <div className="room room--loading" aria-busy="true">
        <p className="room__loading-text">Opening secure session…</p>
      </div>
    )
  }

  if (error && !session) {
    return (
      <div className="room room--message">
        <div className="room__message-card">
          <RestrictionNotice
            error={error}
            action={
              <button type="button" className="btn btn--secondary" onClick={() => navigate('/')}>
                Back to Remote
              </button>
            }
          />
        </div>
      </div>
    )
  }

  if (!session) return null

  if (ended || ['ENDED', 'EXPIRED', 'DECLINED', 'FAILED'].includes(session.status)) {
    return <SessionEnded session={session} isGuest={isGuest} />
  }

  const me = session.me
  const awaitingApproval = me?.status === 'REQUESTED'

  // Everything the toolbar can offer, gated once here rather than per button.
  const isSharing = live?.isSharing ?? false
  const canShare =
    session.isHost &&
    capabilities.screenCapture &&
    can(PERMISSIONS.SCREEN_SHARE) &&
    (policy?.allowedShareModes.length ?? 0) > 0
  const canChat = session.capabilities.chat && (isGuest || can(PERMISSIONS.CHAT_USE))
  const canAnnotate = session.capabilities.annotation && (isGuest || can(PERMISSIONS.ANNOTATION_USE))
  const canUseMicrophone =
    session.capabilities.audio && capabilities.microphone && (isGuest || can(PERMISSIONS.MICROPHONE_SHARE))

  const waiting = session.waiting ?? []

  return (
    <div className="room">
      <header className="room__header">
        <div className="room__identity">
          <button
            type="button"
            className="btn btn--ghost btn--sm room__back"
            onClick={() => {
              void actions.leave()
              navigate(isGuest ? '/' : '/sessions')
            }}
            aria-label="Leave session"
          >
            <ArrowLeft size={16} aria-hidden="true" />
          </button>

          <div className="room__identity-text">
            <div className="room__title-row">
              <span className="room__display-id mono">{session.displayId}</span>
              <span className="room__secure">
                <Shield size={12} aria-hidden="true" />
                Secure
              </span>
            </div>
            <p className="room__context truncate">
              {describeScope(session.scopeType, session.companyName)}
              {session.sourceProductLabel ? ` · ${session.sourceProductLabel}` : ''}
            </p>
          </div>
        </div>

        <div className="room__status">
          <ConnectionIndicator live={live} sessionStatus={session.status} />
          {session.startedAt ? (
            <span className="room__clock mono" aria-label="Session duration">
              {formatClock(elapsed)}
            </span>
          ) : null}
        </div>

        <div className="room__header-actions">
          {session.isHost ? (
            session.status === 'PAUSED' ? (
              <button type="button" className="btn btn--secondary btn--sm" onClick={() => void actions.resume()}>
                <Play size={15} aria-hidden="true" />
                Resume
              </button>
            ) : (
              <button type="button" className="btn btn--ghost btn--sm room__ghost" onClick={() => void actions.pause()}>
                <Pause size={15} aria-hidden="true" />
                Pause
              </button>
            )
          ) : null}

          <button
            type="button"
            className="btn btn--danger btn--sm"
            onClick={async () => {
              await actions.end()
            }}
          >
            End
          </button>
        </div>
      </header>

      {/* The persistent sharing indicator (§30). It is deliberately impossible
          to miss and always carries the stop control. */}
      {isSharing ? (
        <div className="room__sharing-banner" role="status">
          <span className="room__sharing-dot" aria-hidden="true" />
          <span>
            <strong>You’re sharing</strong>
            {session.actualDisplaySurface ? ` · ${surfaceLabel(session.actualDisplaySurface)}` : ''}
            {session.companyName ? ` · ${session.companyName}` : ''}
          </span>
          <button type="button" className="btn btn--danger btn--sm" onClick={() => void actions.stopShare()}>
            <StopCircle size={15} aria-hidden="true" />
            Stop sharing
          </button>
        </div>
      ) : null}

      {session.status === 'PAUSED' ? (
        <div className="room__notice" role="status">
          This session is paused. Nothing is being shared.
        </div>
      ) : null}

      {awaitingApproval ? (
        <div className="room__notice room__notice--waiting" role="status">
          Waiting for the host to admit you to this session.
        </div>
      ) : null}

      {/* The host's approval queue (§71). Nothing is transmitted until this is
          answered — the server will not issue a signalling token before it. */}
      {session.isHost && waiting.length > 0 ? (
        <div className="approval-queue" role="alertdialog" aria-label="Someone wants to join">
          {waiting.map((participant) => (
            <div key={participant.uuid} className="approval-queue__item">
              <div>
                <p className="approval-queue__name">{participant.displayName}</p>
                <p className="approval-queue__role">
                  {participant.role === 'GUEST'
                    ? `External guest${participant.audit?.email ? ` · ${participant.audit.email}` : ''}`
                    : participant.role === 'SUPPORT_TECHNICIAN'
                      ? 'AICOUNTLY Support'
                      : 'AICOUNTLY user'}{' '}
                  would like to {isSharing ? 'view your shared screen' : 'join this Remote session'}.
                </p>
              </div>

              <div className="row">
                <button
                  type="button"
                  className="btn btn--secondary btn--sm"
                  onClick={() => void actions.deny(participant.uuid)}
                >
                  Decline
                </button>
                <button
                  type="button"
                  className="btn btn--primary btn--sm"
                  onClick={() => void actions.approve(participant.uuid)}
                >
                  Allow
                </button>
              </div>
            </div>
          ))}
        </div>
      ) : null}

      <div className="room__body">
        <SessionStage
          session={session}
          live={live}
          pointerEnabled={pointerOn && canAnnotate}
          annotationTool={canAnnotate ? annotationTool : 'none'}
          annotationColour="#25b003"
          onPointerMove={actions.sendPointer}
          onAnnotation={actions.sendAnnotation}
          authorName={me?.displayName ?? 'Participant'}
        />

        {panel ? (
          <SessionSidePanel
            panel={panel}
            session={session}
            live={live}
            messages={messages}
            canChat={canChat}
            onClose={() => setPanel(null)}
            onSendChat={actions.sendChat}
            onApprove={actions.approve}
            onDeny={actions.deny}
          />
        ) : null}
      </div>

      <footer className="room__toolbar">
        <div className="room__toolbar-group">
          {canShare ? (
            isSharing ? (
              <ToolbarButton
                icon={<StopCircle size={18} />}
                label="Stop sharing"
                tone="danger"
                onClick={() => void actions.stopShare()}
              />
            ) : (
              <ToolbarButton
                icon={<MonitorUp size={18} />}
                label="Share"
                tone="primary"
                onClick={() => actions.beginShare(policy?.allowedShareModes[0] ?? 'SAFE_SHARE')}
              />
            )
          ) : null}

          {canUseMicrophone ? (
            <ToolbarButton
              icon={live?.microphoneOn ? <Mic size={18} /> : <MicOff size={18} />}
              label={live?.microphoneOn ? 'Mic on' : 'Mic off'}
              active={live?.microphoneOn}
              onClick={() => void actions.toggleMicrophone()}
            />
          ) : null}

          {canAnnotate ? (
            <>
              <ToolbarButton
                icon={<MousePointer2 size={18} />}
                label="Pointer"
                active={pointerOn && annotationTool === 'none'}
                onClick={() => {
                  setPointerOn((on) => !on)
                  setAnnotationTool('none')
                }}
              />

              <ToolbarButton
                icon={<Pencil size={18} />}
                label="Draw"
                active={annotationTool === 'pen'}
                onClick={() => setAnnotationTool((tool) => (tool === 'pen' ? 'none' : 'pen'))}
              />
              <ToolbarButton
                icon={<Circle size={18} />}
                label="Arrow"
                active={annotationTool === 'arrow'}
                onClick={() => setAnnotationTool((tool) => (tool === 'arrow' ? 'none' : 'arrow'))}
              />
              <ToolbarButton
                icon={<Square size={18} />}
                label="Box"
                active={annotationTool === 'rectangle'}
                onClick={() => setAnnotationTool((tool) => (tool === 'rectangle' ? 'none' : 'rectangle'))}
              />
              <ToolbarButton
                icon={<Highlighter size={18} />}
                label="Highlight"
                active={annotationTool === 'highlight'}
                onClick={() => setAnnotationTool((tool) => (tool === 'highlight' ? 'none' : 'highlight'))}
              />

              {(live?.annotations.length ?? 0) > 0 ? (
                <button type="button" className="btn btn--ghost btn--sm room__ghost" onClick={actions.clearAnnotations}>
                  Clear
                </button>
              ) : null}
            </>
          ) : null}
        </div>

        <div className="room__toolbar-group">
          <ToolbarButton
            icon={<Users size={18} />}
            label="People"
            active={panel === 'participants'}
            badge={waiting.length || undefined}
            onClick={() => setPanel((current) => (current === 'participants' ? null : 'participants'))}
          />

          {canChat ? (
            <ToolbarButton
              icon={<MessageSquare size={18} />}
              label="Chat"
              active={panel === 'chat'}
              onClick={() => setPanel((current) => (current === 'chat' ? null : 'chat'))}
            />
          ) : null}

          <ToolbarButton
            icon={<Info size={18} />}
            label="Details"
            active={panel === 'details'}
            onClick={() => setPanel((current) => (current === 'details' ? null : 'details'))}
          />

          <ToolbarButton
            icon={<Shield size={18} />}
            label="Security"
            active={panel === 'security'}
            onClick={() => setPanel((current) => (current === 'security' ? null : 'security'))}
          />
        </div>
      </footer>

      <ConsentDialog
        state={shareIntent}
        session={session}
        policy={policy}
        onConfirm={() => void actions.confirmShare()}
        onCancel={actions.cancelShare}
        onChangeMode={(mode) => actions.beginShare(mode)}
      />

      {shareIntent.phase === 'error' ? (
        <div className="room__error-toast" role="alert">
          <RestrictionNotice
            compact
            error={
              shareIntent.error instanceof RemoteApiError
                ? shareIntent.error
                : new RemoteApiError(
                    (shareIntent.error as RemoteCaptureError).code,
                    shareIntent.error.message,
                    403,
                  )
            }
            action={
              <button type="button" className="btn btn--secondary btn--sm" onClick={actions.cancelShare}>
                Choose again
              </button>
            }
          />
        </div>
      ) : null}
    </div>
  )
}

function ToolbarButton({
  icon,
  label,
  onClick,
  active = false,
  tone = 'default',
  badge,
}: {
  icon: React.ReactNode
  label: string
  onClick: () => void
  active?: boolean
  tone?: 'default' | 'primary' | 'danger'
  badge?: number
}) {
  return (
    <button
      type="button"
      className={[
        'tool-button',
        active ? 'tool-button--active' : '',
        tone === 'primary' ? 'tool-button--primary' : '',
        tone === 'danger' ? 'tool-button--danger' : '',
      ]
        .filter(Boolean)
        .join(' ')}
      onClick={onClick}
      // The label is always rendered, never an icon alone (§73, §63).
      title={label}
      aria-pressed={active}
    >
      <span className="tool-button__icon" aria-hidden="true">
        {icon}
      </span>
      <span className="tool-button__label">{label}</span>
      {badge ? <span className="tool-button__badge">{badge}</span> : null}
    </button>
  )
}

function surfaceLabel(surface: string): string {
  return (
    { browser: 'Browser tab', window: 'Application window', monitor: 'Entire screen', unknown: 'Selected screen' }[
      surface
    ] ?? 'Selected screen'
  )
}

/**
 * A guest's participant uuid, read back from the token they were issued.
 *
 * The token's payload is not secret — it is bound by its signature, which only
 * the server can produce — so reading the participant id out of it is safe and
 * saves storing the same value twice.
 */
function readGuestParticipantUuid(): string | null {
  const token = getGuestToken()
  if (!token) return null

  try {
    const payload = token.replace(/^guest\./, '').split('.')[0]
    const decoded = JSON.parse(atob(payload.replace(/-/g, '+').replace(/_/g, '/'))) as { p?: string }

    return decoded.p ?? null
  } catch {
    return null
  }
}
