# Licence entitlements for TURN admission — audit and status (Remote and Connect)

Identical in `aicountly/remote-aicountly` and `aicountly/connect-aicountly`.

> **Superseded in direction by the confirmed architecture** — a centralised licensing module (engage = staff allocation, manage =
> customer view, a **central backend = the one authority**) that Remote and Connect *consume*. The contract, the common adapter and
> the rollout are in **`docs/LICENCE_ENTITLEMENT_CONTRACT.md`**. This document keeps what was *audited* (what exists today, what does
> not) and the **status of every licensing integration**. An earlier version of this audit proposed a Manage-internal "company
> entitlements" endpoint feeding per-product mirror tables; that proposal is **withdrawn** — it would have created a second licensing
> path beside the central one.

**Rule.** A user is *paid* for relay capacity only if a **trusted, current, server-side entitlement** says so — never from anything the
client sends — and only **in context**: for the authenticated user, under the active company, for the product, with verified membership
(and an assigned seat where the licence is seat-based). **Not** because some other company the user belongs to is paid; switching company
recalculates; a personal licence is a separate user-scoped entitlement. Every doubt resolves to **free**. Final decision and rules:
`docs/LICENCE_ENTITLEMENT_CONTRACT.md` §1, §4.

**Enforcement.** `WEBRTC_TURN_ADMISSION` is `observe` everywhere during development. A readiness gate forces `observe` even if
`enforce` is configured, until the central source is operational and validated (`enforceBlockedBy` in `probe.php --status`, and an
`enforce_blocked` audit event). **Nothing here enables enforcement.** See contract §10–§11.

## 1. What was audited (2026-10-21)

Repositories read: `remote-aicountly`, `connect-aicountly`, `manage-aicountly`, `engage-aicountly`, `my-aicountly-com` (portal),
`finance-aicountly`, `apis-aicountly`, `integrate-aicountly`.

| Fact | Where | Consequence |
|---|---|---|
| Manage holds `ghm_subscription` (company × app: `kind` paid/trial/complimentary, `status`, `current_period_end`; app keys `remote`, `chat`), today operator-entered (`source = 'manual'`); Engage holds `engage_licences` (`licence_ref`, `product_code`, `licence_type_code` → `lifecycle_kind`, `plan_code`, `starts_at`, `ends_at`, `status`) | `manage-aicountly/server-php/database/migrations/018_subscription_lifecycle.sql`; `engage-aicountly/docs/LICENSING.md` | These are the *inputs* to the central module the business has now confirmed; they are not read by products and were never meant to be |
| Products can see only a company's lifecycle (`active`/`restricted`/`purge_pending`) through `companyinfo` / `internal/company-status` — never `kind`, plan or end date. The portal's `validatesession`/`userprofile` carry no licence data | `manage-aicountly/server-php/src/Lifecycle/CompanyLifecycleView.php`, `docs/INTERNAL_PRODUCT_APIS.md` | A product cannot learn "paid" from anything that exists today |
| Engage → Manage entitlement event: "Phase B, not built"; checkout/payments not built | `manage-aicountly/docs/BILLING_INTEGRATION.md` | The central module is genuinely new work |

## 2. Remote — the actual plan catalogue (audit result)

`remote_entitlements.plan_code` values that **exist in the repository**:

| Code | Where | What it is | Paid? |
|---|---|---|---|
| `REMOTE_FREE` | column default; `EffectivePolicyResolver` fallback when a company has no row | no entitlement row | free |
| `REMOTE_PROFESSIONAL` | `RemotePlatformDefaultsSeeder` — the **platform-default** row (`company_id IS NULL`) | the feature ceiling for companies without their own row; not a purchase | **never a paid signal** — the adapter's compat path ignores this row |

**Nothing in the repository writes a per-company row** (only the seeder, for the default); the docs say "billing is Manage's". So there
is **no Remote paid plan code anywhere in the codebase**. Under the new architecture that is correct: the *central catalogue* will
own plan codes and their `plan_class`; Remote's `plan_code` becomes a mirror of the central code (contract §9), and
`WEBRTC_TURN_PAID_PLAN_CODES` survives only as a development-time compatibility list (`STAGING_PAID_TEST` in the sandbox).

## 3. Status of every licensing integration

