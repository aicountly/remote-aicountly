/**
 * The plan-limit refusal (CONTRACTS §3.6). A write the plan does not allow answers
 *
 *   402 {"error":{"code":"PLAN_LIMIT_REACHED","details":{"code":"remote.max_devices","limit":50,"used":50}}}
 *
 * and the web says "Your plan allows 50 registered computers — ask the account owner to upgrade in Manage."
 * Plans are changed by the account owner in Manage (Plans & billing), never here. Pure: no imports.
 */

export const PLAN_LIMIT_CODE = 'PLAN_LIMIT_REACHED'

export interface PlanLimitDetails {
  code: string
  limit: number
  used: number | null
}

/** What each of this app's plan codes counts (singular, plural). */
const NOUNS: Record<string, [string, string]> = {
  'remote.max_sessions_per_month': ['session a month', 'sessions a month'],
  'remote.max_devices': ['registered computer', 'registered computers'],
}

function wholeNumber(v: unknown): number | null {
  if (typeof v === 'number' && Number.isInteger(v) && v >= 0) return v
  if (typeof v === 'string' && /^\d{1,9}$/.test(v.trim())) return Number(v.trim())
  return null
}

/** The refusal's details ({code, limit, used}) when this is a plan-limit refusal, otherwise null. */
export function planLimitFromError(status: number, code: string | undefined | null, details: unknown): PlanLimitDetails | null {
  if (code !== PLAN_LIMIT_CODE && !(status === 402 && (!code || code === 'error'))) return null
  const d = details && typeof details === 'object' && !Array.isArray(details) ? (details as Record<string, unknown>) : {}
  return { code: typeof d.code === 'string' ? d.code : '', limit: wholeNumber(d.limit) ?? 0, used: wholeNumber(d.used) }
}

/** The sentence a person reads for a plan-limit refusal. */
export function planLimitMessage(details: PlanLimitDetails | null): string {
  if (!details || !details.code) return "This plan's limit is reached — ask the account owner to upgrade in Manage."
  const noun = NOUNS[details.code]
  const what = noun ? `${details.limit} ${details.limit === 1 ? noun[0] : noun[1]}` : String(details.limit)
  return `Your plan allows ${what} — ask the account owner to upgrade in Manage.`
}
