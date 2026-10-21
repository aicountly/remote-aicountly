# TURN validation runbook — AICOUNTLY Remote

How to prove, on the real infrastructure, that the relays carry traffic for Remote — and to hand back
the evidence. Everything here is **non-disruptive**: it allocates and releases a few relay sessions with
short-lived credentials, changes nothing on a TURN server, and needs no root.

> **TLS and firewall checks do not prove relaying.** A certificate that verifies and a port that accepts
> a connection say nothing about whether coturn will *authenticate* a credential this application minted,
> *allocate* a relay for it, open the relay port range, and *forward* a peer's packets. Those are the four
> things below, and only a real allocation, then real data through it, shows them.

> **Update 2026-10-21.** The staging deployment sequence, the observe-mode configuration and the first authenticated API-host commands for
> Remote desktop sessions, Connect calls, regional selection, bandwidth measurement and failover are in `docs/STAGING_TURN_DEPLOYMENT.md` §7
> (they use the commands of Parts A–G below). Infrastructure reports coturn **4.18.0-1.el9** on both nodes, valid TLS on 443, and Noida's
> self-IP peer denial corrected — A2 is the first test that verifies this from the API host. Denial/spill tests (Part D2, F5) need `enforce`,
> which is held back by the licence readiness gate until the mappings and feed are complete; in `observe` they are read from the audit log.

## What is already known, and what is not

| | Status |
|---|---|
| The application mints coturn REST credentials per node, from a per-node secret | Tested (unit, HTTP, and against a local coturn 4.6.1) |
| A relay allocation, a refusal of wrong/expired/missing credentials, and a browser relaying data, over UDP/TCP/TLS | Tested **against a local coturn only** |
| The same against `turn-mum.aicountly.com` and `turn-ncr.aicountly.com` | **Not yet run — this is what the commands below are for** |
| Regional routing, the paid reserve, overload shedding, load measurement | Implemented and tested against **local** relays only; **every capacity number is an assumption** until Part F |

## 0. Before you start

* Run Part A and B against **staging first**; production only ever needs Mumbai.
* `turn-ncr` is validated **in staging only**. It stays out of production until the security clearance
  for that machine is recorded — see *Greater Noida* in `docs/LAUNCH_READINESS_TURN.md`.
* The commands print no secret. The grant files in Part A4 do contain **live credentials** (valid for
  15 minutes); copy them with `scp`, never paste them, and delete them when done.
* PHP CLI on the API host (cPanel): `PHP=/opt/cpanel/ea-php83/root/usr/bin/php` — any CLI with the
  `openssl` extension and sockets. All commands run from the API folder (`cd <docroot>/api`).

## Part A — on the API host (the machine that mints the credentials)

**A1. What would be served, and is the configuration sane**

```bash
$PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --status
```

Expect: `relay configured: yes`; `mum  active`; in production `ncr  disabled`, in staging `ncr  standby`; **no
`warning:` lines** (a warning about two nodes sharing a secret means NCR is using Mumbai's — fix that before
going on). Probe state is `unknown` until the first probe runs.

**A2. Authenticated allocation, negative tests, certificate and address — Mumbai**

```bash
$PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=mum --allocate --negative \
     --expect-ip=mum=148.135.210.65 | tee /tmp/probe-mum.txt
$PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=mum --allocate --negative \
     --expect-ip=mum=148.135.210.65 --json > /tmp/evidence-mum.json; echo "exit=$?"
```

Pass criteria — all of:

| Check | Expected line / value |
|---|---|
| The name points where it should | `PASS mum … turn-mum.aicountly.com -> 148.135.210.65` |
| UDP 3478 | `udp  binding ok …   allocation ok …` |
| TCP 3478 | `tcp  binding ok …   allocation ok …` |
| TURN/TLS 443 | `tls  binding ok …   allocation ok …` |
| The certificate is real | `certificate VERIFIED for turn-mum.aicountly.com`, issuer a public CA, **days remaining ≥ 14** |
| A forged credential is refused | `must refuse wrongSecret  REJECTED` on every transport |
| An expired credential is refused | `must refuse expired      REJECTED` on every transport |
| No credential is refused | `must refuse anonymous    REJECTED` on every transport |
| Exit code | `0` |

