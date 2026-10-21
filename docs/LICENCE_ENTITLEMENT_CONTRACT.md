# Licence entitlement contract — central licensing → AICOUNTLY Remote and Connect

Identical in `aicountly/remote-aicountly` and `aicountly/connect-aicountly`. Contract id: **`aicountly.licence/1`**.

> **Status: a PROPOSAL for the licensing team, plus the consumer half, which is built.** The central licensing backend is
> under development and **does not exist yet**. Everything on the *consumer* side (the common adapter in `lib/ice-kit`, its tests,
> the conformance vectors, the readiness gate, the staging fixtures) is implemented and tested in these repositories. The
> *server* side of the API (§5) is a specification, not code. Nothing here has run against a real licensing backend.

## 1. The confirmed architecture

| Component | Role | Relationship to TURN capacity |
|---|---|---|
| **engage.aicountly.org** | Internal AICOUNTLY staff application: allocates, modifies, renews and revokes licences | Writes to the central backend. Not read by products |
| **manage.aicountly.com** | Customer-facing: assigned licences, plans and entitlements are visible | Reads the central backend. Not read by products for TURN |
| **Central licensing backend** | **The single authority** for subscription status, plan classification, validity and feature entitlements | Issues signed entitlements; products consume them |
| **AICOUNTLY Remote and Connect** | Consumers of centrally verified entitlements | Decide only *which capacity class* a request falls in, from what the backend signed |

Consequences, all enforced in code:

* **Remote and Connect are not licensing authorities.** Neither classifies a plan as paid on its own account once the central source
  exists. Remote's `remote_entitlements.plan_code` becomes a *mirror* of the central catalogue's plan code (§9); Connect's
  `connect_license_tier` is a **temporary cache / compatibility mechanism**, not a source of truth.
* **One adapter for both products** (`Aicountly\Ice\Licence`, in the shared kit), not two licensing integrations.
* **Hard paid/free enforcement does not start until the central source is operational and validated.** `WEBRTC_TURN_ADMISSION` stays
  `observe` during development, and a readiness gate forces that even if `enforce` is configured (§10).
* The **Mumbai/NCR active-active routing** and the **50 % reservation of actual bandwidth and concurrent allocations for paid
  customers across Remote and Connect collectively** (shared PostgreSQL ledger) are unchanged; this contract only decides *who is paid*.

## 2. Principles

1. **Trusted, server-side evidence only.** Paid classification comes from a document the central backend signed. Nothing a client sends
   — a field, header, token claim, UI flag — is ever an input. The identifiers passed to the adapter (a session's company, an
   authenticated user) are derived on the server.
2. **Every doubt is free.** No document, a bad or missing signature, an unknown key, a document for another customer or product, a stale
   or future-dated document, an expired or suspended or unknown state, a revoked revision, an unreachable backend: all resolve to
   **free** capacity. A failing licence source can only tighten a customer's capacity, never hand out the paid reserve.
3. **The cache is not trusted; the signature is.** Documents are Ed25519-signed; products hold only the *public* key. A compromised
   product host, a writable database role or a poisoned cache cannot mint a paid entitlement.
4. **Tenant isolation is checked at every layer** (§6): the transport scopes by product, the sync drops any foreign document, and the
   resolver re-checks customer, product and subject against the signed document.
5. **Revocation beats freshness** (§8).

## 3. The entitlement document

An **envelope** carries a signed **document**:

```json
{
  "document": {
    "contract": "aicountly.licence/1",
    "customer_id": "42",
    "product_id": "remote",
    "subject": null,
    "licence_id": "LIC-000123",
    "plan_code": "REMOTE_BUSINESS",
    "plan_class": "paid",
    "kind": "paid",
    "state": "paid",
    "valid_from": "2026-10-20T00:00:00Z",
    "valid_until": "2026-11-20T00:00:00Z",
    "entitlements": [ {"code": "max_session_minutes", "value": 480}, {"code": "recording", "value": true} ],
    "revision": 5,
    "issued_at": "2026-10-21T00:00:00Z"
  },
  "signature": { "alg": "ed25519", "kid": "central-2026-10", "value": "<base64 of the 64-byte detached signature>" }
}
```

