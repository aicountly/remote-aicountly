import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { About, AgentConfig, AgentState, CodecView, PermissionSummary, SessionSummary } from '../types/agent'

/**
 * The window as a whole, over the real bridge: every `invoke` goes to a table
 * of answers keyed by command name, so what is tested is what the window does
 * with what the Rust side says — a rejection with a plain string included,
 * which is how a failing command arrives.
 */

const core = vi.hoisted(() => ({ invoke: vi.fn() }))

vi.mock('@tauri-apps/api/core', () => core)
vi.mock('@tauri-apps/api/event', () => ({ listen: vi.fn(async () => () => undefined) }))

const { default: App } = await import('./App')
const { forgetPortalPage } = await import('../features/portal/PortalView')

const CONFIG: AgentConfig = {
  apiBaseUrl: 'https://remote.aicountly.com/api',
  portalUrl: 'https://my.aicountly.com',
  presenceIntervalSeconds: 60,
  runInBackground: true,
  startAtLogin: true,
  captureQuality: 'adaptive',
  openh264Enabled: true,
  allowSecureDesktopUnattended: false,
  shareSound: false,
}

const PERMISSIONS: PermissionSummary = {
  screen_capture: 'ready',
  input_injection: 'ready',
  clipboard: 'ready',
  background_service: 'ready',
  power: 'ready',
}

const CODEC: CodecView = {
  enabled: true,
  status: { state: 'ready', version: '2.6.0', path: 'x', sizeBytes: 1 },
  attribution: 'OpenH264 Video Codec provided by Cisco Systems, Inc.',
  version: '2.6.0',
  file: 'openh264-2.6.0-win64.dll',
}

function about(overrides: Partial<About> = {}): About {
  return {
    product: 'AICOUNTLY Remote',
    version: '1.0.0',
    build: '202611140900',
    platform: 'Windows',
    supported: true,
    supportsHosting: true,
    supportsCompanion: true,
    supportsSound: true,
    supportsSecureDesktop: true,
    stopShortcut: 'Ctrl+Alt+Shift+S',
    keyStorage: 'Windows DPAPI (machine scope)',
    logFile: null,
    service: {
      running: true,
      version: '1.0.0',
      enrolled: true,
      deviceUuid: null,
      keyFingerprint: null,
      unattendedEnabled: false,
      detail: null,
    },
    privacyPolicyUrl: 'https://aicountly.com/privacy-policy',
    ...overrides,
  }
}

function session(overrides: Partial<SessionSummary> = {}): SessionSummary {
  return {
    sessionUuid: 'session-uuid',
    displayId: 'AR-10311',
    connectedName: 'Sam',
    companyName: null,
    startedAt: new Date(Date.now() - 60_000).toISOString(),
    unattended: false,
    control: { state: 'none', clipboard: false, requesterUuid: null, requesterName: null },
    ...overrides,
  }
}

function state(status: AgentState['status']): AgentState {
  return {
    status,
    deviceName: 'PRIYA-LAPTOP',
    deviceUuid: 'device-uuid',
    companyName: null,
    keyFingerprint: 'AAAA BBBB',
    unattended: { enabled: false, enabledAt: null, lastUsedAt: null, allowedByPolicy: true },
    allowedCapabilities: null,
    agentVersion: '1.0.0',
    recentSessions: [],
  }
}

type Answer = unknown | ((args: unknown) => unknown)

/** What each command answers. A function may throw — a string, as the Rust side does. */
function answers(table: Record<string, Answer>) {
  const all: Record<string, Answer> = {
    get_permissions: PERMISSIONS,
    get_configuration: CONFIG,
    about: about(),
    device_identity: 'AAAA BBBB',
    codec_status: CODEC,
    check_for_update: null,
    portal_show: undefined,
    portal_hide: undefined,
    portal_set_bounds: undefined,
    computer_history: { sessions: [], events: [] },
    list_computers: { computers: [], count: 0 },
    help_participants: { waiting: [], joined: [] },
    ...table,
  }

  core.invoke.mockImplementation(async (command: string, args?: unknown) => {
    if (!(command in all)) throw `no answer for ${command}`

    const value = all[command]

    return typeof value === 'function' ? (value as (args: unknown) => unknown)(args) : value
  })
}

function called(command: string): boolean {
  return core.invoke.mock.calls.some(([name]) => name === command)
}

