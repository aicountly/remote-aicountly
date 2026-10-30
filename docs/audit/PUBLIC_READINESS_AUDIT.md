# AICOUNTLY Remote: public-readiness audit

| | |
|---|---|
| Date | 30 October 2026 |
| Written for | The owner |
| Code audited | Release branch `claude/clever-dirac-x9bs7d` at `7274918`. This release is `8cefb42` + `7274918` on top of `main` (`3abaa9e`). Line numbers refer to `7274918`. The parallel changes listed in §2 may move a few of them. |
| Scope | Personal AICOUNTLY accounts only. By the owner's decision, Remote has no link to companies or organisations for now. Windows code signing is left out, except where something depends on it (the OV certificate from SSL.com is in validation). |
| Effort | **S** = up to 2 days · **M** = up to 2 weeks · **L** = up to 6 weeks · **XL** = longer, or a dependency decision is needed first |
| Competitors | The AnyDesk, TeamViewer and UltraViewer columns come from general knowledge, **as commonly advertised. Verify before quoting publicly.** |

Every statement about AICOUNTLY Remote cites the code as `file:line`. Where a file name is unique in the repository, only
the name is given. The folders are:

| Folder | Files cited by name |
|---|---|
| `backend/app/Domain/Device/` | `CompanionService.php`, `DeviceSessionService.php` |
| `backend/app/Domain/Policy/` | `EffectivePolicyResolver.php`, `EffectivePolicy.php`, `CompanyPolicyDefaults.php` |
| `backend/app/Domain/Session/`, `…/Audit/` | `JoinService.php`, `SessionService.php`, `ChatService.php`, `AuditService.php` |
| `backend/app/Config/`, `…/Filters/`, `…/Libraries/` | `Remote.php`, `Routes.php`, `App.php`, `Cache.php`, `RateLimitFilter.php`, `CompanyLifecycle.php` |
| `backend/app/Database/Seeds/`, `backend/bin/ops/` | `RemotePlatformDefaultsSeeder.php`, `jobs.txt` |
| `desktop/src-tauri/src/` | `session.rs`, `agent.rs`, `companion.rs`, `runtime.rs`, `src-tauri/src/lib.rs`, `commands/mod.rs`, `portal.rs` |
| `desktop/src-tauri/src/platform/` | `windows/capture.rs`, `windows/input.rs`, `macos/permissions.rs` |
| `desktop/crates/remote-core/src/` | `update.rs`, `logging.rs`, `remote-core/src/lib.rs` |
| `desktop/agents/windows-service/src/` | `service/storage.rs` |
| `desktop/src-tauri/`, `desktop/installers/windows/` | `tauri.conf.json`, `windows-app-manifest.xml`, `hooks.nsh` |
| `desktop/src/` | `app/App.tsx`, `features/device/SignInScreen.tsx`, `features/settings/SettingsPage.tsx` (called "desktop `SettingsPage.tsx`"), `features/update/Updates.tsx` |
| `web/src/features/room/`, `…/sessions/` | `CompanionPanel.tsx`, `ControlPanel.tsx`, `SessionRoomPage.tsx`, `StartSessionPage.tsx` |
| `web/src/app/`, `web/src/services/`, `web/src/utils/` | `RemoteProvider.tsx`, `AppShell.tsx`, `router.tsx`, `webrtc/screenCapture.ts`, `webrtc/RemotePeerConnection.ts`, `webrtc/remoteControl.ts`, `api/remote.ts`, `analytics.ts` |
| `.github/workflows/`, `desktop/scripts/` | `release-windows.yml`, `deploy-production.yml`, `ci.yml`, `make-manifest.mjs` |

---

## 1. Verdict

### Office pilot: go, with conditions

Remote is ready for a supervised office pilot of **attended help between personal accounts**. It works like this. The
person being helped shares their entire screen from Chrome or Edge, or from the app's Remote tab. They link their own
computer, which then carries the input. They approve every request. The helper needs only a browser. The flow is built end
to end, on the server and on both desktop platforms. This release removed the bug that stopped personal accounts using it.

Start the pilot when these three things are true:

1. **Production runs this release's backend and web.** The fixes for personal accounts are on the server
   (`CompanionService.php:958-974`, `EffectivePolicyResolver.php:335-338`, `DeviceSessionService.php:470-476`). Merging
   is not deploying. Until the deploy, "Enable control from this computer" will still be refused in a personal session.
2. **Every pilot computer runs a numbered build from this release.** App settings → About shows a 12-digit Build, not
   "Local build" (desktop `SettingsPage.tsx:118-122`).
3. **The parallel fixes have landed, or the group has been told they have not.** These are: signing a computer out
   revokes it on the server, uninstall cleans up, and the web is personal-only (see §2).

Tell the pilot group these limits before they start (details in §5.2):

* The helper cannot click on UAC prompts or administrator windows, and the app does not tell them why.
* Control works only while the whole screen is shared and the app is open on that computer. The room's Share button
  proposes a browser tab, so change it to Entire screen (an S-sized fix, P1-13).
* The clipboard does not work during control.
* A session stops at 60 minutes, with no warning.
* Files can be at most 25 MB.
* Only one screen is shared at a time.
* The Windows key, Alt+Tab and Ctrl+Alt+Del cannot be sent.
* The installers are unsigned, so Windows and macOS will warn about them.

### Public launch: not yet

Today AICOUNTLY Remote is a careful tool for *attended* help in the browser. People who compare it with AnyDesk,
TeamViewer or UltraViewer will expect three things it does not have yet:

* a desktop app that sends its own picture (`session.rs:34-39`);
* a way through UAC prompts (`windows-app-manifest.xml:18`);
* unattended access to their own computers (`CompanyPolicyDefaults.php:197`).

These P0 blockers must be fixed first (§6.1):

1. **Updates run whatever the download host serves.** The size and SHA-256 are checked, but they come from that same
   host. Nothing checks a signature before the installer runs.
2. **There are no Terms, no privacy notice for Remote and no EULA**, in the web, the app or the installer.
3. **Nothing is ever deleted on a schedule.** Chat text, IP addresses and user agents are kept for ever. Nothing in the
   product can delete the audit rows of personal sessions.
4. **There is no handling of abuse or scams.** At scale, that is the first thing remote-control tools get used for.
5. **Operations are not ready for the public.**
   * Production deploys skip the tests unless someone ticks a box.
   * CI no longer runs on pull requests.
   * This repository shows no monitoring or alerting.
   * Everything runs on one server, and signalling is a single process that keeps all its state in memory.
6. **Positioning needs a decision.** Either launch as "attended help in the browser" and say plainly what it does not do,
   or wait for native video, UAC support and unattended access. That is about a quarter of work (XL).
7. **Pricing and free-tier limits are not decided.** TURN admission only observes and refuses nobody. Its capacity
   figures are placeholders, and the relays are shared with Connect.

Two more are blockers only in some cases. If you market outside India, you need relays near those users. If you serve
the EU, analytics must not load before consent.

**What is already strong:**

