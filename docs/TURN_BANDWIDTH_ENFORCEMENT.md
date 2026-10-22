# TURN bandwidth enforcement and its load test — Remote and Connect (shared)

Identical in `aicountly/remote-aicountly` and `aicountly/connect-aicountly`. The relay nodes are shared, so is this.

**Question.** Application-side admission works from *estimates* (kbit/s per usage profile). How is it technically
guaranteed that free traffic cannot consume the bandwidth reserved for paid users — and how will that be proven?

**Answer in one paragraph.** Admission is only the first of three layers. The second is coturn itself, run as **two
instances per host** (a paid-only one and a shared one) with hard allocation and bandwidth limits on the shared one.
The third — the one that makes the guarantee true whatever either of the others believes — is the **network**: the
shared instance's packets are classified by port, and an HTB shaper on the egress NIC gives the paid class a
guaranteed rate of `R × link` and caps the shared class at `(1 − R) × link`. The guarantee is then *measured* with a
three-phase load test on the real node, with pass criteria fixed in advance, and watched afterwards by a
reconciliation job that compares the relay's own byte counts per tier with what the books claim.

## 0. Status labels

| Label | Meaning |
|---|---|
| **Implemented and tested** | Code or artifacts exist in the repositories and a test ran (stated where) |
| **Locally evidenced** | Ran against local software in the build sandbox (coturn 4.6.1, a Linux 6.18 namespace) — not the real nodes |
| **Proposal** | Designed, artifacts drafted, not run anywhere; needs the infrastructure team |
| **Assumption** | A number or fact nobody has measured |

## 1. The three layers

| # | Layer | Enforces | Based on | Status |
|---|---|---|---|---|
| 1 | Admission at credential issue/renewal (`Admission::evaluate`, shared ledger) | free `reserved ≤ cap − max(paid, R·cap)`; shed new free at 85 % | **Estimates** per usage profile, corrected by the measured node load | Implemented and tested (unit, PostgreSQL race test with two products) |
| 2 | coturn per instance: `total-quota`, `bps-capacity`, `max-bps`, `user-quota` | the shared instance cannot allocate beyond its share; each session is throttled | coturn's own allocation accounting | Locally evidenced on 4.6.1 (below). Real nodes run 4.18.0-1.el9: **assumption** that it behaves the same |
| 3 | nftables classification + `tc` HTB on the egress NIC | shared class ≤ `(1−R)·link`; paid class ≥ `R·link` whatever shared offers | Packets actually leaving the NIC | Locally evidenced in a network namespace (§3). Not on the real NIC |

Layer 3 is what answers the question. Layers 1 and 2 keep the system from *asking* the network to do the impossible.

## 2. Tier-split relays (layer 2)

Per physical node: instance **paid** (ports 3478/TLS, relay range 40000-44999) and instance **shared**
(3479/TLS, 45000-49999). The application issues credentials for the paid instance to paid users only; free users — and
paid users when the paid instance is full — get the shared one. Files: `backend/lib/ice-kit/infra/tier-split/`
(`README.md` is the how-to). The kit models the pair as one *group* (`WEBRTC_TURN_NODE_<ID>_GROUP`) so capacity is
booked once per machine and `Topology` refuses a configuration whose structural reserve is below the declared paid
reserve (**implemented and tested**, `TierSplitTest`).

### Formulas (the infrastructure team fills these from Part F measurements)

Let `link` = sustainable egress rate (Mbit/s, measured, not the advertised port speed), `B` = sustainable relayed
throughput per direction (bytes/s), `A` = sustainable allocations, `R` = paid reserve (≥ 0.5; **default 0.5**).

| Setting | Paid instance | Shared instance |
|---|---|---|
| `total-quota` | ⌈1.1·R·A⌉ | ⌊(1−R)·A⌋ (hard end of the free share) |
| `bps-capacity` | ⌈1.1·R·B⌉ | ⌊(1−R)·B⌋ |
| `max-bps` (per session) | 1 000 000 B/s | 500 000 B/s |
| HTB class | `1:10` rate `R·link`, ceil `link`, prio 0 | `1:20` rate `0.05·link`, ceil `(1−R)·link`, prio 1 |

