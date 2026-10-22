# Staging deployment and first real-server TURN tests — Remote and Connect (shared)

Identical in `aicountly/remote-aicountly` and `aicountly/connect-aicountly`.

> **Nothing in this document has been executed against a server.** It was prepared from a build sandbox with no access
> to the OVH/WHM host, the GitHub Actions secrets, the relay nodes or any database. Every command below is a
> *prepared* command for the operator. No production system, secret or DNS record is touched by any of it; the
> sandbox deploy workflows now **refuse** a production target (§2).

**Licensing does not gate this work.** The central licensing module is under development (`docs/LICENCE_ENTITLEMENT_CONTRACT.md`). Staging
deployment, authenticated relay tests, regional selection, failover and bandwidth measurements all run in `observe` mode today, using
**signed fixtures** (§7.0) or the development-time compatibility mapping for the paid/free split. Only *enforcement* waits for the
central source; the hard 50 % paid reservation across Remote and Connect (shared ledger) and the Mumbai/NCR routing are exercised
regardless.

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

### 2.3 Preflight (read-only; run this first)

`scripts/staging/turn-preflight.sh` checks, changing nothing, that the repository secrets exist (by name), that the target is not production, that the sandbox API
host has what the kit needs (PHP with sodium, pdo_pgsql, openssl, curl; `api/.env`; admission not `enforce`; the kit deployed; `probe.php --status` showing a configured relay
and no warnings) and that both TURN nodes resolve, accept TCP on 3478 and 443 and present a verified TLS certificate with at least 14 days left. `--print` shows what it would run.

```bash
scripts/staging/turn-preflight.sh --repo aicountly/remote-aicountly --product remote  --ssh <sandbox-user>@<sandbox-host> --root <SANDBOX_SSH_REMOTE_ROOT>
scripts/staging/turn-preflight.sh --repo aicountly/connect-aicountly --product chat   --ssh <sandbox-user>@<sandbox-host> --root <SANDBOX_SFTP_REMOTE_ROOT>
```

Before the first deploy, §3–§4 items will FAIL (secrets, ledger, kit not yet deployed) — that is the to-do list. After §6 step 7 every line must PASS.

## 3. Secrets (names only — never write values into a repository, chat or log)

