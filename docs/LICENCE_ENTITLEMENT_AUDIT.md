# Licence entitlements for TURN admission — audit, mapping and feed (Remote and Connect)

Identical in `aicountly/remote-aicountly` and `aicountly/connect-aicountly`.

**Rule.** A user is *paid* for relay capacity only if a **trusted, current subscription entitlement** says so. Never
from anything the client sends (a request field, a header, a JWT claim the client can influence, a UI flag). Every
doubt — no row, unknown plan, expired, stale, unreadable, no database — resolves to **free**.

**Status of enforcement.** `WEBRTC_TURN_ADMISSION=enforce` is **held at `observe`** by a readiness gate until the
mappings and the feed below are complete (`enforceBlockedBy` in `probe.php --status`, `/health` and the audit log
shows why). Staging runs in `observe`. **Nothing here enables enforcement.**

## 1. What was audited

Repositories read: `remote-aicountly`, `connect-aicountly`, `manage-aicountly`, `engage-aicountly`, `my-aicountly-com`
(the portal), `finance-aicountly`, `apis-aicountly`, `integrate-aicountly`. Findings are about the code on their default
branches on 2026-10-21.

## 2. The authoritative source

| Fact | Where | Verdict |
|---|---|---|
| A company's subscription to an app is a row in **Manage `ghm_subscription`**: `cmp_id`, `app_key` (`remote`, `chat`, …), `kind` ∈ {`paid`,`trial`,`complimentary`}, `status` ∈ {`active`,`cancelled`,`expired`,`restricted`,`purge_pending`,`purged`}, `current_period_end`, `source` ∈ {`manual`,`payment`} | `manage-aicountly/server-php/database/migrations/018_subscription_lifecycle.sql` | **The system of record** for "does this company have a paid subscription to this product, and until when" |
| Manage's rows are backed by **Engage licences** (`engage_licences`: `product_code`, `licence_type_code` → `lifecycle_kind` paid/trial/complimentary, `plan_code`, `app_keys[]`, `starts_at`, `ends_at`, `status`); Finance issues the invoices | `engage-aicountly/docs/LICENSING.md`, `manage-aicountly/docs/BILLING_INTEGRATION.md` | Upstream of Manage. Engage → Manage entitlement event is **"Phase B, not built"** |
| Today every `ghm_subscription` row is `source = 'manual'` (operator-entered); `payment` is reserved for the unbuilt payment integration | same | Trusted (operators), but **not automated** |
| **Products cannot read it.** `internal/company-status` and `companyinfo` expose only the *lifecycle* (`active`/`restricted`/`purge_pending`) — never `kind`, `plan`, or the end date. The portal (`my.aicountly.com`) `validatesession`/`userprofile` carry no licence data at all | `manage-aicountly/server-php/src/Lifecycle/CompanyLifecycleView.php`, `docs/INTERNAL_PRODUCT_APIS.md` | **There is no product-facing "is this company paid" endpoint.** This is the gap |

**Conclusion.** The authoritative source is `ghm_subscription` in Manage (kind = `paid`, status = `active`, period not
ended). The missing piece is an authenticated way to hand that to Remote and Connect.

## 3. Recommended feed (a proposal — not built)

Add to Manage, beside `internal/company-status`, **`GET /api/internal/company-entitlements?ids=…&app=remote|chat`**
(product service key, same authentication as `company-status`), answering per company
`{cmp_id, app_key, kind, status, current_period_end, as_of}` straight from `ghm_subscription`. A scheduled **pull** in each
product (every 15 min, plus on demand when a company is first seen) upserts the product's local mirror:

| Product | Mirror | Written by | Key |
|---|---|---|---|
| Remote | `remote_entitlements` (`company_id`, `plan_code`, `valid_from`, `valid_until`, `updated_at`) | the pull job | company |
| Connect | `connect_license_tier` (`user_uuid`, `tier`, `valid_until`, `source`, `updated_at`) | the pull job | user (via company membership — see §5) |

Why pull rather than push: a pull job's `updated_at` is the heartbeat that lets the product notice a stopped feed
(**freshness**, §4), and it needs no inbound endpoint on a shared host. A push from Engage would also work if it rewrites
rows on a schedule so the heartbeat exists. **Both need Manage/Engage engineering — not done here.**

## 4. What the products enforce today (implemented and tested)

