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
| An AICOUNTLY account with `remote.device.enrol` | in a company whose plan includes `desktop_devices` |
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
| 1.5 | `Get-Acl C:\ProgramData\AICOUNTLY\Remote \| Format-List` | SYSTEM and Administrators full; Users read; **no** Everyone entry |
| 1.6 | Start Menu | An "AICOUNTLY Remote" entry that launches the tray application |
| 1.7 | Task Manager → Startup apps | "AICOUNTLY Remote", enabled, removable |
| 1.8 | Check UAC settings before and after | **Unchanged.** `ConsentPromptBehaviorAdmin` and `PromptOnSecureDesktop` untouched |
| 1.9 | Check Windows Security | No exclusion added; no setting changed |

## 2. Registration

| # | Do | Expect |
|---|---|---|
| 2.1 | Open the agent | "This computer is not registered", and a button to do it |
| 2.2 | Register → sign in through the portal | The portal, in the person's own browser |
| 2.3 | Pick a company, name the machine, register | It succeeds and shows a key fingerprint |
| 2.4 | Compare the fingerprint with the Computers page | **Identical**, character for character |
| 2.5 | `dir C:\ProgramData\AICOUNTLY\Remote` | `device-signing-key.key` exists |
| 2.6 | `type` that file | Binary, and DPAPI-protected. Not a readable key |
| 2.7 | Search the whole disk for the base64 public key | Found only in the agent's own memory and the API response; **not** in a log, a `.env`, the registry, or any file it wrote |
| 2.8 | Restart the machine, wait, reload the Computers page | The device shows **Online** without anybody signing in to the agent |
| 2.9 | Try to register a *second* machine with the same key file copied across | Refused — the fingerprint is unique |

## 3. Presence and policy

| # | Do | Expect |
|---|---|---|
| 3.1 | Watch the agent's status for two presence intervals | It stays Online; nothing flaps |
| 3.2 | Disconnect the network | Within a minute: "offline", with a reason, and a retry that backs off |
| 3.3 | Reconnect | It recovers on its own. No restart, no click |
| 3.4 | Sleep the machine, wake it | It recovers |
| 3.5 | Turn `allow_remote_control` **off** in Remote policy | Within one poll the agent's Permissions panel says remote control is not permitted |
| 3.6 | Turn it back on | It comes back |
| 3.7 | `sc.exe stop AicountlyRemoteService` while the agent is open | The agent says the background service is not running and does **not** crash |
| 3.8 | `sc.exe start` it | It reconnects |

## 4. Attended control

| # | Do | Expect |
|---|---|---|
| 4.1 | From the browser, start a session with the machine | The agent shows a session running, in the window **and** in the tray |
| 4.2 | Look at the tray tooltip | It names the session and who is connected |
| 4.3 | Browser: Request control | The agent shows a consent dialog naming **who**, **which organisation**, and what control means |
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
to do the thing.

| # | Do | Expect |
|---|---|---|
| 9.1 | While controlling, trigger a UAC prompt on the machine | The screen goes blank or freezes at the prompt. The agent says the Secure Desktop is in front. **No input reaches it** |
| 9.2 | Lock the machine while controlling | Same |
| 9.3 | Try to control an elevated application (an admin PowerShell) | Input is refused, and the message says why — higher integrity level |
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
| 10.7 | Connect nine times at once | The ninth is dropped; the service stays responsive |
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
