# Database

PostgreSQL only. The schema uses JSONB, `INET`, `TIMESTAMPTZ`, partial unique
indexes and `CHECK` constraints, so it is not portable to MySQL and is not meant
to be.

Every table is prefixed `remote_`. AICOUNTLY runs several products against
shared infrastructure, and a table called `sessions` would be a collision
waiting to happen.

## Migrations

```bash
cd backend
php spark migrate                                    # apply
php spark migrate:rollback                           # undo the last batch
php spark migrate:status
php spark db:seed RemotePlatformDefaultsSeeder       # safe in production
php spark db:seed RemoteDevelopmentSeeder            # refuses to run in production
```

| Migration | Creates |
|---|---|
| `…000100_CreateRemoteDirectoryTables` | `remote_identities`, `remote_company_directory`, `remote_user_company_access` |
| `…000200_CreateRemotePolicyTables` | `remote_company_policies`, `remote_entitlements`, `remote_role_permissions`, `remote_user_permissions` |
| `…000300_CreateRemoteSessionTables` | `remote_sessions`, `remote_participants`, `remote_invitations`, and the display-id sequence |
| `…000400_CreateRemoteActivityTables` | `remote_session_events`, `remote_audit_logs`, `remote_messages`, `remote_file_transfers`, `remote_recordings` |
| `…000500_CreateRemoteSupportTables` | `remote_support_requests`, `remote_session_feedback` |
| `…000600_CreateRemotePlatformTables` | `remote_context_tokens`, `remote_devices` |

Rollback is exercised in CI, not assumed: a migration nobody can undo is a
migration nobody can deploy with confidence.

---

## Projections of AICOUNTLY data

None of these is a master. AICOUNTLY owns identity (`my.aicountly.com`) and the
company/branch/financial-year masters (`manage.aicountly.com`).

### `remote_identities`

**Not an authentication store.** No password, no credential, no session. It
exists because the portal identifies a user by UUID while Remote needs a stable
integer to hang a dozen foreign keys on.

`id` always comes from the sequence; AICOUNTLY's own numeric user id is kept
beside it in `platform_user_id` for cross-product correlation. Adopting the
platform id as the primary key would let a later sequence value collide with an
id the platform had already used.

### `remote_company_directory`

Company id, display name, timezone, locale, `synced_at`. Enough to render a
session list without a network call. Not a company master.

### `remote_user_company_access`

Which companies a user may act in, and with what platform role. Populated from
a verified launch context, from the platform directory API when one is
configured, or by an administrator. It is a cache with a `synced_at`, and
`source` records which of the three it came from.

This is the table tenant isolation is decided against: no row, no access.

---

## Policy

### `remote_company_policies`

One row per company, with a lazily-provisioned conservative default the first
time a company is seen — the alternative is either "no row means no policy"
(unsafe) or a nightly sync of every AICOUNTLY company (wasteful).

Column defaults *are* the STANDARD preset: Safe Share, browser tabs and
application windows on; entire monitor, external guests, file transfer and
recording off.

Two constraints are enforced by the database rather than only by code:

```sql
CHECK (max_session_duration_minutes BETWEEN 5 AND 1440)
CHECK (remote_enabled OR NOT (allow_safe_share OR allow_browser_tab
                              OR allow_application_window OR allow_entire_monitor))
```

The second means what it says: turning Remote off is the one switch that cannot
be contradicted by the ones below it.

### `remote_entitlements`

The plan's ceiling (§79). `company_id IS NULL` is the platform default that
applies to every company without its own — kept unique by a partial index,
because PostgreSQL treats NULLs as distinct in a `UNIQUE` constraint and would
otherwise allow a second one.

### `remote_role_permissions`, `remote_user_permissions`

Explicit `ALLOW` / `DENY` rules. `company_id IS NULL` is the platform-wide
default; the company's own row is applied after it and wins. Both are keyed
`UNIQUE (COALESCE(company_id, 0), …)` for the same NULL reason.

An empty table means "the defaults from `PermissionCatalog`", not "nobody can
do anything".

---

## Sessions

### `remote_sessions`

The core table. Three identifiers, and they are not interchangeable:

| Column | What it is |
|---|---|
| `id` | internal, never leaves the server |
| `uuid` | the public name — unguessable, used in URLs, not a credential |
| `display_id` | `AR-10282`, a label to read aloud, explicitly not secret |
| `session_code` | nine digits — **is** a credential; from a CSPRNG, cleared when the session ends |

The scope rules are constraints, because a bug here would leak a tenant:

```sql
CHECK (scope_type <> 'COMPANY' OR company_id IS NOT NULL)
CHECK (scope_type <> 'PERSONAL' OR (company_id IS NULL
                                    AND branch_id IS NULL
                                    AND financial_year_id IS NULL))
CHECK (branch_id IS NULL OR company_id IS NOT NULL)
```