Reading a failure: `connect_failed` / `timeout` = network or firewall (the API host may be blocked from
reaching 3478 — see *Open questions*); `tls_failed` = certificate (the line below it says expired, wrong
name or untrusted); `auth_rejected` on the **allocation** = the secret in `.env` is not coturn's;
`allocation_quota_reached` / `insufficient_capacity` = coturn limits; `open_relay` or `ACCEPTED` on a
negative test = **a security finding**: stop and tell the infrastructure team.

**A3. What happens when a credential expires (about 80 seconds per transport)**

```bash
for t in udp tcp tls; do
  $PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=mum --transports=$t --expiry-test=$t \
       --json > /tmp/expiry-mum-$t.json
done
$PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=mum --transports=tcp --expiry-test=tcp
```

Expect for every transport: `new allocation with the expired credential  REFUSED auth_rejected` — and
**record** whether `an established allocation SURVIVES … or DOES NOT SURVIVE` its credential expiring.
That single line decides whether `WEBRTC_TURN_RESTART_ON_RENEWAL` can be switched off (see
`docs/ICE_CONTRACT.md`). If it says **DOES NOT SURVIVE**, a relayed session is cut when its credential
expires: keep the default, and tell us — that raises the priority of agent-side renewal.

**A4. Grants for the browser test (Part B)**

```bash
for t in udp tcp tls; do
  $PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=mum --transports=$t \
       --emit-grant=/tmp/grant-mum-$t.json --ttl=900
done
sleep 60
$PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=mum --emit-grant=/tmp/grant-mum-renewed.json --ttl=900
```

**A5. Noida — staging only**

```bash
$PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=ncr --allocate --negative \
     --expect-ip=ncr=103.69.196.150 --json > /tmp/evidence-ncr.json; echo "exit=$?"
```

Same pass criteria, with `turn-ncr.aicountly.com` and `103.69.196.150`. Then A3 and A4 for `--node=ncr`
(files `…-ncr-…`). **Do this on the staging API host**, whose `.env` carries NCR's secret; production's
does not, by design.

## Part B — on a test machine with Chromium

Use two networks if you can: an ordinary one (UDP works) and a restrictive one (UDP blocked, or only
443 outbound — a phone hotspot with a firewall, a corporate guest network). The restrictive one is what
the TLS relay exists for.

```bash
git clone --depth 1 https://github.com/aicountly/remote-aicountly    # or copy web/src/ice-kit
cd remote-aicountly/web
npm install --no-save playwright && npx playwright install chromium

scp apihost:/tmp/grant-mum-*.json .            # live credentials: delete when done

for t in udp tcp tls; do
  ICE_GRANT=grant-mum-$t.json ICE_EXPECT_NODE=mum ICE_EXPECT_PROTOCOL=$t \
  ICE_REPORT=browser-mum-$t.json node src/ice-kit/tools/relay-check.mjs
done
# an ICE restart onto renewed credentials, still relayed:
ICE_GRANT=grant-mum-tcp.json ICE_FRESH_GRANT=grant-mum-renewed.json ICE_EXPECT_NODE=mum \
ICE_REPORT=browser-mum-restart.json node src/ice-kit/tools/relay-check.mjs

shred -u grant-mum-*.json 2>/dev/null || rm -f grant-mum-*.json
```

Each run must end `PASS` on every line, including `the relay that carried it was node 'mum'` and
`over udp|tcp|tls`. A UDP-blocked network should fail the `udp` run and pass `tcp`/`tls` — that is the
point of testing there. If the allocation lines pass but `exchanged data` fails, see the note below.

> The check puts both peers on the **same relay**. A coturn that denies peers at its own address
> (`denied-peer-ip`) refuses one allocation forwarding to the other (coturn logs `403 Forbidden IP`).
> That is a limit of this single-node check, not of real sessions; Part C and D use two real peers.

## Part C — Remote in staging, end to end

Deploy the integration branch to **staging** with the manual sandbox workflow (it injects the secrets over
stdin — `docs/LAUNCH_READINESS_TURN.md` §4), then:

For the relay tests, set `WEBRTC_FORCE_RELAY = true` in **staging's** `.env` (never production's): every
connection is then relay-only, so a pass proves the relay carried it. Remove it afterwards.

