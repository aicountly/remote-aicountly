import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactElement } from 'react'
import { MonitorOff, MousePointer2, ShieldCheck } from 'lucide-react'

import type { AnnotationShape, EngineSnapshot } from '../../services/webrtc/RemoteSessionEngine'
import { normalisePointer } from '../../services/webrtc/remoteControl'
import type { PointerPosition as ControlPoint } from '../../services/webrtc/remoteControl'
import type { SessionDetail } from '../../types/remote'

/**
 * The shared screen, with the pointer and annotation overlays on top (§34).
 *
 * The overlays are **overlays**. They are drawn on a canvas that sits above the
 * video and they never touch the shared application — a viewer drawing a circle
 * changes nothing on the sharer's machine, which is the honest limit of what a
 * browser can do and exactly what Remote promises.
 *
 * Coordinates travel normalised (0..1) so a pointer lands in the same place on
 * a 4K sharer and a laptop viewer.
 */

export type AnnotationTool = 'none' | 'pen' | 'arrow' | 'rectangle' | 'highlight'

interface Props {
  session: SessionDetail
  live: EngineSnapshot | null
  /** Pointer and annotation are only offered when policy permits them. */
  pointerEnabled: boolean
  annotationTool: AnnotationTool
  annotationColour: string
  onPointerMove: (x: number, y: number) => void
  onAnnotation: (shape: AnnotationShape) => void
  authorName: string
  /**
   * Whether this browser is sending input to the machine on screen.
   *
   * True only after the server recorded a grant and the control channel opened
   * — the hook decides, this renders it. While it is true the stage takes over
   * the pointer and the keyboard, and while it is false none of that is bound
   * at all (§18).
   */
  controlling?: boolean
  onControlPointer?: (position: ControlPoint, force?: boolean) => void
  onControlButton?: (button: number, pressed: boolean, position?: ControlPoint, double?: boolean) => void
  onControlScroll?: (deltaX: number, deltaY: number, position?: ControlPoint) => void
  onControlKey?: (event: KeyboardEvent, pressed: boolean) => boolean
  onControlClipboard?: (text: string) => boolean
  /** Set when the host granted the clipboard as well. Separate from control. */
  clipboardShared?: boolean
}

