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
  2  the agent starts a device-code sign-in  a short code, and a long secret
  3  a person confirms it in their browser   their own, already-working sign-in
  4  the agent polls and is handed a device  the server enrolled it on confirm
  5  the agent proves possession             challenge → signature → credential
  6  from then on, only step 5               this window never held a credential
```

No step in this ever puts a portal credential into the agent's window. That is
what makes a stolen copy of an agent installation useless: without the
machine's own key store, it cannot pass step 5, and it was never capable of
minting a step 5 credential for itself out of anything it was handed earlier.

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

### 2. Signing in, and enrolling — device-code sign-in

A native window cannot receive a portal redirect the way a browser tab can.
The first version of this tried anyway, with the loopback pattern RFC 8252
describes for exactly this shape of application: bind `127.0.0.1:0`, open the
portal in the system browser, wait for it to redirect back. It worked when the
portal showed its login form, and silently failed to complete when the person
already had a live portal session — the portal does not honour a `returnUrl`
on a loopback address for its own silent-SSO fast path, which is a portal
behaviour outside this repository, not something this codebase could fix or
guarantee.

**Device-code sign-in replaces it**, and depends on nothing outside this
repository: it reuses `web/`'s own sign-in, which already works in both
cases, because it is an ordinary browser-to-browser redirect rather than a
loopback one.

```text
  POST /v1/remote/desktop-signin/start    { publicKey, deviceName, … }   agent, unauthenticated
       → { userCode, deviceCode, verificationUriComplete, expiresAt }

  agent opens verificationUriComplete in the system browser

  the person, already able to sign in to the web app the ordinary way,
  confirms the code and chooses which organisation this machine belongs to

  POST /v1/remote/desktop-signin/poll     { deviceCode }                 agent, unauthenticated
       → { status: pending | denied | expired | confirmed, device? }
```

Two codes, two audiences, and the split is the whole security model:

* **`userCode`** is short (`ABCD-1234`) and shown on the machine's own
  screen, so a person can match it against the confirmation page. Someone who
  only saw it over a shoulder can open that page but cannot act as the agent.
* **`deviceCode`** is a 32-byte secret the agent holds and never displays. It
  is what `poll` is called with, and answering it is the proof — the same
  shape of guarantee a device-auth challenge nonce gives (§4 below), reused
  here for a sign-in that has no key to sign with yet.

`start` carries the enrolment request the agent already has at that moment —
its public key, its name, its declared capabilities — captured once, so
neither the browser confirmation nor the later poll needs to ask the agent for
anything further. The confirming browser supplies the other half: who is
confirming, and which company. The **server** joins the two the moment `poll`
observes the code confirmed, spending it exactly once (a guarded
`UPDATE … WHERE status = 'CONFIRMED'`, the same pattern a device-auth nonce is
spent with) and enrolling the device in the same call — `DeviceService::enrol`,
unchanged, so every check below still applies:

1. the confirming person is a member of that company;
2. the plan includes `desktop_devices`;
3. the confirming person holds `remote.device.enrol`;
4. the public key is well formed, 32 bytes, and not all-zero;
5. the key's fingerprint is not already enrolled anywhere
   (`remote_devices_fingerprint_uniq`).

The declared capabilities are stored **and intersected with the company's
policy on every read**. A machine that claimed `remote_control: true` in an
organisation that forbids it is a machine that will be refused control — the
declaration is an upper bound, never a grant.

`DEVICE_ENROLLED` is written to the audit trail with who did it, from where,
and the key fingerprint. Never the key. Confirming and declining the code are
their own audit events too — `DESKTOP_SIGNIN_CONFIRMED`,
`DESKTOP_SIGNIN_DENIED` — recorded against the person in the browser, since
that is where the human decision was actually made.

**No portal credential ever reaches this window.** Not the long-lived
`auth_token`, not a `ses_key` — `poll`'s successful answer is an already-
enrolled device, not a credential to enrol one with. That closes off more than
the loopback failure: it also means an action that genuinely needs a person's
own credential — turning unattended access *on*, today — cannot be
self-served from this window at all any more, only from the web console.
Turning it *off*, and unregistering, already have (or are meant to have) a
path through the machine's own device credential instead of a person's; see
`services/api.ts`'s header comment for where that still needs finishing.

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

**The device key, the configuration and the enrolment record are deleted.**
`NSIS_HOOK_POSTUNINSTALL` removes `%ProgramData%\AICOUNTLY\Remote\device-signing-key.key`,
`config.json` and `enrolment.json` — the last of these is what makes
`device_uuid` survive an ordinary restart (see "Signing in, and enrolling"
above); leaving it
behind would mean the next install's `Agent::load` believed it was still this
machine, with a key that had just been deleted.

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
