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
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['src/**/*.test.ts', 'src/**/*.test.tsx'],
    setupFiles: ['./src/test/setup.ts'],
    restoreMocks: true,
  },
})
