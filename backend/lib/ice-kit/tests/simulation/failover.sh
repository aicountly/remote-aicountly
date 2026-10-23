#!/usr/bin/env bash
#
# LOCAL FAILOVER SIMULATION — a test of the application's selection logic, NOT evidence about any
# real TURN node. Two throwaway coturns on loopback, each with its OWN secret, stand in for the
# primary ("mum", active) and the secondary ("ncr", standby). It then walks the whole chain a real
# failover takes:
#
#   probe (bin/probe.php --allocate --write-health) -> health file -> IceIssuer -> grant -> browser
#
#   1. both relays up         the primary alone is served
#   2. primary stopped        after ONE failed probe the standby joins; after TWO the primary is dropped
#   3. primary restored       the next probe returns service to the primary alone
#
# Nothing here touches, or could touch, a production server: it starts and stops only the two processes
# it started itself, on ports of its own.
#
# Needs: php, turnserver (coturn). For the browser phases also node + Playwright's Chromium:
#   RELAY_CHECK=/path/to/web/src/ice-kit/tools/relay-check.mjs \
#   RELAY_CHECK_CWD=/path/to/a/dir/with/playwright CHROMIUM_PATH=/path/to/chrome  failover.sh
# Without RELAY_CHECK the browser phases are skipped and reported as skipped.
#
# Exit 0 only when every check that ran passed.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
KIT="$(cd "$HERE/../.." && pwd)"
command -v turnserver >/dev/null || { echo "turnserver (coturn) is not installed" >&2; exit 2; }
# coturn 4.18 removed --no-dtls (DTLS support is gone); older releases need it to keep DTLS off. Pass it only where it exists.
NO_DTLS=""; turnserver --help 2>&1 | grep -q -- '--no-dtls' && NO_DTLS="--no-dtls"
command -v php >/dev/null || { echo "php is not installed" >&2; exit 2; }

MUM_PORT=${SIM_MUM_PORT:-13478}
NCR_PORT=${SIM_NCR_PORT:-14478}
WORK="$(mktemp -d)"
PIDS=()
pass=0; fail=0; skipped=0

cleanup() { for p in "${PIDS[@]:-}"; do [ -n "$p" ] && kill "$p" 2>/dev/null; done; rm -rf "$WORK"; }
trap cleanup EXIT

ok()   { printf '  \033[32mPASS\033[0m %s\n' "$1"; pass=$((pass + 1)); }
bad()  { printf '  \033[31mFAIL\033[0m %s (%s)\n' "$1" "$2"; fail=$((fail + 1)); }
check() { [ "$2" = "$3" ] && ok "$1" || bad "$1" "expected '$2', got '$3'"; }

start_relay() { # name port secret relay-port-base -> pid in $RELAY_PID
  turnserver -n --use-auth-secret --static-auth-secret="$3" --realm="$1.sim" \
    --listening-ip=127.0.0.1 --relay-ip=127.0.0.1 --listening-port="$2" --no-tls $NO_DTLS \
    --min-port="$4" --max-port=$(($4 + 99)) --no-cli --allow-loopback-peers \
    --userdb="$WORK/$1.sqlite" --log-file=/dev/null --pidfile="$WORK/$1.pid" >/dev/null 2>&1 &
  RELAY_PID=$!
  sleep 1.5
}

# Two secrets that differ, as the real nodes' do.
MUM_SECRET="sim-mumbai-secret-$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')"
NCR_SECRET="sim-noida-secret-$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')"

