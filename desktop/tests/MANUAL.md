# Manual and end-to-end tests

The pass that has to happen on a real Windows machine before a release. It is
not a formality: every native call in `src-tauri/src/platform/windows/` and
`agents/windows-service/` is compiled and type-checked by CI and **has not been
executed**. Automated coverage is [../../docs/desktop/TESTING.md](../../docs/desktop/TESTING.md).

Work through it in order. Where a step says what should *not* happen, that is
the test — a product that quietly does the extra thing is the failure mode
worth catching.

## What you need

| | |
|---|---|
| A clean Windows 11 (or 10 22H2) virtual machine | one that has never had the agent installed |
| A second machine, or another browser profile | to be the person connecting |
| An AICOUNTLY account | a brand-new one is enough — no organisation is needed to register a computer |
| A second account **without** `remote.control.request` | for the refusal tests |
| A deployment | sandbox is fine; note which one, results differ by policy |

Record the build under test — the version, the commit and whether it is signed
— at the top of your results. "It worked on the build I had" is not a result.

---

## 1. Installation

| # | Do | Expect |
|---|---|---|
| 1.1 | Run `AICOUNTLY-Remote-x64-Setup.exe` on the clean VM | One UAC prompt, because it is a per-machine install |
| 1.2 | *(unsigned build)* Read the SmartScreen warning | It appears. **This is correct.** Do not add an exclusion; note it and continue |
| 1.3 | `sc.exe query AicountlyRemoteService` | `STATE : 4 RUNNING` |
| 1.4 | `services.msc` | "AICOUNTLY Remote Service", Automatic (Delayed Start), Local System |
| 1.5 | `Get-Acl C:\ProgramData\AICOUNTLY\Remote \| Format-List` | SYSTEM and Administrators full; Users **modify**; **no** Everyone entry. Modify, not read — the tray application writes all three files in there as the signed-in person; see docs/desktop/WINDOWS_AGENT.md |
| 1.6 | Start Menu | An "AICOUNTLY Remote" entry that launches the tray application |
| 1.7 | Task Manager → Startup apps | "AICOUNTLY Remote", enabled, removable |
| 1.8 | Check UAC settings before and after | **Unchanged.** `ConsentPromptBehaviorAdmin` and `PromptOnSecureDesktop` untouched |
| 1.9 | Check Windows Security | No exclusion added; no setting changed |
| 1.10 | Finish the installer with "Run AICOUNTLY Remote" ticked | The window opens |
| 1.11 | Sign out and back in (or restart) | The tray icon appears and the window does **not** open: the Run entry passes `--background` |
| 1.11a | `Get-ItemProperty "HKLM:\Software\Microsoft\Windows\CurrentVersion\Run" -Name "AICOUNTLY Remote"`, then `Test-Path` the executable in it | The path exists. It names `AicountlyRemote.exe` — the `[[bin]]` name, not the product name. A Run value naming a missing file is not an error Windows reports to anybody, so the tray application simply never started |
| 1.11b | `Get-ChildItem C:\ProgramData\AICOUNTLY\Remote\logs` | `agent.log` and `service.log`, both with recent lines in them. Neither contains a key, a token, a clipboard payload or a frame |
| 1.12 | With the agent already running, start `"AicountlyRemote.exe" --background` again | Nothing opens. Start it again without the flag: the existing window comes to the front |

### 1a. Upgrade and reinstall over a running install

| # | Do | Expect |
|---|---|---|
| 1a.1 | Install a newer build over the running one, service **running**, agent open | No "file in use" prompt and no reboot request; the installer waits for the service to stop |
| 1a.2 | `sc.exe query AicountlyRemoteService` afterwards | `RUNNING`, registered once |
| 1a.3 | Open `services.msc` and leave it open, then reinstall | The service is registered afterwards (the installer retries past "marked for deletion"), or says plainly that it could not be |
| 1a.4 | Install the same build twice in a row | Still one service, one Run entry, one Start Menu entry |

## 2. Registration

**Do this as an ordinary, non-administrator account**, signed in normally, with
no elevation anywhere. That is who registers a computer, and it is the case a
read-only data directory broke: every write — the key, the enrolment record,
the configuration — failed with "Access is denied" on a freshly installed
product.

