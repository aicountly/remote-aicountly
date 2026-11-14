# Testing the desktop agent

What is covered automatically, what is covered by hand, and — stated plainly —
what is not covered at all.

## The numbers

```bash
cd desktop    && cargo test --workspace   #   732 — protocol, gate, identity, state, session, service, the installer, the manifest
cd desktop    && npm test                 #   137 — the app's own window
cd backend    && vendor/bin/phpunit       # 1,118 — devices, policy, control, unattended, desktop sign-in, rate limits
cd web        && npm test                 #   593 — 552 for the app (capability gating, control input, the room, devices) and 41 for the ICE kit
cd signalling && npm test                 #    46 — tokens, rooms, device rooms, live relay, the limits
```

**869 automated tests in `desktop/`** — 732 Rust and 137 for the window —
beside the backend, web and signalling suites above. All five figures are what
the suites reported on 14 November 2026, run on one Linux machine (the backend
against a local PostgreSQL 16; fifteen of its tests are skipped without a TURN
relay).

The desktop split by crate:

| | |
|---|---|
| `aicountly-remote-desktop` | 307 — the agent, its commands, the connection loop, the session, the companion, the platform layer's arithmetic (139 of them, 44 under `windows/` and 69 under `macos/`), what the installer claims, and what the embedded Windows manifest becomes |
| `remote-core` | 123 — configuration, the API client, signalling, backoff, the state machine, the update check, the log file |
| `remote-webrtc` | 104 — peer connection, data channels, a real SDP negotiation, the H.264 encoder, sound (one more is a release-build measurement, run by hand; three browser-interop tests need Node and Chromium) |
| `remote-protocol` | 81 — the wire format, the gate, clipboard bounds, monitors, the reasons input is refused |
| `aicountly-remote-service` | 67 — the IPC protocol, the pipe ACL, the service's decisions, the Secure Desktop helper's protocol |
| `remote-device` | 28 — capture profiles, capability declaration |
| `remote-security` | 22 — keys, the canonical payload, storage, the enrolment record |

## Why so much of it runs on Linux

Everything that *decides* anything lives in `desktop/crates/` and in
`agents/windows-service/src/machine.rs`, none of which calls a native API. That
is not a happy accident — it is the reason for the split — and it means the
control gate, the signed payload, the state machine, the backoff and the
service's own decisions are all exercised on every run of the suite, on a fast
runner or a developer's machine, without a Windows machine anywhere. (No
workflow runs on a push any more: the suite runs when somebody starts it, and
before a deploy or a release — see the README.)

**`src-tauri/src/platform/windows/` runs on Linux too.** The module is not
gated on the target: each file keeps its native calls in an inner
`#[cfg(target_os = "windows")] mod imp` and refuses with `Unsupported`
elsewhere, so the 44 tests covering where a click lands, the virtual-key table,
the extended-key set, wheel deltas, clipboard bounds, the IPC client, the
display names a person picks a screen by and the storage refusal all run on
every host. What a Linux runner genuinely cannot
check is the `imp` halves — `SendInput`, `Windows.Graphics.Capture`, DPAPI, the
pipe, the SCM — and those are what the Windows CI job compiles and
clippy-checks, and what `desktop/tests/MANUAL.md` covers by hand.

