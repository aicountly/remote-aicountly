import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('../auth/portal', async () => {
  const actual = await vi.importActual<typeof import('../auth/portal')>('../auth/portal')
  return { ...actual, ensureSesKey: vi.fn(async () => 'ses-plan') }
})

import { RemoteApiError, apiFetch, apiFetchWithMeta } from './api/client'
import { PLAN_LIMIT_CODE, planLimitFromError, planLimitMessage } from './planLimit'

/** 402 PLAN_LIMIT_REACHED (CONTRACTS §3.6): the web names the limit and who can change it. */
const refusal = {
  error: {
    code: 'PLAN_LIMIT_REACHED',
    message: 'Your plan allows 1 registered computer — ask the account owner to upgrade in Manage.',
    details: { code: 'remote.max_devices', limit: 1, used: 1 },
  },
}

describe('plan limit', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('names the session and device limits', () => {
    expect(planLimitMessage({ code: 'remote.max_sessions_per_month', limit: 20, used: 20 })).toBe(
      'Your plan allows 20 sessions a month — ask the account owner to upgrade in Manage.',
    )
    expect(planLimitMessage({ code: 'remote.max_devices', limit: 1, used: 1 })).toBe(
      'Your plan allows 1 registered computer — ask the account owner to upgrade in Manage.',
    )
    expect(planLimitMessage(null)).toMatch(/ask the account owner to upgrade in Manage/)
    expect(planLimitFromError(403, 'SESSION_QUOTA_REACHED', {})).toBeNull()
    expect(planLimitFromError(402, 'REQUEST_FAILED', null)).toBeNull()
    expect(planLimitFromError(402, PLAN_LIMIT_CODE, { code: 'remote.max_devices', limit: '2' })).toEqual({ code: 'remote.max_devices', limit: 2, used: null })
  })

  it.each([
    ['apiFetch', apiFetch],
    ['apiFetchWithMeta', apiFetchWithMeta],
  ])('%s throws PLAN_LIMIT_REACHED with the details and the sentence', async (_name, call) => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(refusal), { status: 402, headers: { 'Content-Type': 'application/json' } })))
    const error = (await (call as (p: string) => Promise<unknown>)('/devices').catch((e: unknown) => e)) as RemoteApiError
    expect(error).toBeInstanceOf(RemoteApiError)
    expect(error.code).toBe('PLAN_LIMIT_REACHED')
    expect(error.status).toBe(402)
    expect(error.details).toMatchObject({ code: 'remote.max_devices', limit: 1, used: 1 })
    expect(error.message).toBe('Your plan allows 1 registered computer — ask the account owner to upgrade in Manage.')
  })
})
