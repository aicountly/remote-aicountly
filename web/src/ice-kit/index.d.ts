/**
 * Types for the AICOUNTLY ICE kit (index.js). Hand-written to match it; keep the two in step.
 */

export declare const ICE_CONTRACT: 'aicountly.ice/1'

export interface RelayNode {
  readonly id: string
  readonly label: string
  readonly region: string
  readonly priority: number
  readonly status: string
  readonly health: string
  readonly urls: readonly string[]
}

export interface IceServer {
  readonly urls: string[]
  readonly username?: string
  readonly credential?: string
}

export interface IceConfig {
  readonly contract: string | null
  readonly iceServers: readonly IceServer[]
  readonly iceTransportPolicy: RTCIceTransportPolicy
  readonly iceCandidatePoolSize: number
  readonly relayAvailable: boolean
  readonly relayNodes: readonly RelayNode[]
  /** ms epoch on this client's clock */
  readonly receivedAt: number
  readonly expiresAt: number | null
  readonly refreshAt: number | null
  readonly degraded: boolean
  readonly failover: boolean
  /** Whether a relayed connection is restarted onto renewed credentials. Default true. */
  readonly restartOnRenewal: boolean
}

export declare function normalizeIceConfig(raw: unknown, now?: number): IceConfig
export declare function rtcConfiguration(config: IceConfig, overrides?: Partial<RTCConfiguration>): RTCConfiguration
export declare function needsRefresh(config: IceConfig | null, now?: number): boolean
export declare function isExpired(config: IceConfig | null, now?: number, marginMs?: number): boolean
export declare function applyIceConfig(pc: RTCPeerConnection, config: IceConfig): boolean
export declare function relayNodeForUrl(config: IceConfig | null, url: string | null | undefined): RelayNode | null
export declare function hostPort(url: string): string | null
export declare function urlsOf(server: { urls?: string | string[] } | null | undefined): string[]

export type CandidateType = 'host' | 'srflx' | 'prflx' | 'relay'
export declare const CANDIDATE_TYPES: readonly CandidateType[]

export type IceErrorCategory =
  | 'turn_unreachable'
  | 'turn_auth_failed'
  | 'turn_forbidden'
  | 'turn_capacity'
  | 'turn_error'
  | 'stun_unreachable'
  | 'ice_failed'
  | 'no_relay_candidates'
  | 'credentials_expired'
  | 'signalling_lost'

export declare function parseCandidate(
  candidate: RTCIceCandidate | RTCIceCandidateInit | string | null,
): { type: CandidateType | null; protocol: string | null; relayProtocol: string | null }

export declare function classifyIceError(event: {
  errorCode?: number
  url?: string
}): { category: IceErrorCategory; code: number; url: string | null }

export type IceVerdict =
  | 'ok'
  | 'no-relay-configured'
  | 'relay-auth-failed'
  | 'relay-unreachable'
  | 'relay-refused'
  | 'no-relay-candidates'
  | 'ice-failed'

export interface SelectedPath {
  path: 'direct' | 'relay' | 'unknown'
  localCandidateType: CandidateType | null
  remoteCandidateType: CandidateType | null
  relayProtocol: 'udp' | 'tcp' | 'tls' | null
  relayNode: string | null
  rttMs: number | null
}

export type ConnectivityOutcome = 'connected' | 'recovered' | 'failed' | 'abandoned'

export interface ConnectivityReport {
  outcome: ConnectivityOutcome
  path: SelectedPath['path']
  localCandidateType: CandidateType | null
  remoteCandidateType: CandidateType | null
  relayProtocol: SelectedPath['relayProtocol']
  relayNode: string | null
  gathered: Record<CandidateType, number>
  iceErrors: Array<{ category: IceErrorCategory; node: string | null; code: number }>
  restarts: number
  credentialRefreshes: number
  timeToConnectMs: number | null
  durationMs: number
}

export declare class IceDiagnostics {
  constructor(pc: RTCPeerConnection, options?: { config?: IceConfig | null; now?: () => number })
  readonly gathered: Record<CandidateType, number>
  readonly restarts: number
  readonly credentialRefreshes: number
  setConfig(config: IceConfig | null): void
  noteRestart(): void
  noteCredentialRefresh(): void
  selectedPath(): Promise<SelectedPath>
  verdict(): IceVerdict
  iceErrors(): Array<{ category: IceErrorCategory; node: string | null; code: number; count: number }>
  report(outcome: ConnectivityOutcome): Promise<ConnectivityReport>
  dispose(): void
}

export declare function describeVerdict(verdict: IceVerdict | string): string

export interface Clock {
  setTimeout(fn: () => void, ms: number): unknown
  clearTimeout(id: unknown): void
  now(): number
}

export declare const defaultClock: Clock

export type SupervisorState = 'new' | 'connecting' | 'connected' | 'unstable' | 'recovering' | 'failed' | 'closed'

export interface SupervisorOptions {
  pc: RTCPeerConnection
  restart: (info: { attempt: number; reason: string }) => void | Promise<void>
  refreshCredentials?: () => Promise<IceConfig | null>
  getConfig?: () => IceConfig | null
  onStateChange?: (state: SupervisorState, info: { attempts: number; attempt?: number; reason?: string }) => void
  onGiveUp?: (reason: 'exhausted' | 'timeout' | string) => void
  onRecovered?: (info: { attempts: number; ms: number }) => void
  network?: Pick<EventTarget, 'addEventListener' | 'removeEventListener'> & { navigator?: { onLine?: boolean } } | null
  clock?: Clock
  disconnectedGraceMs?: number
  attemptTimeoutMs?: number
  maxAttempts?: number
  recoveryWindowMs?: number
}

export declare class ConnectionSupervisor {
  constructor(options: SupervisorOptions)
  readonly state: SupervisorState
  readonly attempts: number
  readonly restartsTotal: number
  readonly recovering: boolean
  requestRestart(reason: string): Promise<void>
  retry(reason?: string): void
  dispose(): void
}

export declare class CredentialRefresher {
  constructor(options: {
    fetch: () => Promise<unknown>
    onRefreshed?: (config: IceConfig) => void
    onError?: (error: unknown) => void
    clock?: Clock
    minDelayMs?: number
    retryDelayMs?: number
  })
  readonly current: IceConfig | null
  start(config: IceConfig | null): this
  refreshNow(): Promise<IceConfig>
  stop(): void
}

export interface RelayProbeResult {
  ok: boolean
  verdict: 'ok' | 'unsupported' | 'no-relay-configured' | 'relay-auth-failed' | 'relay-unreachable'
  relayAvailable: boolean
  nodes: Array<{
    id: string
    label: string
    region: string
    relayCandidates: number
    protocols: string[]
    errors: string[]
  }>
  durationMs: number
}

export declare function probeRelay(
  config: IceConfig,
  options?: { timeoutMs?: number; RTCPeerConnection?: typeof RTCPeerConnection },
): Promise<RelayProbeResult>

export declare function isRestartDriver(selfId: string, peerId: string): boolean
