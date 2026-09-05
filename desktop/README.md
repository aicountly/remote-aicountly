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
  remote-core/           configuration, the API client, signalling, the state machine
  remote-webrtc/         one trait, and webrtc-rs behind it

src-tauri/               the user-session process
  src/agent.rs           the one path from the network to the operating system
  src/runtime.rs         the connection loop: authenticate, presence, policy
  src/session.rs         one session: join, negotiate, pump the control channel
  src/commands/          the short, named set the window may call
  src/ipc/               talking to the service
  src/platform/windows/  the only place a Win32 or WinRT call is made
  src/platform/macos/    Unsupported, honestly

agents/windows-service/  the Session 0 service and the named-pipe protocol
src/                     the window: React 19 + TypeScript
installers/windows/      NSIS hooks — the service, and what uninstall removes
scripts/windows/         build, sign and verify
tests/                   MANUAL.md — the pass that has to happen on real Windows
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

# everything, on any host — the Windows-only modules are compiled out
cargo test --workspace
cargo clippy --workspace --all-targets -- -D warnings

# type-check the Windows-only half without a Windows machine
rustup target add x86_64-pc-windows-gnu     # plus gcc-mingw-w64-x86-64
cargo build --target x86_64-pc-windows-gnu -p aicountly-remote-service
cp target/x86_64-pc-windows-gnu/debug/AicountlyRemoteService.exe \
   src-tauri/binaries/AicountlyRemoteService-x86_64-pc-windows-gnu.exe
cargo clippy --workspace --all-targets --target x86_64-pc-windows-gnu -- -D warnings

# the installers, on Windows
pwsh -File scripts/windows/build.ps1
```

## Tests

```bash
cargo test --workspace   # 248 — protocol, gate, keys, state, capture maths, IPC, the session
npm test                 #  12 — the session banner and the unattended screen
cargo audit --deny warnings
```

**No Windows-only code path has been executed on a Windows machine.** It is
compiled, clippy-clean and type-checked for the Windows target; running it is
`tests/MANUAL.md`, and that pass has to happen before a release.

## Documentation

| Document | Covers |
|---|---|
| [../docs/desktop/ARCHITECTURE.md](../docs/desktop/ARCHITECTURE.md) | How the pieces fit, and what is shared with the browser |
| [../docs/desktop/SECURITY.md](../docs/desktop/SECURITY.md) | The security model, and its honest limits |
| [../docs/desktop/DEVICE_ENROLMENT.md](../docs/desktop/DEVICE_ENROLMENT.md) | Keys, challenges, unattended access, revocation |
| [../docs/desktop/WINDOWS_AGENT.md](../docs/desktop/WINDOWS_AGENT.md) | The two processes, capture, input, and what Windows will not allow |
| [../docs/desktop/WINDOWS_RELEASE.md](../docs/desktop/WINDOWS_RELEASE.md) | Building, signing, releasing |
| [../docs/desktop/TESTING.md](../docs/desktop/TESTING.md) | What is covered, and what is not |
| [tests/MANUAL.md](tests/MANUAL.md) | The manual pass on a real Windows machine |