The `allow_*` columns are the **policy snapshot**: the rules in force when the
session was created. An administrator turning chat off stops the next session,
not one two people are already talking in.

`session_code` has a partial unique index over non-NULL values, so a retired
code returns to the pool.

### `remote_participants`

```sql
CHECK ((participant_role = 'GUEST') = (user_id IS NULL))
```

A guest has no AICOUNTLY identity; everybody else must have one. The two can
never drift apart.

`capabilities` is JSONB — the capability negotiation a desktop agent will use
without a schema change ([DESKTOP_AGENT.md](DESKTOP_AGENT.md)).

`UNIQUE (session_id, user_id) WHERE user_id IS NOT NULL` is what makes a repeated
join request idempotent instead of producing a queue of duplicates.

### `remote_invitations`

`token_hash CHAR(64) UNIQUE` — SHA-256 of the secret. The secret itself appears
in exactly one HTTP response and is never stored, so a database dump cannot be
turned back into a working link. Losing it means issuing a new one.

```sql
CHECK (max_uses >= 1 AND used_count >= 0 AND used_count <= max_uses)
```

Redemption is `UPDATE … WHERE used_count < max_uses`, so a link opened twice at
the same instant is consumed once.

---

## Activity

### `remote_session_events` vs `remote_audit_logs`

Deliberately separate.

* **Events** are the session's own timeline, shown to anyone who can see the
  session.
* **Audit** is the security record: actor, company, IP, user agent, request id.
  It outlives the session and needs `remote.audit.view`.

Neither ever receives screen content or a chat body. `AuditService::scrub()`
enforces that before the write.

### `remote_messages`

Chat, with its own retention. Kept out of the audit trail on purpose: enabling
advanced audit must not enable transcript retention.

```sql
CHECK (char_length(body) BETWEEN 1 AND 4000)
```

### `remote_file_transfers`

A ledger, not a store. The bytes move peer-to-peer over the data channel and
never touch this server; the row records what was offered, accepted and
completed.

### `remote_recordings`

Schema, consent model and status, so recording can be switched on without a
migration against live session data. **Nothing writes to it in V1.**

```sql
CHECK (status IN ('REQUESTED','FAILED','DELETED')
       OR consent_state IN ('GRANTED','NOT_REQUIRED'))
```

Capture cannot begin until consent is settled — a database-level version of
§38.

---

## Support

### `remote_support_requests`

```sql
CHECK ((status = 'ACCEPTED') = (accepted_by_user_id IS NOT NULL
                                AND accepted_at IS NOT NULL)
       OR status IN ('COMPLETED','CANCELLED'))
```

An accepted request has an acceptor and vice versa — they cannot drift apart,
whichever technician won the race. A partial index on `status = 'PENDING'`
drives the queue.

`support_ticket_id`, `source_product`, `source_route`, `source_reference`,
`source_agent` and `source_conversation_id` are all optional opaque strings, so
Pulse, Advisor or a future helpdesk can each fill them their own way without
Remote depending on any of them.

---

## Platform

### `remote_context_tokens`

Replay protection for launch tokens. `UNIQUE (jti)` is the guard: two requests
presenting the same token both attempt the insert and exactly one succeeds.
Checking with a `SELECT` first would leave the race open.

Rows are only interesting until the token would have expired anyway; the index
on `expires_at` drives the cleanup sweep.

### `remote_devices`

Future-facing (§52). Nothing writes to it in V1, and no desktop-agent
functionality is presented as live in the UI. It exists so the agent work does
not begin with a migration against live session data.

---

## Time

Every timestamp is `TIMESTAMPTZ` and every write carries an explicit `+00`
offset (`App\Domain\Support\Clock`).

A naive `Y-m-d H:i:s` string handed to a `TIMESTAMPTZ` column is interpreted in
the **database server's** timezone, which on a shared host is whatever the
provider set — so a session created at 14:00 UTC can land as 14:00 IST and
expire five and a half hours early. The offset removes the guesswork.

Reading back needs no such care: PostgreSQL renders `TIMESTAMPTZ` with its
offset, so `strtotime()` recovers the correct instant wherever it is parsed. The
API formats every timestamp as ISO-8601 UTC and the browser renders it in the
viewer's own timezone.

## Booleans

PostgreSQL returns booleans as the strings `'t'` and `'f'`, and `(bool) 'f'` is
**true** in PHP. Session and participant rows are normalised once on the way out
of the database (`SessionService::castRow()`, `ParticipantService::castRow()`)
rather than at each call site — casting per call site is how a session with chat
switched off ends up allowing chat.