| Field | Type | Meaning |
|---|---|---|
| `contract` | string | Always `aicountly.licence/1` |
| **`customer_id`** | string | The customer (company): Manage's `cmp_id` as a decimal string. Remote's `company_id` is this value |
| **`product_id`** | string | The product: Manage's `app_key` — `remote` (Remote), `chat` (Connect) |
| `subject` | string \| null | Set only on a *by-subject* answer (§5.2): the user the answer is for. `null` on a customer answer |
| **`licence_id`** | string \| null | Engage's `licence_ref` (`LIC-000123`). Required when `state` is `paid`; `null` for a default free plan |
| **`plan_code`** | string \| null | The central catalogue's plan code (Engage `plan_code`). Required when `state` is `paid`. Remote stores this in `remote_entitlements.plan_code` |
| `plan_class` | `free` \| `paid` | The backend's own classification of the plan. A paid state requires `paid` |
| `kind` | `free` \| `paid` \| `trial` \| `complimentary` | Engage's licence lifecycle kind. **Only `paid` uses the paid reserve by default**; widening is a product policy (`WEBRTC_LICENCE_PAID_KINDS`) |
| **`state`** | see below | The licence state |
| **`valid_from`**, **`valid_until`** | RFC 3339 UTC `…Z`, or `null` | The validity window; `valid_until` is exclusive. Seconds precision, no offsets or fractions |
| **`entitlements`** | list of `{code, value}` | Feature entitlements (value: string ≤ 128, integer or boolean; codes `[a-z][a-z0-9_.]*`, unique, ≤ 64). Not used by TURN; carried so one document serves every consumer |
| `revision` | integer ≥ 0 | Monotonic per (customer, product). A product never replaces a cached document with a lower revision, and a revocation names a revision |
| `issued_at` | RFC 3339 UTC | When the backend produced this answer. Products distrust documents older than the maximum age (§7) |

### States

| `state` | Meaning | TURN capacity |
|---|---|---|
| `free` | A valid free plan, or no paid licence | free |
| `paid` | A valid, current paid licence | **paid** (if plan class `paid`, kind allowed, in window, fresh, unrevoked) |
| `expired` | Ended: `valid_until` passed or the subscription lapsed | free |
| `suspended` | Stopped administratively or revoked (non-payment, abuse, legal hold) | free |
| `unknown` | The backend cannot say | free |

Locally the adapter adds reasons (`stale`, `signature_invalid`, `identity_mismatch`, `revoked`, `no_entitlement`, …) and may downgrade
a `paid` document to `expired` (its own `valid_until` passed since issue) or `unknown` (stale, not yet valid) — never upgrade one.

### Canonical form and signature

Signed bytes = the `document` serialised as **canonical JSON**: object keys sorted bytewise, no whitespace, UTF-8, `/` and non-ASCII
unescaped, **no floating-point numbers**, and every possibly-empty collection a *list* (`entitlements`). Signature = Ed25519 (libsodium
`crypto_sign_detached`) over those bytes, base64. The executable definition is `Aicountly\Ice\Licence\Canonical` and
`EntitlementVerifier::sign()`; **`lib/ice-kit/tests/contract/licence-v1-vectors.json`** holds 21 signed test vectors (a published
TEST key) with the capacity class each must resolve to — a backend implementer runs its output through them. JSON Schema:
`docs/contracts/licence-entitlement-v1.schema.json`.

## 4. Identity mapping

