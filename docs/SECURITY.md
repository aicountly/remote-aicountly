# Security model

Remote lets one person watch another person's screen, and — on a Windows
machine running the desktop agent — use it. That is the whole product, and it
is why everything below is a requirement rather than a nice-to-have.

This document covers the product as a whole. The desktop agent's own model,
including the five checks a keystroke has to pass and the things Windows will
not let it do, is [desktop/SECURITY.md](desktop/SECURITY.md).

## What Remote guarantees

* **Nothing is transmitted before the host approves.** A participant who is not
  `APPROVED` cannot obtain a signalling token, so they never enter the room and
  never receive an SDP offer. Approval is enforced in the API, not in the
  browser.
* **The user chooses what is shared, in their own browser's picker.** Remote
  cannot select a surface, cannot pre-authorise one, and cannot remember a
  choice. Capture never starts without a user gesture.
* **A disallowed surface never reaches a peer.** The client stops every track
  the moment it sees one; the server refuses the `share-started` call
  independently. A client that skips its own check still cannot get the session
  marked as sharing.
* **Company policy cannot be overridden downstream.** The capability mask is
  applied after every role and user grant, so no per-user `ALLOW` survives a
  company prohibition.
* **Tenant isolation.** A company's policy, sessions, permissions and audit are
  reachable only by a member of that company with the matching permission,
  resolved per company on every request.
* **Screen content is never stored.** Not a frame, not a screenshot, not a
  thumbnail. The stream is peer-to-peer and ephemeral.
* **No input reaches a machine without a grant, and stopping needs nothing.**
  Remote control is refused unless the host's negotiated capabilities, the
  plan, the company policy, both people's permissions and the person at the
  machine all agree. The agent's gate is **local**: pressing Stop control drops
  the next message with no network round trip, no permission check and no
  cooperation from the other end.
* **Unattended access is never a remembered approval.** Its own entitlement,
  policy switch, permission and per-device setting, off by default, audited on
  every connection, and switchable off from the machine itself.