Worked example (illustrative numbers, **not measured**): `link = 1000 Mbit/s`, `R = 0.5` → paid guaranteed 500 Mbit/s
(may borrow idle capacity up to 1000), shared never above 500. With `A = 4000` → shared `total-quota = 2000`.

### What was measured about coturn locally (4.6.1)

* `max-bps` **throttles an individual session** (a client offering above it is held to it).
* `bps-capacity` **reserves at allocation time**: once the sum of reserved rates reaches it, further allocations are
  refused with **486 Allocation Quota Reached**; it does not shape traffic. So `bps-capacity` stops the shared instance
  from *growing* beyond its share (and the application sees the refusal as a `full` node), while `max-bps` bounds each
  session — the product `(sessions) × max-bps` is what layer 1 books, and layer 3 backstops.
* `-y` (client-to-client) in `turnutils_uclient` doubles allocations per client — account for it in load tests.

## 3. Network shaping (layer 3) — locally evidenced

`bash tests/loadtest/shaping-netns.sh 40 6` (root, Linux, `ip netns`, `nft`, `tc`): builds a client and a server
namespace joined by a veth pair, loads `nftables-tier.nft.example`, applies `tc-tier-shaping.sh.example` on a 40 Mbit/s
link with `R = 50 %`, and drives UDP from the paid and shared source ports, measuring per-port goodput at the client.

```
link 40 Mbit/s, reserve 50% => paid guaranteed 20, shared ceiling 20
WITHOUT the shaper (one FIFO bottleneck):
  free floods at 120, paid offers 30        paid 18.6  free 20.5     <- the paid user gets a FIFO share and loses 38 %
WITH the shaper:
  free alone floods at 120                  paid  0.0  free 20.8     <- free capped at (1-R)*link, link not full
  paid alone offers 30                      paid 30.0  free  0.0     <- paid borrows above its 20 guarantee
  free floods at 120, paid offers 30        paid 30.0  free 10.1     <- paid gets everything it asked for
  free floods at 120, paid offers 10        paid 10.0  free 20.8     <- free never exceeds 20 even with paid idle-ish
counters: paid_out and shared_out both non-zero   PASS (the script exits non-zero otherwise)
```

What this shows: with the shaper, a flood from the shared instance leaves the paid flow untouched, and the shared
class is held at its ceiling. What it does not show: the real NIC's rate, real coturn traffic, packet rates in the
hundreds of thousands per second, or the behaviour of the OVH hypervisor's own limiter (Part B measures those).

An earlier version classified with `tc filter … fw` and failed with `TC classifier not found` on this kernel; the test
caught it, and classification now uses `meta priority set 1:10|1:20` in nftables (HTB honours the priority as the class
id), which needs no `cls_*` module. Re-run the namespace self-test on the real host *before* touching its NIC (it uses
veth/namespaces, not the real interface).

## 4. Known limits

1. **Egress only.** tc shapes what leaves; a flood *arriving* is not slowed by it (the nft file has a commented ingress
   rate limit for the shared UDP ports; enable only after measuring — dropping is crude for TCP).
2. **TLS on 443.** One IP gives 443 to one process. The recommended answer is a **second public IP** for the shared
   instance (the paid instance keeps 443 on the first); fallback: an SNI proxy, classified by upstream port; last
   resort: free users get 5349 only. **Open decision for infrastructure** (is a second IP available on the OVH server?).
3. **CPU and conntrack are not shaped.** Measure them in Part B.
4. **The structural reserve is symmetric by byte, not by session.** Many tiny shared sessions can exhaust `total-quota`
   (allocation refusals) well before bandwidth; that is the intended failure mode for free — they are refused, paid is not.
5. **Noida.** All of the above must be repeated on Greater Noida; its clearance (security review) is separate and
   outstanding. It stays out of production.

## 5. The load test on the real node (Part B)

