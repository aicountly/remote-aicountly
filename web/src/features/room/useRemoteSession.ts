import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import {
  approveParticipant,
  declareShareIntent,
  denyControl,
  denyParticipant,
  endSession,
  fetchControlState,
  fetchMessages,
  fetchSession,
  grantControl,
  leaveSession,
  pauseSession,
  postMessage,
  reportContextMismatch,
  requestControl,
  requestJoin,
  resumeSession,
  revokeControl,
} from '../../services/api/remote'
import { RemoteApiError } from '../../services/api/client'
import { RemoteSessionEngine } from '../../services/webrtc/RemoteSessionEngine'
import type { AnnotationShape, EngineSnapshot } from '../../services/webrtc/RemoteSessionEngine'
import { readCaptureHandle, requestMicrophone, requestScreenShare } from '../../services/webrtc/screenCapture'
import { RemoteCaptureError } from '../../services/webrtc/screenCapture'
import type {
  ChatMessage,
  EffectivePolicy,
  SessionControlState,
  SessionDetail,
  ShareMode,
} from '../../types/remote'
import type { PointerPosition as ControlPoint } from '../../services/webrtc/remoteControl'

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

/**
 * How often control state is re-read while somebody is waiting on an answer.
 *
 * Faster than the session poll because both ends of a control request are
 * watching a screen for it: the person who asked, and the person deciding.
 */
const CONTROL_POLL_ACTIVE_MS = 4_000

