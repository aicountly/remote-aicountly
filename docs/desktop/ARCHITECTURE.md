# The desktop agent's architecture

AICOUNTLY Remote for Windows is **not a second product**. It is a participant
in the same session, evaluated by the same policy resolver, recorded in the
same audit trail, and connected through the same signalling service as a
browser. What it adds is a client that can be controlled — which the browser
never could.

This document is how it is put together. What it is *allowed* to do is
[SECURITY.md](SECURITY.md); how a machine gets an identity is
[DEVICE_ENROLMENT.md](DEVICE_ENROLMENT.md); what only exists on Windows is
[WINDOWS_AGENT.md](WINDOWS_AGENT.md).

## The claim the whole thing rests on

> An agent is a participant with different capabilities.

`remote_participants.capabilities` has carried a capability set since V1. A
browser reports `remote_control: false`; an agent reports `true`. Nothing in
the session model, the policy hierarchy or the audit schema changed to make
the desktop agent possible — the seams were left, and this fills them.

**The declaration is only ever an upper bound.** The server intersects it with
the plan entitlement and the company policy before anything is granted
(`DeviceService::effectiveCapabilities`), so an agent that lied in its
enrolment request gets a session it is refused control of. Nothing in the
product branches on `client_type`.

## Two processes, and why

```text
  AicountlyRemoteService.exe          Session 0, LocalSystem, delayed auto-start
    device identity and presence        the machine is reachable
    device authentication               holds no user's credential
    restart, service lifecycle          privileged, and only where needed
          │
          │  named pipe, ACL'd, versioned, no command variant
          ▼
  AicountlyRemote.exe                 the signed-in user's own session
    tray, window, consent               a person can see and stop it
    screen capture                      of the desktop they are looking at
    keyboard and mouse injection        into the desktop they are using
    clipboard, WebRTC                   where the session actually happens
```

A Windows service runs in Session 0, which has no desktop: it cannot capture
what a person sees, cannot inject input into their session, and cannot show
them anything. `SERVICE_INTERACTIVE_PROCESS` has been ignored since Windows 10
1803 and the Interactive Services Detection service was removed. So the split
is not a preference — it is what Windows permits — and neither process
pretends to be the other.

The service does **not** launch the user interface. The installer adds an
ordinary per-machine `Run` entry instead, which a person can see in Task
Manager's Startup tab and turn off.

## The workspace

```text
desktop/
  crates/
    remote-protocol/    the control wire format, and the gate that admits input
    remote-security/    device keys, the canonical signed payload, key storage
    remote-device/      the platform traits every OS implements
    remote-core/        configuration, the API client, signalling, the state machine
    remote-webrtc/      the peer connection, behind one interface
  src-tauri/
    src/                the application: tray, commands, the connection loop
    src/session.rs      one session: join, negotiate, pump the control channel
    src/platform/       the ONLY place a native API is called
      windows/          capture, input, clipboard, DPAPI, service, power
      macos/            every provider returns Unsupported
  agents/
    windows-service/    the Session 0 half: SCM, named pipe, restart
  src/                  the agent's own React interface
  installers/, scripts/ packaging, signing, verification
```

The split between `crates/` and `src-tauri/src/platform/` is the design. Every
decision — what a control message means, whether input is admitted, how a
challenge is signed, when to retry — is in `crates/`, compiles on any host, and
is tested on Linux CI. What is left in `platform/windows/` is native calls with
almost no branching, which is the part a Linux runner genuinely cannot check
and the part a Windows runner therefore has to.

`cargo test --workspace` runs **248 Rust tests** on any host.

### The platform traits

`crates/remote-device/src/lib.rs` defines seven, and each platform implements
them or says it cannot:

| Trait | Windows | macOS |
|---|---|---|
| `ScreenCaptureProvider` | `Windows.Graphics.Capture` + D3D11 readback | `Unsupported` |
| `InputProvider` | `SendInput`, absolute virtual-desktop coordinates | `Unsupported` |
| `ClipboardProvider` | `OpenClipboard` / `CF_UNICODETEXT`, text only | `Unsupported` |
| `SecureStorageProvider` | DPAPI, machine scope, extra entropy | `Unsupported` |
| `SystemServiceProvider` | the SCM, and the named-pipe client | `Unsupported` |
| `DeviceInfoProvider` | `RtlGetVersion`, `COMPUTERNAME` | `Unsupported` |
| `PowerProvider` | delegates the restart to the service | `Unsupported` |

macOS returns `PlatformError::Unsupported` from every one. **There is no
pretend implementation**: a provider that silently did nothing would look like
control that works and does not, which is worse than a clear refusal. What a
real macOS port needs is listed in `desktop/scripts/macos/README.md`.

## What a session looks like

```text
  browser                         API                        agent
  ───────                         ───                        ─────
                                                    presence  ──▶  reachable
  connect (unattended)   ──▶  policy ∧ entitlement ∧
                              permission ∧ device state
                              creates an ordinary session
                              UNATTENDED_SESSION_STARTED
                                     ──── device-<uuid> room ────▶  join
                              signalling token (2 min, one room)
  ◀──────────── SDP offer / answer, ICE, over the relay ─────────▶
  ◀════════ WebRTC: video track + two data channels ═════════════▶
  request control        ──▶  CONTROL_REQUESTED
                                                          consent dialog
                              CONTROL_GRANTED       ◀──   Allow
  input ═══ aicountly-remote-control ═══════════════════▶  ControlGate
                              CONTROL_REVOKED       ◀──   Stop control
```

