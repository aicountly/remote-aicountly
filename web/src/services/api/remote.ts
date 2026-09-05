/**
 * Every Remote API endpoint, as a typed function.
 *
 * Screens call these; nothing else builds a URL. Keeping the paths in one file
 * is what makes the API versioning in `apiFetch` (`/v1/remote`) a single edit
 * rather than a search-and-replace across the feature folders.
 */

import { apiFetch, apiFetchWithMeta, setGuestToken } from './client'
import type {
  AuditEntry,
  Bootstrap,
  ChatMessage,
  CompanyPolicy,
  ConnectionState,
  CreatedInvitation,
  EffectivePolicy,
  Entitlement,
  FileTransfer,
  Invitation,
  Participant,
  PermissionMatrixUser,
  RemoteSession,
  ScopeType,
  SessionDetail,
  SessionEvent,
  ShareMode,
  SignallingCredentials,
  SupportRequest,
  DeviceListing,
  RemoteDevice,
  SessionControlState,
} from '../../types/remote'

// --- Bootstrap and policy ---------------------------------------------------

export function fetchBootstrap(): Promise<Bootstrap> {
  // The one request that spends the launch-context token, because it is the
  // first thing the app does after arriving from another AICOUNTLY product.
  return apiFetch<Bootstrap>('/bootstrap', { withLaunchContext: true })
}

export function fetchEffectivePolicy(scopeType: ScopeType, companyId: number | null): Promise<EffectivePolicy> {
  const params = new URLSearchParams({ scopeType })
  if (companyId !== null) params.set('companyId', String(companyId))

  return apiFetch<EffectivePolicy>(`/policy/effective?${params.toString()}`)
}

// --- Sessions ---------------------------------------------------------------

export interface CreateSessionInput {
  scopeType: ScopeType
  companyId?: number | null
  branchId?: number | null
  financialYearId?: number | null
  sessionType?: 'ASSISTANCE' | 'SUPPORT' | 'INTERNAL'
  requestedShareMode?: ShareMode
  allowAudio?: boolean
  allowSystemAudio?: boolean
  issueSummary?: string | null
}

export function createSession(input: CreateSessionInput): Promise<SessionDetail> {
  return apiFetch<SessionDetail>('/sessions', {
    method: 'POST',
    body: input,
    withLaunchContext: true,
  })
}

export function fetchSession(uuid: string, signal?: AbortSignal): Promise<SessionDetail> {
  return apiFetch<SessionDetail>(`/sessions/${uuid}`, { signal })
}

/**
 * Ask the server whether this sharing mode is permitted, **before** the browser
 * picker opens (§16, §30).
 *
 * Doing it in this order means a user is never shown an operating-system dialog
 * for something their organisation was always going to refuse.
 */
export function declareShareIntent(uuid: string, shareMode: ShareMode): Promise<{
  approved: boolean
  shareMode: ShareMode
  allowedShareModes: ShareMode[]
  allowSystemAudio: boolean
}> {
  return apiFetch(`/sessions/${uuid}/share-intent`, { method: 'POST', body: { shareMode } })
}

/** Report the surface the user actually picked. The server enforces it again. */
export function reportShareStarted(uuid: string, displaySurface: string): Promise<SessionDetail> {
  return apiFetch<SessionDetail>(`/sessions/${uuid}/share-started`, {
    method: 'POST',
    body: { displaySurface },
  })
}

export function reportShareStopped(uuid: string, reason: string): Promise<SessionDetail> {
  return apiFetch<SessionDetail>(`/sessions/${uuid}/share-stopped`, { method: 'POST', body: { reason } })
}

/** A cooperating AICOUNTLY tab reported a different company (§12). */
export function reportContextMismatch(
  uuid: string,
  observedCompanyId: number | null,
  observedProduct: string | null,
): Promise<SessionDetail> {
  return apiFetch<SessionDetail>(`/sessions/${uuid}/context-mismatch`, {
    method: 'POST',
    body: { observedCompanyId, observedProduct },
  })
}

export function pauseSession(uuid: string): Promise<SessionDetail> {
  return apiFetch<SessionDetail>(`/sessions/${uuid}/pause`, { method: 'POST' })
}

export function resumeSession(uuid: string): Promise<SessionDetail> {
  return apiFetch<SessionDetail>(`/sessions/${uuid}/resume`, { method: 'POST' })
}

export function endSession(uuid: string, reason = 'ENDED_BY_USER'): Promise<SessionDetail> {
  return apiFetch<SessionDetail>(`/sessions/${uuid}/end`, { method: 'POST', body: { reason } })
}

