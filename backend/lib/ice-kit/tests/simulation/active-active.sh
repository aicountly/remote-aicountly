#!/usr/bin/env bash
#
# LOCAL ACTIVE-ACTIVE SIMULATION — a test of the application's routing, admission and accounting logic
# against real coturn processes, NOT evidence about the real Mumbai or Greater Noida nodes.
#
# Three throwaway coturns on loopback, each with its own secret: "mum" and "ncr" are regional peers (both
# `active`), and "qt" is a plain coturn with `total-quota=4`, used only to show what coturn's own quotas do.
#
#   0. coturn's quota is tier-blind        4 free credentials exhaust it; a paid one is then refused
#   1. regional peers                      west clients are served by mum, north clients by ncr (real browser)
#   2. one peer stops                      after two probes everyone is served by the other (real browser)
#   3. the peer returns                    service goes back to the nearest peer
#   4. the paid reserve                    free users fill their share of each node and spill to the other
#                                          region, are then refused; paid users still get the reserve, with
#                                          coturn confirming the allocations really exist (collector)
#   5. traffic by tier                     the relay's own log attributes carried bytes to free/paid
#
# It starts and stops only the processes it started, on ports of its own.
#
# Needs: php, turnserver. For the browser phases also node + Playwright's Chromium:
#   RELAY_CHECK=/path/to/web/src/ice-kit/tools/relay-check.mjs RELAY_CHECK_CWD=/path/to/web \
#   CHROMIUM_PATH=/path/to/chrome  active-active.sh
# Exit 0 only when every check that ran passed.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
KIT="$(cd "$HERE/../.." && pwd)"
command -v turnserver >/dev/null || { echo "turnserver (coturn) is not installed" >&2; exit 2; }
command -v php >/dev/null || { echo "php is not installed" >&2; exit 2; }

MUM_PORT=${SIM_MUM_PORT:-15478}; NCR_PORT=${SIM_NCR_PORT:-16478}; QT_PORT=${SIM_QT_PORT:-17478}
WORK="$(mktemp -d)"
PIDS=()
pass=0; fail=0; skipped=0

cleanup() { for p in "${PIDS[@]:-}"; do [ -n "$p" ] && kill "$p" 2>/dev/null; done; rm -rf "$WORK"; }
trap cleanup EXIT

ok()    { printf '  \033[32mPASS\033[0m %s\n' "$1"; pass=$((pass + 1)); }
bad()   { printf '  \033[31mFAIL\033[0m %s (%s)\n' "$1" "$2"; fail=$((fail + 1)); }
check() { [ "$2" = "$3" ] && ok "$1" || bad "$1" "expected '$2', got '$3'"; }

start_relay() { # name port secret relay-port-base [extra turnserver args...] -> pid in $RELAY_PID
  local name="$1" port="$2" secret="$3" base="$4"; shift 4
  turnserver -n --use-auth-secret --static-auth-secret="$secret" --realm="$name.sim" \
    --listening-ip=127.0.0.1 --relay-ip=127.0.0.1 --listening-port="$port" --no-tls --no-dtls \
    --min-port="$base" --max-port=$((base + 99)) --no-cli --allow-loopback-peers --verbose --simple-log \
    --userdb="$WORK/$name.sqlite" --log-file="$WORK/$name.log" --pidfile="$WORK/$name.pid" "$@" >/dev/null 2>&1 &
  RELAY_PID=$!
  sleep 1.5
}

MUM_SECRET="sim-mumbai-secret-$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')"
NCR_SECRET="sim-noida-secret-$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')"
QT_SECRET="sim-quota-secret-$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')"

