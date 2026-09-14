import { useCallback, useEffect, useMemo, useState } from 'react'

import { Brand } from '../components/Brand'
import { DeviceCard } from '../features/device/DeviceCard'
import { RegisterDevice } from '../features/device/RegisterDevice'
import { RecentSessions } from '../features/home/RecentSessions'
import { PermissionsCard } from '../features/permissions/PermissionsCard'
import { ControlRequest } from '../features/session/ControlRequest'
import { SessionBanner } from '../features/session/SessionBanner'
import { SettingsPage } from '../features/settings/SettingsPage'
import { UnattendedCard } from '../features/unattended/UnattendedCard'
import * as api from '../services/api'
import * as bridge from '../services/tauri'
import type { About, AgentConfig, AgentState, CompanyOption, PermissionSummary } from '../types/agent'
import { activeSession, isEnrolled } from '../types/agent'

type Screen = 'home' | 'unattended' | 'settings' | 'register'

/**
 * The window.
 *
 * The layout is one decision: **the session banner is above everything else,
 * on every screen.** Somebody who opened this application while a colleague is
 * connected should not have to navigate to find that out, and somebody who
 * wants it to stop should not have to navigate to do that either.
 */
export default function App() {
  const [screen, setScreen] = useState<Screen>('home')
  const [state, setState] = useState<AgentState | null>(null)
  const [permissions, setPermissions] = useState<PermissionSummary | null>(null)
  const [config, setConfig] = useState<AgentConfig | null>(null)
  const [about, setAbout] = useState<About | null>(null)
  const [companies, setCompanies] = useState<CompanyOption[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [shareClipboard, setShareClipboard] = useState(false)

  const refresh = useCallback(async () => {
    const [next, perms] = await Promise.all([bridge.getState(), bridge.getPermissions()])

    setState(next)
    setPermissions(perms)
  }, [])

  useEffect(() => {
    void (async () => {
      await refresh()
      setConfig(await bridge.getConfiguration())
      setAbout(await bridge.about())
    })()
  }, [refresh])

  // The Rust side pushes a new state when the tray acts — a Stop control from
  // the tray has to reach this window, or the two would disagree about whether
  // somebody is still controlling the machine.
  useEffect(() => {
    let disposeState: (() => void) | undefined
    let disposeNavigate: (() => void) | undefined

    void bridge.onStateChange(setState).then((dispose) => {
      disposeState = dispose
    })
    void bridge
      .onNavigate((target) => setScreen(target === 'settings' ? 'settings' : 'unattended'))
      .then((dispose) => {
        disposeNavigate = dispose
      })

    return () => {
      disposeState?.()
      disposeNavigate?.()
    }
  }, [])

  // Poll while a session is running: what the server knows and this process
  // does not — a grant revoked from the browser, a device revoked by an
  // administrator — arrives this way.
  const session = state ? activeSession(state) : null

  // What the organisation permits. Read from the device's own row, which the
  // agent fetches with its device credential and pushes here — the window
  // never asks the API for a policy of its own, because the *device's*
  // policy is what governs a session on this machine, not the signed-in
  // person's.
  const allowed = state?.allowedCapabilities ?? null

  useEffect(() => {
    if (!session) return

    const timer = setInterval(() => void refresh(), 5_000)

    return () => clearInterval(timer)
  }, [session, refresh])

  const run = useCallback(
    async (action: () => Promise<unknown>) => {
      setBusy(true)
      setError(null)

      try {
        await action()
        await refresh()
      } catch (caught) {
        setError(caught instanceof Error ? caught.message : 'Something went wrong.')
      } finally {
        setBusy(false)
      }
    },
    [refresh],
  )

  const stopControl = useCallback(
    () =>
      run(async () => {
        // One call, and it is local. The Rust side's gate stops admitting
        // input before this resolves; telling the API is something it does
        // afterwards with the machine's own device credential, and a failure
        // there leaves the machine in the state the person chose rather than
        // in the one the server last heard about.
        setState(await bridge.stopControl())
      }),
    [run],
  )

  const endSession = useCallback(() => run(() => bridge.endSession()), [run])

  const grantControl = useCallback(
    () =>
      run(async () => {
        const requesterUuid = session?.control.requesterUuid

        // Nobody to grant to — the request must have been withdrawn or
        // already answered elsewhere while this was on screen.
        if (!requesterUuid) throw new Error('That request is no longer waiting.')

        setState(await bridge.grantControl(requesterUuid, shareClipboard))
        setShareClipboard(false)
      }),
    [run, session, shareClipboard],
  )

  const denyControl = useCallback(
    () =>
      run(async () => {
        setState(await bridge.denyControl())
        setShareClipboard(false)
      }),
    [run],
  )

  const register = useCallback(
    (companyId: number, deviceName: string) =>
      run(async () => {
        if (!config) throw new Error('Settings are not loaded yet.')

        const material = await bridge.createDeviceKey()
        const { device } = await api.enrolDevice(config.apiBaseUrl, companyId, deviceName, material)

        // The server call above already created the device row; without this
        // second call the window would keep showing "Not registered" and the
        // connection loop would never attempt to authenticate, even though
        // registration just succeeded.
        setState(
          await bridge.confirmEnrolment({
            deviceUuid: device.uuid,
            companyId,
            companyName: companies.find((company) => company.companyId === companyId)?.name ?? null,
            deviceName: device.deviceName,
          }),
        )

        setScreen('home')
      }),
    [run, config, companies],
  )

  const unregister = useCallback(
    () =>
      run(async () => {
        const uuid = state?.deviceUuid

        // The server-side revocation first, then the local key. In that order
        // because a key removed from a machine whose device row is still
        // active would leave a device an administrator can see and nobody can
        // reach — and the reverse leaves a key that authenticates nothing.
        if (uuid && config && api.hasSessionKey()) {
          await api.revokeDevice(config.apiBaseUrl, uuid).catch(() => undefined)
        }

        await bridge.unregisterDevice()
      }),
    [run, state, config],
  )

  const enableUnattended = useCallback(
    () =>
      run(async () => {
        if (!config || !state?.deviceUuid) throw new Error('This device is not registered.')

        const { device } = await api.enableUnattended(config.apiBaseUrl, state.deviceUuid)
        await bridge.recordUnattendedEnabled(device.unattendedEnabledAt)
      }),
    [run, config, state],
  )

  const disableUnattended = useCallback(
    () =>
      run(async () => {
        if (!config || !state?.deviceUuid) throw new Error('This device is not registered.')

        await api.disableUnattended(config.apiBaseUrl, state.deviceUuid).catch(() => undefined)
        await bridge.recordUnattendedDisabled()
      }),
    [run, config, state],
  )

  const defaultDeviceName = useMemo(() => state?.deviceName ?? '', [state])

  if (!state || !permissions) {
    return (
      <div className="app">
        <main className="app__main">
          <p className="empty">Starting AICOUNTLY Remote…</p>
        </main>
      </div>
    )
  }

  return (
    <div className="app">
      <header className="app__header">
        <Brand version={state.agentVersion} />

        <nav className="app__nav">
          <NavItem screen="home" current={screen} onSelect={setScreen}>
            Home
          </NavItem>
          {isEnrolled(state) ? (
            <NavItem screen="unattended" current={screen} onSelect={setScreen}>
              Unattended access
            </NavItem>
          ) : null}
          <NavItem screen="settings" current={screen} onSelect={setScreen}>
            Settings
          </NavItem>
        </nav>
      </header>

      <main className="app__main">
        {/* Above everything, on every screen. */}
        <SessionBanner
          state={state}
          onStopControl={stopControl}
          onEndSession={endSession}
          busy={busy}
        />

        {/*
         * Also above everything, on every screen: a pending request is a
         * decision waiting on the person at this machine, and it must not be
         * hideable by being on the wrong tab when it arrives.
         */}
        {session && session.control.state === 'requested' ? (
          <ControlRequest
            session={session}
            requesterName={session.control.requesterName || session.connectedName || 'Someone'}
            clipboardAllowedByPolicy={allowed?.clipboard_sync ?? false}
            shareClipboard={shareClipboard}
            onShareClipboardChange={setShareClipboard}
            onAllow={grantControl}
            onDeny={denyControl}
            busy={busy}
          />
        ) : null}

        {about && !about.supported ? (
          <div className="notice notice--warning">
            <p className="notice__title">Not supported on this operating system</p>
            <p>
              AICOUNTLY Remote for desktop supports Windows 10 22H2 and Windows 11. This build is
              running on {about.platform}, where screen sharing and remote control are not
              available.
            </p>
          </div>
        ) : null}

        {error ? <div className="notice notice--danger">{error}</div> : null}

        {screen === 'register' ? (
          <RegisterDevice
            companies={companies}
            defaultName={defaultDeviceName}
            onRegister={register}
            onCancel={() => setScreen('home')}
            busy={busy}
            error={error}
          />
        ) : screen === 'unattended' ? (
          <UnattendedCard
            state={state}
            onEnable={enableUnattended}
            onDisable={disableUnattended}
            busy={busy}
            error={error}
          />
        ) : screen === 'settings' && config ? (
          <SettingsPage
            config={config}
            about={about}
            onSave={(next) => run(async () => setConfig(await bridge.saveConfiguration(next)))}
            busy={busy}
            error={error}
          />
        ) : (
          <>
            <DeviceCard
              state={state}
              onRegister={() =>
                void run(async () => {
                  if (config && api.hasSessionKey()) {
                    setCompanies(await api.fetchCompanies(config.apiBaseUrl))
                  }
                  setScreen('register')
                })
              }
              onUnregister={unregister}
              busy={busy}
            />

            <PermissionsCard permissions={permissions} allowed={allowed} />

            <RecentSessions sessions={state.recentSessions} />
          </>
        )}
      </main>
    </div>
  )
}

function NavItem({
  screen,
  current,
  onSelect,
  children,
}: {
  screen: Screen
  current: Screen
  onSelect: (screen: Screen) => void
  children: React.ReactNode
}) {
  return (
    <button
      type="button"
      className={`app__nav-item${current === screen ? ' app__nav-item--active' : ''}`}
      onClick={() => onSelect(screen)}
      aria-current={current === screen ? 'page' : undefined}
    >
      {children}
    </button>
  )
}