cat > "$WORK/boot.php" <<PHP
<?php
require_once '$KIT/autoload.php';
\$env = [
  'WEBRTC_TURN_NODES' => 'mum,ncr', 'WEBRTC_STUN_URLS' => '', 'WEBRTC_TURN_MAX_NODES' => '2',
  'WEBRTC_TURN_NODE_MUM_HOST' => '127.0.0.1', 'WEBRTC_TURN_NODE_MUM_PORT' => '$MUM_PORT', 'WEBRTC_TURN_NODE_MUM_STATUS' => 'active',
  'WEBRTC_TURN_NODE_MUM_PRIORITY' => '10', 'WEBRTC_TURN_NODE_MUM_TRANSPORTS' => 'udp,tcp', 'WEBRTC_TURN_NODE_MUM_STUN' => 'false', 'WEBRTC_TURN_NODE_MUM_SECRET' => '$MUM_SECRET',
  'WEBRTC_TURN_NODE_NCR_HOST' => '127.0.0.1', 'WEBRTC_TURN_NODE_NCR_PORT' => '$NCR_PORT', 'WEBRTC_TURN_NODE_NCR_STATUS' => 'standby',
  'WEBRTC_TURN_NODE_NCR_PRIORITY' => '20', 'WEBRTC_TURN_NODE_NCR_TRANSPORTS' => 'udp,tcp', 'WEBRTC_TURN_NODE_NCR_STUN' => 'false', 'WEBRTC_TURN_NODE_NCR_SECRET' => '$NCR_SECRET',
];
return [
  'topology' => Aicountly\Ice\Topology::fromEnvironment(fn (\$k) => \$env[\$k] ?? null),
  'health'   => new Aicountly\Ice\HealthStore('$WORK/health.json', 900, 2),
];
PHP

probe()  { php "$KIT/bin/probe.php" --bootstrap="$WORK/boot.php" --allocate --write-health --timeout=2 >/dev/null 2>&1; }
served() { php "$HERE/sim-grant.php" "$WORK/boot.php" > "$WORK/grant.json" && php -r '$g = json_decode(file_get_contents($argv[1]), true); echo implode(",", array_column($g["relayNodes"], "id")), "|", $g["failover"] ? "failover" : "normal", "|", $g["degraded"] ? "degraded" : "ok";' "$WORK/grant.json"; }
browser() { # expected-node tolerated-nodes
  if [ -z "${RELAY_CHECK:-}" ]; then printf '  \033[33mSKIP\033[0m browser relay check (set RELAY_CHECK)\n'; skipped=$((skipped + 1)); return; fi
  if (cd "${RELAY_CHECK_CWD:-.}" && ICE_GRANT="$WORK/grant.json" ICE_EXPECT_NODE="$1" ICE_TOLERATE_NODES="${2:-}" CHROMIUM_PATH="${CHROMIUM_PATH:-}" \
        timeout 120 node "$RELAY_CHECK" > "$WORK/browser.out" 2>&1); then
    ok "a real browser relayed data through node '$1'"
  else
    bad "a real browser relayed data through node '$1'" "$(grep -E '^FAIL|error' "$WORK/browser.out" | head -2 | tr '\n' ' ')"
  fi
}

echo "SIMULATION (local coturns, not the real nodes) — mum=:$MUM_PORT active, ncr=:$NCR_PORT standby"
start_relay mum "$MUM_PORT" "$MUM_SECRET" 43000; MUM_PID=$RELAY_PID; PIDS+=("$MUM_PID")
start_relay ncr "$NCR_PORT" "$NCR_SECRET" 44000; NCR_PID=$RELAY_PID; PIDS+=("$NCR_PID")

echo; echo "1. both relays up"
probe
check "the primary alone is served, normally" "mum|normal|ok" "$(served)"
browser mum ""

echo; echo "2. the primary stops"
kill "$MUM_PID" 2>/dev/null; wait "$MUM_PID" 2>/dev/null; sleep 1
probe
check "after ONE failed probe the standby joins the primary, and leads" "ncr,mum|failover|ok" "$(served)"
browser ncr "mum"
probe
check "after TWO the primary is dropped and the standby serves alone" "ncr|failover|ok" "$(served)"
browser ncr "mum"
php "$KIT/bin/probe.php" --bootstrap="$WORK/boot.php" --status 2>/dev/null | sed 's/^/    /'

echo; echo "3. the primary comes back"
start_relay mum "$MUM_PORT" "$MUM_SECRET" 43000; MUM_PID=$RELAY_PID; PIDS+=("$MUM_PID")
probe
check "the next probe returns service to the primary alone" "mum|normal|ok" "$(served)"
browser mum ""

echo; echo "4. both stop (last resort)"
kill "$MUM_PID" "$NCR_PID" 2>/dev/null; wait "$MUM_PID" "$NCR_PID" 2>/dev/null; sleep 1
probe; probe
check "both are offered, flagged degraded, rather than nothing" "mum,ncr|normal|degraded" "$(served)"

echo; printf 'passed %d, failed %d, skipped %d\n' "$pass" "$fail" "$skipped"
[ "$fail" -eq 0 ]
