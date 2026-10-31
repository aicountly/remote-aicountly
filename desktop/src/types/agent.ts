/**
 * The Rust side's shapes, as TypeScript.
 *
 * Hand-written and mirroring `remote-core::state` and `remote-device`, for the
 * same reason `web/src/types/remote.ts` is hand-written: the surface is small
 * and a hand-written type carries constraints a generator loses — `AgentStatus`
 * below is a discriminated union, so a screen that forgets a state is a compile
 * error rather than a blank panel.
 *
 * Every field here crosses the Tauri boundary from Rust. Nothing secret does:
 * there is no token, no key and no credential in any of these, because there
 * is no Rust command that returns one.
 */

export type ControlStateView = 'none' | 'requested' | 'granted' | 'denied' | 'revoked'

export interface ControlSummary {
  state: ControlStateView
  clipboard: boolean
  /** Who is asking, while `state` is `'requested'`. Cleared once answered. */
  requesterUuid: string | null
  /** Their name, as the consent dialog shows it. */
  requesterName: string | null
}

export interface SessionSummary {
  sessionUuid: string
  displayId: string
  /** Who is connected. A person, so the person at the machine knows who. */
  connectedName: string
  companyName: string | null
  startedAt: string
  /**
   * Whether they got here through unattended access.
   *
   * Shown in the indicator, because "somebody connected while you were away"
   * is materially different from "you let somebody in".
   */
  unattended: boolean
  control: ControlSummary
}

/**
 * Where a control companion is. Mirrors `remote_core::CompanionPhase`.
 *
 * The owner shares a screen from the browser and links this computer to that
 * share; somebody they grant control to in the browser then uses this
 * computer's mouse and keyboard.
 */
export type CompanionPhase =
  | 'awaiting_confirmation'
  | 'choosing_monitor'
  | 'ready'
  | 'controlled'
  | 'paused'

/** A monitor, as the companion's picker offers it. */
export interface MonitorOption {
  id: number
  name: string
  primary: boolean
  width: number
  height: number
  /** 200 on a Retina display. */
  scalePercent: number
}

export interface CompanionSummary {
  companionUuid: string
  sessionUuid: string
  /** `123-456`, as the browser shows it. */
  displayId: string
  companyName: string | null
  /** Who linked this computer — its owner, from their browser. */
  requestedByName: string | null
  expiresAt: string | null
  /** When it joined; `null` while it is still being asked. */
  startedAt: string | null
  phase: CompanionPhase
  /** What the picker offers, while the person here has to choose. */
  monitors: MonitorOption[]
  /** The monitor control lands on, once known. */
  monitor: MonitorOption | null
  controllerName: string | null
}

export type AgentStatus =
  | { status: 'not_enrolled' }
  | { status: 'authenticating'; attempt: number }
  | { status: 'offline'; reason: string; retryable: boolean }
  | { status: 'online' }
  | ({ status: 'in_session' } & SessionSummary)
  | ({ status: 'companion' } & CompanionSummary)
  | { status: 'revoked' }

export interface UnattendedState {
  enabled: boolean
  enabledAt: string | null
  lastUsedAt: string | null
  /**
   * Whether the organisation permits it at all — so the UI says "not available
   * for your organisation" rather than showing a switch that always refuses.
   */
  allowedByPolicy: boolean
}

export interface AgentState {
  status: AgentStatus
  deviceName: string | null
  deviceUuid: string | null
  companyName: string | null
  /** The public key fingerprint, to compare with what the console shows. */
  keyFingerprint: string | null
  unattended: UnattendedState
  /**
   * What the organisation currently allows, from the device's own row —
   * `null` until the first successful poll, never guessed at in the meantime.
   */
  allowedCapabilities: AgentCapabilities | null
  agentVersion: string
  recentSessions: SessionSummary[]
}

export type PermissionState =
  | 'ready'
  | 'needs_attention'
  | 'denied'
  | 'not_applicable'
  | 'unsupported'

export interface PermissionSummary {
  screen_capture: PermissionState
  input_injection: PermissionState
  clipboard: PermissionState
  background_service: PermissionState
  power: PermissionState
}

export interface AgentCapabilities {
  screen_share: boolean
  screen_view: boolean
  remote_control: boolean
  unattended_access: boolean
  file_transfer: boolean
  clipboard_sync: boolean
  reboot: boolean
}

export interface AgentConfig {
  apiBaseUrl: string
  portalUrl: string
  presenceIntervalSeconds: number
  runInBackground: boolean
  startAtLogin: boolean
  captureQuality: 'adaptive' | 'low_bandwidth' | 'high_quality'
}

export interface ServiceStatus {
  running: boolean
  version: string | null
  enrolled: boolean
  deviceUuid: string | null
  keyFingerprint: string | null
  unattendedEnabled: boolean
  detail: string | null
}

export interface About {
  product: string
  version: string
  /** Which build of the version (`yyyyMMddHHmm`), as in the installer's name; null for a local build. */
  build: string | null
  platform: string
  supported: boolean
  /** Whether this build can share its own screen and be controlled (Windows). */
  supportsHosting: boolean
  /** Whether it can be its owner's control companion for a browser share. */
  supportsCompanion: boolean
  /** The system-wide Stop control shortcut, as this platform writes it. */
  stopShortcut: string
  /** Where the device key is kept. The store's name, never anything in it. */
  keyStorage: string
  /**
   * Where this process writes its log — the path, never the contents.
   *
   * A release build has no console (`windows_subsystem = "windows"`), so the
   * file is the only place the log exists and the only thing a support
   * engineer can be asked for.
   */
  logFile: string | null
  service: ServiceStatus
  /** AICOUNTLY's privacy policy, opened in the browser from the About panel. */
  privacyPolicyUrl: string
}