| # | Do | Expect |
|---|---|---|
| 2.1 | Open the agent | "This computer is not registered", and a button to do it |
| 2.2 | Register | A browser tab opens to AICOUNTLY Remote's sign-in page; the agent shows a short code and waits |
| 2.3 | In the browser: sign in if needed, check the code matches, confirm. No organisation is asked for | The agent picks it up on its own within a few seconds — no code to type, nothing else to do at the machine |
| 2.4 | Compare the fingerprint the agent now shows with the Computers page | **Identical**, character for character |
| 2.5 | `dir C:\ProgramData\AICOUNTLY\Remote` | `device-signing-key.key` exists |
| 2.6 | `type` that file | Binary, and DPAPI-protected. Not a readable key |
| 2.7 | Search the whole disk for the base64 public key | Found only in the agent's own memory and the API response; **not** in a log, a `.env`, the registry, or any file it wrote |
| 2.8 | Restart the machine, wait, reload the Computers page | The device shows **Online** without anybody signing in to the agent |
| 2.9 | Try to register a *second* machine with the same key file copied across | Refused — the fingerprint is unique |
| 2.10 | Register again, but decline the code in the browser instead of confirming | The agent says the sign-in was declined and offers to try again |
| 2.11 | Register again, close the browser tab, and wait past the code's expiry (see `Config\Remote::$desktopSignInCodeTtlSeconds`) without confirming | The agent says the code expired and offers to start again |
| 2.12 | Already signed in to AICOUNTLY in the default browser before clicking Register | The browser tab still opens and still needs the code confirmed — this is the case the old loopback sign-in silently failed on; it must not silently fail here |

## 2a. Settings

| # | Do | Expect |
|---|---|---|
| 2a.1 | As a **standard** user, change Screen quality and press Save | It saves. `config.json` in `C:\ProgramData\AICOUNTLY\Remote` changes, and the service picks it up on its next start. No elevation, no "Access is denied" — the data directory grants the Users group modify for exactly this |
| 2a.2 | Then open `config.json` in Notepad as that same user | The new value is in it, and the file is the machine-wide one — not a per-user copy somewhere else |
| 2a.3 | Turn "Keep running when the window is closed" **off**, Save, close the window | The agent exits and the tray icon goes |
| 2a.4 | Same setting off, but with a session running, close the window | The window hides and the agent keeps running: a running session is never left without its tray icon |
| 2a.5 | The Settings page | No "Start when I sign in" switch; the text points to Task Manager → Startup apps |

## 3. Presence and policy

| # | Do | Expect |
|---|---|---|
| 3.1 | Watch the agent's status for two presence intervals | It stays Online; nothing flaps |
| 3.2 | Disconnect the network | Within a minute: "offline", with a reason, and a retry that backs off |
| 3.3 | Reconnect | It recovers on its own. No restart, no click |
| 3.4 | Sleep the machine, wake it | It recovers |
| 3.5 | Turn `allow_remote_control` **off** in Remote policy | Within one poll the agent's Permissions panel says remote control is not permitted |
| 3.6 | Turn it back on | It comes back |
| 3.7 | `sc.exe stop AicountlyRemoteService` while the agent is open | The agent says the background service is not running and does **not** crash. The About panel says so within a few seconds rather than hanging — the IPC round trip has a three-second deadline |
| 3.8 | `sc.exe start` it | It reconnects |
| 3.9 | **Quit** the agent from the tray (not just close the window) and wait two presence intervals | The Computers page still shows the machine online. The service holds its own device credential — `service.log` has "this computer authenticated with AICOUNTLY Remote" and an expiry — and reports presence without the window. This is the whole point of the service |
| 3.10 | Sign out of Windows entirely, wait two presence intervals | Still online. That is what "reachable before anybody signs in" means, and it is what a machine with no credential in the service could never do |
| 3.11 | Open the agent again and read the About panel | The background service reports **enrolled**, with this machine's device uuid and the same key fingerprint the window shows — not "not registered" |
| 3.12 | Revoke the device on the Computers page, then watch `service.log` | It stops reporting and says the computer is no longer accepted. It does **not** keep retrying for ever, and it does **not** stop the service |
| 3.13 | Register the machine again from the window, **without** restarting the service | `service.log` shows the service authenticating within seconds, not at its next interval — the window tells it to look — and the machine is online again. A revoked-then-reregistered machine must not need a service restart |

## 4. Attended control