# capacity: 4 allocations per node, one URL (udp) each => 4 "units"; 50% (2) reserved for paid.
cat > "$WORK/boot.php" <<PHP
<?php
require_once '$KIT/autoload.php';
\$env = [
  'WEBRTC_TURN_NODES' => 'mum,ncr', 'WEBRTC_STUN_URLS' => '', 'WEBRTC_TURN_MAX_NODES' => getenv('SIM_MAX_NODES') ?: '2',
  'WEBRTC_TURN_ADMISSION' => 'enforce', 'WEBRTC_TURN_BALANCE_BAND_MS' => '15',
  'WEBRTC_TURN_NODE_MUM_HOST' => '127.0.0.1', 'WEBRTC_TURN_NODE_MUM_PORT' => '$MUM_PORT', 'WEBRTC_TURN_NODE_MUM_STATUS' => 'active',
  'WEBRTC_TURN_NODE_MUM_REGION' => 'in-west', 'WEBRTC_TURN_NODE_MUM_LAT' => '19.076', 'WEBRTC_TURN_NODE_MUM_LON' => '72.878',
  'WEBRTC_TURN_NODE_MUM_TRANSPORTS' => 'udp', 'WEBRTC_TURN_NODE_MUM_STUN' => 'false', 'WEBRTC_TURN_NODE_MUM_SECRET' => '$MUM_SECRET',
  'WEBRTC_TURN_NODE_MUM_MAX_ALLOCATIONS' => '4',
  'WEBRTC_TURN_NODE_NCR_HOST' => '127.0.0.1', 'WEBRTC_TURN_NODE_NCR_PORT' => '$NCR_PORT', 'WEBRTC_TURN_NODE_NCR_STATUS' => 'active',
  'WEBRTC_TURN_NODE_NCR_REGION' => 'in-north', 'WEBRTC_TURN_NODE_NCR_LAT' => '28.474', 'WEBRTC_TURN_NODE_NCR_LON' => '77.504',
  'WEBRTC_TURN_NODE_NCR_TRANSPORTS' => 'udp', 'WEBRTC_TURN_NODE_NCR_STUN' => 'false', 'WEBRTC_TURN_NODE_NCR_SECRET' => '$NCR_SECRET',
  'WEBRTC_TURN_NODE_NCR_MAX_ALLOCATIONS' => '4',
];
return [
  'topology' => Aicountly\Ice\Topology::fromEnvironment(fn (\$k) => \$env[\$k] ?? null),
  'health'   => new Aicountly\Ice\HealthStore('$WORK/health.json', 900, 2),
  'leases'   => new Aicountly\Ice\FileLeaseStore('$WORK/leases.json'),
  'load'     => new Aicountly\Ice\LoadStore('$WORK/load.json', 180),
];
PHP

probe()  { php "$KIT/bin/probe.php" --bootstrap="$WORK/boot.php" --allocate --write-health --timeout=2 >/dev/null 2>&1; }
grant()  { php "$HERE/sim-grant.php" "$WORK/boot.php" "$@" > "$WORK/grant.json"; }                      # tier region subject session
served() { grant "$@" && php -r '$g = json_decode(file_get_contents($argv[1]), true); $n = array_column($g["relayNodes"], "id"); echo $n ? implode(",", $n) : "none", "|", $g["admission"]["outcome"], "|", $g["admission"]["reason"];' "$WORK/grant.json"; }
hold()   { php "$HERE/hold-allocation.php" 127.0.0.1 "$1" "$2" "$3" "${4:-6}"; }                        # port secret subject seconds
bg_hold() { hold "$@" > "$WORK/hold.$3.out" 2>&1 & HOLD_PIDS+=($!); PIDS+=($!); }
collect() { bash "$KIT/bin/turn-node-load.sh" --node="$1" --iface=lo --relay-min="$2" --relay-max=$(($2 + 99)) --sample=1; }
# A browser given two relays may pick either for its selected pair, so the browser phases ask for a grant
# with ONE node (the first choice) and prove that node carries data; the routing itself is asserted on the full grant.
browser() { # expected-node tier region subject session
  SIM_MAX_NODES=1 grant "$2" "$3" "$4" "$5"
  if [ -z "${RELAY_CHECK:-}" ]; then printf '  \033[33mSKIP\033[0m browser relay check (set RELAY_CHECK)\n'; skipped=$((skipped + 1)); return; fi
  if (cd "${RELAY_CHECK_CWD:-.}" && ICE_GRANT="$WORK/grant.json" ICE_EXPECT_NODE="$1" ICE_TOLERATE_NODES="${2:-}" CHROMIUM_PATH="${CHROMIUM_PATH:-}" \
        timeout 120 node "$RELAY_CHECK" > "$WORK/browser.out" 2>&1); then
    ok "a real browser relayed data through node '$1'"
  else
    bad "a real browser relayed data through node '$1'" "$(grep -E '^FAIL|error' "$WORK/browser.out" | head -2 | tr '\n' ' ')"
  fi
}

