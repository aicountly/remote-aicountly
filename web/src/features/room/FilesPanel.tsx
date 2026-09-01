import { useRef, useState } from 'react'
import { AlertTriangle, Ban, Check, Download, Paperclip, X } from 'lucide-react'

import { safeFileName } from '../../services/webrtc/fileTransfer'
import type { TransferView } from '../../services/webrtc/fileTransfer'
import type { EnginePeer } from '../../services/webrtc/RemoteSessionEngine'
import { RemoteApiError } from '../../services/api/client'
import { formatBytes } from '../../utils/format'

/**
 * Sending and receiving files during a session (§36).
 *
 * Three things this panel is careful about, because they are the three ways a
 * file transfer goes wrong for a person rather than for a program:
 *
 *   * **Nothing arrives without being asked for.** An incoming file is an offer
 *     with the sender's name on it, and it is refused by default. Bytes only
 *     start moving after Accept.
 *   * **Nothing lands on the disk on its own.** A received file is held in this
 *     tab until Save is pressed. A silent download is how a support session
 *     turns into a delivery mechanism.
 *   * **It says where the file goes.** Straight to the other browser, never
 *     through AICOUNTLY — which is worth stating, because most people
 *     reasonably assume the opposite.
 */

interface Props {
  transfers: TransferView[]
  peers: EnginePeer[]
  canSend: boolean
  canReceive: boolean
  maxBytes: number
  onOffer: (file: File, toParticipantUuid?: string | null) => Promise<void>
  onAccept: (uuid: string) => Promise<void>
  onDecline: (uuid: string) => Promise<void>
  onCancel: (uuid: string) => Promise<void>
  onDismiss: (uuid: string) => void
}

export default function FilesPanel({
  transfers,
  peers,
  canSend,
  canReceive,
  maxBytes,
  onOffer,
  onAccept,
  onDecline,
  onCancel,
  onDismiss,
}: Props) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [recipient, setRecipient] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const connected = peers.filter((peer) => peer.dataChannelReady)
  const chosen = recipient || (connected.length === 1 ? connected[0].participantUuid : '')

  async function choose(file: File | undefined) {
    if (!file || busy) return

    setError(null)

    // The server enforces the ceiling; checking here too means a person is told
    // before they wait for an upload dialog rather than after.
    if (file.size > maxBytes) {
      setError(`“${file.name}” is ${formatBytes(file.size)}. The most you can send is ${formatBytes(maxBytes)}.`)

      return
    }

    if (file.size === 0) {
      setError(`“${file.name}” is empty, so there is nothing to send.`)

      return
    }

    setBusy(true)

    try {
      await onOffer(file, chosen || null)
    } catch (err) {
      setError(describeOfferError(err))
    } finally {
      setBusy(false)
      // Reset the input so choosing the same file twice still fires a change.
      if (inputRef.current) inputRef.current.value = ''
    }
  }

  return (
    <div className="files">
      <p className="files__lede">
        Files go straight from one browser to the other over the same encrypted connection as the screen.
        AICOUNTLY never receives or stores them — only a record of who sent what, and whether it was accepted.
      </p>

      {canSend ? (
        <div className="files__send">
          {connected.length > 1 ? (
            <>
              <label className="field__label" htmlFor="file-recipient">
                Send to
              </label>
              <select
                id="file-recipient"
                className="input"
                value={chosen}
                onChange={(event) => setRecipient(event.target.value)}
              >
                <option value="">Choose someone…</option>
                {connected.map((peer) => (
                  <option key={peer.participantUuid} value={peer.participantUuid}>
                    {peer.name}
                  </option>
                ))}
              </select>
            </>
          ) : null}

          <input
            ref={inputRef}
            id="file-input"
            className="sr-only"
            type="file"
            onChange={(event) => void choose(event.target.files?.[0])}
          />

          <button
            type="button"
            className="btn btn--secondary btn--sm files__choose"
            disabled={busy || connected.length === 0 || (connected.length > 1 && !chosen)}
            onClick={() => inputRef.current?.click()}
          >
            <Paperclip size={15} aria-hidden="true" />
            {busy ? 'Offering…' : 'Choose a file'}
          </button>

          <p className="files__hint">
            {connected.length === 0
              ? 'Nobody is connected yet, so there is nowhere to send a file.'
              : `Up to ${formatBytes(maxBytes)}. The other person has to accept before anything is sent.`}
          </p>
        </div>
      ) : (
        <p className="files__hint">
          {canReceive
            ? 'You can receive files in this session, but not send them.'
            : 'File transfer is turned off for this session.'}
        </p>
      )}

      {error ? (
        <p className="files__error" role="alert">
          <AlertTriangle size={14} aria-hidden="true" />
          {error}
        </p>
      ) : null}

      {transfers.length === 0 ? (
        <p className="files__empty">No files have been sent in this session.</p>
      ) : (
        <ul className="files__list">
          {transfers.map((transfer) => (
            <TransferRow
              key={transfer.uuid}
              transfer={transfer}
              onAccept={onAccept}
              onDecline={onDecline}
              onCancel={onCancel}
              onDismiss={onDismiss}
            />
          ))}
        </ul>
      )}
    </div>
  )
}

