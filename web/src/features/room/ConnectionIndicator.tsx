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
 *
 * While connected, the word has two figures beside it — the round trip and the
 * frame rate arriving — because "the picture is slow" has two different causes,
 * the network and the computer at the other end, and they are what tell them
 * apart. They are shown, never announced: a screen reader reading out a new
 * round trip every two seconds would drown the changes that matter.
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

/**
 * `45 ms · 15 fps`, or as much of it as the browser measured — only while connected and not paused,
 * because a figure from a connection that is not carrying the session describes nothing. The sharer
 * receives no picture, so for them it is the round trip alone.
 */
function describeStats(live: EngineSnapshot | null, sessionStatus: SessionStatus): string | null {
  if (sessionStatus === 'PAUSED' || live?.connection !== 'connected' || !live.stats) return null

  const { roundTripMs, framesPerSecond } = live.stats
  const figures = [
    roundTripMs !== null ? `${roundTripMs} ms` : null,
    framesPerSecond !== null ? `${framesPerSecond} fps` : null,
  ].filter((figure): figure is string => figure !== null)

  return figures.length > 0 ? figures.join(' · ') : null
}

export default function ConnectionIndicator({ live, sessionStatus, onReconnect }: Props) {
  const { label, tone, announcement } = present(live, sessionStatus)
  const figures = describeStats(live, sessionStatus)
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
        {figures ? (
          <span
            className="connection__figures"
            title="The round trip to the other side, and the frames a second of the shared screen arriving here. A still screen sends few frames; that is normal."
          >
            <span aria-hidden="true">· </span>
            {figures}
          </span>
        ) : null}
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
            (live.relayDenied
              ? 'A direct connection could not be made, and relay capacity is fully used for this account right now. Try again shortly.'
              : live.relayAvailable === false
                ? 'A direct connection could not be made on this network.'
                : null)}
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
