/**
 * The API contract, as TypeScript.
 *
 * These types mirror `App\Domain\Support\Presenter` on the backend. They are
 * hand-written rather than generated because the surface is small and stable,
 * and because a hand-written type can carry the constraint a generator loses —
 * `ShareMode` and `DisplaySurface` below are unions, not strings, so a typo in
 * a policy check is a compile error rather than a silently-false condition.
 */

export type ScopeType = 'PERSONAL' | 'COMPANY' | 'AICOUNTLY_SUPPORT';

export type SessionType = 'ASSISTANCE' | 'SUPPORT' | 'INTERNAL' | 'GUEST_VIEW';

export type SessionStatus =
  | 'CREATED'
  | 'WAITING'
  | 'JOIN_REQUESTED'
  | 'CONNECTING'
  | 'ACTIVE'
  | 'PAUSED'
  | 'RECONNECTING'
  | 'ENDED'
  | 'DECLINED'
  | 'EXPIRED'
  | 'FAILED';

export type ShareMode = 'SAFE_SHARE' | 'BROWSER_TAB' | 'APPLICATION_WINDOW' | 'ENTIRE_MONITOR';

/** What `MediaTrackSettings.displaySurface` reports, plus our own "not told". */
export type DisplaySurface = 'browser' | 'window' | 'monitor' | 'unknown';

export type ParticipantRole = 'SHARER' | 'VIEWER' | 'SUPPORT_TECHNICIAN' | 'OBSERVER' | 'GUEST';

export type ParticipantStatus = 'REQUESTED' | 'APPROVED' | 'DENIED' | 'JOINED' | 'LEFT' | 'REMOVED';

export type ConnectionState = 'IDLE' | 'CONNECTING' | 'CONNECTED' | 'INTERRUPTED' | 'CLOSED';

export type ClientType = 'BROWSER' | 'DESKTOP_AGENT' | 'MOBILE';

/**
 * Capability negotiation (§51).
 *
 * The UI is built from this, never from `clientType === 'BROWSER'`. When a
 * desktop agent arrives it reports `remoteControl: true` and the same session
 * screen grows the control it was always able to render.
 */
export interface ParticipantCapabilities {
  screen_share?: boolean;
  screen_view?: boolean;
  remote_control?: boolean;
  unattended_access?: boolean;
  file_transfer?: boolean;
  clipboard_sync?: boolean;
  reboot?: boolean;
}

export interface Participant {
  uuid: string;
  displayName: string;
  role: ParticipantRole;
  clientType: ClientType;
  status: ParticipantStatus;
  isHost: boolean;
  isSharing: boolean;
  microphoneEnabled: boolean;
  connectionState: ConnectionState;
  capabilities: ParticipantCapabilities;
  requestedAt: string | null;
  joinedAt: string | null;
  leftAt: string | null;
  audit?: { ip: string | null; userAgent: string | null; email: string | null };
}

export interface SessionCapabilities {
  audio: boolean;
  systemAudio: boolean;
  chat: boolean;
  annotation: boolean;
  fileTransfer: boolean;
  recording: boolean;
  externalGuest: boolean;
}

export interface RemoteSession {
  uuid: string;
  displayId: string;
  /** Grouped for reading aloud — `583 194 726`. Null once the session ends. */
  sessionCode: string | null;
  scopeType: ScopeType;
  companyId: number | null;
  companyName: string | null;
  branchId: number | null;
  financialYearId: number | null;
  sessionType: SessionType;
  status: SessionStatus;
  requestedShareMode: ShareMode;
  actualDisplaySurface: DisplaySurface | null;
  sourceProduct: string | null;
  sourceProductLabel: string | null;
  sourceRoute: string | null;
  supportTicketId: string | null;
  issueSummary: string | null;
  ownerName: string | null;
  capabilities: SessionCapabilities;
  maxDurationMinutes: number;
  startedAt: string | null;
  endedAt: string | null;
  expiresAt: string | null;
  createdAt: string | null;
  durationSeconds: number | null;
  endReason: string | null;
}