| Secret (both repos) | What | Notes |
|---|---|---|
| `SANDBOX_TURN_MUM_SECRET` | `static-auth-secret` of the **Mumbai** coturn | Exists. One value per **node**, identical in both repos (it is the node's secret) |
| `SANDBOX_TURN_NCR_SECRET` | `static-auth-secret` of the **Noida** coturn | Exists. **Must differ** from Mumbai's — `inject-turn-secrets.sh` refuses two equal node secrets |
| `SANDBOX_LICENCE_S2S_SECRET` | this product's AIC-S2S v1 secret for the **central licence API** | **Not yet** — the API does not exist. The workflows already pass it over stdin when set (empty = ignored). Never reuse a TURN or ledger secret (the injector refuses) |
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
* **Licence**: no central API exists yet, so `WEBRTC_LICENCE_API_URL` is unset and nothing syncs. The staging file sets
  `WEBRTC_LICENCE_COMPAT=allow` (the legacy mapping may label the books) and leaves `WEBRTC_TURN_LICENCE_FEED` unset, so even if someone
  flips the mode to `enforce` the readiness gate holds it at `observe` (`enforceBlockedBy` lists six conditions). See
  `docs/LICENCE_ENTITLEMENT_CONTRACT.md` §10.

## 6. Deployment sequence

Every step says who runs it and how to tell it worked. **Stop at the first failure.**

| # | Step | Who | Check |
|---|---|---|---|
| 1 | Confirm the sandbox location (§2.2) and run the preflight (§2.3) | operator | not a production account; the preflight's to-do list is what steps 2–7 clear |
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
   -f run_ci_gate=false -f migrate=apply -f turn_config=apply -f turn_check=true
# turn_config=apply writes docs/staging/turn-observe.remote.env (non-secret, observe) into api/.env — steps 5/7 below, done by the workflow;
# turn_check=true runs scripts/ci/turn-staging-check.sh on the server after the deploy (probe status + authenticated allocation and negative
# tests per node). Each result is an ANNOTATION on the run, readable without the raw log:
#   gh api repos/aicountly/remote-aicountly/check-runs/<job id>/annotations --jq '.[] | "\(.annotation_level) \(.title)\n\(.message)\n"'
gh run watch --repo aicountly/remote-aicountly

# Step 5 — Remote observe config (run from a checkout of the branch; stdin only; <ROOT> = the sandbox document root)
ssh <sandbox-user>@<sandbox-host> "bash <ROOT>/api/bin/ops/apply-turn-config.sh" < docs/staging/turn-observe.remote.env

# Step 6 — Connect
gh workflow run deploy-sandbox-cpanel.yml --repo aicountly/connect-aicountly --ref claude/webrtc-turn-ice-integration \
   -f turn_config=apply -f turn_check=true      # waits for approval of the protected `sandbox` environment
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

### 7.0 Paid and free test identities — signed fixtures (no central backend needed)

Licensing is **contextual**: a test identity is a *user in a company*, not a user. The real path (Ed25519 signature, freshness, tenant check,
membership, seat, revocation) can be exercised before the licensing module exists with **signed staging fixtures**. Generate a staging key pair
on an admin machine — the private half never goes to the API host — and give the API host only the public half:

```bash
# admin machine (needs PHP with libsodium)
php backend/lib/ice-kit/bin/entitlement-fixture.php keys --out=$HOME/.aic-staging-keys --kid=stg-1
#   prints:  WEBRTC_LICENCE_PUBLIC_KEYS entry: stg-1=<base64>
printf 'WEBRTC_LICENCE_PUBLIC_KEYS=stg-1=<base64>\n' | ssh <sandbox> "bash <ROOT>/api/bin/ops/apply-turn-config.sh"
K="--secret-key-file=$HOME/.aic-staging-keys/stg-1.secret --kid=stg-1"
F=backend/lib/ice-kit/bin/entitlement-fixture.php

# REMOTE: company 481 holds a company-wide paid licence (a session is licensed in its company, owner = the acting user)
php $F sign $K --product=remote --customer=481 --state=paid --plan=STAGING_PAID_TEST --out=/tmp/remote-481.json
# CONNECT, company-wide: company 42 is paid; user 7 is a CURRENT member of 42 (a member-level document states membership)
php $F sign $K --product=chat --customer=42 --subject=7 --member=true --state=paid --out=/tmp/chat-42-u7.json
# CONNECT, seat licence: company 43 has seats; user 8 holds one, user 9 is a member without a seat
php $F sign $K --product=chat --customer=43 --state=paid --model=seat --out=/tmp/chat-43.json
php $F sign $K --product=chat --customer=43 --subject=8 --member=true --state=paid --model=seat --seat=assigned --out=/tmp/chat-43-u8.json
php $F sign $K --product=chat --customer=43 --subject=9 --member=true --state=paid --model=seat --seat=unassigned --out=/tmp/chat-43-u9.json
# CONNECT, personal licence: user 10's own, separate from any company
php $F sign $K --product=chat --scope=user --subject=10 --state=paid --plan=STAGING_PERSONAL --out=/tmp/chat-personal-u10.json
scp /tmp/{remote,chat}-*.json <sandbox>:/tmp/        # signed, not secret; delete afterwards

# on the API host
for f in /tmp/remote-481.json /tmp/chat-42-u7.json /tmp/chat-43.json /tmp/chat-43-u8.json /tmp/chat-43-u9.json /tmp/chat-personal-u10.json; do
  $PHP lib/ice-kit/bin/entitlement-fixture.php load --bootstrap=config/ice-kit.php --file=$f; done
```

Then **resolve contexts** — the decision a credential request would make, with no network and no session:

```bash
R="$PHP lib/ice-kit/bin/entitlement-fixture.php resolve --bootstrap=config/ice-kit.php"
$R --product=chat --customer=42 --subject=7            # state paid  · tier paid · reason paid · basis company_wide · evidence central
$R --product=chat --customer=44 --subject=7            # company 44 (user 7 "switched"): state unknown · tier free · no_entitlement   <- nothing carries over
$R --product=chat --subject=7                          # personal context: unknown · free                                              <- company 42's licence is not 7's personal licence
$R --product=chat --customer=43 --subject=8            # seat assigned: paid · basis seat
$R --product=chat --customer=43 --subject=9            # seat_unassigned: free
$R --product=chat --customer=43 --subject=7            # user 7 is not in company 43: no_entitlement / seat_not_assigned: free
$R --product=chat --subject=10                         # personal licence: paid · basis personal
$R --product=chat --customer=42 --subject=10           # user 10 inside company 42: their personal licence is NOT carried in: free
$R --product=remote --customer=481                     # a Remote session's company: paid · company_wide

# revocation: a seat revoked / a member removed ends THAT pair only, immediately
$PHP lib/ice-kit/bin/entitlement-fixture.php revoke --bootstrap=config/ice-kit.php --product=chat --customer=43 --subject=8 --revision=1
$R --product=chat --customer=43 --subject=8            # suspended · reason revoked
```

`sign --state=` also takes `free`, `expired`, `suspended`, `unknown`; `--kind=trial` shows a trial refused the paid reserve; `--member=false` a
removed member; `--entitlement=meeting.max_guests=1` sets a meeting limit. Fixtures are `evidence central` because they are genuinely signed; they
prove the consumer, not the backend. The alternative for a quick check is the compat mapping, labelled `evidence compat`, which can never support enforcement
(Remote: a `remote_entitlements` row on `WEBRTC_TURN_PAID_PLAN_CODES`; Connect: a `connect_license_tier` row, honoured **only in a personal context**).

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

Needs a staging company with a **paid test entitlement** and a **free** one: load the signed fixture for the paid company (§7.0). The session is licensed in **its company's context with its owner as the acting user**, so the owner must be a current member of that company in Remote (an access row, confirmed by Manage within the grace period — in staging `remote.membershipEnforcement` decides) — or, for a quick
check only, give it a compat row (`WEBRTC_TURN_PAID_PLAN_CODES=STAGING_PAID_TEST` is in the staging file, and a staging `remote_entitlements` row with
that `plan_code` and the company id is accepted as *compat*). The free company needs nothing. The company ids must be the real staging company ids.

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

A paid test user is a user **in a company**: load the signed member-level fixtures of §7.0 (user 7 in company 42; user 8 holds a seat in company 43).
The client names its company with `?company_id=` — a *claim* the document must confirm; omit it for the personal context. The same user asking under a
different `company_id` is recalculated from that company's own evidence (the switching check below). For a quick check only, a `connect_license_tier` row
(`user_uuid`, `tier = 'paid'`, `source = 'manual'`) is accepted as *compat* **in the personal context only**.

```bash
# AIC_TOKEN = the staging bearer token of that user (the portal session); read -rs, never an argument.
curl -sS "https://connect.gh.aicountly.com/api/calls/config?company_id=42&region=in-west&rtt%5Bmum%5D=12&rtt%5Bncr%5D=41" \
     -H "Authorization: Bearer $AIC_TOKEN" \
  | jq '.data | {contract, turnConfigured, expiresIn, relayNodes: [.relayNodes[]|{id,proximity,load}], admission}'
#   user 7 with company_id=42 -> admission.tier "paid"; the SAME user with company_id=44 (a switch) or with no company_id -> "free";
#   mum first for this client; outcome "admitted"; mode "observe".  `connect.ice.issue` in writable/logs records licence/licenceReason/licenceBasis/licenceEvidence.
# A meeting room is licensed under the company it was created with (POST /api/v1/rooms {..., "company_id": "42"}); the HOST is evaluated in that company,
# and an external guest is served under the host's licensed meeting (no account upgraded). The participant token the room join returned (header X-Room-Participant), not the bearer; a guest is accounted to the HOST's tier:
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

See the PR descriptions and `docs/LAUNCH_READINESS_TURN.md` §1a. In short: the central licensing backend and its connection to both products (`docs/LICENCE_ENTITLEMENT_CONTRACT.md`, `docs/LICENCE_ENTITLEMENT_AUDIT.md` §3);
measured capacities (Runbook F); tier-split relays + shaper + TLS-443 decision (`docs/TURN_BANDWIDTH_ENFORCEMENT.md`); Noida security
clearance; production ledger adoption; the node-side collector and probe cron jobs installed.

## 12. Staging run log

### 2026-10-22 — first deployment of the integration branch to the OVH sandbox (Server 1, `server1`)

| Run | Result |
|---|---|
| Remote [39806100771](https://github.com/aicountly/remote-aicountly/actions/runs/39806100771) (`b867a43`, turn_config=apply, turn_check=true) | Deployed (web, API, migrations, observe config). **Verify the live app failed**: the runner was refused by Imunify360 bot-protection (HTTP 403), which `verify-live.sh` did not recognise as the anti-bot page → fixed in `7f2372b` |
| Remote [39806767658](https://github.com/aicountly/remote-aicountly/actions/runs/39806767658) (`7f2372b`) | **Success** — live app verified from the server; TURN check ran |
| Connect [39806772545](https://github.com/aicountly/connect-aicountly/actions/runs/39806772545) (`5f418fe`, turn_config=apply, turn_check=true) | **Success** — web, API, migrations (incl. 16: `connect_license_tier`, `connect_rooms.licence_customer_id`), observe config, live app verified; TURN check ran |

The production-target guard passed in all three runs. Production was not touched.

**Measured from the sandbox API host** (`scripts/ci/turn-staging-check.sh`; identical results from both products):

| | Mumbai `turn-mum.aicountly.com` | Noida `turn-ncr.aicountly.com` |
|---|---|---|
| DNS → expected address | ✅ 148.135.210.65 | ✅ 103.69.196.150 |
| STUN binding UDP 3478 / TCP 3478 / TLS 443 | ✅ 6–14 ms / 5–12 ms / 12–20 ms | ✅ 55–59 ms / 100–116 ms / 232–251 ms |
| TLS certificate on 443 | ✅ verified, Let's Encrypt YE2, valid to 2027-01-19 (88 days) | ✅ verified, Let's Encrypt YE2, valid to 2027-01-19 (88 days) |
| Anonymous allocation refused (UDP/TCP/TLS) | ✅ REJECTED | ✅ REJECTED |
| **Authenticated allocation** | ❌ not run — `no_credentials` | ❌ not run — `no_credentials` |
| Wrong-secret / expired credential refused | ⏸ inconclusive (needs the node secret) | ⏸ inconclusive |

**Why allocation did not run:** the repository secrets `SANDBOX_TURN_MUM_SECRET` and `SANDBOX_TURN_NCR_SECRET` are not set in either
repository (the injector reported "empty, ignored"), so the API has no node secret to mint a credential with (`relay configured: NO`). Nothing was
wrong on the relays: they answer, present valid certificates, and refuse anonymous use.

**Other findings:**
* The sandbox's PHP (ea-php83 8.3.35) has **no `sodium` extension**. TURN does not need it; central licence verification does. Fixed in the kit by a
  fallback to `paragonie/sodium_compat` (verified to agree with ext-sodium on all 45 signed vector envelopes) — but neither product installs that
  package yet. Preferred fix: enable `ea-php83-php-sodium` in WHM → EasyApache 4 (one extension, no code change).
* `WEBRTC_LICENCE_PUBLIC_KEYS` unset, ledger DSN unset (by design until §4), licence resolution `unknown/free` — expected: observe mode, no central source.
* The licence gate holds observe as designed.

**Next run** (after the two node secrets are set — §3): `gh workflow run deploy-sandbox.yml --repo aicountly/remote-aicountly --ref claude/webrtc-turn-ice-integration -f migrate=apply -f turn_config=skip -f turn_check=true`
and the same for Connect. The check then performs the authenticated allocation and the wrong-secret / expired-credential refusals on both nodes.