* Nothing is sent before the host admits a person (`docs/SECURITY.md:13-16`).
* Control needs consent at the computer and again for each request. It stops locally, with no network round trip
  (`docs/desktop/ARCHITECTURE.md:153-164`).
* Screen content and file contents are never stored. When a relay is needed, it forwards them encrypted and cannot read
  them.
* Device keys never leave the computer.
* Device, signalling and TURN credentials are all short-lived.
* The documentation is mostly honest about its limits.

Rough effort: the P0 items take 4–6 weeks of focused work, not counting the signing dependency. The features needed to
match the competitors take a quarter or more.

---

## 2. What this release changed

Done in `8cefb42` / `7274918`:

| Change | Where |
|---|---|
| **Every build has a number**: the commit time, UTC, `yyyyMMddHHmm`. It is in the binary, the installer's file name, the GitHub release (one release per build, pinned to the built commit) and the in-app update check. A later build of the same version is offered, and it can never be served from a cache under the old name. Installers are served `immutable` for a year, which is why reinstalling "the latest" 1.0.0 kept installing the old build. | `remote-core/src/lib.rs:47-58`; `release-windows.yml:156-185`, `:331`, `:405-406`; `make-manifest.mjs:75-76`; `update.rs:190-216`, `:269-271` |
| **Personal accounts can be helped.** A personal session can link its owner's own computer as the control companion. | `CompanionService.php:95-107`, `:171-181`, `:958-974` |
| A personal account may share an entire screen and request control. The person at the computer still confirms the link and grants each request. | `EffectivePolicyResolver.php:318-338` |
| Personal devices are no longer "not found" in personal sessions. | `DeviceSessionService.php:470-476` |
| The macOS app reopens when its Dock icon is clicked. | `src-tauri/src/lib.rs:285-303` |
| Desktop window: dark chrome of its own; the build in the header, About and update prompts; a "Getting help on this computer" card; no organisation wording; the Unattended access tab only where it can be used. | `App.tsx:438`, `:460`, `:630`; desktop `SettingsPage.tsx:118-122`; `Updates.tsx:123-124`, `:155-156` |
| Inside the app, the web portal is always personal. Its Start page starts on "Entire screen" (the room's Share button does not follow it yet: P1-13). It hides the organisation switcher and the session-context card. | `RemoteProvider.tsx:123-134`; `AppShell.tsx:204`; `StartSessionPage.tsx:36`, `:130` |
| New tests for the personal-account fixes and for build numbering. | `backend/tests/Device/ControlCompanionTest.php` (3 new), `backend/tests/Policy/EffectivePolicyResolverTest.php` (2 new); build comparison in `update.rs:689-1007`; `desktop/scripts/make-manifest.test.mjs` |

**Being done in parallel**. These are not in `7274918`, so check that they have landed before the pilot:

* Session-room upgrades: full screen, and connection details.
* **Sign this computer out** revokes the device on the server. Today it only forgets the key locally
  (`commands/mod.rs:276-284`).
* Uninstall removes the logs folder. Today `hooks.nsh:238-257` deletes the key, the configuration and the enrolment file,
  but leaves `logs\` behind, so the folder stays.
* The whole web becomes personal-only. Today the switcher is hidden only inside the app (`AppShell.tsx:204`).

---

## 3. How remote control works today (checked in the code)

```text
 Person being helped (A)                                        Helper (B)
 ┌────────────────────────────────────┐   screen video     ┌──────────────────────┐
 │ Chrome/Edge, or the app's Remote   │ ── peer to peer ─▶ │ any browser, signed  │
 │ tab: shares the ENTIRE screen      │    (or via TURN)   │ in to AICOUNTLY      │
 │   │ "Enable control from this      │                    │                      │
 │   ▼  computer" → confirm in the app│   input only       │ "Request control"    │
 │ AICOUNTLY Remote app, same computer│ ◀─ data channel ── │                      │
 │ (control companion)                │                    │                      │
 └────────────────────────────────────┘                    └──────────────────────┘
```

* **The picture** comes from the browser's own screen capture: ideally 15 fps, at most 30 fps
  (`screenCapture.ts:84-88`). The browser's default encoder settings are used; nothing sets a codec or bitrate
  (`RemotePeerConnection.ts:274`).
* **The input** goes through the companion, and only while all of these hold (`CompanionService.php:44-52`):
  * the session is a personal one or a company's (never a support session);
  * A is the joined host and is sharing an entire screen;
  * the device is A's own, active and online;
  * someone confirmed the link on that computer within 5 minutes;
  * the companion has matched the monitor;
  * A granted this request.
* **Stopping.** The control gate is local. It can be closed with the shortcut (Ctrl+Alt+Shift+S on Windows, ⌘⌥⇧S on
  a Mac: `src-tauri/src/lib.rs:47-58`), the banner or the tray icon. Any real mouse or keyboard use at the computer pauses
  the helper. Their input resumes after 1.5 seconds without local input (`companion.rs:63`).
* **The Windows app never sends a picture of its own.** No frame is encoded (`session.rs:34-39`). The app hosts a session
  only when an unattended session is waiting for it (`runtime.rs:387-400`), and a personal account cannot turn unattended
  access on (`CompanyPolicyDefaults.php:197`). So for personal accounts, the companion is the only way to control a
  computer.
* **On a Mac, the app is a companion and nothing more.** Accessibility is checked live. Screen Recording is never asked
  for (`macos/permissions.rs:3-13`). The clipboard, a background service and restarts are not supported (`:34-40`).

---

## 4. Capability matrix

The AICOUNTLY column describes this release, checked in the code. The three competitor columns are **as commonly
advertised. Verify before quoting publicly.**

| Capability | AICOUNTLY Remote | AnyDesk | TeamViewer | UltraViewer |
|---|---|---|---|---|
| Connect by ID or code | A 9-digit session code, and the host admits each person (`Remote.php:217`, `JoinService.php:29`). There is no connecting to a computer by its ID. "This computer's ID" is a key fingerprint that nothing accepts (`SignInScreen.tsx:52`, `agent.rs:313-328`). | Yes. A permanent ID or alias. | Yes. ID and password. | Yes. ID and password. |
| Attended control | Yes, on Windows and Mac. The browser shares the whole screen and the person's own app carries the input. Two consents. | Yes | Yes | Yes |
| Unattended access | No. It is off for personal accounts (`CompanyPolicyDefaults.php:197`), and the app sends no picture anyway (`session.rs:34-39`). | Yes | Yes | Yes |
| UAC and elevated windows | No. The app runs `asInvoker` (`windows-app-manifest.xml:18`), and refused input is dropped without a word (`agent.rs:1017`, `companion.rs:867`). | Yes, when installed | Yes, when installed | Usually yes when installed (verify) |
| Multi-monitor | One shared screen at a time, picked by the person in the browser. The helper cannot switch screens (`agent.rs:963-966`). | Yes. Switch, or show all. | Yes | Yes. Switch. |
| File transfer | Browser to browser. The recipient must accept. 25 MB at most (`Remote.php:248`). | Yes, with a file manager | Yes | Yes |
| Clipboard | No, not during companion control (`CompanionService.php:771`). | Text and files, both ways | Text and files | Text (files: verify) |
| Chat | Yes, in the web | Yes | Yes | Yes, a main feature |
| Annotations and whiteboard | A live pointer and annotation, in the web | Whiteboard | Yes | Not advertised (verify) |
| Recording | No (`Remote.php:225`) | Yes, on paid plans | Yes | Not advertised (verify) |
| Address book | A list of your own computers only | Yes, on paid plans | Yes (Computers & Contacts) | A basic partner list (verify) |
| Wake-on-LAN | No | Yes, on paid plans | Yes | Not advertised (verify) |
| Reboot and reconnect | No. The API call exists but no screen uses it (`api/remote.ts:512-518`), and it is off for personal accounts (`CompanyPolicyDefaults.php:199`). | Yes | Yes, including safe mode | Verify |
| Mobile apps | None. A mobile browser can join, watch and chat, but cannot share (`docs/BROWSER_SUPPORT.md:56-64`). | iOS and Android | iOS and Android | Not offered (verify) |
| Linux | Browser only: it can share and watch but cannot be controlled. There is no app: `desktop/src-tauri/src/platform/` has only `windows/` and `macos/`, and no Linux build is published (`update.rs:147-161`). | Yes | Yes | No |
| **Access from a browser alone** | **Yes. This is the real advantage. The helper needs only a browser and an AICOUNTLY account. The person being helped needs a browser, and the app only if they want to be controlled.** | The app is normally needed at both ends (web client limited: verify) | Web client for connecting out only; the other end needs the app | No |
| Two-factor sign-in | Left to the AICOUNTLY sign-in. Not visible in this repository. | Yes | Yes, plus trusted devices | Not advertised (verify) |
| Audit | Personal accounts get session history and an event timeline. The full audit trail is for companies only (`EffectivePolicyResolver.php:250-258`). | Session logs, on paid plans | Connection reports, on paid plans | Basic history |
| Pricing and free tier | Not decided. A built-in "Basic" plan applies (`EffectivePolicyResolver.php:525-535`). | Free for personal use; paid tiers | Free for personal use; paid tiers (dearer) | Free; low-cost paid tiers |

---

## 5. Office pilot

### 5.1 Must be true before the pilot starts

| # | Blocker | Why it matters | Where | Effort |
|---|---|---|---|---|
| PB-1 | Deploy this release's backend and web to production, with your go-ahead. | Without it, linking a personal computer is refused (`COMPANION_SCOPE`), and personal devices are "not found" in personal sessions. | `CompanionService.php:139-144`, `:958-974`; `DeviceSessionService.php:470-476` | S |
| PB-2 | Publish numbered Windows and macOS builds. Testers install only those. | Without a number, nobody can tell which build was tested. That was the old 1.0.0 cache problem. | `release-windows.yml:156-185`; desktop `SettingsPage.tsx:118-122` | S |
| PB-3 | The parallel fixes have landed, or are explicitly put off. | If they have not, "Sign this computer out" leaves the device active on the server, and uninstall leaves a folder behind. | `commands/mod.rs:276-284`; `hooks.nsh:238-257` | S–M (in flight) |
| PB-4 | Each tester has a personal AICOUNTLY account and has signed in. | Joining with a code needs a signed-in account (`Routes.php:278-279`). | — | S |
| PB-5 | Check which guest-link entitlement production uses. | The built-in Basic plan says external guests are off (`EffectivePolicyResolver.php:529`). The platform-defaults seeder that `docs/DEPLOYMENT.md:278-282` tells you to run says they are on, with a 120-minute cap (`RemotePlatformDefaultsSeeder.php:29`). Whether guest links work depends on which row exists. | `remote_entitlements` row with `company_id IS NULL` | S |
| PB-6 | A Mac build that Gatekeeper will open, and Accessibility granted on that Mac. | Without Accessibility, macOS drops every injected event (`macos/permissions.rs:3-9`). An ad-hoc-signed build has to be allowed in Privacy & Security (`docs/desktop/PUBLISHING.md:236-238`). | — | S |

### 5.2 What to tell the pilot group

| # | Caveat | What testers will see | Where |
|---|---|---|---|
| C-1 | **No UAC and no administrator windows.** The app runs at the user's own level, so Windows refuses input sent to higher-privileged windows (Task Manager, installers, admin consoles). The UAC prompt runs on the Secure Desktop. | The helper's clicks do nothing in those windows, and nothing says why. During a UAC prompt the picture freezes or goes black. The person at the computer has to answer it. | `windows-app-manifest.xml:18`; `windows/input.rs:424-431`; `agent.rs:1014-1018`; `companion.rs:865-868`; `secure_desktop_state()` is defined at `windows/capture.rs:77-89` and never called |
| C-2 | **Control needs the entire screen.** In the app, the Start page chooses "Entire screen" for you. In Chrome or Edge it chooses Safe Share (a browser tab). Either way, the room's **Share** button then proposes Safe Share again, so check the share dialog and use **Change what to share** to pick **Entire screen** (P1-13). | "Control works only when you share your entire screen". | `StartSessionPage.tsx:35-37`; `SessionRoomPage.tsx:530`; `CompanionPanel.tsx:49-57` |
| C-3 | **No clipboard during control** (owner decision 3). | To pass text across, put it in the chat. The person at the computer can copy it from there. | `CompanionService.php:771`, `:805-820`; `docs/desktop/CONTROL_COMPANION.md:27-28` |
| C-4 | **Sessions end 60 minutes after they start, with no warning and no way to extend.** | The room just ends. Start a new session. | `CompanyPolicyDefaults.php:200`; `SessionService.php:391-434` |
| C-5 | **Files are 25 MB at most**, sent between the two browsers or portals. | Bigger files are refused before sending. | `Remote.php:248` |
| C-6 | **One screen at a time.** The helper cannot switch screens. | To change screens, stop sharing and share again. The companion matches the new screen. | `agent.rs:963-966`; `docs/desktop/CONTROL_COMPANION.md:46-48` |
| C-7 | **Special keys cannot be sent.** The helper's own computer catches the Windows key, Alt+Tab and Ctrl+Alt+Del. The web does not use the browser's keyboard-lock feature. | Those keys act on the helper's own computer. | No `navigator.keyboard.lock` in `web/src` |
| C-8 | **Use computers with one Windows user each.** The device identity belongs to the whole machine: its key is protected with machine-scope DPAPI, and every local user can modify the folder it is in. | On a shared PC, every Windows account is the person who registered it. | `service/storage.rs:14-33`; `hooks.nsh:119-125` |
| C-9 | **If you have registered more than one computer, pick the one you are sitting at.** The list shows all your online computers, and nothing checks that the one you pick is the one sharing. | The confirm prompt appears on the wrong computer, or the link expires after 5 minutes. | `CompanionService.php:100-118` |
| C-10 | **On a Mac, sharing from the app's Remote tab has not been tested.** Whether WKWebView can share the screen depends on the macOS version. If the Remote tab says this browser cannot share, use **Open in browser** and share from Chrome. On ad-hoc-signed builds, expect macOS to forget the Accessibility permission after an update. Turn it off and on again. | "Sharing unavailable", or an update after which input does nothing. | `docs/desktop/PORTAL.md:76-77`; `macos/permissions.rs:3-9` |
| C-11 | **Ignore "This computer's ID"** on the sign-in screen. A helper cannot use it. | — | `SignInScreen.tsx:43-54` |
| C-12 | **The office counts as one IP address for rate limits.** For example, 10 code attempts per minute are shared by the whole office. | Only matters if many people join at the same moment. | `Routes.php:278-279`; `RateLimitFilter.php:17-24` |
| C-13 | **The installers are unsigned.** You will see "Windows protected your PC" and a Gatekeeper warning. Antivirus or IT policy may block them. | Note every warning you see. | `docs/desktop/PUBLISHING.md:232-238` |
| C-14 | **The TURN relays are in India only** (Mumbai and Greater Noida). | A helper outside India is slower whenever the connection goes through a relay. | `docs/production/turn-production.remote.env:14`, `:20`, `:37` |

---

## 6. Public launch: findings

**A dependency, not a finding:** code signing. Windows needs the OV certificate (SSL.com, in validation). macOS needs a
Developer ID certificate and notarisation (`docs/desktop/PUBLISHING.md:183-196`). Signing unlocks four things:

* SmartScreen trust. With OV, that trust still builds up over time with download volume.
* The P0-1 signature check.
* `uiAccess` and the other elevation work in P1-2. These need a signed binary.
* macOS remembering the Accessibility permission across updates.

### 6.1 P0: must be fixed before any public launch

**P0-1. The update path trusts the download host completely.** **M** (the check is ready to switch on once signing exists)

* *Why it matters.* The app reads `manifest.json` from `downloads.aicountly.com` and downloads the installer it names
  from the same host. It checks the size and SHA-256 that the same manifest gives, then runs the file (`update.rs:23-27`,
  `:36-40`, `:400-420`; `commands/mod.rs:748-756`). Anyone who can change files on that one host can make every installed
  copy run their program. This product installs a LocalSystem service, and the app would tell the person the update was
  verified.
* *Fix.* Before `open::that`:
  * On Windows, check the Authenticode signature (`WinVerifyTrust`) and that the signer is "Aicountly Interactive
    Services Private Limited".
  * On macOS, check the code signature and Team ID of the app inside the disk image.
  * Sign the manifest itself with an offline Ed25519 key built into the app, so that one host can no longer vouch for
    itself.

**P0-2. No Terms, no Remote privacy notice and no EULA.** **M** to write, with counsel. **S** to put in place.

* *Why it matters.* The only legal link anywhere is the general privacy policy, and it is reached only from the analytics
  banner (`AnalyticsConsent.tsx:11`, `:50`). The installer has no licence page (`tauri.conf.json:60-67` sets none), and
  the app links to nothing. A remote-control product needs, at minimum:
  * terms that cover acceptable use and abuse;
  * a notice that says what Remote stores: IP addresses, user agents, chat text, file names and device details;
  * a grievance contact.
* *Fix.* Add Terms, privacy and licence links in the web footer, on the app's sign-in screen and in About, and as a licence
  page in the NSIS installer. Ask for acceptance at the first session.

**P0-3. Data is never deleted on a schedule, and personal audit rows can never be deleted.** **M**

* *Why it matters.* Remote keeps a lot of personal data:
  * chat text (`ChatService.php:61-68`);
  * IP addresses and user agents on sessions, participants, audit rows, devices and sign-in codes (`AuditService.php:192-193`;
    the `ip` and `user_agent` columns in the migrations);
  * file names and session history.

  None of it is deleted on a schedule. The only jobs are the company lifecycle and the TURN probe (`jobs.txt`).
  `docs/DATABASE.md:222` promises chat its "own retention", but nothing implements it. Closing an account deletes the
  person's own personal sessions and devices, and anonymises their identity row (`CompanyLifecycle.php:230-283`). Two
  things survive it:
  * The audit rows of personal sessions. The trigger lets only the company purge delete audit rows
    (`docs/DATABASE.md:202-207`).
  * A helper's name, IP address and chat lines inside *other people's* sessions.
* *Fix.* Set a retention period for each kind of data and add a nightly purge job. Delete or anonymise personal audit rows
  after the retention period and when the account closes. Keep the 180-day logs that CERT-In requires (§10).

**P0-4. No handling of abuse or scams.** **M**

* *Why it matters.* Remote-control tools are the first tool in tech-support fraud. Today the host sees only the helper's
  display name and the words "AICOUNTLY user" (`SessionRoomPage.tsx:412-418`). There is no warning, no way to report
  someone and no way to block them.
* *Fix.*
  * Warn people the first time they grant control: "Only allow people you know. AICOUNTLY will never ask you to."
  * Show the helper's verified e-mail address.
  * Add "Report this person" in the room and on the session-ended screen.
  * Let support suspend an account on the server side, and publish an abuse contact.

**P0-5. Operations are not ready for the public.** **S** for CI. **M** for monitoring and backups.

* *Why it matters.*
  * Production deploys skip the test suite unless someone ticks a box (`deploy-production.yml:19-22`: `run_ci_gate`
    defaults to false).
  * CI no longer runs on pull requests (`ci.yml:16`), although the README still says it does.
  * This repository shows no external uptime monitoring or paging for the API, signalling or TURN.
  * Signalling is a single process that keeps its rooms in memory (`signalling/src/rooms.js:18`,
    `signalling/src/server.js:122`) on one cPanel server (`docs/DEPLOYMENT.md:312-325`).
  * No database backup or restore test is documented here.
  * CERT-In requires a cyber incident to be reported within 6 hours, and you cannot report what you do not see.
* *Fix.*
  * Run CI on every pull request, and make the deploy gate default to on.
  * Add uptime checks on `/api/health`, `/signal/health` and the TURN probe, with alerts that reach a person.
  * Take nightly PostgreSQL backups and test a restore.
  * Publish a status page, and write a one-page incident runbook.

**P0-6. Positioning: say what it does and does not do.** **S** (a decision and the wording)

* *Why it matters.* There is no unattended access, no UAC support, no picture from the app, no control of Linux and no
  mobile app (§4). Marketing that implies it matches AnyDesk or TeamViewer would mislead people, and they would find out in
  their first support call.
* *Fix.* Either launch as "help in the browser, no install for the helper", with a fair comparison, or wait until P1-1 to
  P1-3 are built.

**P0-7. Pricing and free-tier limits are not decided, and TURN admission only observes.** **M** for the decision and the limits. **L** with billing.

* *Why it matters.*
  * Entitlements fall back to a built-in Basic plan or a seeded row (`EffectivePolicyResolver.php:525-535`,
    `RemotePlatformDefaultsSeeder.php:29`).
  * The central licensing backend does not exist yet (`docs/LAUNCH_READINESS_TURN.md`, rows B4 and B8).
  * TURN admission is `observe`: it keeps books and refuses nobody, and its capacity figures are placeholders
    (`turn-production.remote.env:10-12`, `:27-30`).
  * The same two relays carry Connect and the sandbox (`:27-28`). A free tier with no limits lets a few heavy users, or
    abusers, use up what both products rely on.
* *Fix.* Decide what is free. Enforce session limits per account. Measure relay capacity (runbook F1–F3), then switch
  admission to `enforce`.

**P0-8. TURN relays outside India.** **M** per region. P0 only if you market outside India; otherwise P1.

* *Why it matters.* Both relays are in India (`turn-production.remote.env:14`). When a relay is needed, a user in Europe,
  the Gulf or North America goes round the world.
* *Fix.* Add a relay where the users are, before marketing there.

**P0-9. Analytics loads before consent.** **S**. P0 if you serve the EU; otherwise P1.

* *Why it matters.* Before anyone answers the banner, GA4 already loads "cookieless" (`analytics.ts:44`). It still sends
  pings to Google, with the IP address and user agent. The banner says "nothing that identifies you"
  (`web/src/components/AnalyticsConsent.tsx:38`).
* *Fix.* Set `ANALYTICS_BEFORE_CHOICE = 'none'` and soften the banner's wording.

### 6.2 P1: needed to compete credibly (the first quarter after launch)

| # | Finding | Why it matters | Where | Effort |
|---|---|---|---|---|
| P1-1 | **A video encoder in the Windows app** (Media Foundation H.264, or libvpx). | Without it the app cannot host. Everything users of other products expect depends on it: unattended access, one app instead of a browser plus an app, and later the Secure Desktop. | `session.rs:34-39`; `docs/desktop/ARCHITECTURE.md:228-233` | XL |
| P1-2 | **UAC, elevated windows and the Secure Desktop.** First, *tell* the helper when the computer is on the Secure Desktop or when input to a window was refused (send `control_paused` with a reason). Then support it properly, through the service on the Secure Desktop or with `uiAccess`. Both need signing. | IT support often means installing software, and installing software means UAC. | `secure_desktop_state()` is never called (`windows/capture.rs:77-89`); `Handled::Failed` is ignored (`companion.rs:865-868`) | S to tell the helper; XL for real support |
| P1-3 | **Unattended access to your own computers**: off by default, switched on deliberately, with 2FA. | People expect AnyDesk-style "reach my own PC". Today it is off for personal accounts and has no picture. | `CompanyPolicyDefaults.php:197`; `EffectivePolicyResolver.php:534` | L (after P1-1) |
| P1-4 | **Connect by computer ID, and an address book.** Make "This computer's ID" a real ID that a helper can type to *ask* for a session, with the consent still at the computer. Or rename it "Key fingerprint" now. | Today the screen invites people to read out an ID that leads nowhere. | `SignInScreen.tsx:43-54`; `agent.rs:313-328` | S to rename; L to build |
| P1-5 | **Clipboard during companion control**: text, both ways, only when ticked. | Pasting a command or a URL is everyday support work. | `CompanionService.php:771`; `docs/desktop/CONTROL_COMPANION.md:27-28` | M |
| P1-6 | **Session continuity.** Warn before the time limit and allow extending it. Bring a session back after the helped computer restarts. Add an idle timeout. | Today a session simply ends at 60 minutes, and nothing brings it back after a restart. | `CompanyPolicyDefaults.php:200`; `SessionService.php:391-434`; the app's own reconnect window is 30 seconds (`session.rs:85-86`) | M |
| P1-7 | **Crash reporting, and "Send logs to support".** | The logs exist only on the computer, at most 2 × 2 MB. A crash only writes a line to the log. The web reports no errors at all. | `logging.rs:19-22`, `:38-47`, `:131-138` | M |
| P1-8 | **The web after a deploy.** Each deploy deletes the old hashed files (`rsync --delete`), and a request for a missing file is answered with `index.html`. Every page is loaded lazily, and the router has no error screen of its own. So a portal left open across a deploy, including the one inside the app, shows React Router's default error page the next time someone moves to another page. | Anyone with the portal open meets that error page after every deploy. | `deploy-production.yml:345`; `web/public/.htaccess:28-36`; `router.tsx:19-35`, `:41-75` | S |
| P1-9 | **Two-factor sign-in.** Confirm that the AICOUNTLY sign-in offers it, and require it for helpers and before unattended access. | Taking over a helper's account means reaching other people's screens. | Not in this repository | S–M (needs the portal team) |
| P1-10 | **A security contact.** SECURITY.md points to a contact that is never named. Add `/.well-known/security.txt`, a mailbox and a response time. | Researchers will look for remote-access tools. | `docs/SECURITY.md:305-308` | S |
| P1-11 | **Rate limiting.** It is counted per IP address, in a file cache. `proxyIPs` is empty. | Offices behind one IP share a budget, and the limit is not shared between servers. Key signed-in calls on the account instead, and use a shared store. | `RateLimitFilter.php:17-24`, `:73-79`; `Cache.php:25`; `App.php:195` | M |
| P1-12 | **Documents that now say the wrong thing** (see the appendix). | Support answers and legal wording will be taken from them. | Appendix | S |
| P1-13 | **The room's Share button ignores the Start page.** It always proposes the first mode the policy allows, which for a personal account is Safe Share. So "starts on Entire screen" inside the app stops at the Start page. Use the session's `requestedShareMode` instead. Fix this before the pilot if you can. | The most common reason control will "not work" in the pilot. | `SessionRoomPage.tsx:530`; `EffectivePolicy.php:126-131` | S |

### 6.3 P2: later

| # | Finding | Where | Effort |
|---|---|---|---|
| P2-1 | Recording, with consent from both sides. | `Remote.php:225` | L |
| P2-2 | Let the helper switch screens (`select_monitor` exists in the protocol but the web never sends it). | `remoteControl.ts:106`; `agent.rs:963-966` | M |
| P2-3 | File transfer: a higher cap, folders, resuming, and sending from the app itself. | `Remote.php:248`; `docs/desktop/ARCHITECTURE.md:234-236` | M |
| P2-4 | Wake-on-LAN, restart and reconnect (the restart API exists, but nothing calls it). | `api/remote.ts:512-518` | M–L, after P1-1 and P1-3 |
| P2-5 | Mobile apps, and a companion for Linux. | `update.rs:147-161` | XL / L |
| P2-6 | Languages (Hindi first) and an accessibility audit to WCAG 2.2 AA. The only language today is `en`. | `backend/app/Language/`; `web/index.html:2` | L |
| P2-7 | Installer: a per-user option that needs no administrator rights, and more languages. It is per-machine and English-only today. | `tauri.conf.json:61-62` | M |
| P2-8 | Narrow the web CSP. `connect-src` allows any `https:` and `wss:` address. | `web/public/.htaccess:107` | S |
| P2-9 | Choose the right computer for the companion. Inside the app, pre-select the computer the portal is running on, and warn when there are several. | `CompanionService.php:100-118` | S–M |
| P2-10 | Fix the false message "The clipboard is shared in both directions". It only ever goes from the controller to the computer. | `ControlPanel.tsx:276-278`; `docs/desktop/ARCHITECTURE.md:237-239` | S |
| P2-11 | Shared PCs. Every Windows account on a PC acts as the person who registered it. Document this, or register per user for personal accounts. | `service/storage.rs:14-33`; `hooks.nsh:119-125` | M |
| P2-12 | Make "personal only" true on the server too. The API still accepts company scope, the Manage relay and the lifecycle receivers. Put them behind a switch, or refuse them. | `Routes.php:57`, `:66`, `:298-306` | S–M |
| P2-13 | A special-keys menu (Ctrl+Alt+Del, the Windows key, Alt+Tab) and keyboard lock in full screen. | `web/src` | S–M |
| P2-14 | Decide whether analytics should run inside the desktop app's portal. Today it does, banner included. | `analytics.ts` (no check for the app) | S |

---

## 7. Security and privacy

**What is good, and should stay:**

* Nobody gets a signalling token before the host admits them (`docs/SECURITY.md:13-16`).
* Control needs a confirmation at the computer and a grant in the browser for each request. The companion can stop control
  but never grant it (`docs/desktop/CONTROL_COMPANION.md:25-26`, `:120-121`).
* The input gate is local, and stopping needs no network (`docs/desktop/ARCHITECTURE.md:153-164`).
* Device keys are Ed25519, kept in DPAPI on Windows or the Keychain on macOS. Device credentials last 15 minutes
  (`Remote.php:98`), signalling tokens 2 minutes (`:78`), and TURN credentials are minted per session with HMAC
  (`:160-166`).
* The audit metadata is scrubbed of secrets. A session you may not see answers 404, not 403. The API headers are strict.
* Screen content and file contents are never stored.

**What needs work:**

* **Updates** (P0-1).
* **Not end-to-end against the operator.** Media is encrypted with DTLS-SRTP, but AICOUNTLY runs the signalling, so it
  could in principle insert itself into a connection. SECURITY.md says so (`docs/SECURITY.md:52-55`). Say it publicly too,
  and do not claim "end-to-end" in marketing.
* **Two-factor sign-in.** Unknown (P1-9).
* **Rate limiting** (P1-11).
* **The security contact** (P1-10).
* **The CSP** (P2-8).
* **Shared PCs** (P2-11).
* **A controller can click on Remote's own screens**: admitting someone to the session, accepting a file on the person's
  behalf, linking a device. The person can always take over, because local input pauses the helper and the shortcut is
  local. Still, consider ignoring remote input over Remote's own approval controls (P2).

**What Remote stores about people.** Names and e-mail addresses from the AICOUNTLY account, session metadata, participants,
IP addresses and user agents, chat text, file names and sizes, device names, operating-system versions, key fingerprints
and presence times. GA4 receives the origin and a masked path. Screen content and file contents are never stored.
Retention and deletion: P0-3. Consent for analytics: P0-9.

---

## 8. Reliability and operations

* **Hosting.**
  * The API (PHP) and signalling (a systemd unit) share one AlmaLinux/cPanel server (`docs/DEPLOYMENT.md:312-325`).
  * Imunify360 WebShield sits in front of it. It has already broken sign-in once
    (`docs/auth/SIGN_IN_WAF_INTERCEPTION.md`), so check desktop-app traffic against it whenever its rules change.
* **Signalling.**
  * It runs as one process and keeps its rooms in memory. Every deploy restarts it (`docs/DEPLOYMENT.md:322-324`).
  * Media keeps flowing peer to peer while signalling is down, but nothing new can connect or renegotiate.
  * A deploy-window guard exists (`scripts/ops/deploy-window.sh`). Keep production deploys outside office hours during
    the pilot.
* **TURN.**
  * Mumbai and Greater Noida run active-active, with fallback over TLS on port 443.
  * A probe checks them every 5 minutes (`jobs.txt`).
  * Admission only observes, and the capacity figures are placeholders (P0-7).
  * The relays are shared with Connect and the sandbox.
  * The end-to-end relay test is a manual workflow (`docs/E2E_TEST_PLAN.md`, stage 1).
* **Testing.**
  * The desktop release always runs desktop CI first (`release-windows.yml:83-88`).
  * Web and backend deploys do not, unless someone ticks the box (P0-5).
  * The native calls are compiled on a Windows runner. `desktop/tests/MANUAL.md:3-6` still says they have not been
    executed, and no completed manual pass is recorded. Your own runs since then have found real bugs (the app closing
    itself, `791de21`; the portal hanging Windows, `6ac04ef`), so treat the pilot as the first systematic pass.
* **Sessions** expire only when someone next reads them, with no warning (P1-6). There is no idle timeout.
* **Diagnostics.**
  * Each computer keeps its own logs (`%ProgramData%\AICOUNTLY\Remote\logs\agent.log`, `service.log`), and the path is
    shown in About.
  * There is no way to send the logs, and no crash reporting (P1-7).
  * The web has no error boundary (P1-8).
* **Unknown from this repository:** database backups, uptime alerting, who is on call, and where the API host and its logs
  physically are. The TURN documents mention an "OVH application server". Settle all four before launch (P0-5, and §10
  for CERT-In).

---

## 9. User experience

* **There are two places to act, and the app now explains them.** People share in the browser and confirm in the app. The
  "Getting help on this computer" card lists the three steps and the stop shortcut (`App.tsx:630`). In the pilot, watch
  whether people find **Enable control from this computer** (`CompanionPanel.tsx:172`) without help.
* **Defaults.** The app's Start page starts on "Entire screen", but a plain browser starts on Safe Share
  (`StartSessionPage.tsx:35-37`). In both, the room's Share button proposes Safe Share again (P1-13). When the web becomes
  personal-only, start on "Entire screen" whenever the person has the app.
* **Failures that say nothing.** Input refused by an elevated window, and the Secure Desktop (C-1, P1-2). This is the
  likeliest "it's broken" report in the pilot.
* **The things a helper misses most:** the clipboard (P1-5), special keys (P2-13), switching screens (P2-2), full screen
  (in parallel), and a session that stops at 60 minutes (P1-6).
* **Misleading wording:** "This computer's ID" (P1-4), "The clipboard is shared in both directions" (P2-10), and the
  analytics banner's "nothing that identifies you" (P0-9).
* **Trust signals.** The helper appears only by their display name (P0-4). Competitors show who is connecting and warn
  about scams.
* **Reach.** English only (P2-6). Phones can watch and chat only. Linux can be watched but not controlled.
* **Accessibility** is partial. There are ARIA roles and labels on the dialogs, but no audit has been done (P2-6).

---

## 10. Legal and compliance

Use this to brief counsel. It is not legal advice.

| Item | Status in the product | Needed before a public launch |
|---|---|---|
| Terms of Service | None anywhere: web, app or installer | Terms covering acceptable use, the ban on unauthorised access and scams, suspension and liability. Accept them at the first session (P0-2). |
| Privacy notice | Only the general `aicountly.com/privacy-policy`, linked from the analytics banner (`AnalyticsConsent.tsx:11`) | A section for Remote: each kind of data in §7, why it is kept, for how long, who processes it (Google Analytics, the hosting provider, the TURN hosts), and the person's rights. Link it from the web, the app and the installer. |
| EULA | No licence page in the NSIS installer (`tauri.conf.json:60-67`) and nothing in the app | A licence for the desktop app, shown in the installer and in About. |
| DPDP Act 2023 (India) | Analytics consent only. No grievance contact. No retention. Erasure only when the account closes, and only partly (P0-3). | A notice and consent that list each kind of data and its purpose. A way to withdraw consent. A grievance contact. Erasure once the purpose is served. Reasonable security safeguards. Telling the Data Protection Board and the people affected about a breach. Verifiable parental consent for anyone under 18 (check with the portal team how sign-up handles age). Processor contracts. The DPDP Rules 2025 bring in most obligations in stages; confirm the dates with counsel. |
| IT Act 2000 and IT Rules 2021 | Remote passes chat between users, so it is probably an intermediary (counsel to confirm). | Published terms and privacy policy, and a named grievance officer. Acknowledge a complaint within 24 hours and resolve it within 15 days. |
| CERT-In Directions (28 April 2022) | No incident process or log policy in this repository | Report a cyber incident within 6 hours. Keep ICT logs for 180 days in India. Synchronise clocks to NTP. Fit this with DPDP deletion (P0-3), and confirm where the hosts are. |
| GDPR, only if you offer the service in the EU | GA4 loads before consent (`analytics.ts:44`) | Consent before analytics (P0-9). An Article 13 notice. A lawful basis for each purpose. Processor agreements. An EU representative (Art. 27), unless exempt. A transfer mechanism. A process for access and deletion requests. |
| Advertising and comparisons | — | Comparisons with named competitors must be accurate (P0-6), and the competitor columns in §4 must be verified first. |

---

## 11. Office-pilot test script

**You need:**

* **PC-1**: Windows 10 22H2 or 11, the computer being helped, signed in as account **A**.
* **PC-2**: Windows, the helper's computer, account **B**. A browser is enough here.
* **Mac**: macOS 13 or later, being helped, account **C**.
* All three accounts are personal AICOUNTLY accounts.
* Use computers that have one Windows user each.
* One person sits at each computer, and you can talk to each other by phone.
* If possible, have one laptop with display scaling at 125–150% and one computer with two screens.
* Write down the build number, the computer and the time for every result.

"Pass" describes what you should see. Anything else is a fail: write down exactly what appeared.

**A. Install and check the build (on every computer)**

1. **Download** the installer for this release.
   *Pass:* the file name contains `-b` and 12 digits, for example `Aicountly-Remote-1.0.0-b202610300716-x64-Setup.exe`, or
   `…-arm64.dmg` / `…-x64.dmg` for a Mac.
2. **Windows: run the installer.** On "Windows protected your PC", click **More info**, then **Run anyway** (expected while
   the app is unsigned). Answer **Yes** to the Windows prompt. Finish with "Run AICOUNTLY Remote" ticked.
   *Pass:* the window opens and the tray icon appears.
   *Fail:* antivirus or IT policy blocks it. Record the exact message.
3. **Mac: install.** Open the `.dmg`, drag AICOUNTLY Remote to Applications and open it. If macOS refuses, go to System
   Settings → Privacy & Security, click **Open Anyway**, then open the app again.
   *Pass:* the window opens and the menu-bar icon appears.
4. **Open App settings → About.**
   *Pass:* Version is 1.0.0. Build shows the same 12 digits as the file name, not "Local build". Platform is correct.
5. **Close the window and reopen it**: from the Start menu on Windows, or by clicking the Dock icon on the Mac.
   *Pass:* the window comes back. On the Mac, this is fixed in this release.

**B. Sign in**

6. **Sign in.** Click **Sign in to AICOUNTLY** and sign in inside the window (or choose **Open in browser instead**).
   Check that the code shown matches, then confirm.
   *Pass:* within a few seconds, This computer shows **Online**. The **Remote** tab opens the portal, signed in, with no
   organisation switcher.
7. **Mac only: Accessibility.** When the app asks, go to System Settings → Privacy & Security → Accessibility and turn on
   AICOUNTLY Remote.
   *Pass:* the app's permissions card no longer warns about input.
8. **The helper (PC-2).** Open `https://remote.aicountly.com` in Chrome or Edge and sign in as B.
   *Pass:* the dashboard appears.

**C. Start a session and share the entire screen (PC-1 is helped, PC-2 helps)**

9. **PC-1: start a session.** In the Remote tab, click **Start session** in the menu (or **Share my screen** on the
   dashboard). Check that **Entire screen** is selected, then click **Create session**.
   *Pass:* the session room opens and shows a 9-digit code.
10. **Join.** Read the code to B. On PC-2, open **Join session**, type the code and click **Join session**.
    *Pass:* PC-2 shows "Waiting for the host to admit you".
11. **PC-1: admit B.** Where the room says someone wants to join, click **Allow**.
    *Pass:* B is in the room and is labelled "AICOUNTLY user".
12. **PC-1: share.** Click **Share** in the room's toolbar. The dialog "You're about to share your screen" opens. If it
    does not say Entire screen, click **Change what to share** and choose **Entire screen** (it proposes Safe Share today:
    P1-13). Click **Continue to choose screen**, then pick the whole screen in the picker (in Chrome: the **Entire
    screen** tab, the screen, then **Share**).
    *Pass:* a "You're sharing · Entire screen" banner appears. Within about 5 seconds PC-2 shows PC-1's screen, and the
    text is readable.

**D. Enable control from this computer**

13. **PC-1: link the computer.** Click **Control** in the toolbar, then **Enable control from this computer**, then
    **Use \<PC-1's name\>**. If more than one computer is listed, pick the one you are sitting at.
    *Pass:* the panel says "Confirm on \<PC-1\> to continue".
14. **PC-1: confirm in the app.** The AICOUNTLY Remote window comes to the front and asks "Let people in session … control
    this computer while you share it?". Click **Allow while I share**. If it asks which screen you are sharing, pick it.
    *Pass:* the web panel says "Ready. \<PC-1\> will carry control you allow." The app says "This computer can be
    controlled while you share".

**E. Request, grant and use control**

15. **PC-2: ask for control.** Once PC-1 is ready, a **Control** button appears in PC-2's toolbar. Click it, then
    **Request control**.
    *Pass:* PC-2 shows "Waiting for A to answer".
16. **PC-1: grant it.** The **Control** button on PC-1 shows a badge. Open it, and where it says "B is asking to control
    this computer", click **Allow control**.
    *Pass:* PC-2 shows "You are controlling this computer". The AICOUNTLY Remote window on PC-1 says "B is controlling
    this computer".
17. **PC-2: use PC-1.**
    * Open Notepad on PC-1 and type `Test é € 123`.
    * Select the text and right-click it.
    * Scroll a long web page.
    * Move the pointer into all four corners of the screen.

    *Pass:* the text appears exactly as typed. Clicks land where B points, corners included. The right-click opens PC-1's
    own menu. Any delay feels shorter than half a second.
18. **PC-1: take over.** Move your own mouse while B is moving theirs.
    *Pass:* PC-2 shows "Paused: someone is using that computer", and the app on PC-1 says "Paused while you use this
    computer". B's control comes back about 2 seconds after A stops.
19. **Known limit: check it, do not try to fix it.**
    * From PC-2, open Task Manager on PC-1 and click in it.
    * Then start something that brings up a UAC prompt, for example **Run as administrator** on Windows Terminal.

    *Pass (meaning "behaves as expected"):* B's clicks do nothing in Task Manager. During the UAC prompt, PC-2's picture
    freezes or goes dark. A has to answer the prompt on PC-1. Write down what B saw.
20. **Scaling and second screens** (if you have them). On the scaled laptop, and on a computer with two screens sharing the
    second one, repeat the corner test from step 17.
    *Pass:* clicks land where B points.

**F. Stop control**

21. **PC-1: press Ctrl+Alt+Shift+S** (on a Mac, ⌘⌥⇧S).
    *Pass:* control ends at once. PC-2 says control has ended, and moving B's mouse no longer moves PC-1's pointer. The
    control banner on PC-1 disappears. If the shortcut does nothing, note it, and use **Stop control** in the app or the
    tray icon instead.
22. **Stop from the helper's side.** B clicks **Request control** again and A clicks **Allow control**. Then B clicks
    **Stop controlling**.
    *Pass:* control ends cleanly, and the picture continues.

**G. Files and chat**

23. **B sends a file to A.** On PC-2 open **Files**, choose **Send to** A, then **Choose a file** (a PDF of about 5 MB).
    On PC-1 click **Accept**, then **Save**.
    *Pass:* the saved file opens and is the same size as the original.
24. **A sends a file to B.** Repeat step 23 the other way round.
    *Pass:* the same as step 23.
25. **Try a 30 MB file.**
    *Pass:* it is refused before anything is sent, with a message that the most you can send is 25 MB.
26. **Chat.** Each person sends a message in **Chat**.
    *Pass:* each message arrives within a second or so, and both people see the same history.

**H. End, then repeat elsewhere**

27. **PC-1: click End.**
    *Pass:*
    * PC-2 is told the session has ended.
    * The PC-1 app shows no session and no control.
    * **Sessions** lists the session, with its timeline.
28. **Repeat C to H with the Mac as the computer being helped** (account C), with B helping. Share from the app's Remote
    tab. If it says sharing is not available, choose **Open in browser** and share from Chrome. On the Mac, the shortcut is
    ⌘⌥⇧S.
    *Pass:* the same as on PC-1. Write down which way the screen was shared.
29. **Repeat steps 9–17 from another network**, with B on a phone hotspot or at home.
    *Pass:* the picture appears within about 10 seconds. Write down what the session's **Security** panel says about the
    connection.
30. **Optional: leave a session running past 60 minutes.**
    *Expected today:* it ends at 60 minutes, with no warning. Write down when it ended.
31. **After the parallel fixes land: sign out and uninstall.**
    * Click **Sign this computer out**.
    * Then uninstall the app.

    *Pass:*
    * The web's Computers page no longer shows the computer as usable.
    * After the uninstall, the folder `C:\ProgramData\AICOUNTLY\Remote` is gone.
32. **When a newer build is published, open the app.**
    *Pass:*
    * The app offers "1.0.0 (build …)", or a newer version, with a higher build number than yours.
    * After installing it, About shows the new build.

**Results sheet.** Copy this table and fill in one row for each step:

| Step | PC-1 | Mac | Network | Build | Pass or fail | What you saw |
|---|---|---|---|---|---|---|

---

## 12. Roadmap

### The next two weeks: pilot, and the quick P0/P1 fixes

| When | What |
|---|---|
| Days 1–2 | Fix the room's Share button so it keeps the Start page's choice (P1-13). Get your go-ahead and deploy this release's backend and web (PB-1). Publish numbered Windows and Mac builds (PB-2). Check the guest-link entitlement row (PB-5). Land the parallel fixes or put them off explicitly (PB-3). |
| Days 2–5 | Run the pilot script (§11) with 2 PCs and a Mac. Then run it with 5–10 people doing real help sessions for a week. Fix what they find. |
| Week 1 (S items) | Turn CI back on for pull requests and make the deploy gate default to on (P0-5). Load no analytics before consent (P0-9). Add an error screen, and keep the previous deploy's files for a day (P1-8). Rename "This computer's ID" (P1-4). Tell the helper when input was refused or the Secure Desktop is showing (P1-2, first step). Correct the clipboard message (P2-10). Publish a security contact (P1-10). Correct the documents in the appendix (P1-12). |
| Week 2 | Start the Terms, privacy notice and EULA with counsel (P0-2). Write the retention schedule and the purge job (P0-3). Design the scam warning and reporting (P0-4). Set up uptime monitoring, alerts and a backup restore test (P0-5). Decide pricing and the free tier (P0-7). Write the update signature check, ready to switch on when the certificate arrives (P0-1). |

### The next quarter

| Month | What | Result |
|---|---|---|
| 1 | Finish every P0. Measure TURN capacity, then switch admission to `enforce` when the licence source allows it (P0-7). Add a relay outside India if you will market there (P0-8). Warn before the 60-minute limit and allow extending it (P1-6). Rate limiting per account (P1-11). | **Public launch possible**, positioned as attended help in the browser (P0-6). |
| 1–3 | The video encoder in the Windows app (P1-1). Then UAC and the Secure Desktop through the signed service (P1-2). Then unattended access to your own computers, with 2FA (P1-3, P1-9). | Matches the competitors on the features users look for first. |
| 2–3 | Connect by ID and an address book (P1-4). Clipboard during companion control (P1-5). Reconnecting after a restart (P1-6). Crash reporting and log upload (P1-7). | Fewer support tickets, and a smoother experience for helpers. |
| Later | Recording, Wake-on-LAN, restart, mobile apps, Linux, languages, a per-user installer (P2). | — |

---

## Appendix: documents that now say the wrong thing

Fix these with P1-12. People will quote them.

| Document | What it says | What is true now |
|---|---|---|
| `README.md` ("Tests") | `ci.yml` runs on every pull request and push to `main`. | It runs only on demand, or when a deploy calls it (`ci.yml:16`). |
| `README.md` ("What it deliberately does not do") | "macOS and Linux agents are not built." | macOS is a control companion (`docs/desktop/ARCHITECTURE.md:106-113`). |
| `docs/SECURITY.md:56-59` | Control requires AICOUNTLY Remote for Windows at the other end. | A browser share can be controlled through the person's own app, on Windows or Mac. |
| `docs/desktop/CONTROL_COMPANION.md:56`, `:60`, `:66` | A personal session has no companions (`PERSONAL_SCOPE`). | Personal sessions take personal companions. Only other scopes answer `COMPANION_SCOPE` (`CompanionService.php:958-974`). |
| `docs/desktop/ARCHITECTURE.md:240-241` | Automatic updates are deliberately inactive. | The app checks for updates when it starts and offers to install them (`update.rs`; `commands/mod.rs:722-770`). |
| `backend/app/Config/Remote.php:131` | "the agent's signed update manifest" | The manifest is not signed (P0-1). |
| `docs/DATABASE.md:222` | Chat has its own retention. | Nothing deletes chat (P0-3). |