| # | Do | Expect |
|---|---|---|
| 4.1 | From the browser, start a session with the machine | The agent shows a session running, in the window **and** in the tray |
| 4.2 | Look at the tray tooltip | It names the session and who is connected |
| 4.3 | Browser: Request control | The agent shows a consent dialog naming **who** (and the organisation, when there is one), a red caution — only allow this if you know them and asked for help; AICOUNTLY never calls to ask for control — what control means, and how to stop it (the button, the tray icon, and the shortcut written out). The keyboard is on the dialog, not on either button: pressing Enter or Space straight away answers nothing. **Esc** is "Not now" |
| 4.3a | Do 4.3 with the window on the **Remote** tab (where a signed-in computer opens) | The dialog takes the whole window — the embedded site is put away while the question waits — and **both buttons can be reached**. Before, the dialog was drawn under the site with its buttons below the bottom edge of a window that cannot scroll. Answer it: the site comes back |
| 4.4 | Do nothing for a minute | No input reaches the machine. Move the mouse in the browser: the pointer does not move |
| 4.5 | Agent: **Not now** | The browser says declined and offers to ask again. Still no input |
| 4.6 | Request again → **Allow control** | The browser shows Controlling; the persistent banner appears |
| 4.7 | Move the mouse in the browser | The pointer moves to the same place — check the corners and, on a multi-monitor machine, the second screen |
| 4.8 | Type a sentence including a non-ASCII character (`é`, `€`) | The character typed appears, not the key in that position |
| 4.9 | Scroll | It scrolls, and one browser notch is about one notch |
| 4.10 | Right-click | A context menu on the remote machine; the browser's own menu does **not** open |
| 4.11 | Agent: **Stop control** | Input stops immediately. Keep moving the mouse in the browser: nothing happens |
| 4.12 | Do 4.11 again with the network unplugged | It still stops immediately. **This is the test that matters most** |
| 4.13 | Browser: Stop controlling | Both ends agree that it stopped |
| 4.14 | Session detail → the timeline | `CONTROL_REQUESTED`, `_DENIED`, `_GRANTED`, `_REVOKED`, with who and when |

### 4a. The session stays up, and control works the way a person expects

Each of these was a fault found by reading the code and reproduced in a test,
not on a real machine. They are the first things to check on one.

| # | Do | Expect |
|---|---|---|
| 4a.1 | Start a help session from the app ("give your code"), join it from a browser, and **leave both alone for five minutes** | The picture keeps moving and the session is still running. Before this change the session ended about two minutes in, when the browser's room token expired and the relay announced the helper as gone |
| 4a.2 | With the picture up and **nobody** controlling, watch a clock on the shared screen | It ticks. Before, the picture froze the moment the control channel opened |
| 4a.3 | Close the agent's window to the tray, then request control from the browser | The window **comes forward** with the consent dialog |
| 4a.4 | **Allow control**, and start a stopwatch | The browser shows Controlling **within a second or two**, and the pointer follows at once. Before, it could take twenty-five seconds |
| 4a.5 | Move the mouse in circles for ten seconds | Smooth. No pause every three seconds, no burst of movement after a still moment |
| 4a.6 | In Notepad on the controlled machine: type text, then Ctrl+A, Ctrl+C, End, Ctrl+V, Ctrl+Z | Select all, copy, paste and undo all happen. Before, every shortcut was dead: a letter was injected as a character with no key behind it |
| 4a.7 | Alt+F (a menu), Alt+F4 on a spare window, Win+R, Win+D, Ctrl+Shift+Esc | Each does what it does at the keyboard. Win and Alt+Tab reach the remote machine only in **full screen** (the browser keeps them otherwise) — check both |
| 4a.8 | On a controller with AltGr (a German or French layout): type `@`, `€`, `{` | The character appears; no shortcut fires |
| 4a.9 | Hold Ctrl in the browser, Alt+Tab away to another window, release, come back and type | Plain text arrives. Ctrl is not stuck down on the controlled machine |
| 4a.10 | Double-click a word in Notepad, and a file on the desktop | The word is selected; the file opens |
| 4a.11 | Reload the helper's browser tab while controlling | The helper comes back to the same session, the picture returns within a few seconds, and — once readmitted — control works without anything being sent "more times than before" |
| 4a.12 | Close the helper's tab and wait | The session ends on the machine within about a minute, not immediately and not never |
| 4a.13 | Leave the shared screen completely still, then join from a second browser profile after the first has left | The picture appears at once, not when something next moves |

### 4b. Two screens

