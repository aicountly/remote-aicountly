import { useEffect, useState } from 'react'

import * as bridge from '../../services/tauri'
import type { CodecView } from '../../types/agent'

/**
 * Cisco's OpenH264 codec on this computer, kept current.
 *
 * Read once, then followed through the Rust side's events, which carry every
 * change — a download's progress included — so nothing here polls. `null`
 * until the first answer, and for as long as there is none: a window that
 * cannot read the codec's state says nothing about it rather than guessing.
 */
export function useCodec(): [CodecView | null, (next: CodecView) => void] {
  const [codec, setCodec] = useState<CodecView | null>(null)

  useEffect(() => {
    let cancelled = false
    let dispose: (() => void) | undefined

    void bridge.codecStatus().then(
      (view) => {
        if (!cancelled) setCodec(view)
      },
      () => undefined,
    )

    void bridge
      .onCodecChange((view) => {
        if (!cancelled) setCodec(view)
      })
      .then(
        (stop) => {
          if (cancelled) stop()
          else dispose = stop
        },
        () => undefined,
      )

    return () => {
      cancelled = true
      dispose?.()
    }
  }, [])

  return [codec, setCodec]
}
