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
| `unknown` | no probe result, or one older than 15 minutes (a stopped probe job says nothing about a node) |

plus `serving` — whether a client asking right now is given this node.

Selection, for every request:

1. **Active nodes that are not `unreachable`.** A node declared down is never handed out beside a working one.
2. **Standby nodes join** whenever no active node is fully working — the primary is down, has lost a
   transport, or has just failed one probe. A fully healthy primary is served **alone**.
3. **Last resort:** if everything servable is `unreachable`, all of it is offered, active first, flagged
   `degraded: true` — any may have recovered since the last probe, and offering nothing cannot be better.
4. Fully working nodes lead, then the client's region hint, then priority; the cap on nodes per client
   (`WEBRTC_TURN_MAX_NODES`, default 2) is applied **after** that, so it can never cut a working standby in
   favour of a degraded primary. A `disabled` node cannot be reached by any of this.

Timing: the first failed probe (≤ 5 min after the failure) brings the standby in beside the primary; the
second (≤ 10 min) drops the primary. Between failure and detection a client is offered the primary and
tries it, which costs one failed gather — it does not stop it using the others it was given. Recovery is
one successful probe.

Tested in CI as a local simulation (`lib/ice-kit/tests/simulation/failover.sh`: two throwaway coturns with
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
| At a glance | `GET /api/health` → `relayNodes: [{{id, status, servable, health, condition, state, serving}}]` |
| Per-connection outcomes | `php lib/ice-kit/bin/summarize-reports.php writable/logs/log-YYYY-MM-DD.log` |
| Browser relay check | `web/src/ice-kit/tools/relay-check.mjs` (`ICE_EXPECT_NODE`, `ICE_EXPECT_PROTOCOL`, `ICE_REPORT`) |
| Local failover simulation | `lib/ice-kit/tests/simulation/failover.sh` |

The exact commands, pass criteria and what to send back: `docs/TURN_VALIDATION_RUNBOOK.md`.

## Remaining

See `docs/LAUNCH_READINESS_TURN.md` for the blocker matrix, risks and the launch recommendation.
