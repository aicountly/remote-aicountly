/**
 * The AICOUNTLY Connect embed contract (v1), as Remote uses it.
 *
 * The source of truth is connect-aicountly's docs/EMBED_SDK.md and
 * web/public/embed/v1/loader.js. Only what Remote calls is typed here.
 */

export interface ConnectScopePatch {
  companyId?: number | null
  fyId?: number | null
  boId?: number | null
}

export type ConnectEventHandler = (name: string, detail: unknown) => void

export interface ConnectInitOptions extends ConnectScopePatch {
  /** The host's ses_key: cached normally, re-minted when `forceRefresh`, null when signed out. */
  getSesKey: (forceRefresh: boolean) => Promise<string | null>
  apiOrigin?: string
  product?: string
  position?: 'bottom-right' | 'bottom-left'
  zIndex?: number
  advisorUrl?: string
  labels?: { button?: string; openAria?: string; closeAria?: string; advisor?: string }
  /** Read at mount only: false mounts no call engine (no call buttons, no ringing, no polling). */
  calling?: boolean
  /** false renders no floating launcher; the host opens the panel itself. */
  launcher?: boolean
  onEvent?: ConnectEventHandler
}

export type ConnectUpdate = Partial<Omit<ConnectInitOptions, 'apiOrigin' | 'calling'>>

export interface ConnectOpenRequest {
  conversationId?: string
  /** `{}` opens the pinned AI Pulse thread; `{ prompt }` pre-fills a question (sent only with `send: true`). */
  pulse?: { prompt?: string; send?: boolean }
}

/** `window.AICountlyChat` / `window.AICountlyConnect` — the same object. */
export interface ConnectApi {
  /** Resolves false when the widget could not load. Never rejects. */
  init(options: ConnectInitOptions): Promise<boolean>
  update(patch: ConnectUpdate): void
  open(request?: ConnectOpenRequest): void
  close(): void
  destroy(): void
  isReady(): boolean
  version?: string
}

declare global {
  interface Window {
    AICountlyChat?: ConnectApi
    AICountlyConnect?: ConnectApi
  }
}