export default function SessionStage({
  session,
  live,
  pointerEnabled,
  annotationTool,
  annotationColour,
  onPointerMove,
  onAnnotation,
  authorName,
  controlling = false,
  onControlPointer,
  onControlButton,
  onControlScroll,
  onControlKey,
  onControlClipboard,
  clipboardShared = false,
}: Props) {
  const videoRef = useRef<HTMLVideoElement>(null)
  const surfaceRef = useRef<HTMLDivElement>(null)
  const [drawing, setDrawing] = useState<AnnotationShape | null>(null)

  const stream = live?.remoteStream ?? live?.localStream ?? null
  const isViewingRemote = Boolean(live?.remoteStream)

  useEffect(() => {
    const video = videoRef.current
    if (!video) return

    if (video.srcObject !== stream) {
      video.srcObject = stream

      // A stream can arrive before the element is ready to play it; autoplay
      // rejection here is normal and not worth surfacing.
      video.play().catch(() => undefined)
    }
  }, [stream])

  const toNormalised = useCallback((event: { clientX: number; clientY: number }) => {
    const rect = surfaceRef.current?.getBoundingClientRect()
    if (!rect || rect.width === 0 || rect.height === 0) return null

    return {
      x: Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width)),
      y: Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height)),
    }
  }, [])

  const handlePointerMove = useCallback(
    (event: React.PointerEvent) => {
      const point = toNormalised(event)
      if (!point) return

      if (pointerEnabled && annotationTool === 'none') {
        // Throttling happens in the engine, not here: a React state update per
        // mousemove would be the more expensive half (§65).
        onPointerMove(point.x, point.y)
      }

      if (drawing) {
        setDrawing((current) =>
          current
            ? {
                ...current,
                points:
                  current.tool === 'pen'
                    ? [...current.points, point]
                    : [current.points[0], point],
              }
            : null,
        )
      }
    },
    [toNormalised, pointerEnabled, annotationTool, onPointerMove, drawing],
  )

  const handlePointerDown = useCallback(
    (event: React.PointerEvent) => {
      if (annotationTool === 'none') return

      const point = toNormalised(event)
      if (!point) return

      event.currentTarget.setPointerCapture(event.pointerId)

      setDrawing({
        id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
        tool: annotationTool,
        points: [point, point],
        colour: annotationColour,
        author: authorName,
      })
    },
    [annotationTool, toNormalised, annotationColour, authorName],
  )

  const handlePointerUp = useCallback(() => {
    if (!drawing) return

    // A tap with no movement is not a shape; discard it rather than sending a
    // dot every time someone clicks.
    const [start, end] = [drawing.points[0], drawing.points[drawing.points.length - 1]]
    const moved = Math.abs(start.x - end.x) > 0.005 || Math.abs(start.y - end.y) > 0.005

    if (moved) onAnnotation(drawing)

    setDrawing(null)
  }, [drawing, onAnnotation])

  // --- Remote control input (§18) ------------------------------------------
  //
  // Bound only while `controlling` is true. Nothing here is a permission check
  // — the server made the decision and the agent's own gate is what enforces
  // it — but a browser that keeps listening after a revocation is a browser
  // that will eventually send one event too many, so it stops listening.

  /**
   * A pointer event as a fraction of the **shared screen**.
   *
   * Measured against the `<video>`, not the surface: `object-fit: contain`
   * leaves letterbox bars that belong to neither, and a click in one is not a
   * click on the remote machine. {@link normalisePointer} returns null there,
   * and null is dropped rather than clamped — clamping would land the click at
   * an edge the person never aimed at.
   */
  const controlPoint = useCallback((event: { clientX: number; clientY: number }): ControlPoint | null => {
    const video = videoRef.current
    if (!video) return null

    return normalisePointer(event, video)
  }, [])

  const handleControlPointerMove = useCallback(
    (event: React.PointerEvent) => {
      const point = controlPoint(event)
      if (point) onControlPointer?.(point)
    },
    [controlPoint, onControlPointer],
  )

  const handleControlPointerDown = useCallback(
    (event: React.PointerEvent) => {
      const point = controlPoint(event)
      if (!point) return

      // Focus first: the keyboard handlers are on this element, so a click is
      // also how somebody starts typing on the remote machine.
      surfaceRef.current?.focus()
      event.currentTarget.setPointerCapture(event.pointerId)

      // The move is forced past the throttle, because a click at a stale
      // position is much worse than one a frame late.
      onControlPointer?.(point, true)
      onControlButton?.(event.button, true, point, event.detail >= 2)
    },
    [controlPoint, onControlPointer, onControlButton],
  )

  const handleControlPointerUp = useCallback(
    (event: React.PointerEvent) => {
      const point = controlPoint(event)

      onControlButton?.(event.button, false, point ?? undefined)
    },
    [controlPoint, onControlButton],
  )

  const handleControlKey = useCallback(
    (event: React.KeyboardEvent, pressed: boolean) => {
      // Tab would move focus out of the stage and Space would scroll the page,
      // and both are keys somebody controlling a computer means to send.
      if (onControlKey?.(event.nativeEvent, pressed)) event.preventDefault()
    },
    [onControlKey],
  )

  // Wheel is bound natively rather than through React, because React attaches
  // its own wheel listener passively — `preventDefault()` in a synthetic
  // handler is ignored, and the page would scroll underneath the person.
  useEffect(() => {
    const surface = surfaceRef.current
    if (!controlling || !surface || !onControlScroll) return

    const onWheel = (event: WheelEvent) => {
      event.preventDefault()
      onControlScroll(event.deltaX, event.deltaY, controlPoint(event) ?? undefined)
    }

    surface.addEventListener('wheel', onWheel, { passive: false })

    return () => surface.removeEventListener('wheel', onWheel)
  }, [controlling, onControlScroll, controlPoint])

  // The clipboard travels only when the host granted it — a separate decision
  // from control, and off unless it was ticked (§59).
  useEffect(() => {
    if (!controlling || !clipboardShared || !onControlClipboard) return

    const onPaste = (event: ClipboardEvent) => {
      if (!surfaceRef.current?.contains(document.activeElement)) return

      const text = event.clipboardData?.getData('text/plain')
      if (text) onControlClipboard(text)
    }

    window.addEventListener('paste', onPaste)

    return () => window.removeEventListener('paste', onPaste)
  }, [controlling, clipboardShared, onControlClipboard])

  const shapes = useMemo(
    () => (drawing ? [...(live?.annotations ?? []), drawing] : (live?.annotations ?? [])),
    [live?.annotations, drawing],
  )

  const sharingParticipant = session.participants.find((participant) => participant.isSharing)

  return (
    <div className="stage">
      {stream ? (
        <div
          ref={surfaceRef}
          className={[
            'stage__surface',
            annotationTool !== 'none' ? 'stage__surface--drawing' : '',
            controlling ? 'stage__surface--controlling' : '',
          ]
            .filter(Boolean)
            .join(' ')}
          // While controlling, the stage is a control surface and nothing else:
          // the annotation handlers are not bound, so a drag is a drag on the
          // remote machine rather than a line drawn over it.
          onPointerMove={controlling ? handleControlPointerMove : handlePointerMove}
          onPointerDown={controlling ? handleControlPointerDown : handlePointerDown}
          onPointerUp={controlling ? handleControlPointerUp : handlePointerUp}
          onPointerCancel={controlling ? handleControlPointerUp : handlePointerUp}
          onKeyDown={controlling ? (event) => handleControlKey(event, true) : undefined}
          onKeyUp={controlling ? (event) => handleControlKey(event, false) : undefined}
          onContextMenu={controlling ? (event) => event.preventDefault() : undefined}
          tabIndex={controlling ? 0 : undefined}
          role={controlling ? 'application' : undefined}
          aria-label={controlling ? 'Remote computer — your keyboard and mouse control it' : undefined}
        >
          <video
            ref={videoRef}
            className="stage__video"
            autoPlay
            playsInline
            // Muted for the local preview only: hearing your own shared audio
            // back is feedback, not a feature.
            muted={!isViewingRemote}
          />

          <AnnotationLayer shapes={shapes} />

          {Object.entries(live?.pointers ?? {}).map(([peerId, pointer]) => (
            <span
              key={peerId}
              className="stage__pointer"
              style={{
                left: `${pointer.x * 100}%`,
                top: `${pointer.y * 100}%`,
                color: pointer.colour,
              }}
              aria-hidden="true"
            >
              <MousePointer2 size={18} />
              <span className="stage__pointer-name" style={{ background: pointer.colour }}>
                {pointer.name}
              </span>
            </span>
          ))}
        </div>
      ) : (
        <div className="stage__placeholder">
          <MonitorOff size={30} aria-hidden="true" />
          <p className="stage__placeholder-title">
            {sharingParticipant
              ? `Waiting for ${sharingParticipant.displayName}’s screen`
              : 'No screen is being shared yet'}
          </p>
          <p className="stage__placeholder-body">
            {session.isHost
              ? 'Choose Share to pick what to show. Nothing is transmitted until you do.'
              : 'The host has not started sharing. Chat is available in the meantime.'}
          </p>
        </div>
      )}

      {isViewingRemote && sharingParticipant ? (
        <div className="stage__attribution">
          <ShieldCheck size={14} aria-hidden="true" />
          <span>
            <strong>{sharingParticipant.displayName}</strong> is sharing
            {session.sourceProductLabel ? ` · ${session.sourceProductLabel}` : ''}
            {session.companyName ? ` · ${session.companyName}` : ''}
          </span>
        </div>
      ) : null}
    </div>
  )
}

