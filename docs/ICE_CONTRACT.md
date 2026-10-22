# ICE, TURN and connection recovery — AICOUNTLY Remote

How a Remote participant gets its STUN/TURN servers, how the connection survives a network that changes under
it, and how an operator knows whether the relays work. The shared pieces are the **ICE kit**:
`backend/lib/ice-kit` (PHP, identical in Connect) and `web/src/ice-kit` (browser, identical in Connect).

## Infrastructure this assumes

| Node | Host | Production | Staging | Notes |
|---|---|---|---|---|
| `mum` | `turn-mum.aicountly.com` (148.135.210.65) | **active** | active | coturn, HMAC (`use-auth-secret`); UDP/TCP 3478, TLS 443; relay UDP 40000–49999 |
| `ncr` | `turn-ncr.aicountly.com` (103.69.196.150) | **disabled** | standby (isolated tests) | its **own** secret; withheld from production until its security clearance is recorded |

Nothing in this repository configures, deploys or reaches into those hosts, and nothing here needs root.
TLS and firewall checks passed on both; **authenticated allocation and end-to-end relaying have not yet
been verified against them** — that is `docs/TURN_VALIDATION_RUNBOOK.md`.

## Configuration (`api/.env`)

Non-secret lines — the same keys in Remote and Connect, listed in `backend/.env.example`:

```
WEBRTC_TURN_NODES = mum,ncr
WEBRTC_TURN_NODE_MUM_HOST = turn-mum.aicountly.com
WEBRTC_TURN_NODE_MUM_STATUS = active
WEBRTC_TURN_NODE_MUM_PRIORITY = 10
WEBRTC_TURN_NODE_MUM_REGION = in-west
WEBRTC_TURN_NODE_MUM_LABEL = Mumbai
WEBRTC_TURN_NODE_MUM_TLS_PORT = 443
WEBRTC_TURN_NODE_NCR_HOST = turn-ncr.aicountly.com
WEBRTC_TURN_NODE_NCR_STATUS = disabled          # standby in staging only
WEBRTC_TURN_NODE_NCR_PRIORITY = 20
WEBRTC_TURN_NODE_NCR_REGION = in-north
WEBRTC_TURN_NODE_NCR_LABEL = "Greater Noida"
WEBRTC_TURN_NODE_NCR_TLS_PORT = 443
WEBRTC_TURN_TTL = 3600
```

**Secrets are not in this file's template, the repository, a response or a log.** Each node has its own:
`WEBRTC_TURN_NODE_MUM_SECRET` and `WEBRTC_TURN_NODE_NCR_SECRET`, written into the server's `api/.env` by the
deploy over SSH stdin (§ *Secrets*). Do **not** also set `WEBRTC_TURN_STATIC_AUTH_SECRET`: a node without its
own secret inherits it, and two nodes sharing one means a credential minted for either is valid at both.
`probe.php --status` warns when that happens.

**No spaces in unquoted values** — CodeIgniter's `.env` parser throws on one and the API stops answering;
quote a value that needs a space. `EnvExampleFormatTest` keeps the template honest; a hand-edited server
`.env` has no such guard. With `WEBRTC_TURN_NODES` unset, the original single-pool keys apply, as one node.

## The contract: `aicountly.ice/1`

| Endpoint | Who | Notes |
|---|---|---|
| `POST /api/v1/remote/sessions/{uuid}/signalling-token` | admitted participant or guest | as `ice`, beside the flat `iceServers` / `relayAvailable` older clients read |
| `POST /api/v1/remote/sessions/{uuid}/ice-servers` | admitted participant or guest | the grant alone — renewal without reconnecting to the room. 30/min |
| `POST /api/v1/remote/sessions/{uuid}/connectivity-report` | admitted participant (or one who just left) | logs one allow-listed line. 20/min |
| `POST /api/v1/remote/devices/me/sessions/{uuid}/join` | the device (Bearer `device.…`) | as `ice`, with a **session-bound** credential (below) |

