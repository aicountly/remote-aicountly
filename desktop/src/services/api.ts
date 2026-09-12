/**
 * The Remote API, as the desktop agent calls it.
 *
 * Two credentials, and they are never confused:
 *
 *   * a portal **`ses_key`**, held in memory only while a person is signing in
 *     to register this machine, and used for exactly one call — enrolment;
 *   * a **device credential**, which the Rust side obtains by proving
 *     possession of the private key and which never comes into this window at
 *     all.
 *
 * Neither is ever written to disk. The `ses_key` lives in a module variable
 * and dies with the window, exactly as it does in `web/src/auth/tokens.ts`.
 */

import type { CompanyOption, DeviceResource, EnrolmentMaterial } from '../types/agent'

/** The portal session key. Memory only; never `localStorage`. */
let sessionKey: string | null = null

export function setSessionKey(key: string | null): void {
  sessionKey = key
}

export function hasSessionKey(): boolean {
  return sessionKey !== null
}

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
}

async function request<T>(baseUrl: string, path: string, options: RequestOptions = {}): Promise<T> {
  if (!sessionKey) {
    throw new RemoteApiError('UNAUTHENTICATED', 'Sign in to AICOUNTLY to continue.', 401)
  }

  let response: Response

  try {
    response = await fetch(`${baseUrl.replace(/\/$/, '')}/v1/remote${path}`, {
      method: options.method ?? 'GET',
      headers: {
        Authorization: `Bearer ${sessionKey}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
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

/** The organisations this person may register a device into. */
export async function fetchCompanies(baseUrl: string): Promise<CompanyOption[]> {
  const bootstrap = await request<{
    companies: Array<{ companyId: number; name: string }>
  }>(baseUrl, '/bootstrap')

  // Whether each one permits enrolment is a per-company answer, so it is asked
  // per company rather than inferred from the bootstrap's active scope.
  const options = await Promise.all(
    bootstrap.companies.map(async (company) => {
      try {
        const devices = await request<{ canEnrol: boolean }>(
          baseUrl,
          `/devices?companyId=${company.companyId}&limit=1`,
        )

        return { ...company, canEnrol: devices.canEnrol }
      } catch {
        // A company whose policy could not be read is one this person cannot
        // register into as far as we know, which is the safe reading.
        return { ...company, canEnrol: false }
      }
    }),
  )

  return options
}

/**
 * Register this machine.
 *
 * The body carries the **public** key the Rust side generated. The private
 * half never leaves the machine and never enters this window.
 */
export function enrolDevice(
  baseUrl: string,
  companyId: number,
  deviceName: string,
  material: EnrolmentMaterial,
): Promise<{ device: DeviceResource }> {
  return request<{ device: DeviceResource }>(baseUrl, '/devices/enrol', {
    method: 'POST',
    body: {
      companyId,
      deviceName,
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
 * `Agent::report_control_decision`. Reporting it from here would need the
 * portal `ses_key`, which exists only for the few seconds somebody is
 * registering this computer, so a grant made an hour later would be a call
 * that could only ever be refused.
 */
