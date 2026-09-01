/**
 * Formatting (§97).
 *
 * Timestamps arrive as ISO-8601 UTC and are rendered in the viewer's own
 * timezone by `Intl` — never by string surgery, and never with a hardcoded
 * offset. The default locale is `en-IN` because that is AICOUNTLY's, but the
 * browser's own locale wins when it has one.
 */

const LOCALE = typeof navigator !== 'undefined' && navigator.language ? navigator.language : 'en-IN'

/** `31 Aug 2026, 2:42 PM` */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'

  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return '—'

  return new Intl.DateTimeFormat(LOCALE, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(date)
}

/** `31 Aug 2026` */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'

  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return '—'

  return new Intl.DateTimeFormat(LOCALE, { day: '2-digit', month: 'short', year: 'numeric' }).format(date)
}

export function formatTime(iso: string | null | undefined): string {
  if (!iso) return '—'

  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return '—'

  return new Intl.DateTimeFormat(LOCALE, { hour: 'numeric', minute: '2-digit' }).format(date)
}

/** `18 min 22 sec` — the shape §97 asks for. */
export function formatDuration(seconds: number | null | undefined): string {
  if (seconds === null || seconds === undefined || Number.isNaN(seconds)) return '—'
  if (seconds < 60) return `${Math.max(0, Math.round(seconds))} sec`

  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  const remaining = Math.round(seconds % 60)

  if (hours > 0) {
    return minutes > 0 ? `${hours} hr ${minutes} min` : `${hours} hr`
  }

  return remaining > 0 ? `${minutes} min ${remaining} sec` : `${minutes} min`
}

/** `00:18:32` — the running clock in the session header. */
export function formatClock(seconds: number): string {
  const safe = Math.max(0, Math.floor(seconds))
  const hours = String(Math.floor(safe / 3600)).padStart(2, '0')
  const minutes = String(Math.floor((safe % 3600) / 60)).padStart(2, '0')
  const secs = String(safe % 60).padStart(2, '0')

  return `${hours}:${minutes}:${secs}`
}

/** `2 minutes ago`, `in 8 minutes` — for queue waits and expiry countdowns. */
export function formatRelative(iso: string | null | undefined): string {
  if (!iso) return '—'

  const target = new Date(iso).getTime()
  if (Number.isNaN(target)) return '—'

  const deltaSeconds = Math.round((target - Date.now()) / 1000)
  const absolute = Math.abs(deltaSeconds)

  const formatter = new Intl.RelativeTimeFormat(LOCALE, { numeric: 'auto' })

  if (absolute < 60) return formatter.format(deltaSeconds, 'second')
  if (absolute < 3600) return formatter.format(Math.round(deltaSeconds / 60), 'minute')
  if (absolute < 86_400) return formatter.format(Math.round(deltaSeconds / 3600), 'hour')

  return formatter.format(Math.round(deltaSeconds / 86_400), 'day')
}