/** What `GET /sessions/{uuid}` adds on top of the row. */
export interface SessionDetail extends RemoteSession {
  participants: Participant[];
  isHost: boolean;
  me: Participant | null;
  waiting?: Participant[];
  invitations?: Invitation[];
  audit?: { createdIp: string | null; createdUserAgent: string | null };
}

export interface Invitation {
  uuid: string;
  invitationType: 'INTERNAL' | 'EXTERNAL_GUEST' | 'SUPPORT';
  inviteeEmail: string | null;
  usedCount: number;
  maxUses: number;
  redeemedAt: string | null;
  revokedAt: string | null;
  expiresAt: string | null;
  createdAt: string | null;
}

/** The one and only time the secret link exists. It is never returned again. */
export interface CreatedInvitation {
  invitation: Invitation;
  url: string;
}

export type PermissionName = string;

/**
 * The resolved answer for one user in one scope. Produced entirely by the
 * backend (§9); the frontend only reads it.
 */
export interface EffectivePolicy {
  remoteEnabled: boolean;
  scopeType: ScopeType;
  companyId: number | null;
  companyName: string | null;
  policyPreset: 'RESTRICTED' | 'SAFE' | 'STANDARD' | 'OPEN' | 'CUSTOM';
  allowSafeShare: boolean;
  allowBrowserTab: boolean;
  allowApplicationWindow: boolean;
  allowEntireMonitor: boolean;
  allowMicrophone: boolean;
  allowSystemAudio: boolean;
  allowTextChat: boolean;
  allowAnnotation: boolean;
  allowFileTransfer: boolean;
  allowExternalGuest: boolean;
  allowInternalSessions: boolean;
  allowAicountlySupport: boolean;
  allowRecording: boolean;
  recordingRequiresConsent: boolean;
  maxSessionDurationMinutes: number;
  guestLinkExpiryMinutes: number;
  allowedShareModes: ShareMode[];
  permissions: Record<PermissionName, boolean>;
  restrictions: string[];
}

export interface CompanySummary {
  companyId: number;
  name: string;
  isCompanyAdmin: boolean;
  roleKey: string;
  branchId: number | null;
  financialYearId: number | null;
}

export interface LaunchContext {
  companyId: number | null;
  branchId: number | null;
  financialYearId: number | null;
  product: string;
  route: string | null;
  supportTicketId: string | null;
  sourceAgent: string | null;
  issueSummary: string | null;
}

export interface DashboardMetrics {
  activeSessions: number;
  sessionsThisMonth: number;
  averageDurationSeconds: number | null;
  pendingSupportRequests: number;
}

export interface FeatureFlags {
  fileTransfer: boolean;
  recording: boolean;
  externalGuest: boolean;
  safeShare: boolean;
  microphone: boolean;
  multiViewer: boolean;
  fileTransferMaxBytes: number;
}

export interface Bootstrap {
  user: {
    uuid: string;
    displayName: string;
    email: string | null;
    isSupportAgent: boolean;
  };
  companies: CompanySummary[];
  activeScope: { scopeType: ScopeType; companyId: number | null };
  launchContext: LaunchContext | null;
  policy: EffectivePolicy;
  metrics: DashboardMetrics;
  recentSessions: RemoteSession[];
  features: FeatureFlags;
  realtime: { signallingConfigured: boolean; relayAvailable: boolean };
}

export interface SessionEvent {
  eventType: string;
  actorType: 'USER' | 'GUEST' | 'SUPPORT' | 'SYSTEM';
  actorName: string | null;
  metadata: Record<string, unknown>;
  occurredAt: string | null;
}

export interface AuditEntry {
  uuid: string;
  event: string;
  actorType: string;
  actorName: string | null;
  companyId: number | null;
  sessionUuid: string | null;
  sourceProduct: string | null;
  ip: string | null;
  userAgent: string | null;
  metadata: Record<string, unknown>;
  createdAt: string | null;
}

export interface ChatMessage {
  uuid: string;
  authorName: string;
  messageType: 'USER' | 'SYSTEM';
  body: string;
  createdAt: string | null;
}

export type SupportRequestStatus =
  | 'PENDING'
  | 'ACCEPTED'
  | 'DECLINED'
  | 'CANCELLED'
  | 'EXPIRED'
  | 'COMPLETED';

