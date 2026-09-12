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

export function unregisterDevice(): Promise<AgentState> {
  return invoke<AgentState>('unregister_device')
}

export function recordUnattendedEnabled(enabledAt: string | null): Promise<AgentState> {
  return invoke<AgentState>('enable_unattended', { enabledAt })
}

export function recordUnattendedDisabled(): Promise<AgentState> {
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