/** How long a queued item has been waiting, as `mm:ss`. */
export function formatWaiting(iso: string | null | undefined): string {
  if (!iso) return '00:00'

  const started = new Date(iso).getTime()
  if (Number.isNaN(started)) return '00:00'

  const seconds = Math.max(0, Math.floor((Date.now() - started) / 1000))
  const minutes = String(Math.floor(seconds / 60)).padStart(2, '0')

  return `${minutes}:${String(seconds % 60).padStart(2, '0')}`
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

/** The scope, in words rather than an enum value. */
export function describeScope(scopeType: string, companyName: string | null): string {
  switch (scopeType) {
    case 'PERSONAL':
      return 'Personal'
    case 'AICOUNTLY_SUPPORT':
      return companyName ? `AICOUNTLY Support · ${companyName}` : 'AICOUNTLY Support'
    default:
      return companyName ?? 'Company'
  }
}

const SHARE_MODE_LABELS: Record<string, string> = {
  SAFE_SHARE: 'AICOUNTLY Safe Share',
  BROWSER_TAB: 'Browser tab',
  APPLICATION_WINDOW: 'Application window',
  ENTIRE_MONITOR: 'Entire screen',
}

export function describeShareMode(mode: string | null | undefined): string {
  if (!mode) return '—'

  return SHARE_MODE_LABELS[mode] ?? mode
}

const SURFACE_LABELS: Record<string, string> = {
  browser: 'Browser tab',
  window: 'Application window',
  monitor: 'Entire screen',
  unknown: 'Not reported by this browser',
}

export function describeSurface(surface: string | null | undefined): string {
  if (!surface) return '—'

  return SURFACE_LABELS[surface] ?? surface
}

/**
 * Event names as sentences (§60, §42).
 *
 * An audit trail an administrator can read is worth more than one that is
 * faithful to the enum, so every event the product records has wording here.
 */
const EVENT_LABELS: Record<string, string> = {
  SESSION_CREATED: 'Session created',
  INVITATION_CREATED: 'Invitation created',
  INVITATION_REVOKED: 'Invitation withdrawn',
  INVITATION_REDEEMED: 'Invitation used',
  PARTICIPANT_JOIN_REQUESTED: 'Asked to join',
  PARTICIPANT_APPROVED: 'Admitted to the session',
  PARTICIPANT_DENIED: 'Declined',
  PARTICIPANT_JOINED: 'Joined',
  PARTICIPANT_LEFT: 'Left',
  SCREEN_SHARE_REQUESTED: 'Asked to share a screen',
  SCREEN_SHARE_PERMISSION_GRANTED: 'Screen sharing allowed by the browser',
  SCREEN_SHARE_PERMISSION_DENIED: 'Screen sharing refused by the browser',
  SCREEN_SHARE_STARTED: 'Screen sharing started',
  SCREEN_SHARE_STOPPED: 'Screen sharing stopped',
  SURFACE_BROWSER_SELECTED: 'Shared a browser tab',
  SURFACE_WINDOW_SELECTED: 'Shared an application window',
  SURFACE_MONITOR_SELECTED: 'Shared an entire screen',
  POLICY_REJECTED: 'Blocked by organisation policy',
  MICROPHONE_STARTED: 'Microphone on',
  MICROPHONE_STOPPED: 'Microphone off',
  CHAT_STARTED: 'Chat started',
  FILE_TRANSFER_STARTED: 'File transfer started',
  FILE_TRANSFER_COMPLETED: 'File transfer completed',
  FILE_TRANSFER_FAILED: 'File transfer failed',
  CONNECTION_INTERRUPTED: 'Connection interrupted',
  CONNECTION_RESTORED: 'Connection restored',
  SESSION_PAUSED: 'Session paused',
  SESSION_RESUMED: 'Session resumed',
  SESSION_ENDED: 'Session ended',
  SESSION_EXPIRED: 'Session expired',
  RECORDING_STARTED: 'Recording started',
  RECORDING_STOPPED: 'Recording stopped',
  COMPANY_CONTEXT_MISMATCH: 'Organisation mismatch detected',
  SUPPORT_REQUESTED: 'AICOUNTLY Support requested',
  SUPPORT_ACCEPTED: 'AICOUNTLY Support accepted',
  SUPPORT_DECLINED: 'AICOUNTLY Support declined',
  SUPPORT_CANCELLED: 'Support request cancelled',
  POLICY_UPDATED: 'Remote policy changed',
  PERMISSION_UPDATED: 'Remote permissions changed',
  SIGNALLING_TOKEN_ISSUED: 'Secure connection authorised',
}

export function describeEvent(eventType: string): string {
  return EVENT_LABELS[eventType] ?? eventType.replace(/_/g, ' ').toLowerCase()
}

/** Permission names as sentences, for the administration matrix. */
export function describePermission(permission: string): string {
  const labels: Record<string, string> = {
    'remote.access': 'Use AICOUNTLY Remote',
    'remote.session.create': 'Start a session',
    'remote.session.join': 'Join a session',
    'remote.session.end': 'End a session',
    'remote.screen.share': 'Share a screen',
    'remote.screen.view': 'View a shared screen',
    'remote.safe_share': 'AICOUNTLY Safe Share',
    'remote.browser_tab.share': 'Share a browser tab',
    'remote.window.share': 'Share an application window',
    'remote.monitor.share': 'Share an entire screen',
    'remote.microphone.share': 'Use a microphone',
    'remote.system_audio.share': 'Share system audio',
    'remote.chat.use': 'Use chat',
    'remote.annotation.use': 'Use annotations',
    'remote.file.send': 'Send files',
    'remote.file.receive': 'Receive files',
    'remote.support.request': 'Request AICOUNTLY Support',
    'remote.support.accept': 'Answer AICOUNTLY Support requests',
    'remote.external.invite': 'Invite external guests',
    'remote.recording.start': 'Start a recording',
    'remote.recording.view': 'View recordings',
    'remote.session.history.own': 'See own session history',
    'remote.session.history.company': 'See organisation session history',
    'remote.audit.view': 'View the audit trail',
    'remote.policy.view': 'View Remote policy',
    'remote.policy.manage': 'Manage Remote policy',
  }

  return labels[permission] ?? permission
}
