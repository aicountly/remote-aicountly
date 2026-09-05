# AICOUNTLY Remote — desktop agent

The Windows desktop agent for AICOUNTLY Remote. It plugs into the session,
policy, audit and signalling model the rest of this repository already has:
**an agent is a participant with different capabilities**, not a second Remote.

| | |
|---|---|
| Supported | Windows 10 22H2, Windows 11 · x86_64 (ARM64 is a build target, not a port) |
| Not supported | macOS — the traits are here, the implementation is not, and it says so |
| Executables | `AicountlyRemote.exe` (the person's session) · `AicountlyRemoteService.exe` (the machine) |
| Service | `AICOUNTLY Remote Service` |

## Layout

```
crates/                  portable. Compiles and is tested on any host.
  remote-protocol/       the control channel: input, clipboard, framing, the gate
  remote-security/       the device keypair, the canonical challenge, secure storage
  remote-device/         the seven platform traits every target implements
  remote-core/           configuration, the API client, the state machine, backoff
  remote-webrtc/         one trait, and webrtc-rs behind it

src-tauri/               the user-session process
  src/agent.rs           the one path from the network to the operating system
  src/commands/          the short, named set the window may call
  src/ipc/               talking to the service
  src/platform/windows/  the only place a Win32 or WinRT call is made
  src/platform/macos/    Unsupported, honestly

agents/windows-service/  the Session 0 service and the named-pipe protocol
src/                     the window: React 19 + TypeScript
installers/windows/      NSIS hooks — the service, and what uninstall removes
scripts/windows/         build, sign and verify
tests/                   the end-to-end test document
```

## Why two processes

Windows services run in Session 0, which has no desktop. A service cannot
capture what a person sees, cannot inject input into their session, and cannot
show them a window — `SERVICE_INTERACTIVE_PROCESS` has been ignored since
Windows 10 1803. So:

* **`AicountlyRemoteService.exe`** owns the machine: device identity, presence,
  auto-start, restart. It is not interactive and cannot be made so.
* **`AicountlyRemote.exe`** owns the session: tray, window, consent, capture,
  input, clipboard, WebRTC. It runs as the signed-in person.

They talk over a named pipe with an explicit ACL, an authenticated handshake
and a versioned, length-prefixed protocol that **cannot express a command**.
See `agents/windows-service/src/ipc.rs`.

## Building

```bash
# Node 22+, Rust 1.82+
npm install

# the window, on its own
npm run dev

# the whole agent (needs Windows for the native layer)
npm run tauri dev

# the portable crates, on any host
cargo test -p remote-protocol -p remote-security -p remote-device -p remote-core -p remote-webrtc

# everything, on Windows
cargo test --workspace
```

## Tests

```bash
cargo test --workspace   # 210 — protocol, gate, keys, state, capture maths, IPC
npm test                 #  12 — the session banner and the unattended screen
```

## Documentation

| Document | Covers |
|---|---|
| [../docs/desktop/ARCHITECTURE.md](../docs/desktop/ARCHITECTURE.md) | How the pieces fit, and what is shared with the browser |
| [../docs/desktop/SECURITY.md](../docs/desktop/SECURITY.md) | The security model, and its honest limits |
| [../docs/desktop/DEVICE_ENROLMENT.md](../docs/desktop/DEVICE_ENROLMENT.md) | Keys, challenges, unattended access, revocation |
| [../docs/desktop/WINDOWS_AGENT.md](../docs/desktop/WINDOWS_AGENT.md) | The two processes, capture, input, and what Windows will not allow |
| [../docs/desktop/WINDOWS_RELEASE.md](../docs/desktop/WINDOWS_RELEASE.md) | Building, signing, releasing |
| [../docs/desktop/TESTING.md](../docs/desktop/TESTING.md) | The end-to-end matrix |