| # | Do | Expect |
|---|---|---|
| 4b.1 | On a machine with two displays, grant control | Beside the full screen button the browser shows **1** and **2** |
| 4b.2 | Click **2** | The picture changes to the second display within a second |
| 4b.3 | Click in the four corners of the second display's picture | Each click lands in that corner **of the second display**, not the first |
| 4b.4 | Drag a window from one display to the other at the machine, then switch in the browser | It is where it was left |
| 4b.5 | Stop control | The switcher goes. The picture stays on the display it was showing |
| 4b.6 | With one display | No switcher is offered |

### 4c. Sound

| # | Do | Expect |
|---|---|---|
| 4c.1 | Fresh install: App settings | "Share this computer's sound" is there and **off** |
| 4c.2 | With it off, play a video on the machine during a session | The helper hears nothing. `agent.log` has no "sound is being shared" line |
| 4c.3 | Switch it on (it saves at once and says **Saved** — there is no Save button for it), play the video again | The helper hears it within a second or two — telephone quality, in step with the picture. `agent.log`: "this computer's sound is being shared" with the mixer's rate and channels |
| 4c.4 | Switch it off again **during** the session | The sound stops within a second; the picture carries on |
| 4c.5 | Pause the video for ten seconds, then play | Sound resumes cleanly, not sped up or stretched |
| 4c.6 | Unplug the headphones (or change the default output) mid-session | A gap of a couple of seconds, then sound again from the new device |
| 4c.7 | Open the room from a pasted link in a new tab and touch nothing | The **picture** is there. The sound starts at the first click or key press |
| 4c.8 | On a Mac | The setting is not offered: a Mac hosts without sound |

### 4d. What the helper is told, and what they can reach

The helper's room has a **Keys** button beside the full screen button while
they are controlling: the combinations a browser keeps for itself or a keyboard
cannot send.

| # | Do | Expect |
|---|---|---|
| 4d.1 | While being controlled, open **Task Manager** (or any program started with "Run as administrator") and bring it to the front. Helper: click and type in it | Nothing happens in that window — Windows does not allow it — and **within a second the helper's room says so**, above the picture: a window that runs as administrator is in front, and the person there has to use it or close it. `agent.log`: "the operating system refused an injected input event" |
| 4d.2 | Bring an ordinary window back to the front | The notice in the helper's room goes away within a few seconds, and input works again |
| 4d.3 | Grant control with **Also let them answer Windows administrator prompts** ticked. Helper: **Keys → Ctrl+Alt+Del** | The Windows security screen (Lock / Switch user / Task Manager) appears on the computer and in the helper's picture, and the helper can choose from it. This needs the background service **and** Windows' own policy to allow a service to raise it (`HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System\SoftwareSASGeneration` = 1 or 3). The app reads that value and never changes it |
| 4d.4 | The same on a computer where that policy value is absent or 0 (the default on a home PC) | Nothing happens on the computer, and the helper's room says **why**: "Ctrl+Alt+Del was not sent: Windows on that computer does not let an app send it. The person there can press it themselves." Record which case the test machine is |
| 4d.5 | Grant control **without** that box. Helper: **Keys → Ctrl+Alt+Del** | Nothing happens on the computer — nothing is opened that the person did not agree to — and the helper's room says it can only be sent when that box was ticked |
| 4d.6 | Helper: the other entries of the Keys menu — Task Manager, Start menu, Run…, File Explorer, Show the desktop, Switch window, Close window (on a spare window), Esc, Print Screen | Each does on the computer what it does at its own keyboard. None of them needs full screen |
| 4d.7 | Grant control with **Also share the clipboard** ticked. Helper: copy a sentence in another tab, come back to the room, click into Notepad on the shared screen and press **Ctrl+V** (on a Mac helper, ⌘V) | The sentence the helper copied is pasted — not whatever was on that computer's clipboard. Before, the paste key arrived and the text did not |
| 4d.8 | The same without the clipboard box | Ctrl+V pastes that computer's **own** clipboard. Nothing of the helper's is sent |
| 4d.9 | Copy something on the controlled computer, then paste in a document on the helper's own computer | It is **not** there. The consent dialog promises "Nothing you copy here is sent to them", and that is how it is built: the clipboard goes one way |
| 4d.10 | Helper on a **Mac**, controlling a Windows PC: in Notepad, ⌘A, ⌘C, ⌘V, ⌘Z; in File Explorer, ⌘-click two files | Select all, copy, paste and undo happen, and both files are selected: the helper's ⌘ is sent as Ctrl. Tapping ⌘ alone does **not** open the Start menu. The Windows key itself is in **Keys** (Start menu, Run…, File Explorer, Show the desktop). Before, ⌘V arrived as the Windows key and V and opened Windows' clipboard history |
| 4d.11 | Helper on a Mac keyboard where a character needs **Option** — `#` on a British layout (⌥3), `@` on a German one (⌥L), `€` — typed into Notepad on the PC | **Known not to work yet**: the character is sent with Alt held and Windows does not type it. Record what arrives. Characters typed without Option are unaffected |

