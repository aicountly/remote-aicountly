# Control companions

A browser cannot be controlled. A **control companion** lets a viewer control a computer anyway while its
owner shares an *entire screen* from Chrome or Edge: the owner's own AICOUNTLY Remote desktop app joins the
same session as an input-only participant, linked to the owner's browser participant. The picture still comes
from the browser; the companion only applies input the owner granted.

```
 A's browser (sharing an entire screen) ──── video ────▶ B's browser
        │ links A's own device                              │ requests control
        ▼                                                   ▼
 A's desktop app  (CONTROL_COMPANION, input only) ◀── aicountly-remote-control ── B
```

Server side: `backend/app/Domain/Device/CompanionService.php` (decisions),
`backend/app/Domain/Session/CompanionLinks.php` (reading, expiry, presenting),
`ControlService` (who is controllable, who may grant), `CompanionController` and `DeviceAgentController`.
Browser side: `CompanionPanel.tsx`, `RemoteSessionEngine.ts`, `useRemoteSession.ts`.
The desktop half lives in `desktop/` and follows the same contract.

## Owner decisions

1. **Entitlement**: the existing `desktop_devices` entitlement, `featureDesktopAgent` and the scope's
   `allow_remote_control` — the company's policy in a company session, the platform's personal policy
   (`CompanyPolicyDefaults::personal()`, on) in a personal one. REMOTE_PROFESSIONAL's platform default stays off.
5. **Personal sessions** take companions. Remote is used with a personal account, so this is the ordinary
   case: a personal session links its owner's own *personal* computer (both `company_id IS NULL`), and a
   personal account may share an entire screen and request control without anybody granting it those —
   in a personal session the person at the machine is the only one whose say matters, and they still confirm
   the link there and grant each request. A support session takes none (`COMPANION_SCOPE`).
2. **Consent, twice**: a one-time confirmation at the machine per link, *and* the owner grants each control
   request in the browser (the existing control queue). The companion can stop control; it can never grant it.
3. **Clipboard**: off in v1. A companion participant never carries `clipboard_sync`, a grant through one is
   always written with `clipboard_enabled = false`, and switching the clipboard on is refused.
4. **macOS signing** is outside this work (owner test builds: one stable Apple Development certificate;
   Developer ID and notarisation before external testers).
5. **Minimum macOS**: 13.
6. **Controllable browsers**: only those that report `displaySurface` (Chrome, Edge). Firefox and Safari report
   nothing, are recorded as `unknown`, and never qualify.
7. **Local override**: any real mouse or keyboard activity at the machine pauses remote input, releases held
   keys and sends `control_paused`; input resumes after 1.5 s of local idle (`control_resumed`).

## Lifecycle of a link

| Status | Meaning | Next |
|---|---|---|
| `PENDING` | A linked one of their devices; waiting for somebody at that machine to confirm. Expires after 5 minutes. | `JOINED`, `DECLINED`, `EXPIRED`, `ENDED` |
| `JOINED` | The companion participant is in the session; it has not matched the monitor yet. | `ACTIVE`, `ENDED` |
| `ACTIVE` | It knows which monitor the shared picture is. Controllable while A shares an entire screen. | `JOINED` (picture changed), `ENDED` |
| `DECLINED` / `EXPIRED` / `ENDED` | Finished. `end_reason` says why. The device is free again. | — |

`ACTIVE → JOINED` happens whenever the browser reports a *different* picture (another surface, or the same
surface at another size) and whenever sharing stops: the companion must match the monitor again and POST it.
Reporting the same share again changes nothing.

The companion is **controllable** (`ControlService::controllableHost()`) only while all of these hold at once:
its link is `ACTIVE`, the linked browser participant is `JOINED` and sharing, and the session's
`actual_display_surface` is `monitor`.

## Endpoints

Browser (`api-auth`; a guest token is refused, and so is a support session):

