import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

/**
 * The desktop window's build.
 *
 * Two differences from `web/`, both because this is a Tauri window rather than
 * a page on the internet:
 *
 *   * a fixed port, because `tauri.conf.json` names it as `devUrl` and the two
 *     have to agree;
 *   * `envPrefix` restricted to `VITE_`, so a stray environment variable on a
 *     developer's machine cannot end up inlined into a binary that gets signed
 *     and shipped. Nothing secret belongs in a `VITE_` value in either project
 *     — see the note in `.env.example` at the repository root — and the agent
 *     receives its endpoints from its own configuration rather than from the
 *     bundle.
 */
export default defineConfig({
  plugins: [react()],
  clearScreen: false,
  envPrefix: ['VITE_'],
  server: {
    port: 5174,
    strictPort: true,
    watch: {
      // The Rust side has its own rebuild; watching it here would restart the
      // dev server on every `cargo` write.
      ignored: ['**/src-tauri/**', '**/crates/**', '**/agents/**'],
    },
  },
  build: {
    outDir: 'dist',
    // Chromium is the only engine this ships against — the WebView2 runtime.
    target: 'chrome120',
    sourcemap: false,
    emptyOutDir: true,
  },
})
