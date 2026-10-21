#!/usr/bin/env bash
#
# SELF-TEST OF THE TIER-SHAPING ARTIFACTS ON A REAL KERNEL, IN NETWORK NAMESPACES — not evidence about any TURN host.
#
# Builds a "server" and a "client" namespace joined by a veth pair, loads infra/tier-split/nftables-tier.nft.example and
# applies infra/tier-split/tc-tier-shaping.sh.example to the server's interface at a small link rate, then measures what
# a free flood and a paid flow each actually get — with and without the shaper. Needs root (CAP_NET_ADMIN), nft, tc,
# python3. It touches only the two namespaces it creates and removes them on exit.
#
#   sudo bash shaping-netns.sh [LINK_MBIT=40] [SECONDS=6]
set -uo pipefail
[ "$(id -u)" -eq 0 ] || { echo "needs root" >&2; exit 2; }
for c in ip nft tc python3; do command -v "$c" >/dev/null || { echo "needs $c" >&2; exit 2; }; done
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"; INFRA="$HERE/../../infra/tier-split"
LINK="${1:-40}"; SECS="${2:-6}"; PAIDG=$(( LINK / 2 )); 
cleanup() { ip netns del tsrv 2>/dev/null; ip netns del tcli 2>/dev/null; rm -f /tmp/tier-netns.py; }
trap cleanup EXIT; cleanup
ip netns add tsrv; ip netns add tcli
ip link add vsrv type veth peer name vcli
ip link set vsrv netns tsrv; ip link set vcli netns tcli
ip -n tsrv addr add 10.9.0.1/24 dev vsrv; ip -n tcli addr add 10.9.0.2/24 dev vcli
ip -n tsrv link set vsrv up; ip -n tcli link set vcli up; ip -n tsrv link set lo up; ip -n tcli link set lo up

cat > /tmp/tier-netns.py <<'PY'
import socket, sys, time, threading
mode, *a = sys.argv[1:]
if mode == "rx":                       # rx <seconds> <port>...: count bytes per destination port
    secs = float(a[0]); ports = [int(p) for p in a[1:]]; got = {p: 0 for p in ports}; stop = time.time() + secs + 1
    def run(p):
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); s.bind(("10.9.0.2", p)); s.settimeout(0.2); t0 = None
        while time.time() < stop:
            try: d = s.recv(2000); got[p] += len(d)
            except socket.timeout: pass
    ts = [threading.Thread(target=run, args=(p,)) for p in ports]; [t.start() for t in ts]; [t.join() for t in ts]
    print(" ".join(f"{p}:{got[p]*8/secs/1e6:.1f}" for p in ports))
else:                                   # tx <sport> <dport> <Mbit/s offered> <seconds>
    sport, dport, mbit, secs = int(a[0]), int(a[1]), float(a[2]), float(a[3])
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); s.setsockopt(socket.SOL_SOCKET, socket.SO_SNDBUF, 1 << 22)
    s.bind(("10.9.0.1", sport)); pkt = b"x" * 1200; end = time.time() + secs; sent = 0; t0 = time.time()
    while time.time() < end:
        s.sendto(pkt, ("10.9.0.2", dport)); sent += len(pkt) * 8
        ahead = sent / (mbit * 1e6) - (time.time() - t0)
        if ahead > 0.002: time.sleep(ahead)
PY

scenario() { # label paid-offered free-offered
  ip netns exec tcli python3 /tmp/tier-netns.py rx "$SECS" 9001 9002 > /tmp/tier-rx.out &
  sleep 0.5
  [ "$3" != "0" ] && ip netns exec tsrv python3 /tmp/tier-netns.py tx 3479 9002 "$3" "$SECS" &
  [ "$2" != "0" ] && ip netns exec tsrv python3 /tmp/tier-netns.py tx 3478 9001 "$2" "$SECS" &
  wait
  printf '  %-58s paid %s\n' "$1" "$(cat /tmp/tier-rx.out | sed 's/9001:/ /; s/9002:/ free /')"
}

echo "link ${LINK} Mbit/s, reserve 50% => paid guaranteed ${PAIDG}, shared ceiling ${PAIDG}"
ip netns exec tsrv tc qdisc replace dev vsrv root handle 1: tbf rate "${LINK}mbit" burst 64k latency 50ms   # the "NIC": a plain bottleneck
echo "WITHOUT the shaper (one FIFO bottleneck):"
scenario "free floods at $((LINK*3)), paid offers $((PAIDG+PAIDG/2))" $((PAIDG+PAIDG/2)) $((LINK*3))
ip netns exec tsrv tc qdisc del dev vsrv root 2>/dev/null
ip netns exec tsrv nft -f "$INFRA/nftables-tier.nft.example"
ip netns exec tsrv env IFACE=vsrv LINK_MBIT="$LINK" RESERVE_PCT=50 bash "$INFRA/tc-tier-shaping.sh.example" apply
echo "WITH the shaper:"
scenario "free alone floods at $((LINK*3))"                         0 $((LINK*3))
scenario "paid alone offers $((PAIDG+PAIDG/2))"                     $((PAIDG+PAIDG/2)) 0
scenario "free floods at $((LINK*3)), paid offers $((PAIDG+PAIDG/2))" $((PAIDG+PAIDG/2)) $((LINK*3))
scenario "free floods at $((LINK*3)), paid offers $((PAIDG/2))"       $((PAIDG/2)) $((LINK*3))
echo "counters (nftables, the network's own view of each tier):"
ip netns exec tsrv nft list table inet tierctl | awk '/counter (paid|shared)_(out|in) \{/ {name=$2} /packets/ {print "  " name ": " $0; name=""}'
PO=$(ip netns exec tsrv nft list counter inet tierctl paid_out   | awk '/packets/ {print $2}')
SO=$(ip netns exec tsrv nft list counter inet tierctl shared_out | awk '/packets/ {print $2}')
[ "${PO:-0}" -gt 0 ] && [ "${SO:-0}" -gt 0 ] && echo "PASS: both tier counters moved" || { echo "FAIL: a tier counter is empty"; exit 1; }
