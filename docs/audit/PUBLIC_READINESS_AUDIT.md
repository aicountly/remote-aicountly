# AICOUNTLY Remote: public-readiness audit

| | |
|---|---|
| Date | 14 November 2026. This is the third pass: the first was 30 October, brought up to date on 1 November. |
| Written for | The owner |
| Asked for | A re-run of the audit covering four things — taking control of a person's computer, how friendly and easy the screens are, speed, and how far it scales — and a fix for every gap and leftover that could be fixed. |
| Code audited | Two things, kept apart throughout. **Live:** `main` at `972a9fb` (PR #116), deployed to production on 13 November and built into desktop build `202611132211`. **Not live:** branch `claude/nifty-ramanujan-mic1cd`, the commits listed in §2.1, which is where everything this pass fixed is. Nothing on the branch has been merged, deployed or released. |
| Scope | Personal AICOUNTLY accounts only. By the owner's decision, Remote has no link to companies or organisations for now. Code signing is left out, except where something depends on it. |
| How it was checked | By reading the code; by five separate inspections (every earlier finding re-checked against `972a9fb`; the server's request load; a load test of the signalling relay; every screen of the web app, and of the desktop app's window, drawn in a real browser at desktop and phone size and photographed); and by the automated tests, run on one Linux machine. **Nothing was run on a real Windows PC or Mac.** §0 says what that leaves unproven. |
| Effort | **S** = up to 2 days · **M** = up to 2 weeks · **L** = up to 6 weeks · **XL** = longer, or a dependency decision is needed first |
| Competitors | The AnyDesk, TeamViewer and UltraViewer columns come from general knowledge, **as commonly advertised. Verify before quoting publicly.** |

Statements about the code cite the file and, where it helps, the function. Line numbers are given only where the
earlier re-check recorded them against `972a9fb`; a file this pass changed is cited by name and function instead.

---

## 0. Status at a glance (14 November 2026)

### Live now

* **Production** runs `972a9fb` (deploy run 67, 13 November 22:13 UTC). Its test gate was switched off at the owner's
  request; the suites were run locally first.
* **The desktop app**: GitHub release `desktop-v1.0.0-beta-b202611132211` holds the Windows and Mac build
  `202611132211`. **Both are unsigned.** `publish-downloads.yml` was not run in this work, so check on the downloads page
  which build it offers.
* That release's notes mention only the Mac build. The Windows installer in it is unsigned too, and its warning was
  written over (fixed for future releases, §2.1; this one can be corrected by hand).

### Fixed in this pass — on the branch, not live

| Area | What was wrong | Now | Commit |
|---|---|---|---|
| Control | When Windows refused the helper's clicks (a window running as administrator in front), the helper was told nothing: control read as granted at both ends and nothing moved. | The helper's room says why, above the picture, within a second. | `34c07c6` |
| Control | Ctrl+Alt+Del could not be sent at all. Nor could the Windows key, Alt+Tab or Task Manager outside full screen, or in Firefox and Safari. | A **Keys** menu in the room sends them. When Ctrl+Alt+Del cannot be sent, the helper is told which of two reasons applies. | `b08100f`, `9c397c7` |
| Control | With the clipboard shared, Ctrl+V pasted what was already on the computer, not what the helper had copied. | The helper's text is sent ahead of the paste key. | `9c397c7` |
| Control | The room treated every computer as a Windows PC talking to a Windows PC. A Mac helper's ⌘C and ⌘V reached Windows as the Windows key and a letter; a PC helper's Ctrl+C did nothing on a Mac; the Keys menu sent Windows shortcuts to a Mac. | ⌘ and Ctrl change places between the two kinds of keyboard, and a Mac gets a Mac's Keys menu. | `b1797f0` |
| Control | A Mac's picture had no mouse pointer in it, and a Mac whose screen was not changing still converted and encoded every frame. | The pointer is drawn into the picture; a still screen costs almost nothing. | `34c07c6` |
| Control | The Mac app offered "answer Windows administrator prompts" and other Windows-only settings. | Not offered on a Mac. | `3a29d6e` |
| Control | If the person sharing closed their browser, the app carrying control never told the server it had left. The computer stayed "busy" for up to an hour. | The app leaves, and a job on the server closes any session nobody came back to. | `54dfcfe` |
| Desktop window | The Allow / Not now buttons could be below the bottom of a window that cannot scroll. Every failure read "Something went wrong." Stop control was greyed out while anything else was loading. Nothing warned about scams. | Fixed: of the 16 defects found, 14 outright and 2 partly (§7.2). | `3a29d6e` |
| Web app | A request to control your computer was a "1" on a toolbar button. A declined helper waited for ever. An error looked like an empty list. Primary buttons failed the contrast test. Half the room's toolbar was off screen on a phone. | Fixed: of the 18 defects found, 14 outright and 3 partly; 1 is an owner decision (§7.3). | `9c397c7`, `2ce447a`, `ee7ac93`, `4332973` |
| Scale | Rate limits were counted per office address: 60 PCs, or 4 help sessions, in one office used up a budget. Every idle PC booked relay capacity it never used. An outage of Console became an outage of Remote. Sessions were never closed unless somebody looked at them. | Limits follow the computer or the person. Idle PCs book nothing. Console's last answer is reused while it is down. A five-minute job closes overdue sessions. | `a135c92`, `8f1c9ad`, `465108f`, `54dfcfe` |
| Relay | One malformed request crashed the signalling relay and dropped everybody (it could be sent only from the server itself, not through the public address). One slow reader cost 400 MB in a minute. A burst of ordinary messages got a helper disconnected. Nothing capped connections. | All six reproduced defects fixed, and re-measured (§9.3). | `810688f` |
| Deploy | A deploy deleted the files the previous version of the web app was still loading. | Kept for 30 days. | `5758e4f` |
| Documents | The README and eight other documents described a Windows-only, companion-only product with test counts from several releases ago. | Corrected (Appendix A). | `ab638b5`, `822cdac` |

### Still to do before a public launch

The P0 list in §6.1 has not got shorter. Each item needs a decision, a certificate or somebody outside this repository
before code can close it:

1. A signed update path (P0-1) — waits for the certificates.
2. Terms, a privacy notice for Remote and an EULA (P0-2) — counsel.
3. A retention schedule and the job that deletes on it (P0-3) — needs the schedule decided.
4. Reporting and suspending somebody who abuses it (P0-4). The warnings are now in place; the rest is not.
5. Operations: monitoring, backups, a second server (P0-5).
6. Positioning (P0-6), pricing and relay admission (P0-7).
7. Analytics without consent (P0-9), which has grown: a second pipeline now records every signed-in page view whatever the
   person answered.
8. **A recorded test on real machines.** Listed separately below because it is the largest risk in this document.

### Not proven on a real computer

Every item below is built and passes its tests on Linux, and the Windows code is checked by a Windows compiler. None
has been run on a real computer as part of this work, and no completed manual pass is recorded anywhere in the
repository.

* **Everything PR #116 changed** (live now): the session surviving past two minutes, control arriving within a second,
  keyboard shortcuts, two screens, sound. `desktop/tests/MANUAL.md` §4a–4c.
* **Everything this pass changed in the desktop app**: the refused-input notice, Ctrl+Alt+Del through the service
  (`SendSAS` and the registry read that decides whether it may be sent), the Mac's pointer in the picture, the window's
  new layout. `MANUAL.md` §4d–4f.
* **The two-keyboard swap** between a Mac and a Windows PC, in both directions (`MANUAL.md` 4d.10, 4e.8–4e.10).
* The earlier list still stands: the Secure Desktop helper on Windows 10 and 11, the Cisco codec download behind a
  proxy, hosting and unattended access on a Mac, macOS 15's repeated Screen Recording question, two-step verification
  with a real authenticator.

The first thing to do with this audit is to run `desktop/tests/MANUAL.md` on one Windows PC and one Mac and write down
what happened. It is about half a day.

### Decisions only the owner can make

| # | Decision | Why it is yours |
|---|---|---|
| 1 | Merge the branch, deploy, restart signalling, release a new desktop build, publish it. | Nothing above is live until this is done (§14). |
| 2 | Signing: the Windows OV certificate (in validation) and an Apple Developer ID. | Blocks P0-1, SmartScreen and Gatekeeper trust, and elevated windows (P1-2b). |
| 3 | Terms, privacy notice, EULA, retention periods. | Counsel (§12). |
| 4 | Analytics: whether Google Analytics may load before the person chooses, and whether AICOUNTLY's own analytics may run without a choice at all. | Law, not code. One switch each (P0-9). |
| 5 | Whether an installer may set the Windows policy that lets Ctrl+Alt+Del be sent. | It changes a security setting on somebody's PC. Today nothing does, and Ctrl+Alt+Del works only where the policy already allows it. |
| 6 | Whether what is copied on the helped computer may travel to the helper. | The consent dialog promises it does not. Built one way only. |
| 7 | Whether the web's Start page should begin on **Entire screen** instead of Safe Share. | Safe Share is the private default; Entire screen is the only one that can be controlled. A sentence now says so (§7.3). |
| 8 | Which tab the desktop app opens on: **Remote** (today) or **Get help**. | Left as designed. |
| 9 | Pricing, the free tier, and switching relay admission from watching to refusing. | P0-7. |
| 10 | A security contact address. | P1-10. |
| 11 | Six facts about the server that cannot be read from the code (§9.6). | They decide the real capacity. |

---

## 1. Verdict

### Scorecard

| What was asked | Verdict | In one sentence |
|---|---|---|
| Taking control of a person's computer | **Ready for the pilot. Not proven on real machines.** | Mouse, keyboard, shortcuts, two screens, sound, Ctrl+Alt+Del, paste and both keyboards are built and tested in code on Windows and Mac; what is missing is somebody trying them on real ones. |
| Friendly screens | **Good, after this pass.** | 34 defects were found across the desktop window and the web app; 28 are fixed outright, 5 partly, and 1 is an owner decision. |
| Easy to use | **Good for the common path, fair on a phone.** | Being helped is 7 clicks after sign-in and helping is 2 clicks and nine digits; a phone can join, watch and chat but not share. |
| Speed | **Good.** | The web app is usable in under a second, the relay adds under a millisecond, and the app encodes a 1080p screen inside its 33 ms budget on a modest 4-core machine. A 4K or Retina screen manages about 21–23 frames a second. |
| Scale | **Hundreds of sessions at once on today's single server; not thousands.** | The relay is not the limit — it held 19,900 connections in 200 MB. The API is, because the room asks it for news every few seconds. What stood in the way of the first few hundred is fixed; what stands in the way of the first few thousand is listed in §9.5. |
| Security | **Sound design, two open gaps.** | Consent, the local stop and short-lived credentials are right; the update path trusts its download host (P0-1) and the installers are unsigned. |
| Legal and operations | **Not ready.** | No Terms, no retention, no monitoring, one server. Unchanged since 1 November. |

### Office pilot: go, with two conditions

1. **Merge the branch, deploy it, and install a build made from it** (§14). Otherwise the pilot tests screens this audit
   has already replaced.
2. **Run `desktop/tests/MANUAL.md` on one Windows PC and one Mac first**, before giving it to the pilot group.

Tell the pilot group these limits (details in §5.2):

* A window running as administrator (Task Manager, an installer) does not take the helper's clicks. The helper is now
  told so.
* A Windows administrator prompt, the Ctrl+Alt+Del screen and the lock screen can be answered only if the person ticked
  **Also let them answer Windows administrator prompts**, and only with the background service installed.
* Ctrl+Alt+Del works only on a PC whose Windows policy allows a program to send it. On an ordinary home PC it does not,
  and the room says so.
* The clipboard goes one way, helper to computer, on Windows only, and only when the person ticked it.
* A helper on a Mac cannot type a character that needs the Option key (`#` on a British keyboard, `@` on a German one)
  into a Windows PC.
* Unattended access needs two-step verification on the account.
* A session stops at 60 minutes. The room now warns five minutes before; it cannot be extended.
* Files can be at most 25 MB, and only between two browsers: the desktop app neither sends nor receives one.
* When the desktop app shares its own screen, one helper connects at a time, and there is no chat on that computer.
* The installers are unsigned, so Windows and macOS will warn about them.

### Public launch: not yet

Remote control itself is no longer the reason. The reasons are the eight items listed in §0, of which six need a
decision or somebody outside this repository, and one — the test on real machines — needs half a day.

**What is already strong:**

* Nothing is sent before the host admits a person.
* Control needs consent at the computer for each request. It stops locally, with no network round trip, from a button
  that is never disabled, a tray icon and a keyboard shortcut.
* The person is told who is asking, warned to allow only somebody they know, and shown what control means — in the app
  and, since this pass, in the browser.
* Screen content and file contents are never stored. When a relay is needed, it forwards them encrypted and cannot read
  them.
* Device keys never leave the computer. Device, signalling and TURN credentials are all short-lived.
* The helper is told the truth when something does not work, in words they can act on.
* 2,626 automated tests pass (§11).

---

## 2. What changed since the last audit

### 2.1 This pass — on the branch, not live

| Commit | What it does | Where |
|---|---|---|
| `34c07c6` | **The computer says when it refuses input.** A new message, `input_refused`, tells the helper's room why: a window running as administrator is in front, or the computer would not take a click. **A Mac's picture shows its pointer**, drawn in at the right size on a Retina display. **A still Mac screen is not converted and encoded again** for every frame; Windows stops allocating a new buffer per frame. (The commit message calls that buffer "WASAPI-era"; it belongs to Windows.Graphics.Capture. The code is right.) | `remote-protocol` (`InputRefusedReason`); `agent.rs`; `session.rs`; `companion.rs`; `platform/pointer.rs`; `platform/macos/capture.rs`; `platform/windows/capture.rs` |
| `b08100f` | **Ctrl+Alt+Del is routed to the service's helper**, which raises it with `SendSAS` — only when the person ticked the administrator-prompts box. The app reads the Windows policy that decides whether a program may send it and never changes it. When it cannot be sent, the helper is told which reason applies. | `agent.rs` (`secure_attention_plan`); `secure_desktop.rs` (`software_secure_attention_permitted`) |
| `3a29d6e` | **The desktop window.** A question waiting for the person takes the whole window on the Remote tab, so its buttons can always be reached. Failures show the reason. Stop buttons are never disabled. The strip at the top stays in view and says in words whether the other person can see or is controlling. Scam warnings on the consent, admit and companion dialogs. Text that failed the contrast test is darker. A notice on every tab when the computer is offline. A Mac is offered nothing a Mac cannot do. | `desktop/src/` (20 files); `platform/mod.rs` (`supports_secure_desktop`); `commands/mod.rs` (`show_log_folder`) |
| `5758e4f` | **A deploy keeps the previous version's files** for 30 days, so a page loaded before the deploy can still fetch its parts. | `deploy-production.yml`; `deploy-sandbox.yml` |
| `a135c92` | **Rate limits follow the caller**: a computer, a signed-in person or a guest each has their own budget. Only a call nobody has authenticated yet is counted against its address. The two sign-in steps a computer makes at start-up went from 20 to 120 a minute. | `RateLimitFilter.php`; `Routes.php`; `RateLimitTest.php` |
| `8f1c9ad` | **Idle computers and token renewals book no relay capacity.** A computer's check-in used to be handed relay credentials it never used, and booked 6 Mbit/s for them. Entering a room is recorded once, not once every 90 seconds. | `DevicePresenceService.php`; `SignallingController.php` |
| `465108f` | **An outage of Console is not an outage of Remote.** While Console is down, its last answer is reused and one request in a while tries again, instead of every request waiting on it. | `ConsoleDatabaseDetails.php` |
| `54dfcfe` | **Sessions past their time are closed by a job** every five minutes (`remote:sessions-sweep`), which also frees a computer left "busy" in one. The app carrying control leaves when its sharer disappears. | `RemoteSessionsSweep.php`; `SessionService::sweepExpired`; `jobs.txt`; `companion.rs` |
| `ad15c69` | **A personal account can manage its own computers** from the Computers page. The page promised Remove and offered no button. | `DeviceController.php` |
| `810688f` | **The relay**: a malformed request gets a 400 instead of crashing it; a burst is dropped instead of disconnecting the sender; a reader that stops reading is cut off at 1 MB; rooms are capped at 64 and connections at a ceiling below the open-file limit; the heartbeat no longer stalls everybody. | `signalling/src/server.js`; `rooms.js` |
| `9c397c7` | **The room.** A request for control is a strip above the picture, opens the Control panel and changes the tab's title. The helper's own request can be cancelled, and a "no" is said. A helper has Leave beside End, and a helper's End asks first. A warning five minutes before the time limit. The **Keys** menu. Paste. The session code is shown large to a host waiting alone. | `web/src/features/room/`; `services/webrtc/` |
| `2ce447a` | **The web app's colours pass the contrast test**, an error is no longer shown as an empty list, the timeline has words for all the server's events, and wording that said "Windows" or "this organisation" where neither was true is corrected. | `web/src/styles/`; `features/devices`, `sessions`, `settings`; `utils/format.ts` |
| `b1797f0` | **Two kinds of keyboard**: ⌘ and Ctrl change places between a Mac and a PC, and a Mac gets its own Keys menu. The server tells the room which the computer is. A person is never shown their own request as a question to answer. The manual test plan is brought up to what is built. | `remoteControl.ts` (`keyForPlatform`); `Presenter.php` (`operatingSystem`); `desktop/tests/MANUAL.md` |
| `ab638b5`, `822cdac` | **Documents corrected** (Appendix A). **Both platforms' release notes are kept** on a shared release. One test that depended on how fast the machine was is made exact. | `README.md`; `docs/`; `release-windows.yml`; `release-macos.yml`; `RateLimitTest.php` |
| `ee7ac93` | **The browser's consent prompt warns about scams** too. A skip link, a phone menu that leaves the keyboard's path when shut, a join request that a screen reader announces, a name for the shared screen. | `ControlPanel.tsx`; `AppShell.tsx`; `SessionRoomPage.tsx`; `SessionStage.tsx` |
| `4332973` | **A phone** is offered Join first, cannot create a session it could not share in, sees the whole toolbar, and gets the picture rather than the People list. | `DashboardPage.tsx`; `StartSessionPage.tsx`; `room.css` |

### 2.2 PR #116 — live since 13 November

"Make remote control work: input, reconnects, shortcuts, two screens, sound." Each line was a fault found by reading the
code and reproduced in a test.

| Fault | Now |
|---|---|
| A help session ended about two minutes in: the browser's room token expired and the relay announced the helper as gone. | The token is renewed before it expires; a departure is waited out for a minute. |
| The picture froze the moment the control channel opened. | It keeps moving. |
| After **Allow control**, the helper could wait 25 seconds. | A second or two. |
| The pointer paused every three seconds. | Smooth. |
| Every keyboard shortcut was dead: Ctrl+C was typed as the letter c. | A letter typed with Ctrl, Alt or the Windows key held is pressed as a key. |
| A key held while the helper switched windows stayed down on the other computer. | Released. |
| One screen only. | Whoever is controlling switches between the computer's displays. |
| No sound. | On Windows, the computer's sound beside its picture, when the person switches it on. Telephone quality. |
| An unattended session that was over kept being rejoined by the computer. | The computer ends it when its viewer has gone. |
| Clicks on a Mac ignored ⌘, ⇧ and ⌥. | They are honoured. |

### 2.3 Between the audits — pull requests #94 to #115

Twenty-two pull requests by others. The ones that changed a finding in this document:

| Change | Finding |
|---|---|
| A production deploy runs the test suite unless it is switched off (`2d3a020`). | P0-5, partly |
| The app does not offer a build the publisher marked unsigned as an in-app update (`3306e31`). | P0-1, partly |
| A desktop sign-in must be opened with the enrolled computer's own credential, and the approval page shows which computer is asking (`2d48b3b`, `1175b70`). The older way is still accepted until 31 December 2026. | §10 |
| AICOUNTLY's own analytics was added to the web app (`ff3a475`, `923d4eb`). | P0-9, worse |
| A maintenance page that can be switched on from the server (`7337b4e`). | §11 |
| A page refresh keeps the person's seat in the session (`1d0667b`). | — |

---

## 3. How remote control works today (checked in the code)

There are two ways a computer is controlled, and the helper needs only a browser for both.

```text
 A. The app shares its own screen — "Get help" (attended), or unattended access to your own computer

 Person being helped                                             Helper
 ┌──────────────────────────────────┐  screen (H.264), sound   ┌───────────────────────┐
 │ AICOUNTLY Remote app             │ ───── peer to peer ────▶ │ any browser, signed   │
 │ Windows or Mac                   │      (or via TURN)       │ in to AICOUNTLY       │
 │ consent dialog · Stop control    │ ◀──── data channel ───── │ mouse, keyboard, the  │
 └──────────────────────────────────┘  input, helper's paste   │ Keys menu, paste      │
                                                               └───────────────────────┘

 B. A browser shares, the app carries control — the "control companion"

 ┌──────────────────────────────────┐  screen (browser's own)  ┌───────────────────────┐
 │ Chrome or Edge shares the ENTIRE │ ───── peer to peer ────▶ │ any browser           │
 │ screen; the app on the same      │ ◀──── data channel ───── │                       │
 │ computer carries the input       │        input only        │                       │
 └──────────────────────────────────┘                          └───────────────────────┘
```

* **The picture.** In A the app captures the screen (Windows.Graphics.Capture; Core Graphics on a Mac), scales anything
  larger than 1080p down to it, and encodes H.264 with Cisco's OpenH264 at up to 30 frames a second
  (`remote-webrtc/src/video/`; `session.rs` `CapturePump`). In B the picture is the browser's own capture.
* **Who may send input.** In A: the person admitted the helper, the helper asked, and the person pressed **Allow
  control** in the app — or the computer's owner switched unattended access on for that computer, with a two-step code.
  In B, all of these hold (`CompanionService.php`): the session is personal, the person is the joined host and is
  sharing an entire screen, the computer is their own and online, somebody confirmed the link on that computer within
  five minutes, the companion matched the screen, and the person granted this request.
* **The gate is local.** Input is admitted or dropped inside the app (`ControlGate`), not on the server. While a request
  is waiting for an answer nothing is admitted at all, so a helper cannot click the app's own **Allow** button.
* **Stopping.** The **Stop control** button at the top of the app's window (never disabled, on every tab), the tray or
  menu-bar icon, or the shortcut (Ctrl+Alt+Shift+S; ⌘⌥⇧S on a Mac). It takes effect on the computer at once, with or
  without a network. In way B, any real mouse or keyboard use at the computer also pauses the helper, whose input
  resumes 1.5 seconds after the person stops (`companion.rs`). In way A it does not: both can move the pointer, as in
  other remote-control tools, and the person stops it with the button or the shortcut.
* **What the helper's keyboard sends** (`web/src/services/webrtc/remoteControl.ts`):
  * Ordinary typing goes as the character that was typed, so a French keyboard controlling a US one types what was meant.
  * A letter typed with Ctrl, Alt or the Windows key held goes as that key, so shortcuts work.
  * A helper on a Mac has ⌘ sent as Ctrl to a Windows PC; a helper on Windows or Linux has Ctrl sent as ⌘ to a Mac
    (`keyForPlatform`). The same kind of keyboard at both ends, or a computer that has not said what it is: nothing is
    changed.
  * In full screen, Chrome and Edge hand the room the whole keyboard, so Alt+Tab, the Windows key and Esc go to the
    other computer. Firefox and Safari cannot.
  * The **Keys** menu sends what no keyboard can hand over: on Windows, Ctrl+Alt+Del, Task Manager, the Start menu, Run,
    File Explorer, Show the desktop, Switch window, Close window, Esc and Print Screen; on a Mac, Force Quit, Spotlight,
    Switch app, Mission Control, Close window, Quit app, Interrupt and Esc (`SessionStage.tsx`, `keyMenuFor`).
* **Paste.** Where the person ticked **Also share the clipboard** (Windows, way A only), the text the helper copied is
  sent ahead of their paste key. Nothing copied on the computer travels back.
* **Screens.** In A, the app tells the room its displays, and whoever is controlling switches between them. In B the
  browser shares one screen and there is nothing to switch.
* **Sound.** In A, on Windows, when the person switched **Share this computer's sound** on. Off by default; telephone
  quality (G.711).
* **Windows prompts.** While an administrator prompt, the Ctrl+Alt+Del screen or the lock screen is in front, and the
  person ticked the box for it, the picture and the input go through a helper process started by the background
  service (`secure_desktop.rs`). **Ctrl+Alt+Del** from the Keys menu goes the same way and is raised with `SendSAS`.
* **What the helper is told**, above the picture: in way B, that somebody at the computer is using it (paused); that a Windows
  prompt is open, and whether they can answer it; that a window running as administrator would not take their click;
  that Ctrl+Alt+Del was not sent, and which of two reasons applies; that control ended, and why.

---

## 4. Capability matrix

The AICOUNTLY column describes the branch, checked in the code. The three competitor columns are **as commonly
advertised. Verify before quoting publicly.**

| Capability | AICOUNTLY Remote | AnyDesk | TeamViewer | UltraViewer |
|---|---|---|---|---|
| Connect by ID or code | A 9-digit session code, and the host admits each person. The app's **Get help** gives the code from the computer itself; **Connect → Help someone** joins by it. Your own computers are connected to from a list, not by an ID. | Yes. A permanent ID or alias. | Yes. ID and password. | Yes. ID and password. |
| Attended control | Yes, on Windows and Mac, two ways (§3). Admitting and control are separate consents. | Yes | Yes | Yes |
| Unattended access | Yes, to **your own** computers: switched on per computer, owner only, every connection recorded and shown on the machine, behind two-step verification. On a Mac the app must be running and somebody signed in. | Yes | Yes | Yes |
| UAC and elevated windows | **UAC prompts, Ctrl+Alt+Del and the lock screen: yes, with consent**, through the background service. **Windows running as administrator on the ordinary desktop: no** — Windows refuses the app's input there, and the helper is now told so. Needs `uiAccess` and a signed build (P1-2b). | Yes, when installed | Yes, when installed | Usually yes when installed (verify) |
| Special keys | The Keys menu (§3), in every browser. Ctrl+Alt+Del only where the PC's Windows policy lets a program send it. The whole keyboard in full screen in Chrome and Edge. | Yes | Yes | Yes |
| Mac and Windows together | Either can help or be helped. ⌘ and Ctrl change places. **Not yet:** a character a Mac helper types with Option. | Yes | Yes | Windows only (verify) |
| Multi-monitor | When the app shares its own screen, the helper switches between the computer's displays once control is granted. One at a time; no "show all". Not when a browser shares. | Yes. Switch, or show all. | Yes | Yes. Switch. |
| Sound | Windows, when the app shares its own screen and the person switched it on. Telephone quality. Not from a Mac. | Yes | Yes | Verify |
| Clipboard | Text, helper to computer, Windows only, when the app shares its own screen and the person ticked it. Never computer to helper; never files; not through a companion. | Text and files, both ways | Text and files | Text (files: verify) |
| File transfer | Browser to browser. The recipient must accept. 25 MB at most. The desktop app neither sends nor receives. | Yes, with a file manager | Yes | Yes |
| Chat | Yes, between browsers. The desktop app's own window has no chat. | Yes | Yes | Yes, a main feature |
| Annotations | A live pointer and drawing, in the web | Whiteboard | Yes | Not advertised (verify) |
| Recording | No | Yes, on paid plans | Yes | Not advertised (verify) |
| Address book | Your own computers, with how many are registered and online, each one's history, and Remove — in the web and the app | Yes, on paid plans | Yes | A basic partner list (verify) |
| Wake-on-LAN | No | Yes, on paid plans | Yes | Not advertised (verify) |
| Reboot and reconnect | No. The API call exists but no screen uses it, and it is off for personal accounts. | Yes | Yes, including safe mode | Verify |
| Mobile apps | None. A phone's browser can join, watch and chat; it cannot share, and control from it is taps only. | iOS and Android | iOS and Android | Not offered (verify) |
| Linux | Browser only: it can share and watch but cannot be controlled. No app. | Yes | Yes | No |
| **Access from a browser alone** | **Yes. This is the real advantage. The helper needs only a browser and an AICOUNTLY account.** | The app is normally needed at both ends (verify) | Web client for connecting out only | No |
| Two-factor sign-in | Remote's own two-step verification, required for unattended access. Whether the portal sign-in has a second factor is not visible here. | Yes | Yes, plus trusted devices | Not advertised (verify) |
| Told the truth when it fails | Yes: paused, a prompt is open, input refused, Ctrl+Alt+Del not sent, control ended — each in words. | — | — | — |
| Audit | Session history and a timeline with every event in words. The full audit trail is for companies only. | Session logs, on paid plans | Connection reports, on paid plans | Basic history |
| Pricing and free tier | Not decided. A built-in "Basic" plan applies. | Free for personal use; paid tiers | Free for personal use; paid tiers (dearer) | Free; low-cost paid tiers |

---

## 5. Office pilot

### 5.1 Must be true before the pilot starts

| # | Condition | Why | Effort |
|---|---|---|---|
| PB-1 | **Merge and deploy the branch** (web and API), and restart the signalling service. | Everything in §2.1 that is not in the desktop app. | S |
| PB-2 | **Release a Windows and a Mac build from the merged commit**, and publish them. Testers install only those. | The desktop window's fixes, the refused-input notice, Ctrl+Alt+Del and the Mac's pointer are in the app, not the web. | S |
| PB-3 | **Run `desktop/tests/MANUAL.md` §1–§9 on one Windows PC and one Mac**, and record the result. | Nothing in PR #116 or in this pass has run on a real computer (§0). | S (half a day) |
| PB-4 | Each tester has a personal AICOUNTLY account and has signed in. | Joining with a code needs a signed-in account. | S |
| PB-5 | Check which guest-link setting production uses. | The built-in Basic plan says external guests are off; the platform-defaults seeder says they are on, with a 120-minute cap (`RemotePlatformDefaultsSeeder.php:37`, `EffectivePolicyResolver.php:548-549`). Whether guest links work depends on which row exists. | S |
| PB-6 | A Mac build that Gatekeeper will open, with Accessibility and Screen Recording granted. | Without them macOS drops every injected event and shows only the wallpaper. | S |

### 5.2 What to tell the pilot group

| # | Caveat | What testers will see |
|---|---|---|
| C-1 | **Windows prompts only with the box ticked; windows running as administrator not at all.** | With the box ticked, the picture switches to the prompt and the room says "You can answer it". Without it, "Ask the person at the computer to answer it". In Task Manager or an installer's own window, the helper's clicks do nothing, and the room says a window running as administrator is in front. |
| C-2 | **Control needs the entire screen.** In the app, Start session chooses it for you. In a browser, Start session begins on Safe Share, and now says underneath that a helper can look but not control, and what to choose instead. | "Shared this way, a helper can look but cannot control this computer." |
| C-3 | **The clipboard goes one way.** Helper to computer, Windows only, when the app shares its own screen and the person ticked **Also share the clipboard**. Not through a companion, not on a Mac, never back to the helper. | To pass text the other way, read it out, or use the chat in a browser session. |
| C-4 | **Sessions end 60 minutes after they start.** The room warns five minutes before. It cannot be extended. | "This session reaches its time limit in 5 minutes and ends then. To carry on, start a new session." |
| C-5 | **Files are 25 MB at most**, between two browsers. | Bigger files are refused before sending. |
| C-6 | **One screen at a time.** When the app shares, whoever is controlling switches screens with **1** and **2** beside the full screen button. When a browser shares, stop and share the other screen. | — |
| C-7 | **Special keys come from the Keys menu.** In Chrome and Edge, full screen also sends Alt+Tab and the Windows key from the keyboard. | A **Keys** button beside full screen while controlling. |
| C-8 | **Ctrl+Alt+Del works only where Windows allows it.** It needs the administrator-prompts box ticked, the background service, and a Windows policy that lets a program send it. An ordinary home PC does not have that policy. | "Ctrl+Alt+Del was not sent: Windows on that computer does not let an app send it. The person there can press it themselves." |
| C-9 | **A Mac helper cannot type a character that needs the Option key** into a Windows PC (`#` on a British keyboard, `@` on a German one, `€`). | Nothing is typed. Characters typed without Option are fine. |
| C-10 | **From a Mac keyboard the Windows key is in the Keys menu**, because ⌘ is sent as Ctrl. From a PC keyboard a Mac's own Control key is there too (**Interrupt**). | — |
| C-11 | **When the app shares its own screen, one helper at a time**, and that computer has no chat and no file transfer. | A second helper waits until the first has gone. |
| C-12 | **Sound is Windows only, off until switched on, and telephone quality.** | — |
| C-13 | **Use computers with one Windows user each.** The computer's identity belongs to the whole machine. | On a shared PC, every Windows account is the person who registered it. |
| C-14 | **If you have registered more than one computer, pick the one you are sitting at** when linking a companion. Nothing checks it. | The confirm prompt appears on the wrong computer. |
| C-15 | **On a Mac, sharing from the app's Remote tab has not been tested.** If it says this browser cannot share, use **Get help** in the app instead, or share from Chrome. | — |
| C-16 | **The installers are unsigned.** | "Windows protected your PC", and a Gatekeeper refusal on a Mac. Note every warning. |
| C-17 | **The TURN relays are in India only.** | A helper abroad is slower whenever the connection goes through a relay. |

The earlier caveat about an office sharing one rate limit is gone: limits now follow the computer or the person.

---

## 6. Findings

**A dependency, not a finding:** code signing. Windows needs the OV certificate (SSL.com, in validation). macOS needs a
Developer ID certificate and notarisation. Signing unlocks SmartScreen trust, the P0-1 signature check, `uiAccess` for
elevated windows (P1-2b), and macOS remembering the Accessibility permission across updates.

### 6.1 P0: must be fixed before any public launch

| # | Finding | Status on 14 November | What is left | Effort |
|---|---|---|---|---|
| P0-1 | **The update path trusts the download host completely.** | **Partly.** The app checks the file's name, size and SHA-256, all from the same manifest. Since PR #108 a build marked unsigned is not offered in the app at all: the person is sent to the download page. So today no installed copy updates itself. | Before running an installer: check its Authenticode signature and signer on Windows, its code signature and Team ID on macOS. Sign the manifest with an offline key built into the app. `signed: true`, or a missing field, is still simply believed (`update.rs:275`, `:500`, `:515`). | M, after the certificates |
| P0-2 | **No Terms, no privacy notice for Remote, no EULA** — in the web, the app or the installer. | **Open.** The only legal link is the general privacy policy. | Terms covering acceptable use and abuse; a notice of what Remote stores; a grievance contact; a licence page in the installer that reproduces Cisco's OpenH264 licence. Accept at the first session. | M with counsel; S to put in place |
| P0-3 | **Nothing is deleted on a schedule**, and the audit rows of personal sessions can never be deleted. | **Open.** The new sweep job closes sessions; it deletes nothing. | A retention period for each kind of data and a nightly purge. Keep the 180-day logs CERT-In requires. | M |
| P0-4 | **No handling of abuse or scams.** | **Partly.** The person is now warned at every point of consent — the app's control dialog, its admit prompt, its companion confirmation, **Get help**, and the browser's grant prompt: "Only allow this if you know … and asked them for help. AICOUNTLY never calls or messages anybody to ask for control." | The helper's verified e-mail address beside their name. "Report this person" in the room and on the ended screen. Suspending an account on the server. A published abuse contact. | M |
| P0-5 | **Operations are not ready for the public.** | **Partly.** A production deploy runs the tests unless told not to. The relay no longer falls over on the six things the load test found. Sessions are closed by a job. A deploy keeps the previous version's files. | CI still runs only when started by hand; the sandbox deploy's test gate is off by default. No uptime monitoring or alerting. No database backup or restore test documented. No status page or runbook. One server; one signalling process holding its rooms in memory. | S for CI; M for the rest |
| P0-6 | **Positioning: say what it does and does not do.** | **Open.** More is built, and still none of it is proven on real machines. | Run the pilot, then launch with a fair comparison that names what is not there (§4). | S |
| P0-7 | **Pricing and free-tier limits are not decided, and TURN admission only watches.** | **Open.** One thing got safer: idle computers no longer book relay capacity, so switching admission to refuse would no longer turn away free users because 70 PCs were switched on. | Decide what is free. Limits per account. Measure relay capacity, then switch admission to `enforce`. | M; L with billing |
| P0-8 | **TURN relays outside India.** P0 only if you market outside India. | **Open.** | A relay where the users are. | M per region |
| P0-9 | **Analytics without consent.** P0 if you serve the EU; a DPDP question in India. | **Worse.** Google Analytics still loads "cookieless" before a choice (`analytics.ts:44`), and the banner still says "nothing that identifies you". And a second pipeline, AICOUNTLY's own, records a page view for every navigation of a signed-in person under their user id, whatever they answered and before they answer (`web/src/main.tsx`, `estateAnalytics.ts`, `AnalyticsCollectController.php`). It sends a masked path, never a token or a query. | Decide (owner decision 4). The switches are `ANALYTICS_BEFORE_CHOICE = 'none'` and the `initEstateAnalytics()` call. Soften the banner's wording. A retention rule for the second pipeline. | S once decided |
| P0-10 | **An H.264 patent licence.** | **Done**: the app uses Cisco's binary. | Counsel's view on whether the pool's content-provider licence applies once plans are paid. | Counsel |
| P0-11 | **No recorded test on a real Windows PC or Mac.** *New as a numbered finding.* | **Open.** | `desktop/tests/MANUAL.md`, both platforms, written down (§0). | S |

### 6.2 P1: needed to compete credibly

P1-12, P1-13 and P2-10 were closed in the earlier pass and are not repeated.

| # | Finding | Status | What is left | Effort |
|---|---|---|---|---|
| P1-1 | A video encoder in the app. | **Built.** Input no longer waits for an encode. Measured again in §8. | A hardware encoder (Media Foundation, VideoToolbox) or a faster path for 4K and Retina screens; ScreenCaptureKit on macOS 14+. | M |
| P1-2 | UAC, elevated windows and the Secure Desktop. | (a) test on Windows 10 and 11 — **open**. (b) elevated windows on the ordinary desktop through `uiAccess` — **open**, after signing. (c) tell the helper when an elevated window refused input — **done in this pass**. (d) Ctrl+Alt+Del — **done in this pass**, subject to the Windows policy. The earlier audit said "only the button is missing"; it needed a message, a rule and routing as well, and those are what was built. | (a), (b); owner decision 5. | M after signing |
| P1-3 | Unattended access to your own computers. | **Done.** | — | — |
| P1-4 | Connect by computer ID. | **Open.** Only the rename to "Key fingerprint" exists. | A real ID a helper can type to *ask* for a session, with the consent still at the computer. | L |
| P1-5 | Clipboard. | **Partly.** Helper to computer, text, Windows, when the app shares — and the paste key now carries the helper's text (this pass). | Through a companion; on a Mac; computer to helper (owner decision 6); files. | M |
| P1-6 | Session continuity. | **Partly.** A warning five minutes before the limit (this pass). A reloaded or dropped helper is waited for a minute (PR #116). | Extending a session. Coming back after the helped computer restarts. An idle timeout. | M |
| P1-7 | Crash reporting, and "Send logs to support". | **Partly.** About now has **Open the folder** for the logs (this pass). | Sending them. Crash reports. The web reports no errors at all. | M |
| P1-8 | The web after a deploy. | **Done** — the error screen and one reload earlier, the previous version's files kept in this pass. | — | — |
| P1-9 | Two-factor sign-in. | Remote's part **done**. | The portal's own second factor (portal team). Making Remote's compulsory for helpers needs the portal to vouch for who is enrolling. | Portal team |
| P1-10 | A security contact. | **Open.** No contact is named, there is no `security.txt`, and the deploy excludes `.well-known/`, so one placed there would not ship (`deploy-production.yml`). | A mailbox, a response time, the file, and the exclude removed. | S |
| P1-11 | Rate limiting per IP address. | **Done for signed-in calls and computers** (this pass). | The counters live in files on one server; a second server would need a shared store. `proxyIPs` is empty: check that the address the API sees behind Imunify360 is the visitor's (§9.6). | S to check; M for a shared store |
| P1-14 | **A Mac helper's Option-typed characters are not typed on Windows.** *New.* `#` on a British Mac keyboard, `@` on a German one, `€`, `₹`. The character arrives with Alt held, and Windows treats it as a shortcut. | **Open.** Characters typed without Option are unaffected. | Hold the Option key back until the next key shows whether it was a shortcut or a character. Needs a real Mac and PC to get right. | M |
| P1-15 | **The room asks the server for news every few seconds.** *New.* 0.55 requests a second for each browser session, 1.2 with a desktop app in it (§9.1). This, not the relay, is what limits how many sessions one server carries. | **Open.** Guards against overlapping requests, and the fixes in §9.4, are done. | Have the relay tell the room when something changed, and ask the server only then. | L |
| P1-16 | **Three database reads get slow as history grows.** *New.* At a million sessions the dashboard's first request takes 0.4–0.6 s, and a computer's history about 0.1 s. | **Open.** The fix was measured, not applied: a rewritten query and three indexes take them to under 1 ms each. | A migration: index `(initiator_user_id, created_at DESC)` on sessions, `(device_id)` on participants, and one on the audit table's device id. | S–M |
| P1-17 | **The older desktop sign-in is still accepted.** *New.* A sign-in started without the computer's own credential is honoured until 31 December 2026 so that installed copies keep working (`DesktopSignInService.php`). | **Open, by design, with a date.** | Delete the constant and the `optional-device-auth` filter on that date. Make sure the builds people have installed are newer than PR #109 by then. | S |

### 6.3 P2: later

| # | Finding | Status | Effort |
|---|---|---|---|
| P2-1 | Recording, with consent from both sides. | Open | L |
| P2-2 | Let the helper switch screens. | **Done** when the app shares its own screen and control is granted. Left: through a companion; without control; showing all screens at once. | M |
| P2-3 | File transfer: a higher cap, folders, resuming, and from the app itself. | Open | M |
| P2-4 | Wake-on-LAN, restart and reconnect. | Open | M–L |
| P2-5 | Mobile apps, and an app for Linux. | Open | XL / L |
| P2-6 | Languages (Hindi first) and a full accessibility audit. | Open for languages. Accessibility: contrast, focus, a skip link, announcements and phone layout were measured and fixed in this pass (§7.5); a full WCAG 2.2 AA audit has not been done. | L |
| P2-7 | Installer: a per-user option, and more languages. | Open | M |
| P2-8 | Narrow the web's content security policy: `connect-src` allows any `https:` and `wss:` address. | Open; now stated honestly in `docs/SECURITY.md`. | S |
| P2-9 | Choose the right computer for the companion. | Open | S–M |
| P2-11 | Shared PCs: every Windows account on a PC acts as the person who registered it. | Open | M |
| P2-12 | Make "personal only" true on the server too. | Open | S–M |
| P2-13 | A special-keys menu and keyboard lock. | **Done** (PR #116 and this pass). | — |
| P2-14 | Analytics inside the desktop app's embedded pages. | Open; both pipelines run there. Part of owner decision 4. | S |
| P2-15 | *New.* The room's 20-second "I am here" call is read by nothing on the server. Drop it or use it. | Open | S |
| P2-16 | *New.* The **Get help** window asks the server who is waiting every 2 seconds — the largest single source of requests in a session the app hosts. | Open | S |
| P2-17 | *New.* Each API request repeats the same lookups: 8 database reads for a session, 15–16 for control, 17 for a computer's control. Remembering them within a request would make that 5, 9 and 10. | Open | S–M |
| P2-18 | *New.* The portal is asked about each signed-in browser once a minute, waiting up to 8 and 15 seconds. A slow portal would hold up Remote, computers included. | Open. Shorter waits, and reusing the last answer while the portal is down, is an owner decision: it trades a minute of stale sign-in for staying up. | S |
| P2-19 | *New.* The newest release's notes do not say its Windows installer is unsigned. | The workflows are fixed. That release needs its notes edited by hand. | S |
| P2-20 | *New.* When the app shares its own screen it has no chat or file panel, and one helper connects at a time. | Open | M |
| P2-21 | *New.* The shared company-access screens (Users & Access) still put white text on the brand green. They are a kit shared with other AICOUNTLY products and were not changed here. | Open | S, in the kit |
| P2-22 | *New.* No dark theme; the system's preference is ignored. | Open | M |
| P2-23 | *New.* A server message says "not enabled for this organisation" to a personal account. The web's own wording was fixed; the API's was not. | Open | S |

---

## 7. Friendly and easy to use

### 7.1 How it was checked

Every screen was drawn in a real browser and photographed, before and after the fixes:

* **The desktop app's window** at its default size (960×720) and its minimum (720×560), in each state it can be in:
  first run, signed in, a help session, a request for control, being controlled, unattended, offline, removed, updating,
  on Windows and as a Mac. 194 pictures.
* **The web app** at 1440×900 and at a phone's 390×844, signed in as the person being helped and as the helper, with a
  stand-in for the server and a real connection between two pages. 170 pictures.

Colours were read from the drawn page and their contrast computed. The keyboard was walked through every page with Tab.
Clicks were counted.

**Not drawn:** the AICOUNTLY sign-in page (another product), the browser's own "choose what to share" window, the
AICOUNTLY Connect launcher, the app's tray menu and title bar, and the web pages the app shows inside its window.

### 7.2 The desktop app's window: 16 defects

| # | What was wrong | Status |
|---|---|---|
| 1 | **The Allow / Not now buttons could not be reached.** The window opens on a tab that cannot scroll, and the consent dialog was drawn below its bottom edge — a 12-pixel sliver of the buttons at the default size, nothing at all in some states. | **Fixed.** A question waiting for the person takes the whole window until it is answered. Checked at both sizes. |
| 2 | **Every failure said only "Something went wrong."** The reason the app had worked out was thrown away. | **Fixed.** The reason is shown. |
| 3 | **Stop control and End session were greyed out** whenever anything else in the window was loading. | **Fixed.** They are never disabled. |
| 4 | **The consent dialog**: no scam warning; the keyboard was not on it; Esc did nothing; a label and its explanation ran together; the stop shortcut was never mentioned. | **Fixed.** A red caution first, the keyboard on the dialog and on neither button, Esc is "Not now", the shortcut is written out. |
| 5 | **Being controlled looked like being watched.** One green banner, one word different, and it scrolled out of sight. | **Fixed.** The strip stays at the top on every tab and says "… is controlling this computer" or "… can see this computer's screen"; Stop control is a solid white button. |
| 6 | **Get help**: the banner said a session was active and somebody was viewing before anybody had joined; the code could not be copied; nothing said where the helper types it. | **Fixed.** |
| 7 | **Home**: the header wrapped and clipped at the minimum size; **Get help** was the third of six small tabs; the first tab is the embedded site. | **Partly.** The header wraps cleanly, and "This computer" now has a card that leads to Get help. Which tab the window opens on is left as designed (owner decision 8). |
| 8 | **Contrast**: banner text 2.9:1, its labels 2.5:1, primary buttons 2.9:1, small hints 2.9–3.1:1 — including the only scam warning. 27 text styles failed. | **Fixed.** Of 74 styles measured afterwards, only disabled buttons are under 4.5:1, and those are exempt. |
| 9 | **Offline** was one grey pill on one tab; **removed** was the sign-in card with one grey sentence. | **Fixed.** A notice on every tab with the reason; **Get a code** is disabled rather than failing. |
| 10 | **The Mac build spoke Windows**: it offered "answer Windows administrator prompts", mentioned Task Manager, and showed a background service as "Not running". | **Fixed.** |
| 11 | **Jargon**: "This device", "Device ID", "Registration: Protected", "A capability works only when…", "Control companion". | **Fixed.** "This computer", with the technical details folded away for support. |
| 12 | **Settings**: the first field was an editable server address; the sound switch promised "stops at once" and applied only on Save; nothing said "saved". | **Fixed.** The switch saves at once and says so; the address is under Advanced. |
| 13 | The field for a helper's code showed 8 of its 11 characters. | **Fixed.** |
| 14 | The update prompt did not take the keyboard, and Esc did nothing. | **Fixed.** |
| 15 | First run: the steps were bold right-aligned rows, the key fingerprint was the most prominent thing on the screen, and there was nothing for somebody without an account. | **Partly.** Numbered steps; the fingerprint is folded away. Still nothing for somebody without an account. |
| 16 | The dialog's name for a screen reader was "Someone is asking…" though the name was known; the banner announced itself every second. | **Fixed.** |

**What already worked well, and was kept:** the admit and control prompts appear on every tab; both extra consents
start unticked; "What allowing this means" names who, what, and that stopping needs nobody's agreement; the code is
large and grouped 3-3-3; an unattended session has its own amber banner.

### 7.3 The web app: 18 defects

| # | What was wrong | Status |
|---|---|---|
| 1 | **A request to control your computer was invisible**: a "1" on a toolbar button. No strip, no change to the tab. | **Fixed.** A strip above the picture, the Control panel opens by itself, and the browser tab's title says who is asking. |
| 2 | **While controlling, a label on the picture swallowed clicks**: a real click on the other computer's Start button sent nothing. | **Fixed.** The label goes while controlling. |
| 3 | **The untouched first path shares a browser tab**, which cannot be controlled. | **Owner decision 7.** The default is unchanged; a sentence under the choices now says a helper can look but not control, and what to choose instead. |
| 4 | **A declined helper waited for ever** on "Waiting for the host to admit you". | **Fixed.** They are told, and the page stops asking. |
| 5 | **"Give me your code"**: it was 13 pixels high in the header, beside a bolder session number. | **Fixed.** A host waiting alone sees the code large, with what to do with it. |
| 6 | A waiting helper saw three different statuses, and a red **End** that failed while waiting and, once admitted, ended the session for everybody without asking. | **Fixed.** One waiting message; a helper has **Leave**, and a helper's **End** asks first. |
| 7 | Just after sharing the entire screen, the Control panel still said "Share your entire screen" for up to 15 seconds. | **Fixed.** It is asked again at once. |
| 8 | **The helper's own request** was only in a side panel: no way to cancel it, a refusal was silent, and "control stopped" was a grey line under the toolbar. | **Fixed.** A strip with **Cancel request**; a refusal is said, with **Ask again**; notices sit above the picture. |
| 9 | **On a phone**, which cannot share: "Share my screen" was the highlighted first action, under a welcome card and a notice that repeated itself; "Create session" still worked, into a room with no Share button. | **Fixed.** Join is first; sharing says "Not available in this browser"; Create session is disabled there. |
| 10 | **Errors looked like empty lists**: a failed request showed "No computers registered yet" or "No sessions yet"; the Start page stayed a grey block. | **Fixed.** Each says it could not be loaded and offers **Try again**. |
| 11 | A personal account got no **Remove** or unattended buttons on the Computers page, though the page promised them. | **Fixed** (a server fix). |
| 12 | **Wording**: "It does not control your computer"; "No installation required"; "Anita Sharma can be controlled…"; the Security panel addressed a helper as the person sharing; "This organisation" on a personal account; "for Windows" in six places. | **Partly.** All of those are corrected. The person and their computer still go by more than one name each across the screens. |
| 13 | The session timeline showed the server's own codes for 19 events, the control events among them. | **Fixed**, with a test that fails when the server gains an event the web has no words for. |
| 14 | **Contrast and keyboard**: white on green 2.9:1; muted text 3.1:1; focus ring 1.3–1.5:1; a join request was not announced; a heading out of order. | **Fixed** (§7.5). |
| 15 | The Control panel had no padding; the companion's "Ready" was in the red box that means "being controlled"; "HOST" and "…(control)" were cut short. | **Partly.** Padding and "Ready" are fixed; long names are still cut short in the People list. |
| 16 | **The room on a phone**: a header 163 pixels tall; a toolbar that scrolled sideways, hiding seven of its buttons; 32-pixel buttons. | **Partly.** Every button is on screen and at least 44 pixels tall, and the picture has 551 pixels instead of 294. The header is still tall. |
| 17 | Dialog buttons touching; a list with no bullets; the phone menu still in the keyboard's path when shut, and its button never an X; the Arrow tool's icon was a circle. | **Fixed.** |
| 18 | The chat history was fetched again after every check of the session; leaving quickly could leave a connection open. | **Fixed.** |

**What already worked well, and was kept:** the Join page is one field that takes the keyboard at once, groups the
digits, and says the host must admit you; stopping is one red click in a pinned banner on both sides; the host's control
prompt is plain and the clipboard is a separate, unticked box; no sideways scrolling at phone width; dialogs keep the
keyboard inside, close on Esc and give the keyboard back; the status line uses words, never "WebRTC" or "TURN".

### 7.4 How much a person has to do

Counted after sign-in, on the web:

| To | Clicks |
|---|---|
| Be helped (share your screen and let somebody in) | 4 in the app, 2 in the browser's own picker, 1 to allow the helper: **7** |
| …and let them control the computer | 4 more, and 1 in the desktop app |
| Help somebody | **2**, and the nine digits of the code |
| …and control their computer | 2 more |

From the desktop app, being helped is shorter: **Get help → Get a code**, read the code out, **Let them in**, **Allow
control** — four clicks, and no picker.

With the People panel open the picture takes 51% of a 1440×900 window; with it closed, 72%.

### 7.5 Reading and keyboard, measured

| | Before | After |
|---|---|---|
| Web, light pages: text styles under 4.5:1 | 8 | 1 (a disabled button, which is exempt) |
| Web, the room: text styles under 4.5:1 | 3 | 0 |
| Web, primary button (white on green) | 2.9:1 | 6.7:1 |
| Web, focus ring against the page | 1.3–1.5:1 | 6.3–6.7:1 |
| Web, focus ring in the dark room | 1.8:1 | 11:1 |
| Web, phone: stops the keyboard makes on things that are off screen | 7 | 0 |
| Web: a way past the header and menu | none | "Skip to content" |
| Web: the shared screen's name for a screen reader | none | "The shared screen" |
| Desktop window: text styles under 4.5:1 | 27 | 0 (disabled buttons aside) |

### 7.6 Left

* The two owner decisions (the Start page's default, the app's first tab).
* A design pass on names: one word for the person, one for their computer, everywhere.
* The room's header on a phone.
* Somebody without an AICOUNTLY account meets a sign-in page and nothing else.
* No dark theme. English only.
* A full accessibility audit by somebody who uses a screen reader. What was measured here is what a machine can measure.

---

## 8. Speed

Everything here was measured on one machine: four processor cores (Xeon, 2.1 GHz) and 16 GB, with no real network in
between. Figures for a real laptop on a real connection will differ, and §8.5 lists what could not be measured at all.

### 8.1 The web app

The final build, in a real browser, with 150 ms added to every answer from the server. Median of five runs.

| | |
|---|---|
| Something on the screen | 0.10 s |
| Dashboard ready to use | 0.56 s |
| Session room ready to use | 0.71 s |
| How much the page jumps while loading (layout shift) | 0.002 on the dashboard. In a helper's room 0.001 — it was 0.15 before this pass, when the picture's arrival pushed the page about. |
| Downloaded to show the dashboard | 233 kB, in 9 script files and 1 stylesheet |
| The whole app | 755 kB of script (236 kB compressed) in 37 files, loaded a page at a time; the room is 147.5 kB (42 kB compressed) |
| Calls to the server in the first 10 seconds | Dashboard 3. Room, as the person being helped, 9 (it was 11). Room, as the helper, 9. |

A tab left open on the dashboard, Computers or Sessions makes no calls at all.

### 8.2 The desktop app's picture

The app's own encoder (OpenH264, built with its assembly code, as Cisco's binary is), in a release build, on the
hardest case there is: a test picture in which every frame is different. A real desktop is far easier — most frames
change a little or not at all, and an unchanged screen is not encoded.

| Screen | Converting and encoding one frame | 95th percentile | What that allows |
|---|---|---|---|
| 1920×1080 | 25.5 ms | 32.5 ms | 30 frames a second, inside the 33 ms each frame has |
| 3024×1964 (a Retina MacBook), scaled to 1920×1246 | 47.2 ms | 66.5 ms | About 21 a second |
| 3840×2160 (4K), scaled to 1080p | 42.4 ms | 57.0 ms | About 23 a second |

* Scaling a large screen down takes 22 ms of that on its own — half the cost. That, or a hardware encoder, is where a
  smoother 4K or Retina picture would come from (P1-1).
* About 20 frames a second is enough to help somebody. It is not enough to watch a video through.
* The helper's mouse and keyboard no longer wait for a frame to finish encoding (PR #116).
* A still screen costs nothing on Windows, and since this pass almost nothing on a Mac.

### 8.3 The path a click takes

Browser → data channel → the app's gate → the operating system. Pointer movement is sent at most once every 16 ms.
Nothing on that path waits for the server or for the picture. **How long it takes from the helper's hand to the other
screen has not been measured**: it needs two real computers and a real network. The pilot script's test is that any
delay feels shorter than half a second.

### 8.4 The relay and the server

* The relay adds 0.2 ms to a message at the median and 0.6 ms at the 95th percentile, with 19,900 connections open
  (§9.3).
* Each lookup the room's regular calls make is indexed: 0.02–0.05 ms each in a database holding a million sessions.
* How long the production server takes to answer a call was not measured. The sums in §9 assume 50 ms.

### 8.5 Not measured

Anything over a real network. Real laptops: processor use, battery, fan. The delay from hand to screen. What capturing
costs on a real Mac. The production server's own response times.

---

## 9. Scale

### 9.1 What one session asks of the server

The room does not wait to be told that something changed. It asks, on a timer.

| Who asks | What, and how often | Requests a second |
|---|---|---|
| Browser, person being helped | The session, every 4 s (2 s while somebody is waiting to be let in) | 0.25 |
| Browser, helper | The session, every 25 s | 0.04 |
| Both browsers together | Control, every 15 s each; every 4 s while control is asked for or granted | 0.13–0.50 |
| Both browsers together | "I am here", every 20 s each (read by nothing — P2-15) | 0.10 |
| Both browsers together | A new room token, every 90 s each | 0.02 |
| Desktop app sharing its screen, or carrying control | Control, every 3 s | 0.33 |
| Desktop app's **Get help** window | Who is waiting, every 2 s (P2-16) | 0.50 |
| **One session between two browsers** | | **0.55** |
| **One session with the desktop app in it, being controlled** | | **1.2** |
| **One signed-in computer doing nothing** (a Mac: 0.04) | Check-in and presence, each once a minute, by the app and by the Windows service | **0.06** |

Each of those requests makes between 5 and 17 database reads, and opens its own database connection.

### 9.2 What that adds up to

| At the same moment | Requests a second |
|---|---|
| 100 sessions | 55–120 |
| 1,000 sessions | 550–1,200 |
| 10,000 sessions | 5,500–12,000 |
| 10,000 signed-in computers doing nothing | 580 — as much as 500 to 1,000 sessions |

A PHP server answers as many requests at once as it has workers. If a request takes 50 ms and there are *W* workers,
that is 20 × *W* requests a second: about 36 × *W* browser sessions, or 16 × *W* sessions with the desktop app in them.
**With 20 workers, roughly 700 browser sessions or 300 with the app — on a server doing nothing else.** Both numbers
in that sentence are assumptions: neither the worker count nor the response time can be read from the code (§9.6).

So: **today's single server is good for hundreds of sessions at once, and the limit is the asking, not the relay or
the media**, which goes directly between the two computers.

### 9.3 The signalling relay, load-tested

The relay as it is in the repository, driven by thousands of real connections on the same machine. "Before" is `main`
(what production runs); "after" is the branch.

| | Before | After |
|---|---|---|
| Most connections held | 19,900 (the test machine's own file limit) | 19,900 |
| Memory at 19,900 | 193–218 MB | 192 MB |
| Processor with 19,900 idle connections | 0% | 0% |
| Delay added to a message, 5% of rooms busy (6,082 messages a second) | 0.12–0.15 ms median, 0.34–0.50 ms at the 95th percentile | 0.20 ms, 0.58 ms |
| Processor at 6,082 messages a second | 17–21% of one core | 25% of one core |
| Longest a message waited during the relay's 30-second check of every connection, at 10,000 / 19,900 | 32–82 ms / 195–346 ms | 11–15 ms / 19–26 ms |
| One participant sending the largest allowed message as fast as allowed, into a room of 50 | 89% of the core; every other room delayed 0.26–0.76 s | 5% of the core; other rooms 0.6 ms median, 5 ms worst; the sender is cut off after 2 s |
| A recipient that stops reading | 404 MB of memory in a minute | Memory flat at about 90 MB: what one sender may send in ten seconds is capped by size as well as by count, and a recipient more than 1 MB behind is cut off |
| An ordinary burst — a helper's pointer while a connection is being set up; a newcomer to a room of 6 | The sender was disconnected, and the room told they had left | Delivered; nobody disconnected |
| A request with a malformed address (`//`). It cannot arrive through the public address, only from a program on the same server. | The relay crashed; all 100 test connections dropped | Answered "400 Bad request"; all 100 stayed up |
| Running out of open files (tested at a limit of 1,024) | New connections accepted and then reset, nothing logged, and the health check failed too | Refused with 503 at 960; a line in the log saying why; the health check answered 20 times out of 20 |
| Restart with 10,000 connected | Everybody back 6.5 s after the stop signal | Not re-run; that code is unchanged |

What the fixes cost: each message now takes about 41 millionths of a second of processor instead of about 30, because
its size is counted and the recipient's backlog checked. One core still relays more than 20,000 messages a second, and
a busy session sends a few a second.

**The relay is not what limits Remote.** Its first real limit is the number of files the operating system lets it
open: one per participant, and one per signed-in computer even when idle. With the common default of 1,024 that is 960
connections. `docs/DEPLOYMENT.md` now shows the one line that raises it.

### 9.4 What stood in the way of the first few hundred — fixed in this pass

| Was | Now |
|---|---|
| **Rate limits were per office address.** One office ran out at 60 Windows PCs checking in, 10 PCs starting up together, 6 sessions hosted by the app, or 4 **Get help** windows open. | Per computer, per person, per guest. Only a call nobody has signed is counted against its address. |
| **Every idle computer booked 6 Mbit/s of relay capacity** on each check-in, for credentials it never used. The book showed "free tier full" at 70 idle PCs; had admission been switched to refuse, it would have refused real sessions. Each booking also rewrote one shared file under a lock: 9 ms at 1,000 idle PCs, 44 ms at 4,000. | A check-in books nothing, and neither does renewing a room token. |
| **Console down meant Remote down.** Five minutes after Console stopped answering, every request waited 2–4 seconds on it. | The last answer is reused; one request now and then tries again. |
| **A room token renewed every 90 seconds wrote an audit row each time** — 80 rows an hour for every session. | One row when a participant enters the room. |
| **Sessions were never closed** unless somebody looked at them, and a computer could stay "busy" in a dead one for an hour. | A job every five minutes. |
| **A slow answer was asked for again** before it arrived, so a struggling server got more requests, not fewer. | One at a time. |
| **Closing the tab did not say goodbye**: the request was cut off with the page. | It is sent in a way the browser completes after the page has gone. |
| **A relay restart made every participant fetch a new token within the same third of a second.** | The first attempt is spread over about a second, a third of the rate. |

### 9.5 What stands in the way of the next step — not done

In the order they would be met.

| # | Limit | When | What it takes |
|---|---|---|---|
| 1 | **The asking itself** (P1-15). | Hundreds of sessions at once. | Have the relay — which already carries a message the moment something happens — tell the room, and ask the server only then. The largest single gain, and the largest job (L). Before it: ask who is waiting every 5 s instead of 2 (P2-16), drop the unused "I am here" call (P2-15), remember lookups within a request (P2-17). All three are small. |
| 2 | **The server's own capacity**: PHP workers, and a new database connection for every request. | The same. | Know the numbers (§9.6). A connection pooler in front of PostgreSQL. |
| 3 | **The relay book**: one file, rewritten under a lock each time somebody enters a room, and slower the more sessions are open — 9 ms a booking with 1,000 entries in it, 44 ms with 4,000. | Thousands of sessions at once. It was hundreds before the idle bookings and renewals were taken out of it. | Counters in the database instead of a file (M). |
| 4 | **The portal**: asked about each signed-in browser once a minute, waiting up to 8 and 15 seconds (P2-18). | Thousands of browsers, or one slow day at the portal. | Shorter waits; reuse the last answer while it is down. |
| 5 | **History**: three reads that slow down as rows pile up (P1-16), and nothing ever deleted (P0-3). A Windows PC writes about 200 "authenticated" audit rows a day by itself: 10,000 PCs are 2 million rows a day. | Months of use. | Three indexes and a rewritten query; a retention job; fewer routine audit rows. |
| 6 | **One of everything**: one server, one relay process with its rooms in memory, limits counted in files on that server. | When one server is not enough, or must not be a single point of failure. | A shared store for limits and rooms; then a second server. |

### 9.6 What could not be told from the code

These decide the real numbers. Each is a question for whoever runs the server.

1. How many PHP workers the API has, and whether OPcache is on.
2. PostgreSQL's `max_connections`.
3. Whether the address the API sees is the visitor's or Imunify360's. If it is Imunify360's, the limits on unsigned
   calls are shared by everybody.
4. The open-file limit of the signalling process (`cat /proc/<pid>/limits`, or the line it logs at start).
5. How many WebSocket connections the front server (Apache) will proxy at once.
6. Whether production's relay book is the file or a database.

---

## 10. Security and privacy

**What is good, and should stay:**

* Nobody gets a signalling token before the host admits them.
* Control needs consent at the computer for each request. The companion can stop control but never grant it.
* The input gate is local, and stopping needs no network.
* While a request for control is waiting, the gate admits nothing: a helper who can already see the screen cannot click
  the app's own **Allow** button. Switching unattended access on needs a two-step code from the last ten minutes, which
  a helper does not have.
* Device keys are Ed25519, kept in DPAPI on Windows or the Keychain on macOS. Device credentials last 15 minutes,
  signalling tokens 2 minutes, and TURN credentials are minted per session.
* The audit metadata is scrubbed of secrets. A session you may not see answers 404, not 403. The API headers are strict.
* Screen content and file contents are never stored.
* The room is told which operating system a **computer** runs, so it can send the right keys. It is told nothing about
  a person's browser (`Presenter::operatingSystemOf`, with a test for both).

**What needs work:**

* **Updates** (P0-1). In-app installing is off while builds are unsigned, which removes the immediate danger and leaves
  people updating by hand.
* **Unsigned installers** (owner decision 2).
* **Not end-to-end against the operator.** Media is encrypted in transit, but AICOUNTLY runs the signalling, so it could
  in principle insert itself into a connection. `docs/SECURITY.md` says so. Do not claim "end-to-end" in marketing.
* **The web app's own page holds the person's sign-in inside the desktop app.** The app's Remote tab is the web app
  signed in as the person, and the sign-in hand-off delivers their portal token to it. The hand-off must now be opened
  with the enrolled computer's credential; the older way is accepted until 31 December 2026 (P1-17).
* **The content security policy** lets a page script send to any HTTPS address (P2-8).
* **Rate limits** follow the caller now (P1-11). Whether the address the server sees is the visitor's, behind
  Imunify360, could not be told from the code (§9.6); if it is not, the unauthenticated limits — sign-in steps, guest
  links — are shared by everybody.
* **The security contact** (P1-10).
* **Shared PCs** (P2-11).
* **The Secure Desktop helper runs as SYSTEM.** It starts only for a session the service knows, at the installed app's
  request, in the console session, after the person's consent; it talks only over a pipe that admits SYSTEM and that
  session's user, and stops when control stops. It now also raises Ctrl+Alt+Del on request, under the same conditions
  and only where Windows' own policy permits. What remains: a process already running as the same user could inject
  into the app during a granted session and so reach the helper. Review it again once the builds are signed.
* **A helper can click on some of Remote's own screens** — admitting another person into the session, accepting a file
  on the person's behalf. Not the control consent (above). The person can always stop it: the stop shortcut is
  local, and when a browser shares, their own mouse pauses the helper. Still worth ignoring remote input over those
  controls.
* **A burst from one participant** can no longer take the relay from everybody else (§9.3).

**What Remote stores about people.** Names and e-mail addresses from the AICOUNTLY account, session metadata,
participants, IP addresses and user agents, chat text, file names and sizes, device names, operating-system versions,
key fingerprints and presence times. Google Analytics receives the origin and a masked path. AICOUNTLY's own analytics
receives a masked path, a random session id and — attached by the server — the person's user id. Screen content and
file contents are never stored. Retention and deletion: P0-3. Consent for analytics: P0-9.

---

## 11. Reliability and operations

* **Hosting.** The API (PHP) and signalling (a systemd unit) share one AlmaLinux/cPanel server. Imunify360 sits in
  front of it and has already broken sign-in once (`docs/auth/SIGN_IN_WAF_INTERCEPTION.md`): check desktop-app traffic
  against it whenever its rules change.
* **Signalling** is one process that keeps its rooms in memory, and every deploy restarts it. After a restart every
  participant reconnects within about a second: measured, 10,000 clients were all back 6.5 seconds after the old
  process was told to stop, and after five seconds of downtime nobody gave up. Media keeps flowing peer to peer
  meanwhile; nothing new can connect.
* **The relay's limits are now explicit**: at most 64 participants in a room; a ceiling on connections just below the
  process's open-file limit, above which it answers 503 and says so in its log once a minute; `/signal/health` keeps
  answering at the ceiling. **Check the limit on the server**: with the common default of 1,024 open files the ceiling
  is 960, and every signed-in computer holds one connection even when idle (`docs/DEPLOYMENT.md`,
  `REMOTE_SIGNAL_MAX_CONNECTIONS`).
* **Sessions** are closed by a job every five minutes once they are past their time, which also frees a computer left
  "busy" in one (`remote:sessions-sweep`; the production deploy installs it). Before, a session expired only when
  somebody next looked at it.
* **A deploy** keeps the previous version's files for 30 days; the maintenance page can be switched on from the server.
* **Console.** If Console cannot be reached, Remote goes on with the last database details it was given, and asks again
  now and then, instead of every request waiting on it.
* **TURN.** Mumbai and Greater Noida run active-active, with fallback over TLS on port 443, probed every five minutes.
  Admission only watches, and the capacity figures are placeholders (P0-7). The relays are shared with Connect and the
  sandbox.
* **Testing.**
  * 2,626 automated tests on 14 November: 1,118 for the API (15 of them skipped without a TURN relay), 593 for the web
    (552 and 41 for the ICE kit), 46 for signalling, 732 in Rust and 137 for the desktop window. All pass, with the Rust
    code checked for Linux and cross-checked for Windows. The Mac-only code was last compiled by the release run on
    `972a9fb`. This pass added one Mac-only function (reading where the pointer is, with the same two system calls the
    Mac's input code already makes); it has not been compiled on a Mac. A release compiles it before anything ships.
  * A desktop release always runs the desktop suite first. A production deploy runs the web and API suites unless that
    is switched off. No suite runs on a pull request or a push.
  * No manual pass on real machines is recorded (P0-11).
* **Diagnostics.** Each computer keeps its own logs, and About has **Open the folder**. There is no way to send them
  and no crash reporting (P1-7). The app now shows the reason for a failure instead of "Something went wrong", which
  is most of what a person on the phone to support needs to read out.
* **Unknown from this repository:** database backups, uptime alerting, who is on call, where the API host and its logs
  physically are, and the six server facts in §9.6. Settle them before launch (P0-5, and §12 for CERT-In).

---

## 12. Legal and compliance

Use this to brief counsel. It is not legal advice. Nothing in this table changed in this pass except the two analytics
rows.

| Item | Status in the product | Needed before a public launch |
|---|---|---|
| Terms of Service | None anywhere: web, app or installer | Terms covering acceptable use, the ban on unauthorised access and scams, suspension and liability. Accept them at the first session (P0-2). |
| Privacy notice | Only the general `aicountly.com/privacy-policy`, linked from the analytics banner and the app's About | A section for Remote: each kind of data in §10, why it is kept, for how long, who processes it (Google Analytics, AICOUNTLY's analytics, the hosting provider, the TURN hosts), and the person's rights. Link it from the web, the app and the installer. |
| EULA | No licence page in the installer. About shows Cisco's OpenH264 licence text. | A licence for the desktop app, shown in the installer and in About. **It must reproduce Cisco's OpenH264 binary licence text and its fourth condition** (`docs/legal/OPENH264.md`). |
| H.264 patents | Cisco's binary, under Cisco's licence | Nothing to buy while Cisco's four conditions are met. Before paid plans, ask counsel whether the pool's content-provider terms apply. |
| DPDP Act 2023 (India) | A choice is offered for Google Analytics only, and it loads before the choice. AICOUNTLY's own analytics records signed-in page views under a user id with no choice offered (P0-9). No grievance contact. No retention. Erasure only when the account closes, and only partly (P0-3). | A notice and consent that list each kind of data and its purpose. A way to withdraw consent. A grievance contact. Erasure once the purpose is served. Reasonable security safeguards. Breach notification. Verifiable parental consent for anyone under 18 (ask the portal team how sign-up handles age). Processor contracts. Confirm the DPDP Rules' dates with counsel. |
| IT Act 2000 and IT Rules 2021 | Remote passes chat between users, so it is probably an intermediary (counsel to confirm). | Published terms and privacy policy, and a named grievance officer. Acknowledge a complaint within 24 hours and resolve it within 15 days. |
| CERT-In Directions (28 April 2022) | No incident process or log policy in this repository | Report a cyber incident within 6 hours. Keep ICT logs for 180 days in India. Synchronise clocks to NTP. Fit this with DPDP deletion (P0-3), and confirm where the hosts are. |
| GDPR, only if you offer the service in the EU | Both analytics pipelines run before, and one regardless of, consent | Consent before analytics (P0-9). An Article 13 notice. A lawful basis for each purpose. Processor agreements. An EU representative, unless exempt. A transfer mechanism. A process for access and deletion requests. |
| Advertising and comparisons | — | Comparisons with named competitors must be accurate (P0-6), and the competitor columns in §4 must be verified first. |

---

## 13. Office-pilot test script

This is the walk a group of people does together. The desktop app's own checklist, step by step and platform by
platform, is `desktop/tests/MANUAL.md`; run that first on one PC and one Mac (PB-3).

**You need:**

* **PC-1**: Windows 10 22H2 or 11, the computer being helped, signed in as account **A**.
* **PC-2**: Windows, the helper's computer, account **B**. A browser is enough here.
* **Mac**: macOS 13 or later, being helped, account **C**. Later, also as the helper's computer.
* All three accounts are personal AICOUNTLY accounts. Use computers that have one Windows user each.
* One person sits at each computer, and you can talk to each other by phone.
* If possible: one laptop with display scaling at 125–150%, one computer with two screens, and one phone.
* Write down the build number, the computer and the time for every result.

"Pass" describes what you should see. Anything else is a fail: write down exactly what appeared.

**A. Install and check the build (on every computer)**

1. **Download** the installer for the build made from the merged branch.
   *Pass:* the file name contains `-b` and 12 digits.
2. **Windows: run the installer.** On "Windows protected your PC", click **More info**, then **Run anyway** (expected
   while the app is unsigned). Answer **Yes** to the Windows prompt.
   *Pass:* the window opens and the tray icon appears. *Fail:* antivirus or IT policy blocks it — record the message.
3. **Mac: install.** Open the `.dmg`, drag AICOUNTLY Remote to Applications and open it. If macOS refuses, go to System
   Settings → Privacy & Security, click **Open Anyway**, then open the app again.
   *Pass:* the window opens and the menu-bar icon appears.
4. **Open App settings → About.**
   *Pass:* Build shows the same 12 digits as the file name, not "Local build". **Open the folder** opens the logs folder.
5. **Close the window and reopen it.**
   *Pass:* the window comes back.

**B. Sign in**

6. **Sign in.** Click **Sign in to AICOUNTLY**, sign in, check that the code and the computer's name shown match, then
   confirm.
   *Pass:* within a few seconds, This computer shows **Online**. The **Remote** tab opens the web app, signed in.
7. **Mac only.** Grant Accessibility and Screen Recording when the app asks, and reopen it.
   *Pass:* the permissions card no longer warns. The app offers no "Windows administrator prompts" setting, no sound
   switch and no background-service row.
8. **The helper (PC-2).** Open `https://remote.aicountly.com` in Chrome or Edge and sign in as B.
   *Pass:* the dashboard appears in under a second.

**C. The app shares its own screen — Get help**

9. **PC-1: Get help → Get a code.**
   *Pass:* a nine-digit code, large, with a Copy button and where the helper types it. The strip at the top says
   "Waiting for someone to join — nobody is connected yet".
10. **PC-2: Join session**, type the code, Join.
    *Pass:* PC-2 says it is waiting to be let in. PC-1 asks "B wants to join", with a caution to let in only somebody
    you know, on whichever tab is open.
11. **PC-1: Let B in.**
    *Pass:* within about 10 seconds PC-2 sees PC-1's screen, moving, sharp enough to read. PC-1's strip says "B can see
    this computer's screen".
12. **Leave both alone for five minutes.**
    *Pass:* the picture keeps moving and the session is still running. (Before PR #116 it ended at about two minutes.)
13. **PC-2: Control → Request control.**
    *Pass:* PC-2 shows a strip, "Waiting for … to answer your request", with **Cancel request**. On PC-1 the app's
    window comes forward and the question takes the whole window: who is asking, a red caution, what control means, how
    to stop it. **Both buttons can be reached.** Pressing Enter straight away answers nothing.
14. **PC-1: Not now.** Then PC-2: **Ask again**, and PC-1: **Allow control**.
    *Pass:* after "Not now", PC-2 is told and offered **Ask again**. After **Allow control**, PC-2 says "You are
    controlling this computer" within a second or two, and PC-1's strip says "B is controlling this computer".
15. **PC-2: use PC-1.** Open Notepad and type `Test é € 123`. Select it, Ctrl+C, End, Ctrl+V, Ctrl+Z. Right-click.
    Double-click a word. Scroll a long page. Move the pointer into all four corners.
    *Pass:* the text appears as typed; copy, paste and undo happen; clicks land where B points, corners included; any
    delay feels shorter than half a second.
16. **PC-1: move your own mouse** while B is moving theirs.
    *Expected:* both move the pointer; nothing pauses when the app shares its own screen. A stops B with **Stop
    control** or the shortcut (step 21). (When a browser shares, A's mouse pauses B: step 26.)
17. **A window running as administrator.** On PC-1 open Task Manager and bring it to the front. B clicks in it.
    *Pass:* B's clicks do nothing there, and **within a second B's room says a window running as administrator is in
    front**. Bring an ordinary window forward: the notice goes and input works again.
18. **Two screens** (if PC-1 has them).
    *Pass:* B sees **1** and **2** beside the full screen button, switches, and clicks land on the screen shown.
19. **The Keys menu.** B opens **Keys** and tries Task Manager, Start menu, Run…, Show the desktop, Switch window.
    *Pass:* each does on PC-1 what it does at its own keyboard.
20. **Sound.** On PC-1 switch on **Share this computer's sound** in App settings and play a video.
    *Pass:* the switch says **Saved**; B hears it within a second or two. Switch it off: the sound stops, the picture
    carries on.
21. **Stop.** PC-1: press Ctrl+Alt+Shift+S (on a Mac, ⌘⌥⇧S).
    *Pass:* control ends at once; B's mouse no longer moves PC-1's pointer; PC-2 says why.
22. **PC-1: End session.**
    *Pass:* PC-2's room closes and says the session ended. **Sessions** lists it, and its timeline reads in words —
    "Control of the computer allowed", not "control granted".

**D. A browser shares, the app carries control**

23. **PC-1, in the app's Remote tab: Start session.** Check that **Entire screen** is selected. Create session.
    *Pass:* the room opens with the session code shown large. In a plain browser, Start session begins on Safe Share and
    a sentence underneath says a helper can look but not control.
24. **B joins by the code; A allows.** A clicks **Share** and shares the entire screen.
    *Pass:* PC-2 shows PC-1's screen within about 5 seconds.
25. **PC-1: Control → Control from this computer → Use \<PC-1\>**, then **Allow while I share** in the app.
    *Pass:* the panel says "Ready", in green.
26. **PC-2: Request control.** On PC-1, in the browser:
    *Pass:* a strip above the picture says "B is asking to control this computer", the Control panel opens by itself
    with a caution and the question, and the browser tab's title names B. **Allow control** works as in step 14.
    Then A moves their own mouse while B is moving theirs: PC-2 shows "Paused — someone is using that computer", and
    B's control comes back about 2 seconds after A stops.
27. **Close PC-1's browser tab without ending the session.**
    *Pass:* within a minute the app on PC-1 says the sharer has gone, and **Get help** can start a new session at once.

**E. Windows prompts and Ctrl+Alt+Del** (needs the background service)

28. **Box ticked.** In a Get help session, when B asks for control, A ticks **Also let them answer Windows
    administrator prompts** and allows. On PC-1 start something that shows a "Yes/No" prompt.
    *Pass:* PC-2's picture switches to the prompt, the room says "You can answer it", and B's click on **Yes** works.
29. **Box not ticked.** Repeat without the box.
    *Pass:* the room says "Ask the person at the computer to answer it", and B's input does nothing on the prompt.
30. **Ctrl+Alt+Del, box ticked.** B chooses **Keys → Ctrl+Alt+Del**.
    *Pass, one of two:* the Windows security screen appears and B can choose from it; or the room says "Windows on that
    computer does not let an app send it". **Write down which** — it depends on that PC's Windows policy.
31. **Ctrl+Alt+Del, box not ticked.**
    *Pass:* nothing happens on PC-1, and the room says it can only be sent when the box was ticked.
32. **Lock screen, unattended.** Turn on the matching switch in App settings, connect unattended (step 37), lock PC-1.
    *Pass:* PC-2 sees the lock screen and can type the password.

**F. The clipboard**

33. **With the box.** A ticks **Also share the clipboard** when allowing control. B copies a sentence in another tab,
    returns to the room, clicks into Notepad on PC-1 and presses Ctrl+V.
    *Pass:* B's sentence is pasted.
34. **Without the box.**
    *Pass:* Ctrl+V pastes whatever was on PC-1's own clipboard. Nothing of B's is sent.
35. **The other way.** Copy something on PC-1, paste on PC-2.
    *Pass:* it is **not** there.

**G. Unattended, and your own computers**

36. **Two-step verification.** On PC-2 (account A) open Settings → Two-step verification, scan the code with an
    authenticator app, confirm, save the recovery codes.
    *Pass:* signing in again asks for a code first.
37. **Unattended.** In Remote → Computers, **Allow unattended** for PC-1 (it asks for a fresh code). Leave PC-1 alone.
    On PC-2, **Connect** to PC-1.
    *Pass:* PC-1's screen appears and can be controlled with nobody there; PC-1 shows an amber banner. Account B
    cannot connect to it. The Computers page has **Remove** and **History** for each computer.

**H. A Mac**

38. **Repeat C with the Mac as the computer being helped.**
    *Pass:* as on PC-1, and: the helper sees the Mac's **pointer** move, the same size as on the Mac; ⌘-click and
    ⇧-click select files in Finder; the consent dialog says "this Mac" and offers no clipboard box and no Windows
    box.
39. **Keys on a Mac.** B opens **Keys**.
    *Pass:* Force Quit, Spotlight search, Switch app, Mission Control, Close window, Quit app, Interrupt, Esc — not
    the Windows list. Each does what it says.
40. **A PC helping a Mac.** From PC-2, in TextEdit on the Mac: Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+Z.
    *Pass:* select all, copy, paste and undo happen.
41. **A Mac helping a PC.** Sign in as B on the Mac's browser and help PC-1. In Notepad: ⌘A, ⌘C, ⌘V, ⌘Z. Tap ⌘ alone.
    *Pass:* select all, copy, paste and undo happen; the Start menu does **not** open.
42. **Known gap — record it.** On a Mac with a British or German keyboard, type `#` or `@` (with Option) into Notepad
    on PC-1.
    *Expected today:* nothing is typed.

**I. Files, chat, a phone, and endings**

43. **Files and chat**, between two browsers (section D's kind of session): send a 5 MB file each way; try a 30 MB one;
    send a chat message each.
    *Pass:* files arrive intact; 30 MB is refused before sending; messages arrive within a second.
44. **A phone.** Sign in on a phone's browser.
    *Pass:* **Join session** is the first, highlighted card; "Share my screen" says it is not available in this
    browser. In a room, every toolbar button is on screen and the picture fills most of it.
45. **A declined helper.** B joins by a code; A presses **Decline**.
    *Pass:* B is told, and the page stops waiting.
46. **Leave and End.** As a helper, press **Leave**; in another session, press **End**. As the person being helped,
    press **End**.
    *Pass:* a helper's Leave takes only the helper out, and a helper's End asks "End it for everyone?" first. The
    person being helped has **End** alone, and it ends their session at once.
47. **The time limit.** Leave a session running.
    *Pass:* at 55 minutes the room says it ends in 5 minutes. At 60 it ends. Write down when.
48. **Another network.** Repeat steps 9–15 with B on a phone hotspot.
    *Pass:* the picture appears within about 10 seconds. Write down what the **Security** panel says about the
    connection — and that it speaks to B about "the person sharing", not as if B were sharing.
49. **Offline.** Unplug PC-1's network and open **Get help**.
    *Pass:* a notice on every tab says the computer is not connected, and why; **Get a code** is disabled.
50. **Sign out and uninstall.** **Sign this computer out**, then uninstall.
    *Pass:* the web's Computers page shows it as removed; `C:\ProgramData\AICOUNTLY\Remote` is gone.

**Results sheet.** Copy this table and fill in one row for each step:

| Step | PC-1 | Mac | Network | Build | Pass or fail | What you saw |
|---|---|---|---|---|---|---|

---

## 14. Roadmap

### To make this pass live

Nothing in §2.1 reaches anybody until these are done, in this order. Each is the owner's to start.

| Step | What it brings | Note |
|---|---|---|
| 1. Merge the branch into `main`. | — | The suites are green locally (§11). |
| 2. Deploy to production (web and API). | The room's new behaviour, the web's fixes, limits per caller, no relay bookings for idle PCs, Console's outage isolated, the sweep job (the deploy installs it), the previous version's files kept from the *next* deploy on. | The deploy window is 20:00–08:00 IST. Existing desktop builds keep working with it. |
| 3. Restart the signalling service (the deploy does). | The relay's fixes. | Everybody reconnects within a few seconds. Check the line it logs: "accepting up to N connections". |
| 4. Release a Windows and a Mac build from the merged commit. | The desktop window's fixes, the refused-input notice, Ctrl+Alt+Del, the Mac's pointer. | The release compiles the Mac code first; one small Mac-only function added in this pass has not been compiled on a Mac yet. |
| 5. Publish the downloads. | People can get the build. | `publish-downloads.yml` |
| 6. Run `desktop/tests/MANUAL.md` on a PC and a Mac. | Proof. | Half a day. |

### The next two weeks

| When | What |
|---|---|
| Days 1–2 | The six steps above. Check the guest-link setting (PB-5). Ask whoever runs the server the six questions in §9.6, and raise the relay's open-file limit. |
| Days 2–7 | The pilot script (§13) with 2 PCs and a Mac, then 5–10 people doing real help sessions. Fix what they find. |
| Week 1 (small items) | Decide the analytics question and flip the two switches accordingly (P0-9). A security contact, and stop the deploy excluding `.well-known/` (P1-10). Turn CI back on for pull requests if the minutes can be afforded, or keep running the suites locally and say so (P0-5). Ask who is waiting every 5 s, drop the unused "I am here" call (P2-15, P2-16). Edit the newest release's notes (P2-19). |
| Week 2 | Start the Terms, privacy notice and EULA with counsel (P0-2). Write the retention schedule and the purge job (P0-3). "Report this person" and account suspension (P0-4). Uptime monitoring, alerts and a backup restore test (P0-5). Decide pricing and the free tier (P0-7). The three indexes (P1-16). |

### The next quarter

| Month | What | Result |
|---|---|---|
| 1 | Finish every P0. Sign the builds when the certificates arrive, then switch on the update signature check (P0-1) and `uiAccess` for elevated windows (P1-2b). Measure TURN capacity, then switch admission to `enforce` (P0-7). | **Public launch possible.** |
| 1–2 | The Option key from a Mac (P1-14). Clipboard through a companion and on a Mac (P1-5). Extending a session (P1-6). Crash reporting and sending logs (P1-7). Remove the older desktop sign-in on 31 December (P1-17). | A helper stops noticing which computer they are on. |
| 2–3 | The relay tells the room when something changed, instead of the room asking (P1-15). A connection pooler; relay bookings in the database (§9.5). | Thousands of sessions on one server instead of hundreds. |
| 2–3 | Connect by ID (P1-4). A hardware encoder (P1-1). Chat and files in the app's own sessions (P2-20). | Closer to what people know from other tools. |
| Later | Recording, Wake-on-LAN, restart, mobile apps, Linux, languages, a per-user installer, a second server (P2). | — |

---

## Appendix A: documents corrected in this pass

| Document | What it said | What is true |
|---|---|---|
| `README.md` | Control "on a Windows machine". | A Mac hosts and is controlled too. What only Windows does is listed as such. |
| `README.md`, `docs/desktop/WINDOWS_RELEASE.md` | No unsigned artifact can become a release. | `allow_unsigned` does exactly that, and every build so far used it. |
| `README.md`, `docs/DEVELOPMENT.md`, `docs/desktop/TESTING.md`, `docs/desktop/ARCHITECTURE.md`, `docs/DESKTOP_AGENT.md` | Test counts from several releases ago (211 / 104 / 27 / 432 / 36). | 1,118 / 593 / 46 / 732 / 137. |
| `docs/desktop/ARCHITECTURE.md` | macOS is "a companion and nothing else"; capture refused; "never unattended". | It hosts with Core Graphics capture, and is reachable unattended while the app runs. |
| `docs/DESKTOP_AGENT.md` | "macOS and Linux are not built"; the control switches all default off. | macOS is built. For a personal account control, unattended access and the clipboard are permitted by default. |
| `docs/desktop/TESTING.md` | Tests run "on every push". Nothing native has run. | No workflow runs on a push. Builds are installed and run by people; no manual pass is recorded. |
| `docs/DEPLOYMENT.md`, `ci.yml` | CI runs on every pull request and push to `main`. | Only when started by hand, or by a deploy. |
| `docs/DEPLOYMENT.md` | — | How to raise the relay's open-file limit. |
| `docs/SECURITY.md` | Control needs the app as the companion of a browser share. | The app also shares its own screen. |
| `docs/SECURITY.md` | The web's policy allows the named origins and "nothing wider". | `connect-src` allows any `https:` and `wss:` address. |
| `docs/SECURITY.md` | Analytics is Google Analytics, governed by the banner. | A second pipeline runs whatever the banner's answer. |
| `docs/BROWSER_SUPPORT.md` | Nothing about controlling a computer; "to send Esc, leave full screen first". | A table of what each browser can do while controlling; Esc is sent in Chrome and Edge, and from the Keys menu everywhere. |
| `docs/desktop/WINDOWS_AGENT.md` | Keys are injected as Unicode, never as keys. Ctrl+Alt+Del "does nothing" when the policy forbids it. | A shortcut is pressed as a key. The helper is told which of three outcomes happened. |
| `docs/ARCHITECTURE.md` | The app holds nothing that is a user's credential. | Its Remote tab is the web app signed in as the person. |
| `desktop/tests/MANUAL.md` | A Windows prompt: "No input reaches it". | With the box ticked the helper answers it. Sections 4d–4f are new. |

## Appendix B: where the numbers came from

Everything measured in this document was measured in the session that wrote it, on one Linux machine with four cores,
and can be measured again the same way:

| What | How |
|---|---|
| The five suites | `backend`: `vendor/bin/phpunit` against PostgreSQL 16. `web`: `npm run typecheck && npm test && npm run build`. `signalling`: `npm test`. `desktop`: `cargo test --workspace`, `cargo clippy --workspace --all-targets -- -D warnings`, the same for `--target x86_64-pc-windows-gnu -p aicountly-remote-desktop`, `cargo fmt --all -- --check`, and `npm run typecheck && npm test`. |
| The encoder | `cargo test -p remote-webrtc --release --no-default-features --features bundled-openh264 --lib encoding_fits_the_frame_interval -- --ignored --nocapture` |
| The web's load times, contrast and keyboard order | Scripts that drive a real browser against the built app with a stand-in for the server. They are not in the repository. |
| The desktop window's pictures and contrast | The same kind of script, against the window's own pages. Not in the repository. |
| The relay load test | A script that starts the repository's relay and opens thousands of connections to it. Not in the repository. |
| The server's request sums and the slow reads | Read from the code; the reads were timed in a throwaway database with a million sessions. |

What those tools printed, and ten before-and-after pictures, are kept beside this document in
`docs/audit/evidence-2026-11-14/`.

The three sets of scripts lived in the working area of the session and are gone when it ends. If the audit is to be
re-run again, adding them to the repository first would save rebuilding them.