### 4f. The window while somebody is connected

| # | Do | Expect |
|---|---|---|
| 4f.1 | During a session, scroll the Settings tab, and switch between every tab | The green strip at the top never leaves: who is connected, whether they can **see** or are **controlling**, and **Stop control** / **End session**. It says "can see this computer's screen" before control is allowed and "is controlling this computer" after |
| 4f.2 | Press **Stop control** while another button in the window is still working (press Check for updates first) | It stops at once. The stop buttons are never greyed out |
| 4f.3 | Before anybody joins a help session, read the strip | "Waiting for someone to join — nobody is connected yet", not a claim that somebody can see the screen |
| 4f.4 | Pull the network cable, then open **Get help** | A notice on every tab says this computer is not connected and why; **Get a code** is disabled rather than failing when pressed. Plug it back in: the notice goes without a restart |
| 4f.5 | Make any action fail (Get a code with the server unreachable) | The message is the reason in words — not "Something went wrong." |
| 4f.6 | Revoke the computer on the Computers page, then open the app | The sign-in screen says this computer was removed from the account and what signing in again does, instead of an unexplained sign-in screen |
| 4f.7 | Share from a browser with **Control from this computer** on, then close the browser tab without ending the session | Within a minute the app says the sharer has gone and returns to idle, and a **new** help session can be started straight away. Before, the app never told the server it had left, and the computer stayed "busy" until the old session timed out |
| 4f.8 | On a **Mac**: read the consent dialog and App settings | No "Windows administrator prompts" tick, no "Run as a Windows service" row and no sound switch; the stop shortcut is written with the Mac's keys (⌘⌥⇧S) and the dialog says "this Mac" |

### 4e. A Mac being helped

| # | Do | Expect |
|---|---|---|
| 4e.1 | Host a session from the Mac. Person at the Mac: move the pointer around | The helper sees an **arrow** move with it — on a Retina display, the same apparent size as on the Mac. Before, the Mac's picture had no pointer at all |
| 4e.2 | Helper, with control: move the mouse | The arrow on the Mac's picture follows the helper's own pointer, tip to tip, in all four corners |
| 4e.3 | With two displays: move the Mac's pointer onto the display that is **not** being shown | The arrow leaves the picture; it does not stick at the edge |
| 4e.4 | Leave the Mac completely alone for a minute and watch Activity Monitor | AICOUNTLY Remote's CPU use falls to a few per cent — a still desktop is no longer converted and encoded for every frame. Move the pointer: the picture follows at once |
| 4e.5 | While the desktop is still, join from a second browser profile after the first has left | The picture appears at once, not when something next moves |
| 4e.6 | Double-click a file in Finder, ⌘-click two files, ⇧-click a range, ⌥-drag a file to copy it | Each does what it does at the Mac |
| 4e.7 | Helper, controlling the Mac: open **Keys** | A Mac's list — Force Quit…, Spotlight search, Switch app, Mission Control, Close window, Quit app, Interrupt, Esc — not the Windows one (whose "Run…" was ⌘R on a Mac, which reloads the page in front). Try each: it does on the Mac what it says |
| 4e.8 | Helper on a **Windows PC**, controlling the Mac: in TextEdit, Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+Z, Ctrl+S; in Finder, Ctrl-click two files | Select all, copy, paste, undo and save happen, and both files are selected: the helper's Ctrl is sent as ⌘. Before, each arrived as a Control chord and did nothing |
| 4e.9 | The same helper: start `ping apple.com` in Terminal on the Mac, then **Keys → Interrupt** | It stops. Ctrl+C from the PC keyboard is ⌘C on the Mac (copy), so the Mac's own Control+C is in the menu |
| 4e.10 | Helper on a Mac, controlling the Mac | Nothing is changed on the way: ⌘ is ⌘ and Control is Control |