/** One of the tabs in the window's header. */
async function tab(name: string): Promise<HTMLElement> {
  return within(await screen.findByRole('navigation')).findByRole('button', { name })
}

beforeEach(() => {
  core.invoke.mockReset()
  forgetPortalPage()
})

describe('the window', () => {
  /**
   * On the Remote tab the window cannot scroll — the portal is a native layer
   * over a fixed frame — and the consent card's buttons were below the bottom
   * edge with no way to reach them. A question waiting on the person takes the
   * window over instead, and gives it back when it is answered.
   */
  it('gives a request for control the whole window on the Remote tab, and the portal back afterwards', async () => {
    const asking = state({
      status: 'in_session',
      ...session({ control: { state: 'requested', clipboard: false, requesterUuid: 'p1', requesterName: 'Sam' } }),
    })
    const granted = state({
      status: 'in_session',
      ...session({ control: { state: 'granted', clipboard: false, requesterUuid: null, requesterName: null } }),
    })

    let current = asking
    answers({
      get_state: () => current,
      grant_control: () => {
        current = granted

        return granted
      },
    })

    const { container } = render(<App />)

    // A signed-in computer opens on Remote; the request is waiting there.
    expect(await screen.findByRole('alertdialog', { name: 'Sam is asking to control this computer' })).toBeInTheDocument()

    // (Landing on Remote happens a moment after the first state arrives.)
    await waitFor(() => expect(container.querySelector('.portal')).toHaveClass('portal--hidden'))

    const root = container.querySelector('.app')
    expect(root).not.toHaveClass('app--portal')
    expect(called('portal_hide')).toBe(true)
    expect(called('portal_show')).toBe(false)

    await userEvent.click(screen.getByRole('button', { name: 'Allow control' }))

    // Answered: the portal is back, and the top of the window says who has the mouse and keyboard.
    await waitFor(() => expect(root).toHaveClass('app--portal'))
    expect(container.querySelector('.portal')).not.toHaveClass('portal--hidden')
    expect(called('portal_show')).toBe(true)
    expect(screen.getByRole('status')).toHaveTextContent('Sam is controlling this computer')
    expect(screen.getByRole('button', { name: 'Stop control' })).toBeEnabled()
  })

  /** Every failure used to read "Something went wrong.", whatever the Rust side had said. */
  it('shows a failure in the words the Rust side gave for it', async () => {
    answers({
      get_state: state({ status: 'online' }),
      start_help_session: () => {
        throw 'AICOUNTLY Remote could not be reached. Check the internet connection and try again.'
      },
    })

    render(<App />)

    await userEvent.click(await tab('Get help'))
    await userEvent.click(screen.getByRole('button', { name: 'Get a code' }))

    expect(
      await screen.findByText('AICOUNTLY Remote could not be reached. Check the internet connection and try again.'),
    ).toBeInTheDocument()
    expect(screen.queryByText('Something went wrong.')).not.toBeInTheDocument()
  })

  /** Offline was one grey pill on one tab, and the screens that need a connection simply failed. */
  it('says on every screen that this computer is not connected, and why', async () => {
    answers({
      get_state: state({ status: 'offline', reason: 'the network connection was lost', retryable: true }),
    })

    render(<App />)

    expect(await screen.findByText('This computer is not connected to AICOUNTLY Remote')).toBeInTheDocument()
    expect(screen.getByText(/The network connection was lost\./)).toBeInTheDocument()

    await userEvent.click(await tab('Get help'))

    expect(screen.getByText('This computer is not connected to AICOUNTLY Remote')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Get a code' })).toBeDisabled()
  })

  /** A Mac shows its own screen now, and was being asked about Windows' prompts. */
  it('does not offer a Mac the Windows administrator-prompt tick', async () => {
    answers({
      about: about({ platform: 'macOS', supportsSecureDesktop: false, stopShortcut: '⌘⌥⇧S' }),
      get_state: state({
        status: 'in_session',
        ...session({ control: { state: 'requested', clipboard: false, requesterUuid: 'p1', requesterName: 'Sam' } }),
      }),
    })

    render(<App />)

    expect(await screen.findByRole('alertdialog', { name: 'Sam is asking to control this Mac' })).toBeInTheDocument()
    expect(screen.queryByText(/Windows administrator prompts/)).not.toBeInTheDocument()
    expect(screen.getByText('⌘⌥⇧S')).toBeInTheDocument()
  })
})
