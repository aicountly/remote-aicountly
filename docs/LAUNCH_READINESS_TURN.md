# TURN launch readiness — AICOUNTLY Remote

Status of the WebRTC/TURN integration against the real Mumbai (`turn-mum.aicountly.com`, 148.135.210.65) and
Greater Noida (`turn-ncr.aicountly.com`, 103.69.196.150) relays. Companion documents: `docs/ICE_CONTRACT.md`
(contract and behaviour) and `docs/TURN_VALIDATION_RUNBOOK.md` (the commands whose output this document is waiting for).

## Recommendation

**Do not launch TURN-dependent traffic yet.** The code, tooling and deployment pipeline are ready and tested against a
local coturn; the one thing that makes this a launch is missing — an authenticated relay allocation and relayed data
against the real Mumbai node, from the API host and from a browser.

1. **Mumbai only, production:** go when runbook A1–A4 and B pass on Mumbai, `turn-probe` is running, and C passes in
   staging. The expected effort is the runbook plus creating one repository secret.
2. **Greater Noida:** stays `disabled` in production — not `standby`, which still serves. It needs its own A/B/D/F results in staging **and** the security
   clearance. Neither exists today. See § 1a for the active-active and licence-reserve gates.
3. **Capabilities actually tested** (locally only): HMAC credential issuance and rejection, allocation over UDP/TCP/TLS, relay-only Chromium
   connection with data, ICE restart onto renewed credentials, selection and failover logic, secret injection.
   **Assumptions** (untested on real infrastructure): everything in the matrix marked Pending test or Blocked.

## 1a. Revised launch blockers — active-active routing and licence-aware admission

Added by the confirmed architectural decisions (regional peers; ≥ 50 % of every node reserved for paid licences, enforced across fallback).
The design, with what is implemented, proposed and assumed, is `docs/ACTIVE_ACTIVE_TURN.md`; the commands that settle the open items are
`docs/TURN_VALIDATION_RUNBOOK.md` parts D, F and G. **Nothing below has been exercised against Mumbai or Greater Noida.**

