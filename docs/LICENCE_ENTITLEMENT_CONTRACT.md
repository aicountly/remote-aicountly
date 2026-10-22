# Licence entitlement contract — central licensing → AICOUNTLY Remote and Connect

Identical in `aicountly/remote-aicountly` and `aicountly/connect-aicountly`. Contract id: **`aicountly.licence/1`**.

> **Status: a PROPOSAL for the licensing team, plus the consumer half, which is built.** The central licensing backend is
> under development and **does not exist yet**. Everything on the *consumer* side (the common adapter in `lib/ice-kit`, its tests,
> the conformance vectors, the readiness gate, the staging fixtures) is implemented and tested in these repositories. The
> *server* side of the API (§6) is a specification, not code. Nothing here has run against a real licensing backend.

## 1. The confirmed architecture and the eligibility decision

| Component | Role | Relationship to TURN capacity |
|---|---|---|
| **engage.aicountly.org** | Internal AICOUNTLY staff application: allocates, modifies, renews and revokes licences (and seats) | Writes to the central backend. Not read by products |
| **manage.aicountly.com** | Customer-facing: assigned licences, plans and entitlements are visible | Reads the central backend. Not read by products for TURN |
| **Central licensing backend** | **The single authority** for subscription status, plan classification, validity, membership/seat eligibility and feature entitlements | Issues signed entitlements; products consume them |
| **AICOUNTLY Remote and Connect** | Consumers of centrally verified entitlements | Decide only *which capacity class* a request falls in, from what the backend signed |

**Eligibility is contextual and company-scoped — never global.** A user is *not* paid because some company they belong to has an
active paid licence. The decision is made for **one authenticated user, operating under one active company (or none), for one product**,
and the user is paid only if that company's subscription **permits that user's access**:

* **company-wide** licence → every *current member* of the company qualifies (membership is verified, not assumed);
* **seat** licence → only a member with an *assigned* seat qualifies (unassigned and revoked seats do not);
* a **personal** licence is a separate, user-scoped entitlement that applies in the user's personal context only;
* **switching company recalculates** the decision for the new company from that company's own evidence; paid status is never transferred;
* an **external meeting guest** is served under a paid host's licensed *meeting* (§9), without any account being upgraded.

Consequences, all enforced in code:

* **Remote and Connect are not licensing authorities.** Remote's `remote_entitlements.plan_code` mirrors the central catalogue code (§10);
  Connect's `connect_license_tier` is a **temporary compatibility cache**, honoured only in a personal context and never inside a company.
* **One adapter for both products** (`Aicountly\Ice\Licence`, in the shared kit), not two licensing integrations.
* **Hard paid/free enforcement does not start until the central source is operational and validated.** `WEBRTC_TURN_ADMISSION` stays
  `observe` during development, and a readiness gate forces that even if `enforce` is configured (§11).
* The **Mumbai/NCR active-active routing** and the **50 % reservation of actual bandwidth and concurrent allocations for paid
  customers across Remote and Connect collectively** (shared PostgreSQL ledger) are unchanged; this contract only decides *who is paid*.

## 2. Principles

1. **Trusted, server-side evidence only.** Paid classification comes from a document the central backend signed. The user is the
   *authenticated* one and the product is fixed. The **company is a claim** when it comes from a request (`?company_id=`): it is trusted
   for nothing except being looked up, because the signed document must independently state that this user is a current member of it.
   A false claim finds no eligibility and is simply free.
2. **Every doubt is free.** No document, a bad signature, an unknown key, a document for another customer, user or product, a stale or
   future-dated document, an expired/suspended/unknown state, a revoked revision, a member not shown to be current, a seat not assigned,
   an unreachable backend: all resolve to **free** capacity. A failing licence source can only tighten capacity, never hand out the reserve.
3. **The cache is not trusted; the signature is.** Documents are Ed25519-signed; products hold only the *public* key.
4. **Tenant isolation is checked at every layer** (§7): the transport scopes by product, the sync drops any foreign document, the cache keys
   carry scope, company and user, and the resolver re-checks all of them against the signed document.
