import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

/**
 * Vitest is configured separately from the app build so the test environment
 * (jsdom) never leaks into the production bundle.
 *
 * The suite covers the logic a screen-sharing product cannot afford to get
 * wrong: capability detection, sharing-surface enforcement, and the policy
 * gating the UI is built from. Rendering is not the risk here — the browser
 * refusing a capture, or a surface slipping past a policy check, is.
 */
export default defineConfig({
  plugins: [react()],
  // One test reads the server's list of event names (backend/app/Domain/Audit/EventType.php) to
  // check that the web has wording for each. That file is outside this folder, which the test
  // server refuses unless told otherwise. Tests only: the app build does not use this file.
  server: {
    fs: { allow: ['..'] },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['src/**/*.test.ts', 'src/**/*.test.tsx'],
    setupFiles: ['./src/test/setup.ts'],
    restoreMocks: true,
  },
})
