import { useEffect, useRef, useState } from 'react'
import { Mic, MonitorUp, Send, ShieldCheck, X } from 'lucide-react'

import StatusBadge from '../../components/ui/StatusBadge'
import InvitePanel from './InvitePanel'
import type { EngineSnapshot } from '../../services/webrtc/RemoteSessionEngine'
import type { ChatMessage, SessionDetail } from '../../types/remote'
import { describeShareMode, describeSurface, formatTime } from '../../utils/format'

/**
 * The collapsible right-hand panel (§33): people, chat, details, security.
 *
 * Chat is deliberately a panel rather than an overlay — §35 asks that it not
 * dominate the shared screen, and a docked column keeps the video at full size
 * instead of covering the corner somebody is trying to point at.
 */

type Panel = 'participants' | 'chat' | 'invite' | 'details' | 'security'

interface Props {
  panel: Panel
  session: SessionDetail
  live: EngineSnapshot | null
  messages: ChatMessage[]
  canChat: boolean
  canInviteExternal: boolean
  onClose: () => void
  onSendChat: (body: string) => Promise<void>
  onApprove: (participantUuid: string) => Promise<void>
  onDeny: (participantUuid: string) => Promise<void>
}

const TITLES: Record<Panel, string> = {
  participants: 'Participants',
  chat: 'Chat',
  invite: 'Invite someone',
  details: 'Session details',
  security: 'Security',
}

export default function SessionSidePanel({
  panel,
  session,
  live,
  messages,
  canChat,
  canInviteExternal,
  onClose,
  onSendChat,
  onApprove,
  onDeny,
}: Props) {
  return (
    <aside className="side-panel" aria-label={TITLES[panel]}>
      <div className="side-panel__header">
        <h2 className="side-panel__title">{TITLES[panel]}</h2>
        <button type="button" className="btn btn--ghost btn--sm room__ghost" onClick={onClose} aria-label="Close panel">
          <X size={16} aria-hidden="true" />
        </button>
      </div>

      <div className="side-panel__body">
        {panel === 'participants' ? (
          <ParticipantsPanel session={session} live={live} onApprove={onApprove} onDeny={onDeny} />
        ) : null}

        {panel === 'chat' ? <ChatPanel messages={messages} canChat={canChat} onSend={onSendChat} /> : null}

        {panel === 'invite' ? (
          <InvitePanel session={session} canInviteExternal={canInviteExternal} />
        ) : null}

        {panel === 'details' ? <DetailsPanel session={session} /> : null}

        {panel === 'security' ? <SecurityPanel session={session} live={live} /> : null}
      </div>
    </aside>
  )
}

function ParticipantsPanel({
  session,
  live,
  onApprove,
  onDeny,
}: {
  session: SessionDetail
  live: EngineSnapshot | null
  onApprove: (uuid: string) => Promise<void>
  onDeny: (uuid: string) => Promise<void>
}) {
  const present = session.participants.filter((participant) => participant.status !== 'LEFT')

  return (
    <ul className="participant-list">
      {present.map((participant) => {
        const peer = live?.peers.find((entry) => entry.participantUuid === participant.uuid)

        return (
          <li key={participant.uuid} className="participant">
            <div className="participant__avatar" aria-hidden="true">
              {participant.displayName.slice(0, 1).toUpperCase()}
            </div>

            <div className="participant__body">
              <p className="participant__name truncate">
                {participant.displayName}
                {participant.isHost ? <span className="participant__tag">Host</span> : null}
              </p>
              <p className="participant__role">
                {roleLabel(participant.role)}
                {peer?.dataChannelReady ? ' · connected' : ''}
              </p>
            </div>

            <div className="participant__meta">
              {participant.isSharing ? (
                <span className="participant__icon" title="Sharing a screen">
                  <MonitorUp size={14} aria-hidden="true" />
                  <span className="sr-only">Sharing a screen</span>
                </span>
              ) : null}

              {participant.microphoneEnabled ? (
                <span className="participant__icon" title="Microphone on">
                  <Mic size={14} aria-hidden="true" />
                  <span className="sr-only">Microphone on</span>
                </span>
              ) : null}

              <StatusBadge status={participant.status} kind="participant" />
            </div>

            {session.isHost && participant.status === 'REQUESTED' ? (
              <div className="participant__actions">
                <button type="button" className="btn btn--secondary btn--sm" onClick={() => void onDeny(participant.uuid)}>
                  Decline
                </button>
                <button type="button" className="btn btn--primary btn--sm" onClick={() => void onApprove(participant.uuid)}>
                  Allow
                </button>
              </div>
            ) : null}
          </li>
        )
      })}
    </ul>
  )
}