## 5. Control that must be refused

| # | Do | Expect |
|---|---|---|
| 5.1 | Sign in as the account **without** `remote.control.request` | No Request control button. Not a disabled one — none |
| 5.2 | `POST /sessions/{uuid}/control/request` with that account's token | 403 `CONTROL_REQUEST_DENIED` |
| 5.3 | Turn `allow_remote_control` off, then ask | 403 `REMOTE_CONTROL_NOT_ALLOWED`, even for a user with an explicit ALLOW |
| 5.4 | Grant control, then turn the company switch off mid-session | The browser stops offering control; the agent's panel agrees |
| 5.5 | Two browsers ask for control at once, grant one, grant the other | 409 `CONTROL_ALREADY_GRANTED`, naming who has it |
| 5.6 | Start a **browser-to-browser** session | No Request control button anywhere, and the panel says a browser cannot be controlled |
| 5.7 | Replay a captured control message on the data channel | Dropped. `rejected_control_messages()` goes up |

## 6. The clipboard

| # | Do | Expect |
|---|---|---|
| 6.1 | Grant control **without** ticking the clipboard | Copying in the browser changes nothing on the machine |
| 6.2 | Grant with the clipboard ticked, copy text, paste on the machine | It arrives |
| 6.3 | Copy 10 MB of text | Refused by the bound. Nothing crashes |
| 6.4 | Copy an image | Nothing is transferred. Text only, by design |
| 6.5 | Turn `allow_clipboard_sync` off, grant with it ticked | The grant succeeds; the clipboard stays off |
| 6.6 | Audit trail | `CLIPBOARD_SYNCED` records that it was used. **No clipboard content anywhere** |

## 7. Unattended access

| # | Do | Expect |
|---|---|---|
| 7.1 | Computers page → the device | Unattended access is **off**. It was never implied by anything in section 4 |
| 7.2 | Allow unattended → read the dialog | It says what will happen, that it is audited, that the machine still shows a session, and that it can be switched off from either end |
| 7.3 | Confirm | It turns on, with who and when |
| 7.4 | `POST /devices/{uuid}/unattended/enable` **without** `confirm` | Refused |
| 7.5 | Sign out of Windows entirely, then Connect from the browser | The session is created and the machine is reachable. **There is no picture** — nobody is signed in, so there is no desktop; see WINDOWS_AGENT.md §4 |
| 7.6 | Sign in at the machine, connect unattended | A session starts with nobody approving it. The agent shows it, in the tray and in the window, marked as unattended |
| 7.7 | Audit trail | `UNATTENDED_SESSION_STARTED`, with who started it |
| 7.8 | Agent → **Turn off unattended access** | It goes off, server-side, with no permission needed |
| 7.9 | Try to connect unattended again | Refused |
| 7.10 | Turn `allow_unattended_access` off for the company, with a device switched on | Connecting is refused. The company switch wins |
| 7.11 | Quit the agent's UI while unattended access is on | The tray menu distinguishes **Close window**, **Quit AICOUNTLY Remote** and **Disable unattended access**. Quitting does not silently remove an administrator's setting |

## 8. Restart

| # | Do | Expect |
|---|---|---|
| 8.1 | With `allow_device_reboot` **off**, ask for a restart | Refused |
| 8.2 | Turn it on, no active session, ask | Refused — there is no session to authorise it |
| 8.3 | With control granted, ask | Windows shows its own 30-second notice naming AICOUNTLY Remote and who asked |
| 8.4 | Leave an unsaved document open | It is **not** force-closed |
| 8.5 | Ask twice in a minute | The second is refused: the cooldown |
| 8.6 | After the restart | The session is over. Nothing claims it will resume. The device comes back Online on its own |
| 8.7 | Audit trail | The restart is recorded before it happened |

## 9. The limits Windows imposes

These are the tests that prove the product is honest. Each one **should** fail
to do the thing — except where the person at the computer ticked **Also let
them answer Windows administrator prompts**, which is the one way a helper is
let through, and is checked both ways.