/** Everything the enrolment call needs. The **public** key, and nothing else. */
export interface EnrolmentMaterial {
  publicKey: string
  hostName: string
  operatingSystem: string
  osVersion: string
  architecture: string
  agentVersion: string
  capabilities: AgentCapabilities
}

/**
 * What the window reports back once the server has accepted this machine's
 * enrolment — everything `confirm_enrolment` needs to bring the running agent
 * into line with what `POST /devices/enrol` already did.
 */
export interface EnrolmentConfirmation {
  deviceUuid: string
  /** Null for a personal device — the person's own computer, the ordinary case. */
  companyId: number | null
  companyName: string | null
  deviceName: string
}

/**
 * A newer AICOUNTLY Remote, published for this computer
 * (`remote_core::update`). Absent when this is the latest version, and when
 * the check could not be made at all.
 */
export interface UpdateInfo {
  currentVersion: string
  /** Which build is installed; null for a local build. */
  currentBuild: string | null
  latestVersion: string
  /** Which build is published, when the manifest says. */
  latestBuild: string | null
  /** The installer for this OS and architecture; null when there is none to fetch automatically. */
  installer: { file: string; url: string; size: number; sha256: string } | null
  /** The download page, for installing by hand. */
  downloadPageUrl: string
}

/** What happens once the installer has been opened. */
export interface UpdateStarted {
  version: string
  platform: 'windows' | 'macos'
  /** This application closes after this long, so the installer can replace it. */
  closesInSeconds: number
}

/** A registered device, as the API renders it. */
export interface DeviceResource {
  uuid: string
  deviceName: string
  status: 'PENDING' | 'ACTIVE' | 'SUSPENDED' | 'REVOKED'
  companyId: number | null
  keyFingerprint: string | null
  unattendedAccessEnabled: boolean
  unattendedEnabledAt: string | null
  unattendedLastUsedAt: string | null
  capabilities: AgentCapabilities
}

/**
 * What starting a device-code sign-in returns (docs/desktop/DEVICE_ENROLMENT.md).
 *
 * `userCode` is shown on this screen for a person to match against the
 * confirmation page. `deviceCode` is not shown anywhere — it is the secret
 * this window polls with, and answering the poll is what proves this is the
 * process that started the sign-in.
 */
export interface DesktopSignInStart {
  userCode: string
  deviceCode: string
  verificationUri: string
  verificationUriComplete: string
  expiresAt: string
  intervalSeconds: number
}

/** What one poll of a device-code sign-in reports. */
export type DesktopSignInOutcome =
  | { status: 'pending' }
  | { status: 'denied' }
  | { status: 'expired' }
  | { status: 'confirmed'; device: DeviceResource; companyName: string | null }

/** Whether a session is running, derived from the status rather than stored. */
export function activeSession(state: AgentState): SessionSummary | null {
  return state.status.status === 'in_session' ? state.status : null
}

export function isEnrolled(state: AgentState): boolean {
  return state.status.status !== 'not_enrolled'
}

export function isOnline(state: AgentState): boolean {
  return (
    state.status.status === 'online' ||
    state.status.status === 'in_session' ||
    state.status.status === 'companion'
  )
}

/** The control companion, joined or still being asked. */
export function activeCompanion(state: AgentState): CompanionSummary | null {
  return state.status.status === 'companion' ? state.status : null
}

export function isBeingControlled(state: AgentState): boolean {
  const phase = activeCompanion(state)?.phase

  return activeSession(state)?.control.state === 'granted' || phase === 'controlled' || phase === 'paused'
}

export function isUsable(permission: PermissionState): boolean {
  return permission === 'ready' || permission === 'not_applicable'
}

// ------------------------------------------------- your computers, history

/** One of the signed-in person's computers (`GET /devices/me/computers`). */
export interface OwnComputer {
  uuid: string
  deviceName: string
  operatingSystem: string
  osVersion: string | null
  status: string
  online: boolean
  lastSeenAt: string | null
  enrolledAt: string | null
  unattendedAccessEnabled: boolean
  isThisComputer: boolean
}

export interface OwnComputers {
  computers: OwnComputer[]
  count: number
}

/** A session this computer took part in, newest first. */
export interface HistorySession {
  uuid: string
  displayId: string
  accessMode: string
  role: 'HOST' | 'COMPANION' | string
  status: string
  startedAt: string | null
  endedAt: string | null
  durationSeconds: number | null
  peers: string[]
  controlled: boolean
}

/** A change to this computer — registered, signed out, unattended on or off. */
export interface HistoryEvent {
  event: string
  at: string | null
  summary: string
  actorName: string | null
}

export interface DeviceHistory {
  sessions: HistorySession[]
  events: HistoryEvent[]
}

// ------------------------------------------------------------ help sessions

/** A help session this computer hosts ("give your code"). */
export interface HelpSession {
  uuid: string
  displayId: string
  sessionCode: string | null
  status: string
}

/** Somebody waiting for, or in, a help session this computer hosts. */
export interface HostedParticipant {
  uuid: string
  displayName: string
  role: string
  status: string
  requestedAt: string | null
  joinedAt: string | null
}

export interface HostedParticipants {
  waiting: HostedParticipant[]
  joined: HostedParticipant[]
}