| Method and path | What it does |
|---|---|
| `GET /v1/remote/sessions/{uuid}/companion/devices` | `{devices: Device[], restrictions: string[]}` — the caller's own devices in the session's scope (personal ones for a personal session, the session's company's otherwise) that are ACTIVE, online and declare `remote_control`. Restrictions: `COMPANION_SCOPE` (a support session), `REMOTE_CONTROL_NOT_ALLOWED` (+ the policy's `REMOTE_CONTROL_*`), `CONTROL_ACCEPT_DENIED`, `NO_ELIGIBLE_DEVICE`. |
| `POST /v1/remote/sessions/{uuid}/companion` `{deviceUuid}` | rate limit `companion-link,10,60`. `201 {companion, control, deviceInvite}`. `deviceInvite` is `{token, url, room, expiresAt, sessionUuid, companionUuid}` — a 2-minute token for the device's presence room; the browser sends `device-invite` `{sessionUuid, companionUuid}` there so the app re-reads `GET /devices/me` at once. |
| `DELETE /v1/remote/sessions/{uuid}/companion` | Ends the live link (`ENDED_BY_USER`) and revokes any control through it. Host, owner or the linked sharer. Idempotent. `{companion, control}`. |
| `POST /v1/remote/sessions/{uuid}/share-started` | Adds optional `surfaceWidth`, `surfaceHeight` (`track.getSettings()`, 1..16384, both or neither). |
| `GET /v1/remote/sessions/{uuid}/control` | Adds `companion`, `sharedSurface`, `controlBlockedReason`, `grantId` (below). |

`POST /companion` errors: `SESSION_ALREADY_ENDED` 409, `COMPANION_SCOPE` 403 (a support session),
`REMOTE_CONTROL_NOT_ALLOWED` 403, `CONTROL_ACCEPT_DENIED` 403, `NOT_SESSION_HOST` 403 (the caller must be the joined, sharing host),
`NOT_FOUND` 404 (not the caller's device, or not in the session's scope), `DEVICE_NOT_ACTIVE` 409, `DEVICE_OFFLINE` 409,
`DEVICE_NOT_CONTROLLABLE` 409 (the device ∩ policy has no `remote_control`), `COMPANION_ALREADY_LINKED` 409
(a live link, or the session already has a controllable agent), `DEVICE_BUSY` 409 (live link elsewhere, or the
machine is already in this session as its shared screen).

Device (`device-auth:device.session`, all before `devices/(:segment)` in `Routes.php`):

| Method and path | What it does |
|---|---|
| `GET /v1/remote/devices/me` | Adds `pendingCompanions: [{companionUuid, sessionUuid, displayId, companyName, requestedByName, requestedAt, expiresAt}]`. |
| `POST /v1/remote/devices/me/companions/{uuid}/join` | After the local confirmation. Same shape as `devices/me/sessions/{uuid}/join` (`session, participant, signalling, iceServers, relayAvailable, ice`) plus `companion`. Idempotent: again returns a fresh signalling token for the same participant. Errors: `NOT_FOUND`, `COMPANION_NOT_PENDING` 409 (`details.status`), `REMOTE_CONTROL_NOT_ALLOWED`, `CONTROL_ACCEPT_DENIED`, `DEVICE_NOT_CONTROLLABLE`, `DEVICE_BUSY`. rate limit `device-companion-join,30,60`. |
| `POST /v1/remote/devices/me/companions/{uuid}/decline` | `PENDING → DECLINED`. Idempotent on DECLINED. `{companion}`. |
| `POST /v1/remote/devices/me/companions/{uuid}/monitor` `{monitor: {id, name, x, y, width, height, scale}, matchedBy}` | `JOINED/ACTIVE → ACTIVE`. `matchedBy` ∈ `AUTO_SINGLE`, `AUTO_ASPECT`, `USER`. Integers; width/height 1..16384, x/y ±65536, scale 0.25..8. A different monitor while ACTIVE revokes control (`MONITOR_CHANGED`). `{companion, control}`. `COMPANION_NOT_JOINED` 409 before join. |
| `POST /v1/remote/devices/me/companions/{uuid}/leave` | Ends the link (`LEFT_AT_DEVICE`), revokes control, the participant leaves. Idempotent. `{companion}`. |
| `GET /v1/remote/devices/me/sessions/{uuid}/control` | Works for the companion participant. `companion` is **this device's own** latest link in the session; `allowClipboardSync` and `allowDeviceReboot` are false for a companion. |
| `POST /v1/remote/devices/me/sessions/{uuid}/control` | `DENY`/`REVOKE` from the companion as before (actor `DEVICE`). `GRANT` → `403 COMPANION_CANNOT_GRANT`. |

`devices/me/sessions/{uuid}/join` refuses a device that is a companion in that session (`409 DEVICE_IS_COMPANION`).
Any 404 means "not this device's link" — another device's link and another company's session look the same.

### The added control-state fields

```jsonc
{
  "companion": {                // the session's most recent link (any status), or null
    "uuid": "…", "status": "ACTIVE", "deviceUuid": "…", "deviceName": "Asha MacBook",
    "linkedParticipantUuid": "…",   // the browser participant it serves
    "participantUuid": "…",         // its own participant, once joined
    "monitor": {"id": 1, "name": "…", "x": 0, "y": 0, "width": 1512, "height": 982, "scale": 2},
    "monitorMatch": "AUTO_SINGLE",
    "ready": true,                  // ACTIVE and A shares an entire screen right now
    "endReason": null, "requestedAt": "…", "expiresAt": "…", "joinedAt": "…", "endedAt": null
  },
  "sharedSurface": {"displaySurface": "monitor", "width": 3024, "height": 1964, "verified": true},
  "controlBlockedReason": null,     // NO_COMPANION | NOT_SHARING | SURFACE_NOT_MONITOR | COMPANION_NOT_READY
  "grantId": "3f…"                  // opaque: controller uuid + control_granted_at; null without a controller
}
```

`controlBlockedReason` is null whenever somebody can be controlled (a companion that is ready, or an agent that
shares its own screen). Otherwise, in this order: no live link → `NO_COMPANION`; the linked person is not
sharing → `NOT_SHARING`; the surface is not `monitor` (including `unknown`) → `SURFACE_NOT_MONITOR`; the link is
`PENDING` or `JOINED` → `COMPANION_NOT_READY`. A request or a grant refused for this reason is
`409 HOST_NOT_CONTROLLABLE` with `details.controlBlockedReason`.

`grantId` lets the agent re-arm its gate only for a *new* grant: after a local Stop it stays revoked until it
sees a different `grantId`.

## Who may do what

* Only the person sharing may link, and only their own device, in the session's company (404 otherwise).
* One live link per session and one per device — enforced by partial unique indexes, not only by code.
* Only the **linked sharer** grants control through a companion: not the session owner, not another host, not an
  administrator (`NOT_SESSION_HOST`). Nothing is grantable while the companion is not ready.
* The companion participant is `role CONTROL_COMPANION`, `client_type DESKTOP_AGENT`, `is_host false`, status
  `JOINED`, `user_id` = the device owner (who must be the sharer), `companion_of_participant_id` = the sharer.
  Its capabilities are the device's declaration ∩ the owner's policy, then narrowed to `remote_control` alone.
* `ParticipantService::findByUser()` never returns it (it has a device), and `setSharing()` never marks it.

## What takes control away without anybody pressing Stop

| Trigger | Effect | Audit |
|---|---|---|
| `share-started` with a different surface or size | control revoked; ACTIVE → JOINED | `CONTROL_REVOKED` actor `SYSTEM`, reason `SURFACE_CHANGED`; `CONTROL_COMPANION_JOINED` (reason, from ACTIVE) |
| `share-stopped` (or a context mismatch) | control revoked; ACTIVE → JOINED | `CONTROL_REVOKED` `SYSTEM` `SHARE_STOPPED` |
| The sharer leaves | link ENDED, companion participant LEFT, control revoked | `CONTROL_REVOKED` `SYSTEM` `SHARER_LEFT`; `CONTROL_COMPANION_ENDED` |
| The browser ends the link | as above, actor the user | `ENDED_BY_USER` |
| The companion leaves | as above, actor `DEVICE` | `LEFT_AT_DEVICE` |
| A different monitor while ACTIVE | control revoked | `MONITOR_CHANGED` |
| PENDING for 5 minutes | EXPIRED (on read) | `CONTROL_COMPANION_ENDED` `SYSTEM` `EXPIRED` |
| The session ends | ENDED | `CONTROL_COMPANION_ENDED` `SYSTEM` `SESSION_ENDED` |

## Audit events

`CONTROL_COMPANION_REQUESTED` (USER, security), `CONTROL_COMPANION_JOINED` (DEVICE or SYSTEM, security),
`CONTROL_COMPANION_DECLINED` (DEVICE, material), `CONTROL_COMPANION_READY` (DEVICE, material — monitor id, size,
scale, `matchedBy`), `CONTROL_COMPANION_ENDED` (USER, DEVICE or SYSTEM, material — `reason`). Each carries
`companionUuid`; the existing `CONTROL_*` events carry `companionParticipantUuid` / `reason` where relevant.

## Signalling and the browser engine

* The companion's token is minted with role `CONTROL_COMPANION`, name `"<device> (control)"` and its capabilities,
  kind session — the relay treats it like any session participant.
* `control-hint` is relayed (broadcast, payload always dropped): "re-read the control state now". Browsers send it
  after every control call; it grants nothing.
* The browser engine never offers or sends media, chat, pointers, annotations or files to a CONTROL_COMPANION peer
  and never takes a picture, a share state or connection quality from one. A viewer offers to the companion only
  while its own control state is `REQUESTED` or `GRANTED` and it is the API's `controllableHostUuid`; the
  connection is control-only (`aicountly-remote-control`, no audio/video offered) and is closed when the state
  moves on. An offer a companion makes unasked is dropped.
* From the companion (protocol version 1, additive): `monitor_layout`, `control_paused {reason}`,
  `control_resumed {}`, `control_ended {reason}` — every one enveloped with `p` = the companion's participant uuid;
  one naming anybody else is ignored. While paused the browser sends no input and says so.

## Database

Migration `2026-10-25-000100_AddRemoteControlCompanions`:

* `remote_control_companions` — `CHECK (status <> 'ACTIVE' OR (monitor IS NOT NULL AND monitor_match IS NOT NULL))`,
  JOINED/ACTIVE need a participant and `joined_at`, finished statuses ⇔ `ended_at`; partial unique indexes on
  `session_id` and on `device_id` over PENDING/JOINED/ACTIVE.
* `remote_participants` — role `CONTROL_COMPANION`; `companion_of_participant_id`;
  `CHECK ((participant_role = 'CONTROL_COMPANION') = (companion_of_participant_id IS NOT NULL))`; a companion
  carries a device, is a `DESKTOP_AGENT` and is never a host.
* `remote_sessions` — `actual_surface_width`, `actual_surface_height` (NULL or 1..16384).
* The lifecycle manifest purges the links with the company, before sessions, participants and devices.

## The desktop app as host: your computers, history, and "give your code"

Remote is personal-only: every account is a personal account, and these routes are written for that case. A company
device (the company code is dormant) gets the same rules in its own company. Every device route below is
`device-auth:device.self`, answers from the device row its credential names, and takes no person, company or other
device as input. Server side: `DeviceService::computersFor()`, `DeviceHistory`, `DeviceSessionService::startAttended()`
and the hosted-session methods after it, `DeviceAgentController`, `DeviceController::history()`.

### Unattended access to your own computers

Personal scope permits it (`CompanyPolicyDefaults::personal()`), Basic entitles it (the built-in entitlement and the
platform-wide row — migration `2026-10-31-000100_EntitleUnattendedAccessByDefault`, `RemotePlatformDefaultsSeeder`), and
a personal account holds `remote.unattended.access`. That reaches nothing by itself: each computer stays unreachable
until somebody at it turns its own switch on with `confirm: true` (`POST /devices/{uuid}/unattended/enable`), and only
its owner can then connect (`POST /devices/{uuid}/connect`; anybody else gets 404). Reboot stays off for personal
computers. Unattended sessions keep granting control with the session, as before. Company scope is unchanged: every
preset still leaves `allow_unattended_access` off and an organisation grants the permission deliberately.

### Endpoints

| Method and path | Filter, rate limit | What it does |
|---|---|---|
| `GET /v1/remote/devices/me/computers` | `device-auth:device.self` | `{computers: Computer[], count}` — the owner's computers in this computer's scope (personal: `user_id` = owner, `company_id IS NULL`), REVOKED left out, this computer first, then by name. |
| `GET /v1/remote/devices/me/history[?limit=]` | `device-auth:device.self` | `{sessions: HistorySession[], events: HistoryEvent[]}` for this computer. |
| `GET /v1/remote/devices/{uuid}/history[?limit=]` | `api-auth` | The same, for a person who may manage the device — its owner for a personal one. 404 for anybody else. |
| `POST /v1/remote/devices/me/sessions` `{issueSummary?}` | `device-auth:device.self`, `device-session-start,10,60` | `201 {session}` — an ATTENDED personal session owned and initiated by the device's owner, requested share `ENTIRE_MONITOR`, `device_id` = this device, created through `SessionService::create()` (quota, policy snapshot, code, `SESSION_CREATED`), plus `DEVICE_SESSION_STARTED` (actor `DEVICE`). The agent then joins it as host with `POST devices/me/sessions/{uuid}/join`. |
| `GET /v1/remote/devices/me/sessions/{uuid}/participants` | `device-auth:device.self`, `device-session-participants,120,60` | `{waiting: Participant[], joined: Participant[]}` — people only: `waiting` is REQUESTED (longest first), `joined` is APPROVED or JOINED. |
| `POST /v1/remote/devices/me/sessions/{uuid}/participants/{participantUuid}/admit` | `device-auth:device.self`, `device-session-admit,30,60` | `{participant}` — `ParticipantService::approve()` as the owner, exactly as the browser host's Admit (`PARTICIPANT_APPROVED`, actor the owner). Idempotent on APPROVED/JOINED; `409 PARTICIPANT_NOT_WAITING` otherwise. |
| `POST /v1/remote/devices/me/sessions/{uuid}/participants/{participantUuid}/decline` | as admit | `{participant}` — `ParticipantService::deny()` as the owner (`PARTICIPANT_DENIED`). |
| `POST /v1/remote/devices/me/sessions/{uuid}/end` | `device-auth:device.self`, `device-session-end,30,60` | `{session}` — `SessionService::end()` as the owner, reason `ENDED_BY_USER`. Idempotent. |

`?limit=` defaults to 20 and is clamped to 1..50 (not a number → 20); it applies to each list separately.

"A session this device hosts" is one whose `device_id` names this device, in its scope — a session started here, or an
unattended one. Anything else is `404 NOT_FOUND`: another of the owner's computers, a stranger's computer, a browser
session the owner started even with this computer joined as its screen, a malformed uuid. A participant that is not a
person in that session (the computer's own participant, another machine, somebody in another session) is `404` too.
Admit and decline need an ATTENDED session: in an unattended one they are `403 SESSION_NOT_ATTENDED` (nobody is at the
machine; whoever connected decides from their browser). Listing participants and ending work for both.

What changes in a session started here, and why:

* The owner's browser seat that `create()` makes (the joined, sharing host) becomes a VIEWER, `is_host false`, LEFT, with
  no `joined_at` — the owner is at the machine, and the machine is the host once it joins. A helper's browser therefore
  sees the same shape as an unattended session: the device participant is the sharing host.
* `access_mode` stays ATTENDED, so `pendingFor()` / `GET devices/me` `pendingSessions` never list it (unattended only).
* Control stays attended: the helper requests it (`POST sessions/{uuid}/control/request`, which finds the device
  participant as `controllableHost`), and the computer decides through `devices/me/sessions/{uuid}/control`.

### Shapes

```jsonc
// Computer
{"uuid": "…", "deviceName": "Meera laptop", "operatingSystem": "macOS", "osVersion": "14.5", "status": "ACTIVE",
 "online": true, "lastSeenAt": "2026-10-31T09:36:39+00:00", "enrolledAt": "2026-10-31T09:36:39+00:00",
 "unattendedAccessEnabled": false, "isThisComputer": true}

// HistorySession — newest first; the device took part as the session's device_id or as a participant
{"uuid": "…", "displayId": "AR-10001", "accessMode": "ATTENDED", "role": "HOST", "status": "ENDED",
 "startedAt": "2026-10-31T09:36:39+00:00", "endedAt": "2026-10-31T09:52:10+00:00", "durationSeconds": 931,
 "peers": ["Ravi Kumar"], "controlled": true}

// HistoryEvent — newest first
{"event": "UNATTENDED_ACCESS_ENABLED", "at": "2026-10-31T09:36:39+00:00",
 "summary": "Unattended access switched on.", "actorName": "Meera Nair"}

// Participant (participants, admit, decline)
{"uuid": "…", "displayName": "Ravi Kumar", "role": "VIEWER", "status": "REQUESTED",
 "requestedAt": "2026-10-31T09:36:39+00:00", "joinedAt": null}
```

* `role` is `COMPANION` when the device's participant was a control companion, `HOST` otherwise.
* `startedAt` is `started_at`, or `created_at` for a session nobody ever joined (then `durationSeconds` is null).
  A session past its expiry is expired on read, so it shows `EXPIRED`.
* `peers` are the names of the other participants who were ever admitted (joined, approved, or in now; never the
  declined, never somebody who only waited, never the owner's unused seat), in arrival order. Never an email: a name
  that is an address shows as `Guest` (guest) or `AICOUNTLY user`.
* `controlled` is true when the session timeline has a `CONTROL_GRANTED`.
* `events` are these audit rows about the device, and no others: `DEVICE_ENROLLED`, `DEVICE_UPDATED` (rename,
  suspend, resume, registering again with the same key), `DEVICE_REVOKED` ("Signed out from the desktop app." when the
  machine did it), `UNATTENDED_ACCESS_ENABLED`, `UNATTENDED_ACCESS_DISABLED`, `DEVICE_REBOOT_REQUESTED`.
  `DEVICE_AUTHENTICATED` (every credential renewal) and `DEVICE_AUTH_FAILED` are left out, and session rows are the
  session list's. `actorName` is the person's current name, the computer's name when it acted itself, `AICOUNTLY
  Remote` for the system, or null — never an email.

## Only verifiable with real machines

Monitor matching against a real capture (points vs pixels, HiDPI scale, multiple monitors of the same aspect),
the local-input pause on real hardware, and Chrome/Edge reporting `displaySurface` and the track size for an
entire-screen share.
