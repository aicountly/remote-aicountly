# Staging deployment and first real-server TURN tests — Remote and Connect (shared)

Identical in `aicountly/remote-aicountly` and `aicountly/connect-aicountly`.

> **Nothing in this document has been executed against a server.** It was prepared from a build sandbox with no access
> to the OVH/WHM host, the GitHub Actions secrets, the relay nodes or any database. Every command below is a
> *prepared* command for the operator. No production system, secret or DNS record is touched by any of it; the
> sandbox deploy workflows now **refuse** a production target (§2).

Scope of this round: deploy the integration branch `claude/webrtc-turn-ice-integration` to the **existing sandbox
accounts**, in **`observe`** mode, with **distinct per-node secrets**, a **staging** shared capacity ledger, and run the
first authenticated relay tests. **Not** in scope: merging, production deployment, enabling enforcement, enabling
Greater Noida in production.

## 0. Status labels

| Label | Meaning |
|---|---|
| **Done** | exists in the repositories and was tested (named) |
| **Prepared** | written, reviewed, not run against the real hosts |
| **Needs you** | a decision, access or value only the operator has |

## 1. Pull requests (draft)

| Repo | PR | Head |
|---|---|---|
| Remote | [aicountly/remote-aicountly#29](https://github.com/aicountly/remote-aicountly/pull/29) — draft | `claude/webrtc-turn-ice-integration` |
| Connect | [aicountly/connect-aicountly#33](https://github.com/aicountly/connect-aicountly/pull/33) — draft | `claude/webrtc-turn-ice-integration` |

Neither is merged. The sandbox workflows are `workflow_dispatch` only and take the branch to deploy from the *Run
workflow* dialog (or `--ref`), so the sandbox can run the branch without merging it.

## 2. Where staging goes — what was inspected, and the guard

Inspected: the two sandbox workflows (`remote-aicountly/.github/workflows/deploy-sandbox.yml`,
`connect-aicountly/.github/workflows/deploy-sandbox-cpanel.yml`) and the production ones they sit beside.

| | Remote | Connect |
|---|---|---|
| Existing sandbox site | `https://remote.gh.aicountly.com` | `https://connect.gh.aicountly.com` |
| Workflow | *Deploy to cPanel Sandbox* (`deploy-sandbox.yml`, manual) | *Deploy to cPanel via SSH (manual Sandbox)* (`deploy-sandbox-cpanel.yml`, manual, protected environment `sandbox`) |
| SSH account | secret `SANDBOX_SSH_USER`, host `SANDBOX_SSH_HOST` | secret `SANDBOX_SFTP_USER`, host `SANDBOX_SFTP_HOST` |
| Document root (web build) | secret `SANDBOX_SSH_REMOTE_ROOT` | secret `SANDBOX_SFTP_REMOTE_ROOT` |
| **API folder** | `<root>/api` (backend rsynced there; `.env` in it) | `<root>/api` (`server-php` rsynced there; `.env` in it) |
| **Production (must never be touched)** | `/home/remoteaicountly/public_html` | `/home/connectaicountly/public_html` |

**Final staging deployment paths** = `<value of the SANDBOX_*_REMOTE_ROOT secret>` and `<…>/api`. Their *values* are
GitHub secrets that could not be read from where this was prepared, so the literal paths are **Needs you: confirm with
§2.2 before the first deploy**. The sandbox is, by construction, a different cPanel account (it serves the
`.gh.aicountly.com` names) — but "by construction" is a convention, and a mistyped secret is exactly how a sandbox
workflow's `rsync --delete` lands on production. So:

### 2.1 Guard (Done — tested)

`scripts/ci/sandbox-target-guard.sh <ssh-user> <remote-root>` now runs as the **first** step of both sandbox workflows
(Remote: inside *Validate deployment configuration*; Connect: *Refuse a production deploy target*). It exits non-zero
— before anything is built or sent — if the SSH user is `remoteaicountly` or `connectaicountly`, or the root is inside
`/home/remoteaicountly` or `/home/connectaicountly` (case-insensitive; more accounts can be added with
`PRODUCTION_ACCOUNTS`, the two defaults cannot be removed). Tests: `SandboxTargetGuardTest` in both repos.

### 2.2 Confirm the real sandbox location (read-only; run once, from your machine)

```bash
# Uses the same SSH details the workflow uses. Prints nothing secret. Expected: a user that is NOT remoteaicountly /
# connectaicountly, and a path under that user's own home.
ssh <sandbox-user>@<sandbox-host> 'id -un; echo "$HOME"; readlink -f "<SANDBOX_*_REMOTE_ROOT>"; test -f "<root>/api/.env" && echo "api/.env present" || echo "api/.env MISSING"'
```

If the first line is a production account, stop and fix the `SANDBOX_*` secrets — the workflow would now refuse anyway.

## 3. Secrets (names only — never write values into a repository, chat or log)

| Secret (both repos) | What | Notes |
|---|---|---|
| `SANDBOX_TURN_MUM_SECRET` | `static-auth-secret` of the **Mumbai** coturn | Exists. One value per **node**, identical in both repos (it is the node's secret) |
| `SANDBOX_TURN_NCR_SECRET` | `static-auth-secret` of the **Noida** coturn | Exists. **Must differ** from Mumbai's — `inject-turn-secrets.sh` refuses two equal node secrets |
| `SANDBOX_TURN_LEDGER_PASSWORD` | password of the staging ledger role `turn_ledger_staging` | **New.** Must differ from every TURN secret (the injector refuses otherwise, even with `--allow-shared`) |

Production secrets (`TURN_*` without `SANDBOX_`) are untouched, and **must not be reused** for staging: staging should have
its own coturn secrets if the nodes can run a second realm; if the same physical coturn serves both, they necessarily
share the node secret — record that as an accepted risk, don't hide it.

Generating and installing without ever printing (run on a trusted admin machine with `gh` authenticated):

```bash
umask 077
d=$(mktemp -d); trap 'shred -u "$d"/* 2>/dev/null; rmdir "$d"' EXIT
openssl rand -hex 32 > "$d/ncr"                       # 64 hex chars: inside the injector's accepted alphabet
openssl rand -hex 32 > "$d/ledger"
# Noida's secret: put "$d/ncr" into the node's turnserver.conf (static-auth-secret=…) by the infrastructure team's
# own secure channel, restart coturn on that node, THEN store it:
gh secret set SANDBOX_TURN_NCR_SECRET      --repo aicountly/remote-aicountly  < "$d/ncr"
gh secret set SANDBOX_TURN_NCR_SECRET      --repo aicountly/connect-aicountly < "$d/ncr"
gh secret set SANDBOX_TURN_LEDGER_PASSWORD --repo aicountly/remote-aicountly  < "$d/ledger"
gh secret set SANDBOX_TURN_LEDGER_PASSWORD --repo aicountly/connect-aicountly < "$d/ledger"
# The ledger password goes into PostgreSQL in §4 from the same file; nothing is echoed.
```

For Mumbai: keep the existing `SANDBOX_TURN_MUM_SECRET` if it already equals the node's secret (verify with the probe in
§7.1 — an `auth_rejected` on allocation means it does not). Rotate with the same pattern.

How the secrets reach the API host (**Done — tested**, `InjectTurnSecretsTest`): the workflow step *TURN relay secrets
(stdin only)* pipes `KEY=value` lines to `api/bin/ops/inject-turn-secrets.sh` over SSH stdin. It accepts only
`WEBRTC_TURN_STATIC_AUTH_SECRET`, `WEBRTC_TURN_NODE_<ID>_SECRET` and `WEBRTC_TURN_LEDGER_PASSWORD`; refuses placeholders and
parser-breaking characters; refuses duplicates; ignores empty values (an unset secret never blanks a working `.env`);
prints key names only; keeps the file's owner and mode.

## 4. The shared capacity ledger (PostgreSQL) — staging

Remote and Connect book capacity on the **same physical relays**, so their lease books must be one set of rows
(`lib/ice-kit/sql/turn_capacity.sql`; design: `docs/ACTIVE_ACTIVE_TURN.md`). With a file ledger per product the
50 % paid reserve is two independent promises that can add up to more than the node has.

**Staging gets its own ledger database** — `aicountly_turn_staging` — not the one production will use. Reason: staging
traffic is real traffic on the same nodes; until production adopts the ledger, production's books don't know about it,
and the admission code already treats load it cannot explain as *both* tiers' (conservative). Once production moves to the
shared ledger, staging and production must book against **one** ledger or their reservations are again separate.

**Needs you** (PostgreSQL administrator on the WHM server; **assumption** that the server's PostgreSQL listens on
`127.0.0.1:5432` and is reachable from the sandbox cPanel accounts — confirm; if not, set `WEBRTC_TURN_LEDGER_DSN` to the
right host):

```bash
sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
CREATE ROLE turn_ledger_staging LOGIN PASSWORD '$(cat "$d/ledger")';   -- the file from §3; do not echo
CREATE DATABASE aicountly_turn_staging OWNER turn_ledger_staging;
SQL
# schema, as the ledger role:
PGPASSWORD="$(cat "$d/ledger")" psql -v ON_ERROR_STOP=1 -h 127.0.0.1 -U turn_ledger_staging -d aicountly_turn_staging \
    -f backend/lib/ice-kit/sql/turn_capacity.sql          # Connect checkout: server-php/lib/ice-kit/sql/turn_capacity.sql
```

The role owns only that database and has no access to any product database. Idempotent: safe to run again.
If the ledger is unreachable: paid users are still served (unbooked), free users are refused (`ledger_unavailable`) **only while
enforcing**; in `observe` nobody is refused and the failure is logged.

## 5. Observe-mode configuration (Prepared)

`docs/staging/turn-observe.remote.env` (Remote) and `docs/staging/turn-observe.connect.env` (Connect): the non-secret
settings — both nodes, regions, placeholder capacities, `WEBRTC_TURN_ADMISSION=observe`, the staging ledger DSN, the
licence mapping. Applied by `api/bin/ops/apply-turn-config.sh` (Done — tested, `ApplyTurnConfigTest`): only
`WEBRTC_TURN_/STUN_/GEO_` keys; refuses anything secret-looking; refuses `enforce` without `--allow-enforce`; with
`--keep-out=ncr` refuses to make Noida active.

* **Distinct node secrets**: `WEBRTC_TURN_NODE_MUM_SECRET` and `WEBRTC_TURN_NODE_NCR_SECRET`, never `WEBRTC_TURN_STATIC_AUTH_SECRET`
  (which would give both nodes one secret). `probe.php --status` prints a `warning:` if two nodes share one.
* **Noida stays out of production.** In the *staging* file Noida is `active`, so it can be validated; the production `.env`
  is not changed by anything here, and the production deploy must pass `--keep-out=ncr`.
* **Capacities are placeholders** (assumption). In observe they only size the books and the reconciliation report.
* **Licence**: `WEBRTC_TURN_LICENCE_FEED` is deliberately **unset**, so even if someone later flips the mode to `enforce` the
  readiness gate holds it at `observe` (`enforceBlockedBy`). See `docs/LICENCE_ENTITLEMENT_AUDIT.md`.

## 6. Deployment sequence

Every step says who runs it and how to tell it worked. **Stop at the first failure.**

| # | Step | Who | Check |
|---|---|---|---|
| 1 | Confirm the sandbox location (§2.2) | operator | not a production account |
| 2 | Create the staging ledger database and schema (§4) | DB admin | `psql … -c '\dt'` lists `turn_leases`, `turn_node_load`, `turn_capacity_config` |
| 3 | Generate and store the secrets (§3); put Noida's new secret on the Noida coturn and restart it | infra + operator | `gh secret list --repo …` shows the three names |
| 4 | **Deploy Remote sandbox** from the branch (below) | operator | workflow green; guard step passed; *Verify the live app* passed |
| 5 | Apply the observe config to Remote's `api/.env` (below) | operator | each key `written` / `already current`; no `Refused` |
| 6 | **Deploy Connect sandbox** from the branch (below; approve the `sandbox` environment gate) | operator | workflow green |
| 7 | Apply the observe config to Connect's `api/.env` | operator | same |
| 8 | Both products: `probe.php --status` (§7.1) | operator | 2 nodes, no warnings, `enforceBlockedBy` non-empty, ledger reachable |
| 9 | `reconcile.php --register` for each product (§7.5) | operator | no `config_mismatch` |
| 10 | Tests §7 | operator + test machine | per test |

```bash
# Step 4 — Remote. Starts the workflow for the branch (does not merge anything). Add -f migrate=skip for a first deploy
# before api/.env exists. run_ci_gate=true runs the full suite on GitHub first.
gh workflow run deploy-sandbox.yml --repo aicountly/remote-aicountly --ref claude/webrtc-turn-ice-integration \
   -f run_ci_gate=false -f migrate=apply
gh run watch --repo aicountly/remote-aicountly

# Step 5 — Remote observe config (run from a checkout of the branch; stdin only; <ROOT> = the sandbox document root)
ssh <sandbox-user>@<sandbox-host> "bash <ROOT>/api/bin/ops/apply-turn-config.sh" < docs/staging/turn-observe.remote.env

# Step 6 — Connect
gh workflow run deploy-sandbox-cpanel.yml --repo aicountly/connect-aicountly --ref claude/webrtc-turn-ice-integration
gh run watch --repo aicountly/connect-aicountly

# Step 7 — Connect observe config
ssh <sandbox-user>@<sandbox-host> "bash <ROOT>/api/bin/ops/apply-turn-config.sh" < docs/staging/turn-observe.connect.env
```

The workflows also re-inject the three secrets on every run (idempotent). The `.env` must exist before step 5
(first deploy: create it from `.env.example` on the host, then rerun the deploy).

## 7. Tests on the API host (real, authenticated)

Prerequisites: SSH to the sandbox API host. In every command: `PHP=/opt/cpanel/ea-php83/root/usr/bin/php` (any CLI with
`openssl`, sockets and `pdo_pgsql`), `cd <ROOT>/api`. Commands print no secret. Files that contain **live credentials**
(`--emit-grant`) are `0600`, valid ≤ 15 minutes; copy with `scp`, delete afterwards.

### 7.1 First executable commands — configuration, ledger and authenticated allocation

```bash
cd <ROOT>/api; PHP=/opt/cpanel/ea-php83/root/usr/bin/php

# (a) What the API would serve; ledger reachable; licence gate state.
$PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --status
#   expect: relay configured: yes · mum active · ncr active (staging) · no `warning:` · admission mode observe ·
#           enforceBlockedBy lists the licence reasons · capacity/reservations read from the PostgreSQL ledger

# (b) Authenticated allocation + negative tests + certificate + address, per node (real coturn, real credentials)
$PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=mum --allocate --negative --expect-ip=mum=148.135.210.65 --json > /tmp/evidence-mum.json; echo "exit=$?"
$PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=ncr --allocate --negative --expect-ip=ncr=103.69.196.150 --json > /tmp/evidence-ncr.json; echo "exit=$?"
```

Pass criteria are the table in `docs/TURN_VALIDATION_RUNBOOK.md` Part A2 (UDP/TCP/TLS allocation OK; certificate verified
and ≥ 14 days; wrong-secret, expired and anonymous credentials all `REJECTED`; exit 0). Infra reports both nodes run
coturn 4.18.0-1.el9, reachable over valid TLS 443, and that Noida's self-IP peer denial is corrected and coturn was
restarted — **that is the infrastructure team's statement; (b) is the first test that verifies it from the API host.**
`auth_rejected` on the allocation = the secret in `.env` is not the node's. `open_relay` / `ACCEPTED` on a negative test = a
security finding: stop.

### 7.2 Remote desktop sessions (authenticated, end to end)

Needs a staging company with a **paid test entitlement** and a **free** one. Insert once (staging database only):

```sql
-- paid test company: STAGING_PAID_TEST is in WEBRTC_TURN_PAID_PLAN_CODES (staging only; a test fixture, not a real plan)
INSERT INTO remote_entitlements (company_id, plan_code, valid_from, valid_until)
VALUES (<paid_company_id>, 'STAGING_PAID_TEST', now() - interval '1 day', now() + interval '30 days')
ON CONFLICT (company_id) WHERE company_id IS NOT NULL DO UPDATE SET plan_code = EXCLUDED.plan_code, valid_from = EXCLUDED.valid_from,
  valid_until = EXCLUDED.valid_until, updated_at = now();
-- free test company: no row at all (the platform default row is NOT used for tier), or plan_code = 'REMOTE_FREE'
```

Then, with `WEBRTC_FORCE_RELAY=true` in staging's `.env` (never production's) so every connection is relay-only, follow
`TURN_VALIDATION_RUNBOOK.md` Part C (rows 1–10), which covers a browser-to-browser session, the Windows desktop agent
(unattended connect, control, reconnect, long-session credential), expiry, reconnect and rate limits. The API checks (token
read from the environment, never typed on the command line):

```bash
# SESSION = a session uuid created in the UI by a company member; AIC_TOKEN = that member's staging bearer token (export it
# in your shell; `read -rs AIC_TOKEN; export AIC_TOKEN`). Prints structure only.
curl -sS -X POST "https://remote.gh.aicountly.com/api/v1/remote/sessions/$SESSION/ice-servers" \
     -H "Authorization: Bearer $AIC_TOKEN" -H 'Content-Type: application/json' \
     -d '{"region":"in-north","rtt":{"mum":38,"ncr":12}}' \
  | jq '.data | {contract, relayNodes: [.relayNodes[]|{id,proximity,load}], admission, servers: [.iceServers[]|{urls, hasCredential: has("credential")}]}'
#   expect: contract "aicountly.ice/1"; relayNodes ordered ncr first for this client; admission.tier "paid" for the paid
#           company, "free" for the other; admission.mode "observe"; admission.outcome "admitted"; hasCredential true on TURN
#   no token / not admitted:  401 / 403 AWAITING_APPROVAL — the same call as an un-admitted joiner must be 403

# Books after a session: the lease appears in the SHARED ledger, tagged product=remote and its tier
$PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --status | sed -n '/reserved/,$p'
$PHP lib/ice-kit/bin/summarize-reports.php writable/logs/log-$(date +%F).log
```

### 7.3 Connect calls and rooms (authenticated)

Insert a paid and a free test user (staging database; `source = 'manual'`):

```sql
INSERT INTO connect_license_tier (user_uuid, tier, valid_until, source, updated_at)
VALUES ('<paid_user_id>', 'paid', now() + interval '30 days', 'manual', now())
ON CONFLICT (user_uuid) DO UPDATE SET tier = 'paid', valid_until = EXCLUDED.valid_until, updated_at = now();
-- the free user needs no row
```

```bash
# AIC_TOKEN = the staging bearer token of that user (the portal session); read -rs, never an argument.
curl -sS "https://connect.gh.aicountly.com/api/calls/config?region=in-west&rtt%5Bmum%5D=12&rtt%5Bncr%5D=41" \
     -H "Authorization: Bearer $AIC_TOKEN" \
  | jq '.data | {contract, turnConfigured, expiresIn, relayNodes: [.relayNodes[]|{id,proximity,load}], admission}'
#   paid user -> admission.tier "paid"; free user -> "free"; mum first for this client; outcome "admitted"; mode "observe"
# A meeting room: the participant token the room join returned (header X-Room-Participant), not the bearer; a guest is accounted to the HOST's tier:
curl -sS -X POST "https://connect.gh.aicountly.com/api/rooms/$ROOM/ice" -H "X-Room-Participant: $ROOM_PARTICIPANT_TOKEN" -H 'Content-Type: application/json' -d '{"region":"in-north"}' | jq '.data | {contract, relayNodes: [.relayNodes[]|{id}], admission}'
# The client reports how it connected (moves the bandwidth charge to the relay it really uses; zero for a direct path):
curl -sS -X POST "https://connect.gh.aicountly.com/api/calls/$CALL/connectivity-report" -H "Authorization: Bearer $AIC_TOKEN" \
     -H 'Content-Type: application/json' -d '{"outcome":"connected","path":"relay","relayNode":"mum","relayProtocol":"udp"}'
```

Real calls: `docs/WEBRTC_TURN_SETUP.md` ("Testing") with `WEBRTC_FORCE_RELAY=true` on staging, and
`docs/MOBILE_CALL_TEST_MATRIX.md` for the mobile app. A call is relayed when the Security/diagnostics panel names the node and
protocol, and `connect.ice.issue` lines appear in `writable/logs`.

### 7.4 Regional selection

```bash
# What each tier would be offered from each region, and why — never changes the books:
$PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --status --explain
#   expect (shape): free client in in-west  -> mum,ncr ; in-north -> ncr,mum ; no region -> by priority
#   proximity basis is `measured` once a client reports rtt, `learned` after >= 5 clients of a region, else `geo` / `region`
```

Real clients: open the staging site from a Mumbai-region network and from an NCR-region network; the diagnostics panel / the
`relayNodes[0].id` of the API answers above must name the nearer node. The browser measures RTT itself (`LatencyMeter`) and
sends it; `curl` with a made-up `rtt` only tests the server's ordering. Record region, node chosen and measured RTT
(Runbook Part G).

### 7.5 Bandwidth measurements

```bash
# On each TURN node (infrastructure team, unprivileged; read-only): measured allocations and NIC rate, one JSON line
bash turn-node-load.sh --node=mum --sample=30           # idle (B0), then with k relayed sessions (Runbook F1)

# Feed it to the shared ledger from the API host (cron in production; by hand now):
ssh turn-load@turn-mum.aicountly.com | $PHP lib/ice-kit/bin/ingest-load.php --bootstrap=config/ice-kit.php

# Books vs. reality, per product, registers this product's view of each node's capacity first:
$PHP lib/ice-kit/bin/reconcile.php --bootstrap=config/ice-kit.php --product=remote --register   # then --product=connect on Connect's host
$PHP lib/ice-kit/bin/reconcile.php --bootstrap=config/ice-kit.php --product=remote --usage-log=<coturn log> --window=3600 --json
```

`config_mismatch` between the two products' registrations is the finding that the reserve is not guaranteed. The per-tier
byte shares need coturn's `usage:` lines (`verbose`), which the relay writes per closed allocation; the tier is the signed
`p.` / `f.` prefix of the username. Saturation and the tier-isolation load test: `docs/TURN_BANDWIDTH_ENFORCEMENT.md` §5 — requires
the tier-split relays (a proposal for the infrastructure team; **not** needed for observe-mode product testing).

### 7.6 Failover tests

Staging only, with `WEBRTC_FORCE_RELAY=true`; restores are mandatory. No TURN server is touched: only the staging `.env` and probe.

```bash
# F1 — a peer becomes unreachable (Mumbai's port closed in the staging .env, then probed twice so health records it):
printf 'WEBRTC_TURN_NODE_MUM_PORT=3499\nWEBRTC_TURN_NODE_MUM_TLS_PORT=3499\n' | bash bin/ops/apply-turn-config.sh
for i in 1 2; do $PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --allocate --write-health; done
$PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --status --explain      # mum unreachable; every region -> ncr only
#   start a session from a Mumbai-region network: the panel and `relayNodes[0].id` name ncr; /health shows mum unreachable
printf 'WEBRTC_TURN_NODE_MUM_PORT=3478\nWEBRTC_TURN_NODE_MUM_TLS_PORT=443\n' | bash bin/ops/apply-turn-config.sh   # RESTORE
$PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --allocate --write-health                            # mum recovers

# F2 — the other direction: set WEBRTC_TURN_NODE_NCR_STATUS=disabled, probe, confirm everything goes to mum, then restore `active`.
# F3 — mid-session: with a relayed session open, F1 the node the session uses; the client must ICE-restart onto the other node
#      (diagnostics: `restarts >= 1`, outcome `recovered`) — runbook Part C row 5/6.
```

Capacity-spill and denial tests (Runbook D2) need `enforce`, which is **blocked on purpose** until the licence mappings and feed are
complete; in `observe` the books show what *would* have been refused (`reason` in the audit log), which is the data to collect.

## 8. Rollback / teardown (staging)

* Mode: `printf 'WEBRTC_TURN_ADMISSION=off\n' | bash bin/ops/apply-turn-config.sh` (no books, no limits).
* Ledger: `DROP DATABASE aicountly_turn_staging; DROP ROLE turn_ledger_staging;` — staging only. Without the DSN the products fall back to their file books.
* Remove `WEBRTC_FORCE_RELAY` from staging's `.env` when finished.
* Delete grant files (`shred -u /tmp/grant-*.json /tmp/evidence-*.json`).
* Production was never changed by any step.

## 9. Remaining blockers before enforcement or production

See the PR descriptions and `docs/LAUNCH_READINESS_TURN.md` §1a. In short: licence mapping and feed (`docs/LICENCE_ENTITLEMENT_AUDIT.md`);
measured capacities (Runbook F); tier-split relays + shaper + TLS-443 decision (`docs/TURN_BANDWIDTH_ENFORCEMENT.md`); Noida security
clearance; production ledger adoption; the node-side collector and probe cron jobs installed.
