import { useEffect, useRef, useState } from 'react'

import type { EngineSnapshot } from '../../services/webrtc/RemoteSessionEngine'
import type { SessionStatus } from '../../types/remote'

/**
 * Connection state, in words (§49).
 *
 * No ICE, no DTLS, no candidate pairs. A person watching a session go wrong
 * needs to know whether to wait or to do something, and "Reconnecting…" answers
 * that where "ICE disconnected" does not. The technical detail is still logged
 * for diagnostics; it just is not the interface.
 *
 * The state is also announced to screen readers, because a silent transition
 * from connected to interrupted is exactly the sort of change that must not be
 * visual-only (§63).
 */

interface Props {
  live: EngineSnapshot | null
  sessionStatus: SessionStatus
  /** Try again after the connection gave up. Absent: no button. */
  onReconnect?: () => void
}

interface Presentation {
  label: string
  tone: 'good' | 'warn' | 'bad' | 'idle'
  /** Announced to assistive technology when it changes. */
  announcement: string
}

function present(live: EngineSnapshot | null, sessionStatus: SessionStatus): Presentation {
  if (sessionStatus === 'PAUSED') {
    return { label: 'Paused', tone: 'warn', announcement: 'The session is paused.' }
  }

  switch (live?.connection) {
    case 'connected':
      return quality(live)
    case 'connecting':
      return {
        label: 'Establishing secure connection…',
        tone: 'idle',
        announcement: 'Establishing a secure connection.',
      }
    case 'waiting-for-peer':
      return { label: 'Waiting for the other person', tone: 'idle', announcement: 'Waiting for the other person to join.' }
    case 'reconnecting':
      return { label: 'Reconnecting…', tone: 'warn', announcement: 'The connection dropped. Reconnecting.' }
    case 'interrupted':
      return { label: 'Connection unstable', tone: 'warn', announcement: 'The connection is unstable.' }
    case 'failed':
      return {
        label: 'Unable to reconnect',
        tone: 'bad',
        announcement: 'The connection could not be re-established.',
      }
    default:
      return { label: 'Connecting…', tone: 'idle', announcement: 'Connecting.' }
  }
}

function quality(live: EngineSnapshot): Presentation {
  switch (live.quality) {
    case 'poor':
      return { label: 'Connection poor', tone: 'warn', announcement: 'Connected, but the connection is poor.' }
    case 'fair':
      return { label: 'Connection fair', tone: 'warn', announcement: 'Connected with a fair connection.' }
    default:
      return { label: 'Connected', tone: 'good', announcement: 'Connected.' }
  }
}

export default function ConnectionIndicator({ live, sessionStatus, onReconnect }: Props) {
  const { label, tone, announcement } = present(live, sessionStatus)
  const [announced, setAnnounced] = useState('')
  const previous = useRef('')

  useEffect(() => {
    if (previous.current === announcement) return

    previous.current = announcement
    setAnnounced(announcement)
  }, [announcement])

  return (
    <>
      <span className={`connection connection--${tone}`}>
        <span className="connection__dot" aria-hidden="true" />
        <span className="connection__label">{label}</span>
      </span>

      <span className="sr-only" role="status" aria-live="polite">
        {announced}
      </span>

      {/* Why it failed, from what the browser actually saw — no relay
          configured, the relay unreachable or refusing — rather than
          "reconnecting" forever (§20, docs/ICE_CONTRACT.md). */}
      {live?.connection === 'failed' ? (
        <span className="connection__hint">
          {live.network.message ??
            (live.relayAvailable === false ? 'A direct connection could not be made on this network.' : null)}
          {onReconnect ? (
            <button type="button" className="btn btn--secondary btn--sm connection__retry" onClick={onReconnect}>
              Reconnect
            </button>
          ) : null}
        </span>
      ) : null}
    </>
  )
}
