# ICE, TURN and connection recovery — AICOUNTLY Remote

How a Remote participant gets its STUN/TURN servers, how the connection survives a network that
changes under it, and how an operator knows whether the relays work. The shared pieces are the
**ICE kit**: `backend/lib/ice-kit` (PHP, identical in Connect) and `web/src/ice-kit` (browser,
identical in Connect).

## Infrastructure this assumes

| Node | Host | Status in configuration | Notes |
|---|---|---|---|
| `mum` | `turn-mum.aicountly.com` (148.135.210.65) | `active` | coturn, `use-auth-secret`; UDP/TCP 3478, TLS on **443**; relay UDP 40000–49999 |
| `ncr` | `turn-ncr.aicountly.com` (103.69.196.150) | **`disabled`** | not deployed; must stay disabled until validated (below) |

Nothing in this repository configures, deploys or reaches into those hosts. The application only
reads its own `.env`.

## Configuration (`backend/.env`)

The `WEBRTC_TURN_*` keys are shared with Connect — see `backend/.env.example` and
`backend/lib/ice-kit/README.md`. Production:

```
WEBRTC_TURN_NODES = mum,ncr
WEBRTC_TURN_NODE_MUM_HOST = turn-mum.aicountly.com
WEBRTC_TURN_NODE_MUM_STATUS = active
WEBRTC_TURN_NODE_MUM_PRIORITY = 10
WEBRTC_TURN_NODE_MUM_REGION = in-west
WEBRTC_TURN_NODE_MUM_LABEL = Mumbai
WEBRTC_TURN_NODE_MUM_TLS_PORT = 443
WEBRTC_TURN_NODE_NCR_HOST = turn-ncr.aicountly.com
WEBRTC_TURN_NODE_NCR_STATUS = disabled
WEBRTC_TURN_NODE_NCR_PRIORITY = 20
WEBRTC_TURN_NODE_NCR_REGION = in-north
WEBRTC_TURN_NODE_NCR_LABEL = "Greater Noida"
WEBRTC_TURN_NODE_NCR_TLS_PORT = 443
WEBRTC_TURN_STATIC_AUTH_SECRET = <coturn static-auth-secret — from the infrastructure team>
WEBRTC_TURN_TTL = 3600
```

**No spaces in unquoted values.** CodeIgniter's `.env` parser throws on one, and in this framework
version that becomes a fatal error on every request. `tests/Deploy/EnvExampleFormatTest.php` keeps
`.env.example` honest; a hand-edited server `.env` has no such guard.

With `WEBRTC_TURN_NODES` unset, the original `remote.turnUrls` / `remote.turnStaticAuthSecret` /
`remote.turnUsername` / `remote.turnCredential` keys apply exactly as before, as one node.

## The contract: `aicountly.ice/1`

Returned by:

| Endpoint | Who | Notes |
|---|---|---|
| `POST /api/v1/remote/sessions/{uuid}/signalling-token` | admitted participant or guest | as `ice`, beside the flat `iceServers` / `relayAvailable` older clients read |
| `POST /api/v1/remote/sessions/{uuid}/ice-servers` | admitted participant or guest | the grant alone — renewal without reconnecting to the room. 30/min |
| `POST /api/v1/remote/devices/me/sessions/{uuid}/join` | a device | as `ice`, beside the flat fields the desktop agent reads |

Admission is the signalling token's rule: the session is live and the caller's participant is
`APPROVED` or `JOINED`. A viewer the host has not admitted gets `403 AWAITING_APPROVAL` and no
credential. An optional body `{"region": "in-north"}` reorders nodes; it never adds one.

```json
{
  "contract": "aicountly.ice/1",
  "iceServers": [
    { "urls": ["stun:turn-mum.aicountly.com:3478"] },
    { "urls": ["turn:turn-mum.aicountly.com:3478?transport=udp",
               "turn:turn-mum.aicountly.com:3478?transport=tcp",
               "turns:turn-mum.aicountly.com:443?transport=tcp"],
      "username": "1792573600:<participant uuid>", "credential": "<base64 HMAC-SHA1>" }
  ],
  "iceTransportPolicy": "all", "iceCandidatePoolSize": 0, "relayAvailable": true,
  "issuedAt": "…", "expiresAt": "…", "expiresIn": 3600, "refreshAfter": 2880,
  "relayNodes": [{ "id": "mum", "label": "Mumbai", "region": "in-west", "priority": 10,
                   "status": "active", "credential": "rest", "urls": ["…"], "health": "up" }],
  "degraded": false, "failover": false
}
```