| Concept | Central | Remote | Connect |
|---|---|---|---|
| Customer | `customer_id` = Manage `cmp_id` | `company_id` of the session | resolved by the backend from the user (§5.2) |
| Product | `product_id` | `remote` | `chat` |
| Licence | `licence_id` = Engage `licence_ref` | audit/support reference | audit/support reference |
| Plan | `plan_code` = Engage catalogue code | `remote_entitlements.plan_code` mirrors it | n/a (the document is the record) |
| Who asks | the authenticated session's company | `forCustomer(company, 'remote')` | `forSubject(user, 'chat')`; a meeting guest uses the **host's** subject |

**Open (licensing team):** whether Connect's unit is the *user* (a user may belong to several customers — the backend picks the best
active entitlement, recommended) or the *seat*. The by-subject endpoint exists so Connect does not have to know either.

## 5. The API (server side — proposal)

Base: `https://<licence-host>/api`. **Read-only, GET only.** Success: `{"success": true, "data": …, "page": {…}}`; errors as in the
commerce contract (`{"success": false, "code", "message", "error": {…, "retryable"}}`) — the same shapes Engage and Finance use.

### 5.1 One customer — `GET /v1/entitlements/{customer_id}?product_id=remote`
`data` = one envelope. A customer with no licence for the product gets a **`free`** document (`licence_id: null`), never a 404, so
"free" is a signed statement and not an absence. 404 only for an unknown customer id (the adapter treats it as no entitlement → free).

### 5.2 One user — `GET /v1/entitlements/by-subject/{subject}?product_id=chat`
`data` = one envelope with `subject` set: the best active entitlement across the customers the user belongs to (the backend owns
membership). Same free-document rule.

### 5.3 Snapshot — `GET /v1/entitlements?product_id=remote&cursor=&limit=`
`data` = list of envelopes (limit ≤ 500), `page.next_cursor`, and **`page.as_of_seq`**: the changes-feed sequence number the snapshot is
consistent with. Used for the first load and for periodic full reconciliation.

### 5.4 Changes and revocations — `GET /v1/entitlements/changes?product_id=remote&since=<seq>&limit=`
`data` = list of `{seq, customer_id, product_id, revision, revoked}`; `page.next_seq`. `revoked: true` means "stop honouring revisions up to
and including `revision` immediately". Every state change, renewal, modification and revocation made in Engage appears here. A feed that
has been compacted past `since` answers `410 gone`; the product then takes a fresh snapshot.

## 6. Authentication and tenant isolation

* **Transport authentication: AIC-S2S v1** — the signed server-to-server scheme Finance, Engage and Manage already speak
  (`X-AIC-Client`, `-Key-Id`, `-Timestamp` ±300 s, `-Nonce`, `-Environment`, `-Signature: v1=HMAC-SHA256`; canonical string
  `METHOD\nPATH\nCANONICAL_QUERY\nTIMESTAMP\nNONCE\nsha256(body)`). The adapter's `AicS2s` is a byte-exact port, pinned to Finance's
  published worked example in `LicenceTest`.
* **One client id and one secret per product** (`remote`, `chat`). The backend selects the secret from the client id and **answers only
  for that product**: a request for `product_id=chat` from client `remote` is `403`. A product can read all of *its own* customers'
  entitlements (it serves them) and nothing of any other product's.
* **Environment separation.** `X-AIC-Environment` (`production` | `sandbox` | `local`) must equal the backend's. Sandbox signing keys
  never verify in production and vice-versa; staging products are configured with a *staging* public key only.
* **Response authenticity is not left to TLS.** Every document is Ed25519-signed independently; products verify against
  `WEBRTC_LICENCE_PUBLIC_KEYS` (two key ids may be live for rotation). A response whose signature fails is dropped, counted and never stored.
* **Defence in depth against a misbehaving backend or proxy:** the sync drops any envelope whose `product_id` is not the product's own;
  the resolver re-checks `customer_id` / `product_id` / `subject` against the lookup key.
