# Active-active TURN routing and licence-aware admission — AICOUNTLY Remote

> **Decision 2026-10-22 — Greater Noida joins production as Mumbai's regional peer.** Both nodes passed authenticated relay tests
> (allocation over UDP/TCP/TLS-443; wrong-secret, expired and anonymous credentials refused; valid certificates) from the OVH application
> server (`docs/STAGING_TURN_DEPLOYMENT.md` §12). Production now runs **active-active**: each client is offered the node nearest to it first
> and the other as its fallback (TLS 443); a node the 5-minute probe finds down is skipped. Admission stays `observe`. Statements below that
> keep Noida `disabled` in production describe the state before this decision.

Mumbai (`mum`) and Greater Noida (`ncr`) are **regional peers**. This document is the design of how a client
is routed between them, how at least half of each node is kept for paid customers, and how both are
enforced and *verified* — written so the parts that exist, the parts that are proposed and the parts that
are assumed cannot be confused.

Companion documents: `docs/ICE_CONTRACT.md` (the wire contract), `docs/TURN_VALIDATION_RUNBOOK.md` (the commands
that produce evidence from the real nodes), `docs/LAUNCH_READINESS_TURN.md` (blockers and the launch recommendation).

## 0. How to read the status labels

| Label | Meaning |
|---|---|
| **Implemented · tested locally** | Code exists and is covered by automated tests and/or the local simulations, which run real coturn processes and a real Chromium on one machine. Says nothing about the real Mumbai and Noida nodes. |
| **Implemented · unverified on real infrastructure** | Code exists; what it assumes about the real nodes (a log format, an option's behaviour in their coturn build, a header from their edge) has not been checked there. The runbook step that checks it is named. |
| **Proposal** | A design for the infrastructure or product team to accept. No code in this repository depends on it being built. |
| **Assumption** | A number or fact the design uses that nobody has measured. Each is listed in § 12. |

**Nothing in this document has been run against `turn-mum.aicountly.com` or `turn-ncr.aicountly.com`.**
Greater Noida stays out of production until its authenticated relay tests and its pending security review are complete.

## 1. What changed

| | Before | Now |
|---|---|---|
| Node roles | Mumbai primary, Noida standby that serves only when Mumbai is down | Both `active`, serving at the same time; `standby` still exists for a node that should serve only when no active node can |
| Choosing a node | Priority, then region hint | Proximity (measured round trip › learned › edge geography › region hint), load, health, stickiness — per request |
| Failure of one node | Standby joins after one failed probe | The other peer is already serving its own region and takes over the failed node's clients (reciprocal) |
| Capacity | Not modelled | Per-node allocation and bandwidth limits; ≥ 50 % reserved for paid; per-session leases; measured load |
| Licence | Not modelled | Every grant is accounted to a tier; free users cannot consume the paid reserve, on any node, including through fallback |
| Production state | Mumbai only | **Unchanged: Mumbai only.** Noida is `disabled` in production until it is cleared. With one node there is no peer to spill to, and the reserve still applies to it |

## 1a. Review of the previous implementation against these decisions

| Component | Finding | Consequence for active-active / paid reserve | Change |
|---|---|---|---|
| `IceIssuer` | Ranked by health, then region hint, then `priority`; a second node joined only as a *standby* | Could not express two nodes serving at once, geography, load, or tiers | Rewritten as per-request ranking with admission (§ 4, § 6) |
| `IceIssuer` | Capped the nodes per client **after** a priority sort | With peers, the cap could drop the healthy near node in favour of a far one | Cap applied after ranking; second node offered over TLS only |
| `HealthStore` | A refused probe counted as a failure; two failures meant `down` | A node that is merely *full* (`486`/`508`) would be treated as dead and dropped | State `full` / condition `overloaded`, kept apart from `down` |
| `HealthStore` / probe | Recorded only ok/not-ok per transport | A relay near its limit answers slowly long before it refuses | Allocation time recorded; slower than `WEBRTC_TURN_PROBE_SLOW_MS` (1.5 s) marks the node `degraded` |
| Probe | Measures reachability and allocation *from the API host* | Says nothing about a client's latency, capacity or load | Unchanged in purpose; client latency is measured by the client (`LatencyMeter`), load by the collector (L3) |
| Client selection | A region string the client typed; no measurement | Mumbai-near and Delhi-near users indistinguishable | Region hint, edge geography, client-measured and learned round trips |
| Credentials | `<expiry>:<subject>` | The relay could not tell a free credential from a paid one, so no relay-side audit was possible | Tier signed into the username |
| `Topology` / `.env` | No capacity, coordinates or tier settings | Nothing to reserve against | Per-node `LAT/LON/MAX_*/TIERS/PAID_RESERVE_PERCENT`; admission keys (§ 10) |
| Release of capacity | None (credentials just expired) | Finished sessions would hold capacity for up to an hour | Release on session end/expiry/leave; `noteConnection` charges bandwidth only where traffic is |
| Deployment | One production secret (Mumbai); Noida sandbox only | Matches the decision that Noida stays out of production | Unchanged; capacities are non-secret `.env` values set by the operator, not by the deploy |
| Renewal | A refreshed grant was always adopted | A *denied* renewal would have replaced a working relay with none and restarted the session without it | A denied renewal is never adopted while a relay is held |

## 2. Architecture

```
   browser / desktop agent / mobile
        │  1. asks for credentials (+ region hint, + the round trips it measured)
        ▼
   Remote API ── tier ◄── company plan (remote_entitlements.plan_code)
        │
        │  IceIssuer.issue()  (one locked step)
        │    a. health        ◄── turn-health.json   written every 5 min by bin/probe.php
        │    b. measured load ◄── turn-load.json     written by bin/ingest-load.php (optional)
        │    c. books         ◄─► turn-leases.json   one lease per client per node, under flock
        │    d. proximity     ◄── client hint / edge geography / measured rtt / learned rtt
        │    e. rank, admit, reserve, mint a per-node HMAC credential carrying the tier
        ▼
   grant: iceServers (best node: all URLs; fallback node: TLS only), admission {tier, outcome, reason}
        │
        ├─► Mumbai coturn ──┐   usage log: "username=<expiry>:p.<subject> … rb= sb="   (tier visible to the relay)
        └─► Noida  coturn ──┘
```

Everything above the dashed line of the relays is the application's control plane. It decides who is *given*
a relay. It cannot stop a holder of a valid credential from using more than was reserved for them, which is
why § 7 has further layers.

## 3. What is being counted (four different things)

| Term | Unit | Where it lives | Why it is separate |
|---|---|---|---|
| **Product session** | a Remote session / a Connect call or meeting | `Lease.sessionRef` | what a customer understands; released when it ends |
| **Lease** | one participant's claim on one node | `turn-leases.json` | lasts as long as its credential; renewed with it |
| **Allocations** | TURN allocations the lease is expected to open | `Lease.allocations` | one per TURN URL a browser is given: 3 on the first node, **1** on the fallback node (TLS only) — this is what coturn's `total-quota` counts |
| **Allocated bandwidth** | bit/s the lease is expected to carry (up + down, as the node's NIC sees it) | `Lease.bps` — charged to the node the client *relays through* | an allocation that carries nothing still occupies a slot; a client that reports a **direct** path is still charged a residual (20 %, `WEBRTC_TURN_DIRECT_CHARGE_PERCENT`) because the claim cannot be verified |
| **Actual traffic** | measured allocations and rx+tx bit/s | `turn-load.json` | the truth the estimates are checked against; when it exceeds the books, the excess is charged to *both* tiers |

## 4. Node selection

Per request, for a client of a tier and a usage profile (**implemented · tested locally**; `lib/ice-kit/src/IceIssuer.php`):

1. **Candidates** — servable nodes (not `disabled`, with a credential and URLs) that serve the tier. A `disabled` node is unreachable by every path below.
2. **Admission** per candidate (§ 6). In `enforce` mode a node that refuses the client is removed; in `observe` it is kept and the verdict is only logged.
3. **Pool** — `active` nodes that are not `down`. If none is fully working, usable `standby` nodes join (`failover`). If everything admissible is known down, all of it is offered flagged `degraded` — a probe's opinion is not proof — but **capacity is never overridden**: a node that is full stays out.
4. **Cost** of each pool node, in milliseconds of "distance":

   `cost = proximity + loadPenalty(utilization) + healthPenalty − stickiness`

   | term | value |
   |---|---|
   | proximity | the client's own measured RTT › the RTT learned for its region (≥ 5 samples, ≤ 7 days) › edge geography `10 + km/60` › region hint (`10` if it is the node's region, else the geo model from that region's node, else `60`) › `60` for everyone |
   | loadPenalty | `0` below `busyAt` (70 %); rising to `+40 ms` at `shedFreeAt` (85 %); then steeply to `+120 ms` at 100 % |
   | healthPenalty | `+30 ms` for `degraded`/`failing` |
   | stickiness | `−20 ms` for the node a renewing client is already on, so a few milliseconds' difference does not restart ICE |

5. **Order** — fully-working nodes first; then costs quantised to a **balance band** (15 ms); nodes in the same band are ordered by utilization (so near-equidistant nodes share load), then `priority`, then id. The cap on nodes per client (`WEBRTC_TURN_MAX_NODES`, 2) is applied *after* this.
6. **Offer** — the first node gets every URL it has; every other node is the fallback and gets only `WEBRTC_TURN_FALLBACK_TRANSPORTS` (default `tls`): one allocation instead of three on the second node, and TLS works on every network the first node's UDP and TCP do.
7. **Reserve** — the lease is written in the same `flock`ed step as the decision, so two requests can never both take the last slot (verified with nine racing processes).

Worked example (production-like numbers, Mumbai at 20 % and Noida idle): a Pune client (`in-west`) costs `mum 10`, `ncr 29` → Mumbai first, Noida as fallback. At 80 % Mumbai's penalty is `+27 ms`: `mum 37` vs `ncr 29` → Noida first, Mumbai as fallback — load is shared, and nobody is refused.

**What a client can influence:** its region hint, its position headers (if the edge supplies them) and its reported round trips change *which node it is offered first* and nothing else. Tier, admission and credentials never read them, so spoofing them cannot buy capacity.

## 5. Overload thresholds

Utilization is the larger of allocations and bandwidth, as a fraction of the node's configured sustainable capacity, counting the books and any measured load the books do not explain.

| State | Utilization | What the system does | Signal |
|---|---|---|---|
| `normal` | < 70 % | routes by proximity | — |
| `busy` | ≥ 70 % | adds a load penalty, so near-equidistant peers share | `/api/health` `load: busy` |
| `overloaded` | ≥ 85 % | **new free sessions are refused** on this node (they go to the peer, or are denied); paid are still admitted; *renewals are never refused for this reason* | `load: overloaded`; log `reason: node_overloaded` |
| `full` | ≥ 100 % | nobody new is admitted | `load: full`; `reason: node_full` |

Separately, a probe that is refused by coturn for capacity (`486`/`508`) marks the node `full` in the health file (state `full`, condition `overloaded`) — alive, but not a node to send more people to — and is never confused with `down`.

Thresholds are configuration (`WEBRTC_TURN_BUSY_AT`, `WEBRTC_TURN_SHED_FREE_AT`) and are **assumptions** until the load test in the runbook (part F) shows where the real nodes degrade.

## 6. Paid and free admission

Per metered dimension (allocations; bandwidth), with `cap` the node's sustainable limit and `R = ceil(cap × reserve%)` (reserve ≥ 50 %): (**implemented · tested locally**; `Admission.php`)

* **Paid** is admitted iff `free + paid + unattributed + request ≤ cap`.
* **Free** is admitted iff `free + unattributed + request + max(paid, R) ≤ cap` (the reserve is held whether or not paid users are using it), **and** `total + unattributed + request ≤ shedFreeAt × cap`.
* Paid users may therefore use the reserve *and* the shared part; free users shrink as paid grows past `R`.
* **Unattributed** load — measured traffic the books cannot explain — is charged to both tiers, so a disagreement between measurement and books can only make admission stricter.
* **Renewals** (the client already holds a lease on the node) do not count against themselves and are exempt from the shed threshold: overload closes the door to new free sessions, it does not evict the ones inside. A renewal the hard limits still refuse is simply not adopted by the client — it keeps the credentials it has (the kit's `CredentialRefresher` ignores a denied grant while it holds a working relay) and asks again a minute later.
* **Client reports are advice, and checked.** A connectivity report can move a bandwidth charge only between nodes the client really holds a lease on, and the move must itself fit on the target node by the same rule as an admission; an invented node, or a claim of "direct", cannot zero a charge.
* **Denial** returns STUN only, `relayAvailable:false` and `admission {outcome:"denied", reason}`; clients say "relay capacity is fully used for this account right now", not "no relay is configured".
* **Unknown means free.** A missing plan, an unlisted plan, an expired licence or a failed lookup is `free`. The reserve is handed out only on positive evidence.
* Properties checked by a randomised test (four reserve settings × 4 000 admissions and releases): capacity is never exceeded; free never takes the reserve; a paid user inside the reserve is never refused.

**Where the tier comes from — Remote:** the plan of the company that owns the session (`remote_entitlements.plan_code`). `WEBRTC_TURN_PAID_PLAN_CODES` lists the codes that count as paid. **That list is an open business decision**: this repository does not say which plans are paid, and the platform default row seeds `REMOTE_PROFESSIONAL`, so it must be set deliberately; empty (the default) means nobody is paid and the reserve is never handed out. Guests, the host's browser and the desktop agent all take the session's tier (the agent's credential is session-bound and its lease expires with it).

**Modes:** `off` (no books), `observe` (books and audit log, nothing denied — the default), `enforce`. A staging run in `observe` shows what `enforce` would have refused.

## 6a. What an independent review found, and what changed

The admission, lease and selection code was read by a reviewer who had not written it and was asked to break it. It found the following; each is fixed and has a regression test (`tests/ReviewRegressionTest.php`) unless noted.

| Finding | Fix |
|---|---|
| A client's own report could move its bandwidth charge to a node it held only a fallback lease on, past the free share, or zero it with an invented node id or a "direct" claim | Reports can only choose among held nodes; moving a charge re-runs admission on the target; a direct claim keeps a residual charge |
| A renewing client's own traffic was counted twice when telemetry was present, so a renewal could be refused by its own load | Own lease added back when comparing with measured traffic |
| A node a client stopped being offered lost its lease at once, though the allocation lived until its credential expired | Slots stay booked until expiry; only the bandwidth moves |
| One client repeating requests could steer a region's learned latency | One sample per client (hashed), median over ≥ 5 distinct clients, unknown regions ignored, trusted edge geography outranks learned |
| A corrupt lease file read as empty with no signal; one poisoned row could fail every request open for good | The file is set aside and the repair is audited; bad rows cost a row; status reports `books: unavailable` |
| Eight junk node ids evicted the real nodes' telemetry; a crafted id could forge a line of the ingest tool's output; a trailing newline passed the id check | Only configured node ids are accepted; newest readings kept; output sanitised; `\z` |
| The usage-log regex was unanchored: text embedded in another log line could be counted as traffic | Anchored to coturn's prefix and byte-count tail; usernames shaped exactly as minted or reported untiered |
| `_TIERS=paidd` silently made a node free-only; naming both tiers allowed a 0 % reserve | Unknown tier leaves the node out and names it; a two-tier node can never go below the floor |
| A one-allocation fallback lease made a client count as "renewing" on a node it never used, skipping the shed threshold | Only a charged lease (first node, or the node it reported) counts |
| The shed threshold was off by one for some whole percentages (`0.70 × 90`) | Exact comparison |
| Two different non-ASCII subjects merged into one lease; probes could lose each other's health update; lock handles leaked; predictable temp names | Stable stand-in subject; locked health writes; handles closed; random temp names |
| A mistyped `WEBRTC_TURN_ADMISSION` silently meant `observe` | Reported as a configuration warning |
| NIC rx+tx versus a client's "up + down" (a relayed bit can cross the NIC twice) | Not a code change: the profiles are defined as **NIC footprint** and F1 measures them that way; the defaults are client-leg figures and are likely low (§ 12) |


## 7. Enforcement and how it is verified

| Layer | What it does | What it cannot do | Status |
|---|---|---|---|
| **L1 — API admission and books** | Refuses or routes at issuance and renewal; tier is signed into the TURN username; per-node secrets mean a credential only works on the node it was minted for | Cannot see traffic; a holder of a credential can send more than its estimate | **Implemented · tested locally** |
| **L2 — coturn limits** (`user-quota`, `total-quota`, `max-bps`, `bps-capacity`) | Bound allocations per credential (`user-quota`, which keeps the estimate an upper bound), allocations per node (`total-quota`), bandwidth per session and per node | **Do not know about licence tiers.** They count credentials and bytes, not plans. Shown directly: a relay with `total-quota=4` is exhausted by four *free* credentials and then refuses a *paid* one (`active-active.sh` phase 0) | Settings are a **proposal** (§ 8); the tier-blindness is **tested locally** |
| **L3 — node telemetry** | Measures allocations (UDP sockets in the relay port range) and rx/tx bit/s from `/proc`; the API charges what the books miss to both tiers | Needs a login on each node; samples are seconds old | Collector and ingest **implemented · tested locally** (counted 4 of 4 allocations in the simulation); the transport (forced-command SSH) is a **proposal** |
| **L4 — relay-side attribution** | `bin/summarize-usage.php` reads coturn's own closed-allocation log lines and attributes bytes to the tier in the signed username | Lags (a line appears when an allocation closes); the log format is read from coturn 4.6.1 | **Implemented · tested locally** (a real Chromium's relayed bytes attributed to paid and free); format on the real nodes **unverified** (runbook F6) |
| **L5 — hard separation by tier** | Two coturn instances per host (a reserved one and a shared one), disjoint relay port ranges, own secrets and `bps-capacity`; `nftables` counters (and `tc`, if shaping is wanted) per range | Needs a second listener per host; TLS on 443 cannot be shared by two instances on one IP without SNI routing | **Proposal** (§ 9); the kit already supports it through `WEBRTC_TURN_NODE_<ID>_TIERS` |

Why L1 + L3 + L4 are the proposed baseline and L5 is optional: L1 guarantees the *policy* at admission; L3 stops the books drifting from reality; L4 lets anyone audit, from the relay's side and without trusting the application, whether free traffic stayed inside its share. L5 would make the reservation a property of the network rather than of the application, at the price of a second listener per host — worth it only if L4 shows the baseline leaking.

Considered and rejected: per-tier **realms** with coturn's per-realm quotas — browsers cannot choose a realm (they do not control the STUN `ORIGIN`), and a server-side mapping would be exactly L5.

## 8. coturn settings to request (proposal — nothing here has been changed)

Derived from a node's configured capacity `A` (allocations) and `B` (bytes/s); `A` and `B` are the **measured sustainable** values, not the line rate:

| Setting | Value | Purpose |
|---|---|---|
| `total-quota` | `⌈1.1 × A⌉` | a safety net just above the application's limit; a refusal shows up in the health probe as `full` |
| `user-quota` | `8` | the most allocations one credential may hold (3 URLs + ICE restarts in flight); keeps a lease's allocation estimate an upper bound |
| `bps-capacity` | `⌈1.1 × B⌉` | server-wide throughput safety net |
| `max-bps` | per-session ceiling, e.g. `1 000 000` (bytes/s) | one runaway session cannot take a node |
| `denied-peer-ip` | `10.0.0.0-10.255.255.255`, `172.16.0.0-172.31.255.255`, `192.168.0.0-192.168.255.255`, `127.0.0.0-127.255.255.255`, `169.254.0.0-169.254.255.255`, `::1` | a relay is not a way into the host's network |
| `no-cli`, `no-multicast-peers`, `stale-nonce` | on | no management interface; fewer abuse paths |
| `verbose` + log rotation | on | the usage lines L4 reads |
| `static-auth-secret` | **its own, per node** | a credential for one node is useless at the other |

Whether the real build honours each option as the local 4.6.1 does is runbook part F3 — the nodes are reported as "Coturn 4.18", a version this work could not identify.

## 9. Hard separation by tier (proposal, optional)

Run two coturn processes per host: `mum-paid` (relay ports 40000–44999, `bps-capacity` = the reserved share) and `mum-shared` (45000–49999, the rest), each with its own listener ports and secret. In the application:

```
WEBRTC_TURN_NODES = mum-paid,mum-shared,ncr-paid,ncr-shared
WEBRTC_TURN_NODE_MUM-PAID_TIERS   = paid           # a single-tier node has no reserve of its own: it IS the reserve
WEBRTC_TURN_NODE_MUM-SHARED_TIERS = paid,free      # a two-tier node always keeps the 50 % floor
```

Paid clients prefer their own instance (lower `priority`) and spill into the shared one (where half of it is still held for paid); free clients can only be given the shared one. The cost is a second listener per host — and a second TLS name or SNI routing, because two instances cannot both own `:443` on one IP. This is **not** needed to launch; it exists so that, if the relay-side audit (L4) ever shows free traffic exceeding its share, the fix is configuration, not code.

## 10. Required changes

**Application — done** (this change, both products): kit 1.2.0 (`Tier`, `Capacity`, `Admission`, leases, `LoadStore`, `LatencyStore`, `Proximity`, `GeoHeaders`, `IceRuntime`, selection and `noteConnection`/`release`); Remote adapter (`IceConfigService` supplies tier, session reference and client context; `SessionService` and `ParticipantService` release leases when a session ends, expires or a participant leaves; `SignallingController` records reported paths; `DeviceAgentController` issues the session-bound agent grant under the same tier; `config/ice-kit.php` gives the probe the same books); clients send the round trips they measure and handle a denied relay; `/api/health` shows each node's `load`.

**Application — still open:** a licence feed into the paid-plan list (`WEBRTC_TURN_PAID_PLAN_CODES`), agreed with billing; a shared lease store if more than one API host serves the same relays (the file store is per host); agent-side credential renewal (unchanged from the previous report).

**Infrastructure — needed, none of it done by this repository:**
1. Measured capacities for both nodes (runbook F) and the settings in § 8.
2. A read-only forced-command login on each node for `turn-node-load.sh`, if L3 is wanted (otherwise admission runs on the books alone).
3. Edge geography headers on the API's requests, if the edge can supply them (otherwise routing uses region hints and measured round trips only).
4. Greater Noida's security clearance and its own authenticated tests before it is enabled in production.
5. Decision on L5.

## 11. Tests

**Automated, run on every change** (PHP kit suite, identical in Remote and Connect): the admission arithmetic (including 16 000 randomised steps across four reserve settings), selection (regional preference, measured RTT, load sharing, reciprocal failover, tier-restricted nodes), lease books (persistence, expiry, corruption, secrets never written, nine racing processes), telemetry and latency ingestion (bounds, expiry, poisoning), usage-log attribution, edge-header parsing; browser kit tests (admission fields, denied renewal never replaces a working relay, latency meter); product API tests (plan → tier, full free pool refuses free and not paid, session end and leave release capacity, a reported direct path frees bandwidth, health exposes no capacities).

**Simulations** (`lib/ice-kit/tests/simulation/`, real coturn + real Chromium, local only): `failover.sh` (primary/standby, retained) and `active-active.sh` — regional routing, one peer stopped and restored, free pool spilling to the other region then refused, paid reserve intact, the books agreeing with coturn's real allocation count, the relay's log attributing bytes to tiers.

**Real-server validation plan** (`docs/TURN_VALIDATION_RUNBOOK.md`, parts F and G — **not yet run**):

| Step | Question it answers |
|---|---|
| F1 | What does one Remote session / call / room participant really cost a node (NIC rx+tx)? Replaces the profile assumptions |
| F2 | What does each node sustain before quality degrades? Sets `MAX_ALLOCATIONS`, `MAX_MBPS` |
| F3 | Do the real build's `user-quota`, `total-quota`, `max-bps`, `bps-capacity` behave as § 7 assumes? |
| F4 | Does the collector agree with coturn and with the books on the real hosts? |
| F5 | Under a free flood plus steady paid load, does paid keep its reserve and free stop at its share? |
| F6 | Does the usage log have the format L4 reads, and do its tier totals match the books? |
| G1–G3 | Does each region get its nearer node from real networks; does a stopped/blocked peer move its clients; does a loaded node shed to its peer? |
| G4 | Does a renewal near overload keep a session alive? |
| G5 | Noida gating: authenticated tests and clearance recorded before `STATUS=active` anywhere production-facing |

## 12. Assumptions that need a number

| Assumption | Where it is used | How it is replaced |
|---|---|---|
| Profile bandwidth: Remote 6 000 kbit/s, call 3 000, room 3 000, audio 150, default 2 000 — client-leg figures; the NIC footprint can be up to twice as much when a stream is relayed across both nodes | every bandwidth admission | F1 |
| Sustainable capacity per node (placeholders in `.env.example`) | every admission | F2 |
| Busy 70 % / shed 85 % | load shedding | F2, F5 |
| `10 ms + km/60` geography model | cold-start routing | G1 |
| Fallback node needs only TLS | one allocation instead of three on the second node | G2 |
| A browser makes one allocation per URL | the allocation estimate | F4 |
| 15 ms balance band, 20 ms stickiness | stability vs sharing | G1, G3 |
| The edge can supply visitor location | edge geography | infrastructure answer |
| Real coturn's usage-log format equals 4.6.1's | L4 | F6 |

## 13. Shared ledger, licence gate and bandwidth layers (kit 1.3.0, 2026-10-21)

*Implemented and tested locally unless stated.* Details and commands: `docs/STAGING_TURN_DEPLOYMENT.md`,
`docs/TURN_BANDWIDTH_ENFORCEMENT.md`, `docs/LICENCE_ENTITLEMENT_AUDIT.md`.

* **One set of books for every product.** With `WEBRTC_TURN_LEDGER_DSN` set, leases and measured load live in PostgreSQL
  (`PdoLeaseStore`, `PdoLoadStore`; `sql/turn_capacity.sql`), updated in one transaction under an advisory lock
  (`pg_advisory_xact_lock`), so Remote and Connect admit against the **same** node rows: the paid reserve is one guarantee. Without the
  DSN the per-product files (§3) are used, as before. `CapacityRegistry` records each product's view of each node's capacity so a
  disagreement is detected (`reconcile.php` → `config_mismatch`).
* **Ledger down.** While `enforce`: paid is served unbooked (a customer is never refused over our bookkeeping), free is refused
  (`reason = ledger_unavailable`). In `observe` nobody is refused.
* **Reconciliation.** `bin/reconcile.php` compares the books with the collector's measurement and with the relay's own per-tier byte
  counts (report-only; leaked leases lapse with their credentials rather than being dropped).
* **Licence entitlements (kit 1.4.0).** Who is paid is decided by the **central licensing backend**, not by Remote or Connect
  (`docs/LICENCE_ENTITLEMENT_CONTRACT.md`). A common adapter reads its Ed25519-signed entitlements — states free / paid / expired /
  suspended / unknown, tenant-checked, fresh, revocable — and only a valid `paid` yields the paid capacity class; every doubt is free. Eligibility is **contextual and company-scoped**: the authenticated user, the active company, the product, verified current membership (and an assigned seat for seat licences); a company's licence never makes a user paid anywhere else, switching company recalculates, a personal licence is a separate user-scoped entitlement, and an external meeting guest is accounted to the paid host's licensed meeting (within plan limits) without any account being upgraded.
  The old per-product mappings (Remote's `WEBRTC_TURN_PAID_PLAN_CODES`, Connect's `connect_license_tier`) are a **development-time
  compatibility path** with weaker evidence. **Licence readiness gate:** `enforce` runs as `observe` — and `status.enforceBlockedBy` says why —
  until the central API is configured and syncing, keys are present, the maximum age is set, compat is denied and an operator has attested
  validation. During development the mode stays `observe`; the 50 % reservation and the Mumbai/NCR routing are unaffected.
* **Bandwidth.** §9's optional hard separation is now a concrete, locally exercised design (`infra/tier-split/`): two coturn instances
  per host, and an nftables-classified HTB shaper that guarantees the paid class its share of the NIC whatever the shared class
  offers. **Proposal for the infrastructure team; untested on the real nodes.**
* **What §12's list gained:** the sustainable rate of the real NIC (not the advertised port speed), whether coturn 4.18.0-1.el9 matches the
  4.6.1 behaviour recorded here, whether the OVH host has an upstream limiter, and whether a second public IP exists for TLS on 443.
