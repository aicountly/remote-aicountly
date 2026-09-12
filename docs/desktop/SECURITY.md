# The desktop agent's security model

A program that can see somebody's screen and move their mouse deserves to be
read sceptically. This document says what protects that, where the boundary
actually is, and — the part that matters most — what it does **not** protect.

The browser product's model is [../SECURITY.md](../SECURITY.md) and still
applies in full: this is what the agent adds.

## The five things a keystroke has to get past

Input reaches a Windows desktop only when all five are true. They are checked
in different places on purpose, because a single check is a single thing to get
wrong.

| # | Check | Where | If it fails |
|---|---|---|---|
| 1 | The host declared `remote_control` | negotiated capabilities | there is no control UI at all |
| 2 | The plan includes desktop devices | `remote_entitlements` | the capability is masked off |
| 3 | The organisation allows control | `allow_remote_control` | `REMOTE_CONTROL_NOT_ALLOWED` |
| 4 | The people hold the permissions | `remote.control.request` / `.accept` | `CONTROL_REQUEST_DENIED` |
| 5 | The person at the machine agreed | `ControlGate`, in the agent | the message is dropped and counted |

1–4 are the server's. **5 is the agent's, and it is local.** That is
deliberate: revoking a grant must not depend on the network, on the browser
cooperating, or on the API being reachable. `ControlGate::revoke()` changes a
value in the agent's own memory and the next message is dropped, whatever
anything else believes.

The capability mask is applied **last** in `EffectivePolicyResolver`, after
every role and user grant. A user-level ALLOW cannot survive a company
prohibition, and `backend/tests/Policy/DesktopPolicyTest.php` asserts exactly
that.

## Device identity

A machine is not a person and cannot hold a person's credential. It holds an
**Ed25519 keypair**, generated on the machine, whose private half never leaves
it.

```text
  agent                                   API
  ─────                                   ───
  POST /devices/auth/challenge  ─────▶    nonce, issuedAt, expiry
                                          single-use row
  sign(canonical payload)
  POST /devices/auth/verify     ─────▶    verify signature
                                          spend the nonce (guarded UPDATE)
                                ◀─────    a short-lived device credential
```

The signed payload is canonical, domain-separated and newline-delimited:

```
AICOUNTLY-REMOTE-DEVICE-AUTH-v1
<device uuid>
<nonce, lowercase hex>
<issuedAt, seconds>
aicountly-remote-api
```

Never JSON. A signature over JSON is a signature over whichever serialiser
produced it, and two implementations that disagree about key order produce a
signature that verifies on one and not the other. `DeviceSignature` (PHP) and
`remote_security::challenge_payload` (Rust) build the same bytes, and a test on
each side asserts the exact string.

Properties the challenge/response holds, each with a test:

* **single use** — the nonce is spent with `UPDATE … WHERE consumed_at IS NULL`
  and the affected-row count is checked, so two simultaneous verifications
  cannot both win;
* **bounded lifetime** — expired challenges are refused and swept;
* **replay-proof** — a captured signature is worthless once the nonce is spent;
* **tenant-isolated** — a device uuid from another company is not found;
* **revocation is immediate** — the device row is re-read on every request by
  `DeviceAuthFilter`, so a revoked device's unexpired credential stops working
  on its next call rather than at its next renewal;
* **rate-limited** — both endpoints, because running them in a loop is the only
  load they can put on anything;
* **audited without secrets** — the event records the device, not the key.

There is **no permanent bearer token that is the machine's identity**. The
credential lasts minutes, is held in memory, and is re-obtained by proving
possession again.

## Where the private key lives

Windows DPAPI, machine scope (`CRYPTPROTECT_LOCAL_MACHINE`), with additional
entropy, in `%ProgramData%\AICOUNTLY\Remote\device-signing-key.key`.

Machine scope rather than user scope because the service runs as `LocalSystem`
and a user-scoped blob would be invisible to it — a device that could only
authenticate while one particular person happened to be signed in would not be
an unattended device at all.

Machine scope on its own means any process on the machine could call
`CryptUnprotectData` with the defaults. The additional entropy raises that to
"a process written against this specific product". **It is not a secret** —
it is compiled into a binary anybody can download — and it is not pretending to
be one. What actually protects the file is its ACL (SYSTEM and Administrators
full control, Users read) and the fact that reading it requires code execution
on the machine already; at that point the attacker can see the screen without
any of this.

The key is **never**: in `.env`, in `localStorage`, in plaintext JSON, in the
registry, in a log line, in Git, in PostgreSQL, in browser storage, or in an
IPC message. `agents/windows-service/src/ipc.rs` has a test asserting the last
of those directly.

## Unattended access

Not "remember this approval". A different thing, with its own everything:

| | Attended | Unattended |
|---|---|---|
| Entitlement | `desktop_devices` | `unattended_access` |
| Company policy | `allow_remote_control` | `allow_unattended_access` |
| Permission | `remote.control.request` | `remote.unattended.access` |
| Device switch | — | `unattended_access_enabled`, off by default |
| Consent | per session, at the machine | earlier, deliberate, revocable |
| Event | `CONTROL_GRANTED` | `UNATTENDED_SESSION_STARTED` |

`DeviceSessionService::startUnattended()` checks six things in order — tenant,
policy ∧ entitlement, permission, device ACTIVE, device switch on, device
reachable — and a database CHECK enforces the dependency
(`NOT unattended_access_enabled OR status = 'ACTIVE'`) so a suspended device
cannot be left unattended-enabled in the data.

It can be switched off by an administrator in the Computers page, or by
whoever is at the machine, from the agent, with **no permission required** —
`POST /devices/me/unattended/disable`, on the machine's own credential. Taking
a capability away is not the act that needed authorising.

