# Company lifecycle (Manage) in Remote

Manage decides **when** a company that stopped paying for Remote becomes read-only (view and export
only, for 45 days), and when its data here is deleted; Remote decides **how**, through a reviewed
manifest. Manage also asks, when an AICOUNTLY account is closed, for that person's own non-company data
to be deleted. Contract: manage-aicountly `docs/company-lifecycle/README.md`. Implementation: the
AICOUNTLY company-lifecycle receiver kit **v1.0.0**, vendored unchanged at
`backend/lib/company-lifecycle/` (copy it again from manage-aicountly
`Saas Common Handler/company-lifecycle/` to update; never edit it here).

| Piece | Where |
|---|---|
| Tables (`aic_lifecycle_events`, `_state`, `_jobs`, `_acks`) | migration `backend/app/Database/Migrations/2026-10-14-000100_CreateAicCompanyLifecycleTables.php`, which runs the kit's `schema.sql` copied unchanged to `backend/app/Database/Sql/2026-10-14-000100_aic_company_lifecycle.sql` (`php spark migrate`) |
| FK cycle broken for the purge | migration `2026-10-14-000200_DropInvitationRedeemedParticipantForeignKey`: `remote_participants.invitation_id` ↔ `remote_invitations.redeemed_participant_id` formed a cycle, and the kit refuses any purge order for a cycle; the redundant back-reference constraint goes (column and index stay) |
| Receiver `POST /api/internal/manage/company-lifecycle` | `app/Config/Routes.php` → `Api\CompanyLifecycleController::receive`, **no filter** (CSRF is off app-wide; CORS/headers globals only) — the HMAC (`LIFECYCLE_WEBHOOK_SECRET`) is its only credential |
| Write gate (423 `COMPANY_RESTRICTED`) | `EffectivePolicyResolver::resolve()` — where every company action is checked against membership (403 `COMPANY_ACCESS_DENIED` first) — calls `App\Libraries\CompanyLifecycle::enforce()`; the 423 is an `ApiException` (`error.code`, `error.details.restricted_until`) |
| companyinfo write-through | `Domain\Directory\ManageClient::companyAccess()`; the SPA reads the enforced lifecycle from `GET /v1/remote/bootstrap` (`activeScope.lifecycle`) and `GET /v1/remote/policy/effective` (`lifecycle`) |
| Allow-list (writes that stay open while read-only) | `CompanyLifecycle::ALLOW`: ending a session, stopping a share, leaving, participant/device presence, revoking control, an invitation or a device, disabling unattended access, cancelling a support request. Every GET passes. |
| Purge manifest | `backend/config/lifecycle-manifest.php` |
| Worker bootstrap | `backend/config/lifecycle-kit.php` (boots CodeIgniter's configuration only, so the kit gets the Console-resolved database and the account hook) |
| `.htaccess` | `lib/` and `config/` are refused like `app/` (`tests/Deploy/ApiHtaccessTest.php` covers them) |
| SPA | `web/src/services/companyRestriction.ts` (423 in `services/api/client.ts`, lifecycle from bootstrap / effective policy in `RemoteProvider`), `web/src/components/CompanyRestrictedBanner.tsx` in `AppShell` |
| Tests | `backend/tests/Lifecycle/CompanyLifecycleTest.php` (PHPUnit, the suite's database): manifest problems for the company **and** user scopes are empty on the migrated schema, every allow-list entry is a real route, receiver 401/422/202, 423 after the company check with ending a session still allowed, 403 for a stranger, companyinfo sync, a signed company purge end to end, a signed account purge (personal session deleted, company session kept, identity anonymised); vitest for the restriction state and the banner |

## Manifest decisions (`backend/config/lifecycle-manifest.php`)

* **company_tables — everything company-scoped** (`company_id`): sessions with their participants, invitations, messages, file-transfer records, recording records, events and feedback; support requests; devices and their challenges; desktop sign-in codes; launch-context tokens; policy, entitlements (the plan limits as Remote applies them — billing is Manage's), role / user permissions, memberships, the directory projection; and Remote's own audit log (no statute keeps it). Archived first, children before parents.
* **company_retain — none.**
* **Account purge — the account hook, not `user_tables`.** The person is a `remote_identities` row (`platform_uuid`) that the company's records point at with `ON DELETE NO ACTION`, so the engine cannot delete it, and every personal table reaches the person only through it. `CompanyLifecycle::purgeAccount()` (the kit's `account` hook) does, in one transaction: delete the person's **PERSONAL** sessions (no company — their participants, messages, events, transfers, invitations and feedback cascade), personal devices and desktop sign-in codes, and anonymise the identity projection (name → "Former AICOUNTLY user", e-mail and platform user id cleared). Company sessions and devices stay with their companies. Open question: this path has no archive (the kit archives manifest tables only).
* **ignore** — `remote_identities` (above) and the CodeIgniter migrations table. The draft's `uuid` columns are each row's own public id, not a person.

A migration that adds a table with a company column (or a personal one) must list it in the manifest in
the same commit: strict mode refuses to purge while one is missing, and the lifecycle test fails CI.

## Object storage

None: Remote stores no file and no recording object — file transfers go peer-to-peer and `remote_recordings` rows are never given a stored object by the API (nothing writes `storage_key`). Only rows are purged.

## Cron

cPanel → Cron Jobs, with the PHP the deploy names:

```cron
*/5 * * * * cd ~/public_html/api && <php> lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php >> $HOME/logs/remote-company-lifecycle.log 2>&1
```

Weekly, drop purge archives (they hold personal data) after the 90-day window:

```cron
17 3 * * 0 cd ~/public_html/api && <php> lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --drop-archives-older-than=90 --confirm >> $HOME/logs/remote-company-lifecycle.log 2>&1
```

Operator commands (`--status`, `--dry-run=company:<cmp_id>`, `--dry-run=account:<uuid>`,
`--requeue=<event_id>`, `--list-archives`, `--restore=<schema> --confirm`): kit README §5, always with
`--bootstrap=config/lifecycle-kit.php` (this product's PDO — database name and user from Console when
`CONSOLE_DB_DETAILS_KEY` is set — its .env and its hooks).

## Environment (`api/.env`, never the SPA)

| Variable | |
|---|---|
| `LIFECYCLE_WEBHOOK_SECRET` | required; ≥ 32 chars (`openssl rand -hex 32`); the same value goes into Manage as `LIFECYCLE_WEBHOOK_SECRET_REMOTE`. Unset: events answer 503, nothing is restricted or purged. |
| `MANAGE_API_URL` | required for acks: `https://manage.aicountly.com` (sandbox `https://manage.gh.aicountly.com`). No default, so a sandbox never acks production. |
| `MANAGE_PRODUCT_SERVICE_KEY` | optional: Manage's `internal/company-status` is asked just before each purge; a purge Manage has since reversed is refused. |
| `LIFECYCLE_MANIFEST` | optional override of `config/lifecycle-manifest.php`. |

The worker needs `CREATE` on the product database for the archive schemas
(`GRANT CREATE ON DATABASE <db> TO <user>;`).

**Go live:** deploy → apply the migration → install the cron → set `LIFECYCLE_WEBHOOK_SECRET` and
`MANAGE_API_URL` here → in Manage set `LIFECYCLE_WEBHOOK_SECRET_REMOTE` (same value; and
`LIFECYCLE_WEBHOOK_URL_REMOTE` if the catalog host is not this API) → update manage-aicountly
`docs/company-lifecycle/README.md` §10.
