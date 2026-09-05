# Testing the desktop agent

What is covered automatically, what is covered by hand, and — stated plainly —
what is not covered at all.

## The numbers

```bash
cd desktop    && cargo test --workspace   # 248 — protocol, gate, identity, state, session, service
cd desktop    && npm test                 #  12 — the agent's own interface
cd backend    && vendor/bin/phpunit       # 211 — devices, policy, control, unattended
cd web        && npm test                 # 104 — capability gating, control input, devices
cd signalling && npm test                 #  27 — tokens, rooms, device rooms, live relay
```

**602 automated tests.** The split by crate:

| | |
|---|---|
| `remote-protocol` | 58 — the wire format, the gate, clipboard bounds, monitors |
| `remote-core` | 52 — configuration, the API client, signalling, backoff, the state machine |
| `aicountly-remote-desktop` | 51 — the agent, its commands, the connection loop, the session |
| `aicountly-remote-service` | 29 — the IPC protocol, the pipe ACL, the service's decisions |
| `remote-device` | 21 — capture profiles, capability declaration |
| `remote-security` | 20 — keys, the canonical payload, storage |
| `remote-webrtc` | 17 — peer connection, data channels, a real SDP negotiation |

## Why so much of it runs on Linux

Everything that *decides* anything lives in `desktop/crates/` and in
`agents/windows-service/src/machine.rs`, none of which calls a native API. That
is not a happy accident — it is the reason for the split — and it means the
control gate, the signed payload, the state machine, the backoff and the
service's own decisions are all exercised on every push, on a fast runner,
without a Windows machine anywhere.

What is left in `src-tauri/src/platform/windows/` is native calls with almost
no branching. That is the part a Linux runner cannot check, and the part the
Windows CI job therefore compiles and clippy-checks.

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

## What is not covered

Read this rather than assuming the test count covers it.

* **No Windows-only code path has been executed.** Capture, input injection,
  DPAPI, the named pipe, the SCM registration and the restart are compiled,
  clippy-clean and type-checked for the Windows target, and none of them has
  run on a Windows machine in this work. `desktop/tests/MANUAL.md` is the pass
  that has to happen before a release, and it is not a formality.
* **There is no end-to-end media test**, because there is no encoder — see
  [ARCHITECTURE.md](ARCHITECTURE.md#what-is-built-and-what-is-not). The WebRTC
  tests negotiate a real session, exchange SDP and assert that the answer
  carries `m=video`, `VP8` and `m=application`; nothing sends a frame.
* **There is no test against a running relay.** The signalling messages are
  parsed and asserted against the shapes `signalling/src/server.js` relays, and
  the session loop's join handling is tested; nothing in CI opens a socket to a
  real relay. That is a step in `desktop/tests/MANUAL.md`.
* **The installer has not been run.** `hooks.nsh` is reviewed and not executed;
  NSIS is not on a Linux runner and the release workflow is manual.
* **No signed build exists**, so nothing has been verified against a real
  certificate chain. `verify.ps1` has been read, not exercised against a
  signature.
* **macOS is not tested** because there is nothing to test: every provider
  returns `Unsupported`, and a test asserting that is a test of the refusal.

## Adding a test

Put it where it will run on every push:

1. Is it a decision? Then it belongs in `desktop/crates/` or in
   `agents/windows-service/src/machine.rs`, and it runs everywhere.
2. Is it arithmetic — coordinates, scaling, a bound? Pull the arithmetic out of
   the Windows-only module, as `describe_monitor` and `to_virtual_desktop`
   already are, and test it there.
3. Is it genuinely a native call? Then it is a manual test, and it goes in
   `desktop/tests/MANUAL.md` with what to do and what to expect.

The rule of thumb: if a test needs `#[cfg(target_os = "windows")]`, ask whether
the thing being tested could be moved instead.

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