/** And slower when nothing is outstanding, which is nearly always. */
const CONTROL_POLL_IDLE_MS = 15_000

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

  // --- Remote control state (§18, §51) -------------------------------------
  //
  // Read from the server rather than inferred, because the answer is the
  // server's: the negotiated capability of the host, the organisation's policy,
  // this person's permission and the host's decision are four different facts
  // and the browser owns none of them. What the browser owns is showing them.

  const [control, setControl] = useState<SessionControlState | null>(null)
  const [controlBusy, setControlBusy] = useState(false)
  const [controlNotice, setControlNotice] = useState<string | null>(null)

  // A guest holds no AICOUNTLY permission and the control endpoints refuse a
  // guest token, so asking would be a 401 on every poll. Guests see the room
  // without a control panel, which is exactly what they are entitled to.
  const isGuestParticipant = Boolean(guestParticipantUuid)

  const refreshControl = useCallback(async () => {
    if (isGuestParticipant) return

    try {
      const state = await fetchControlState(sessionUuid)

      if (mountedRef.current) setControl(state)
    } catch {
      /* the room does not fail because control state could not be read */
    }
  }, [sessionUuid, isGuestParticipant])

  const controlOutstanding =
    (control?.pendingRequests.length ?? 0) > 0 || session?.me?.controlState === 'REQUESTED'

  useEffect(() => {
    if (isGuestParticipant || ended) return

    void refreshControl()

    const interval = setInterval(
      () => void refreshControl(),
      controlOutstanding ? CONTROL_POLL_ACTIVE_MS : CONTROL_POLL_IDLE_MS,
    )

    return () => clearInterval(interval)
  }, [refreshControl, isGuestParticipant, ended, controlOutstanding])

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
      // The agent has *already* stopped accepting input by the time this
      // arrives — its gate is local. This is the browser catching up: stop
      // sending, say why, and re-read the state the server now holds.
      onControlEnded: (reason) => {
        if (!mountedRef.current) return

        setControlNotice(controlEndedMessage(reason))
        void refreshControl()
        void reload()
      },
    })

    engineRef.current = engine
    void engine.start()

    return () => {
      // Everything: tracks, data channels, peer connections, socket, timers.
      engine.dispose()
      engineRef.current = null
    }
  }, [myParticipantUuid, myStatus, session, sessionUuid, reload, refreshControl])

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

  // --- Remote control actions (§18) ----------------------------------------

  /**
   * The peer whose machine can be controlled, from **negotiated capabilities**.
   *
   * Not `clientType`, not a name, not a guess: a peer that reported
   * `remote_control: true` in its capability declaration. A browser reports
   * false, so this is null for a browser-to-browser session and the room has
   * nothing to offer — which is §51 holding all the way out to the button.
   *
   * The declaration is only ever an upper bound. The server intersects it with
   * the entitlement and the policy before anything is granted, so a peer that
   * lied about its capabilities gets a request the server refuses.
   */
  const controllableHost = useMemo(
    () => live?.peers.find((peer) => peer.capabilities.remote_control === true) ?? null,
    [live?.peers],
  )

  const hostPeerUuid = controllableHost?.participantUuid ?? null
  const controlChannelReady = controllableHost?.controlChannelReady ?? false
  const myControlState = session?.me?.controlState ?? null

  // Input flows only while the server says GRANTED *and* the channel is open.
  // Anything else — denied, revoked, never asked, the peer gone — stops it,
  // locally and without waiting for a round trip.
  useEffect(() => {
    const engine = engineRef.current
    if (!engine) return

    if (myControlState === 'GRANTED' && hostPeerUuid && controlChannelReady) {
      if (!engine.controlling) engine.startControlling(hostPeerUuid)

      return
    }

    if (engine.controlling) engine.stopControlling()
  }, [myControlState, hostPeerUuid, controlChannelReady])

  /** Run one control call, keeping the returned state and surfacing refusals. */
  const runControl = useCallback(
    async (call: () => Promise<{ control: SessionControlState }>) => {
      setControlBusy(true)
      setControlNotice(null)

      try {
        const result = await call()

        if (mountedRef.current) setControl(result.control)

        await reload()
      } catch (err) {
        if (mountedRef.current) {
          setControlNotice(
            err instanceof RemoteApiError
              ? err.message
              : 'That could not be done. Try again in a moment.',
          )
        }
      } finally {
        if (mountedRef.current) setControlBusy(false)
      }
    },
    [reload],
  )

  const askForControl = useCallback(
    () => runControl(() => requestControl(sessionUuid)),
    [runControl, sessionUuid],
  )

  const allowControl = useCallback(
    (participantUuid: string, allowClipboard: boolean) =>
      runControl(() => grantControl(sessionUuid, participantUuid, allowClipboard)),
    [runControl, sessionUuid],
  )

  const refuseControl = useCallback(
    (participantUuid: string) => runControl(() => denyControl(sessionUuid, participantUuid)),
    [runControl, sessionUuid],
  )

  /**
   * Stop control.
   *
   * The engine is stopped **first and unconditionally**. Whoever pressed this
   * is entitled to have it take effect before the network is consulted, and if
   * the network is the reason they pressed it, waiting would be the worst
   * possible behaviour (§18).
   */
  const stopControl = useCallback(
    (participantUuid?: string) => {
      engineRef.current?.stopControlling()

      return runControl(() => revokeControl(sessionUuid, participantUuid))
    },
    [runControl, sessionUuid],
  )

  // Thin pass-throughs for the stage's input capture. Each one is a no-op
  // unless `startControlling()` has been called, so a stray event during a
  // revoked session sends nothing.
  const sendControlPointer = useCallback((position: ControlPoint, force = false) => {
    engineRef.current?.sendControlPointer(position, force)
  }, [])

  const sendControlButton = useCallback(
    (button: number, pressed: boolean, position?: ControlPoint, double = false) => {
      engineRef.current?.sendControlButton(button, pressed, position, double)
    },
    [],
  )

  const sendControlScroll = useCallback(
    (deltaX: number, deltaY: number, position?: ControlPoint) => {
      engineRef.current?.sendControlScroll(deltaX, deltaY, position)
    },
    [],
  )

  const sendControlKey = useCallback(
    (event: KeyboardEvent, pressed: boolean) => engineRef.current?.sendControlKey(event, pressed) ?? false,
    [],
  )

  const sendControlClipboard = useCallback(
    (text: string) => engineRef.current?.sendControlClipboard(text) ?? false,
    [],
  )

  const dismissControlNotice = useCallback(() => setControlNotice(null), [])

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
    control,
    controlBusy,
    controlNotice,
    controllableHost,
    /** Whether input is being sent right now — the persistent indicator (§18). */
    isControlling: live?.controllingPeerId !== null && live?.controllingPeerId !== undefined,
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
      refreshControl,
      askForControl,
      allowControl,
      refuseControl,
      stopControl,
      dismissControlNotice,
      sendControlPointer,
      sendControlButton,
      sendControlScroll,
      sendControlKey,
      sendControlClipboard,
    },
  }
}

/**
 * The agent's `control_ended` reason, in words a person can act on.
 *
 * The reasons come from `remote_protocol` and are deliberately few. Anything
 * unrecognised falls through to the plain truth — control stopped — rather
 * than to the raw token, which would mean nothing to whoever is reading it.
 */
function controlEndedMessage(reason: string): string {
  return (
    {
      stopped_locally: 'The person at the computer stopped remote control.',
      revoked_by_server: 'Remote control was stopped.',
      session_ended: 'The session ended, so remote control stopped with it.',
      connection_lost: 'The connection to that computer was lost, so remote control stopped.',
      shutting_down: 'That computer is restarting, so remote control stopped.',
    }[reason] ?? 'Remote control stopped.'
  )
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
