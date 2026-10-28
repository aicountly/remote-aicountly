import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'

import { fetchBootstrap, fetchEffectivePolicy } from '../services/api/remote'
import { RemoteApiError, setLaunchContextToken } from '../services/api/client'
import { getCapabilities } from '../services/browser/capabilities'
import type { RemoteBrowserCapabilities } from '../services/browser/capabilities'
import type { Bootstrap, EffectivePolicy, ScopeType } from '../types/remote'
import { noteCompanyLifecycle, setActiveCompanyForRestrictions } from '../services/companyRestriction'

/**
 * Application-wide Remote state: who you are, which organisation you are acting
 * in, and what that combination permits.
 *
 * The policy here is the *only* thing screens gate on, and it always comes from
 * the server. Switching organisation refetches it rather than deriving it
 * locally, because the whole point of §77 is that the answer differs per
 * company and must not be guessed from what the last one allowed.
 */

interface RemoteContextValue {
  status: 'loading' | 'ready' | 'error'
  error: RemoteApiError | null
  bootstrap: Bootstrap | null
  /** The effective policy for the currently selected scope. */
  policy: EffectivePolicy | null
  scopeType: ScopeType
  companyId: number | null
  capabilities: RemoteBrowserCapabilities
  switchScope: (scopeType: ScopeType, companyId: number | null) => Promise<void>
  refresh: () => Promise<void>
  can: (permission: string) => boolean
}

const RemoteContext = createContext<RemoteContextValue | null>(null)

/** Where the selected organisation survives a reload. */
const SCOPE_STORAGE_KEY = 'remote:scope'

interface StoredScope {
  scopeType: ScopeType
  companyId: number | null
}

function readStoredScope(): StoredScope | null {
  try {
    const raw = localStorage.getItem(SCOPE_STORAGE_KEY)
    if (!raw) return null

    const parsed = JSON.parse(raw) as StoredScope

    return parsed.scopeType ? parsed : null
  } catch {
    return null
  }
}

function writeStoredScope(scope: StoredScope): void {
  try {
    localStorage.setItem(SCOPE_STORAGE_KEY, JSON.stringify(scope))
  } catch {
    /* private mode — the selection simply does not persist */
  }
}

/**
 * Take the signed launch context out of the URL before anything else runs.
 *
 * It is removed from the address bar immediately: it is single-use, and leaving
 * it in history invites a replay that the backend would reject anyway but which
 * would look, to the user, like a broken link.
 */
function captureLaunchContextFromUrl(): void {
  const params = new URLSearchParams(window.location.search)
  const token = params.get('context') ?? params.get('remote_context')

  if (!token) return

  setLaunchContextToken(token)

  params.delete('context')
  params.delete('remote_context')

  const query = params.toString()
  window.history.replaceState(
    null,
    '',
    `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`,
  )
}

