import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import {
  approveParticipant,
  declareShareIntent,
  denyParticipant,
  endSession,
  fetchMessages,
  fetchSession,
  leaveSession,
  pauseSession,
  postMessage,
  reportContextMismatch,
  requestJoin,
  resumeSession,
} from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import { RemoteSessionEngine } from '../../services/webrtc/RemoteSessionEngine'
import type { AnnotationShape, EngineSnapshot } from '../../services/webrtc/RemoteSessionEngine'
import { readCaptureHandle, requestMicrophone, requestScreenShare } from '../../services/webrtc/screenCapture'
import { RemoteCaptureError } from '../../services/webrtc/screenCapture'
import type { ChatMessage, EffectivePolicy, SessionDetail, ShareMode } from '../../types/remote'

/**
 * The live session, as a hook.
 *
 * The engine owns the WebRTC lifecycle; this owns the React lifecycle and the
 * session record. The division matters because they have different lifetimes —
 * a re-render must not restart a peer connection, and an unmount **must** stop
 * every track (§56).
 *
 * The session record is polled rather than pushed. Peer state arrives over the
 * data channel in real time; what polling covers is the things the server knows
 * and the peers do not — a new person waiting to be admitted, an expiry, an
 * administrator ending the session from elsewhere. Ten seconds is frequent
 * enough for those and cheap enough not to matter (§65).
 */

const SESSION_POLL_MS = 10_000

/** Slower once nothing is pending — most sessions sit in this state. */
const SESSION_POLL_IDLE_MS = 25_000

export type ShareIntentState =
  | { phase: 'idle' }
  | { phase: 'consenting'; shareMode: ShareMode }
  | { phase: 'picking'; shareMode: ShareMode }
  | { phase: 'error'; error: RemoteApiError | RemoteCaptureError }

interface UseRemoteSessionOptions {
  sessionUuid: string
  policy: EffectivePolicy | null
  /** Set for a guest, who has no AICOUNTLY identity to look themselves up by. */
  guestParticipantUuid?: string | null
}