export interface SessionHistoryFilters {
  scopeType?: ScopeType | ''
  companyId?: number | ''
  status?: string
  sessionType?: string
  sourceProduct?: string
  from?: string
  to?: string
  limit?: number
  offset?: number
}

export function fetchSessionHistory(
  filters: SessionHistoryFilters = {},
): Promise<{ data: RemoteSession[]; meta: Record<string, unknown> }> {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== null && value !== '') params.set(key, String(value))
  }

  return apiFetchWithMeta<RemoteSession[]>(`/sessions/history?${params.toString()}`)
}

export function fetchSessionEvents(uuid: string): Promise<SessionEvent[]> {
  return apiFetch<SessionEvent[]>(`/sessions/${uuid}/events`)
}

export function submitFeedback(
  uuid: string,
  resolution: 'YES' | 'PARTIALLY' | 'NO',
  comments?: string,
): Promise<{ recorded: boolean }> {
  return apiFetch(`/sessions/${uuid}/feedback`, { method: 'POST', body: { resolution, comments } })
}

// --- Participants -----------------------------------------------------------

export function requestJoin(uuid: string): Promise<{ session: RemoteSession; participant: Participant }> {
  return apiFetch(`/sessions/${uuid}/join-request`, { method: 'POST' })
}

export function approveParticipant(sessionUuid: string, participantUuid: string): Promise<Participant> {
  return apiFetch<Participant>(`/sessions/${sessionUuid}/participants/${participantUuid}/approve`, {
    method: 'POST',
  })
}

export function denyParticipant(sessionUuid: string, participantUuid: string): Promise<Participant> {
  return apiFetch<Participant>(`/sessions/${sessionUuid}/participants/${participantUuid}/deny`, {
    method: 'POST',
  })
}

export function markParticipantJoined(sessionUuid: string, participantUuid: string): Promise<Participant> {
  return apiFetch<Participant>(`/sessions/${sessionUuid}/participants/${participantUuid}/joined`, {
    method: 'POST',
  })
}

export function leaveSession(sessionUuid: string, participantUuid: string): Promise<void> {
  return apiFetch<void>(`/sessions/${sessionUuid}/participants/${participantUuid}/leave`, { method: 'POST' })
}

export function reportPresence(
  sessionUuid: string,
  participantUuid: string,
  connectionState: ConnectionState,
  microphoneEnabled?: boolean,
): Promise<Participant> {
  return apiFetch<Participant>(`/sessions/${sessionUuid}/participants/${participantUuid}/presence`, {
    method: 'POST',
    body: microphoneEnabled === undefined ? { connectionState } : { connectionState, microphoneEnabled },
  })
}

// --- Signalling -------------------------------------------------------------

export function fetchSignallingCredentials(sessionUuid: string): Promise<SignallingCredentials> {
  return apiFetch<SignallingCredentials>(`/sessions/${sessionUuid}/signalling-token`, { method: 'POST' })
}

// --- Chat -------------------------------------------------------------------

export function fetchMessages(sessionUuid: string, after?: string): Promise<ChatMessage[]> {
  const query = after ? `?after=${encodeURIComponent(after)}` : ''

  return apiFetch<ChatMessage[]>(`/sessions/${sessionUuid}/messages${query}`)
}

export function postMessage(
  sessionUuid: string,
  body: string,
  deliveredVia: 'DATA_CHANNEL' | 'RELAY' = 'RELAY',
): Promise<ChatMessage> {
  return apiFetch<ChatMessage>(`/sessions/${sessionUuid}/messages`, {
    method: 'POST',
    body: { body, deliveredVia },
  })
}

// --- File transfer (§36) ----------------------------------------------------
//
// None of these carry file content. They are the ledger: the offer, the
// recipient's decision, how far it got and how it ended. The bytes travel
// peer-to-peer over the data channel, which is why there is no upload here to
// find.

export function fetchTransfers(sessionUuid: string): Promise<FileTransfer[]> {
  return apiFetch<FileTransfer[]>(`/sessions/${sessionUuid}/transfers`)
}

export interface FileOfferInput {
  /** Optional only when there is exactly one other person in the session. */
  toParticipantUuid?: string | null
  fileName: string
  fileSize: number
  mimeType?: string | null
}

/**
 * Register the offer **before** any chunk goes on the wire.
 *
 * The server is what enforces the size ceiling, the organisation's file-transfer
 * switch and this user's permission to send, so a client that skipped its own
 * checks still gets no further than this call.
 */
