/**
 * Manage's company registry (/api/v1/*) for Remote, through the shared client in
 * src/company-access (copied verbatim from Manage; never edited here).
 *
 * The browser calls Manage directly with the person's in-memory ses_key (the
 * same key as Remote's own API calls); nothing is proxied or stored by Remote.
 * Manage stays the authority: Remote's backend asks it with `app=remote` before
 * relying on any company membership (MembershipVerifier / ManageClient).
 */

import { ensureSesKey } from '../auth/portal'
import { createCompanyRegistryClient } from '../company-access/index.ts'
import type { CompanyRegistryClient } from '../company-access/index.ts'
import { getManageApiOrigin } from './appLauncher'

/** Remote's Manage catalog id. */
export const REGISTRY_APP_KEY = 'remote'
export const REGISTRY_APP_NAME = 'Aicountly Remote'

let shared: CompanyRegistryClient | null = null

/** The shared registry client, created on first use. */
export function getCompanyRegistry(): CompanyRegistryClient {
  shared ??= createCompanyRegistryClient({
    appKey: REGISTRY_APP_KEY,
    baseUrl: `${getManageApiOrigin()}/api`,
    getSesKey: () => ensureSesKey(),
  })
  return shared
}
