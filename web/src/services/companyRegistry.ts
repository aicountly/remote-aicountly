/**
 * Manage's company registry (/api/v1/*) for Remote, through the shared client in
 * src/company-access (copied verbatim from Manage; never edited here).
 *
 * The browser calls Remote's own API (`/api/manage/v1/*`, backend
 * ManageRegistryController / Domain/Directory/ManageRegistryRelay) with the
 * person's in-memory ses_key (the same key as Remote's own API calls), and the
 * API relays exactly these calls to Manage with it; nothing is stored by Remote.
 * Production hosting answers a browser it distrusts (common Indian CGNAT
 * broadband) with a challenge page that carries no CORS headers, so a direct
 * call from remote.aicountly.com to manage.aicountly.com failed with no
 * response at all; a call to the page's own origin does not. Manage stays the
 * authority: Remote's backend asks it with `app=remote` before relying on any
 * company membership (MembershipVerifier / ManageClient).
 */

import { ensureSesKey } from '../auth/portal'
import { createCompanyRegistryClient } from '../company-access/index.ts'
import type { CompanyRegistryClient } from '../company-access/index.ts'
import { getApiBaseUrl } from '../config'

/** Remote's Manage catalog id. */
export const REGISTRY_APP_KEY = 'remote'
export const REGISTRY_APP_NAME = 'Aicountly Remote'

let shared: CompanyRegistryClient | null = null

/** The shared registry client, created on first use. */
export function getCompanyRegistry(): CompanyRegistryClient {
  shared ??= createCompanyRegistryClient({
    appKey: REGISTRY_APP_KEY,
    baseUrl: `${getApiBaseUrl()}/manage`,
    getSesKey: () => ensureSesKey(),
  })
  return shared
}