| # | Integration | Status | Notes |
|---|---|---|---|
| L1 | **Common licence-entitlement adapter** in the shared kit (`Aicountly\Ice\Licence`): states `free/paid/expired/suspended/unknown`; **contextual** resolver (user + active company + product → company-wide membership, assigned seat, or personal licence); Ed25519 verification; caches (memory/file/PostgreSQL) with per-company / per-member / per-user scopes; AIC-S2S client; snapshot+changes sync with **per-subject** revocation; fetch-through; meeting-guest policy; readiness | **Built, tested** | `LicenceTest`, `LicenceContextTest`, `LicenceContractVectorsTest` (41 vectors) |
| L2 | Wire contract, JSON Schema, conformance vectors for the licensing team | **Built** (a proposal for them to accept or change) | `docs/LICENCE_ENTITLEMENT_CONTRACT.md`, `docs/contracts/licence-entitlement-v1.schema.json`, `tests/contract/` |
| L3 | **Remote** consumes it: a session is licensed in its **company context** (owner as the subject, owner's current membership as evidence, product `remote`); central evidence overrides the legacy mapping; `plan_code` mirror semantics documented | **Built, tested** | `ActiveActiveIceApiTest` (central paid, suspended beats compat, revocation, cross-tenant, forged, stale, owner removed, membership unconfirmed, seat assigned/revoked) |
| L4 | **Connect** consumes it: **company-scoped** — `?company_id=` claim on calls (confirmed by the member document), rooms licensed under a stated company (`licence_customer_id`), host evaluated in that company, **external guests under the paid host's meeting within plan limits**; `connect_license_tier` demoted to a temporary compat cache read in the personal context only | **Built, tested** | `LicenceTierResolverTest`, `RoomIceAdmissionTest`, `IceConfigServiceAdmissionTest` |
| L5 | Staging without the central module: signed fixtures (`bin/entitlement-fixture.php keys/sign/load/revoke/resolve`) exercising the real signature, freshness and revocation path | **Built, tested** | staging key's private half never on the API host |
| L6 | **Central licensing backend** (server side of the contract) | **Pending — not built** | the licensing module team |
| L7 | Engage writes / Manage reads the central backend | **Pending** | licensing module |
| L8 | Central signing-key custody and rotation; per-product AIC-S2S client ids and secrets | **Pending** | licensing + ops |
| L9 | Scheduled `sync-entitlements` job on the API hosts; `SANDBOX_LICENCE_S2S_SECRET` repository secrets | **Not installed** (nothing to sync; the workflows already carry the secret through stdin when it is set) | ops, after L6 |
| L10 | Mapping the document's `entitlements` onto `remote_entitlements` feature columns | **Pending** (not a TURN item) | needs the central entitlement-code catalogue |
| L11 | ~~Connect's unit~~ **Decided:** contextual, company-scoped (company-wide membership or assigned seat; personal licence separate; guests under the host's meeting). **Still open (business):** trial/complimentary kinds (default: not paid capacity); a personal licence inside a company context (default: not applied); default guest and participant limits | **Decision made; three defaults pending** | contract §1, §9, §14 |
| L15 | Central backend publishes **member-level** documents (membership, seat) and a changes feed with `(company, user)` entries — or Connect gains its own Manage membership check | **Pending — licensing team** (hardening: Connect-side membership verification) | contract §6, §13 |
| L12 | Optional signed revocation push to the products | **Not built; optional** | the poll is the guarantee |
| L13 | Runtime database role of each product: SELECT-only on any compat table | **Not done**; matters less now (central documents are signed, compat rows are not trusted for enforcement) | ops |
| L14 | Shadow comparison of central vs compat for every paying customer; then `WEBRTC_LICENCE_COMPAT=deny` | **Pending** (needs L6) | contract §11 phase 2–3 |

## 4. Completion checklist before `enforce`

- [ ] L6–L8: the central backend exists, signs documents, products hold its public key and an AIC-S2S secret
- [ ] L9: sync job installed on each API host; `lastSyncAt` fresh
- [ ] L11/L15: trial/complimentary, personal-in-company and guest defaults decided; member-level documents published
- [ ] L14: shadow comparison clean; `WEBRTC_LICENCE_COMPAT=deny`
- [ ] `WEBRTC_TURN_ENTITLEMENT_MAX_AGE_HOURS` set; `probe.php --status` shows `enforceBlockedBy: []` in each product in staging with real data
- [ ] Capacities measured and the bandwidth shaper load-tested (`docs/TURN_BANDWIDTH_ENFORCEMENT.md`)
- [ ] Operator sets `WEBRTC_TURN_LICENCE_FEED=confirmed`; approval recorded
- [ ] Only then `WEBRTC_TURN_ADMISSION=enforce`, in staging first (`apply-turn-config.sh --allow-enforce`)

## 5. Security notes

* The signature, not the storage, is the trust anchor: the cache tables can be written by an application role without that role being
  able to make anyone paid. Compat rows are unsigned and therefore only ever label the books in `observe`.
* The tier is signed into the TURN username (`p.` / `f.`) by the API, so the relay's own log attributes traffic to a tier a client
  cannot forge (`UsageLog`, `bin/summarize-usage.php`).
* A failed lookup is **free**, never an error that grants. A paying customer is briefly treated as free during an outage — and then
  only when the free pool is full.