export interface SupportRequest {
  uuid: string;
  status: SupportRequestStatus;
  priority: 'LOW' | 'NORMAL' | 'HIGH' | 'URGENT';
  requesterName: string;
  companyId: number | null;
  companyName: string | null;
  sourceProduct: string | null;
  sourceProductLabel: string | null;
  sourceRoute: string | null;
  supportTicketId: string | null;
  issueSummary: string | null;
  requestedShareMode: ShareMode;
  sessionUuid: string | null;
  sessionDisplayId: string | null;
  sessionStatus: SessionStatus | null;
  acceptedAt: string | null;
  expiresAt: string | null;
  createdAt: string | null;
}

export interface CompanyPolicy {
  companyId: number;
  policyPreset: EffectivePolicy['policyPreset'];
  remoteEnabled: boolean;
  allowSafeShare: boolean;
  allowBrowserTab: boolean;
  allowApplicationWindow: boolean;
  allowEntireMonitor: boolean;
  allowMicrophone: boolean;
  allowSystemAudio: boolean;
  allowTextChat: boolean;
  allowAnnotation: boolean;
  allowFileTransfer: boolean;
  allowExternalGuest: boolean;
  allowInternalSessions: boolean;
  allowAicountlySupport: boolean;
  allowRecording: boolean;
  recordingRequiresConsent: boolean;
  maxSessionDurationMinutes: number;
  guestLinkExpiryMinutes: number;
  updatedAt: string | null;
}

export interface Entitlement {
  planCode: string;
  maxMonthlySessions: number | null;
  maxSessionDurationMinutes: number | null;
  externalGuests: boolean;
  recording: boolean;
  fileTransfer: boolean;
  advancedAudit: boolean;
  desktopDevices: boolean;
  unattendedAccess: boolean;
}

export interface PermissionMatrixUser {
  userUuid: string;
  displayName: string;
  email: string | null;
  roleKey: string;
  isCompanyAdmin: boolean;
  permissions: Record<PermissionName, boolean>;
  overrides: Record<PermissionName, 'ALLOW' | 'DENY'>;
}

export interface SignallingCredentials {
  token: string;
  url: string;
  room: string;
  expiresAt: string;
  participantUuid: string;
  role: ParticipantRole;
  iceServers: RTCIceServer[];
  /** False when no TURN is configured — the UI says so instead of hanging. */
  relayAvailable: boolean;
}

/** The permission names the frontend actually gates on. */
export const PERMISSIONS = {
  ACCESS: 'remote.access',
  SESSION_CREATE: 'remote.session.create',
  SESSION_JOIN: 'remote.session.join',
  SESSION_END: 'remote.session.end',
  SCREEN_SHARE: 'remote.screen.share',
  SCREEN_VIEW: 'remote.screen.view',
  SAFE_SHARE: 'remote.safe_share',
  BROWSER_TAB_SHARE: 'remote.browser_tab.share',
  WINDOW_SHARE: 'remote.window.share',
  MONITOR_SHARE: 'remote.monitor.share',
  MICROPHONE_SHARE: 'remote.microphone.share',
  SYSTEM_AUDIO_SHARE: 'remote.system_audio.share',
  CHAT_USE: 'remote.chat.use',
  ANNOTATION_USE: 'remote.annotation.use',
  FILE_SEND: 'remote.file.send',
  FILE_RECEIVE: 'remote.file.receive',
  SUPPORT_REQUEST: 'remote.support.request',
  SUPPORT_ACCEPT: 'remote.support.accept',
  EXTERNAL_INVITE: 'remote.external.invite',
  RECORDING_START: 'remote.recording.start',
  RECORDING_VIEW: 'remote.recording.view',
  SESSION_HISTORY_OWN: 'remote.session.history.own',
  SESSION_HISTORY_COMPANY: 'remote.session.history.company',
  AUDIT_VIEW: 'remote.audit.view',
  POLICY_VIEW: 'remote.policy.view',
  POLICY_MANAGE: 'remote.policy.manage',
} as const;

export type PermissionKey = (typeof PERMISSIONS)[keyof typeof PERMISSIONS];