| # | Blocker | Status | Evidence / what remains | Gates |
|---|---|---|---|---|
| A1 | Application implements regional-peer routing, tiers, capacity books, admission, release, telemetry ingest, relay-side attribution | **Resolved (tested locally)** | Kit suite 149 tests (admission arithmetic incl. randomised, selection, books under nine racing processes, telemetry, latency, usage log, 20 review-regression tests); Remote API tests for plan → tier, full free pool, release on end/leave, reported direct path, health shape; browser kit 41 tests; both simulations pass with real coturn and Chromium | — |
| A2 | Mumbai authenticated allocation and relayed data (unchanged from § 1, #1) | **Pending test** | Runbook A, B | any production relay traffic |
| A3 | Every capacity number — what a session costs, what a node sustains, where to shed — is an assumption | **Pending test** | Runbook F1, F2. Defaults in `.env.example` are placeholders | `enforce` (not `observe`) |
| A4 | Licence → tier: which Remote plans are paid | **Partially resolved** | Plan → tier is implemented (`WEBRTC_TURN_PAID_PLAN_CODES` against `remote_entitlements.plan_code`; unknown or unlisted = free, tested over HTTP). **Which plans are paid is a business decision nobody has made**; the platform default row seeds `REMOTE_PROFESSIONAL`. | `enforce` |
| A5 | The paid reserve holds under load on the real nodes (a free flood cannot take it; paid inside the reserve is never refused) | **Pending test** | Runbook F5. Local evidence only: `active-active.sh` (four-slot nodes) and the randomised admission test | `enforce` |
| A6 | coturn's own limits on the real build (`total-quota`, `user-quota`, `max-bps`, `bps-capacity`) | **Pending test** | Runbook F3. Tier-blindness of coturn's quota **demonstrated locally** (four free credentials exhaust a `total-quota=4` relay; a paid one is refused) — the reason the application layer exists | recommended before `enforce` |
| A7 | Measured node load reaches the API (`turn-node-load.sh` → `ingest-load.php`) | **Partially resolved** | Collector and ingest tested locally (counted 4 of 4 real allocations). The transport — a forced-command, read-only SSH login per node — is a **proposal** nobody has built. Without it admission runs on the books alone, which drift if clients misreport or sessions leak | recommended before `enforce` |
| A8 | Relay-side attribution of traffic to tiers (the audit that free traffic stayed in its share) | **Partially resolved** | Parser tested on real coturn 4.6.1 lines; a real Chromium's bytes attributed to paid and free locally. The real nodes' log format is unverified (the version is reported as "4.18") — Runbook F6 | recommended |
| A9 | Regional routing quality: edge geography unknown; measured-RTT loop implemented | **Pending test** | Runbook G1. Not a blocker while Noida is out of production (one node = nothing to choose between) | enabling Noida |
| A10 | Regional fallback and capacity spill between Mumbai and Noida | **Blocked** | Needs Noida cleared (A11). Local simulation passes; staging Part D1/D2 are written | enabling Noida |
| A11 | **Greater Noida in production** | **Blocked** | Authenticated tests (Runbook A, B, F1–F3 for ncr) **and** the security review of its historical privileged-access concern, signed off and recorded (G5). Production config keeps `STATUS=disabled` and holds no Noida secret | enabling Noida |
| A12 | Renewals near overload keep a session alive | **Pending test** | Runbook G4. Logic and client behaviour (a denied renewal is never adopted) tested locally | `enforce` |
| A13 | One API host per product assumed (the lease books are a locked file) | **Limitation** | A second API host sharing the same relays needs a shared lease store behind `LeaseStore`; not built. Not a blocker for the current single-host deployment | scaling out |

### Recommendation, revised

1. **Production is Mumbai only.** With one node there is nothing to route between, but the reserve still applies to it.
2. **Start in `WEBRTC_TURN_ADMISSION = observe`.** The books and the audit log run, nothing is denied, and `probe.php --status --explain` shows what `enforce` would have refused.
   Move to `enforce` only when A3 (capacities measured), A4 (a real licence feed) and A5 (the reserve verified under load) are done — enforcing earlier either locks
   people out against invented numbers or, with no licence feed, treats every customer as free.
3. **Greater Noida is enabled in production only by a deliberate change** after A11. Until then it is `disabled`, not `standby`: a standby node is still a node that serves.
4. **What is tested vs assumed:** tested locally — the arithmetic, the selection, the books under concurrency, the clients, the HTTP paths, and everything against real coturn
   *processes* on one machine. Assumed — every number in the capacity model, the real nodes' behaviour, their log format, the edge's geography, the licence feed.

## 1b. Round 3 — shared capacity ledger, bandwidth enforcement, licence gate, staging (2026-10-21)

Added after the confirmed decisions: observe-mode staging first; one capacity ledger for every product that shares the relays; a
technical (not estimated) guarantee for the paid bandwidth; paid status only from trusted, current entitlements. Status labels as in
`docs/ACTIVE_ACTIVE_TURN.md` §0. **Draft PRs only — nothing merged, nothing deployed, no production change.**

| # | Item | Status | Evidence / what remains |
|---|---|---|---|
| B1 | **Cross-product accounting.** Remote and Connect share the same physical nodes; separate file ledgers cannot jointly guarantee the 50 % reserve | **Implemented, tested locally** | `PdoLeaseStore` / `PdoLoadStore` / `CapacityRegistry` over PostgreSQL (`sql/turn_capacity.sql`, one advisory lock): six processes from two "products" racing for a node admit **exactly** its capacity; `reconcile.php` reports `config_mismatch` when products disagree. Ledger outage while enforcing: paid served unbooked, free refused. **Remaining:** create the database/role (staging: `docs/STAGING_TURN_DEPLOYMENT.md` §4), then production adopts the same ledger |
| B2 | Reconcile books with real Coturn usage | **Implemented, tested locally** | `bin/reconcile.php` (report-only): books vs. collector measurement vs. the relay's own usage lines per tier. **Remaining:** runs against real nodes (needs the collector transport and the real log format) |
| B3 | **Bandwidth enforcement** — application estimates alone are insufficient | **Designed; artifacts exercised locally; real nodes untested** | Three layers (admission → two coturn instances with quotas → nftables+HTB shaper). Namespace test: free flood leaves paid at 30.0 of 30 offered; free capped at ~20 of a 40 Mbit link. `docs/TURN_BANDWIDTH_ENFORCEMENT.md`. **Remaining:** infrastructure applies it on a staging node; the three-phase load test with fixed pass criteria; TLS-443 isolation (second IP vs SNI proxy) is an **open decision** |
| B4 | **Licence → tier trusted and current** | **Partially resolved** | Current-entitlement-only reads, validity window and freshness limit in both products; **readiness gate holds `enforce` at `observe`** until the feed is attested and complete. Authoritative source identified (Manage `ghm_subscription`, backed by Engage licences) but **no product-facing endpoint exists** and **no Remote paid plan code exists in any repository** (`docs/LICENCE_ENTITLEMENT_AUDIT.md`). Enforcement stays off |
| B5 | Secure secret/config delivery; staging isolated from production | **Implemented, tested** | `inject-turn-secrets.sh` now also carries the ledger password (never equal to a TURN secret); `apply-turn-config.sh` for everything non-secret (refuses secrets, refuses `enforce` without a flag, can keep Noida out); both sandbox workflows now **refuse a production account/path** (`sandbox-target-guard.sh`) |
| B6 | Infrastructure statement: Mumbai and Noida run coturn **4.18.0-1.el9**, reachable over valid TLS on 443; Noida's self-IP peer denial corrected and coturn restarted | **Reported by infrastructure; not verified by this work** | First verification from the API host: `docs/STAGING_TURN_DEPLOYMENT.md` §7.1(b). The `usage:` log format and `max-bps`/`bps-capacity` semantics were observed on **4.6.1** and are **assumed** to hold on 4.18 |
| B7 | A13 above (one API host assumed) | **Superseded by B1** | The shared ledger removes the single-file limitation |

Still gating `enforce`: A3 (measured capacities), B3 (shaper + load test on a real node), B4 (mappings and feed). Still gating Noida in production: A11.

## 1. Launch-blocker matrix

Status key — **Resolved**: verified, with the evidence named. **Partially resolved**: code or tooling done and
tested, the real-world part is not. **Pending test**: ready to run, not run. **Blocked**: cannot proceed.

*Evidence limits.* This work ran in a sandbox whose DNS cannot resolve `turn-mum.aicountly.com` or
`turn-ncr.aicountly.com`, whose egress allows only an HTTP(S) proxy (no UDP, no raw TCP 3478), and where
port 443 is answered by the egress gateway with its own certificate — **not** by the real relays. Nothing here
has talked to Mumbai or Noida. "Local coturn" below is a coturn 4.6.1 started on the test machine: it validates
the code, not the servers, and is never offered as evidence about them. What the infrastructure team reports
(TLS externally verified, firewalls passed, renewal hooks tested) is taken as reported and was not re-verified.

| # | Original blocker | Status | Evidence / what remains |
|---|---|---|---|
| 1 | Mumbai authenticated allocation untested | **Pending test** | Tooling complete: `probe.php --allocate --negative --expect-ip --expiry-test --emit-grant`. Not run against the real node (sandbox unreachable). Runbook A1–A4. TLS/firewall passing does not imply this. |
| 2 | Same `static-auth-secret` in coturn and both APIs, out-of-band | **Partially resolved** | Per-node secrets; injected over SSH stdin by `bin/ops/inject-turn-secrets.sh` from repository secrets (allow-listed keys, strict value format, duplicate refusal, no value echoed; `InjectTurnSecretsTest`); deploy workflows wired for both products. **Remaining:** create the GitHub secrets (`TURN_MUM_SECRET` prod; `SANDBOX_TURN_MUM_SECRET`, `SANDBOX_TURN_NCR_SECRET` sandbox); a mismatch shows as `auth_rejected` in A1. |
| 3 | TURN/TLS 443 from a browser with the real certificate | **Pending test** | Probe verifies the chain and hostname and reports subject / issuer / SANs / expiry; `relay-check.mjs` with `ICE_EXPECT_PROTOCOL=tls` drives real Chromium. Runbook A1, B. Not run against the real node. |
| 4 | Greater Noida disabled until it passes | **Blocked** (production) / **Pending test** (staging) | Production config has no Noida secret and `STATUS=disabled`, so it cannot be served there. Staging tests are ready (runbook A5, D). Production enablement is gated on the security clearance, which is outside this repository. |
| 5 | cPanel may block outbound 3478 | **Pending test** | Probe tests every transport and records each; UDP/TCP blocked with TLS working reports `degraded` (still served — the TLS URL works for browsers), not `unreachable`. Needs A1 from the real API host. |
| 6 | Degraded nodes are still served; primary failure must reach the standby | **Resolved in logic · Pending on real nodes** | `IceIssuer::select` rewritten: a non-working primary no longer pins the response, standby joins when no active node is fully working, cap applied after ranking, last-resort serves everything flagged `degraded`. Unit cases (`IceIssuerTest`, `TopologyAndHealthTest`) plus a two-coturn local simulation (probe → health file → grant → real Chromium relaying through whichever is served). Not a test of Mumbai/Noida. |
| 7 | Health states distinguishable | **Resolved** | `/api/health` → `relayNodes[].condition` (`healthy`/`degraded`/`unreachable`/`unknown`), `state`, `serving`, role; unit and HTTP tests. |
| 8 | Probe every 5 minutes | **Partially resolved** | `turn-probe` is in `bin/ops/jobs.txt`; the production deploy installs it. **Sandbox deploys do not** — run the probe by hand or add the job there before the failover test (runbook D). Confirm on the host that the job is installed and the health file's mtime moves (checklist). |
| 9 | Desktop agent never renews; long Remote sessions | **Partially resolved** | Measured (local coturn 4.6.1): an *established* allocation survives credential expiry; a *new* one is refused. Fixed server-side: the device-join credential now lasts the rest of the session + 5 min (≤ `WEBRTC_TURN_SESSION_TTL_MAX`, default 8 h; HTTP-tested). **Not done:** agent-side renewal (unverifiable here; webrtc-rs live reconfiguration unconfirmed). Real relay behaviour at expiry: runbook A3 — the result decides whether a >TTL relayed session is a blocker. Default policy (60 min) is under the TTL. |
| 10 | Mobile app never renews | **Partially resolved** | Renewal scheduler implemented and unit-tested (`mobile/tests/iceRenewal.test.ts`); wiring into `CallProvider`/`PeerSession.applyIceConfiguration` covered by typecheck and lint only. **No device test.** |
| 11 | Remote real session with desktop agent + browser | **Pending test** | Not performed (no staging, no Windows agent, no real relay here). Browser-engine behaviour is unit-tested; relay-only ICE, data and restart are proven only against local coturn. Runbook C1. |
| 12 | Connect real calls and meeting rooms | **Pending test** | Not performed. Call/room recovery and renewal unit-tested; web e2e API suite passes; browser media over relay unproven on real nodes. Runbook C2. |
| 13 | Failover on real nodes | **Blocked** | Needs both nodes validated first (Noida is gated). Method that needs no change to any server: staging API pointed at a closed port for Mumbai (runbook D). |
| 14 | Credential issuance endpoints, auth, rate limits, diagnostics authorisation | **Resolved (tested locally)** | Unauthenticated `401`; unadmitted viewer `403` for credentials and for reports; ended session `409`; `/ice-servers` 30/min and `connectivity-report` 20/min return `429`; report lines allow-listed. `IceApiTest`, `DeviceIceTest`, Connect e2e. |
| 15 | Secrets never in repo / responses / bundles / reports / logs | **Resolved (tested locally)** | Grant carries HMAC output only; `TurnNode` hidden from dumps; probe/report JSON secret-free (tests assert absence); `.env.example` contains no secret; the injection script prints key names only. Production log output of a real deploy is not yet observed. |
| 16 | Release workflows | **Reviewed, not run** | Workflows are manual-only. TURN secret steps added to the four deploy workflows; CI runs the failover simulation. Nothing was deployed or triggered. **No pull request has been opened** — awaiting your approval. |

## 2. Reported versus verified

| Claim | Source | Verified here? |
|---|---|---|
| Both nodes pass TLS and firewall checks; certificates externally verified; renewal hooks tested | Infrastructure team | No — taken as reported |
| "Coturn 4.18" | Infrastructure team | **No, and doubtful** — it does not match a coturn release known to this work (4.6.x). Run `turnserver --version` on both nodes and record it; the expiry behaviour in A3 is version-dependent. |
| AlmaLinux 9.8 | Infrastructure team | No |
| Credential expiry semantics | Measured on local coturn 4.6.1 | Yes, locally; **not** on the real nodes |

## 3. Remaining risks

**Application**
* Real-relay behaviour is unmeasured: allocation, relayed throughput, expiry semantics, whether the API host can
  reach 3478 outbound. All are one runbook step each.
* Desktop agent cannot renew credentials. Bounded by the session-bound credential and the 8 h ceiling; unbounded only for
  a policy longer than that on a relay that drops live allocations. Agent-side renewal is the real fix.
* Mobile renewal has no device test; `setConfiguration` behaviour on real handsets across a network handover is unproven.
* Health is probed every 5 minutes: after a primary failure, up to ~5 min of clients are offered it alongside
  the standby (they gather from both), and the primary is dropped after ~10 min. A single-relay outage is therefore
  masked rather than instantaneous; there is no per-client active failover signal beyond ICE's own.
* With Noida disabled in production there is **no standby**: a Mumbai outage means direct connections only, and relay-dependent
  users (restrictive networks) fail. That is the accepted state until Noida is cleared.
* Staging deploys do not install the probe job — a failover test there needs it added or run by hand.
* `WEBRTC_TURN_RESTART_ON_RENEWAL=true` restarts ICE on relayed connections about every 48 minutes — a brief media
  hiccup. Decide after A3.

**Infrastructure**
* Coturn hardening to request (not changed by this work): `denied-peer-ip` for private/loopback/metadata ranges
  (`10/8`, `172.16/12`, `192.168/16`, `127/8`, `169.254/16`, `::1`), `no-cli`, `no-multicast-peers`, `user-quota` /
  `total-quota` / `max-bps`, `stale-nonce`, `no-tcp-relay` only if unused, log rotation, and no `no-auth` anywhere. The probe flags
  an open relay as a finding.
* Per-node secrets must truly differ (the probe and the injector both warn on a shared secret).
* Relay port range 40000–49999 must stay open on the provider firewall; capacity is the first thing to check when
  `insufficient_capacity` appears.
* Certificate renewal: the hook was reported tested; confirm the next renewal reloads coturn without dropping the TLS listener
  (probe the cert expiry weekly — `--allocate` prints `notAfter`).

**Security**
* **Greater Noida carries an unresolved privileged-access concern.** Treat its secret as potentially exposed. Never
  give it the production secret slot; rotate its secret after clearance. Its relay sees only encrypted media (DTLS-SRTP),
  but it sees client IPs and traffic metadata, and a malicious relay can deny service.
* Credentials are bearer tokens for up to 1 h (agent: up to 8 h). They are bound to a participant but not to a source IP.
* A TURN relay can be abused as a proxy to the relay host's network unless `denied-peer-ip` is set (above).

## 4. Tests run

Run on the final tree of this branch, locally (CI workflows are manual-only and were not triggered):

| Suite | Result |
|---|---|
| Backend PHPUnit (real PostgreSQL 16, local coturn 4.6.1 for the live kit tests) | **590 tests, 2262 assertions — OK** |
| Web: `tsc -b`, vitest | **214 passed** (25 files), types clean |
| Web: ICE-kit (node:test) | **32 passed** |
| Signalling | **28 passed** |
| Failover simulation, two local coturns + real Chromium (`failover.sh`) | **9 passed, 0 skipped** — labelled simulation, not the real nodes |
| Kit copies identical between Remote and Connect | `diff -r` empty (PHP and JS) |

All of these exercise the code against a **local** coturn or none. None touched Mumbai or Noida.

### Remote end-to-end (what was and was not done)

| Area | Result |
|---|---|
| Authorization (admitted participant / guest / device token; unadmitted `403`; ended `409`) | **Tested** — backend HTTP tests (real PostgreSQL) |
| Signalling and session establishment with an actual desktop agent and browser | **Not tested** — no staging, no built agent; agent code is not changed except through the server-issued credential |
| Relay-only connection, data channel through a relay | **Tested against local coturn only** (real Chromium); **not** against Mumbai or Noida |
| Reconnection and ICE restart | **Tested** in browser-engine unit tests and, for restart onto renewed credentials, in Chromium against local coturn; **not** with the agent |
| Credential renewal in the browser | **Tested** (unit; Chromium + local coturn). Agent: server-side session-bound credential only |
| Termination | **Not tested** end to end |
| Security panel shows route and node | **Tested** (component/unit tests on the route logic); **not** viewed against a real relay |

## 5. Secure deployment checklist

Each API environment (production, then sandbox/staging) — tick every line; none needs root on the TURN hosts.

**Before deploying**
- [ ] Coturn: `turnserver --version` recorded for each node; `use-auth-secret` with that node's **own** `static-auth-secret`; no `no-auth`, no `cli-password` default, `no-cli` set; hardening list above agreed.
- [ ] GitHub secrets created: production `TURN_MUM_SECRET`; sandbox `SANDBOX_TURN_MUM_SECRET`, `SANDBOX_TURN_NCR_SECRET`. **No Noida secret in production.** Values 16–256 chars of `[A-Za-z0-9._~+/=:@%-]`, different per node.
- [ ] `.env` on each host: `CI_ENVIRONMENT=production`, `WEBRTC_TURN_NODES=mum,ncr`, Mumbai `active`, Noida `disabled` (production) / `standby` (sandbox, only for isolated tests), lists comma-separated with **no spaces**, `WEBRTC_TURN_STATIC_AUTH_SECRET` **unset** when per-node secrets are used, `WEBRTC_FORCE_RELAY` unset/false.
- [ ] Not in any repository, workflow log or ticket: the secrets. `git grep -n -i 'static-auth-secret\|TURN_.*SECRET='` shows only key names and examples.

**After deploying**
- [ ] `.env` mode `600`/owner = the PHP account (`ls -l .env`); `writable/ice/` writable by PHP, not web-readable.
- [ ] `php lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --status` — warnings empty; production lists Mumbai `active`, Noida `disabled`.
- [ ] Runbook **A1–A4 pass on Mumbai from the API host** (UDP, TCP, TLS allocation; wrong-secret, expired and missing-credential refusals; DNS = 148.135.210.65; certificate valid).
- [ ] `crontab -l` shows `turn-probe`; `writable/ice/turn-health.json` mtime advances every 5 minutes; `GET /api/health` shows `relayNodes` Mumbai `healthy`.
- [ ] Runbook **B** passes from at least one restrictive network (TLS 443 only) and one normal network.
- [ ] Runbook **C** (this product) passes in staging.
- [ ] Credential endpoints: `401` without credentials; `403` for an unadmitted participant; `429` under burst; a real response contains no secret (grep the response and the log for the secret's first 6 characters).
- [ ] Rollback: unset `WEBRTC_TURN_NODES` (old single-pool keys apply) or set Mumbai `disabled`; clients fall back to direct ICE. No server-side TURN change needed.

## 6. Items needing your decision

* Approve opening the pull requests (one per repository, from `claude/webrtc-turn-ice-integration`) once the above is reviewed.
* Run runbook Parts A–E and send back the evidence bundle; the matrix is updated from that, not from assumptions.
* Provide the Noida clearance status and the real `turnserver --version` for both nodes.
* Decide whether a Remote session longer than the TTL on the real relay (A3) justifies building agent-side renewal before launch.