export function offerFileTransfer(sessionUuid: string, input: FileOfferInput): Promise<FileTransfer> {
  return apiFetch<FileTransfer>(`/sessions/${sessionUuid}/transfers`, { method: 'POST', body: input })
}

/** The gate the sender waits on. Nothing is sent until this succeeds. */
export function acceptFileTransfer(sessionUuid: string, transferUuid: string): Promise<FileTransfer> {
  return apiFetch<FileTransfer>(`/sessions/${sessionUuid}/transfers/${transferUuid}/accept`, { method: 'POST' })
}

export function declineFileTransfer(sessionUuid: string, transferUuid: string): Promise<FileTransfer> {
  return apiFetch<FileTransfer>(`/sessions/${sessionUuid}/transfers/${transferUuid}/decline`, { method: 'POST' })
}

/** Throttled by the caller: the ledger shows movement, it does not mirror chunks. */
export function reportTransferProgress(
  sessionUuid: string,
  transferUuid: string,
  bytesTransferred: number,
): Promise<FileTransfer> {
  return apiFetch<FileTransfer>(`/sessions/${sessionUuid}/transfers/${transferUuid}/progress`, {
    method: 'POST',
    body: { bytesTransferred },
  })
}

/** Reported by the recipient — the only side that knows every byte arrived. */
export function completeFileTransfer(sessionUuid: string, transferUuid: string): Promise<FileTransfer> {
  return apiFetch<FileTransfer>(`/sessions/${sessionUuid}/transfers/${transferUuid}/complete`, { method: 'POST' })
}

export function abortFileTransfer(
  sessionUuid: string,
  transferUuid: string,
  status: 'CANCELLED' | 'FAILED' = 'CANCELLED',
  errorCode?: string | null,
): Promise<FileTransfer> {
  return apiFetch<FileTransfer>(`/sessions/${sessionUuid}/transfers/${transferUuid}/abort`, {
    method: 'POST',
    body: { status, errorCode },
  })
}

// --- Remote control (docs/DESKTOP_AGENT.md) ---------------------------------
//
// Every one of these is refused by the API unless the organisation permits
// remote control, this person holds the permission, and — for a grant — the
// caller is the host. The browser gates its buttons on the same answer so a
// disabled control is never a surprise, but the API is what enforces it.

export function fetchControlState(sessionUuid: string): Promise<SessionControlState> {
  return apiFetch<SessionControlState>(`/sessions/${sessionUuid}/control`)
}

/** Ask the person at the machine for control of it. */
export function requestControl(sessionUuid: string): Promise<{
  participant: Participant
  control: SessionControlState
}> {
  return apiFetch(`/sessions/${sessionUuid}/control/request`, { method: 'POST' })
}

/**
 * The host granting control.
 *
 * `allowClipboard` is separate on purpose: control and clipboard are different
 * exposures, and starting to control a machine must not silently start copying
 * whatever is on its clipboard.
 */
export function grantControl(
  sessionUuid: string,
  participantUuid: string,
  allowClipboard = false,
): Promise<{ participant: Participant; control: SessionControlState }> {
  return apiFetch(`/sessions/${sessionUuid}/control/grant`, {
    method: 'POST',
    body: { participantUuid, allowClipboard },
  })
}

export function denyControl(
  sessionUuid: string,
  participantUuid: string,
): Promise<{ participant: Participant; control: SessionControlState }> {
  return apiFetch(`/sessions/${sessionUuid}/control/deny`, {
    method: 'POST',
    body: { participantUuid },
  })
}

/**
 * Stop control. Either side, no permission required.
 *
 * The agent's own gate is what actually stops input reaching the machine, and
 * it does so locally and immediately; this is how the *server* and the other
 * participant find out.
 */
export function revokeControl(
  sessionUuid: string,
  participantUuid?: string,
): Promise<{ participant: Participant; control: SessionControlState }> {
  return apiFetch(`/sessions/${sessionUuid}/control/revoke`, {
    method: 'POST',
    body: participantUuid ? { participantUuid } : {},
  })
}

export function setClipboardSharing(
  sessionUuid: string,
  participantUuid: string,
  enabled: boolean,
): Promise<{ participant: Participant; control: SessionControlState }> {
  return apiFetch(`/sessions/${sessionUuid}/control/clipboard`, {
    method: 'POST',
    body: { participantUuid, enabled },
  })
}

// --- Devices (§52) ----------------------------------------------------------

export function fetchDevices(companyId: number): Promise<DeviceListing> {
  return apiFetch<DeviceListing>(`/devices?companyId=${companyId}`)
}

