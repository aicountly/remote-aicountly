# AICOUNTLY Remote — architecture

Remote is AICOUNTLY's secure browser assistance product. Somebody shares a
screen; somebody else, with permission, watches it and helps.

**What V1 is:** attended, browser-only assistance — share, view, chat, point,
annotate.

**What V1 is not:** operating-system remote control or unattended access.
A browser cannot do either, and nothing in the interface implies otherwise.
The desktop agents that will do those things are designed for
([DESKTOP_AGENT.md](DESKTOP_AGENT.md)) but not built.

---

## The four pieces

```
                    ┌──────────────────────────┐
                    │   my.aicountly.com       │
                    │   AICOUNTLY SSO portal   │
                    └───────────▲──────────────┘
                                │ validatesession (server → server)
                                │
  ┌──────────────┐   HTTPS   ┌──┴────────────────┐        ┌──────────────┐
  │  web/        │──────────▶│  backend/         │───────▶│ PostgreSQL   │
  │  React SPA   │   /api    │  CodeIgniter 4    │        │              │
  └──────┬───────┘           └───────────────────┘        └──────────────┘
         │                             │
         │ wss                         │ mints a short-lived,
         │                             │ HMAC-signed token
  ┌──────▼─────────────┐               │
  │  signalling/       │◀──────────────┘
  │  Node WebSocket    │   (verifies only — no database, no policy)
  └──────┬─────────────┘
         │ SDP + ICE relay
  ┌──────▼─────────────┐        media, peer to peer
  │  the other browser │◀═══════════════════════════▶
  └────────────────────┘        (or via TURN)
```

| Piece | Responsibility | Deployed to |
|---|---|---|
| `web/` | The interface. Capture, WebRTC, everything a person sees. | cPanel document root |
| `backend/` | Identity, policy, sessions, audit, token issuance. | `<docroot>/api/` |
| `signalling/` | Relays SDP and ICE between two authorised browsers. | A Node process |
| PostgreSQL | Every durable fact about a session. Never screen content. | The database host |

**Media never touches AICOUNTLY infrastructure.** Video and audio go directly
between the two browsers, or through TURN when a network forces it. The
signalling service carries only the handshake.

---

## Session lifecycle

```
CREATED ──▶ WAITING ──▶ JOIN_REQUESTED ──▶ CONNECTING ──▶ ACTIVE ──▶ ENDED
                │             │                 │            │
                │             ▼                 ▼            ▼
                │         DECLINED          RECONNECTING  PAUSED
                └────────────────────────────▶ EXPIRED / FAILED
```

Every transition goes through `App\Domain\Session\SessionStatus`, which owns the
table of permitted moves. There are no boolean flags standing in for state
anywhere in the product: a session is exactly one of these values.

A session past `expires_at` is expired the moment anyone reads it, rather than
by a scheduled job — cPanel gives no reliable scheduler, and an abandoned
session must not stay joinable.

### The share flow, in order

The order is the security property, not an implementation detail:

1. **Consent.** A dialog states what will be visible, which organisation the
   session belongs to, and who can see it. Capture never starts automatically.
2. **`POST /share-intent`.** The **server** authorises the sharing mode. The
   browser picker is not opened for something the organisation would refuse.
3. **`getDisplayMedia()`.** The browser's own picker, from a user gesture.
4. **Client-side surface check.** If the browser reports a surface policy
   forbids, every track is stopped before the stream reaches a peer connection.
5. **`POST /share-started`.** The server checks the surface **again**. This is
   the half that counts — a client that skipped step 4 still cannot get the
   session marked as sharing.

Firefox and Safari do not implement `displaySurface`. Refusing them outright
would make Remote unusable there, so the session proceeds under the mode
already authorised in step 2 and the gap is recorded honestly: the audit event
carries `verified: false`, and the session's Security panel says the surface
could not be verified rather than implying it was.

---

## Policy resolution

```
AICOUNTLY global flags     Config\Remote — a flag can only ever REMOVE capability
        ↓
Product entitlement        remote_entitlements — the plan's ceiling
        ↓
Company policy             remote_company_policies — the organisation's rules
        ↓
Role permission            remote_role_permissions
        ↓
User permission            remote_user_permissions
        ↓
Session policy             snapshotted onto the session at creation
        ↓
Browser consent            the operating system's picker — never ours
```

**The most restrictive applicable rule wins.** The mechanism is the ordering
inside `EffectivePolicyResolver::resolve()`: role and user grants are applied
first, and the capability mask is applied **last**. That is what makes a
user-level `ALLOW` unable to survive a company prohibition. Reversing those two
steps would silently turn every user grant into an override — which is why the
class carries a comment saying so, and why `EffectivePolicyResolverTest` asserts
it first.

Two further properties fall out of the same design:

* **Tenant isolation.** `resolve()` refuses a company the user has no
  membership in, before reading a single capability. Holding a permission at
  one company grants nothing at another, and `CompanyIsolationTest` asserts
  exactly the scenario from the specification: one person, two companies,
  opposite policies.
* **Policy snapshotting.** A session records the policy in force when it was
  created. An administrator turning chat off stops the *next* session, not one
  two people are already talking in.

---

## Company context

A session's company never comes from a query parameter. `?company_id=481` is a
request, not a fact.

The only way a session acquires a company is a **signed launch context** from a
cooperating AICOUNTLY product — a compact HS256 JWS on the `X-Remote-Context`
header, checked for signature, issuer, audience, expiry, a hard maximum age, a
source-product allowlist, and a one-time `jti` enforced by a unique index (so
two simultaneous redemptions cannot both win).

