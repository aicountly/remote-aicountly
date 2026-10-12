/**
 * AICOUNTLY Connect in Remote — the widget's lifecycle.
 *
 *   startConnect()   after sign-in, outside a live session: load loader.js, then
 *                    init() with the person's ses_key callback, product and scope
 *   updateConnect()  when the Remote scope (Personal / an organisation) changes
 *   stopConnect()    on sign-out, on leaving the shell and on entering a live
 *                    session: destroy() — widget, element, call engine and
 *                    session callback gone
 *
 * The contract is connect-aicountly docs/EMBED_SDK.md (v1); AICOUNTLY Sales and
 * Books are the reference consumers this mirrors. A Connect that is offline,
 * blocked or slow leaves every Remote page exactly as it was: the status is
 * 'unavailable' and there is simply no launcher.
 */

import type { CompanySummary, ScopeType } from '../types/remote'
import { loadConnect } from './loader'
import type { ConnectApi, ConnectInitOptions, ConnectScopePatch, ConnectUpdate } from './types'

export const CONNECT_PRODUCT = 'remote'

/** 'off' before sign-in and after sign-out; 'unavailable' when Connect could not be loaded or mounted. */
export type ConnectStatus = 'off' | 'loading' | 'ready' | 'unavailable'

/** How long the widget's init() may take before Remote treats Connect as unavailable. */
export const INIT_TIMEOUT_MS = 15_000

let status: ConnectStatus = 'off'
let api: ConnectApi | null = null
let generation = 0
/** What init() will be given — patches that arrive while loader.js loads are folded in. */
let options: ConnectInitOptions | null = null

export function getConnectStatus(): ConnectStatus {
  return status
}

async function start(mine: number, origin: string | null, timeoutMs: number | undefined): Promise<boolean> {
  if (!origin) {
    status = 'unavailable'
    return false
  }
  status = 'loading'

  const loaded = await loadConnect(origin, timeoutMs)
  if (mine !== generation) return false
  if (!loaded) {
    status = 'unavailable'
    return false
  }

  api = loaded
  let mounted = false
  let timer: ReturnType<typeof setTimeout> | undefined
  let timedOut = false
  try {
    const gaveUp = new Promise<false>((resolve) => {
      timer = setTimeout(() => {
        timedOut = true
        resolve(false)
      }, INIT_TIMEOUT_MS)
    })
    const initialising = Promise.resolve(loaded.init({ ...(options as ConnectInitOptions) }))
    // An init() that lands after Remote gave up would mount a widget nothing holds
    // a handle to (stopConnect() could not take it down): take it down at once.
    initialising.then(
      () => {
        if (!timedOut) return
        try {
          loaded.destroy()
        } catch {
          /* the widget's problem, never Remote's */
        }
      },
      () => undefined,
    )
    mounted = (await Promise.race([initialising, gaveUp])) === true
  } catch {
    mounted = false
  } finally {
    if (timer !== undefined) clearTimeout(timer)
  }
  if (mine !== generation) return false
  status = mounted ? 'ready' : 'unavailable'
  if (!mounted) api = null
  return mounted
}

/**
 * Load Connect and mount the widget. Resolves true once it is up, false when it
 * could not be (or when stopConnect() came first). Never rejects.
 */
export function startConnect(origin: string | null, initial: ConnectInitOptions, timeoutMs?: number): Promise<boolean> {
  const mine = ++generation
  options = { ...initial }
  return start(mine, origin, timeoutMs)
}

/** Pass a change (the scope, above all) to the widget. Only keys present change. */
export function updateConnect(patch: ConnectUpdate): void {
  if (options) options = { ...options, ...patch }
  if (!api || (status !== 'loading' && status !== 'ready')) return
  try {
    api.update(patch)
  } catch {
    /* the widget's problem, never Remote's */
  }
}

/** Unmount the widget and forget the session callback. Safe to call at any time. */
export function stopConnect(): void {
  generation += 1
  const current = api
  api = null
  options = null
  status = 'off'
  try {
    current?.destroy()
  } catch {
    /* already gone */
  }
}

// ---------------------------------------------------------------------------
// Scope
// ---------------------------------------------------------------------------

function positiveId(value: unknown): number | null {
  return typeof value === 'number' && Number.isInteger(value) && value > 0 ? value : null
}

function branchId(value: unknown): number | null {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0 ? value : null
}

/**
 * Remote's scope as Connect takes it.
 *
 * Remote's organisation comes from the bootstrap's `activeScope` (and the
 * person's later choice in the header), not from cmp/fy/bo query parameters.
 * Only a COMPANY scope carries a company; its financial year and branch are
 * passed only when Remote knows them (the company's entry in the bootstrap).
 * Personal (and AICOUNTLY Support) scope has no company: Pulse then answers
 * without one.
 *
 * Keys that are unknown are null here: init() leaves them out
 * ({@link initialScope}), update() sends them so a previous company's year and
 * branch are cleared rather than carried over.
 */
export function connectScopeFrom(
  scopeType: ScopeType,
  companyId: number | null,
  companies: readonly CompanySummary[] = [],
): { companyId: number | null; fyId: number | null; boId: number | null } {
  const company = scopeType === 'COMPANY' ? positiveId(companyId) : null
  if (company === null) return { companyId: null, fyId: null, boId: null }

  const entry = companies.find((candidate) => candidate.companyId === company)
  return {
    companyId: company,
    fyId: positiveId(entry?.financialYearId),
    boId: branchId(entry?.branchId),
  }
}

/** The scope for init(): only the keys Remote actually knows. */
export function initialScope(scope: ReturnType<typeof connectScopeFrom>): ConnectScopePatch {
  const out: ConnectScopePatch = {}
  if (scope.companyId !== null) {
    out.companyId = scope.companyId
    if (scope.fyId !== null) out.fyId = scope.fyId
    if (scope.boId !== null) out.boId = scope.boId
  }
  return out
}

/** The scope for update(): every key, so a company switch clears what it does not replace. */
export function scopeUpdate(scope: ReturnType<typeof connectScopeFrom>): ConnectScopePatch {
  return { companyId: scope.companyId, fyId: scope.fyId, boId: scope.boId ?? 0 }
}