echo "SIMULATION (local coturns, not the real nodes) — mum=:$MUM_PORT, ncr=:$NCR_PORT (both active), qt=:$QT_PORT (quota demo)"
start_relay mum "$MUM_PORT" "$MUM_SECRET" 43000; MUM_PID=$RELAY_PID; PIDS+=("$MUM_PID")
start_relay ncr "$NCR_PORT" "$NCR_SECRET" 44000; NCR_PID=$RELAY_PID; PIDS+=("$NCR_PID")
start_relay qt "$QT_PORT" "$QT_SECRET" 45000 --total-quota=4; QT_PID=$RELAY_PID; PIDS+=("$QT_PID")
HOLD_PIDS=()

echo; echo "0. coturn's own quota knows nothing about licence tiers"
for i in 1 2 3 4; do bg_hold "$QT_PORT" "$QT_SECRET" "f.crowd$i" 8; done
sleep 2
r="$(hold "$QT_PORT" "$QT_SECRET" "p.customer" 2)"
check "four FREE credentials exhaust a total-quota=4 relay and a PAID one is refused" "REFUSED allocation_quota_reached" "$r"
wait "${HOLD_PIDS[@]}" 2>/dev/null; HOLD_PIDS=()

echo; echo "1. regional peers, both serving"
probe
check "a Mumbai-region paid client is served by mum first, then ncr as fallback" "mum,ncr|admitted|ok" "$(served paid in-west w1 sw1)"
browser mum paid in-west bw1 sbw1
check "a Noida-region paid client is served by ncr first, then mum" "ncr,mum|admitted|ok" "$(served paid in-north n1 sn1)"
browser ncr paid in-north bn1 sbn1
rm -f "$WORK/leases.json"

echo; echo "2. the Mumbai relay stops"
kill "$MUM_PID" 2>/dev/null; wait "$MUM_PID" 2>/dev/null; sleep 1
probe; probe
check "both regions are now served by ncr alone, with no failover flag (peers, not primary/standby)" "ncr|admitted|ok" "$(served paid in-west w2 sw2)"
browser ncr paid in-west bw2 sbw2
check "…including a free client" "ncr|admitted|ok" "$(served free in-west w3 sw3)"
php "$KIT/bin/probe.php" --bootstrap="$WORK/boot.php" --status 2>/dev/null | grep -E "^  (mum|ncr) " | sed 's/^/    /'
rm -f "$WORK/leases.json"

echo; echo "3. it returns"
start_relay mum "$MUM_PORT" "$MUM_SECRET" 43000; MUM_PID=$RELAY_PID; PIDS+=("$MUM_PID")
probe
check "the next probe returns Mumbai-region clients to Mumbai" "mum,ncr|admitted|ok" "$(served paid in-west w4 sw4)"
browser mum paid in-west bw3 sbw3
rm -f "$WORK/leases.json"

