# Company lifecycle (Manage) in Remote

Manage decides **when** a company that stopped paying for Remote becomes read-only (view and export
only, for 45 days), and when its data here is deleted; Remote decides **how**, through a reviewed
manifest. Manage also asks, when an AICOUNTLY account is closed, for that person's own non-company data
to be deleted. Contract: manage-aicountly `docs/company-lifecycle/README.md`. Implementation: the
AICOUNTLY company-lifecycle receiver kit **v1.1.1**, vendored unchanged at
`backend/lib/company-lifecycle/` (copy it again from manage-aicountly
`Saas Common Handler/company-lifecycle/` to update; never edit it here).

| Piece | Where |
|---|---|
| Tables (`aic_lifecycle_events`, `_state`, `_jobs`, `_acks`) | migration `backend/app/Database/Migrations/2026-10-14-000100_CreateAicCompanyLifecycleTables.php`, which runs the kit's `schema.sql` copied unchanged to `backend/app/Database/Sql/2026-10-14-000100_aic_company_lifecycle.sql` (`php spark migrate`) |
| Kit 1.1 tables (`aic_lifecycle_expiries`; jobs may be `held`) | migration `2026-10-16-000100_CreateAicCompanyLifecycle11Tables`, which runs the kit's `schema-1.1.sql` copied unchanged to `backend/app/Database/Sql/2026-10-16-000100_aic_company_lifecycle_1_1.sql` |
| The FK cycle | `remote_participants.invitation_id` ↔ `remote_invitations.redeemed_participant_id` form a cycle. Kit 1.0 refused any purge order for it, so `2026-10-14-000200` dropped the back-reference constraint; kit 1.1 handles cycles, so `2026-10-16-000200_RestoreInvitationRedeemedParticipantForeignKey` puts it back (`ON DELETE SET NULL` as before, now `DEFERRABLE INITIALLY IMMEDIATE` — the application sees no difference; back-references left dangling meanwhile are cleared first) and the manifest lists it in `break_cycle` (NULLed on the purged invitations after archiving, before any delete; a restore relinks it) |
| Receiver `POST /api/internal/manage/company-lifecycle` | `app/Config/Routes.php` → `Api\CompanyLifecycleController::receive`, **no filter** (CSRF is off app-wide; CORS/headers globals only) — the HMAC (`LIFECYCLE_WEBHOOK_SECRET`) is its only credential |
| Write gate (423 `COMPANY_RESTRICTED`) | `EffectivePolicyResolver::resolve()` — where every company action is checked against membership (403 `COMPANY_ACCESS_DENIED` first) — calls `App\Libraries\CompanyLifecycle::enforce()`; the 423 is an `ApiException` (`error.code`, `error.details.restricted_until`). Kit 1.1: a request authenticated by a **device credential** (the desktop agent — a machine caller) goes through the kit's `checkMachine()`; `CompanyLifecycle::MACHINE_ALLOW` is empty (Remote receives no money-moved, telephony or provider webhook and no Manage-signed callback), so only `ALLOW` (presence, disabling unattended access, ending …) stays open to it |
| companyinfo write-through | `Domain\Directory\ManageClient::companyAccess()`; the SPA reads the enforced lifecycle from `GET /v1/remote/bootstrap` (`activeScope.lifecycle`) and `GET /v1/remote/policy/effective` (`lifecycle`) |
| Allow-list (writes that stay open while read-only) | `CompanyLifecycle::ALLOW`: ending a session, stopping a share, leaving, participant/device presence, revoking control, an invitation or a device, disabling unattended access, cancelling a support request. Every GET passes. |
| Purge manifest | `backend/config/lifecycle-manifest.php` |
| Worker bootstrap | `backend/config/lifecycle-kit.php` (boots CodeIgniter's configuration only, so the kit gets the Console-resolved database and the account hook) |
| `.htaccess` | `lib/` and `config/` are refused like `app/` (`tests/Deploy/ApiHtaccessTest.php` covers them) |
| SPA | `web/src/services/companyRestriction.ts` (423 in `services/api/client.ts`, lifecycle from bootstrap / effective policy in `RemoteProvider`), `web/src/components/CompanyRestrictedBanner.tsx` in `AppShell` |
| Desktop agent | a `423 COMPANY_RESTRICTED` shows the server's own sentence (until when, renew) in the agent's status line instead of the generic refusal, and is retried with back-off — renewing lifts it (`desktop/crates/remote-core/src/api.rs` `is_company_restricted()` / `company_restricted_message()`, `desktop/src-tauri/src/runtime.rs` `describe()`); the session window already showed the server's message on a refused join |
| Tests | `backend/tests/Lifecycle/CompanyLifecycleTest.php` (PHPUnit, the suite's database): manifest problems for the company **and** user scopes are empty on the migrated schema (no warnings; `break_cycle`), the kit's `schema-1.1.sql` verbatim and the restored deferrable FK, every allow-list entry is a real route, receiver 401/422/202, 423 after the company check with ending a session still allowed, 403 for a stranger, a device credential refused as a machine caller, companyinfo sync, a signed company purge end to end, a purge through the invitation ↔ participant cycle and its restore relinking it, a signed account purge (personal session deleted, company session kept, identity anonymised) and its restore; vitest for the restriction state and the banner; `cargo test -p remote-core` and `runtime.rs` tests for the agent |

## Manifest decisions (`backend/config/lifecycle-manifest.php`)

* **company_tables — everything company-scoped** (`company_id`): sessions with their participants, invitations, messages, file-transfer records, recording records, events and feedback; support requests; devices and their challenges; desktop sign-in codes; launch-context tokens; policy, entitlements (the plan limits as Remote applies them — billing is Manage's), role / user permissions, memberships, the directory projection; and Remote's own audit log (no statute keeps it). Archived first, children before parents.
* **company_retain — none.**
* **Account purge — the account hook, not `user_tables`.** The person is a `remote_identities` row (`platform_uuid`) that the company's records point at with `ON DELETE NO ACTION`, so the engine cannot delete it, and every personal table reaches the person only through it. `CompanyLifecycle::purgeAccount()` (the kit's `account` hook) does, in one transaction: delete the person's **PERSONAL** sessions (no company — their participants, messages, events, transfers, invitations and feedback cascade), personal devices and desktop sign-in codes, and anonymise the identity projection (name → "Former AICOUNTLY user", e-mail and platform user id cleared). Company sessions and devices stay with their companies. Kit 1.1: before anything changes, the hook archives every row it deletes or changes into the purge's archive (`$ctx->archiveRows`): the personal sessions and their cascaded children, the rows whose `device_id` / `session_id` the deletes set to NULL (a personal device used in a company session), the device challenges, the sign-in codes and the identity it anonymises — children before parents, so `--restore=<archive> --confirm` puts it all back (parents first, the deferrable invitation ↔ participant reference deferred).
* **Retention groups** (kit 1.1.1): nothing retained, so no group roots — `--check-manifest` reports no warnings and no `expiry_groups`; the optional `schema-1.1.1.sql` (group columns in the expiry record) is not added, as no group can expire here.
* **Retention periods / scrub / legal hold** (kit 1.1): none — nothing is retained, no retained row holds personal fields, and Remote records no legal hold. **Senders**: Remote writes into no other product (Manage and the portal are only read), so there is no `DeferredSend` outbox.
* **ignore** — `remote_identities` (above) and the CodeIgniter migrations table. The draft's `uuid` columns are each row's own public id, not a person.

A migration that adds a table with a company column (or a personal one) must list it in the manifest in
the same commit: strict mode refuses to purge while one is missing, and the lifecycle test fails CI.

## Object storage

None: Remote stores no file and no recording object — file transfers go peer-to-peer and `remote_recordings` rows are never given a stored object by the API (nothing writes `storage_key`). Only rows are purged.

## Cron

cPanel → Cron Jobs, with the PHP the deploy names:

```cron
# Each line below reports to Console's Cron Job Monitor after the run (monitor in MON=; key CONSOLE_CRON_MONITOR_KEY in the api .env; never fails the line) — console-react-app docs/CRON_MONITORS.md.
*/5 * * * * cd ~/public_html/api && <php> lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php >> $HOME/logs/remote-company-lifecycle.log 2>&1; RC=$?; MON=remote.company_lifecycle; KEY=$(sed -n 's/^CONSOLE_CRON_MONITOR_KEY *= *//p' .env | tr -d "\"' \r"); BASE=$(sed -n 's/^CONSOLE_API_BASE *= *//p' .env | tr -d "\"' \r"); [ -n "$KEY" ] && curl -fsS -m 5 -o /dev/null -X POST "${BASE:-https://console.aicountly.org/api}/portal/cron-heartbeat" -H "X-Console-Cron-Key: $KEY" -H 'Content-Type: application/json' -d "{\"monitor\":\"$MON\",\"run_id\":\"$MON-$(date +\%s)-$$\",\"outcome\":\"$([ $RC = 0 ] && echo ok || echo error)\",\"finished_at\":$(date +\%s)}" || true
37 3 * * *  cd ~/public_html/api && <php> lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --expire-retained >> $HOME/logs/remote-company-lifecycle.log 2>&1; RC=$?; MON=remote.company_lifecycle_expiry; KEY=$(sed -n 's/^CONSOLE_CRON_MONITOR_KEY *= *//p' .env | tr -d "\"' \r"); BASE=$(sed -n 's/^CONSOLE_API_BASE *= *//p' .env | tr -d "\"' \r"); [ -n "$KEY" ] && curl -fsS -m 5 -o /dev/null -X POST "${BASE:-https://console.aicountly.org/api}/portal/cron-heartbeat" -H "X-Console-Cron-Key: $KEY" -H 'Content-Type: application/json' -d "{\"monitor\":\"$MON\",\"run_id\":\"$MON-$(date +\%s)-$$\",\"outcome\":\"$([ $RC = 0 ] && echo ok || echo error)\",\"finished_at\":$(date +\%s)}" || true
```

The second line (kit 1.1) is the daily retention expiry: Remote retains nothing today, so it expires nothing, but
a retained table given a period later is expired without a deploy step. Console monitors (Console
`cron_monitors`, console-react-app migration 068, `docs/CRON_MONITORS.md`; the cron lines above report to them): `remote.company_lifecycle` and `remote.company_lifecycle_expiry`.

Weekly, drop purge archives (they hold personal data) after the 90-day window:

```cron
17 3 * * 0 cd ~/public_html/api && <php> lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --drop-archives-older-than=90 --confirm >> $HOME/logs/remote-company-lifecycle.log 2>&1
```

Operator commands (`--status`, `--dry-run=company:<cmp_id>`, `--dry-run=account:<uuid>`,
`--requeue=<event_id>`, `--list-archives`, `--restore=<schema> --confirm`, kit 1.1 `--expire-retained=dry-run`,
`--check-manifest`): kit README §5, always with
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
(`GRANT CREATE ON DATABASE <db> TO <user>;`). Remote has no separate grant script: the API's database user runs
the migrations and owns every table, the kit's five (`aic_lifecycle_events / _state / _jobs / _acks / _expiries`)
and their sequences included.

**Go live:** deploy → apply the migration → install the cron → set `LIFECYCLE_WEBHOOK_SECRET` and
`MANAGE_API_URL` here → in Manage set `LIFECYCLE_WEBHOOK_SECRET_REMOTE` (same value; and
`LIFECYCLE_WEBHOOK_URL_REMOTE` if the catalog host is not this API) → update manage-aicountly
`docs/company-lifecycle/README.md` §10.
