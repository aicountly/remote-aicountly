# Future: AICOUNTLY Remote for Windows, macOS and Linux

**Nothing in this document is built.** It records the seams V1 deliberately
left, so that the desktop agents can be added to *this* product rather than
becoming a second one.

If they were built as a separate system, AICOUNTLY would end up with two
session models, two audit trails, two policy engines and two products called
Remote. Everything below exists so that does not happen.

## The one rule V1 obeys

> The UI is built from a participant's declared capabilities, never from
> `clientType === 'BROWSER'`.

A browser reports `remote_control: false`. An agent will report `true`, and the
session screen grows the control it was always able to render — no schema
change, no parallel API, no rename of the SaaS.

## Capability negotiation

Already in the schema (`remote_participants.capabilities`, JSONB) and already
sent on every participant resource.

```jsonc
// what a browser reports today
{
  "screen_share": true,
  "screen_view": true,
  "remote_control": false,      // the Screen Capture API cannot do it
  "unattended_access": false,
  "file_transfer": true,
  "clipboard_sync": false,
  "reboot": false
}

// what an agent will report
{
  "screen_share": true,
  "screen_view": true,
  "remote_control": true,
  "unattended_access": true,
  "file_transfer": true,
  "clipboard_sync": true,
  "reboot": true
}
```

`App\Domain\Session\ClientCapabilities` holds both shapes and normalises a
claim against the ceiling for its client type. **A client cannot grant itself a
capability by asserting one** — the declaration is an upper bound, and policy is
still evaluated on top.

## What an agent reuses unchanged

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

An agent is a participant with different capabilities. That is the entire
architectural claim.

## What has to be added

### Devices

`remote_devices` exists and is empty. It carries `public_key`, `agent_version`,
`operating_system`, `status`, `unattended_access_enabled` and `last_seen_at`.

An agent will need its own authentication: a device keypair, enrolled once by a
signed-in user, with the agent proving possession of the private key. A device
is not a person, so it cannot use a `ses_key` — that is why the column is there
and why the identity projection is separate from it.

### Policy

New switches, following the existing pattern exactly:

```
allow_remote_control          default OFF
allow_unattended_access       default OFF
allow_clipboard_sync          default OFF
allow_device_reboot           default OFF
```

New permissions in `PermissionCatalog`:

```
remote.control.request
remote.control.accept
remote.device.enrol
remote.device.manage
remote.unattended.access
```

Every one defaults off, and every one goes through the same resolver — so the
"company prohibition beats user grant" property applies to remote control on
the day it ships, without new code to enforce it.

### Consent

Attended control needs the same explicit consent screen sharing has: the person
at the machine agrees, sees a persistent indicator, and can revoke instantly.

**Unattended access is a different thing and must be treated as one.** It needs
its own enrolment, its own company switch, its own audit event on every
connection, and a visible record on the device. It must never be a checkbox
inside an attended session.

### Events

Add to `EventType`, no schema change needed:

```
CONTROL_REQUESTED / CONTROL_GRANTED / CONTROL_DENIED / CONTROL_REVOKED
CLIPBOARD_SYNCED
DEVICE_ENROLLED / DEVICE_REVOKED
UNATTENDED_SESSION_STARTED
```

### Entitlements

`remote_entitlements.desktop_devices` and `.unattended_access` exist already and
default to false, so the plan gate is in place before the feature is.

## Where the browser boundary is enforced today

* `ClientCapabilities::browser()` returns `remote_control: false` — the ceiling
  a browser participant is normalised against.
* The session toolbar renders from capabilities and policy, so there is no
  "Control computer" button to hide.
* The product copy says *browser assistance*, *share*, *view* — never *control*.

That last point is not cosmetic. §2 is explicit that the limitation must never
be misrepresented in the interface, and the wording is what a customer decides
to trust the product on.
