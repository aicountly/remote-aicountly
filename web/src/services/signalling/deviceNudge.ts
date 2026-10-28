/**
 * Tell a desktop app to look now (docs/desktop/CONTROL_COMPANION.md).
 *
 * After linking a control companion, the API hands back a short-lived token
 * for that device's presence room. This opens it, says `device-invite` once,
 * and closes. The invite authorises nothing: the app re-reads its pending
 * requests from the API (`GET /devices/me`) and asks the person at it before
 * doing anything. If the nudge never arrives, the app's own poll finds the
 * request anyway — so this is best effort and never throws.
 */

import { signallingProtocols } from './protocol'

export interface DeviceInvite {
  token: string
  url: string
  sessionUuid: string
  companionUuid?: string
}

export function nudgeDevice(invite: DeviceInvite, timeoutMs = 5000): Promise<boolean> {
  return new Promise((resolve) => {
    if (typeof WebSocket === 'undefined' || !invite.url || !invite.token) {
      resolve(false)

      return
    }

    let settled = false
    let socket: WebSocket | null = null

    const finish = (delivered: boolean) => {
      if (settled) return
      settled = true
      clearTimeout(timer)
      try {
        socket?.close()
      } catch {
        /* already closed */
      }
      resolve(delivered)
    }

    const timer = setTimeout(() => finish(false), timeoutMs)

    try {
      socket = new WebSocket(invite.url, signallingProtocols(invite.token))
    } catch {
      finish(false)

      return
    }

    socket.onmessage = (event) => {
      let message: { type?: string } | null = null
      try {
        message = JSON.parse(String(event.data)) as { type?: string }
      } catch {
        return
      }

      if (message?.type !== 'joined') return

      socket?.send(
        JSON.stringify({
          type: 'device-invite',
          payload: { sessionUuid: invite.sessionUuid, companionUuid: invite.companionUuid ?? null },
        }),
      )
      finish(true)
    }

    socket.onerror = () => finish(false)
    socket.onclose = () => finish(false)
  })
}
