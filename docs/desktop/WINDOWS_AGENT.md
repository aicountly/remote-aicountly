# AICOUNTLY Remote for Windows

What the Windows agent is made of, what it needs from the operating system, and
the four things Windows will not let it do.

## Supported

| | |
|---|---|
| Windows 10 22H2 (build 19045) or later | earlier 10 releases are out of support from Microsoft, and this is not software to be running on an unpatched OS |
| Windows 11, all releases | Windows 11 reports itself as major version 10, so the build number is what tells them apart |
| **x86_64** | the shipped architecture |
| ARM64 | the code is architecture-neutral and the workspace builds for it; **no ARM64 build is produced or tested**, so it is prepared for rather than supported |

`is_supported_build()` and `describe_build()` in
`src-tauri/src/platform/windows/device.rs` hold both facts, and the version is
read with `RtlGetVersion` rather than `GetVersionEx`, which has been frozen for
years and lies to unmanifested applications.

## The two executables

| | |
|---|---|
| `AICOUNTLY Remote.exe` | the user-session process: tray, window, capture, input, clipboard, WebRTC |
| `AicountlyRemoteService.exe` | the Session 0 service: device identity, presence, restart, the IPC pipe |

The service's display name is **AICOUNTLY Remote Service**; its key name is
`AicountlyRemoteService`; it runs as `LocalSystem`, `AutoStart` with a delayed
start and `ServiceErrorControl::Normal` — a machine must never fail to boot
because a remote-support agent did not start.

There is **no interactive service**. See
[ARCHITECTURE.md](ARCHITECTURE.md#two-processes-and-why).

### Publisher metadata

`bundle.publisher` and `bundle.copyright` in `tauri.conf.json` are **empty**,
and deliberately: there is no authoritative legal publisher name anywhere in
this repository, and inventing one would put a false entity in the file
properties of a signed binary. They must be filled in before a production
release, and they should match the subject of the code-signing certificate —
see [WINDOWS_RELEASE.md](WINDOWS_RELEASE.md).

## Screen capture

`Windows.Graphics.Capture` — the API Windows actually supports for this — with
a D3D11 staging texture read back to BGRA.

Not `BitBlt` (misses hardware-composited and protected content, and is slow),
not the Desktop Duplication API (one output, awkward with mixed-DPI, and does
not survive a display change). `Windows.Graphics.Capture` handles multiple
monitors, mixed DPI and a display being unplugged mid-session, which are all
things that happen during a support call.

Frames never touch disk. Nothing writes one to PostgreSQL, to a log, to cPanel
storage, or to a temporary file — the frame goes to the encoder and the encoder
to the peer connection.

### Quality

Three profiles in `crates/remote-device/src/frame.rs`, and the ceiling is a
ceiling rather than a target:

| Profile | Ceiling |
|---|---|
| Adaptive (default) | ≤ 1920×1080, ≤ 30 fps, stepping down to 1280 wide and 15, then 8 and 5 fps, when the link cannot sustain it |
| Low bandwidth | ≤ 1280×720, ≤ 12 fps, for a metered or poor link |
| High quality | native resolution, ≤ 30 fps, for a link that can carry it |

`degraded()` and `improved()` step down and back up one notch at a time from
WebRTC's own congestion feedback (`RemoteInboundRtp`: loss and round-trip
time). Nothing is hardcoded to 1080p30 — a connection that cannot sustain it
gets a picture that arrives instead of one that stalls.

