import { useCallback, useEffect, useRef, useState } from 'react'

import { Brand } from '../components/Brand'
import { AccessibilityNotice } from '../features/companion/AccessibilityNotice'
import { CompanionBanner } from '../features/companion/CompanionBanner'
import { CompanionConfirm } from '../features/companion/CompanionConfirm'
import { MonitorPicker } from '../features/companion/MonitorPicker'
import { DeviceCard } from '../features/device/DeviceCard'
import { DesktopSignInPending } from '../features/device/DesktopSignInPending'
import { PortalTools, PortalView, forgetPortalPage } from '../features/portal/PortalView'
import { ComputerHistory } from '../features/home/ComputerHistory'
import { ConnectScreen } from '../features/connect/ConnectScreen'
import { GetHelp, HelpAdmission } from '../features/help/GetHelp'
import { PermissionsCard } from '../features/permissions/PermissionsCard'
import { ControlRequest } from '../features/session/ControlRequest'
import { SessionBanner } from '../features/session/SessionBanner'
import { SettingsPage } from '../features/settings/SettingsPage'
import { UnattendedCard } from '../features/unattended/UnattendedCard'
import { Updates } from '../features/update/Updates'
import * as bridge from '../services/tauri'
import type {
  About,
  AgentConfig,
  AgentState,
  DesktopSignInStart,
  HelpSession,
  HostedParticipant,
  HostedParticipants,
  MonitorOption,
  PermissionSummary,
} from '../types/agent'
import { activeCompanion, activeSession, isEnrolled, isUsable } from '../types/agent'
import { SignInScreen } from '../features/device/SignInScreen'

type Screen = 'portal' | 'connect' | 'help' | 'home' | 'unattended' | 'settings' | 'register'

/** How often the waiting list of a help session is read. */
const HELP_POLL_MS = 2_000