5. **Revocation beats freshness**, and a revocation is as narrow as the thing revoked: a company, one member/seat, or one personal licence (§8).

## 3. The entitlement document

An **envelope** carries a signed **document**. Three shapes, one structure:

| Shape | `scope` | `customer_id` | `subject` | Answers |
|---|---|---|---|---|
| **Company-level** | `company` | the company | `null` | the company's licence: state, plan, validity, model (`company_wide` / `seat`) |
| **Member-level** | `company` | the company | the user | *this user's eligibility inside this company*: current membership and, for a seat licence, the seat |
| **Personal** | `user` | `null` | the user | the user's own licence, outside any company |

```json
{
  "document": {
    "contract": "aicountly.licence/1",
    "scope": "company",
    "customer_id": "42",
    "product_id": "chat",
    "subject": "7",
    "licence_id": "LIC-000123",
    "plan_code": "CHAT_TEAM",
    "plan_class": "paid",
    "kind": "paid",
    "state": "paid",
    "valid_from": "2026-10-20T00:00:00Z",
    "valid_until": "2026-11-20T00:00:00Z",
    "eligibility": { "model": "seat", "member": true, "seat": "assigned" },
    "entitlements": [
      {"code": "meeting.max_guests", "value": 5},
      {"code": "meeting.max_participants", "value": 12},
      {"code": "meeting.guests_paid_quality", "value": true}
    ],
    "revision": 5,
    "issued_at": "2026-10-21T00:00:00Z"
  },
  "signature": { "alg": "ed25519", "kid": "central-2026-10", "value": "<base64 of the 64-byte detached signature>" }
}
```

| Field | Type | Meaning |
|---|---|---|
| `contract` | string | Always `aicountly.licence/1` |
| **`scope`** | `company` \| `user` | Whose licence this is: a company's, or a person's own |
| **`customer_id`** | string \| null | The company (Manage `cmp_id`, decimal string). `null` only for a personal (`user`) entitlement |
| **`product_id`** | string | Manage `app_key`: `remote` (Remote), `chat` (Connect) |
| **`subject`** | string \| null | The user this answer is about. `null` on a company-level document; set on member-level and personal ones |
| **`licence_id`** | string \| null | Engage `licence_ref`. Required when `state` is `paid` |
| **`plan_code`** | string \| null | The central catalogue's plan code. Required when `state` is `paid`. Remote mirrors it in `remote_entitlements.plan_code` |
| `plan_class` | `free` \| `paid` | The backend's classification of the plan. A paid state requires `paid` |
| `kind` | `free` \| `paid` \| `trial` \| `complimentary` | Engage's lifecycle kind. **Only `paid` uses the paid reserve by default** (`WEBRTC_LICENCE_PAID_KINDS` widens it) |
| **`state`** | see §4 | The licence state |
| **`valid_from`**, **`valid_until`** | RFC 3339 UTC `…Z`, or `null` | Validity window; `valid_until` is exclusive |
| **`eligibility.model`** | `company_wide` \| `seat` \| `personal` | How the licence grants access (the "entitlement attribute" that distinguishes company-wide from seat licences) |
| **`eligibility.member`** | bool \| null | Whether `subject` is a **current member** of `customer_id`. `null` when there is no subject |
| **`eligibility.seat`** | `assigned` \| `unassigned` \| `revoked` \| `not_applicable` | The subject's seat. Stated (and not `not_applicable`) exactly when `model` is `seat` and a subject is named |
| **`entitlements`** | list of `{code, value}` | Feature entitlements (value: string ≤ 128, integer or boolean; unique codes `[a-z][a-z0-9_.]*`, ≤ 64). Used by TURN for the meeting limits in §9 |
| `revision` | integer ≥ 0 | Monotonic **per (company, product, subject)**. A product never replaces a cached document with a lower revision, and a revocation names a revision |
| `issued_at` | RFC 3339 UTC | When the backend produced this answer. Products distrust documents older than the maximum age (§7) |