* **A portal session alone does not reach a computer with nobody at it.**
  Turning unattended access on needs Remote's own two-step verification passed
  in the last ten minutes, and connecting needs it passed in that browser
  within its lifetime — see [Two-step verification](#two-step-verification).
* **A machine cannot grant itself anything.** A device declares its
  capabilities at enrolment and the server intersects that declaration with the
  plan and the policy on every read. The declaration is an upper bound.
* **No file arrives without being accepted.** A transfer is registered with the
  API before it is announced, and the sender starts only once the recipient's
  `accept` call has succeeded. The receiving browser has allocated nothing to
  put unsolicited chunks in, and drops them. File contents never reach a
  server — there is no upload endpoint to reach.

## What Remote does not claim

* It is **not** end-to-end encrypted in the sense of being unreadable by a
  relay operator. WebRTC media is encrypted in transit (DTLS-SRTP), and a TURN
  relay forwards without decrypting, but AICOUNTLY operates the signalling
  service and the TURN server.
* **A browser cannot be controlled.** The browser client shares and views;
  annotations are overlays and change nothing on the sharer's machine. Control
  requires the AICOUNTLY Remote application on the computer being controlled
  — on Windows or a Mac, as the *control companion* of the screen its owner
  shares from the browser (desktop/CONTROL_COMPANION.md) — and the interface
  says so rather than offering a button that would be refused.
* **A UAC prompt can be answered remotely only with explicit consent, and
  without weakening UAC.** The Windows Secure Desktop is a boundary the app
  cannot cross; the per-machine service crosses it the supported way, as a
  SYSTEM helper process on `winsta0\winlogon`, and only while a session is
  controlled and either the person at the computer ticked the consent box or
  the owner enabled it for unattended connections. No UAC or policy setting is
  changed, the pipe to the helper admits only the installed app in the console
  session, and when it is not authorised the agent merely says a prompt is open.
  See [desktop/WINDOWS_AGENT.md](desktop/WINDOWS_AGENT.md).
* It **does not protect a machine an attacker already runs code on**. The
  device key protects the machine's *identity*; somebody who can read the key
  file can already read the screen.
* It **cannot verify the sharing surface in every browser**. Firefox and Safari
  do not report `displaySurface`. Remote proceeds under the mode already
  authorised and records `verified: false` rather than implying a check it
  could not make.
* It **does not scan transferred files**. Nothing is uploaded, so there is
  nothing on a server to scan. A received file is held as opaque bytes until the
  recipient presses Save, and the record shows who sent it — the controls are
  consent and the audit trail, not inspection.
* It **cannot see or require the portal's own second factor.** Signing in is
  the AICOUNTLY portal's (my.aicountly.com), another product: whether it asked
  for a second factor is invisible to Remote. Remote's own two-step
  verification is a step *after* that sign-in, for Remote only — it does not
  protect the portal or any other AICOUNTLY product.
* There is no "military-grade" anything here, and the interface says nothing of
  the kind.

---

## Authentication

AICOUNTLY's portal owns identity. The one credential Remote issues to a person
is its own two-step verification token — proof of a second step, never a
sign-in on its own ([below](#two-step-verification)).

| Credential | Lifetime | Stored | Purpose |
|---|---|---|---|
| `auth_token` | long | `localStorage` (this origin only) | mint a `ses_key` |
| `ses_key` | ~15 min | **memory only** | `Authorization: Bearer` on the API |
| Two-step verification token | 12 hours | `localStorage` (this origin only) | `X-Remote-Two-Step`: this browser passed Remote's second step; worthless without a `ses_key` of the same person |
| Guest token | until the session ends | `sessionStorage` | one participant, one session |
| Signalling token | 2 minutes | memory only | one room |
| Device credential | minutes | **memory only, on the machine** | one enrolled device, scoped |

The shared `.aicountly.com` `auth_token` cookie is retired: it was readable by
any script on any `*.aicountly.com` page. Cross-product sign-in is the portal
hand-off (`my.aicountly.com/login/authentication_jump`, backed by the portal's
httpOnly `AIC_AUTH_TOKEN` cookie); a leftover cookie is purged at start-up and
sign-out on `*.aicountly.com`.

A **device** is not a person and never holds a person's credential. It proves
possession of an Ed25519 private key that never leaves the machine, over a
canonical domain-separated payload with a single-use nonce, and receives a
short-lived scoped credential in return. There is no permanent bearer token
that is a machine's identity, and revocation takes effect on the device's next
request rather than at its next renewal. See
[desktop/DEVICE_ENROLMENT.md](desktop/DEVICE_ENROLMENT.md).

`ses_key` never reaches `localStorage` or `sessionStorage` — it lives in a
module variable and dies with the page.

Every API call validates the `ses_key` against
`my.aicountly.com/api/validatesession`, server to server, cached for 60 seconds
against a SHA-256 of the key. **A portal outage denies access rather than
granting it**; there is no fallback that lets a caller through.

### Guest tokens

An external guest has no AICOUNTLY account, so redeeming a one-time invitation
mints an HMAC token bound to **one participant in one session**, expiring with
that session. It is not reusable, opens no other product, and every endpoint
that accepts it re-checks that the participant belongs to the session being
acted on. The key is derived from the signalling secret with a distinct label,
so a guest token can never be presented as a signalling token or the reverse.

### Two-step verification

Remote's own second step: a six-digit code from an authenticator app (TOTP,
RFC 6238 — HMAC-SHA1, 30-second steps, one step either side), or one of ten
one-time recovery codes. A person turns it on in **Settings → Two-step
verification**: scan the QR code or type the key, confirm with a code, save the
recovery codes. It exists because Remote cannot see into the portal sign-in,
and since Remote can reach a person's own computers with nobody at them, a
stolen portal session would otherwise be enough to sit down at those
computers.

**What it protects.**

* For a person who has it on, **every signed-in API call** is refused with
  `403 TWO_STEP_REQUIRED` until the browser making it has passed the step. The
  check is in `ApiAuthFilter`, straight after the portal says who the caller
  is, so a route added later is covered without anybody remembering to. The
  exemptions are listed in `Routes.php` (`api-auth:allow-unverified`): the
  two-step routes themselves; bootstrap, which then carries
  `twoStep: {enabled, sessionVerified}` and nothing of the person's data, so
  the web knows to ask; the legacy `GET /api/session` sign-in check; and
  switching unattended access **off**. Sign-out is the portal relay and never
  reaches the filter.
* **Unattended access needs it, whoever calls.** `DeviceService::enableUnattended`
  and `DeviceSessionService::startUnattended` refuse a person without it with
  `TWO_STEP_SETUP_REQUIRED` ("turn it on in Settings…"), and refuse one whose
  verification is not recent enough with `TWO_STEP_REQUIRED`. This is a
  **step-up**: turning unattended access on needs a code from the last ten
  minutes (`remote.twoStepStepUpSeconds`); connecting needs a verification
  within its lifetime. The checks come after "is this computer yours", so a
  stranger is still told it does not exist. Switching unattended access off
  never needs it, from the web or from the computer itself. New recovery codes
  need the same ten-minute step-up.
* **Guests and devices are unaffected.** A guest has no account; a device is
  the machine itself, proven by its own key.

**What a verified browser is.** Passing the step mints a random 256-bit token,
returned once. The web keeps it in `localStorage` and sends it as
`X-Remote-Two-Step`; the server keeps only its SHA-256
(`remote_two_step_verifications`) with the person's id, when it was verified
and when it expires (12 hours, `remote.twoStepSessionLifetime`). It is **not**
tied to the `ses_key`, deliberately: that key lives about fifteen minutes and
only in memory, so it changes on every reload and every re-mint, and a
verification hung on it would ask for a code all day. It is tied to the
person: with anybody else's session it verifies nothing, and without a valid
portal session it is never looked at. Signing out removes it from the browser.
Kept beside the `auth_token`, it shares that token's exposure — script running
on Remote's own origin could take both — and no other AICOUNTLY product's
origin can read it.

**What it refuses.** Five wrong codes in a row (`remote.twoStepMaxFailures`)
lock verification for fifteen minutes (`remote.twoStepLockoutSeconds`) —
authenticator and recovery codes alike, per person, wherever the guesses come
from, on top of the per-address rate limits. A code is accepted once: the time
step of the last accepted code is stored (`last_used_step`) and only a newer
one passes, by a guarded `UPDATE`, so two requests racing with one code cannot
both get in, and an older code from the window is refused too. A recovery code
is spent by the same kind of guarded update. Turning it off needs a code.

**How the secret is kept.** Sealed with XChaCha20-Poly1305 (libsodium, which
device signatures already need) under a key derived by HKDF-SHA256, label
`remote-two-step-v1`, from `remote.signallingSecret` — as the guest-token key
already is, with a label of its own — unless an operator has set
`remote.twoStepKey`. The signalling secret is the one secret every working
deployment is guaranteed to hold, and its only other holder is the signalling
service on the same host and account. The person's id is the associated data,
so a sealed secret opens only on its own row: a database writer cannot copy
their own secret onto somebody else's. The secrets considered and not used:
`remote.contextSecret` and `LIFECYCLE_WEBHOOK_SECRET` are shared with other
products on other hosts; `encryption.key` is not set in production; a database
password is not something to hang the database's own encryption on. With no
usable secret (empty, or under 32 characters), turning it on answers
`503 TWO_STEP_UNAVAILABLE` — a secret is never stored unsealed instead.
Recovery codes are `password_hash()` values and do not depend on the key, so a
lost key still leaves everybody a way in.

**Rotating the key.** Each sealed secret records the id of the key that sealed
it (a fingerprint, not the key). Before rotating `remote.signallingSecret`, set
`remote.twoStepKey` (secrets sealed under the signalling secret stay readable
automatically), or list the old value in `remote.twoStepPreviousKeys`. Secrets
re-seal under the current key the next time they are used, or all at once with
`php spark remote:two-step rekey`; `php spark remote:two-step status` says
whether anything is sealed under a key the server no longer holds. Such a
secret is answered `503 TWO_STEP_KEY_UNAVAILABLE` ("use a recovery code") and
never counted as a wrong guess.

**Lost phone and lost codes.** AICOUNTLY support can switch it off for a
person with `php spark remote:two-step reset <uuid or e-mail> --reason "…"`,
recorded in the audit trail as the system, with the reason. It is the one way
round the second step, so confirm who is asking first.

**What it does not protect.**

* The portal sign-in itself, and every other AICOUNTLY product. The portal's
  own second factor, if it has one, belongs to the portal team (audit P1-9).
* A person who has **not** turned it on. Somebody holding their portal session
  can use Remote as them; to reach their computers with nobody at them, that
  somebody would first have to turn two-step verification on with an
  authenticator of their own. That is recorded in the audit trail, locks the
  real person out of Remote (so they notice and can ask support to reset it),
  and unattended access, once on, shows on the computer itself — but it is a
  speed bump, not a wall. Remote cannot require everybody to enrol until
  something other than the portal session can vouch for who is enrolling.

Every step is audited (`TWO_STEP_*` events): enrolment started, turned on,
each verification and each wrong code, a lockout, a recovery code spent, new
codes, turned off, reset by support. No code, secret or token reaches the
trail.

**The routes.** All `api-auth:allow-unverified` (they are how a browser passes),
all acting on the caller's own account — nothing in a path or a body names a
person. Responses use the usual `{data: …}` envelope.

| Method and path | Rate limit | What it does |
|---|---|---|
| `GET /v1/remote/me/two-step` | — | `{enabled, pending, available, confirmedAt, recoveryCodesLeft, sessionVerified, verifiedAt, verifiedUntil, lockedUntil}` |
| `POST /v1/remote/me/two-step/enrol` | `two-step-enrol,10,60` | `201 {secret, otpauthUri}` — base32 secret (160 bits) and `otpauth://totp/AICOUNTLY%20Remote:<e-mail>?secret=…&issuer=AICOUNTLY%20Remote&algorithm=SHA1&digits=6&period=30`; pending until confirmed; again replaces a pending one; `409 TWO_STEP_ALREADY_ENABLED` once on; `503 TWO_STEP_UNAVAILABLE` with no key |
| `POST /v1/remote/me/two-step/confirm` `{code}` | `two-step-verify,10,60` | `{recoveryCodes: [10 × "ABCDE-23456"], verificationToken, verifiedAt, verifiedUntil}` — the codes are shown once; this browser is verified |
| `POST /v1/remote/me/two-step/verify` `{code}` or `{recoveryCode}` | `two-step-verify,10,60` | `{verificationToken, verifiedAt, verifiedUntil, method: "TOTP" or "RECOVERY_CODE", recoveryCodesLeft}`; refusals `400 TWO_STEP_CODE_INVALID {attemptsLeft}`, `409 TWO_STEP_CODE_USED`, `429 TWO_STEP_LOCKED {lockedUntil, retryAfterSeconds}`, `409 TWO_STEP_NOT_ENABLED` |
| `POST /v1/remote/me/two-step/recovery-codes` | `two-step-recovery-codes,5,60` | `{recoveryCodes}`; the old ones stop working; `403 TWO_STEP_REQUIRED {reason: "STEP_UP"}` unless verified in the last ten minutes |
| `POST /v1/remote/me/two-step/disable` `{code}` or `{recoveryCode}` | `two-step-verify,10,60` | the status, now `enabled: false`; every recovery code and verified browser goes with it |

The refusals other routes give: `403 TWO_STEP_REQUIRED {reason: "SESSION"}`
(this browser has not passed it), `403 TWO_STEP_REQUIRED {reason: "STEP_UP",
purpose, withinSeconds}` (a fresher code is needed), and
`403 TWO_STEP_SETUP_REQUIRED {purpose, setupPath: "/settings#two-step"}`
(unattended access, for a person who has not turned it on). The web answers
the first two with its one verification prompt and sends the refused request
again once the code is accepted.

---

## Rate limits

| Endpoint | Budget |
|---|---|
| `POST /join/code` | 10 / minute |
| `POST /join/redeem` | 10 / minute |
| `POST /support/requests` | 10 / minute |
| `POST /sessions` | 20 / minute |
| `POST .../invitations` | 20 / minute |
| `POST .../join-request` | 20 / minute |
| `POST .../signalling-token` | 60 / minute |
| `POST .../messages` | 120 / minute |
| `POST /global/*` (portal relay) | 30 / minute |
| `POST /me/two-step/confirm`, `/verify`, `/disable` | 10 / minute, shared — and five wrong codes lock the person for 15 minutes |
| `POST /me/two-step/enrol` | 10 / minute |
| `POST /me/two-step/recovery-codes` | 5 / minute |

Join codes get the tightest budget: nine digits is a small space, and the code
is a convenience for people who already have an AICOUNTLY account — never a
credential that admits a stranger. External guests use invitation links, which
carry 256 bits of entropy, expire in minutes and work once.

Buckets are keyed on a hash of the client IP and the bucket name, so exhausting
the join-code budget does not also lock the caller out of signing in. The
limiter is backed by the framework cache — on cPanel that is the file cache,
which blunts a single-source attempt and is honest about not being distributed.

---

## Secrets

Never in the repository, never in a `VITE_` variable, never in an audit entry.

| Secret | Lives in | Shared with |
|---|---|---|
| `remote.signallingSecret` | `backend/.env` | the signalling service, byte-identical; the guest-token and two-step keys are derived from it, each with its own label |
| `remote.twoStepKey` (optional) | `backend/.env` | — (replaces the signalling secret as the two-step key's source) |
| `remote.contextSecret` | `backend/.env` | products that launch Remote |
| `remote.turnStaticAuthSecret` | `backend/.env` | coturn |
| Database password | `backend/.env` | — |

`VITE_*` values are inlined into the JavaScript bundle and are public by
construction. The signalling URL and the ICE servers are deliberately *not*
build-time configuration: the browser receives them from the API, per session,
so a TURN credential never ends up in a bundle.

`GET /health` reports *that* the deployment is configured, never what with.

---

## Transport and headers

HTTPS is mandatory: `app.forceGlobalSecureRequests` is on by default, and
`getDisplayMedia` requires a secure context in every browser regardless.

Every API response carries:

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: no-referrer
Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'
Permissions-Policy: display-capture=(), microphone=(), camera=()
Cache-Control: no-store, private
```

The API returns only JSON, which is what makes `default-src 'none'`
appropriate. The SPA's own CSP ships with the web build in
`web/public/.htaccess` and must permit the app's scripts and its WebSocket —
and the AICOUNTLY Connect widget's origins (`connect.aicountly.com`,
`connect.gh.aicountly.com`) in `script-src`, `style-src`, `img-src` and
`connect-src`, nothing wider. The widget authenticates with Remote's ses_key as
a Bearer to Connect only, renders in its own shadow root, and is destroyed for
the duration of a live session (see ARCHITECTURE.md § AICOUNTLY Connect).

CORS is an exact-match allowlist and is empty in both deployed environments,
where the app and API share an origin. `Access-Control-Allow-Origin: *` is
never emitted, and the origin is compared with `in_array(..., true)` — a
`startsWith` check is how `https://remote.aicountly.com.attacker.example` gets
let in.

There is no CSRF token because there is no ambient credential: Remote
authenticates with a Bearer token the browser never attaches automatically.

---

## Data protection

**Stored:** session metadata, participants, durations, policy decisions, audit
events, chat messages, and a file transfer's name, size, recipient and outcome.

**Never stored:** screen content, **file contents**, passwords, `ses_key`s,
guest tokens, signalling tokens, TURN credentials.

`AuditService::scrub()` walks metadata before it is written and drops any key
containing `password`, `token`, `secret`, `credential`, `body`, `message`,
`frame`, `screenshot` and similar, at any depth, capping string values at 500
characters. The caller that forgets is the one that matters.

Audit and chat are separate systems. The audit trail records `CHAT_STARTED` and
nothing about what was said, so enabling advanced audit never enables
transcript retention.

IP addresses and user agents are recorded, and are visible **only** to a caller
holding `remote.audit.view`. They never appear in an ordinary session list.

An invitation's secret exists in exactly one HTTP response. Only its SHA-256 is
stored, so a database dump cannot be replayed into a live session.

### Analytics

Google Analytics 4 runs only in a build with a measurement id (production), and
follows the one choice every AICOUNTLY app shares: the `aic_consent` cookie
(`analytics-granted` or `analytics-denied`, `Domain=.aicountly.com`, 180 days),
asked by a small banner at the bottom left with a way back from **Analytics
preferences** in the navigation (`web/src/utils/consent.ts`,
`web/src/components/AnalyticsConsent.tsx`). Consent Mode v2 defaults are set
before any config call and ads consent is never granted. Before a choice the tag
runs cookieless (`ANALYTICS_BEFORE_CHOICE` in `web/src/utils/analytics.ts`;
`'none'` loads nothing until the person allows it); after "No thanks" it is not
loaded at all and `ga-disable-<ID>` is set. Whatever the choice, GA receives the
origin and a masked path only — no query, no fragment, every identifier-like
segment (digits, UUIDs, long tokens, anything with an `@`) replaced with `:id`,
the invitation token with `:token` — and the product name as the page title;
never a user id, a name, an e-mail address or free text. Nothing is loaded while
the address bar holds the sign-in hand-off, an invitation link or another
credential, and no banner is shown there.

---

## Injection and identifiers

Every query goes through CodeIgniter's query builder or a parameterised
`query()`. The few raw fragments are constants with interpolated integers, not
user input.

No serial primary key leaves the server. A session is identified publicly by a
UUID; `AR-10282` is a label for people to read aloud and grants nothing.

A session the caller may not see returns **404, not 403**, so ids cannot be
probed for existence.

---

## Concurrency

Every operation where two people can race is decided by the database, not by a
check-then-act in PHP:

| Race | Guard |
|---|---|
| Two technicians accept one support request | `UPDATE … WHERE status = 'PENDING'` |
| One invitation link opened twice | `UPDATE … WHERE used_count < max_uses` |
| Two hosts approve the same participant | `UPDATE … WHERE status = 'REQUESTED'` |
| One challenge nonce verified twice | `UPDATE … WHERE consumed_at IS NULL`, affected rows checked |
| Two viewers granted control at once | `UPDATE … WHERE control_state = 'REQUESTED'`, plus a controller check |
| Two launches with one context token | `UNIQUE (jti)` |
| Two simultaneous session transitions | `UPDATE … WHERE status = <the one validated>` |
| One two-step code sent twice at once | `UPDATE … WHERE last_used_step < <its step>`, affected rows checked |
| One recovery code used twice at once | `UPDATE … WHERE used_at IS NULL`, affected rows checked |
| A confirmed two-step setting replaced by a racing enrolment | `INSERT … ON CONFLICT DO UPDATE … WHERE confirmed_at IS NULL` |

---

## Reviewing a deployment

```bash
# The API is up, and says what is configured — never what with
curl -s https://remote.aicountly.com/api/health | jq

# The relay is an allowlist
curl -i -X POST https://remote.aicountly.com/api/global/login     # expect 404

# So is the Manage registry relay (only the shared company-access client's calls)
curl -i https://remote.aicountly.com/api/manage/v1/audit-log      # expect 404

# The .env is not fetchable
curl -i https://remote.aicountly.com/api/.env                     # expect 404

# Framework directories are not served
curl -i https://remote.aicountly.com/api/app/Config/Remote.php    # expect 403/404
curl -i https://remote.aicountly.com/api/writable/logs/           # expect 403/404

# The API refuses an unauthenticated call
curl -i https://remote.aicountly.com/api/v1/remote/bootstrap      # expect 401
```

## Reporting

Security issues in AICOUNTLY Remote go to AICOUNTLY's security contact, not to
a public issue tracker.
