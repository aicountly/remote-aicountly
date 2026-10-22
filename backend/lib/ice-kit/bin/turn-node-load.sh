#!/usr/bin/env bash
# turn-node-load.sh — print one JSON load report for a TURN node. Read-only, no root, no coturn interface.
#
#   turn-node-load.sh --node=mum [--iface=eth0] [--relay-min=40000] [--relay-max=49999] [--sample=3]
#
# What it measures, and how (nothing here talks to coturn):
#   allocations  UDP sockets bound in the relay port range — one per TURN allocation (/proc/net/udp and udp6).
#                Needs no privileges and no installed tools; it does not name the processes.
#   rxBps/txBps  bytes per second on the interface over a --sample interval, from /proc/net/dev. This is the
#                NIC's total, so it includes anything else the host sends; run it on a dedicated relay.
#
# Meant to be installed on the node as the FORCED COMMAND of a dedicated, unprivileged login that the API
# host's scheduler connects to (restrict,command="…/turn-node-load.sh --node=mum" in authorized_keys), so
# the key can run this and nothing else. This repository never installs it: the infrastructure team does.
#
# Output (one line): {"node":"mum","at":1792590000,"allocations":212,"rxBps":18400000,"txBps":19100000}
set -uo pipefail

node=""; iface=""; rmin=40000; rmax=49999; sample=3
for arg in "$@"; do
  case "$arg" in
    --node=*) node="${arg#--node=}" ;;
    --iface=*) iface="${arg#--iface=}" ;;
    --relay-min=*) rmin="${arg#--relay-min=}" ;;
    --relay-max=*) rmax="${arg#--relay-max=}" ;;
    --sample=*) sample="${arg#--sample=}" ;;
    -h|--help) sed -n '2,17p' "$0"; exit 0 ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done

[[ "$node" =~ ^[a-z0-9][a-z0-9-]{0,31}$ ]] || { echo "--node=<id> is required (a-z, 0-9, '-')" >&2; exit 2; }
[[ "$rmin" =~ ^[0-9]+$ && "$rmax" =~ ^[0-9]+$ && "$sample" =~ ^[0-9]+$ && "$sample" -ge 1 && "$sample" -le 30 ]] || { echo "bad numeric option" >&2; exit 2; }

if [ -z "$iface" ]; then
  iface="$(awk '$2 == "00000000" { print $1; exit }' /proc/net/route 2>/dev/null)"
fi
[ -n "$iface" ] && grep -q "^ *${iface}:" /proc/net/dev || { echo "cannot find interface '${iface:-?}' (use --iface)" >&2; exit 2; }

read_bytes() { awk -v i="${iface}:" '$1 == i { print $2, $10 }' /proc/net/dev; }

read -r rx1 tx1 < <(read_bytes)
t1=$(date +%s.%N)
sleep "$sample"
read -r rx2 tx2 < <(read_bytes)
t2=$(date +%s.%N)

# /proc/net/udp{,6}: local_address is HEXIP:HEXPORT. Parsed here rather than with `ss` so the script needs
# nothing installed; each coturn allocation holds exactly one UDP relay socket in the range.
allocations=$(cat /proc/net/udp /proc/net/udp6 2>/dev/null | awk -v lo="$rmin" -v hi="$rmax" '
  NR > 0 && $2 ~ /:[0-9A-Fa-f]+$/ { n = split($2, a, ":"); p = 0; h = toupper(a[n]);
    for (i = 1; i <= length(h); i++) p = p * 16 + index("0123456789ABCDEF", substr(h, i, 1)) - 1;
    if (p >= lo && p <= hi) c++ }
  END { print c + 0 }')

awk -v node="$node" -v at="$(date +%s)" -v al="$allocations" -v rx1="$rx1" -v rx2="$rx2" -v tx1="$tx1" -v tx2="$tx2" -v t1="$t1" -v t2="$t2" 'BEGIN {
  dt = t2 - t1; if (dt <= 0) dt = 1
  # Counters are unsigned 64-bit and wrap; a negative delta means a wrap or a reset: report 0, not nonsense.
  rx = (rx2 - rx1) * 8 / dt; tx = (tx2 - tx1) * 8 / dt
  if (rx < 0) rx = 0; if (tx < 0) tx = 0
  printf "{\"node\":\"%s\",\"at\":%d,\"allocations\":%d,\"rxBps\":%d,\"txBps\":%d}\n", node, at, al, rx, tx
}'