Prerequisites: staging applied per `infra/tier-split/README.md` on **one** host (Mumbai first, off-peak, an agreed
window), both instances answer `probe.php --allocate --negative`, `LINK_MBIT` set to the **measured** sustainable
rate. A test machine near the node with `coturn-utils` (`turnutils_uclient`), `jq`, 2 × `grant.json` emitted from the
API host.

```bash
# API host (staging) — grants for the two instances. Contain live credentials: scp them, never paste, delete afterwards.
PHP=/opt/cpanel/ea-php83/root/usr/bin/php
$PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=mum-paid   --tier=paid --emit-grant=/tmp/paid.json --ttl=900
$PHP lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=mum-shared --tier=free --emit-grant=/tmp/free.json --ttl=900

# Test machine
bash tests/loadtest/tier-isolation.sh \
     --paid-host turn-mum.aicountly.com        --paid-port 3478 \
     --free-host turn-mum-shared.aicountly.com --free-port 3479 \
     --paid-grant /tmp/paid.json --free-grant /tmp/free.json \
     --free-ceiling-kBps 25000 --paid-load-kBps 500 --free-clients 12 --seconds 60
# --free-ceiling-kBps is (1-R) x link / 8 x 1000: 25000 for link = 400 Mbit/s, R = 0.5. Recompute from the measured link.
```

Run it **twice**: once at low load (mechanics; passes trivially because nothing competes), once with
`--free-clients` and `--free-ceiling-kBps` sized so the free *offered* load exceeds `(1−R)·link` (the guarantee).

### Pass criteria (fixed before the run)

| | Criterion | Measures |
|---|---|---|
| C1 | free throughput `F ≤ (1−R)·link × 1.10` | free is capped at its share |
| C2 | paid throughput with the flood `P1 ≥ P0 × 0.90` | paid lost nothing |
| C3 | paid loss with the flood `L1 ≤ L0 + 0.5 pp` | and no extra loss |
| C4 | some free allocations refused (486) — or a recorded statement that the flood fitted under the cap | `bps-capacity`/`total-quota` act |
| C5 | `nft list counters inet tierctl`: `shared_out` rate ≤ `(1−R)·link × 1.10` over the same window | the network's own count agrees with the test client's |
| C6 | `bin/reconcile.php --usage-log=… --window=…` reports no `free_traffic_over_booked_share` and no `bandwidth_drift` above tolerance | the relay's per-tier bytes agree with the books |
| C7 | host CPU < 70 % and no conntrack drops during the saturation run | the node itself was not the bottleneck |

A run that cannot saturate the link is reported as **mechanics only — guarantee not shown**, not as a pass.

### Failure handling

* C1 fails → shaper not active or `LINK_MBIT` too high: `tc -s class show dev <nic>` (class `1:20` drops/overlimits).
* C2/C3 fail with C1 passing → paid and free share a bottleneck *upstream* of the NIC (hypervisor limit): lower `LINK_MBIT`.
* C5 and the client disagree → ports in `nftables-tier.nft.example` do not match the conf files.
* `rollback`: `IFACE=<nic> bash tc-tier-shaping.sh.example remove; nft delete table inet tierctl`.

## 6. Continuous verification (after go-live)

* `bin/turn-node-load.sh` (cron, per node) + `bin/ingest-load.php` feed the measured load into the shared ledger; admission
  already charges unexplained traffic to **both** tiers, so a drift makes the system more conservative, not less.
* `bin/reconcile.php --product=remote|connect --usage-log=<paid.log,shared.log> --window=3600` (cron, hourly, report
  only, exits non-zero on findings): `config_mismatch` (two products disagree about a node's capacity),
  `free_over_share`, `booked_over_capacity`, `no_telemetry`, `unattributed_allocations`, `leaked_leases`,
  `bandwidth_drift`, `free_traffic_over_booked_share`.
* Alert on any non-zero exit. Enforcement is switched on only with these running.

## 7. What is still an assumption

`A`, `B`, `link`, per-class kbit/s, whether 4.18.0-1.el9 matches the 4.6.1 behaviour recorded in §2, whether the OVH
hypervisor imposes its own limit, and whether a second public IP exists for TLS-443 isolation.
