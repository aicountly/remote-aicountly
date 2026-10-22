# Tier-split relays — making the paid bandwidth reserve a property of the network

> **Status.** Everything in this folder is a **proposal for the infrastructure team**. Nothing has been applied to
> Mumbai or Noida. What *has* been exercised: the coturn options against a local coturn 4.6.1 (below), and the
> nftables + `tc` artifacts in a Linux network namespace (`tests/loadtest/shaping-netns.sh`). What has **not**: any of
> it on the real nodes' kernels, NICs and coturn 4.18.0-1.el9. `docs/TURN_BANDWIDTH_ENFORCEMENT.md` is the design and
> the pass criteria; this file is the how.

## Why the application alone is not enough

The application admits a client against **estimates** (kbit/s per usage profile, `docs/ACTIVE_ACTIVE_TURN.md` §3). Once
it has handed out a credential, a free client can send as much as it likes through its allocation: coturn, as
configured today, does not distinguish tiers and the NIC is one queue. Admission keeps the *expected* load of free users
inside their share; only the relay and the network can keep the *actual* load there.

## The model

One physical node runs **two coturn instances** on the same host:

| | paid-only instance | shared instance |
|---|---|---|
| Who gets credentials for it | paid clients only (`TIERS=paid`) | free clients, and paid clients as overflow (`TIERS=paid,free`) |
| Ports | 3478 (UDP/TCP), TLS port, relay range 40000-44999 | 3479, TLS port, relay range 45000-49999 |
| Allocation cap (`total-quota`) | ⌈1.1·R·A⌉ | **(1−R)·A** — the hard end of the free share |
| Bandwidth reservation (`bps-capacity`) | ⌈1.1·R·B⌉ | **(1−R)·B** — new allocations refused (486) beyond it |
| Per-session throttle (`max-bps`) | 1 MB/s | 0.5 MB/s |
| Network class (HTB) | `1:10` rate **R·link**, ceil link | `1:20` rate 5 % link, ceil **(1−R)·link** |

`R` is the paid reserve (≥ 0.5), `A` the node's measured sustainable allocations, `B` its measured sustainable relayed
throughput (bytes/s, per direction) — **both come from runbook Part F measurements, not from this repository**.

Three independent mechanisms, each of which catches what the previous cannot:

1. **Admission** (application, per credential) — keeps booked load within the pools. Estimates.
2. **coturn** (per instance, per allocation) — `total-quota` and `bps-capacity` stop the shared instance *allocating*
   beyond its share; `max-bps` throttles each session. Measured locally on 4.6.1: `max-bps` shapes an individual
   session; `bps-capacity` *reserves* at allocation time and refuses (486) once the reserved sum is reached — it does
   **not** shape, so on its own a session that stays under its `max-bps` but is numerous can still saturate the NIC.
3. **The network** (`nftables` classification + `tc` HTB on the egress NIC) — the shared class can never use more than
   (1−R) of the link and the paid class is guaranteed R of it **even when the shared class is flat out**. This is the
   only mechanism that makes "free traffic cannot consume the protected paid bandwidth" true regardless of what
   either application or coturn believes.

