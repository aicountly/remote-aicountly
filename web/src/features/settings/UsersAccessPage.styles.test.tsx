import { render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { CompanyRegistryClient } from '../../company-access/index.ts'
import entry from '../../main.tsx?raw'

/**
 * Users & Access is styled by the shared company-access package's own stylesheet (company-access 1.3.1+):
 * main.tsx imports src/company-access/company-access.css once, after Remote's own CSS, and the build serves it
 * as a same-origin CSS file. While it is applied the package injects no <style> element of its own; without it,
 * the package still injects one (its fallback). A file of its own: the package injects at most once per load.
 */

const INJECTED = 'style[data-aicountly="company-access"]'

vi.mock('../../app/RemoteProvider', () => ({
  useRemote: () => ({ bootstrap: { companies: [] }, scopeType: 'PERSONAL', companyId: null, refresh: async () => {} }),
}))
vi.mock('../../services/companyRegistry', () => ({
  REGISTRY_APP_NAME: 'Aicountly Remote',
  getCompanyRegistry: () => ({ appKey: 'remote', workspaces: async () => [] }) as unknown as CompanyRegistryClient,
}))

afterEach(() => {
  document.querySelectorAll('style').forEach((el) => el.remove())
  vi.resetModules()
})

/** The vendored stylesheet, read from disk: Vitest answers a `.css?raw` import with "" (it does not process CSS). */
async function vendoredStylesheet(): Promise<string> {
  // A variable specifier, so tsc (no @types/node here) does not resolve it; the tests run on Node.
  const nodeFs = 'node:fs'
  const { readFileSync } = (await import(/* @vite-ignore */ nodeFs)) as { readFileSync: (path: URL, encoding: 'utf8') => string }
  // The base through a variable: Vite rewrites `new URL('…', import.meta.url)` itself into a served asset's URL.
  const here = import.meta.url
  return readFileSync(new URL('../../company-access/company-access.css', here), 'utf8')
}

/** A fresh copy of the page and of the package. */
async function renderUsersAccess() {
  const { default: UsersAccessPage } = await import('./UsersAccessPage')
  render(<UsersAccessPage />)
  expect(await screen.findByRole('heading', { name: 'My workspaces', level: 2 })).toBeInTheDocument()
}

describe('Users & Access — the company-access stylesheet', () => {
  it("is imported once by the entry, after Remote's own CSS", () => {
    const line = "import './company-access/company-access.css'"
    expect(entry.split(line).length - 1).toBe(1)
    for (const own of ['tokens', 'base', 'app', 'room'].map((name) => `import './styles/${name}.css'`)) {
      expect(entry.indexOf(own)).toBeGreaterThanOrEqual(0)
      expect(entry.indexOf(line)).toBeGreaterThan(entry.indexOf(own))
    }
  })

  it('leaves the shared screens nothing to inject once it is on the page', async () => {
    // What the production build links from index.html (Vitest itself loads no CSS).
    const bundled = document.createElement('style')
    bundled.textContent = await vendoredStylesheet()
    expect(bundled.textContent).toContain(':root{--aic-ca-stylesheet:1}')
    document.head.appendChild(bundled)

    await renderUsersAccess()
    expect(document.querySelector(INJECTED)).toBeNull()
  })

  it('still injects the same rules when the stylesheet is missing', async () => {
    await renderUsersAccess()
    expect(document.querySelector(INJECTED)).not.toBeNull()
  })
})
