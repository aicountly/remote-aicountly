# AICOUNTLY Remote for Windows, macOS and Linux

This document was written before any of it existed, to record the seams V1
deliberately left. **The Windows agent now fills them.** What follows is the
contract, and then an honest account of how much of it is built.

The detail lives in [`docs/desktop/`](desktop/):

| | |
|---|---|
| [ARCHITECTURE.md](desktop/ARCHITECTURE.md) | how the agent is put together, and what is not built |
| [SECURITY.md](desktop/SECURITY.md) | the five checks a keystroke passes, and the real limits |
| [DEVICE_ENROLMENT.md](desktop/DEVICE_ENROLMENT.md) | how a machine gets an identity and how it loses one |
| [WINDOWS_AGENT.md](desktop/WINDOWS_AGENT.md) | capture, input, the service, and what Windows forbids |
| [WINDOWS_RELEASE.md](desktop/WINDOWS_RELEASE.md) | building, signing, releasing, and why updates are off |
| [TESTING.md](desktop/TESTING.md) | what is covered, and what is not |

## The one rule, still obeyed

> The UI is built from a participant's declared capabilities, never from
> `clientType === 'BROWSER'`.

A browser reports `remote_control: false`. An agent reports `true`, and the
session screen grew the control it was always able to render — no schema
change, no parallel API, no rename of the SaaS.

`web/src/features/room/ControlPanel.tsx` renders from
`controllableHostUuid` — which the server derives from negotiated capabilities
— and from the effective policy. A browser-to-browser session gets one honest
sentence and no button. `ControlPanel.test.tsx` asserts it directly:
`hasControllableHost: false` is `unavailable` whatever the policy says.

**Browser V1 still cannot be controlled, and nothing implies otherwise.**

## Capability negotiation

Unchanged from the original design: `remote_participants.capabilities`, JSONB,
sent on every participant resource.

```jsonc
// a browser
{ "screen_share": true, "screen_view": true, "remote_control": false,
  "unattended_access": false, "file_transfer": true, "clipboard_sync": false,
  "reboot": false }

// a Windows agent
{ "screen_share": true, "screen_view": true, "remote_control": true,
  "unattended_access": true, "file_transfer": true, "clipboard_sync": true,
  "reboot": true }
```

`ClientCapabilities` normalises a claim against the ceiling for its client
type, and `DeviceService::effectiveCapabilities()` intersects the stored
declaration with the company's policy **on every read**. A client cannot grant
itself a capability by asserting one.

## What the agent reuses unchanged

| Concern | Reused |
|---|---|
| Sessions | `remote_sessions`, the state machine, expiry |
| Participants | `remote_participants`, approval, presence |
| Invitations | `remote_invitations`, one-time secrets |
| Policy | the whole hierarchy, unchanged |
| Audit | `remote_session_events`, `remote_audit_logs` |
| Support | `remote_support_requests` |
| Company context | the signed launch token |
| Realtime | the same signalling service and token |

An unattended connection goes through `SessionService::create()` like every
other session. There is no second session model, no second audit trail and no
second policy engine.

## What was added

### Devices

`remote_devices` was an empty table with a `public_key` column. It now carries
the key algorithm, a fingerprint (uniquely indexed), presence, the agent
version, the unattended switch with who enabled it and when it was last used,
and revocation. `remote_device_challenges` holds single-use nonces.

Authentication is Ed25519 proof of possession over a canonical, domain-
separated payload — never a permanent bearer token. See
[DEVICE_ENROLMENT.md](desktop/DEVICE_ENROLMENT.md).

### Policy

The four switches, exactly as designed, all defaulting **off**:

```
allow_remote_control          allow_unattended_access
allow_clipboard_sync          allow_device_reboot
```

with database CHECKs enforcing the dependencies — unattended access and reboot
require remote control, so the data cannot hold a combination the resolver
would have to interpret.

The five permissions, all defaulting off:

```
remote.control.request   remote.control.accept   remote.device.enrol
remote.device.manage     remote.unattended.access
```

The capability mask is applied **last** in `EffectivePolicyResolver`, so a
company prohibition beats a user-level ALLOW. `DesktopPolicyTest` proves it.

### Consent

Attended control has the explicit consent screen sharing has: the person at the
machine sees who is asking and from which organisation, agrees or refuses, sees
a persistent indicator, and can stop it instantly — locally, with no network
round trip and no permission required.

**Unattended access is a different thing and is treated as one.** Its own
entitlement, its own company switch, its own permission, its own device-level
enablement, its own audit event on every connection, and a visible record on
the device and in the console. It is never a checkbox inside an attended
session, and `ControlDecision` has no fourth variant that could become one.

### Events

Added to `EventType`, no schema change:

```
CONTROL_REQUESTED / CONTROL_GRANTED / CONTROL_DENIED / CONTROL_REVOKED
CLIPBOARD_SYNCED
DEVICE_ENROLLED / DEVICE_REVOKED
UNATTENDED_SESSION_STARTED
```

`remote_session_events.actor_type` and `remote_audit_logs.actor_type` now
accept `'DEVICE'`, so a decision the machine made is recorded as the machine's.

### Entitlements

`remote_entitlements.desktop_devices` and `.unattended_access` already existed
and defaulted to false. They are now the plan gate they were put there to be.

## How much of it is built

**The whole server side, the whole browser side, and the machine's identity,
policy, consent and lifecycle.** 583 automated tests across the five parts of
the product.

**Not the media pipeline.** There is no video encoder: `remote-webrtc`
negotiates a VP8 track and the Windows capture provider produces BGRA frames,
and nothing converts one to the other. The agent's signalling client is not
written either. So today an agent registers, stays reachable, appears in the
Computers page, joins the policy and consent model completely — and does not
yet send a picture.

The full list of what is and is not built is in
[ARCHITECTURE.md](desktop/ARCHITECTURE.md#what-is-built-and-what-is-not), and
it is deliberately at the end of that document rather than buried: a design
document that describes intentions as though they were code is how a team
discovers a gap at the wrong moment.

**macOS and Linux are not built.** Every provider in
`src-tauri/src/platform/macos/` returns `PlatformError::Unsupported`. The trait
boundary is what would keep that work confined; the work has not been done, and
nothing pretends it has.
