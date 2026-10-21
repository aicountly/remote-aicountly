# Changelog — AICOUNTLY ICE kit

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
