# Registering a computer

How a machine gets an identity, how it proves it, how that identity is taken
away, and what happens to it when the software is uninstalled.

## The shape of it

A machine is not a person. It cannot hold a portal `ses_key`, cannot be a
member of a company, and must not be able to do anything a person can. What it
holds is an **Ed25519 keypair whose private half never leaves it**, and an
identity the server issued once, to a person who was signed in at the time.

```text
  1  the agent generates a keypair          the private half goes to DPAPI
  2  a person signs in through the portal   ses_key, in memory, for seconds
  3  the agent enrols the PUBLIC key        POST /devices/enrol
  4  the agent proves possession            challenge → signature → credential
  5  from then on, only step 4              the ses_key is never used again
```

Step 2 is the only moment a user credential is involved, and it is used for
exactly one call. That is what makes a stolen copy of an agent installation
useless: without the machine's own key store, it cannot pass step 4.

## Step by step

### 1. The keypair

`enrol_device` (a Tauri command) calls `Agent::create_device_key()`:

```rust
let keypair = DeviceKeypair::generate();
let public_key = keypair.public_key_base64();

secure_storage().store(DEVICE_KEY_ENTRY, keypair.secret_bytes(), StorageScope::LocalMachine)?;

Ok(public_key)          // the PUBLIC half, and only that
```

The private half goes straight to the platform's key store — DPAPI on Windows,
machine scope, with additional entropy — and is never returned to the window,
never logged, and never crosses the IPC channel. `DeviceKeypair`'s `Debug`
implementation prints the fingerprint and nothing else, so a stray `{:?}`
cannot become a leak.

### 2. Signing in

The person signs in through the AICOUNTLY portal exactly as they would for any
other AICOUNTLY SaaS. The `ses_key` lives in a module variable in the agent's
window and dies with it: no `localStorage`, no file, no keychain.

### 3. Enrolment

```http
POST /v1/remote/devices/enrol
Authorization: Bearer <ses_key>

{
  "companyId": 481,
  "deviceName": "Priya's laptop",
  "publicKey": "<base64, 32 bytes>",
  "deviceType": "DESKTOP",
  "operatingSystem": "Windows",
  "osVersion": "11 24H2",
  "architecture": "x86_64",
  "hostname": "WS-01",
  "agentVersion": "1.0.0",
  "capabilities": { "remote_control": true, ... }
}
```

The server checks, in this order:

1. the caller is a member of that company;
2. the plan includes `desktop_devices`;
3. the caller holds `remote.device.enrol`;
4. the public key is well formed, 32 bytes, and not all-zero;
5. the key's fingerprint is not already enrolled anywhere
   (`remote_devices_fingerprint_uniq`).

The declared capabilities are stored **and intersected with the company's
policy on every read**. A machine that claimed `remote_control: true` in an
organisation that forbids it is a machine that will be refused control — the
declaration is an upper bound, never a grant.

`DEVICE_ENROLLED` is written to the audit trail with who did it, from where,
and the key fingerprint. Never the key.

### 4. Proving possession

```http
POST /v1/remote/devices/auth/challenge      { "deviceUuid": "…" }
      →  { "nonce": "…", "issuedAt": 1770000000, "expiresAt": "…" }
```

The agent signs the canonical payload — not JSON:

```
AICOUNTLY-REMOTE-DEVICE-AUTH-v1\n<uuid>\n<nonce>\n<issuedAt>\naicountly-remote-api\n
```

```http
POST /v1/remote/devices/auth/verify
{ "deviceUuid": "…", "nonce": "…", "issuedAt": 1770000000, "signature": "<base64>" }
      →  { "token": "device.…", "expiresAt": "…", "scopes": [...] }
```

Both endpoints are unauthenticated by design: a nonce is worthless without the
private key, and the signature **is** the authentication. Both are
rate-limited, because generating them in a loop is the only load they can put
on anything.

The nonce is spent with a guarded `UPDATE … WHERE consumed_at IS NULL`, and the
affected-row count is checked — so two verifications racing on one nonce cannot
both succeed. A failure of any kind returns the same refusal, so a caller
cannot tell an unknown device from a bad signature from a spent nonce.

### 5. The credential

Short-lived, held in memory, scoped:

| Scope | Reaches |
|---|---|
| `device.presence` | presence, and a token for its own presence room |
| `device.session` | joining a session it was invited to; reading and reporting control |
| `device.self` | switching its own unattended access off |

Nothing under `devices/me` can reach a session it was not invited to, another
device, or a company. `DeviceAuthFilter` re-reads the device row on **every**
request, so revocation takes effect on the next call rather than at the next
renewal.

The connection loop renews a minute before expiry rather than after the first
refusal, so an unattended connection does not arrive to find the agent
re-authenticating.

## The fingerprint

Shown in three places, and it is the only thing that ties a row in a console to
a machine in a building:

* in the agent's own window, on the machine;
* on the Computers page, in the browser;
* in the audit trail, at enrolment.

Grouped for reading (`A1B2 C3D4 E5F6 0718`). Somebody about to connect to a
machine they cannot see can compare it with what the person at that machine
reads out.

## Taking it away

Three different acts, and they are not the same:

| Act | Who | Effect |
|---|---|---|
| **Suspend** | an administrator | the device stops authenticating; the row and the key remain |
| **Revoke** | an administrator | permanent; reinstalling does not bring it back |
| **Unregister** | whoever is at the machine | the local key is deleted *and* the row is revoked |

Revocation is **server-side and immediate**. It does not wait for the agent to
cooperate, and an unexpired credential stops working on its next request. The
agent notices, stops retrying, and says the machine was removed — the one state
its connection loop treats as terminal.

Unregistering does both halves deliberately. A key left on a machine whose row
was revoked authenticates nothing but is still on the machine; a row left
active for a machine whose key was deleted is a row somebody has to clean up.

## Uninstalling

**The device key and the configuration are deleted.** `NSIS_HOOK_POSTUNINSTALL`
removes `%ProgramData%\AICOUNTLY\Remote\device-signing-key.key` and
`config.json`.

This is a decision rather than a default. Uninstalling a remote-support agent
is somebody saying *this machine should no longer be reachable*, and leaving a
usable identity behind — so that a later reinstall silently restored unattended
access — would be the opposite of what they asked for. The cost is that
reinstalling means enrolling again, which needs an AICOUNTLY sign-in. That is
the right way round.

The device's **row** is not deleted by the uninstaller: the server is the
authority on that, and an administrator removes it from the Computers page.
What the uninstall guarantees is that the machine can no longer prove
possession, so the row cannot be used from there whether or not anybody tidies
it up.

## What could still go wrong, and what happens

| | |
|---|---|
| The key file is deleted but the state says enrolled | authentication fails, the status says so, and re-registering fixes it |
| The same key is enrolled twice | refused by the fingerprint unique index |
| A challenge is replayed | the nonce is spent; refused |
| A challenge is used after expiry | refused, and expired rows are swept |
| The clock is wrong | `issuedAt` is bounded against the server's clock |
| The company is changed underneath the device | every read resolves policy for the device's own company |
| A device from company A asks about company B | not found, not forbidden — tenant isolation reveals nothing |