export function fetchDevice(uuid: string): Promise<{
  device: RemoteDevice
  effectiveCapabilities: Record<string, boolean>
  sessions: RemoteSession[]
}> {
  return apiFetch(`/devices/${uuid}`)
}

export function renameDevice(uuid: string, deviceName: string): Promise<{ device: RemoteDevice }> {
  return apiFetch(`/devices/${uuid}`, { method: 'PATCH', body: { deviceName } })
}

export function setDeviceStatus(
  uuid: string,
  status: 'ACTIVE' | 'SUSPENDED',
): Promise<{ device: RemoteDevice }> {
  return apiFetch(`/devices/${uuid}`, { method: 'PATCH', body: { status } })
}

/** Revoke a device. Server-side, immediate, and not undone by reinstalling. */
export function revokeDevice(uuid: string, reason?: string): Promise<{ device: RemoteDevice }> {
  return apiFetch(`/devices/${uuid}/revoke`, { method: 'POST', body: { reason } })
}

/**
 * Turn unattended access on for a device.
 *
 * `confirm` is not ceremony: the API refuses without it, because a request
 * without it is a request that skipped the screen carrying the warning.
 */
export function enableUnattendedAccess(uuid: string): Promise<{ device: RemoteDevice }> {
  return apiFetch(`/devices/${uuid}/unattended/enable`, {
    method: 'POST',
    body: { confirm: true },
  })
}

export function disableUnattendedAccess(uuid: string): Promise<{ device: RemoteDevice }> {
  return apiFetch(`/devices/${uuid}/unattended/disable`, { method: 'POST' })
}

/**
 * Connect to a device with nobody at it.
 *
 * Creates an ordinary Remote session — same policy snapshot, same expiry, same
 * participants, same audit trail — plus a `UNATTENDED_SESSION_STARTED` event
 * and a short-lived token the browser uses to tell the agent to join now
 * rather than waiting for its next poll.
 */
export function connectToDevice(
  uuid: string,
  issueSummary?: string,
): Promise<{
  session: SessionDetail
  device: RemoteDevice
  participant: Participant | null
  host: Participant | null
  deviceInvite: { token: string; url: string; room: string; expiresAt: string; sessionUuid: string }
}> {
  return apiFetch(`/devices/${uuid}/connect`, {
    method: 'POST',
    body: issueSummary ? { issueSummary } : {},
  })
}

/** Ask a device to restart. Separately authorised — see the API. */
export function rebootDevice(
  uuid: string,
  sessionUuid: string,
): Promise<{ accepted: boolean; command: { type: string; sessionUuid: string } }> {
  return apiFetch(`/devices/${uuid}/reboot`, { method: 'POST', body: { sessionUuid } })
}

// --- Invitations ------------------------------------------------------------

export function fetchInvitations(sessionUuid: string): Promise<Invitation[]> {
  return apiFetch<Invitation[]>(`/sessions/${sessionUuid}/invitations`)
}

export function createInvitation(
  sessionUuid: string,
  invitationType: 'INTERNAL' | 'EXTERNAL_GUEST' | 'SUPPORT',
  inviteeEmail?: string | null,
  expiryMinutes?: number,
): Promise<CreatedInvitation> {
  return apiFetch<CreatedInvitation>(`/sessions/${sessionUuid}/invitations`, {
    method: 'POST',
    body: { invitationType, inviteeEmail, expiryMinutes },
  })
}

export function revokeInvitation(sessionUuid: string, invitationUuid: string): Promise<void> {
  return apiFetch<void>(`/sessions/${sessionUuid}/invitations/${invitationUuid}`, { method: 'DELETE' })
}

// --- Joining ----------------------------------------------------------------

export function joinByCode(code: string): Promise<{ session: RemoteSession; participant: Participant }> {
  return apiFetch(`/join/code`, { method: 'POST', body: { code } })
}

/**
 * Redeem an invitation link.
 *
 * `anonymous` because an external guest has no AICOUNTLY account — that is the
 * purpose of a guest invitation. When the response carries a guest token it is
 * stored immediately, since every later call in the session needs it.
 */
export async function redeemInvitation(
  token: string,
  displayName?: string,
  email?: string,
): Promise<{ session: RemoteSession; participant: Participant; guestToken?: string }> {
  const result = await apiFetch<{ session: RemoteSession; participant: Participant; guestToken?: string }>(
    '/join/redeem',
    { method: 'POST', body: { token, displayName, email }, anonymous: true },
  )

  if (result.guestToken) {
    setGuestToken(result.guestToken)
  }

  return result
}

