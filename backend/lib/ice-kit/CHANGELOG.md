# Changelog — AICOUNTLY ICE kit

## 1.5.0 — 2026-10-22

Contextual, company-scoped licensing (docs/LICENCE_ENTITLEMENT_CONTRACT.md §1, §4, §9). **No global upgrades.**

- `LicenceContext` (user, active company or none, product, the product's membership evidence) and `LicenceResolver::forContext()`: paid only for a
  CURRENT member of the company whose subscription permits them — company-wide, or an ASSIGNED seat — with a separate user-scoped personal
  entitlement for the personal context. Switching company recalculates; nothing transfers across tenants.
- Document gains `scope` (company | user) and `eligibility` {model company_wide|seat|personal, member, seat}; three shapes (company-level, member-level, personal).
- Cache scopes `customer` / `member` / `user` (the earlier "best across the user's companies" subject scope is removed); revocation is per (company, user, product),
  so a removed member or a revoked seat ends that pair only. Ledger table `aic_entitlement_revoked` gains `subject`.
- `MeetingGuests`: external guests served under a paid host's licensed meeting within `meeting.max_guests` / `meeting.max_participants` / `meeting.guests_paid_quality`.
- Optional fetch-through for a missing member-level document (`WEBRTC_LICENCE_FETCH_ON_MISS`); `CentralClient::member()/personal()`; sync handles `(company, user)` feed entries.
- 41 conformance vectors; `entitlement-fixture.php` signs/loads member-level and personal documents and resolves a context.

## 1.4.0 — 2026-10-21

The common licence-entitlement adapter (`Aicountly\Ice\Licence`, docs/LICENCE_ENTITLEMENT_CONTRACT.md). Remote and Connect consume
centrally verified entitlements; neither is a licensing authority. Backward compatible: with nothing configured every customer is
`unknown` → free capacity, and a product's legacy mapping can still stand in as `compat` evidence while `WEBRTC_LICENCE_COMPAT` is not `deny`.

- **States** `free / paid / expired / suspended / unknown`; only a valid `paid` yields `Tier::Paid`, every doubt is free.
- **Signed documents** (`Entitlement`, `Canonical`, `EntitlementVerifier`): Ed25519 over canonical JSON; products hold only the public key.
- **Resolver** (`LicenceResolver`, `LicencePolicy`, `Resolution`): tenant check (customer / product / subject), freshness, revocation, validity window, paid kinds.
- **Caches** (`EntitlementCache`: memory, file, PostgreSQL in the shared ledger) — untrusted storage, re-verified on use; a lower revision never replaces a higher.
- **Central client and sync** (`AicS2s`, `CentralClient`, `EntitlementSync`, `bin/sync-entitlements.php`): AIC-S2S v1 signed GETs, snapshot + changes feed, revocation recorded before refetch.
- **Readiness** (`LicenceReadiness`, `LicenceRuntime::readiness`): `enforce` stays `observe` until the central source is operational and validated.
- **Tooling** `bin/entitlement-fixture.php` (keys / sign / load / revoke / resolve) for staging before the backend exists; conformance vectors `tests/contract/licence-v1-vectors.json`.
- The issuer audit event carries `licence`, `licenceReason`, `licenceEvidence`, `plan` (never an identifier).

## 1.3.0 — 2026-10-21

Shared capacity accounting, licence readiness and bandwidth-enforcement artifacts. Backward compatible: with no
ledger DSN the file stores behave exactly as in 1.2.0.

- **One ledger for every product.** `PdoLeaseStore` / `PdoLoadStore` / `CapacityRegistry` over PostgreSQL
  (`sql/turn_capacity.sql`, `LedgerConnection`, env `WEBRTC_TURN_LEDGER_*`): Remote and Connect book against the same
  node-level rows under one advisory lock, so the paid reserve is one guarantee, not two. Ledger down while
  enforcing: paid is served unbooked, free is refused (`ledger_unavailable`).
- **Reconciliation.** `bin/reconcile.php` compares the ledger with the relay's own usage log and the node
  collector, and reports configuration drift between products, over-booking, leaked leases and traffic that
  free users carried beyond their share.
- **Licence readiness gate.** `IceRuntime::issuer(..., $licenceReadiness)`: while the product reports blockers
  (unmapped plans, an unattested feed, stale entitlements) `enforce` runs as `observe` and `status` lists
  `enforceBlockedBy`.
- **Tier-split relays.** `TurnNode::group` / `Capacity::structural` and the `Topology` invariant for a node that
  is two coturn instances (paid-only and shared); `infra/tier-split/` holds the coturn, nftables and tc artifacts;
  `tests/loadtest/` the netns shaping check and the tier-isolation load test.

## 1.2.0 — 2026-10-21

Active-active routing and licence-aware admission (docs/ACTIVE_ACTIVE_TURN.md). Backward compatible: with
no capacity configured nothing is limited, and `WEBRTC_TURN_ADMISSION` defaults to `observe` (books and
audit, no denial).

- **Regional peers.** Several `active` nodes serve at once; each client is ranked per request by proximity
  (measured RTT › learned RTT › edge geography › region hint), load, health and stickiness, with the other
  peer as reciprocal fallback offered over `WEBRTC_TURN_FALLBACK_TRANSPORTS` (default TLS only: one
  allocation instead of three).
- **Admission.** `Tier` (paid/free; unknown is free), `Capacity` (allocations and bandwidth, ≥ 50 % paid
  reserve), `Admission::evaluate` (pool rule + shed threshold, renewals exempt from shedding),
  `AdmissionPolicy` (off/observe/enforce, thresholds, usage profiles), lease books (`Lease`, `LeaseBook`,
  `FileLeaseStore` under flock, `MemoryLeaseStore`) updated in the same locked step as the decision,
  `IceIssuer::release()` and `noteConnection()` (bandwidth follows the node a client says it relays through,
  zero for a direct path). A denied client gets STUN only and `admission.outcome = denied`.
- **Tier in the TURN username** (`<expiry>:p.<subject>` / `f.<subject>`), signed, so the relay's own log
  attributes traffic to a tier (`UsageLog`, `bin/summarize-usage.php`).
- **Measured load.** `LoadStore` + `bin/ingest-load.php` + `bin/turn-node-load.sh` (a read-only node-side
  collector: UDP sockets in the relay range, NIC rx/tx); traffic the books cannot explain is charged to both tiers.
- **Latency.** `ClientContext` (region, edge position, client-measured RTT), `GeoHeaders`, `LatencyStore`
  (per-region moving average, bounded), `Proximity`; browser `LatencyMeter` times each node with its own credential.
- **Health.** State `full` (coturn refused for capacity) kept apart from `down`; condition `overloaded`.
- **Browser.** `admission` and node `load` in the normalised config; `relay-capacity-limited` verdict; a renewal
  denied for capacity never replaces a working relay configuration (`CredentialRefresher.onDenied`).
- **Tooling.** `probe.php --status` shows capacity, load and reservations by tier, `--explain` what each tier in
  each region would be offered; `IceRuntime` builds the stores from the environment; simulations
  `active-active.sh`; `relay-check.mjs` also checks the latency meter.
- Per-node `LAT`/`LON`, `MAX_ALLOCATIONS`, `MAX_MBPS`, `TIERS`, `PAID_RESERVE_PERCENT`; `WEBRTC_TURN_*` admission keys.

## 1.1.0 — 2026-10-21

Live-integration hardening. Nothing in 1.0.0's contract is removed.

- **Failover.** A node that is not `up` no longer pins the primary to the response: selection now ranks
  *working* nodes first and applies the per-client cap afterwards; standby nodes join whenever no active node is
  fully working (down, lost a transport, or one failed probe); if nothing is working, every servable node is
  offered, flagged `degraded`. A `disabled` node is unreachable by every path.
- **Health states.** `HealthStore::condition()` / `IceIssuer::status()` expose `healthy | degraded | unreachable |
  unknown` beside the role (`active | standby | disabled`) and whether the node is `serving`. A node that works
  on some transports but not all is `degraded`.
- **Session-bound credentials** (`Topology::sessionBoundTtl`, `WEBRTC_TURN_SESSION_TTL_MAX`, default 8 h, max 24 h)
  for clients that cannot renew (the desktop agent); `IceIssuer::issue()` takes `options['ttl']`.
- **`restartOnRenewal`** in the grant (`WEBRTC_TURN_RESTART_ON_RENEWAL`, default true) and in the browser kit.
- **Probe.** `--negative` (wrong secret, expired credential, no credential must be refused), `--expect-ip`
  (DNS and relay address), `--expiry-test` (what an expiring credential does to a new and an established
  allocation), `--emit-grant` (0600 grant file for the browser check), certificate summary/inspection.
  `bin/summarize-reports.php` summarises `*.ice.report` log lines.
- **Safety.** `Topology::warnings()` (a secret shared by several nodes, a node with no secret);
  `TurnNode` parameters are `#[SensitiveParameter]`.
- **Tooling.** `tests/simulation/failover.sh` (two throwaway coturns; a labelled local simulation, not a test of
  real nodes); `tools/relay-check.mjs` gains `ICE_EXPECT_NODE`, `ICE_EXPECT_PROTOCOL`, `ICE_TOLERATE_NODES`,
  `ICE_REPORT`.
- Corrected a comment that claimed coturn refuses the refresh of an established allocation after its credential
  expires; measured on coturn 4.6.1 it does not (a *new* allocation is refused).

## 1.0.0 — 2026-10-21

First version, vendored into AICOUNTLY Remote and AICOUNTLY Connect.

- Named TURN nodes from the shared `WEBRTC_TURN_NODES` / `WEBRTC_TURN_NODE_<ID>_*` keys, with status
  (`active` / `standby` / `disabled`, default `disabled`), priority, region, transports and ports.
- coturn REST credentials minted per node (per-node secret optional), `"<expiry>:<subject>"`.
- Selection by status, probe health (fail-open), region hint, priority, and a cap on nodes per client.
- The `aicountly.ice/1` grant, including `refreshAfter` for client-side renewal.
- STUN/TURN probe with authenticated allocation over udp/tcp/tls; `bin/probe.php`; health file.
- `ConnectivityReport` allow-list for client diagnostics.
- Single-pool configuration (`WEBRTC_TURN_URLS`, static pairs) kept as the `default` node.