Consistency rules (a document that breaks one is `malformed` and never trusted): a personal entitlement has model `personal`, `member`
`null`, seat `not_applicable`, a subject and no customer; a company-level document names no member and no seat; a member-level document
states `member` and a seat status exactly for the `seat` model; `paid` needs `licence_id`, `plan_code` and plan class `paid`.

### Canonical form and signature

Signed bytes = the `document` as **canonical JSON**: object keys sorted bytewise, no whitespace, UTF-8, `/` and non-ASCII unescaped, **no
floating-point numbers**, every possibly-empty collection a *list*. Signature = Ed25519 (libsodium `crypto_sign_detached`) over those
bytes, base64. Executable definition: `Aicountly\Ice\Licence\Canonical` and `EntitlementVerifier::sign()`. **`lib/ice-kit/tests/contract/licence-v1-vectors.json`**
holds 41 signed vectors (a published TEST key), each a *context* (user, active company, product, the product's membership evidence),
the cached envelopes and revocations, and the capacity class it must resolve to — the backend team runs its output through them. JSON Schema:
`docs/contracts/licence-entitlement-v1.schema.json`.

## 4. States and the decision

| `state` | Meaning | TURN capacity |
|---|---|---|
| `free` | A valid free plan, or no paid licence | free |
| `paid` | A valid, current paid licence | **paid — if every condition below holds for THIS user in THIS context** |
| `expired` | Ended: `valid_until` passed or the subscription lapsed | free |
| `suspended` | Stopped administratively or revoked | free |
| `unknown` | The backend cannot say | free |

**Only a current, verified, contextually valid PAID entitlement qualifies for protected TURN capacity.** For the context
(user *u*, company *C*, product *p*), `LicenceResolver::forContext()` grants paid only when all of these hold:

1. a document exists for exactly (*C*, *u*, *p*) — member-level — or, for a company-wide licence, the company-level document for (*C*, *p*);
2. its Ed25519 signature verifies; it is well-formed; identity (company, user, product, scope) matches the lookup;
3. `issued_at` is within the maximum age and not in the future; no revocation at or above its revision exists for the company, or for (*C*, *u*);
4. `state = paid`, plan class `paid`, kind allowed, now within `valid_from … valid_until`;
5. **membership:** for a member-level document `eligibility.member = true` **and** the product's own evidence does not say otherwise;
   for a company-level document the *product* must supply current-membership evidence (`membershipVerified = true`);
6. **seat:** for model `seat`, a member-level document with `seat = assigned`; the company-level document alone never satisfies a seat licence;
7. in a **personal context** (no company) only a personal entitlement for *u* can qualify, and a company licence never can;
8. a lapsed or suspended **company** licence overrides a seat document that has not yet caught up.

Reasons recorded (never an identifier): `paid`, `free`, `expired`, `valid_until_passed`, `suspended`, `revoked`, `central_unknown`, `stale`,
`issued_in_future`, `not_yet_valid`, `kind_not_paid`, `not_a_member`, `membership_unverified`, `seat_unassigned`, `seat_revoked`,
`seat_not_assigned`, `seat_requires_subject`, `signature_invalid`, `identity_mismatch`, `malformed`, `no_entitlement`, `no_context`,
`bad_company_context`, `verifier_unavailable`, `lookup_failed`. The basis (`company_wide`, `seat`, `personal`, `host_meeting`) and the
evidence (`central`, `compat`, `none`) are recorded beside them.

## 5. Identity, context and the products

| Concept | Central | Remote | Connect |
|---|---|---|---|
| Company (customer) | `customer_id` = Manage `cmp_id` | `company_id` of the session — **server-side** | the `company_id` the client says it is operating under — **a claim**, confirmed by the document; absent = personal context |
| User | `subject` | the session's **owner** (`remote_identities.platform_uuid`) | the authenticated user |
| Product | `product_id` | `remote` | `chat` |
| Membership evidence the product adds | — | the owner's `remote_user_company_access` row, confirmed by Manage within the grace period (`membershipVerified`) | none yet: Connect relies on the member-level document (see §13) |
| Who is licensed | — | the **session**; every participant is accounted to it | the caller, per request; in a room, the **host** and then guests under the meeting (§9) |

How a client names its company: `GET /api/calls/config?company_id=<id>` (Connect); a room is created with an optional `company_id`
(the company the booking belongs to) and stored as `connect_rooms.licence_customer_id`. A malformed company id is refused
(`bad_company_context`), never read as "no company". Remote never takes the company from a request.

## 6. The API (server side — proposal)

Base: `https://<licence-host>/api`. **Read-only, GET only.** Success: `{"success": true, "data": …, "page": {…}}`; errors as in the
commerce contract (`{"success": false, "code", "message", "error": {…, "retryable"}}`).

### 6.1 Company-level — `GET /v1/entitlements/{customer_id}?product_id=remote`
`data` = the company-level envelope. A customer with no licence for the product gets a **`free`** document (never a 404), so "free" is a
signed statement and not an absence.

### 6.2 Member-level — `GET /v1/entitlements/{customer_id}?product_id=chat&subject={user}`
`data` = the member-level envelope: whether the user is a **current member** of that company and, for a seat licence, their seat. The
backend owns membership. This is the **on-demand** form: the company-wide membership of every user is not pre-published; products ask when
a (company, user) pair is first seen (`WEBRTC_LICENCE_FETCH_ON_MISS=1`, 1–2 s timeout, signature-verified before use) and cache it.

### 6.3 Personal — `GET /v1/entitlements/personal/{subject}?product_id=chat`
`data` = the user's personal envelope (`scope: user`), `free` when they have none.

### 6.4 Snapshot — `GET /v1/entitlements?product_id=remote&cursor=&limit=`
`data` = company-level envelopes plus seat-assignment member-level envelopes plus personal envelopes (limit ≤ 500), with
`page.next_cursor` and **`page.as_of_seq`** (the changes-feed position the snapshot is consistent with). Company-wide members are **not**
enumerated (§6.2).

### 6.5 Changes and revocations — `GET /v1/entitlements/changes?product_id=chat&since=<seq>&limit=`
`data` = list of `{seq, customer_id | null, subject | null, product_id, revision, revoked}`; `page.next_seq`. The pair says what changed:
`(company, null)` the company's licence; `(company, user)` a member added/removed or a **seat assigned/revoked**; `(null, user)` a personal
licence. `revoked: true` = "stop honouring revisions up to and including `revision` for that scope immediately". A compacted feed answers
`410 gone`; the product takes a fresh snapshot.

## 7. Authentication, tenant isolation, caching

* **Transport: AIC-S2S v1** — the signed server-to-server scheme Finance, Engage and Manage already speak (`X-AIC-Client`, `-Key-Id`,
  `-Timestamp` ±300 s, `-Nonce`, `-Environment`, `-Signature: v1=HMAC-SHA256`; canonical `METHOD\nPATH\nCANONICAL_QUERY\nTIMESTAMP\nNONCE\nsha256(body)`).
  `AicS2s` is a byte-exact port pinned to Finance's published example.
* **One client id and secret per product** (`remote`, `chat`); the backend answers **only that product's** entitlements (`product_id=chat` from client `remote` → `403`).
* **Environment separation** (`production` | `sandbox` | `local`); a staging product holds only a staging public key.
* **Response authenticity is not left to TLS:** every document is independently signed and verified against `WEBRTC_LICENCE_PUBLIC_KEYS` (two key ids for rotation).
* **Defence in depth:** the sync drops any envelope for another product; cache keys are `customer`, `member` (`company|user`) and `user` scopes so a lookup in one
  tenant cannot read another's; the resolver re-checks company, user, product and scope against the signed document.
* **Data minimisation:** identifiers, plan code, state, validity, eligibility and entitlements only; logs carry state, reason, basis, evidence and plan code, never an identifier.
* **Hygiene:** HTTPS only (plain HTTP to `localhost` for tests), no redirects, 2 s connect / 6 s total, 4 MiB cap, secret injected over stdin.
* **Freshness:** `WEBRTC_TURN_ENTITLEMENT_MAX_AGE_HOURS` (default 48, must be set explicitly before enforcement): an older document is `unknown` → free. No stale-if-error for paid.
  Credential requests read the cache only (fetch-through for a missing member document is opt-in and bounded). Sync every 1–2 minutes (snapshot, then changes).

## 8. Revocation

A revocation is as narrow as its subject, and is recorded **before** the document is refetched, so even a failed refetch leaves that
user off the paid reserve (`LicenceContextTest`, `LicenceTest`):

| Event in Engage / the backend | Feed entry | Effect |
|---|---|---|
| Company subscription cancelled / suspended / expired | `(company, null)` | everyone in that company, immediately; also overrides seat documents that have not caught up |
| **Member removed** from the company | `(company, user)` | that user in that company; their other companies and their personal licence untouched |
| **Seat revoked** or unassigned | `(company, user)` | that seat holder only; other seats untouched |
| Personal licence ended | `(null, user)` | that personal licence only |

A document with a **higher** revision lifts a revocation; an older replay cannot. Exposure after a loss of entitlement: the changes-feed
delay (≤ the 1–2 minute sync) for *new and renewed* leases; an allocation already admitted lapses with its credential (default ≤ 1 h; a
desktop agent's session-bound credential ≤ the session). Operator kill-switches need no backend: `bin/entitlement-fixture.php revoke
--product=… [--customer=…] [--subject=…] --revision=N`; `WEBRTC_LICENCE_COMPAT=deny`; removing the public key.

## 9. External meeting guests

A guest has no account, so there is nothing to upgrade and **nothing is**. Under a *paid, eligible host's licensed meeting* the guest's
TURN allocation is **accounted to the meeting** (its session reference, released when the guest leaves or the meeting closes) at the host's
licence class, subject to limits from the host's signed entitlements (`Licence\MeetingGuests`):

| Entitlement | Meaning | Default when absent |
|---|---|---|
| `meeting.guests_paid_quality` | `false` = the plan gives guests no paid-quality service | allow (`WEBRTC_LICENCE_GUESTS_PAID_QUALITY=deny` flips it) |
| `meeting.max_guests` | guests served at paid quality at once | `WEBRTC_LICENCE_DEFAULT_MAX_GUESTS` (5) |
| `meeting.max_participants` | host + guests together at paid quality | no limit |

Positions are by join order among those in the meeting now, so a later guest gains paid quality when an earlier one leaves (the next credential
renewal re-decides). The host is evaluated **in the meeting's company context** — the company the room is licensed under — so a host who is a paid
member of company A gives nothing to a meeting held under company B. The same person joining another meeting, or calling from their own account, is
classed on that context's own entitlement. Authenticated users outside the host's company who join are guests too.

## 10. Product alignment

**Remote** — tier from `forContext(company_id, 'remote', owner, membership)`; the session's participants are all accounted to it. `plan_code` in
`remote_entitlements` mirrors the central code; `WEBRTC_TURN_PAID_PLAN_CODES` survives only as a development-time compat mapping (evidence `compat`).
*Pending, separate from TURN:* mapping the document's `entitlements` onto the `remote_entitlements` feature columns.

**Connect** — tier from `forContext(company_id | null, 'chat', user)`; rooms per §9. `connect_license_tier` is a temporary account-level cache read
**only in the personal context**, never inside a company (it would be a global upgrade).

Audit lines (`remote.ice.issue` / `connect.ice.issue`) record `licence` (state), `licenceReason`, `licenceBasis`, `licenceEvidence`, `plan`.

## 11. Readiness gate (why `observe` stays on)

`WEBRTC_TURN_ADMISSION=enforce` runs as `observe` while **any** of these holds (`IceRuntime`, `LicenceReadiness::blockers`, reported as `enforceBlockedBy`):
the central API is not configured · no public key / no libsodium · `WEBRTC_TURN_ENTITLEMENT_MAX_AGE_HOURS` unset · the feed has never completed a sync, or not
within the maximum age · legacy compatibility is still accepted · nobody has attested validation (`WEBRTC_TURN_LICENCE_FEED=confirmed`). Today **all six hold**
everywhere, and enforcement also waits for the shared ledger and measured real-node capacity (`docs/TURN_BANDWIDTH_ENFORCEMENT.md`).

## 12. Rollout phases

| Phase | State | Admission mode |
|---|---|---|
| 0 — **now** | Backend under development. Adapter, vectors, fixtures, gate built; staging uses signed fixtures; compat labels the books | `observe` |
| 1 | Backend in sandbox; products connect, snapshot, fetch member documents | `observe` |
| 2 | **Shadow comparison** per context: central vs compat for every paying customer; zero unexplained differences | `observe` |
| 3 | `WEBRTC_LICENCE_COMPAT=deny`, max age set, operator attests | `observe`, gate clear |
| 4 | Capacities measured, shaper load-tested, shared accounting live, approval given | `enforce` (staging first) |

## 13. Built vs pending

| Item | Status |
|---|---|
| Common adapter: contextual resolver (company-wide, seat, personal), per-subject revocation, Ed25519 verification, caches (memory/file/PostgreSQL), AIC-S2S client, snapshot+changes sync, fetch-through, guest policy, readiness | **Built, tested** (`LicenceTest`, `LicenceContextTest`, `LicenceContractVectorsTest`) |
| 41 conformance vectors + JSON Schema | **Built** |
| Remote: session licensed in its company context (owner + verified membership), central over compat, seat and removal handling | **Built, tested** (`ActiveActiveIceApiTest`) |
| Connect: `company_id` context on calls and rooms, host in the meeting's company, guests under the meeting with limits, compat personal-only | **Built, tested** (`LicenceTierResolverTest`, `RoomIceAdmissionTest`) |
| Isolation, switching, expiry, membership removal, seat revocation, guests — automated | **Built, tested** (kit + both products) |
| **Central licensing backend (server side of §6); Engage/Manage connected to it** | **Pending — not built** |
| Signing-key custody/rotation; per-product S2S credentials; sync job installed | **Pending** |
| Connect product-side membership verification against Manage (hardening; the member-level document already states membership) | **Pending / optional** |
| Entitlement-code catalogue; mapping to `remote_entitlements` feature columns | **Pending** |
| Business: personal licence inside a company context — **not applied**; trial and complimentary kinds — **not paid capacity**; guests — **up to 5 at paid quality per meeting, no participant cap unless the plan sets one** | **Confirmed 2026-10-22** (built as the defaults) |
| Optional revocation push | **Not built; optional** |

## 14. Questions for the licensing team

1. Confirm the three document shapes (company-level, member-level, personal) and the `eligibility` attributes (`model`, `member`, `seat`).
2. Confirm on-demand member documents (§6.2) rather than publishing every company-wide member in the snapshot.
3. Confirm Ed25519, key custody and rotation, and per-product AIC-S2S client ids (`remote`, `chat`).
4. Changes feed: `(company, user)` entries for member removals and seat changes, retention, and `410` behaviour.
5. Which entitlement codes carry the meeting limits (`meeting.max_guests`, `meeting.max_participants`, `meeting.guests_paid_quality`)? Are guests part of the plan?
6. ~~Personal licence inside a company; trial/complimentary; guest defaults~~ — **confirmed by AICOUNTLY on 2026-10-22:** a personal licence is not applied inside a company context; trial and complimentary kinds are not paid capacity; up to 5 guests per meeting at paid quality with no participant cap unless the plan sets one. A plan's own `meeting.*` entitlements still override the guest defaults.