Once a session has a company it keeps it. If a cooperating tab later reports a
different organisation, Remote stops sharing, pauses the session, records
`COMPANY_CONTEXT_MISMATCH`, and tells the user — rather than exposing another
tenant's screen. See [SAFE_SHARE_INTEGRATION.md](SAFE_SHARE_INTEGRATION.md).

A company-scoped workflow can never be escaped into a personal session: with a
verified context present, `scopeType: PERSONAL` is refused outright rather than
quietly downgraded.

---

## Realtime

**Authorisation happens in the API; the signalling service only verifies.**

`POST /sessions/{uuid}/signalling-token` mints a two-minute HS256 token, and
only for a participant whose status is `APPROVED` or `JOINED`. The room is
inside the signed payload, so a client cannot ask to join a room it was not
admitted to — there is no code path in the signalling service that reads a room
name from a client message.

That is what makes host approval mean something. An unapproved viewer gets no
token, never enters the room, and therefore never receives an SDP offer.

The signalling service holds no database, evaluates no policy and knows nothing
about companies. It relays `offer`, `answer`, `ice-candidate` and a little
presence, inside one room, and drops everything else.

**Negotiation rule:** the peer already in the room offers to the one that just
arrived. There is no tie to break, so both sides never offer at once and no
perfect-negotiation rollback is needed.

### STUN and TURN

STUN is enough for most networks. TURN is what makes the strict ones work —
without it, two peers behind symmetric NAT simply cannot connect.

Credentials are never hardcoded and never inlined into the JavaScript bundle:
the browser receives an ICE server list from the API, per session. Two
arrangements are supported, and the first is preferred:

* **Ephemeral** — coturn's `use-auth-secret`. The username is an expiry
  timestamp and the password is its HMAC, so what the browser receives is valid
  for an hour and useless afterwards. The secret never leaves the server.
* **Static** — a fixed username and password from the server `.env`. Simpler,
  appropriate only for a TURN that is not reachable from the open internet.

A minimal coturn configuration:

```conf
listening-port=3478
tls-listening-port=5349
fingerprint
use-auth-secret
static-auth-secret=<the same value as remote.turnStaticAuthSecret>
realm=aicountly.com
cert=/etc/letsencrypt/live/turn.aicountly.com/fullchain.pem
pkey=/etc/letsencrypt/live/turn.aicountly.com/privkey.pem
no-tcp-relay
no-multicast-peers
# Never let a relay be used to reach the internal network.
denied-peer-ip=10.0.0.0-10.255.255.255
denied-peer-ip=172.16.0.0-172.31.255.255
denied-peer-ip=192.168.0.0-192.168.255.255
```

When no TURN is configured the API says so (`relayAvailable: false`) and the UI
explains an unreachable peer honestly instead of showing "Reconnecting…"
forever.

---

## What is stored, and what is not

**Stored:** session metadata, participants, durations, policy decisions, audit
events, chat messages.

**Never stored:** screen pixels, a frame, a screenshot, a password, a token, a
TURN credential. `AuditService::scrub()` strips anything whose key looks like
one before it reaches the database, because the caller that forgets is the one
that matters.

Chat and audit are deliberately separate. The audit trail records *that* a
conversation happened (`CHAT_STARTED`) and nothing about what was said, so
turning on advanced audit never turns on transcript retention.

---

## Layout

```
web/                    React 19 + TypeScript + Vite
  src/app/              shell, router, application-wide state
  src/components/       shared UI
  src/features/         dashboard, sessions, room, support, admin, settings
  src/services/
    api/                the only place that calls the API
    webrtc/             capture, peer connection, session engine
    signalling/         WebSocket client
    browser/            capability detection
  src/types/            the API contract, as TypeScript

backend/                CodeIgniter 4
  app/Controllers/      input, output, status codes — no decisions
  app/Domain/
    Auth/               portal, identity projection, launch context, guests
    Policy/             the permission hierarchy
    Session/            lifecycle, participants, invitations, joining, chat
    Support/            the AICOUNTLY Support queue, shared helpers
    Signalling/         token issuance, ICE configuration
    Audit/              events and the security record
    Directory/          the platform projection
  app/Database/         migrations and seeds
  app/Filters/          auth, CORS, security headers, rate limits, context
  tests/                unit, integration and HTTP feature tests

signalling/             Node WebSocket relay
docs/                   this directory
```

Controllers validate input, call a service, and format the answer. They do not
decide permissions, open transactions or write tables — that all lives in the
services, so it can be tested without HTTP and cannot be forgotten on one route
out of twenty.

---

## Further reading

| Document | Covers |
|---|---|
| [DATABASE.md](DATABASE.md) | Every table, and why it is shaped that way |
| [SECURITY.md](SECURITY.md) | The security model, and its honest limits |
| [DEVELOPMENT.md](DEVELOPMENT.md) | Running all three pieces, and the two-browser walkthrough |
| [DEPLOYMENT.md](DEPLOYMENT.md) | cPanel deployment and first-run setup |
| [SAFE_SHARE_INTEGRATION.md](SAFE_SHARE_INTEGRATION.md) | Launching Remote from another AICOUNTLY product |
| [DESKTOP_AGENT.md](DESKTOP_AGENT.md) | How the future agents plug into this |
| [BROWSER_SUPPORT.md](BROWSER_SUPPORT.md) | What works where, and what degrades |
| [auth/AICOUNTLY_AUTH_WORKFLOW.md](auth/AICOUNTLY_AUTH_WORKFLOW.md) | Sign-in |
