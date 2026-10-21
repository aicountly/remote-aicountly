# TURN validation runbook — AICOUNTLY Remote

How to prove, on the real infrastructure, that the relays carry traffic for Remote — and to hand back
the evidence. Everything here is **non-disruptive**: it allocates and releases a few relay sessions with
short-lived credentials, changes nothing on a TURN server, and needs no root.

> **TLS and firewall checks do not prove relaying.** A certificate that verifies and a port that accepts
> a connection say nothing about whether coturn will *authenticate* a credential this application minted,
> *allocate* a relay for it, open the relay port range, and *forward* a peer's packets. Those are the four
> things below, and only a real allocation, then real data through it, shows them.

## What is already known, and what is not

| | Status |
|---|---|
| The application mints coturn REST credentials per node, from a per-node secret | Tested (unit, HTTP, and against a local coturn 4.6.1) |
| A relay allocation, a refusal of wrong/expired/missing credentials, and a browser relaying data, over UDP/TCP/TLS | Tested **against a local coturn only** |
| The same against `turn-mum.aicountly.com` and `turn-ncr.aicountly.com` | **Not yet run — this is what the commands below are for** |

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

## Part D — failover in staging, without touching any server

This changes only the **staging application's** `.env`; no TURN server, firewall or DNS is touched.

1. In staging's `api/.env`, point Mumbai at a closed port: `WEBRTC_TURN_NODE_MUM_PORT = 3499` and
   `WEBRTC_TURN_NODE_MUM_TLS_PORT = 3499`; set `WEBRTC_TURN_NODE_NCR_STATUS = standby`.
2. `$PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --allocate --write-health` — run it
   twice (the cron job, where installed, does this every 5 minutes).
3. `--status` must show `mum active unreachable`, `ncr standby … SERVING NOW`, and `/health` the same.
4. Start a Remote session: the Security panel / connectivity report must name **ncr**.
5. Restore the two ports; run the probe once; `--status` must show `mum … SERVING NOW` and ncr not.

The same chain, on two throwaway local relays, runs in CI
(`lib/ice-kit/tests/simulation/failover.sh`) — useful, but a test of the logic, not of your nodes.

## Part E — what to send back

```
/tmp/probe-mum.txt  /tmp/evidence-mum.json  /tmp/expiry-mum-{udp,tcp,tls}.json
/tmp/evidence-ncr.json (staging)             browser-mum-{udp,tcp,tls,restart}.json
the summarize-reports output                 curl …/health | jq .relayNodes   (before and during Part D)
turnserver --version                         grep -viE 'secret|password' /etc/turnserver.conf
```

`grep -vi` keeps the shared secret out of what you send. Please also say which network each browser run
used and whether UDP was blocked there.

## Open questions this will settle

* Whether the **API host** may reach 3478 outbound (shared hosting often blocks it). If not, the health
  probe can only use TLS 443; nodes stay correct (any working transport counts) but the UDP and TCP rows
  will read `degraded`. Run Part A from the API host *and* from any other machine to tell a blocked
  probe from a broken node.
* Whether an established allocation survives its credential's expiry (A3) — on the actual coturn build.
* That the relay's per-peer rules allow what real sessions need (Part C).