Admission is the signalling token's rule: the session is live and the caller's participant is `APPROVED` or
`JOINED`. A viewer the host has not admitted gets `403 AWAITING_APPROVAL` and no credential, and cannot write
to the diagnostics log either; an ended session answers `409`; no credentials, `401`. An optional body
`{"region": "in-north"}` reorders nodes — it never adds one.

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
  "degraded": false, "failover": false, "restartOnRenewal": true
}
```

The username binds a participant uuid (a device: `device-<uuid>`), so relay usage is attributable without an
email address.

## Node states and failover

The probe (`turn-probe` in `bin/ops/jobs.txt`, every 5 minutes) allocates a real relay on every transport of
every node that is not disabled, and records the result in `writable/ice/turn-health.json`. A request for
credentials reads it. `GET /api/health` lists each node as:

| `state` | meaning |
|---|---|
| `healthy` | the last probe allocated on every transport it tried |
| `degraded` | the last probe worked **but not on every transport** (e.g. UDP fine, TLS 443 not), or one probe has failed |
| `unreachable` | two consecutive probes failed (`WEBRTC_TURN_HEALTH_FAILURES`) |
| `standby` | the node's role, and healthy: held in reserve |
| `disabled` | never served, whatever its health |
| `full` | coturn refused the probe for capacity (`486`/`508`): alive, but not a node to send more people to (condition `overloaded`) |
| `unknown` | no probe result, or one older than 15 minutes (a stopped probe job says nothing about a node) |

plus `serving` — whether a client asking right now is given this node.

Selection, for every request (the full algorithm, with costs and thresholds, is `docs/ACTIVE_ACTIVE_TURN.md`):

1. **Candidates** — servable nodes (not `disabled`) that serve the client's licence tier.
2. **Admission** — each candidate's committed capacity (lease books, plus any measured load) is checked for the tier; in `enforce` mode a node with no room is removed.
3. **Pool** — `active` nodes that are not `down`. Mumbai and Greater Noida are **regional peers**: when both are active both serve, each its own region first. `standby` nodes join only when no active node is fully working. If everything admissible is known down, all of it is offered flagged `degraded`; capacity is never overridden.
4. **Rank** — fully working nodes first, then by estimated distance (the client's measured round trip › learned › edge geography › region hint) plus a load penalty from 70 % utilization, with nodes in the same 15 ms band ordered by load. The cap on nodes per client is applied after that.
5. **Offer** — the first node with every URL it has; any other only over `WEBRTC_TURN_FALLBACK_TRANSPORTS` (default TLS), so a fallback costs one allocation, not three.

A client refused for capacity gets STUN only and `admission.outcome = denied`.

Timing: the first failed probe (≤ 5 min after the failure) makes the node `degraded`, so its peer leads for every client; the second (≤ 10 min) removes it
from what is offered. Between failure and detection a client is offered the failing node and tries it, which costs one failed gather — it does not stop
it using the peer it was also given. Recovery is one successful probe. (With a primary and a `standby` the same two steps bring the standby in and then
drop the primary.)

Tested in CI as local simulations (`lib/ice-kit/tests/simulation/failover.sh` for primary/standby and `active-active.sh` for regional peers: throwaway coturns with
different secrets, probe → health file → grant → a real browser relaying through whichever is served).
That is a test of this logic, **not** of Mumbai or Noida.

## In the browser (`RemoteSessionEngine`)

* **Recovery** — one `ConnectionSupervisor` per peer: 2.5 s grace on `disconnected`, an ICE restart at once on
  `failed`, up to three attempts with growing timeouts, fresh credentials from the second attempt, waits for
  the network while offline, gives up after 30 s with a reason (`describeVerdict`) and a **Reconnect** button.
* **Who restarts** — both sides notice a failure together, so exactly one side of a pair drives: the browser
  when the peer is a desktop agent (the agent answers restart offers but never makes one), otherwise the lower
  participant uuid. The driver offers with `iceRestart: true`; the other side sends `renegotiate`
  `{"iceRestart": true}`; a repeat within 3 s is ignored; an older client's plain `renegotiate` still gets a
  plain offer. Offers that cross anyway resolve politely (the non-driver rolls back).
* **Renewal** — `CredentialRefresher` asks `/ice-servers` at `refreshAfter`, applies the grant to every
  connection and, unless the grant says `restartOnRenewal: false`, restarts ICE on a connection whose own side
  is relayed.
* **A renegotiation no longer opens new data channels** — chat and control channels are created on the first
  offer only.
* **Diagnostics** — the Security panel says whether media is direct or relayed, through which node and over
  what, and can run a relay check. Connected, recovered and failed connections are reported to
  `…/connectivity-report`, logged as `remote.ice.report`: outcome, path, candidate types, relay node and
  protocol, ICE error categories, restarts, timings — never an address, SDP or credential.

## The desktop agent and long sessions

The agent builds its ICE configuration **once, when it joins**, and nothing in it updates that configuration
(no `set_configuration` anywhere in `desktop/`). A session's length is capped by policy
(`max_session_duration_minutes`, 5–1440; default 60, presets 30/45), so:

* With the default policy a session ends before the ordinary credential (3600 s) does. **Not a problem.**
* With a longer policy, the agent's credential would expire mid-session. On the relay measured (see *What
  credential expiry does*) its existing allocation would survive, but anything the agent must gather again
  — a restart the browser drives after a network change — would be refused: no relay candidate, so a
  session that depends on the relay would not recover.

**Fix (server side, in this change):** the device-join grant is issued for the rest of the session plus five
minutes (`Topology::sessionBoundTtl`), floor the ordinary TTL, ceiling `WEBRTC_TURN_SESSION_TTL_MAX`
(default 8 h; absolute maximum 24 h). It is still an HMAC credential bound to the participant and to the
session; browsers keep the short one because they renew. Covered by `DeviceIceTest`.

**Not done:** agent-side renewal (call the API again and re-configure the live connection). It is feasible
only if webrtc-rs 0.20 can change a live connection's ICE servers, which was not verified, and the agent
cannot be built or run on Windows here. **Residual:** a relayed session longer than
`WEBRTC_TURN_SESSION_TTL_MAX` (8 h by default, ≤ 24 h policy ceiling), or a relay build that drops live
allocations *and* a policy that long. Neither affects the default policy.

## What credential expiry does (measured, and to be re-measured)

Measured on a **local coturn 4.6.1** (`probe.php --expiry-test`, and ad hoc with a stale-nonce build):

| | result |
|---|---|
| A **new** allocation with an expired credential | **refused** (`401`) |
| An **established** allocation, refreshed after its credential expired | **kept alive** — refreshes succeeded for 3 minutes past expiry, including after a stale-nonce re-authentication |

So on that build a credential expiring does **not** cut a relayed session; what it breaks is anything that
needs a *new* allocation afterwards — an ICE restart that gathers afresh, a reconnect after a network
change. That is why clients renew before expiry and apply the new credential to the live connection.

The coturn version on the real nodes (reported as 4.18) is not the one measured, so **run A3 of the runbook on each real node**. If an
established allocation does *not* survive there, keep `WEBRTC_TURN_RESTART_ON_RENEWAL = true` (the default;
it moves relayed connections onto renewed credentials ahead of expiry) and treat agent-side renewal as
launch-blocking for sessions longer than the TTL. If it does survive on both, `false` removes a needless
ICE restart every ~48 minutes from every relayed connection.

## Active-active routing and licence-aware admission (kit 1.2.0)

Additions to `aicountly.ice/1`; every field is optional for an older client, which ignores it.

| Where | Field | Meaning |
|---|---|---|
| request body | `region` | a hint naming a node region (`in-west`, `in-north`); never a filter |
| request body | `rtt` | `{nodeId: ms}` — the client's own measured time to each node (`web/src/ice-kit/latency.js`); advice, bounded 1–3000 ms |
| grant | `admission` | `{tier, outcome, reason, mode}`; `outcome` is `admitted`, `unmetered` (no capacity configured), `denied` (capacity — relay is STUN-only) or `unconfigured`; `reason` is `free_pool_full`, `node_overloaded`, `node_full`, … |
| grant `relayNodes[]` | `load` | `normal`, `busy`, `overloaded`, `full` |
| grant `relayNodes[]` | `proximity` | `{ms, basis}` — why this node ranked where it did (`measured`, `learned`, `geo`, `region`, `unknown`) |
| grant `relayNodes[]` | `urls` | the URLs *offered*: all of them for the first node, the fallback transports for the others |
| connectivity report | `admission` | the outcome the client saw (an enumeration) |
| TURN username | `<expiry>:p.<subject>` / `f.<subject>` | the tier, signed into the credential so the relay's own log attributes traffic to it |

Configuration keys: `WEBRTC_TURN_ADMISSION` (`off` | `observe` default | `enforce`), `WEBRTC_TURN_PAID_RESERVE_PERCENT` (≥ 50),
`WEBRTC_TURN_BUSY_AT`, `WEBRTC_TURN_SHED_FREE_AT`, `WEBRTC_TURN_BALANCE_BAND_MS`, `WEBRTC_TURN_PROFILE_<NAME>_KBPS`,
`WEBRTC_TURN_FALLBACK_TRANSPORTS`, per node `_LAT`, `_LON`, `_MAX_ALLOCATIONS`, `_MAX_MBPS`, `_TIERS`, `_PAID_RESERVE_PERCENT`,
files `WEBRTC_TURN_LEASE_FILE`, `_LOAD_FILE`, `_LOAD_MAX_AGE`, `_LATENCY_FILE`, and optional edge geography `WEBRTC_GEO_*`.

Shared-ledger and licence settings (kit 1.3.0 / 1.4.0): `WEBRTC_TURN_LEDGER_DSN`, `WEBRTC_TURN_LEDGER_USER` (non-secret) and `WEBRTC_TURN_LEDGER_PASSWORD`
(a secret — injected by `bin/ops/inject-turn-secrets.sh`, never `.env.example`), `WEBRTC_TURN_LEDGER_TIMEOUT_MS`. Who is paid comes from the **central licensing backend**, decided per user in the active company's context
through the common adapter (`docs/LICENCE_ENTITLEMENT_CONTRACT.md`): `WEBRTC_LICENCE_API_URL`, `_ENVIRONMENT`, `_KEY_ID`, `_PUBLIC_KEYS` (non-secret), `WEBRTC_LICENCE_S2S_SECRET` (a secret,
same injector), `WEBRTC_TURN_ENTITLEMENT_MAX_AGE_HOURS` (a signed entitlement issued longer ago stops counting as paid), `WEBRTC_LICENCE_PAID_KINDS`, `WEBRTC_LICENCE_DEFAULT_MAX_GUESTS` / `WEBRTC_LICENCE_GUESTS_PAID_QUALITY` (meeting-guest defaults), `WEBRTC_LICENCE_FETCH_ON_MISS` (fetch a missing member-level entitlement on demand), `WEBRTC_LICENCE_COMPAT`
(`allow` default = the product's legacy mapping may stand in where the backend is silent; `deny` = central evidence only) and `WEBRTC_TURN_LICENCE_FEED=confirmed` (an operator's attestation).
A node run as two coturn instances sets `WEBRTC_TURN_NODE_<ID>_GROUP` and `_STRUCTURAL_RESERVE` (`docs/TURN_BANDWIDTH_ENFORCEMENT.md`). `admission.reason` also takes `ledger_unavailable`
(free user refused because the shared ledger could not be reached while enforcing). While the licence readiness gate is not satisfied the effective `admission.mode` is `observe` even if `enforce` is configured.

## Secrets

* The only place a TURN secret lives is the coturn config and the API host's `api/.env`. The grant carries
  `HMAC(secret, "<expiry>:<subject>")`, never the secret; `TurnNode` hides it from `print_r`/`var_dump`/JSON
  and marks its parameters `#[SensitiveParameter]`; the probe's output and reports contain no credential.