export function RemoteProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading')
  const [error, setError] = useState<RemoteApiError | null>(null)
  const [bootstrap, setBootstrap] = useState<Bootstrap | null>(null)
  const [policy, setPolicy] = useState<EffectivePolicy | null>(null)
  const [scopeType, setScopeType] = useState<ScopeType>('PERSONAL')
  const [companyId, setCompanyId] = useState<number | null>(null)

  const capabilities = useMemo(() => getCapabilities(), [])

  // StrictMode runs effects twice in development. Bootstrapping twice would
  // spend the single-use launch context on the first pass and fail the second.
  const booted = useRef(false)

  const load = useCallback(async () => {
    setStatus('loading')
    setError(null)

    try {
      const data = await fetchBootstrap()
      setBootstrap(data)

      // A verified launch context decides the scope — a company-scoped workflow
      // must not open in Personal (§13). Otherwise the last choice is restored,
      // and only if the user still has access to that company.
      const stored = readStoredScope()
      const hasStoredCompany =
        stored?.companyId != null && data.companies.some((c) => c.companyId === stored.companyId)

      const next: StoredScope = data.launchContext?.companyId
        ? { scopeType: data.activeScope.scopeType, companyId: data.activeScope.companyId }
        : hasStoredCompany
          ? { scopeType: stored!.scopeType, companyId: stored!.companyId }
          : { scopeType: 'PERSONAL', companyId: null }

      setScopeType(next.scopeType)
      setCompanyId(next.companyId)
      setActiveCompanyForRestrictions(next.companyId)
      // Manage's company lifecycle as Remote enforces it: the read-only banner.
      if (data.activeScope.companyId != null) {
        noteCompanyLifecycle(data.activeScope.companyId, (data.activeScope as { lifecycle?: unknown }).lifecycle)
      }

      // bootstrap already carries the policy for the scope it chose; refetch
      // only when we restored a different one.
      if (next.scopeType === data.activeScope.scopeType && next.companyId === data.activeScope.companyId) {
        setPolicy(data.policy)
      } else {
        const restored = await fetchEffectivePolicy(next.scopeType, next.companyId)
        if (next.companyId != null) noteCompanyLifecycle(next.companyId, (restored as { lifecycle?: unknown }).lifecycle)
        setPolicy(restored)
      }

      setStatus('ready')
    } catch (err) {
      setError(
        err instanceof RemoteApiError
          ? err
          : new RemoteApiError('UNKNOWN', 'AICOUNTLY Remote could not be loaded.', 0),
      )
      setStatus('error')
    }
  }, [])

  useEffect(() => {
    if (booted.current) return
    booted.current = true

    captureLaunchContextFromUrl()
    void load()
  }, [load])

  const switchScope = useCallback(async (nextScope: ScopeType, nextCompanyId: number | null) => {
    const resolvedCompanyId = nextScope === 'PERSONAL' ? null : nextCompanyId

    setScopeType(nextScope)
    setCompanyId(resolvedCompanyId)
    writeStoredScope({ scopeType: nextScope, companyId: resolvedCompanyId })
    setActiveCompanyForRestrictions(resolvedCompanyId)

    try {
      const next = await fetchEffectivePolicy(nextScope, resolvedCompanyId)
      if (resolvedCompanyId != null) noteCompanyLifecycle(resolvedCompanyId, (next as { lifecycle?: unknown }).lifecycle)
      setPolicy(next)
    } catch (err) {
      // A policy we could not fetch must not leave the previous organisation's
      // policy on screen — that is exactly the cross-tenant confusion §77
      // exists to prevent.
      setPolicy(null)
      setError(err instanceof RemoteApiError ? err : null)
    }
  }, [])

  const can = useCallback(
    (permission: string) => policy?.permissions?.[permission] === true,
    [policy],
  )

  const value = useMemo<RemoteContextValue>(
    () => ({
      status,
      error,
      bootstrap,
      policy,
      scopeType,
      companyId,
      capabilities,
      switchScope,
      refresh: load,
      can,
    }),
    [status, error, bootstrap, policy, scopeType, companyId, capabilities, switchScope, load, can],
  )

  return <RemoteContext.Provider value={value}>{children}</RemoteContext.Provider>
}

/**
 * What an external guest's screens read instead of {@link RemoteProvider}.
 *
 * A guest has no AICOUNTLY account, so there is no bootstrap to fetch and no
 * organisation to choose (§23) — but the session room still reads this
 * context, and without a provider it threw, so every guest invitation ended on
 * an error page. Nothing is granted here: no policy, no bootstrap, and `can()`
 * is always false. The room gates a guest on the session's own capabilities
 * (`isGuest || can(…)`), and the server holds them to exactly that.
 */
export function GuestRemoteProvider({ children }: { children: ReactNode }) {
  const capabilities = useMemo(() => getCapabilities(), [])

  const value = useMemo<RemoteContextValue>(
    () => ({
      status: 'ready',
      error: null,
      bootstrap: null,
      policy: null,
      scopeType: 'PERSONAL',
      companyId: null,
      capabilities,
      switchScope: async () => undefined,
      refresh: async () => undefined,
      can: () => false,
    }),
    [capabilities],
  )

  return <RemoteContext.Provider value={value}>{children}</RemoteContext.Provider>
}

export function useRemote(): RemoteContextValue {
  const context = useContext(RemoteContext)
  if (!context) throw new Error('useRemote must be used inside <RemoteProvider>')

  return context
}

/** The company the user is currently acting in, or null in Personal scope. */
export function useActiveCompany() {
  const { bootstrap, companyId } = useRemote()

  return useMemo(
    () => bootstrap?.companies.find((company) => company.companyId === companyId) ?? null,
    [bootstrap, companyId],
  )
}