An unattended session is an **ordinary** Remote session: same policy snapshot,
same expiry, same participant rows, same audit trail. The tray still shows a
session is running. There is no hidden mode and no code path that could create
one — `AgentState::is_session_active()` is derived from the status rather than
stored beside it, so nothing can be in a session without the indicator being on.

## The IPC channel

A named pipe, `\\.\pipe\AicountlyRemote.Agent`, not a localhost TCP port. A
listener on `127.0.0.1` is reachable by every process on the machine including
anything a browser can be made to talk to, and it has no idea who is
connecting.

```
D:(A;;GA;;;SY)(A;;GA;;;BA)(A;;GRGW;;;IU)S:(ML;;NWNRNX;;;LW)
```

LocalSystem and Administrators full control; the **interactive** user (not
`Users` — narrower, and excludes a service account nobody is sitting in front
of) read and write; no entry for Everyone, Anonymous or Guests; and a mandatory
label that keeps low-integrity processes — a sandboxed browser — out even if
they somehow satisfied the DACL. `PIPE_REJECT_REMOTE_CLIENTS` is set, so it is
not reachable over SMB.

The protocol is length- and version-prefixed, bounded at 64 KiB, and
exhaustively enumerated. Two tests assert the properties that matter:

* `the_protocol_can_express_nothing_executable` — no message names a program,
  a path, an argument, a command line or a library, and there is no
  passthrough;
* `the_protocol_never_carries_a_key_or_a_credential`.

The widest thing a peer that satisfies the ACL can cause is a restart of a
machine it is already signed in to — and even that is refused for a session the
service was never told about, and rate-limited against a restart loop.

**The handshake is not an authentication.** It agrees a protocol version and
catches a half-finished update. A process already running as the signed-in user
could speak this protocol, and nothing at that layer could tell the difference;
the ACL is the boundary, and the protocol being this small is what makes that
acceptable.

## Clipboard

Text only. Policy default off. **A grant of control does not turn it on** — it
is a separate tick in the consent dialog, a separate column
(`clipboard_enabled`), and a database CHECK ties it to an active grant
(`NOT clipboard_enabled OR control_state = 'GRANTED'`).

Bounded, UTF-8 validated, control characters stripped. The audit records that
the clipboard was used and never what was on it; `AuditService::scrub()` would
drop a `body` key even if a caller passed one, and not passing one is the
actual rule.

## What this does not protect

Read this part twice. Everything here is a real limit, and none of it is
worked around by a setting.

* **The Secure Desktop.** A UAC prompt, the sign-in screen and Ctrl+Alt+Del
  run on a separate desktop that a user-session process cannot capture and
  cannot inject into. The agent **notices** this — `OpenInputDesktop` fails —
  and says so, and it does nothing to defeat it. The consequence is real: a
  remote session goes blank at a UAC prompt and somebody has to be at the
  machine. That is the correct behaviour, and any product that tells you
  otherwise has disabled something on your machine.
* **UIPI.** `SendInput` cannot inject into a window running at a higher
  integrity level than the agent. The failure is reported as what it is rather
  than as a mysterious refusal.
* **A machine an attacker already runs code on.** The device key protects the
  machine's *identity*; it does not protect a machine whose screen the attacker
  can already read.
* **The person at the machine, from themselves.** Somebody who is socially
  engineered into pressing Allow has allowed it. What the product can do is
  make the request unambiguous — who, from which organisation, what control
  means — and make stopping it a single obvious button that always works.
  Both of those it does.
* **An administrator of the organisation.** Somebody with
  `remote.device.manage` and `remote.unattended.access` can enable unattended
  access on a machine in their company. That is the feature. What constrains it
  is that it is visible on the device, visible in the Computers page, recorded
  in the audit trail with who and when, and switchable off from the machine
  itself without asking them.

## The twenty requirements, and where each one lives

| # | Requirement | Where |
|---|---|---|
| 1 | TLS/WSS only | `AgentConfig::validate` refuses `http://` outside a debug build |
| 2 | Short-lived credentials | `DevicePrincipal`, minutes, renewed by re-proving possession |
| 3 | Proof of possession | `DeviceAuthenticationService`, Ed25519 over a canonical payload |
| 4 | The key never leaves the machine | DPAPI; never a parameter, response or IPC message |
| 5 | No hardcoded secrets | `AgentConfig` has no field one could go in |
| 6 | No TURN credentials in the app | ICE comes from `GET /devices/me`, per session |
| 7 | No auth token in logs | `DeviceCredential` has no `Debug`; the runtime logs a summary |
| 8 | No clipboard content stored | `CLIPBOARD_SYNCED` records the capability, not the text |
| 9 | No screen pixels stored | Frames go to the encoder; nothing writes one anywhere |
| 10 | No file bytes on the server | Transfers are peer to peer; the API sees the ledger |
| 11 | No arbitrary execution | `IpcRequest` has no such variant; there is no shell path |
| 12 | No hidden control | `is_session_active()` derived from status |
| 13 | A persistent indicator | Tray, window and the browser's own banner |
| 14 | Immediate local stop | `ControlGate::revoke()`, no permission, no network |
| 15 | Server-side revocation | `DeviceAuthFilter` re-reads the row every request |
| 16 | Audit | `CONTROL_*`, `DEVICE_*`, `UNATTENDED_SESSION_STARTED` |
| 17 | Tenant isolation | Every device lookup resolves the caller's policy first |
| 18 | Rate limiting | Enrolment, challenge, verify, presence, control, reboot |
| 19 | Replay protection | Single-use nonces; a monotonic control sequence |
| 20 | Strict input validation | Bounds, UTF-8, identifier shapes, before anything acts |