* **Injection:** `bin/ops/inject-turn-secrets.sh` reads `KEY=VALUE` lines from **stdin** and writes only
  `WEBRTC_TURN_NODE_<ID>_SECRET` / `WEBRTC_TURN_STATIC_AUTH_SECRET` keys into `api/.env`, refusing any other key,
  a placeholder or a value the `.env` parser would choke on, and two nodes given the same secret. It prints key
  names and "written / already current", never a value, and keeps the file's owner and mode.
* **Repository secrets** the deploy workflows read: production — `TURN_MUM_SECRET`; sandbox —
  `SANDBOX_TURN_MUM_SECRET`, `SANDBOX_TURN_NCR_SECRET`. **There is no production secret for Noida**, so
  production's `.env` cannot hold one; add it only when Noida is cleared.
* **Why per-node secrets matter here:** with one shared secret, a relay that is compromised (or simply run by
  someone else) could replay at its sibling the credentials it is handed. Noida carries an unresolved
  privileged-access concern; its secret must be treated as exposed if that machine is.
* **Rotation:** add the new secret to coturn as a *second* `static-auth-secret` line, update the repository
  secret and deploy, then remove the old one from coturn after the longest credential lifetime (the TTL, or
  `WEBRTC_TURN_SESSION_TTL_MAX` for agent sessions). In-flight credentials keep validating throughout.