function ChatPanel({
  messages,
  canChat,
  onSend,
}: {
  messages: ChatMessage[]
  canChat: boolean
  onSend: (body: string) => Promise<void>
}) {
  const [draft, setDraft] = useState('')
  const [sending, setSending] = useState(false)
  const endRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    endRef.current?.scrollIntoView({ block: 'end' })
  }, [messages.length])

  async function submit(event: React.FormEvent) {
    event.preventDefault()

    const body = draft.trim()
    if (!body || sending) return

    setSending(true)
    setDraft('')

    try {
      await onSend(body)
    } finally {
      setSending(false)
    }
  }

  return (
    <div className="chat">
      <div className="chat__messages" role="log" aria-live="polite" aria-label="Session chat">
        {messages.length === 0 ? (
          <p className="chat__empty">
            Messages you send here stay in this session and are visible to everyone in it.
          </p>
        ) : (
          messages.map((message) => (
            <div
              key={message.uuid}
              className={message.messageType === 'SYSTEM' ? 'chat__message chat__message--system' : 'chat__message'}
            >
              {message.messageType === 'SYSTEM' ? (
                <p className="chat__system">{message.body}</p>
              ) : (
                <>
                  <p className="chat__meta">
                    <span className="chat__author">{message.authorName}</span>
                    <span className="chat__time">{formatTime(message.createdAt)}</span>
                  </p>
                  <p className="chat__body">{message.body}</p>
                </>
              )}
            </div>
          ))
        )}
        <div ref={endRef} />
      </div>

      {canChat ? (
        <form className="chat__composer" onSubmit={submit}>
          <label className="sr-only" htmlFor="chat-input">
            Message
          </label>
          <input
            id="chat-input"
            className="input"
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            placeholder="Write a message"
            maxLength={4000}
            autoComplete="off"
          />
          <button type="submit" className="btn btn--primary btn--sm" disabled={!draft.trim() || sending}>
            <Send size={15} aria-hidden="true" />
            <span className="sr-only">Send</span>
          </button>
        </form>
      ) : (
        <p className="chat__disabled">Chat is turned off for this session.</p>
      )}
    </div>
  )
}

function DetailsPanel({ session }: { session: SessionDetail }) {
  return (
    <dl className="detail-list">
      <Detail label="Session" value={session.displayId} mono />
      {session.sessionCode ? <Detail label="Session code" value={session.sessionCode} mono /> : null}
      <Detail label="Organisation" value={session.companyName ?? 'Personal (no organisation)'} />
      {session.sourceProductLabel ? <Detail label="Started from" value={session.sourceProductLabel} /> : null}
      {session.sourceRoute ? <Detail label="Area" value={session.sourceRoute} /> : null}
      {session.supportTicketId ? <Detail label="Ticket" value={session.supportTicketId} /> : null}
      <Detail label="Sharing" value={describeShareMode(session.requestedShareMode)} />
      <Detail label="Surface" value={describeSurface(session.actualDisplaySurface)} />
      <Detail label="Maximum duration" value={`${session.maxDurationMinutes} minutes`} />
    </dl>
  )
}

/**
 * What is protecting this session, in plain language (§98).
 *
 * Every claim here is one the product actually delivers. There is no
 * "military-grade" anything, and where a guarantee is weaker than it looks —
 * an unverified sharing surface — it says so.
 */
function SecurityPanel({ session, live }: { session: SessionDetail; live: EngineSnapshot | null }) {
  const surfaceVerified = session.actualDisplaySurface !== null && session.actualDisplaySurface !== 'unknown'

  return (
    <ul className="security-list">
      <SecurityItem
        good
        title="User-approved screen sharing"
        body="You chose what to share in your browser’s own picker, and you can stop at any moment."
      />
      <SecurityItem
        good
        title="Every viewer is admitted by the host"
        body="Nobody receives your screen until you allow them into the session."
      />
      <SecurityItem
        good={session.companyId !== null}
        title="Company policy protected"
        body={
          session.companyId !== null
            ? `${session.companyName ?? 'Your organisation'} controls which sharing options are available in this session.`
            : 'This is a personal session, so no organisation policy applies to it.'
        }
      />
      <SecurityItem
        good={surfaceVerified}
        title={surfaceVerified ? 'Sharing surface verified' : 'Sharing surface not reported'}
        body={
          surfaceVerified
            ? `Your browser confirmed you are sharing: ${describeSurface(session.actualDisplaySurface)}.`
            : 'This browser does not report which surface was picked, so it could not be verified. Your organisation’s permitted sharing modes still apply.'
        }
      />
      <SecurityItem
        good
        title="Session activity is logged"
        body="Who joined, what was shared and when it ended are recorded. The contents of your screen are not."
      />
      <SecurityItem
        good={live?.relayAvailable !== false}
        title="Direct, encrypted connection"
        body={
          live?.relayAvailable === false
            ? 'Media travels directly between browsers over an encrypted connection. No relay is configured on this deployment, so very restrictive networks may not connect.'
            : 'Media travels directly between browsers over an encrypted connection, or through AICOUNTLY’s relay when a network requires it.'
        }
      />
    </ul>
  )
}

function SecurityItem({ good, title, body }: { good: boolean; title: string; body: string }) {
  return (
    <li className={good ? 'security-item' : 'security-item security-item--caution'}>
      <ShieldCheck size={15} aria-hidden="true" />
      <div>
        <p className="security-item__title">{title}</p>
        <p className="security-item__body">{body}</p>
      </div>
    </li>
  )
}

function Detail({ label, value, mono = false }: { label: string; value: string; mono?: boolean }) {
  return (
    <div className="detail-list__row">
      <dt>{label}</dt>
      <dd className={mono ? 'mono' : undefined}>{value}</dd>
    </div>
  )
}

function roleLabel(role: string): string {
  return (
    {
      SHARER: 'Sharing',
      VIEWER: 'Viewer',
      SUPPORT_TECHNICIAN: 'AICOUNTLY Support',
      OBSERVER: 'Observer',
      GUEST: 'External guest',
    }[role] ?? role
  )
}