/** How long a help session may take to start on this computer before it is given up on. */
const HELP_START_TIMEOUT_MS = 30_000

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
  // This computer's ID, read once and kept. Generated on first run, so it is
  // there to be read out before anybody has signed in.
  const [deviceId, setDeviceId] = useState<string | null>(null)
  const [state, setState] = useState<AgentState | null>(null)
  const [permissions, setPermissions] = useState<PermissionSummary | null>(null)
  const [config, setConfig] = useState<AgentConfig | null>(null)
  const [about, setAbout] = useState<About | null>(null)
  const [signIn, setSignIn] = useState<{ start: DesktopSignInStart; outcome: 'pending' | 'denied' | 'expired' } | null>(
    null,
  )
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [shareClipboard, setShareClipboard] = useState(false)
  // Bumped to send the portal back to its dashboard (choosing "Remote" again),
  // or to a page this window chose: a computer to connect to, a code to join.
  const [portalHome, setPortalHome] = useState(0)
  const [portalPath, setPortalPath] = useState('/')
  // The help session started here ("Get help"), and who is waiting for it.
  const [help, setHelp] = useState<HelpSession | null>(null)
  const [participants, setParticipants] = useState<HostedParticipants | null>(null)
  const helpSeenRunning = useRef(false)
  // The update prompt is this window's own; the portal steps aside for it.
  const [updatePrompt, setUpdatePrompt] = useState(false)
  // Whether the portal is on one of its own pages or on a sign-in page, as
  // the Rust side reports after every page it loads. Browser buttons only for
  // the second.
  const [portalOnOwnPage, setPortalOnOwnPage] = useState(true)
  // A signed-in computer opens on the portal, once, when the window first
  // learns that it is signed in.
  const landed = useRef(false)

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

      // The ID this computer is known by. Failing to read it costs the ID and
      // nothing else — the window still signs in, and the row shows a dash —
      // so it is not allowed to take the rest of this down with it.
      try {
        setDeviceId(await bridge.deviceIdentity())
      } catch {
        setDeviceId(null)
      }
    })()
  }, [refresh])

  // The Rust side pushes a new state when the tray acts — a Stop control from
  // the tray has to reach this window, or the two would disagree about whether
  // somebody is still controlling the machine.
  useEffect(() => {
    let disposeState: (() => void) | undefined
    let disposeNavigate: (() => void) | undefined
    let disposePortalPage: (() => void) | undefined

    void bridge.onStateChange(setState).then((dispose) => {
      disposeState = dispose
    })
    void bridge
      .onNavigate((target) => setScreen(target === 'settings' ? 'settings' : 'unattended'))
      .then((dispose) => {
        disposeNavigate = dispose
      })
    void bridge
      .onPortalPage((page) => setPortalOnOwnPage(page.onPortal))
      .then((dispose) => {
        disposePortalPage = dispose
      })

    return () => {
      disposeState?.()
      disposeNavigate?.()
      disposePortalPage?.()
    }
  }, [])

  // Poll while a session is running: what the server knows and this process
  // does not — a grant revoked from the browser, a device revoked by an
  // administrator — arrives this way.
  const session = state ? activeSession(state) : null
  const companion = state ? activeCompanion(state) : null
  const computerName = about?.platform === 'macOS' ? 'Mac' : 'computer'
  // Accessibility is the one thing a Mac companion needs from the person.
  const needsAccessibility =
    about?.platform === 'macOS' && permissions !== null && !isUsable(permissions.input_injection)

  // What the organisation permits. Read from the device's own row, which the
  // agent fetches with its device credential and pushes here — the window
  // never asks the API for a policy of its own, because the *device's*
  // policy is what governs a session on this machine, not the signed-in
  // person's.
  const allowed = state?.allowedCapabilities ?? null

  // A companion polls too, and faster: Accessibility granted in System
  // Settings should show here without anybody pressing anything.
  const companionActive = companion !== null

  useEffect(() => {
    if (!session && !companionActive) return

    const timer = setInterval(() => void refresh(), companionActive ? 2_000 : 5_000)

    return () => clearInterval(timer)
  }, [session, companionActive, refresh])

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

  const helpUuid = help?.uuid ?? null
  const hostingHelp = helpUuid !== null && session?.sessionUuid === helpUuid

  const forgetHelp = useCallback(() => {
    setHelp(null)
    setParticipants(null)
    helpSeenRunning.current = false
  }, [])

  // A help session also ends on the server, so the helper's page closes too.
  const endSession = useCallback(
    () =>
      run(async () => {
        if (helpUuid && session?.sessionUuid === helpUuid) {
          setState(await bridge.endHelpSession(helpUuid))
          forgetHelp()

          return
        }

        await bridge.endSession()
      }),
    [run, helpUuid, session, forgetHelp],
  )

  const startHelp = useCallback(
    () =>
      run(async () => {
        setHelp(await bridge.startHelpSession())
        setParticipants(null)
        helpSeenRunning.current = false
        setScreen('help')
      }),
    [run],
  )

  const endHelp = useCallback(
    () =>
      run(async () => {
        if (!helpUuid) return

        setState(await bridge.endHelpSession(helpUuid))
        forgetHelp()
      }),
    [run, helpUuid, forgetHelp],
  )

  const answerHelp = useCallback(
    (person: HostedParticipant, admit: boolean) =>
      run(async () => {
        if (!helpUuid) return

        await bridge.answerHelpParticipant(helpUuid, person.uuid, admit)
        setParticipants(await bridge.helpParticipants(helpUuid))
      }),
    [run, helpUuid],
  )

  // Once the help session has run here, its end — from the banner, the tray,
  // the helper, the server — is the end of it on this screen too.
  useEffect(() => {
    if (!help) return
    if (hostingHelp) {
      helpSeenRunning.current = true
    } else if (helpSeenRunning.current) {
      forgetHelp()
    }
  }, [help, hostingHelp, forgetHelp])

  // A help session that never starts here (the screen could not be shown) is
  // ended rather than left open with a code somebody might still type.
  useEffect(() => {
    if (!helpUuid || hostingHelp || helpSeenRunning.current) return undefined

    const timer = setTimeout(() => {
      void bridge.endHelpSession(helpUuid).catch(() => undefined)
      forgetHelp()
      setError('The help session could not start on this computer. Check that AICOUNTLY Remote may show your screen, then try again.')
    }, HELP_START_TIMEOUT_MS)

    return () => clearTimeout(timer)
  }, [helpUuid, hostingHelp, forgetHelp])

  // Who is waiting to be let in. Read from the API, never from a peer, so
  // nobody can put the question on this screen by sending a message.
  useEffect(() => {
    if (!helpUuid || !hostingHelp) return undefined

    let cancelled = false
    const read = () =>
      void bridge.helpParticipants(helpUuid).then(
        (next) => {
          if (!cancelled) setParticipants(next)
        },
        () => undefined,
      )

    read()
    const timer = setInterval(read, HELP_POLL_MS)

    return () => {
      cancelled = true
      clearInterval(timer)
    }
  }, [helpUuid, hostingHelp])

  const openPortal = useCallback((path: string) => {
    setPortalPath(path)
    setPortalHome((count) => count + 1)
    setScreen('portal')
  }, [])

  const openScreenRecording = useCallback(
    () =>
      run(async () => {
        setPermissions(await bridge.requestPermission('screen_capture'))
      }),
    [run],
  )

  const companionUuid = companion?.companionUuid ?? null

  const allowCompanion = useCallback(
    () =>
      run(async () => {
        if (!companionUuid) throw new Error('That request is no longer waiting.')

        setState(await bridge.allowCompanion(companionUuid))
      }),
    [run, companionUuid],
  )

  const declineCompanion = useCallback(
    () =>
      run(async () => {
        if (!companionUuid) return

        setState(await bridge.declineCompanion(companionUuid))
      }),
    [run, companionUuid],
  )

  const chooseMonitor = useCallback(
    (monitor: MonitorOption) =>
      run(async () => {
        if (!companionUuid) return

        setState(await bridge.chooseCompanionMonitor(companionUuid, monitor.id))
      }),
    [run, companionUuid],
  )

  const identifyMonitors = useCallback(() => run(() => bridge.identifyMonitors()), [run])

  const leaveCompanion = useCallback(
    () =>
      run(async () => {
        setState(await bridge.leaveCompanion())
      }),
    [run],
  )

  const openAccessibility = useCallback(
    () =>
      run(async () => {
        setPermissions(await bridge.requestPermission('input_injection'))
      }),
    [run],
  )

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

  // Device-code sign-in (docs/desktop/DEVICE_ENROLMENT.md): this window
  // creates the keypair and starts the code, the confirmation page — opened
  // in the portal inside this window, or in the person's browser if they
  // prefer — confirms it, and the server enrols the device the moment it
  // does. The Rust side never holds a portal credential of any kind; the
  // sign-in lives in the portal's web view, exactly as it would in a browser,
  // and is what the portal's menus use afterwards.
  const beginRegistration = useCallback(
    () =>
      run(async () => {
        if (!config) throw new Error('Settings are not loaded yet.')

        const material = await bridge.createDeviceKey()
        const start = await bridge.startDesktopSignIn(material)

        setSignIn({ start, outcome: 'pending' })
        setScreen('register')
      }),
    [run, config],
  )

  const cancelSignIn = useCallback(() => {
    setSignIn(null)
    setScreen('home')
  }, [])

  // Polls while a sign-in is outstanding. A network hiccup on one tick must
  // not end the wait — the next tick simply tries again, right up until the
  // code itself expires, which the server is the authority on.
  useEffect(() => {
    if (!signIn || signIn.outcome !== 'pending' || !config) return undefined

    const { start } = signIn
    let cancelled = false

    const timer = setInterval(() => {
      void (async () => {
        let outcome: Awaited<ReturnType<typeof bridge.pollDesktopSignIn>>
        try {
          outcome = await bridge.pollDesktopSignIn(start.deviceCode)
        } catch {
          return
        }
        if (cancelled) return

        if (outcome.status === 'confirmed') {
          // A device with no organisation is a personal one — the person's
          // own computer — and is registered exactly like any other.
          try {
            // The server call already created and enrolled the device;
            // without this the window would keep showing "Not registered"
            // and the connection loop would never attempt to authenticate,
            // even though registration just succeeded. The poll that got us
            // here already spent the sign-in code, so a failure here is safe
            // to retry: the next tick's poll replays the same confirmed
            // device rather than needing a fresh code.
            const next = await bridge.confirmEnrolment({
              deviceUuid: outcome.device.uuid,
              companyId: outcome.device.companyId,
              companyName: outcome.companyName,
              deviceName: outcome.device.deviceName,
            })
            if (cancelled) return

            setState(next)
            setSignIn(null)
            // Signed in: straight to everything the portal offers.
            landed.current = true
            setScreen('portal')
          } catch (caught) {
            if (!cancelled) {
              setError(caught instanceof Error ? caught.message : 'Registration succeeded but could not be recorded here.')
            }
          }
        } else if (outcome.status === 'denied' || outcome.status === 'expired') {
          setSignIn((current) => (current ? { ...current, outcome: outcome.status } : current))
        }
      })()
    }, Math.max(1, start.intervalSeconds) * 1000)

    return () => {
      cancelled = true
      clearInterval(timer)
    }
  }, [signIn, config])

  const unregister = useCallback(
    () =>
      run(async () => {
        // One call: the Rust side revokes this computer on the server with its
        // own device credential, then deletes its key. The server first,
        // because a key removed from a machine whose row is still active leaves
        // a device an administrator can see and nobody can reach — and once the
        // key is gone, nothing is left to prove which machine is asking. (This
        // window used to try the revocation itself, with a portal credential it
        // can never hold; see services/api.ts.) Best effort: an offline
        // computer is still signed out here, and its row is left for the
        // console.
        await bridge.unregisterDevice()

        // Signing the computer out signs its portal out too. Best effort: a
        // portal that was never opened has nothing to forget.
        await bridge.portalAction('sign_out').catch(() => undefined)
        forgetPortalPage()
      }),
    [run],
  )

  // Switching unattended access on is a decision the API keeps with a person
  // signed in as themselves, behind its own confirmation — so it is made in
  // Remote → Computers, inside this window, and the next poll brings the
  // switch here.
  const enableUnattended = useCallback(() => openPortal('/devices'), [openPortal])

  // One call, and it is the one that matters. This used to ask the API with a
  // portal credential this window cannot obtain, swallow the refusal, and
  // record the switch as off locally — so the switch appeared to work, and the
  // next poll of `GET /devices/me` put it back, because the server had never
  // been told. The machine tells the server itself now, with its own device
  // credential, and a failure stays a failure.
  const disableUnattended = useCallback(
    () => run(async () => void (await bridge.disableUnattended())),
    [run],
  )

  // Signed in, or not. An administrator who revoked this device signed it out
  // as surely as never having signed in did, so both land on the same screen
  // — and everything the application can do is behind it.
  const revoked = state?.status.status === 'revoked'
  const signedOut = !state || !isEnrolled(state) || revoked

  useEffect(() => {
    if (landed.current || !state) return

    landed.current = true
    if (!signedOut) setScreen((current) => (current === 'home' ? 'portal' : current))
  }, [state, signedOut])

  // The portal is only for a signed-in computer; signing out leaves it.
  useEffect(() => {
    if (signedOut && screen === 'portal') setScreen('home')
  }, [signedOut, screen])

  const selectScreen = useCallback(
    (next: Screen) => {
      // "Remote" again, while already on it: back to the dashboard. Coming
      // back to it from another tab finds the person where they were — in a
      // session they connected to, say.
      if (next === 'portal' && screen === 'portal') {
        setPortalPath('/')
        setPortalHome((count) => count + 1)
      }
      setScreen(next)
    },
    [screen],
  )

  const needsScreenRecording =
    about?.platform === 'macOS' && permissions !== null && !isUsable(permissions.screen_capture)

  // The confirmation page, as a path on this deployment's portal.
  const confirmPath = signIn ? pathOf(signIn.start.verificationUriComplete) : null
  const portalMode =
    (screen === 'portal' && !signedOut) || (screen === 'register' && signIn?.outcome === 'pending')

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
    <div className={portalMode ? 'app app--portal' : 'app'}>
      <header className="app__header">
        <Brand version={state.agentVersion} build={about?.build ?? null} platform={about?.platform ?? null} />

        <nav className="app__nav">
          {/*
           * "Remote" is the web portal itself, with its own menus — Dashboard,
           * Start session, Join session, Support requests, Sessions,
           * Computers, Settings — so it matches the browser exactly. The rest
           * are about this computer, and exist only here.
           */}
          {!signedOut ? (
            <NavItem screen="portal" current={screen} onSelect={selectScreen}>
              Remote
            </NavItem>
          ) : null}
          {!signedOut ? (
            <NavItem screen="connect" current={screen} onSelect={selectScreen}>
              Connect
            </NavItem>
          ) : null}
          {!signedOut ? (
            <NavItem screen="help" current={screen} onSelect={selectScreen}>
              Get help
            </NavItem>
          ) : null}
          <NavItem screen="home" current={screen} onSelect={selectScreen}>
            {signedOut ? 'Sign in' : 'This computer'}
          </NavItem>
          {/*
           * Only where it can be used, or has to be switched off: a tab whose
           * only content is "not available" was a door to nowhere.
           */}
          {!signedOut && about?.supportsHosting !== false && (state.unattended.enabled || state.unattended.allowedByPolicy) ? (
            <NavItem screen="unattended" current={screen} onSelect={selectScreen}>
              Unattended access
            </NavItem>
          ) : null}
          <NavItem screen="settings" current={screen} onSelect={selectScreen}>
            App settings
          </NavItem>
        </nav>

        {portalMode && portalOnOwnPage ? <PortalTools /> : null}
      </header>

      <main className="app__main">
        {/* Above everything, on every screen. */}
        <SessionBanner
          state={state}
          onStopControl={stopControl}
          onEndSession={endSession}
          busy={busy}
        />

        {/* The companion's banner: who is controlling, and Stop. */}
        <CompanionBanner
          state={state}
          computerName={computerName}
          stopShortcut={about?.stopShortcut ?? null}
          onStopControl={stopControl}
          onLeave={leaveCompanion}
          busy={busy}
        />

        {companion && companion.phase === 'awaiting_confirmation' ? (
          <CompanionConfirm
            companion={companion}
            computerName={computerName}
            needsAccessibility={needsAccessibility}
            onAllow={allowCompanion}
            onDecline={declineCompanion}
            onOpenAccessibility={openAccessibility}
            busy={busy}
          />
        ) : null}

        {companion && companion.phase === 'choosing_monitor' ? (
          <MonitorPicker
            companion={companion}
            waitingForAccessibility={needsAccessibility}
            onChoose={chooseMonitor}
            onIdentify={identifyMonitors}
            busy={busy}
          />
        ) : null}

        {needsAccessibility && companion?.phase !== 'awaiting_confirmation' ? (
          <AccessibilityNotice computerName={computerName} onOpen={openAccessibility} busy={busy} />
        ) : null}

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

        {/* Somebody typed this computer's code: asked on every screen. */}
        {hostingHelp ? (
          <HelpAdmission waiting={participants?.waiting ?? []} onAnswer={answerHelp} busy={busy} />
        ) : null}

        {/*
         * Whether a newer version is published: a prompt when the window
         * opens, then a banner for as long as this one is out of date.
         */}
        <Updates sessionActive={session !== null || companion !== null} onPromptChange={setUpdatePrompt} />

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

        {screen === 'register' && signIn ? (
          <>
            <DesktopSignInPending
              start={signIn.start}
              outcome={signIn.outcome}
              onCancel={cancelSignIn}
              onRetry={() => void beginRegistration()}
              onOpenInBrowser={() =>
                void bridge.openUrl(signIn.start.verificationUriComplete).catch(() => undefined)
              }
              busy={busy}
            />
            {signIn.outcome === 'pending' && confirmPath ? (
              <PortalView path={confirmPath} hidden={updatePrompt} signInPage={!portalOnOwnPage} />
            ) : null}
          </>
        ) : screen === 'portal' && !signedOut ? (
          <PortalView path={portalPath} navigateKey={portalHome} hidden={updatePrompt} signInPage={!portalOnOwnPage} />
        ) : signedOut && screen !== 'settings' ? (
          <SignInScreen
            revoked={revoked}
            deviceId={deviceId}
            onSignIn={() => void beginRegistration()}
            busy={busy}
          />
        ) : screen === 'connect' ? (
          <ConnectScreen
            onConnect={(computer) => openPortal(`/connect/${encodeURIComponent(computer.uuid)}`)}
            onJoin={(code) => openPortal(`/join?code=${code}`)}
            onManage={() => openPortal('/devices')}
          />
        ) : screen === 'help' ? (
          <GetHelp
            computerName={computerName}
            help={help}
            hosting={hostingHelp}
            participants={participants}
            canHost={about?.supportsHosting ?? false}
            needsScreenRecording={needsScreenRecording}
            onOpenScreenRecording={openScreenRecording}
            onStart={startHelp}
            onAnswer={answerHelp}
            onEnd={endHelp}
            busy={busy}
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
            {about && about.supportsCompanion && !about.supportsHosting && !companion ? (
              <HowItWorks
                computerName={computerName}
                stopShortcut={about.stopShortcut}
                onOpenRemote={() => selectScreen('portal')}
              />
            ) : null}

            <DeviceCard state={state} onUnregister={unregister} busy={busy} />

            <PermissionsCard
              permissions={permissions}
              allowed={allowed}
              companionOnly={about ? about.supportsCompanion && !about.supportsHosting : false}
            />

            <ComputerHistory recent={state.recentSessions} />
          </>
        )}
      </main>
    </div>
  )
}