Gating that module was a real mistake and it cost a CI cycle to find: it hid a
rounding error and a DPI conversion that would have dropped a working display
out of the monitor layout, and it left a test written specifically for
non-Windows hosts running on no host at all. The rule that replaced it is in
[Adding a test](#adding-a-test).

## The tests that carry a property

Some tests are there to catch a regression. These are there because the
property they assert is the product:

| Test | Property |
|---|---|
| `no_input_is_acted_on_before_control_is_granted` | no grant, no input |
| `stopping_control_takes_effect_immediately_and_locally` | Stop control needs no network |
| `a_replayed_sequence_is_dropped` | a captured message cannot be re-sent |
| `a_stale_server_grant_cannot_undo_a_local_revocation` | Stop control is not undone by the network |
| `the_protocol_expresses_nothing_executable` | the control protocol cannot ask for code |
| `the_protocol_can_express_nothing_executable` | nor can the IPC protocol |
| `the_protocol_never_carries_a_key_or_a_credential` | the pipe cannot be asked for the key |
| `the_acl_grants_nothing_to_everyone_or_to_anonymous` | the pipe's ACL, spelled out |
| `a_restart_is_refused_for_a_session_the_service_never_heard_of` | the service's own check |
| `the_configuration_holds_nothing_secret` | the config file stays an ordinary file |
| `testACompanyProhibitionBeatsAUserLevelAllow` (backend) | the policy hierarchy's whole point |
| `testAMachineCannotConsentWhenTheOrganisationForbidsControl` (backend) | a device's declaration is an upper bound |
| `controlPhase` — capability before policy (web) | a browser host offers no control button |
| `refuses a click in the letterbox` (web) | a click that missed does not land somewhere else |

## Running the Windows half from a Linux machine

The Windows code can be **type-checked** without a Windows machine, which is
how the platform layer's API mismatches were found:

```bash
rustup target add x86_64-pc-windows-gnu
sudo apt-get install gcc-mingw-w64-x86-64

cd desktop
cargo build --target x86_64-pc-windows-gnu -p aicountly-remote-service
cp target/x86_64-pc-windows-gnu/debug/AicountlyRemoteService.exe \
   src-tauri/binaries/AicountlyRemoteService-x86_64-pc-windows-gnu.exe

cargo clippy --workspace --all-targets --target x86_64-pc-windows-gnu -- -D warnings
```

The sidecar copy is needed because Tauri's build script resolves it at compile
time on Windows targets; without the file the application crate does not
configure. CI's Windows job does the same thing with the MSVC triple.

This checks that the code compiles and that every `windows` crate call has the
right shape. It does **not** run any of it: `SendInput` is not called, no
capture happens, no pipe is created.

### The whole installer, from a Linux machine

Further than a type-check: both executables link, the front end builds, and
NSIS produces an installer with `installers/windows/hooks.nsh` compiled into
it. Not a substitute for installing it on Windows — nothing here executes a
single Windows instruction — but it is the difference between knowing the
installer *builds* and finding out during a release.

```bash
sudo apt-get install gcc-mingw-w64-x86-64 nsis libayatana-appindicator3-dev

cd desktop
cargo build --release --target x86_64-pc-windows-gnu -p aicountly-remote-service
cp target/x86_64-pc-windows-gnu/release/AicountlyRemoteService.exe \
   src-tauri/binaries/AicountlyRemoteService-x86_64-pc-windows-gnu.exe
npm run tauri -- build --target x86_64-pc-windows-gnu
#   → target/x86_64-pc-windows-gnu/release/bundle/nsis/*.exe
```

Three notes, each of which costs an hour to rediscover:

* **`nsis`** is what Tauri's bundler shells out to on a non-Windows host. On
  Windows it downloads its own copy; on Linux it needs `makensis` on `PATH`.
* **`libayatana-appindicator3-dev`** has nothing to do with the Windows build.
  The Tauri CLI probes for an appindicator library whenever the `tray-icon`
  feature is on and the *host* is Linux, whatever it is building for, and
  panics when it finds none.
* **It is the GNU target, not MSVC.** A release ships
  `x86_64-pc-windows-msvc`, built on Windows by
  `.github/workflows/release-windows.yml`. The two differ in the C runtime
  they link; this one is a build check, not a shippable artifact.

A shippable installer, and the one CI builds, comes from
`pwsh -File scripts/windows/build.ps1` on Windows.

## What is not covered

Read this rather than assuming the test count covers it.

* **No automated test executes a native Windows call that matters.**
  `SendInput`, `Windows.Graphics.Capture`, WASAPI loopback, DPAPI, the named
  pipe, the SCM registration, the Secure Desktop helper, `SendSAS`, the policy
  read behind it and the restart are compiled, clippy-clean and type-checked
  for the Windows target, built into installers on a Windows runner, and run
  by people on their own PCs — and nothing in this repository records a
  completed manual pass. The arithmetic and the tables around them are tested
  — that is what the 44 tests under `platform/windows/` cover — but a passing
  `to_virtual_desktop` is not a
  pointer that moved. `desktop/tests/MANUAL.md` is the pass
  that has to happen before a release, and it is not a formality.
* **Media is tested up to a real browser decoder, not a real screen.** The
  encoder's unit tests cover colour conversion, stride, odd sizes, downscaling
  and keyframes; a loopback test sends H.264 to a second webrtc-rs peer; and
  `desktop/scripts/interop/` streams synthetic frames to headless Chromium and
  checks `framesDecoded`. Capturing a real screen is a step in
  `desktop/tests/MANUAL.md`.
* **The tests encode with OpenH264 compiled from source, not Cisco's binary.**
  The application loads Cisco's binary, downloaded from Cisco
  ([docs/legal/OPENH264.md](../legal/OPENH264.md)), and no test can reach
  Cisco's server, so `remote-webrtc`'s dev-dependencies switch on its
  test-only `bundled-openh264` feature and the encoder, loopback, interop and
  benchmark tests run on that. The download is tested against a local HTTP
  server — retries, the HTTPS-to-HTTP fallback, the size limits, a tampered
  file, switching off mid-download — with a stand-in for the final load,
  since the bytes are not a library; the `openh264` crate's own refusal of a
  file it does not know is tested on the real loader. Loading Cisco's real
  DLL or dylib — and, on a Mac, that a notarised build may — is a step for a
  real Windows PC and a real Mac.
* **There is no test against a running relay.** The signalling messages are
  parsed and asserted against the shapes `signalling/src/server.js` relays, and
  the session loop's join handling is tested; nothing in CI opens a socket to a
  real relay. That is a step in `desktop/tests/MANUAL.md`.
* **The installer has not been run.** It *builds* now — `desktop-ci.yml`'s
  Windows job builds it on every run, and the section above builds it from a
  Linux machine — and `src-tauri/tests/installer.rs` reads `hooks.nsh` and
  checks what it claims against what cargo actually produces: the executable
  names, the Run entry, the data directory's ACL, the files the uninstall
  removes. What has still not happened is **installing** it: no service has
  been registered by it, no ACL applied, no Run entry exercised at a real
  sign-in. That is `desktop/tests/MANUAL.md`, and it is not a formality.
* **No signed build exists**, so nothing has been verified against a real
  certificate chain. `verify.ps1` has been read, not exercised against a
  signature.
* **macOS's native calls run only on a Mac.** The key table, coordinates,
  display description, the pointer drawn into the picture, the frame pacing,
  permission summary and keychain fallback rule are tested on every host (69
  tests under `platform/macos/`); `mod imp` (Core Graphics capture, CGEvent,
  Keychain, Screen Recording, Accessibility, display list) is compiled and
  clippy-checked by the `macos` job in `desktop-ci.yml`, and exercised only by
  hand — `desktop/tests/MANUAL.md` §4e and the steps in
  `desktop/scripts/macos/README.md`. The companion's loop is tested against a
  fake API, relay and peer on a paused clock (`src-tauri/src/companion.rs`).

## Adding a test

Put it where it will run on every host, every time the suite is run:

1. Is it a decision? Then it belongs in `desktop/crates/` or in
   `agents/windows-service/src/machine.rs`, and it runs everywhere.
2. Is it arithmetic — coordinates, scaling, a bound, a lookup table? It can
   live in `src-tauri/src/platform/`, including under `windows/`, as long as it
   is outside that file's `mod imp`. `describe_monitor`, `to_virtual_desktop`,
   `virtual_key` and `wheel_delta` are all there, and all run on every host.
3. Is it genuinely a native call? Then it is a manual test, and it goes in
   `desktop/tests/MANUAL.md` with what to do and what to expect.

The rule of thumb: **a test that needs `#[cfg(target_os = "windows")]` is a
claim that the thing under test calls Windows.** If it does not, move the thing
out of `mod imp` instead of gating the test — a test that only runs on one
runner is a test that finds its bug one cycle late, and a `#[cfg(not(...))]`
test under a gated module runs nowhere at all.

## The commands, in one place

```bash
# Rust, everything, on any host
cd desktop && cargo test --workspace
cd desktop && cargo clippy --workspace --all-targets -- -D warnings
cd desktop && cargo fmt --all -- --check
cd desktop && cargo audit --deny warnings

# The Windows target, type-checked from Linux (see above for the sidecar)
cd desktop && cargo clippy --workspace --all-targets --target x86_64-pc-windows-gnu -- -D warnings

# The agent's interface
cd desktop && npm ci && npm run typecheck && npm test

# The rest of the product
cd backend    && vendor/bin/phpunit
cd web        && npm run typecheck && npm test
cd signalling && npm test
```

The backend suite needs a real PostgreSQL and applies the migrations itself:

```bash
cd backend
export CI_ENVIRONMENT=testing
export database.tests.hostname=127.0.0.1
export database.tests.database=aicountly_remote_test
export database.tests.username=aicountly_remote
export database.tests.password=<yours>
export database.tests.DBDriver=Postgre
vendor/bin/phpunit
```