function TransferRow({
  transfer,
  onAccept,
  onDecline,
  onCancel,
  onDismiss,
}: {
  transfer: TransferView
  onAccept: (uuid: string) => Promise<void>
  onDecline: (uuid: string) => Promise<void>
  onCancel: (uuid: string) => Promise<void>
  onDismiss: (uuid: string) => void
}) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const incoming = transfer.direction === 'incoming'
  const running = transfer.phase === 'accepted' || transfer.phase === 'transferring'
  const finished = ['completed', 'declined', 'cancelled', 'failed'].includes(transfer.phase)

  async function run(action: () => Promise<void>) {
    setBusy(true)
    setError(null)

    try {
      await action()
    } catch (err) {
      setError(describeOfferError(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <li className={`transfer transfer--${transfer.phase}`}>
      <div className="transfer__head">
        <p className="transfer__name truncate" title={transfer.fileName}>
          {transfer.fileName}
        </p>
        <span className="transfer__size mono">{formatBytes(transfer.fileSize)}</span>
      </div>

      <p className="transfer__meta">
        {incoming ? `From ${transfer.peerName}` : `To ${transfer.peerName}`} · {describePhase(transfer)}
      </p>

      {running ? (
        <div
          className="transfer__bar"
          role="progressbar"
          aria-valuenow={transfer.progress}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-label={`${transfer.fileName} ${transfer.progress}% transferred`}
        >
          <span className="transfer__bar-fill" style={{ width: `${transfer.progress}%` }} />
        </div>
      ) : null}

      {error ? (
        <p className="transfer__error" role="alert">
          {error}
        </p>
      ) : null}

      <div className="transfer__actions">
        {incoming && transfer.phase === 'offered' ? (
          <>
            <button
              type="button"
              className="btn btn--secondary btn--sm"
              disabled={busy}
              onClick={() => void run(() => onDecline(transfer.uuid))}
            >
              <Ban size={14} aria-hidden="true" />
              Decline
            </button>
            <button
              type="button"
              className="btn btn--primary btn--sm"
              disabled={busy}
              onClick={() => void run(() => onAccept(transfer.uuid))}
            >
              <Check size={14} aria-hidden="true" />
              Accept
            </button>
          </>
        ) : null}

        {running ? (
          <button
            type="button"
            className="btn btn--secondary btn--sm"
            disabled={busy}
            onClick={() => void run(() => onCancel(transfer.uuid))}
          >
            Cancel
          </button>
        ) : null}

        {/* Explicitly pressed, never automatic: a file that saves itself is a
            file nobody decided to keep. */}
        {transfer.phase === 'completed' && transfer.blob ? (
          <button type="button" className="btn btn--primary btn--sm" onClick={() => saveTransfer(transfer)}>
            <Download size={14} aria-hidden="true" />
            Save
          </button>
        ) : null}

        {transfer.phase === 'offered' && !incoming ? (
          <button
            type="button"
            className="btn btn--secondary btn--sm"
            disabled={busy}
            onClick={() => void run(() => onCancel(transfer.uuid))}
          >
            Withdraw
          </button>
        ) : null}

        {finished ? (
          <button
            type="button"
            className="btn btn--ghost btn--sm room__ghost"
            onClick={() => onDismiss(transfer.uuid)}
            aria-label={`Dismiss ${transfer.fileName}`}
          >
            <X size={14} aria-hidden="true" />
          </button>
        ) : null}
      </div>
    </li>
  )
}

/**
 * Write the received file to disk.
 *
 * Two deliberate choices. The blob is `application/octet-stream` whatever the
 * sender claimed, so the browser saves it instead of rendering it — an object
 * URL the browser will display inline is one that can run script in this origin.
 * And the name is sanitised again here, because this is the hop where a string
 * that came from another browser becomes a path.
 */
function saveTransfer(transfer: TransferView): void {
  if (!transfer.blob) return

  const url = URL.createObjectURL(transfer.blob)
  const link = document.createElement('a')

  link.href = url
  link.download = safeFileName(transfer.fileName)
  link.rel = 'noopener'
  document.body.appendChild(link)
  link.click()
  link.remove()

  // Revoking immediately cancels the save in some browsers; a short delay is
  // the documented way round it.
  setTimeout(() => URL.revokeObjectURL(url), 30_000)
}

function describePhase(transfer: TransferView): string {
  const incoming = transfer.direction === 'incoming'

  switch (transfer.phase) {
    case 'offered':
      return incoming ? 'Waiting for you to accept' : 'Waiting for them to accept'
    case 'accepted':
      return 'Starting'
    case 'transferring':
      return `${transfer.progress}% · ${formatBytes(transfer.bytesTransferred)}`
    case 'completed':
      return incoming ? 'Received — not saved yet' : 'Delivered'
    case 'declined':
      return incoming ? 'You declined it' : 'They declined it'
    case 'cancelled':
      return 'Cancelled'
    case 'failed':
      return transfer.error ? `Failed · ${describeTransferError(transfer.error)}` : 'Failed'
    default:
      return ''
  }
}

/** The machine codes the engine and the API use, in plain words (§74). */
function describeTransferError(code: string): string {
  return (
    {
      CHANNEL_CLOSED: 'the connection closed',
      CHANNEL_STALLED: 'the connection stopped responding',
      PEER_LEFT: 'they left the session',
      OUT_OF_ORDER: 'the file arrived damaged',
      TOO_LARGE: 'more was sent than was agreed',
    }[code] ?? 'an unexpected problem'
  )
}

function describeOfferError(error: unknown): string {
  if (error instanceof RemoteApiError) return error.message

  const code = error instanceof Error ? error.message : ''

  return (
    {
      PEER_NOT_CONNECTED: 'That person is not connected yet, so a file cannot be sent to them.',
      RECIPIENT_REQUIRED: 'Choose who should receive this file.',
    }[code] ?? 'That file could not be sent.'
  )
}