| # | Do | Expect |
|---|---|---|
| 1 | Browser A starts a session; browser B joins with the code; A admits B | B is **not** given a signalling token or credentials before admission: `POST /api/v1/remote/sessions/{uuid}/ice-servers` as B answers `403 AWAITING_APPROVAL` until A admits, then `200` |
| 2 | A shares a screen, B watches; chat both ways; B sends A a file | Picture, chat and the file arrive (data channel over the relay). **Security panel** on both: *Encrypted, through AICOUNTLY's Mumbai relay* (over TLS/TCP/UDP as the network dictates) |
| 3 | In the Security panel press **Check relay connection** | *The relay is reachable from this network (Mumbai: …)* |
| 4 | Enrolled **Windows desktop agent** (staging): connect to it unattended from a browser, request control, click and type | Picture/control work; panel shows the relay and node. `remote.ice.report` lines for both participants |
| 5 | Browser offline for ~10 s (DevTools → Network → Offline), then back | *Reconnecting…* then *Connected*; the next report has `restarts ≥ 1`, `outcome` `recovered` |
| 6 | Offline for > 30 s | *Unable to reconnect* with a reason (e.g. *the relay server could not be reached from this network*) and a **Reconnect** button that works once the network is back |
| 7 | Set `WEBRTC_TURN_TTL = 120`; keep a relayed session open ≥ 6 min | Stays connected; the summary shows `credentialRenewals ≥ 2` (and `iceRestarts` rising if `WEBRTC_TURN_RESTART_ON_RENEWAL` is true). Repeat with it `false`: renewals but no restarts |
| 8 | **Agent beyond the ordinary TTL:** with `WEBRTC_TURN_TTL = 120` and the policy's session length ≥ 15 min, hold an unattended relayed session for > 5 min, then take the browser offline for 10 s | The session reconnects: the agent's credential (session-long) was still valid when ICE restarted |
| 9 | A ends the session | Both sides close; `POST …/ice-servers` now answers `409 SESSION_ALREADY_ENDED`; a viewer who was never admitted still gets `403` on `…/connectivity-report` |
| 10 | Unauthenticated `POST …/ice-servers`; > 30 requests in a minute | `401`; then `429` |

After the tests, from the staging API host:

```bash
$PHP lib/ice-kit/bin/summarize-reports.php writable/logs/log-$(date +%F).log
curl -s https://remote.gh.aicountly.com/api/health | jq '.relayNodes'
```

The summary shows how many sessions were relayed, through which node over which transport, the time to
connect, ICE errors by node, restarts and credential renewals — the Remote evidence.

## Part D — regional fallback in staging, without touching any server

Mumbai and Greater Noida are regional peers (`docs/ACTIVE_ACTIVE_TURN.md`). This changes only the **staging
application's** `.env`; no TURN server, firewall or DNS is touched. Staging only: Noida is not enabled in
production until it is cleared.

**D1 — a peer becomes unreachable.**

1. In staging's `api/.env`: both nodes `STATUS = active`; point Mumbai at a closed port
   (`WEBRTC_TURN_NODE_MUM_PORT = 3499`, `WEBRTC_TURN_NODE_MUM_TLS_PORT = 3499`).
2. `$PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --allocate --write-health` twice (the cron
   job does this every 5 minutes where installed — **sandbox deploys do not install it**).
3. `--status --explain` must show `mum … unreachable`, and a client in *every* region offered `ncr` only,
   with no `failover` flag (peers, not primary and standby). `/health` shows the same.
4. Start a staging session from a Mumbai-region network: the Security panel / connectivity report must name **ncr**.
5. Restore the two ports; run the probe once; `--status --explain` must show Mumbai-region clients going to `mum` again.

**D2 — a peer fills up (the capacity spill).** Still staging, `WEBRTC_TURN_ADMISSION = enforce`, and a small
capacity so it can be seen: `WEBRTC_TURN_NODE_MUM_MAX_ALLOCATIONS = 12` (and the same for `ncr`).

1. Open free-plan sessions from a Mumbai-region network until `--status` shows Mumbai's free share used
   (`reserved alloc … free 6` of 12 — half is the paid reserve). The next free session must be offered `ncr`.
2. Fill Noida's free share too. The next free session must receive **no relay**: `admission.outcome = denied`,
   `reason = free_pool_full`, and the Security panel says relay capacity is fully used (not "no relay is configured").
3. A paid-plan session started *now*, from the same network, must still get a relay (`admission.outcome = admitted`).
4. End a free session: `--status` must show its allocations returned at once, and the next free session is admitted.

Expected output of `--status --explain` after step 2 (shape, not exact numbers):

```
  free client in in-west  -> (no relay)  denied  free_pool_full
  paid client in in-west  -> mum,ncr     admitted
```