> **Not built.** There is no encoder. `remote-webrtc` negotiates a VP8 track
> and the capture provider produces BGRA frames; nothing converts one into the
> other yet, so the agent joins a session and carries control without sending a
> picture. See
> [ARCHITECTURE.md](ARCHITECTURE.md#what-is-built-and-what-is-not).

## Input injection

`SendInput`, with `MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_VIRTUALDESK` and
coordinates normalised into the 0..65535 virtual-desktop space — which is the
only way to land a click correctly on a multi-monitor, mixed-DPI desktop where
one screen is to the left of another and at a different scale.

Keys are injected as **Unicode** (`KEYEVENTF_UNICODE`) from the character the
person meant, not from a scan code. A French keyboard controlling a US one
produces the character that was typed rather than the one in that physical
position.

Every message goes through `ControlGate::admit()` first. There is no other path
from the network to `SendInput`, and there is no API anywhere in the agent for
executing native code, running a program, or opening an arbitrary file.

## The clipboard

`OpenClipboard` / `CF_UNICODETEXT`, text only, and only while a grant is active
*and* the clipboard was separately agreed. The clipboard is retried a few times
on open, because another process holding it briefly is normal and failing on
the first attempt produces a bug report about a feature that works.

## Restarting the machine

Separately authorised at every level: `allow_device_reboot`, an active session
with control, and the API's own check. By the time anything happens the audit
entry is already written.

The restart is performed by the **service**: `SE_SHUTDOWN_NAME` is a privilege
a user-session process should not be asking for, and the tray application
requesting elevation to reboot would be a prompt nobody should learn to accept.
The IPC message names the session that authorised it and the service refuses it
for a session it was never told about, with a five-minute cooldown against a
restart loop.

`InitiateSystemShutdownExW` with a 30-second grace period and
`bForceAppsClosed = false`. Somebody may have walked up to the machine between
the request and the restart, and their unsaved work is not this service's to
discard.

> **After the restart, the session is over.** The agent does not resume it, and
> nothing in the product claims it will. Reconnecting is a new session, and it
> needs a machine that has finished booting and, unless unattended access is
> enabled, somebody at it.

## The four things Windows will not let this do

Each is a real limitation. None is worked around, and none is presented as
working.

### 1. The Secure Desktop

A UAC prompt, the sign-in screen, the lock screen and Ctrl+Alt+Del run on a
separate desktop that a user-session process cannot capture and cannot inject
into. The agent **detects** it — `OpenInputDesktop` fails when the Secure
Desktop has the input — and tells the person what is happening.

The consequence: a remote session goes blank at a UAC prompt, and somebody has
to be at the machine to answer it. This is Windows working as designed. A
product that shows you a UAC prompt remotely has installed something that
lowers a security boundary on your machine, and this one does not:

* **UAC is not disabled**, and the installer does not touch it;
* `ConsentPromptBehaviorAdmin` and `PromptOnSecureDesktop` are not written;
* no attempt is made to reach the Secure Desktop or to run in Session 0 with a
  desktop.

### 2. UIPI

`SendInput` cannot inject into a window at a higher integrity level. Control of
an elevated application from a non-elevated agent will not work, and the
failure says so — "Windows refused the input, usually because the window in
front runs with higher privileges" — rather than appearing as a dead keyboard.

### 3. Protected content

Applications that mark their windows with `WDA_EXCLUDEFROMCAPTURE`, and DRM
video paths, produce black in the capture. That is the point of the flag.

### 4. Nobody is signed in

The service keeps the machine reachable, but there is no desktop to capture
until somebody signs in. An unattended connection to a machine sitting at the
logon screen can reach the machine and cannot show you anything. The Computers
page's presence is honest about the machine being *reachable*; it does not
promise a picture.

## Antivirus and SmartScreen

Neither is disabled, worked around, or asked about. A signed, timestamped,
reputation-building binary is the way through SmartScreen, and it is the only
way this product uses.

An **unsigned development build will warn**, and that warning is correct: an
unsigned binary vouches for nobody. `scripts/windows/build.ps1` labels its
output `UNSIGNED DEVELOPMENT BUILD`, the sandbox release channel ships a file
saying so beside the installer, and nothing in the workflows can turn an
unsigned artifact into a GitHub release.

If a specific antivirus flags a signed release, the fix is a false-positive
report to that vendor. It is not an exclusion the customer is asked to add.

## Files and registry

| Path | What |
|---|---|
| `%ProgramFiles%\AICOUNTLY Remote\` | both executables |
| `%ProgramData%\AICOUNTLY\Remote\device-signing-key.key` | the DPAPI blob |
| `%ProgramData%\AICOUNTLY\Remote\config.json` | the endpoint and some numbers; nothing secret |
| `HKLM\…\CurrentVersion\Run\AICOUNTLY Remote` | the tray application at sign-in |
| `HKLM\SYSTEM\CurrentControlSet\Services\AicountlyRemoteService` | the service, written by the SCM |

The data directory's ACL is reset and rebuilt by the installer rather than
inherited: SYSTEM and Administrators full control, Users read. A directory that
inherited "Users: Modify" from somewhere would be a directory any account on
the machine could replace a device identity in.

Uninstalling removes all of it, including the key — see
[DEVICE_ENROLMENT.md](DEVICE_ENROLMENT.md#uninstalling).

## Diagnostics

```powershell
sc.exe query AicountlyRemoteService
Get-AuthenticodeSignature "C:\Program Files\AICOUNTLY Remote\AICOUNTLY Remote.exe"
Get-ChildItem "C:\ProgramData\AICOUNTLY\Remote"
Get-Acl "C:\ProgramData\AICOUNTLY\Remote" | Format-List

# Verbose logging, from a console
$env:AICOUNTLY_REMOTE_LOG = 'debug'
& "C:\Program Files\AICOUNTLY Remote\AICOUNTLY Remote.exe"
```

The About panel shows the version, the platform, whether this build has a real
platform implementation, where the key is kept — the store's *name*, never
anything in it — and whether the service is running.
