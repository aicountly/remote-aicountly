#!/usr/bin/env bash
# relay-e2e.sh — browser end-to-end through the real TURN relays, with credentials the API host mints. Runs on a CI runner.
#
#   SSH="ssh deploy-target" REMOTE_ROOT=… PHP_CLI=… PW_DIR=<dir with playwright> OUT=results.txt REPORT_DIR=reports \
#     bash scripts/ci/relay-e2e.sh
#
# Identical in Remote and Connect. For every case, grants are minted on the API host (scripts/ci/emit-grant.sh: the API's own node
# secrets, 300 s credentials), kept in a 0700 temp dir, their credentials masked in the log, and deleted at the end. Each case
# runs web/src/ice-kit/tools/relay-check.mjs in headless Chromium: two RELAY-ONLY peers (so a pass proves the relay carried it),
# video + audio decoded on the far side, data-channel echo RTT and a 1 MiB transfer.
#
# Cases — what real users meet:
#   x-<t>-mum-ncr / x-<t>-ncr-mum   a Mumbai-region user with a Delhi-region user, each on their nearest relay, over udp/tcp/tls
#   same-mum / same-ncr             two users on the same relay (both near Mumbai, both near Delhi)
#   restart-mum                     credential renewal: ICE restart onto freshly minted credentials, still relayed
#
# Output: one "=== <case>" section per case (relay-check's lines; it never prints a credential) and a "summary" section.
# Exit 0 only when every case passed. Changes nothing on the host, the relays excepted (short allocations released by the browser).
set -uo pipefail

: "${SSH:?}" "${REMOTE_ROOT:?}" "${PW_DIR:?}" "${OUT:?}"
PHP_CLI="${PHP_CLI:-}"
REPORT_DIR="${REPORT_DIR:-}"
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
check="$repo/web/src/ice-kit/tools/relay-check.mjs"
[ -f "$check" ] || { echo "relay-check.mjs not found at $check" >&2; exit 2; }

exec 3>&1   # the runner's own stdout: ::add-mask:: goes there, never into $OUT (which becomes annotations)
grants="$(mktemp -d)"; chmod 700 "$grants"
trap 'rm -rf "$grants"' EXIT
: > "$OUT"
[ -n "$REPORT_DIR" ] && mkdir -p "$REPORT_DIR"

mint() { # node transport file
  local q
  q="$(printf '%q ' "$REMOTE_ROOT" "$PHP_CLI" "$1" "$2" 300)"
  $SSH "bash -s -- $q" < "$here/emit-grant.sh" > "$3" 2> "$3.err" || { echo "could not mint a grant for $1 over $2: $(head -c 200 "$3.err")"; return 1; }
  # Mask every credential before anything else runs (the grant itself is never printed).
  jq -r '.iceServers[].credential' "$3" | while IFS= read -r c; do [ -n "$c" ] && echo "::add-mask::$c" >&3; done
  return 0
}

declare -a NAMES RESULTS
run_case() { # name nodeA nodeB transport [restart]
  local name="$1" a="$2" b="$3" t="$4" restart="${5:-}" rc
  local ga="$grants/$name-a.json" gb="$grants/$name-b.json" gf="$grants/$name-fresh.json"
  echo "=== $name" >> "$OUT"
  echo "peer A on $a, peer B on $b, over $t${restart:+, then an ICE restart onto fresh credentials}" >> "$OUT"
  if ! mint "$a" "$t" "$ga" >> "$OUT"; then NAMES+=("$name"); RESULTS+=("FAIL (no grant)"); return; fi
  local env=(ICE_GRANT="$ga" ICE_EXPECT_NODE="$a" ICE_EXPECT_PROTOCOL="$t")
  if [ "$a" != "$b" ]; then
    mint "$b" "$t" "$gb" >> "$OUT" || { NAMES+=("$name"); RESULTS+=("FAIL (no grant)"); return; }
    env+=(ICE_PEER_GRANT="$gb" ICE_EXPECT_PEER_NODE="$b")
  fi
  if [ -n "$restart" ]; then
    sleep 2
    mint "$a" "$t" "$gf" >> "$OUT" || { NAMES+=("$name"); RESULTS+=("FAIL (no grant)"); return; }
    env+=(ICE_FRESH_GRANT="$gf")
  fi
  [ -n "$REPORT_DIR" ] && env+=(ICE_REPORT="$REPORT_DIR/$name.json")
  (cd "$PW_DIR" && env "${env[@]}" timeout 180 node "$check") >> "$OUT" 2>&1
  rc=$?
  echo "(exit $rc)" >> "$OUT"
  NAMES+=("$name"); [ "$rc" -eq 0 ] && RESULTS+=("PASS") || RESULTS+=("FAIL (exit $rc)")
}

for t in udp tcp tls; do
  run_case "x-$t-mum-ncr" mum ncr "$t"
  run_case "x-$t-ncr-mum" ncr mum "$t"
done
run_case same-mum mum mum udp
run_case same-ncr ncr ncr udp
run_case restart-mum mum mum tcp restart

failed=0
{
  echo "=== summary"
  for i in "${!NAMES[@]}"; do
    printf '%-16s %s\n' "${NAMES[$i]}" "${RESULTS[$i]}"
    [ "${RESULTS[$i]}" = PASS ] || failed=$((failed + 1))
  done
  [ "$failed" -eq 0 ] && echo "ALL PASS" || echo "$failed case(s) FAILED"
} >> "$OUT"
[ "$failed" -eq 0 ]
