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

export type AgentStatus =
  | { status: 'not_enrolled' }
  | { status: 'authenticating'; attempt: number }
  | { status: 'offline'; reason: string; retryable: boolean }
  | { status: 'online' }
  | ({ status: 'in_session' } & SessionSummary)
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
  platform: string
  supported: boolean
  /** Where the device key is kept. The store's name, never anything in it. */
  keyStorage: string
  service: ServiceStatus
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

/** An organisation this person may register a device into. */
export interface CompanyOption {
  companyId: number
  name: string
  canEnrol: boolean
}

/** Whether a session is running, derived from the status rather than stored. */
export function activeSession(state: AgentState): SessionSummary | null {
  return state.status.status === 'in_session' ? state.status : null
}

export function isEnrolled(state: AgentState): boolean {
  return state.status.status !== 'not_enrolled'
}

export function isOnline(state: AgentState): boolean {
  return state.status.status === 'online' || state.status.status === 'in_session'
}

export function isBeingControlled(state: AgentState): boolean {
  return activeSession(state)?.control.state === 'granted'
}

export function isUsable(permission: PermissionState): boolean {
  return permission === 'ready' || permission === 'not_applicable'
}
