/**
 * The Remote API, as the desktop agent calls it.
 *
 * Registration used to need a portal **`ses_key`** for its one enrolment
 * call. It no longer does: device-code sign-in (`startDesktopSignIn`,
 * `pollDesktopSignIn`, docs/desktop/DEVICE_ENROLMENT.md) enrols the machine
 * itself, server-side, once a person confirms the code in their own browser
 * — so this window never handles a portal credential of any kind, matching
 * the **device credential** the Rust side obtains by proving possession of
 * the private key, which likewise never crosses into this window.
 *
 * `sessionKey` below is accordingly never set by anything any more, which
 * means `enableUnattended`, `disableUnattended` and the best-effort revoke in
 * `unregisterDevice`'s caller always refuse with `UNAUTHENTICATED` — a known
 * gap, not a bug introduced here: those three calls have always needed a
 * person's credential this window no longer has a way to obtain. Use the
 * AICOUNTLY Remote web console for them until they are moved onto the device
 * credential the way `POST /devices/me/unattended/disable` already is
 * designed to be.
 */

import type { DeviceResource, DesktopSignInOutcome, DesktopSignInStart, EnrolmentMaterial } from '../types/agent'

/** The portal session key. Memory only; never `localStorage`. Never set by anything any more — see above. */
const sessionKey: string | null = null

export class RemoteApiError extends Error {
  readonly code: string
  readonly status: number

  constructor(code: string, message: string, status: number) {
    super(message)
    this.name = 'RemoteApiError'
    this.code = code
    this.status = status
  }
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE'
  body?: unknown
  signal?: AbortSignal
  /** False for the two device-code calls, which carry their own proof instead of a credential. */
  auth?: boolean
}

async function request<T>(baseUrl: string, path: string, options: RequestOptions = {}): Promise<T> {
  const auth = options.auth ?? true

  if (auth && !sessionKey) {
    throw new RemoteApiError('UNAUTHENTICATED', 'Sign in to AICOUNTLY to continue.', 401)
  }

  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  }
  if (auth) headers.Authorization = `Bearer ${sessionKey}`

  let response: Response

  try {
    response = await fetch(`${baseUrl.replace(/\/$/, '')}/v1/remote${path}`, {
      method: options.method ?? 'GET',
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      signal: options.signal,
    })
  } catch (error) {
    throw new RemoteApiError(
      'NETWORK',
      'AICOUNTLY Remote could not be reached. Check this computer’s network connection.',
      0,
    )
  }

  const payload = (await response.json().catch(() => null)) as
    | { data?: T; error?: { code: string; message: string } }
    | null

  if (!response.ok) {
    throw new RemoteApiError(
      payload?.error?.code ?? 'UNEXPECTED',
      payload?.error?.message ?? 'AICOUNTLY Remote returned something unexpected.',
      response.status,
    )
  }

  return payload?.data as T
}

/**
 * Start a device-code sign-in. Unauthenticated: this window holds no
 * credential of any kind at this point, which is the entire reason this
 * exists rather than the machine somehow acquiring a portal session itself.
 */
export function startDesktopSignIn(baseUrl: string, material: EnrolmentMaterial): Promise<DesktopSignInStart> {
  return request<DesktopSignInStart>(baseUrl, '/desktop-signin/start', {
    method: 'POST',
    auth: false,
    body: {
      deviceName: material.hostName,
      publicKey: material.publicKey,
      deviceType: 'DESKTOP',
      operatingSystem: material.operatingSystem,
      osVersion: material.osVersion,
      architecture: material.architecture,
      hostname: material.hostName,
      agentVersion: material.agentVersion,
      capabilities: material.capabilities,
    },
  })
}

/**
 * Poll for an outcome. `deviceCode` is the proof — nobody who only saw the
 * short code this screen displays can call this, because they were never
 * given it.
 */
export function pollDesktopSignIn(baseUrl: string, deviceCode: string): Promise<DesktopSignInOutcome> {
  return request<DesktopSignInOutcome>(baseUrl, '/desktop-signin/poll', {
    method: 'POST',
    auth: false,
    body: { deviceCode },
  })
}

/**
 * Turn unattended access on.
 *
 * `confirm` is not ceremony: the API refuses without it, because a request
 * without it is a request that skipped the screen carrying the warning.
 */
export function enableUnattended(
  baseUrl: string,
  deviceUuid: string,
): Promise<{ device: DeviceResource }> {
  return request<{ device: DeviceResource }>(baseUrl, `/devices/${deviceUuid}/unattended/enable`, {
    method: 'POST',
    body: { confirm: true },
  })
}

export function disableUnattended(
  baseUrl: string,
  deviceUuid: string,
): Promise<{ device: DeviceResource }> {
  return request<{ device: DeviceResource }>(baseUrl, `/devices/${deviceUuid}/unattended/disable`, {
    method: 'POST',
  })
}

export function revokeDevice(baseUrl: string, deviceUuid: string): Promise<{ device: DeviceResource }> {
  return request<{ device: DeviceResource }>(baseUrl, `/devices/${deviceUuid}/revoke`, {
    method: 'POST',
    body: { reason: 'Unregistered from the desktop application' },
  })
}

/*
 * There is deliberately no control API in this window.
 *
 * A control decision is made by the *machine*, and the machine reports it with
 * its own device credential from the Rust side — see
 * `Agent::report_control_decision`. Reporting it from here would need a
 * portal `ses_key`, and this window does not have a way to obtain one at all
 * any more, so a grant made from here would be a call that could only ever
 * be refused.
 */