`TurnNode::group` (`WEBRTC_TURN_NODE_<ID>_GROUP`) tells the kit that the two instances are one machine: capacity is
booked once per group, and `Topology` refuses a configuration whose structural reserve (the paid instance's share) is
below the declared paid reserve (`WEBRTC_TURN_NODE_<ID>_STRUCTURAL_RESERVE`).

## Files

| File | What it is | Applied by |
|---|---|---|
| `turnserver-paid.conf.example`, `turnserver-shared.conf.example` | the two coturn configurations (ports, relay ranges, quotas, throttles, denied peers) | infrastructure team, as a second systemd unit |
| `nftables-tier.nft.example` | classifies egress by the instance's ports (sets the packet priority to the HTB class id) and counts bytes per tier and direction | infrastructure team, `nft -f` |
| `tc-tier-shaping.sh.example` | HTB: `1:10` paid, `1:20` shared, `1:30` everything else | infrastructure team, root, after the nft file |

Classification uses `meta priority set 1:10|1:20`, which HTB treats as the class id: **no tc filter and no `cls_*` module
is needed** (the first version used `fw` filters and failed on a kernel without `cls_fw`; the namespace self-test caught it).

## What was measured here (and what it does and does not mean)

`bash tests/loadtest/shaping-netns.sh 40 6` builds a client and a server network namespace joined by a veth pair, loads
the two files, applies the shaper to a 40 Mbit/s link with R = 50 %, and sends UDP from the paid ports (3478) and the
shared ports (3479) at once. Output on the build host (a sandbox Linux 6.18 kernel — **not** the OVH host):

```
WITHOUT the shaper (one FIFO bottleneck):
  free floods at 120, paid offers 30        paid 18.6  free 20.5      <- paid starves: it gets its FIFO share
WITH the shaper:
  free alone floods at 120                  paid  0.0  free 20.8      <- free capped at (1-R)*link = 20
  paid alone offers 30                      paid 30.0  free  0.0      <- paid may borrow above its 20 guarantee
  free floods at 120, paid offers 30        paid 30.0  free 10.1      <- paid keeps all it asked for; free gets the rest
  free floods at 120, paid offers 10        paid 10.0  free 20.8
counters:  paid_out / shared_out packets moved   (PASS)
```

It shows the mechanism works on a Linux kernel with HTB and nftables. It does **not** show that the real NIC's
sustainable rate is what you set as `LINK_MBIT` (a host may be capped upstream of its NIC), that coturn's own
behaviour behind the shaper matches, or that anything holds at production packet rates. Those need Part B of
`docs/TURN_BANDWIDTH_ENFORCEMENT.md` on the real node.

## Known limits — stated, not hidden

* **Egress only.** `tc` shapes what leaves the NIC. A relayed byte arrives before it leaves, so capping egress caps the
  pair indirectly (the relay drops what it cannot send) but does not stop a flood arriving. `nftables-tier.nft.example`
  carries a commented ingress rate limit for the shared UDP ports; use it only after measuring, because dropping is
  crude for TCP.
* **TLS on 443 cannot be told apart by port.** Restrictive networks depend on TURN/TLS on 443, and one IP can only give
  443 to one process. Options, in order of preference: (a) a **second public IP** for the shared instance on 443 — the
  paid instance keeps the first; the host name `turn-<node>-shared.aicountly.com` points at the second. (b) An **SNI
  proxy** (e.g. `nginx stream` with `ssl_preread`) on 443 that forwards by server name to the instance ports — but then
  the shaper sees the proxy's loopback hop, so it must classify by the *proxy's* upstream port. (c) Offer paid clients
  443 and free clients 5349 only — a product decision with a real cost for free users behind restrictive firewalls.
  **Open: needs an infrastructure decision (second IP available on the OVH server?).**
* **Both instances share the host's CPU and conntrack.** Bandwidth is shaped; CPU is not. Measure CPU in Part B.
* **Virtualised NICs.** If the OVH host exposes a virtio NIC with its own upstream cap, set `LINK_MBIT` to the
  sustainable rate you measured, not the advertised one.

## Apply order (staging host first, never first on production)

```bash
# 1. Two coturn instances: copy the two conf files, fill every <…>, start BOTH, check each answers (probe below).
# 2. Classification + counters:
sudo nft -f nftables-tier.nft.example
# 3. Shaping (changes the NIC's root qdisc — do it in a maintenance window, keep `remove` ready):
sudo IFACE=<nic> LINK_MBIT=<measured sustainable> RESERVE_PCT=50 bash tc-tier-shaping.sh.example apply
sudo IFACE=<nic> bash tc-tier-shaping.sh.example show
# Rollback, at any time:
sudo IFACE=<nic> bash tc-tier-shaping.sh.example remove ; sudo nft delete table inet tierctl
```

Then `tests/loadtest/tier-isolation.sh` (header documents the flags) at low load for mechanics and at saturation for
the guarantee, and `bin/reconcile.php --usage-log=… ` to compare what each tier carried with what the books say.