// --- AICOUNTLY Support ------------------------------------------------------

export interface SupportRequestInput {
  companyId?: number | null
  branchId?: number | null
  financialYearId?: number | null
  requestedShareMode?: ShareMode
  allowAudio?: boolean
  issueSummary?: string | null
  supportTicketId?: string | null
  priority?: 'LOW' | 'NORMAL' | 'HIGH' | 'URGENT'
}

export function createSupportRequest(
  input: SupportRequestInput,
): Promise<{ request: SupportRequest; session: RemoteSession }> {
  return apiFetch('/support/requests', { method: 'POST', body: input, withLaunchContext: true })
}

export function fetchSupportRequests(
  filters: { status?: string; mine?: boolean; limit?: number; offset?: number } = {},
): Promise<{ data: SupportRequest[]; meta: Record<string, unknown> }> {
  const params = new URLSearchParams()
  if (filters.status) params.set('status', filters.status)
  if (filters.mine) params.set('mine', '1')
  if (filters.limit) params.set('limit', String(filters.limit))
  if (filters.offset) params.set('offset', String(filters.offset))

  return apiFetchWithMeta<SupportRequest[]>(`/support/requests?${params.toString()}`)
}

export function acceptSupportRequest(
  uuid: string,
): Promise<{ request: SupportRequest; session: RemoteSession }> {
  return apiFetch(`/support/requests/${uuid}/accept`, { method: 'POST' })
}

export function declineSupportRequest(uuid: string, reason?: string): Promise<SupportRequest> {
  return apiFetch<SupportRequest>(`/support/requests/${uuid}/decline`, { method: 'POST', body: { reason } })
}

export function cancelSupportRequest(uuid: string): Promise<SupportRequest> {
  return apiFetch<SupportRequest>(`/support/requests/${uuid}/cancel`, { method: 'POST' })
}

// --- Company administration -------------------------------------------------

export function fetchCompanyPolicy(companyId: number): Promise<{
  policy: CompanyPolicy
  presets: string[]
  entitlement: Entitlement
  companyName: string | null
}> {
  return apiFetch(`/company/${companyId}/policy`)
}

export function updateCompanyPolicy(
  companyId: number,
  changes: Partial<CompanyPolicy> & { preset?: string },
): Promise<{ policy: CompanyPolicy }> {
  return apiFetch(`/company/${companyId}/policy`, { method: 'PUT', body: changes })
}

export function fetchPermissionMatrix(
  companyId: number,
  filters: { search?: string; limit?: number; offset?: number } = {},
): Promise<{
  data: { users: PermissionMatrixUser[]; catalog: Record<string, string[]> }
  meta: Record<string, unknown>
}> {
  const params = new URLSearchParams()
  if (filters.search) params.set('search', filters.search)
  if (filters.limit) params.set('limit', String(filters.limit))
  if (filters.offset) params.set('offset', String(filters.offset))

  return apiFetchWithMeta(`/company/${companyId}/permissions?${params.toString()}`)
}

export function updateUserPermissions(
  companyId: number,
  userUuid: string,
  permissions: Record<string, 'ALLOW' | 'DENY' | 'INHERIT'>,
): Promise<{ userUuid: string; overrides: Record<string, string>; permissions: Record<string, boolean> }> {
  return apiFetch(`/company/${companyId}/permissions/${userUuid}`, { method: 'PUT', body: { permissions } })
}

export function fetchRolePermissions(companyId: number): Promise<{
  roles: Record<string, Record<string, { effect: string; inheritedFromPlatform: boolean }>>
  catalog: Record<string, string[]>
}> {
  return apiFetch(`/company/${companyId}/role-permissions`)
}

export function updateRolePermissions(
  companyId: number,
  roleKey: string,
  permissions: Record<string, 'ALLOW' | 'DENY' | 'INHERIT'>,
): Promise<{ roleKey: string; overrides: Record<string, string> }> {
  return apiFetch(`/company/${companyId}/role-permissions/${roleKey}`, {
    method: 'PUT',
    body: { permissions },
  })
}

export function fetchAuditTrail(
  companyId: number,
  filters: { event?: string; from?: string; to?: string; sessionUuid?: string; limit?: number; offset?: number } = {},
): Promise<{ data: AuditEntry[]; meta: Record<string, unknown> }> {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== null && value !== '') params.set(key, String(value))
  }

  return apiFetchWithMeta<AuditEntry[]>(`/company/${companyId}/audit?${params.toString()}`)
}