/**
 * The annotation canvas.
 *
 * SVG rather than `<canvas>`: shapes are few, they need to scale with the
 * container without redrawing, and each one stays inspectable in the DOM.
 */
function AnnotationLayer({ shapes }: { shapes: AnnotationShape[] }) {
  if (shapes.length === 0) return null

  return (
    <svg className="stage__annotations" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
      {shapes.map((shape) => (
        <AnnotationShapeElement key={shape.id} shape={shape} />
      ))}
    </svg>
  )
}

function AnnotationShapeElement({ shape }: { shape: AnnotationShape }) {
  const points = shape.points.map((point) => ({ x: point.x * 100, y: point.y * 100 }))
  if (points.length === 0) return null

  const [start] = points
  const end = points[points.length - 1]

  switch (shape.tool) {
    case 'pen':
      return (
        <polyline
          points={points.map((point) => `${point.x},${point.y}`).join(' ')}
          fill="none"
          stroke={shape.colour}
          strokeWidth={0.45}
          strokeLinecap="round"
          strokeLinejoin="round"
          vectorEffect="non-scaling-stroke"
        />
      )

    case 'arrow':
      return (
        <g stroke={shape.colour} strokeWidth={0.45} fill="none" vectorEffect="non-scaling-stroke">
          <line x1={start.x} y1={start.y} x2={end.x} y2={end.y} strokeLinecap="round" />
          {arrowHead(start, end, shape.colour)}
        </g>
      )

    case 'rectangle':
      return (
        <rect
          x={Math.min(start.x, end.x)}
          y={Math.min(start.y, end.y)}
          width={Math.abs(end.x - start.x)}
          height={Math.abs(end.y - start.y)}
          fill="none"
          stroke={shape.colour}
          strokeWidth={0.45}
          vectorEffect="non-scaling-stroke"
        />
      )

    case 'highlight':
      return (
        <rect
          x={Math.min(start.x, end.x)}
          y={Math.min(start.y, end.y)}
          width={Math.abs(end.x - start.x)}
          height={Math.abs(end.y - start.y)}
          fill={shape.colour}
          opacity={0.22}
        />
      )

    default:
      return null
  }
}

function arrowHead(
  start: { x: number; y: number },
  end: { x: number; y: number },
  colour: string,
): ReactElement | null {
  const dx = end.x - start.x
  const dy = end.y - start.y
  const length = Math.hypot(dx, dy)

  if (length < 0.5) return null

  const angle = Math.atan2(dy, dx)
  const size = 2.2

  const left = {
    x: end.x - size * Math.cos(angle - Math.PI / 7),
    y: end.y - size * Math.sin(angle - Math.PI / 7),
  }
  const right = {
    x: end.x - size * Math.cos(angle + Math.PI / 7),
    y: end.y - size * Math.sin(angle + Math.PI / 7),
  }

  return <polygon points={`${end.x},${end.y} ${left.x},${left.y} ${right.x},${right.y}`} fill={colour} stroke="none" />
}