The same chains run in CI on throwaway local relays (`lib/ice-kit/tests/simulation/failover.sh`,
`active-active.sh`) — useful, but a test of the logic, not of your nodes.

## Part F — capacity, admission and the paid reserve, against the real nodes

Nothing in the capacity model has been measured on Mumbai or Noida. Every number the application uses —
what a session costs, what a node sustains, where it should start shedding — is an **assumption** until this
part replaces it. Run F1–F3 on Mumbai first; Noida only in staging and only after its clearance.
Synthetic load consumes real bandwidth: agree a window with the infrastructure team, run off-peak, and stop at the first sign of
loss. The tools below are coturn's own (`turnutils_uclient`, `turnutils_peer`) — flags vary by version; check `-h`.

**F1 — what one session costs a node (replaces the profile assumptions).**
On the node (infrastructure team, unprivileged): `bash turn-node-load.sh --node=mum --sample=30` — this prints
one JSON line: concurrent allocations (UDP sockets in the relay range) and rx/tx bit/s on the NIC. Take it:

1. idle (B0);
2. with `k` relay-only staging sessions of ONE class running for 5 minutes (staging only: `WEBRTC_FORCE_RELAY = true`) —
   a Remote screen-share session, a Connect 1:1 video call, an audio-only call, a 4-person room participant;
3. footprint per client = `(rx+tx with k) − (rx+tx at idle)`, divided by `k`; allocations per client the same way.

Repeat per class with `k` = 1, 3, 6 and keep the median. Put the results into
`WEBRTC_TURN_PROFILE_REMOTE_KBPS`, `…_CALL_KBPS`, `…_ROOM_KBPS`, `…_AUDIO_KBPS`. If allocations per client are not 3 on the
first node and 1 on the fallback node, the allocation estimate is wrong — say so.

**F2 — what a node sustains (sets MAX_ALLOCATIONS and MAX_MBPS).**
From a test machine near the node, with a short-lived credential for synthetic load
(`$PHP lib/ice-kit/bin/probe.php … --node=mum --tier=free --emit-grant=/tmp/g.json --ttl=900`; take the
`username` and `credential` of the TURN entry), ramp client-to-client sessions:

```
U=$(jq -r '.iceServers[]|select(.username)|.username' /tmp/g.json); P=$(jq -r '.iceServers[]|select(.username)|.credential' /tmp/g.json)
for m in 25 50 100 200 400; do
  turnutils_uclient -y -m $m -n 2000 -l 500 -z 20 -u "$U" -w "$P" turn-mum.aicountly.com | tail -4
done
```