/**
 * How somebody gets help on this computer, in the order they do it.
 *
 * The picture comes from the portal's own screen share and the mouse and
 * keyboard from this application (docs/desktop/CONTROL_COMPANION.md), so the
 * steps cross between the two — which is exactly why they are written down
 * here rather than left for somebody to discover.
 */
function HowItWorks({
  computerName,
  stopShortcut,
  onOpenRemote,
}: {
  computerName: string
  stopShortcut: string
  onOpenRemote: () => void
}) {
  return (
    <section className="card how-it-works" aria-labelledby="how-it-works-title">
      <h2 className="card__title" id="how-it-works-title">
        Getting help on this {computerName}
      </h2>
      <ol className="how-it-works__steps">
        <li>
          In <strong>Remote</strong>, start a session and give the person helping you its code.
        </li>
        <li>Admit them when they join, then share your entire screen.</li>
        <li>
          Choose <strong>Enable control from this computer</strong> and confirm here. Whenever they ask to
          use the mouse and keyboard, you decide.
        </li>
      </ol>
      <p className="card__subtitle">
        Stop control at any time with <kbd>{stopShortcut}</kbd>, from the banner, or from the{' '}
        {computerName === 'Mac' ? 'menu bar' : 'tray'} icon.
      </p>
      <div className="how-it-works__actions">
        <button type="button" className="btn btn--primary" onClick={onOpenRemote}>
          Open Remote
        </button>
      </div>
    </section>
  )
}

/** `https://remote.aicountly.com/desktop-signin?code=X` → `/desktop-signin?code=X`. */
function pathOf(url: string): string | null {
  try {
    const parsed = new URL(url)

    return `${parsed.pathname}${parsed.search}`
  } catch {
    return null
  }
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