The shared secret never leaves the API host. The username binds a participant uuid (a device:
`device-<uuid>`) so relay usage is attributable without an email address.

## In the browser (`RemoteSessionEngine`)

* **Recovery** — one `ConnectionSupervisor` per peer: 2.5 s grace on `disconnected`, an ICE restart
  at once on `failed`, up to three attempts with growing timeouts, fresh credentials from the second
  attempt, waits for the network while offline, gives up after 30 s with a reason
  (`describeVerdict`) and a **Reconnect** button (`retryConnection()`).
* **Who restarts** — Remote has no perfect negotiation, and both sides notice a failure together,
  so exactly one side of a pair drives: the browser when the peer is a desktop agent (the agent
  answers restart offers but never makes them), otherwise the lower participant uuid. The driver
  offers with `iceRestart: true`; the other side sends `renegotiate` with
  `{"iceRestart": true}`. A repeat request within 3 s is ignored. An older client's plain
  `renegotiate` still gets a plain offer. Offers that cross anyway (two people sharing in the same
  second) are resolved politely: the non-driver rolls back.
* **Renewal** — `CredentialRefresher` asks `/ice-servers` at `refreshAfter`, applies the grant to
  every connection, and restarts ICE on a connection whose own side is relayed, while its old
  allocation still works. coturn refuses an allocation's refresh once its credential has expired,
  so without this a relayed session longer than the TTL would drop.
* **A renegotiation no longer opens new data channels** — `createOffer()` creates the chat and
  control channels on the first offer only.
* **Diagnostics** — the Security panel says whether media is direct or relayed, through which node
  and over what, and can run a relay check (`probeRelay`). Connected, recovered and failed
  connections are reported to `POST …/sessions/{uuid}/connectivity-report`, which logs one
  allow-listed line (`remote.ice.report`): outcome, path, candidate types, relay node and protocol,
  ICE error categories, restarts, timings. Never an address, SDP or credential.

The desktop agent is unchanged: it answers ICE-restart offers (webrtc-rs) and reads the flat
`iceServers`. It does not renew credentials itself — see *Remaining*.

## Operating it

| | |
|---|---|
| What would be served | `php lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --status` |
| Allocate on every node not disabled | `… --allocate` |
| Validate one node (including a disabled one) | `… --node=ncr --allocate` |
| Health, every 5 minutes | `turn-probe` in `bin/ops/jobs.txt` → `writable/ice/turn-health.json` |
| At a glance | `GET /api/health` → `relayNodes: [{id, status, servable, health}]` |
| Per-connection outcomes | `grep remote.ice.report` in `writable/logs/` |

A node is skipped after two consecutive failed probes; stale results are ignored; the last servable
node is always served (`degraded: true`). A disabled node is never served.

## Validating a node — before calling relaying production-ready

1. `--node=mum --allocate` from the API host: `PASS`, allocation `ok` on udp, tcp and tls.
2. From a browser on a restrictive network (443-only, or UDP blocked):
   `ICE_GRANT=… ICE_FRESH_GRANT=… node web/src/ice-kit/tools/relay-check.mjs` — all `PASS`.
3. A real session with the Security panel showing **through AICOUNTLY's Mumbai relay**.

For Greater Noida, the same three against `ncr` while it is `disabled`, then
`WEBRTC_TURN_NODE_NCR_STATUS = active` (or `standby` to hold it as a spare).

## Remaining

* **Neither node has been tested from this codebase.** The kit was validated against a local coturn
  (allocation on udp/tcp/tls with a kit-minted credential; wrong secret and expired credential
  refused; Chromium relay-only connection, data, and an ICE restart onto a renewed grant). Steps
  1–3 above against `turn-mum` are the launch gate.
* `turns:` on 443 has not been exercised by a browser against a real certificate.
* The desktop agent does not renew TURN credentials; a relayed agent session longer than
  `WEBRTC_TURN_TTL` relies on the browser restarting ICE, which regathers only the browser's side.
  Either raise the TTL for device grants or teach the agent to `set_configuration` (webrtc-rs).