Two data channels, not one:

* `aicountly-remote` — chat, pointer, annotation, file transfer. The same
  channel Browser V1 uses, unchanged.
* `aicountly-remote-control` — input, clipboard, monitor layout. Separate so
  that "may this peer control the machine" is one question about one channel,
  and so that a bug in the collaboration protocol cannot deliver a keystroke.

### Control messages

`crates/remote-protocol/src/lib.rs` defines the vocabulary, and
`web/src/services/webrtc/remoteControl.ts` mirrors it. Coordinates are
**normalised to the shared surface** (0..1), never pixels: the video element,
the monitor and the capture scale are three different sizes that all change
during a session, and a pixel coordinate would be wrong the moment any of them
did.

`ControlGate::admit()` is the only path from the network to the input
providers, and it applies five checks in cheapest-first order:

1. the protocol version matches;
2. the session id matches this session;
3. the participant is the one control was granted to;
4. the sequence is strictly newer than the last message acted on;
5. the state is `Granted`.

Anything else is dropped and counted. The gate is **local**: revoking it stops
the next message with no network round trip, which is what makes Stop control
trustworthy.

## The connection loop

`src-tauri/src/runtime.rs`, one background task on a thread of its own:

1. authenticate with the device key (challenge → signature → credential);
2. renew a minute before expiry, rather than after the first refusal;
3. `GET /devices/me` for what the organisation currently permits, on the
   interval the **server** chooses;
4. report presence;
5. notice unattended sessions waiting for this machine.

A network failure backs off with jitter and never gives up. A **revoked
device** is the one state it deliberately does not retry from: it stops, and
says the machine was removed.

When a session is waiting it hands over to `src-tauri/src/session.rs`, which
joins through the API, opens the same signalling socket the browser uses,
negotiates the peer connection, and pumps the control channel through the gate
until the session ends. One at a time: two sessions on one desktop is not a
feature, and deciding whose input wins is not a decision anybody should have to
make afterwards.

Who is *waiting* for control is polled from the API, never taken from a
data-channel message — so a peer cannot put a consent dialog in front of
somebody by sending one.

## What is built, and what is not

Stating this precisely matters more than it reads: an architecture document
that describes intentions as though they were code is how a team discovers a
gap at the wrong moment.

**Built and tested**

* the whole control protocol and the gate, with 58 tests;
* device identity: keypair, canonical signed payload matching the PHP
  implementation byte for byte, DPAPI storage, enrolment, authentication;
* the agent state machine, configuration, API client, backoff;
* the Windows platform layer — capture, input, clipboard, storage, device
  info, service control, power — compiling for `x86_64-pc-windows-msvc` and
  checked in CI on a Windows runner;
* the Windows service: SCM registration, the ACL'd named pipe, the IPC
  protocol, the restart;
* the connection loop: authentication, renewal, presence, policy refresh;
* the session runtime: joining through the API, the **same** signalling socket
  the browser uses, offer/answer/ICE, and the control channel pumped through
  the gate;
* the tray, the window and the consent screens;
* the whole server side — devices, policy, control, unattended sessions,
  presence rooms, audit — with 208 backend tests;
* the browser side — capability-driven control UI, input capture, the
  Computers page — with 104 web tests.

**Not built**

* **A video encoder.** `remote-webrtc` negotiates a VP8 track and the capture
  provider produces BGRA frames; nothing converts one into the other. Until it
  does, an agent can join a session and carry control messages but cannot send
  a picture. This is the single largest remaining piece and it is a real
  dependency decision (libvpx, or a Media Foundation hardware encoder), not an
  afternoon's work.
* **File transfer in the agent.** The protocol and the browser half exist; the
  agent does not read the collaboration channel, so it neither offers nor
  receives a file.
* **The clipboard in the other direction.** A controller's clipboard reaches
  the machine — the message goes through the gate to the clipboard provider —
  but the machine's clipboard is not sent back to the controller.
* **Reconnecting a session.** A signalling token lasts two minutes; the socket
  is not re-established after it closes, so a session that loses its relay
  connection mid-negotiation ends rather than recovering.
* **Automatic updates.** Deliberately inactive — see
  [WINDOWS_RELEASE.md](WINDOWS_RELEASE.md).
* **macOS.** Every provider returns `Unsupported`.

## Where the boundaries are enforced

| Property | Enforced by |
|---|---|
| A browser cannot be controlled | `ClientCapabilities::browser()` and the capability intersection |
| A device cannot grant itself a capability | `DeviceService::effectiveCapabilities`, server-side |
| A company prohibition beats a user grant | `EffectivePolicyResolver`, capability mask applied last |
| No input without a grant | `ControlGate::admit`, locally, in the agent |
| Stop control needs no network | `ControlGate::revoke`, and no permission check anywhere |
| A running session is visible | `AgentState::is_session_active()`, derived from status |
| The device key never leaves the machine | It is never a parameter, a response, or an IPC message |
| The service runs nothing | `IpcRequest` has no variant naming a program |