| # | Do | Expect |
|---|---|---|
| 9.1 | Control granted **without** the administrator-prompts tick. Trigger a UAC prompt on the machine | The picture stops at the prompt. The helper's room says a Windows administrator prompt is open and to ask the person at the computer to answer it. **No input reaches it** |
| 9.1a | The same with the tick, and the background service running | The helper **sees** the prompt and can answer it; the room says "You can answer it." Stop control: the next prompt is out of reach again |
| 9.1b | With the tick but the background service stopped (`sc.exe stop AicountlyRemoteService`) | As 9.1: the tick alone opens nothing. The room says to ask the person at the computer |
| 9.2 | Lock the machine while controlling, without the tick, then with it | Without: as 9.1. With: the lock screen is shown and can be typed into |
| 9.3 | Try to control an elevated application (an admin PowerShell) | Input is refused — Windows does not let an ordinary program type into one running as administrator, with or without the tick — and the helper's room says why within a second (4d.1) |
| 9.4 | Share a window with `WDA_EXCLUDEFROMCAPTURE` set | Black. That is the flag working |
| 9.5 | Confirm nothing changed | UAC settings, Defender exclusions, the Secure Desktop setting: all as they were before installation |

## 10. The IPC channel

| # | Do | Expect |
|---|---|---|
| 10.1 | As a **standard** (non-admin) user, open `\\.\pipe\AicountlyRemote.Agent` | Refused unless that user is the interactive one |
| 10.2 | From a low-integrity process | Refused by the mandatory label |
| 10.3 | From another machine (`\\host\pipe\…`) | Refused — `PIPE_REJECT_REMOTE_CLIENTS` |
| 10.4 | Connect, and send a frame claiming version 99 | `VERSION_MISMATCH`, with the fix in the message |
| 10.5 | Connect and send a request without `Hello` | `NOT_AUTHENTICATED` |
| 10.6 | Send a frame claiming 4 GB of payload | Refused before anything is allocated; the service does not grow |
| 10.7 | Connect nine times at once, hold them all open, then close them and connect again | The ninth is dropped and the service **stays responsive** — including afterwards. A busy pipe is not a broken one, and the accept loop used to treat "every instance in use" as fatal and stop serving altogether |
| 10.8 | Connect and go silent | Dropped after the idle timeout |
| 10.9 | `netstat -ano \| findstr LISTENING` | **No** listening TCP port belongs to either AICOUNTLY process |

## 11. Uninstalling

| # | Do | Expect |
|---|---|---|
| 11.1 | Apps → AICOUNTLY Remote → Uninstall | It completes without a reboot |
| 11.2 | `sc.exe query AicountlyRemoteService` | "does not exist" — not "marked for deletion" |
| 11.3 | `dir C:\ProgramData\AICOUNTLY` | Gone, including `device-signing-key.key` |
| 11.4 | The `Run` registry value | Gone |
| 11.5 | `%ProgramFiles%\AICOUNTLY Remote` | Gone |
| 11.6 | Computers page | The device is still listed. **Correct** — the server is the authority, and an administrator removes it |
| 11.7 | Reinstall and open the agent | Not registered. Unattended access has **not** silently come back |
| 11.8 | Before uninstalling, create `C:\ProgramData\AICOUNTLY\Remote\device-signing-key.key.new` by hand (what a crash mid-write leaves) | After uninstall, `C:\ProgramData\AICOUNTLY` is gone, including that file |
| 11.9 | Uninstall with the Services console open | The service is not left "marked for deletion" after the console is closed; no reboot needed |

## 12. Signed builds

Only meaningful once a signing identity exists.

| # | Do | Expect |
|---|---|---|
| 12.1 | `Get-AuthenticodeSignature` on the installer, both executables | `Valid`, and the subject is the real publisher |
| 12.2 | The same, checking `TimeStamperCertificate` | Present |
| 12.3 | `pwsh -File scripts/windows/verify.ps1 -Path <downloaded installer>` | Passes on the **downloaded** file, not only the built one |
| 12.4 | Install on a clean VM with SmartScreen on | No warning (EV), or a diminishing one as reputation builds (OV) |
| 12.5 | The file's Details tab | Publisher and copyright are filled in and correct |
| 12.6 | Compare against `SHA256SUMS.txt` in the release | Identical |

---

## Recording a run

```
Build:      1.0.0  (commit ………, signed / UNSIGNED)
Deployment: sandbox / production
Machine:    Windows 11 24H2, build 26100, x86_64
Date:       ………
Tester:     ………

Section  Result   Notes
1        pass
…
9.1      pass     screen froze at the UAC prompt; agent said "Secure Desktop"; no input
…
```

A failure gets an issue with the section number, what happened, and what was on
screen. A step that could not be run — no signing identity yet, no second
machine — is recorded as **not run**, never as passed.