(each step must finish inside the credential's 15 minutes; mint a fresh one between steps if not). While it runs, on the node:
`turn-node-load.sh` every 10 s and `top -b -n1 | head -15`. The **sustainable** capacity is the largest step where packet loss stays under 0.5 %,
jitter under 30 ms and CPU under 70 %; set `MAX_ALLOCATIONS` / `MAX_MBPS` to **80 % of it**. Record the step
at which the node degrades — that is where `BUSY_AT` / `SHED_FREE_AT` should sit below.

**F3 — do coturn's own limits behave as assumed?** With the infrastructure team's agreement, on a *staging copy* of the relay config
(or Noida, before it is enabled), set `total-quota`, `user-quota`, `max-bps`, `bps-capacity` as in `docs/ACTIVE_ACTIVE_TURN.md` § 8 and check, with the
probe and `turnutils_uclient`: a node at `total-quota` refuses a new allocation with `486`/`508` (the probe reports `allocation_quota_reached`
and the health file marks the node `full`); one credential cannot hold more than `user-quota` allocations; a session above `max-bps` is
throttled. **Also record that a free-tier and a paid-tier credential are treated identically** — that is the reason the application layer exists.

**F4 — does measurement agree with the books and with coturn?** With 10 known sessions running: `turn-node-load.sh` allocations must equal what the
admission books reserved (`probe.php --status` → `reserved alloc paid + free`) within the browsers' variance, and measured bit/s must be
within a factor of ~1.5 of reserved bit/s. A large gap means the profile (F1) or the allocation estimate is wrong; the application then charges the
difference to both tiers, which is safe but wasteful.

**F5 — does the reserve hold under load?** Staging, `enforce`, capacities from F2. From test machines: (a) start a free flood — mint free-tier
grants (`--tier=free`) in a loop until the application refuses (`--status --explain` shows `free … denied`); (b) while the flood runs, start paid sessions
(`--tier=paid`) up to the reserve — **every one must be admitted**; (c) record the free share reached (expect ≤ `1 − reserve%`), and that the node's measured
utilization stayed under the shed threshold plus the paid headroom. Any paid refusal while paid usage is inside the reserve is a defect.

**F6 — does the relay's own log attribute traffic by tier?** After F5 (or any session mix), on the node:
`journalctl -u coturn --since '1 hour ago' | grep -c 'usage:'` must be non-zero; then on the API host, with the log copied or piped in:
`$PHP lib/ice-kit/bin/summarize-usage.php <logfile> --window=3600`. Free bytes must be a plausible share of the total and no larger than the free share of the
books. **If there are no `usage:` lines, or they have another format, say so** — the format was read from coturn 4.6.1 and the nodes are reported as "4.18".

## Part G — routing between the regions, from real networks

**G1 — does each region get its nearer node?** From at least: a Mumbai-area network, a Delhi/NCR network, and one elsewhere (mobile data, another city).
On each, with staging in `observe`: open a staging session; read the connectivity report / Security panel for the selected node;
run `relay-check.mjs` (Part B) and note its `measured:` line (the browser's own time to a relay on each node); and note the first node of
`POST …/ice-servers` (for Remote, the `relayNodes` order; for Connect `GET /api/calls/config`). Fill in:

| Network | measured mum ms | measured ncr ms | first node offered | node that carried data |
|---|---|---|---|---|

The first request on a new browser has no measurement and uses the region hint / edge geography; the second (after a call) uses the measurement. Both rows
matter. If the edge does not supply geography (`WEBRTC_GEO_*` unset) say so.

**G2 — reciprocal fallback:** Part D1. **G3 — overload spill:** Part D2.

**G4 — a renewal near overload keeps a session alive.** Staging, `enforce`, `WEBRTC_TURN_TTL = 120`: start a free session and a call; then fill the node
past the shed threshold with other free sessions; the original session's credentials renew at about 96 s. The session must stay connected
(`remote.ice.issue` / `connect.ice.issue` shows an admitted renewal, not `node_overloaded`). Then lower `MAX_ALLOCATIONS` below current use: the renewal may be
refused (`denied`); the client must keep its existing credentials and the session must not drop.

**G5 — Greater Noida gating (no commands).** Before `WEBRTC_TURN_NODE_NCR_STATUS` is anything but `disabled` on a production-facing deployment, the following
must be recorded in `docs/LAUNCH_READINESS_TURN.md`: Part A passed against `turn-ncr` (all three transports, negative tests, certificate, DNS); Part B passed
from a restrictive network; F1–F3 done for Noida; the **security review of the machine's historical privileged-access concern signed off**, with the date and
who accepted it; and Noida's own `static-auth-secret` (different from Mumbai's) stored as a repository secret **and rotated if the review recommends it**.

## Part E — what to send back

```
/tmp/probe-mum.txt  /tmp/evidence-mum.json  /tmp/expiry-mum-{udp,tcp,tls}.json
/tmp/evidence-ncr.json (staging)             browser-mum-{udp,tcp,tls,restart}.json
the summarize-reports output                 curl …/health | jq .relayNodes   (before and during Part D)
turnserver --version                         grep -viE 'secret|password' /etc/turnserver.conf
F1: the per-class footprint table            F2: the ramp table (clients, loss %, jitter, CPU, Mbit/s) and the chosen capacities
F3: the quota experiments                    F4: books vs measurement for 10 sessions
F5: the flood/paid run, probe --status --explain before and after     F6: summarize-usage output, one sample `usage:` line (usernames are signed, not secret)
G1: the network table                        G4: the issue log lines of the renewals
```

`grep -vi` keeps the shared secret out of what you send. Please also say which network each browser run
used and whether UDP was blocked there.

## Open questions this will settle

* Whether the **API host** may reach 3478 outbound (shared hosting often blocks it). If not, the health
  probe can only use TLS 443; nodes stay correct (any working transport counts) but the UDP and TCP rows
  will read `degraded`. Run Part A from the API host *and* from any other machine to tell a blocked
  probe from a broken node.
* Whether an established allocation survives its credential's expiry (A3) — on the actual coturn build.
* What a session costs and what a node sustains (F1–F2), and whether the nodes' `usage:` log lines have the format the tier attribution reads (F6).
* Whether each region really gets its nearer node, and whether the edge supplies geography (G1).
* That the relay's per-peer rules allow what real sessions need (Part C).