* **Data minimisation.** No names, e-mail addresses, prices or invoices — only identifiers, plan code, state, validity and entitlements.
  The products' logs carry state, reason, evidence and plan code only, never a customer or licence identifier.
* **Hygiene.** HTTPS only (plain HTTP only to `localhost` for tests), no redirects followed, 2 s connect / 6 s total, 4 MiB cap; the
  secret is injected over stdin (`bin/ops/inject-turn-secrets.sh`), never written to a repository, argument or log.

## 7. Caching and freshness

| Layer | Behaviour |
|---|---|
| Central | Documents are produced on request with a fresh `issued_at`; the backend may cache them but must not extend `issued_at` |
| Product cache | Signed envelopes in the shared ledger database (`aic_entitlement_cache`) or a file; **not trusted storage** — re-verified on every use. A lower `revision` never replaces a higher |
| Sync | `bin/sync-entitlements.php` every **1–2 minutes**: first a full snapshot, then the changes feed. The heartbeat (`lastSyncAt`) advances only after a complete pass |
| Maximum age | `WEBRTC_TURN_ENTITLEMENT_MAX_AGE_HOURS` (default 48 h, **must be set explicitly** before enforcement): a document issued longer ago is `unknown` → free. Bounds a stopped feed or a replayed old answer |
| Fail-closed | No stale-if-error for **paid**: when the backend is unreachable beyond the maximum age, paid customers fall to free capacity (and only matters when the free pool is full) |
| Per request | Credential requests read the cache only — no synchronous call to the backend on the TURN path |

**Exposure window** after a customer loses a paid licence: the changes-feed delay (≤ sync interval, 1–2 min) **+** nothing more for *new*
and *renewed* leases. An allocation already admitted is not cut off mid-call; it lapses with its credential (default ≤ 1 h, a desktop
agent's session-bound credential ≤ the session). The next renewal is admitted as free.

## 8. Revocation

1. Engage revokes/suspends/expires a licence → the backend bumps `revision`, appends to the changes feed with `revoked: true`.
2. The next sync records the revocation **before** it refetches the document, so even a failed refetch leaves the customer off the
   paid reserve (`revoked`, state `suspended`, tested in `LicenceTest::testTheChangesFeedRevokesBeforeItRefetches`).
3. A document with a **higher** revision lifts the revocation; an older replay cannot.
4. **Manual kill-switches** (operator, no backend needed): `bin/entitlement-fixture.php revoke --customer=… --product=… --revision=N`;
   `WEBRTC_LICENCE_COMPAT=deny` to stop honouring the legacy mapping; removing the public key makes every central document unverifiable → free.
5. **Optional push** (not built): the backend may POST a signed `entitlement.revoked` event to an internal endpoint on each product, which calls
   `EntitlementCache::revoke()`. It would only shorten the 1–2 minute window; the poll is the guarantee.

## 9. Product alignment

**Remote** — `plan_code`: the central catalogue's code for the Remote product is the value of `remote_entitlements.plan_code`. Remote's
tier is `LicenceResolver::forCustomer(company_id, 'remote')`. `WEBRTC_TURN_PAID_PLAN_CODES` survives *only* as the development-time
compatibility mapping (evidence `compat`) and is refused as evidence for enforcement. *Pending, separate from TURN:* mapping the document's
`entitlements` onto the `remote_entitlements` feature columns (`max_session_minutes`, `recording`, `desktop_devices`, …) once the central
entitlement codes are agreed; today those columns are filled by whoever fills them.

**Connect** — tier is `LicenceResolver::forSubject(user, 'chat')`; a meeting room is accounted to the **host's** subject (guests have no
account). `connect_license_tier` (migration 16) is a temporary cache/compat table: read only where the backend is silent and compat is
allowed; rows are unsigned; slated for removal once the central source is validated.

Both: the audit log (`remote.ice.issue` / `connect.ice.issue`) records `licence` (state), `licenceReason`, `licenceEvidence`
(`central` / `compat` / `none`) and `plan` — never an identifier.

