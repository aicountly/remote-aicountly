# Changelog — AICOUNTLY ICE kit

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