export function useRemoteSession({ sessionUuid, policy, guestParticipantUuid }: UseRemoteSessionOptions) {
  const [session, setSession] = useState<SessionDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<RemoteApiError | null>(null)
  const [messages, setMessages] = useState<ChatMessage[]>([])
  const [shareIntent, setShareIntent] = useState<ShareIntentState>({ phase: 'idle' })
  const [live, setLive] = useState<EngineSnapshot | null>(null)
  const [ended, setEnded] = useState(false)

  const engineRef = useRef<RemoteSessionEngine | null>(null)
  const sessionRef = useRef<SessionDetail | null>(null)
  const mountedRef = useRef(true)

  sessionRef.current = session

  // --- Session record ------------------------------------------------------

  const reload = useCallback(
    async (signal?: AbortSignal) => {
      try {
        const detail = await fetchSession(sessionUuid, signal)
        if (!mountedRef.current) return

        setSession(detail)
        setError(null)

        if (['ENDED', 'EXPIRED', 'DECLINED', 'FAILED'].includes(detail.status)) {
          setEnded(true)
        }
      } catch (err) {
        if (!mountedRef.current) return
        if (err instanceof RemoteApiError && err.code === 'ABORTED') return

        setError(
          err instanceof RemoteApiError
            ? err
            : new RemoteApiError('UNKNOWN', 'This session could not be loaded.', 0),
        )
      } finally {
        if (mountedRef.current) setLoading(false)
      }
    },
    [sessionUuid],
  )

  useEffect(() => {
    mountedRef.current = true
    const controller = new AbortController()

    void reload(controller.signal)

    return () => {
      mountedRef.current = false
      controller.abort()
    }
  }, [reload])

  // Poll faster while somebody is waiting to be admitted — that is the one
  // state where a delay is felt by two people at once.
  const hasPending = (session?.waiting?.length ?? 0) > 0
  useEffect(() => {
    if (ended) return

    const interval = setInterval(() => void reload(), hasPending ? SESSION_POLL_MS : SESSION_POLL_IDLE_MS)

    return () => clearInterval(interval)
  }, [reload, hasPending, ended])

  // --- Engine --------------------------------------------------------------

  const myParticipantUuid = guestParticipantUuid ?? session?.me?.uuid ?? null
  const myStatus = session?.me?.status ?? null

  useEffect(() => {
    // Start only once the host has admitted us. Before that there is no token
    // to be had, so connecting would be a guaranteed 403 (§19).
    if (!myParticipantUuid || !session) return
    if (myStatus !== 'APPROVED' && myStatus !== 'JOINED') return
    if (engineRef.current) return

    const engine = new RemoteSessionEngine({
      sessionUuid,
      participantUuid: myParticipantUuid,
      displayName: session.me?.displayName ?? 'Participant',
      onSnapshot: (snapshot) => {
        if (mountedRef.current) setLive(snapshot)
      },
      onChatMessage: (message) => {
        if (!mountedRef.current) return

        // The same message can arrive over the data channel and be fetched from
        // the API; the uuid is what de-duplicates them.
        setMessages((current) =>
          current.some((existing) => existing.uuid === message.uuid) ? current : [...current, message],
        )
      },
      onPeerShareState: () => void reload(),
      onSessionEnded: () => {
        if (mountedRef.current) setEnded(true)
      },
    })

    engineRef.current = engine
    void engine.start()

    return () => {
      // Everything: tracks, data channels, peer connections, socket, timers.
      engine.dispose()
      engineRef.current = null
    }
  }, [myParticipantUuid, myStatus, session, sessionUuid, reload])

  // Stop capture if this component ever unmounts while sharing. Belt and
  // braces over the effect cleanup above, because a leaked screen capture is
  // the worst failure this product can have.
  useEffect(
    () => () => {
      engineRef.current?.dispose()
      engineRef.current = null
    },
    [],
  )

  // --- Chat ----------------------------------------------------------------

  useEffect(() => {
    if (!session || !session.capabilities.chat) return

    void fetchMessages(sessionUuid)
      .then((history) => {
        if (mountedRef.current) setMessages(history)
      })
      .catch(() => {
        /* chat history is not worth failing the room over */
      })
  }, [session?.uuid, session?.capabilities.chat, sessionUuid, session])

  const sendChat = useCallback(
    async (body: string) => {
      const trimmed = body.trim()
      if (!trimmed) return

      // Persist first so the message survives a reload and appears in the
      // record, then push it over the data channel for immediacy. The receiving
      // side de-duplicates on uuid, so the peer never sees it twice.
      try {
        const message = await postMessage(sessionUuid, trimmed, 'DATA_CHANNEL')

        setMessages((current) =>
          current.some((existing) => existing.uuid === message.uuid) ? current : [...current, message],
        )

        engineRef.current?.sendChat(message)
      } catch (err) {
        setError(err instanceof RemoteApiError ? err : null)
      }
    },
    [sessionUuid],
  )

  // --- Sharing -------------------------------------------------------------

  /**
   * The full share flow (§16, §30):
   *   consent → server authorises the mode → browser picker → surface check →
   *   attach to peers → server records what was actually shared.
   */
  const beginShare = useCallback((shareMode: ShareMode) => {
    setShareIntent({ phase: 'consenting', shareMode })
  }, [])

  const confirmShare = useCallback(async () => {
    if (shareIntent.phase !== 'consenting' || !policy) return

    const { shareMode } = shareIntent
    setShareIntent({ phase: 'picking', shareMode })

    try {
      // The server decides first, so the browser picker never opens for
      // something the organisation was going to refuse anyway.
      const intent = await declareShareIntent(sessionUuid, shareMode)

      const capture = await requestScreenShare(policy, shareMode, {
        allowSystemAudio: intent.allowSystemAudio,
      })

      await engineRef.current?.startSharing(capture.stream, capture.surface)

      // §12 — a cooperating AICOUNTLY tab can identify which company it belongs
      // to. When it names a different one, stop rather than expose it.
      const [videoTrack] = capture.stream.getVideoTracks()
      const handle = videoTrack ? readCaptureHandle(videoTrack) : null

      if (handle && sessionRef.current) {
        const observed = parseCaptureHandle(handle.handle)

        if (
          observed.companyId !== null &&
          sessionRef.current.companyId !== null &&
          observed.companyId !== sessionRef.current.companyId
        ) {
          await engineRef.current?.stopSharing('CONTEXT_MISMATCH')
          await reportContextMismatch(sessionUuid, observed.companyId, observed.product)
          await reload()

          setShareIntent({
            phase: 'error',
            error: new RemoteApiError(
              'CONTEXT_MISMATCH',
              'The tab you shared belongs to a different organisation, so sharing was stopped.',
              409,
            ),
          })

          return
        }
      }

      setShareIntent({ phase: 'idle' })
      await reload()
    } catch (err) {
      // Cancelling the browser picker is not an error worth a red panel.
      if (err instanceof RemoteCaptureError && err.code === 'CANCELLED') {
        setShareIntent({ phase: 'idle' })

        return
      }

      setShareIntent({
        phase: 'error',
        error:
          err instanceof RemoteApiError || err instanceof RemoteCaptureError
            ? err
            : new RemoteApiError('UNKNOWN', 'Screen sharing could not start.', 0),
      })
    }
  }, [shareIntent, policy, sessionUuid, reload])

  const cancelShare = useCallback(() => setShareIntent({ phase: 'idle' }), [])

  const stopShare = useCallback(async () => {
    await engineRef.current?.stopSharing('USER_STOPPED')
    await reload()
  }, [reload])

  // --- Microphone ----------------------------------------------------------

  const toggleMicrophone = useCallback(async () => {
    if (!engineRef.current) return

    if (live?.microphoneOn) {
      await engineRef.current.setMicrophone(null)

      return
    }

    try {
      const stream = await requestMicrophone()
      await engineRef.current.setMicrophone(stream)
    } catch (err) {
      setShareIntent({
        phase: 'error',
        error:
          err instanceof RemoteCaptureError
            ? err
            : new RemoteApiError('UNKNOWN', 'Your microphone could not be started.', 0),
      })
    }
  }, [live?.microphoneOn])

  // --- Moderation and lifecycle -------------------------------------------

  const approve = useCallback(
    async (participantUuid: string) => {
      await approveParticipant(sessionUuid, participantUuid)
      await reload()
    },
    [sessionUuid, reload],
  )

  const deny = useCallback(
    async (participantUuid: string) => {
      await denyParticipant(sessionUuid, participantUuid)
      await reload()
    },
    [sessionUuid, reload],
  )

  const join = useCallback(async () => {
    await requestJoin(sessionUuid)
    await reload()
  }, [sessionUuid, reload])

  const end = useCallback(async () => {
    engineRef.current?.announceSessionEnded()
    await endSession(sessionUuid)
    engineRef.current?.dispose()
    engineRef.current = null
    setEnded(true)
    await reload()
  }, [sessionUuid, reload])

  const leave = useCallback(async () => {
    const participantUuid = sessionRef.current?.me?.uuid ?? guestParticipantUuid
    if (participantUuid) {
      await leaveSession(sessionUuid, participantUuid).catch(() => undefined)
    }

    engineRef.current?.dispose()
    engineRef.current = null
  }, [sessionUuid, guestParticipantUuid])

  const pause = useCallback(async () => {
    await pauseSession(sessionUuid)
    await reload()
  }, [sessionUuid, reload])

  const resume = useCallback(async () => {
    await resumeSession(sessionUuid)
    await reload()
  }, [sessionUuid, reload])

  // --- File transfer (§36) -------------------------------------------------
  //
  // Thin pass-throughs: the engine owns the transfer, because a transfer
  // outlives a render exactly as a peer connection does. What the hook adds is
  // nothing — deliberately. A file that kept sending because a component
  // re-rendered would be a bug nobody could reproduce.

  const offerFile = useCallback(async (file: File, toParticipantUuid?: string | null) => {
    const engine = engineRef.current
    if (!engine) throw new Error('PEER_NOT_CONNECTED')

    await engine.offerFile(file, toParticipantUuid)
  }, [])

  const acceptTransfer = useCallback(async (transferUuid: string) => {
    await engineRef.current?.acceptTransfer(transferUuid)
  }, [])

  const declineTransfer = useCallback(async (transferUuid: string) => {
    await engineRef.current?.declineTransfer(transferUuid)
  }, [])

  const cancelTransfer = useCallback(async (transferUuid: string) => {
    await engineRef.current?.cancelTransfer(transferUuid)
  }, [])

  const dismissTransfer = useCallback((transferUuid: string) => {
    engineRef.current?.dismissTransfer(transferUuid)
  }, [])

  // --- Collaboration -------------------------------------------------------

  const sendPointer = useCallback((x: number, y: number) => {
    engineRef.current?.sendPointer(x, y)
  }, [])

  const sendAnnotation = useCallback((shape: AnnotationShape) => {
    engineRef.current?.sendAnnotation(shape)
  }, [])

  const clearAnnotations = useCallback(() => {
    engineRef.current?.clearAnnotations()
  }, [])

  const elapsedSeconds = useMemo(() => {
    if (!session?.startedAt) return 0

    return Math.max(0, Math.floor((Date.now() - new Date(session.startedAt).getTime()) / 1000))
  }, [session?.startedAt])

  return {
    session,
    loading,
    error,
    ended,
    messages,
    live,
    shareIntent,
    elapsedSeconds,
    actions: {
      reload,
      beginShare,
      confirmShare,
      cancelShare,
      stopShare,
      toggleMicrophone,
      sendChat,
      sendPointer,
      sendAnnotation,
      clearAnnotations,
      offerFile,
      acceptTransfer,
      declineTransfer,
      cancelTransfer,
      dismissTransfer,
      approve,
      deny,
      join,
      end,
      leave,
      pause,
      resume,
    },
  }
}

/**
 * Read the opaque capture handle a cooperating AICOUNTLY tab published (§17).
 *
 * The format is `aicountly:<product>:<companyId>` — a product code and an
 * organisation id, and nothing else. It carries no name, no user and no data:
 * enough for Remote to notice that the tab belongs to a different organisation
 * than the session, which is the only question being asked.
 */
function parseCaptureHandle(handle: string): { product: string | null; companyId: number | null } {
  const match = /^aicountly:([a-z_-]+):(\d+)$/i.exec(handle.trim())

  if (!match) return { product: null, companyId: null }

  return { product: match[1].toUpperCase(), companyId: Number(match[2]) }
}