## 10. Readiness gate (why `observe` stays on)

`WEBRTC_TURN_ADMISSION=enforce` runs as `observe` while **any** of these holds (`IceRuntime`, `LicenceReadiness::blockers`, reported as
`enforceBlockedBy` in `probe.php --status`):

1. the central API is not configured (`WEBRTC_LICENCE_API_URL`, `_KEY_ID`, `_ENVIRONMENT`, `_S2S_SECRET`);
2. no public key is configured or libsodium is missing;
3. `WEBRTC_TURN_ENTITLEMENT_MAX_AGE_HOURS` is not set;
4. the feed has never completed a sync, or not within the maximum age;
5. legacy compatibility is still accepted (`WEBRTC_LICENCE_COMPAT` is not `deny`);
6. nobody has attested validation (`WEBRTC_TURN_LICENCE_FEED=confirmed`).

Today **all six hold** in every environment. That is intended.

## 11. Rollout phases

| Phase | State | Admission mode |
|---|---|---|
| 0 — **now** | Central backend under development. Adapter, vectors, fixtures and gate built; compat mapping labels the books in staging | `observe` |
| 1 | Backend exists in sandbox; products connect, take snapshots; staging uses the real feed | `observe` |
| 2 | **Shadow comparison:** for every paying customer, compare the central answer with the compat answer; zero unexplained differences | `observe` |
| 3 | `WEBRTC_LICENCE_COMPAT=deny`, `WEBRTC_TURN_ENTITLEMENT_MAX_AGE_HOURS` set, operator attests | `observe`, gate clear |
| 4 | Capacities measured, bandwidth shaper load-tested (`docs/TURN_BANDWIDTH_ENFORCEMENT.md`), approval given | `enforce` (staging first) |

## 12. Built vs pending

| Item | Status |
|---|---|
| Common adapter: states, document validation, Ed25519 verification, resolver, caches (memory / file / PostgreSQL), AIC-S2S client, sync with revocation, readiness | **Built, tested** (kit `LicenceTest` and `LicenceContractVectorsTest`) |
| Conformance vectors + JSON Schema for the backend team | **Built** |
| Remote consumes it (company → `remote`), plan-code alignment, compat fallback, central-over-compat precedence | **Built, tested** (`ActiveActiveIceApiTest`) |
| Connect consumes it (user → `chat`, host for rooms), `connect_license_tier` demoted to compat | **Built, tested** |
| Staging fixtures: signed test entitlements, revoke, resolve (`bin/entitlement-fixture.php`) | **Built, tested** |
| **Central licensing backend (server side of §5)** | **Pending — not built** |
| **Engage → backend writes; Manage → backend reads** | **Pending (licensing module)** |
| Backend signing key generation, custody, rotation procedure | **Pending (licensing team)** |
| Entitlement-code catalogue and mapping to `remote_entitlements` feature columns | **Pending** |
| Connect's unit (user vs seat) and trial/complimentary treatment | **Pending (business)** |
| Optional revocation push endpoint | **Not built; optional** |
| Scheduled `sync-entitlements` job on the API hosts | **Not installed** (nothing to sync yet) |

## 13. Questions for the licensing team

1. Confirm `customer_id` = Manage `cmp_id` and `product_id` ∈ {`remote`, `chat`} as the keys.
2. Confirm Ed25519 for document signing and who holds/rotates the private key; confirm per-product AIC-S2S client ids (`remote`, `chat`).
3. Is the by-subject lookup (§5.2) acceptable, or does Connect have to resolve membership itself?
4. Which plan codes exist for Remote and Connect, and which are `plan_class: paid`? How are trial and complimentary licences classified for capacity?
5. Is the changes feed with `seq` and revocation flag implementable, and what is its retention?
6. Grace periods (a lapsed payment): does the backend express them as `paid` with a later `valid_until`, or as `suspended`?
