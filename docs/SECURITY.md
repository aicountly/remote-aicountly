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
  requires AICOUNTLY Remote for Windows at the other end, and the interface
  says so rather than offering a button that would be refused.
* **A UAC prompt cannot be answered remotely**, and no setting is changed to
  make it possible. The Windows Secure Desktop is a boundary the agent detects
  and does not cross — see
  [desktop/WINDOWS_AGENT.md](desktop/WINDOWS_AGENT.md).
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
* There is no "military-grade" anything here, and the interface says nothing of
  the kind.

---

## Authentication

Remote issues no credential of its own. AICOUNTLY's portal owns identity.

| Credential | Lifetime | Stored | Purpose |
|---|---|---|---|
| `auth_token` | long | `localStorage` + a `.aicountly.com` cookie | mint a `ses_key` |
| `ses_key` | ~15 min | **memory only** | `Authorization: Bearer` on the API |
| Guest token | until the session ends | `sessionStorage` | one participant, one session |
| Signalling token | 2 minutes | memory only | one room |
| Device credential | minutes | **memory only, on the machine** | one enrolled device, scoped |

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
| `remote.signallingSecret` | `backend/.env` | the signalling service, byte-identical |
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
`web/public/.htaccess` and must permit the app's scripts and its WebSocket.

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

---

## Reviewing a deployment

```bash
# The API is up, and says what is configured — never what with
curl -s https://remote.aicountly.com/api/health | jq

# The relay is an allowlist
curl -i -X POST https://remote.aicountly.com/api/global/login     # expect 404

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