## Operating it

| | |
|---|---|
| What would be served, and every node's state | `php lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --status` |
| Real allocation + negative tests + certificate + DNS | `… --node=mum --allocate --negative --expect-ip=mum=148.135.210.65` |
| What an expiring credential does | `… --node=mum --transports=tcp --expiry-test=tcp` |
| Grant files for the browser check | `… --node=mum --transports=tls --emit-grant=/tmp/g.json --ttl=900` (0600; live credentials) |
| Health, every 5 minutes | `turn-probe` in `bin/ops/jobs.txt` → `writable/ice/turn-health.json` (**production deploy installs it; sandbox deploys do not**) |
| At a glance | `GET /api/health` → `relayNodes: [{id, status, servable, health, condition, state, serving, load, servingBy}]` |
| Per-connection outcomes | `php lib/ice-kit/bin/summarize-reports.php writable/logs/log-YYYY-MM-DD.log` |
| Browser relay check | `web/src/ice-kit/tools/relay-check.mjs` (`ICE_EXPECT_NODE`, `ICE_EXPECT_PROTOCOL`, `ICE_REPORT`) |
| Local failover simulation | `lib/ice-kit/tests/simulation/failover.sh` |

The exact commands, pass criteria and what to send back: `docs/TURN_VALIDATION_RUNBOOK.md`.

## Remaining

See `docs/LAUNCH_READINESS_TURN.md` for the blocker matrix, risks and the launch recommendation.
