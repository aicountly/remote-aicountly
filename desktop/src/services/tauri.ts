/**
 * The bridge to the Rust side.
 *
 * Every `invoke` in the application goes through this file, so the command
 * names exist in one place and a rename is one edit rather than a search.
 *
 * There is deliberately no generic `call(name, args)` here: a generic bridge
 * is a generic bridge, and the whole point of the command list in
 * `src-tauri/src/commands` is that it is a short, named, reviewable set.
 */

import { invoke } from '@tauri-apps/api/core'
import { listen } from '@tauri-apps/api/event'

import type {
  About,
  AgentConfig,
  AgentState,
  DesktopSignInOutcome,
  DesktopSignInStart,
  EnrolmentConfirmation,
  EnrolmentMaterial,
  PermissionSummary,
} from '../types/agent'

export function getState(): Promise<AgentState> {
  return invoke<AgentState>('get_state')
}

export function getPermissions(): Promise<PermissionSummary> {
  return invoke<PermissionSummary>('get_permissions')
}

export function getConfiguration(): Promise<AgentConfig> {
  return invoke<AgentConfig>('get_configuration')
}

export function saveConfiguration(config: AgentConfig): Promise<AgentConfig> {
  return invoke<AgentConfig>('save_configuration', { config })
}

/**
 * Generate this machine's device keypair.
 *
 * What comes back is the **public** half plus the machine's description. The
 * private half went straight into the operating system's key store inside the
 * Rust process and there is no command that returns it.
 */
export function createDeviceKey(): Promise<EnrolmentMaterial> {
  return invoke<EnrolmentMaterial>('enrol_device')
}

/**
 * Tell the Rust side the server accepted this machine's enrolment.
 *
 * Without this call the window keeps showing "Not registered" and the
 * connection loop never attempts to authenticate — even though the server
 * call just before this one succeeded and the key is already in secure
 * storage. See `confirm_enrolment` on the Rust side.
 */
export function confirmEnrolment(confirmation: EnrolmentConfirmation): Promise<AgentState> {
  return invoke<AgentState>('confirm_enrolment', { confirmation })
}

/**
 * Start a device-code sign-in, and get back the code to show.
 *
 * Through the Rust side rather than `fetch`, because this window is a web
 * view: a JSON request to another origin sends a preflight first, and the API
 * answers a preflight from an unconfigured origin with no CORS headers at all.
 * The browser blocked it and the window reported a network problem on a
 * machine whose network was fine. `reqwest` has no origin to be refused.
 */
export function startDesktopSignIn(material: EnrolmentMaterial): Promise<DesktopSignInStart> {
  return invoke<DesktopSignInStart>('start_desktop_sign_in', { material })
}

/** Ask what became of it. */
export function pollDesktopSignIn(deviceCode: string): Promise<DesktopSignInOutcome> {
  return invoke<DesktopSignInOutcome>('poll_desktop_sign_in', { deviceCode })
}

export function unregisterDevice(): Promise<AgentState> {
  return invoke<AgentState>('unregister_device')
}

export function recordUnattendedEnabled(enabledAt: string | null): Promise<AgentState> {
  return invoke<AgentState>('enable_unattended', { enabledAt })
}

/**
 * Switch unattended access off — on the server, with the machine's own device
 * credential, and only then here. Rejects if the server refused, because a
 * window that says unattended access is off while the machine is still
 * reachable is the one outcome this switch exists to prevent.
 */
export function disableUnattended(): Promise<AgentState> {
  return invoke<AgentState>('disable_unattended')
}

export function grantControl(participantUuid: string, clipboard: boolean): Promise<AgentState> {
  return invoke<AgentState>('grant_control', { participantUuid, clipboard })
}

export function denyControl(): Promise<AgentState> {
  return invoke<AgentState>('deny_control')
}

/**
 * **Stop control.**
 *
 * Local and immediate: the Rust side's gate stops admitting input on the next
 * message, before this promise resolves and whatever the network is doing. The
 * API is told afterwards, and if that call fails the machine is still not being
 * controlled.
 */
export function stopControl(): Promise<AgentState> {
  return invoke<AgentState>('stop_control')
}

/** Yes: let people in this session control this computer while its owner shares it. */
export function allowCompanion(companionUuid: string): Promise<AgentState> {
  return invoke<AgentState>('allow_companion', { companionUuid })
}

export function declineCompanion(companionUuid: string): Promise<AgentState> {
  return invoke<AgentState>('decline_companion', { companionUuid })
}

/** The person picked which monitor they are sharing from the browser. */
export function chooseCompanionMonitor(companionUuid: string, monitorId: number): Promise<AgentState> {
  return invoke<AgentState>('choose_companion_monitor', { companionUuid, monitorId })
}

/** Stop being this session's control companion. Control stops at once. */
export function leaveCompanion(): Promise<AgentState> {
  return invoke<AgentState>('leave_companion')
}

/** Put each monitor's name on the monitor itself for a few seconds. */
export function identifyMonitors(): Promise<void> {
  return invoke<void>('identify_monitors')
}

/** Ask the operating system to prompt for a permission (macOS Accessibility). */
export function requestPermission(permission: 'input_injection'): Promise<PermissionSummary> {
  return invoke<PermissionSummary>('request_permission', { permission })
}

export function endSession(): Promise<AgentState> {
  return invoke<AgentState>('end_session')
}

/** Open a URL in the person's own browser. Allowlisted on the Rust side. */
export function openUrl(url: string): Promise<void> {
  return invoke<void>('open_url', { url })
}

export function about(): Promise<About> {
  return invoke<About>('about')
}

/** The Rust side pushing a new state — a tray action, a session starting. */
export function onStateChange(handler: (state: AgentState) => void): Promise<() => void> {
  return listen<AgentState>('aicountly-remote://state', (event) => handler(event.payload))
}

/** The tray asking the window to show a particular screen. */
export function onNavigate(handler: (screen: string) => void): Promise<() => void> {
  return listen<string>('aicountly-remote://navigate', (event) => handler(event.payload))
}

/** Whether this window is running inside Tauri at all. */
export function isDesktop(): boolean {
  return typeof window !== 'undefined' && '__TAURI_INTERNALS__' in window
}