| Rule | Remote (`App\Domain\Signalling\IceConfigService`) | Connect (`App\Services\Calling\LicenceTierResolver`) |
|---|---|---|
| Source of truth | only **the company's own** `remote_entitlements` row — **never** the platform-default row (`company_id IS NULL`, seeded `REMOTE_PROFESSIONAL`, a feature set, not a purchase) | only a `connect_license_tier` row for that user |
| Paid means | `plan_code` ∈ `WEBRTC_TURN_PAID_PLAN_CODES` | `tier = 'paid'` |
| Validity window | `valid_from ≤ now < valid_until` (database clock) | `now < valid_until` |
| Freshness | row `updated_at` within `WEBRTC_TURN_ENTITLEMENT_MAX_AGE_HOURS`, else free | same |
| Free | everything else | everything else |
| Client input | never consulted | never consulted |
| Readiness gate (`enforce` → `observe` while any blocker) | empty paid list; `WEBRTC_TURN_LICENCE_FEED` ≠ `confirmed`; max age unset; `REMOTE_FREE` listed as paid; **any plan code in use that is in neither list**; no company has an entitlement row; table unreadable | `WEBRTC_TURN_LICENCE_FEED` ≠ `confirmed`; max age unset; table empty; table not written within max age; table unreadable |

`WEBRTC_TURN_LICENCE_FEED=confirmed` is an **operator attestation** that the feed in §3 exists, covers every paying
company and is running. Setting it without that is a configuration error the code cannot detect; it is the one place a
person, not the code, vouches. The freshness check is the code's backstop: a feed that stops, stops conferring paid status.

## 5. Remote — the actual plan catalogue (audit result)

`remote_entitlements.plan_code` values that **exist in the repository**:

| Code | Where | What it is | Paid? |
|---|---|---|---|
| `REMOTE_FREE` | column default (`2026-01-05-000200_CreateRemotePolicyTables`); `EffectivePolicyResolver` fallback when no row | a company with no entitlement row | **Free** |
| `REMOTE_PROFESSIONAL` | `RemotePlatformDefaultsSeeder` — the **platform-default** row (`company_id IS NULL`) | the feature ceiling applied to companies *without* their own row (120-minute sessions, recording off, …); not a purchase | **Not a paid signal** — the code ignores this row for tier |

**Nothing in the repository writes a per-company row** (only the seeder, for the default). The docs say "billing is
Manage's" (`docs/COMPANY_LIFECYCLE.md`). So today: no company can be paid, and `WEBRTC_TURN_PAID_PLAN_CODES` cannot be
completed from the code: **the paid plan codes are a business fact not present in any repository.** The earlier example
value `REMOTE_BUSINESS,REMOTE_ENTERPRISE` in `.env.example` is a placeholder, not a catalogue.

Mapping decisions needed (Remote):

1. The list of Remote plan codes that exist in Engage's catalogue (`engage_offers.plan_code` for `product_code` = remote) and
   which are paid. Trial and complimentary kinds: **recommended free for relay priority** (a trial must not hold the
   protected reserve), unless the business decides otherwise.
2. Who maps Manage's `kind`/`plan` onto `remote_entitlements.plan_code` — the pull job in §3, and its owner.

## 6. Connect — the authoritative source and the tier unit

Connect has no licence data of its own (`connect_license_tier` is empty and nothing writes it). Manage's `app_key` for
Connect is `chat`. Two decisions are open:

* **Unit.** Subscriptions belong to a **company**, relay credentials are requested by a **user** (and, in a meeting room,
  billed to the **host**). A user can belong to several companies. Recommended: a user is paid if **any** company they
  belong to holds an active `paid` `chat` subscription; the pull job resolves membership (Manage `company-membership`) and
  writes `connect_license_tier(user_uuid, tier, valid_until)`. Alternatives (per seat, per active company) are business choices.
* **Guests** in a room have no account: they are accounted to the host's tier (implemented, `RoomService`).

## 7. Completion checklist before `enforce`

- [ ] Paid plan codes confirmed by the business and set in `WEBRTC_TURN_PAID_PLAN_CODES`; free codes in `WEBRTC_TURN_FREE_PLAN_CODES`
- [ ] `GET /api/internal/company-entitlements` (or equivalent) built in Manage; pull job in Remote and in Connect
- [ ] Trial / complimentary decision recorded
- [ ] Connect tier unit decided (§6)
- [ ] `probe.php --status` shows `enforceBlockedBy: []` for each product in staging with real data
- [ ] A dry-run diff: for every paying company in Manage, the product's mirror says paid (and the reverse) — zero mismatches
- [ ] Operator sets `WEBRTC_TURN_LICENCE_FEED=confirmed` and `WEBRTC_TURN_ENTITLEMENT_MAX_AGE_HOURS` (recommended 48)
- [ ] Only then: `WEBRTC_TURN_ADMISSION=enforce` in staging first, via `apply-turn-config.sh --allow-enforce`

## 8. Security notes

* The mirror tables are written only by the pull job's database role. Give the API's runtime role **SELECT only** on them
  where the host allows it — a compromised request handler must not be able to promote a user. (Not yet done; today the
  runtime role can write — `database/16_connect_license_tier.sql` grants it.)
* The tier is signed into the TURN username (`p.` / `f.`) by the API, so the relay's own log attributes traffic to a tier
  that a client cannot forge (`UsageLog`, `bin/summarize-usage.php`). A client cannot change its tier by editing a request.
* A failed lookup is **free**, never an error that grants. A paying customer is briefly treated as free during an outage of
  the mirror — and then only when the free pool is full.