echo; echo "4. the paid reserve, across regional fallback (one node per client, 4 units each, 2 reserved for paid)"
# Fresh relays, so the allocation counts below are only the ones this phase opened.
kill "$MUM_PID" "$NCR_PID" 2>/dev/null; wait "$MUM_PID" "$NCR_PID" 2>/dev/null; sleep 1
start_relay mum "$MUM_PORT" "$MUM_SECRET" 43000; MUM_PID=$RELAY_PID; PIDS+=("$MUM_PID")
start_relay ncr "$NCR_PORT" "$NCR_SECRET" 44000; NCR_PID=$RELAY_PID; PIDS+=("$NCR_PID")
probe
rm -f "$WORK/leases.json" "$WORK/load.json"
export SIM_MAX_NODES=1
check "free #1 (west) -> mum"             "mum|admitted|ok" "$(served free in-west f1 sf1)"
check "free #2 (west) -> mum"             "mum|admitted|ok" "$(served free in-west f2 sf2)"
check "free #3 (west): mum's free share is full, so it spills to ncr" "ncr|admitted|ok" "$(served free in-west f3 sf3)"
check "free #4 (west) -> ncr"             "ncr|admitted|ok" "$(served free in-west f4 sf4)"
check "free #5 (west): both free shares are full -> no relay, and the reason is stated" "none|denied|free_pool_full" "$(served free in-west f5 sf5)"
check "paid #1 (west) -> mum, from its reserve" "mum|admitted|ok" "$(served paid in-west p1 sp1)"
check "paid #2 (west): mum is 75% committed (busy), so the equally-near peer takes it" "ncr|admitted|ok" "$(served paid in-west p2 sp2)"
check "paid #3 (west) -> mum, filling its last unit" "mum|admitted|ok" "$(served paid in-west p3 sp3)"
check "paid #4 (west): mum is now full; ncr has paid room left" "ncr|admitted|ok" "$(served paid in-west p4 sp4)"

echo "   coturn confirms the books: holding the admitted allocations for real, then counting them"
for s in "f.f1" "f.f2" "p.p1" "p.p3"; do bg_hold "$MUM_PORT" "$MUM_SECRET" "$s" 12; done
for s in "f.f3" "f.f4" "p.p2" "p.p4"; do bg_hold "$NCR_PORT" "$NCR_SECRET" "$s" 12; done
sleep 2
check "mum carries 4 allocations (2 free + 2 paid), as booked" "4" "$(collect mum 43000 | php -r 'echo json_decode(stream_get_contents(STDIN), true)["allocations"];')"
check "ncr carries 4 allocations (2 free + 2 paid), as booked" "4" "$(collect ncr 44000 | php -r 'echo json_decode(stream_get_contents(STDIN), true)["allocations"];')"
collect mum 43000 | php "$KIT/bin/ingest-load.php" --bootstrap="$WORK/boot.php" >/dev/null
php "$KIT/bin/probe.php" --bootstrap="$WORK/boot.php" --status 2>/dev/null | grep -E "capacity" | sed 's/^/    /'
wait "${HOLD_PIDS[@]}" 2>/dev/null; HOLD_PIDS=()
unset SIM_MAX_NODES

echo; echo "5. traffic by tier, from the relay's own log"
sleep 1
rm -f "$WORK/leases.json" "$WORK/load.json"
browser mum paid in-west t1 st1
browser mum free in-west t2 st2
sleep 1
php "$KIT/bin/summarize-usage.php" "$WORK/mum.log" --json > "$WORK/usage.json" 2> "$WORK/usage.err" || cat "$WORK/usage.err" 
paid_bytes="$(php -r '$u = json_decode(file_get_contents($argv[1]), true); echo $u["tiers"]["paid"]["bytes"];' "$WORK/usage.json")"
free_bytes="$(php -r '$u = json_decode(file_get_contents($argv[1]), true); echo $u["tiers"]["free"]["bytes"];' "$WORK/usage.json")"
if [ -n "${RELAY_CHECK:-}" ]; then
  [ "${paid_bytes:-0}" -gt 1000 ] && ok "the relay's log attributes ${paid_bytes} bytes to the paid tier" || bad "paid bytes in the relay's log" "got '${paid_bytes:-}'"
  [ "${free_bytes:-0}" -gt 1000 ] && ok "…and ${free_bytes} bytes to the free tier" || bad "free bytes in the relay's log" "got '${free_bytes:-}'"
else
  printf '  \033[33mSKIP\033[0m traffic attribution (needs the browser phases)\n'; skipped=$((skipped + 1))
fi

echo; printf 'passed %d, failed %d, skipped %d\n' "$pass" "$fail" "$skipped"
[ "$fail" -eq 0 ]
