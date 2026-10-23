#!/usr/bin/env bash
# turn-staging-check.sh — the first real-server TURN tests, run ON the sandbox API host by the sandbox deploy workflow.
#
#   ssh deploy-target "bash -s -- <remote root> <php cli or ''> <remote|chat> [strict]" < scripts/ci/turn-staging-check.sh
#
# Identical in Remote and Connect. READ-ONLY and non-disruptive: it reads the API's own configuration and asks each relay for
# a short-lived allocation with a freshly minted credential (and proves wrong / expired / missing credentials are refused), then
# releases it. It changes no file, no database row and nothing on any TURN server. Output contains no secret and no credential
# (lib/ice-kit/bin/probe.php never prints them); the workflow turns each section into an annotation.
#
# Sections (lines "=== <name>"): environment, status, mum, ncr, licence. Without "strict": exit 0 always — a failing node is a test
# RESULT, reported in its section with the probe's own exit code. With "strict" (production): a final "verdict" section, and exit 1
# unless the API reports "relay configured: yes" and BOTH nodes pass allocation over every transport, the wrong / expired / anonymous
# refusals and the expected relay IP — so the workflow step fails instead of showing green beside failing annotations.
set -uo pipefail

root="${1:?remote root}"; php="${2:-}"; product="${3:-remote}"; strict="${4:-}"
verdict() { # strict mode only: a check that could not run is a failure
  [ "$strict" = strict ] || exit 0
  echo "=== verdict"; echo "$1"; exit 1
}
root="${root#\~/}"
cd "$HOME" 2>/dev/null || true
api="$root/api"
[ -d "$api" ] || { echo "=== environment"; echo "no API folder at $api"; verdict "FAIL: no API folder"; }
cd "$api" || verdict "FAIL: cannot enter the API folder"

pick() {
  for c in "$php" /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php /usr/local/bin/php php; do
    [ -n "$c" ] || continue
    command -v "$c" >/dev/null 2>&1 || [ -x "$c" ] || continue
    if "$c" -r 'exit(PHP_VERSION_ID >= 80200 && extension_loaded("openssl") ? 0 : 1);' 2>/dev/null; then echo "$c"; return 0; fi
  done
  return 1
}

echo "=== environment"
P="$(pick)" || { echo "no PHP 8.2+ with openssl found (set SANDBOX_PHP_CLI / PROD_PHP_CLI)"; verdict "FAIL: no usable PHP"; }
"$P" -r 'echo "php ".PHP_VERSION; foreach (["openssl","sodium","pdo_pgsql","curl","sockets"] as $e) echo " ".$e."=".(extension_loaded($e)?"yes":"no"); echo "\n";'
echo "kit $(cat lib/ice-kit/VERSION 2>/dev/null || echo missing) · product $product · host $(hostname -s 2>/dev/null || echo ?)"
if [ -f .env ]; then
  mode="$(grep -E '^[[:space:]]*WEBRTC_TURN_ADMISSION[[:space:]]*=' .env | tail -1 | sed 's/.*=[[:space:]]*//')"
  nodes="$(grep -E '^[[:space:]]*WEBRTC_TURN_NODES[[:space:]]*=' .env | tail -1 | sed 's/.*=[[:space:]]*//')"
  echo "admission=${mode:-observe(default)} nodes=${nodes:-unset}"
  for k in WEBRTC_TURN_NODE_MUM_SECRET WEBRTC_TURN_NODE_NCR_SECRET WEBRTC_TURN_STATIC_AUTH_SECRET WEBRTC_TURN_LEDGER_DSN WEBRTC_TURN_LEDGER_PASSWORD WEBRTC_LICENCE_PUBLIC_KEYS; do
    grep -qE "^[[:space:]]*${k}[[:space:]]*=[[:space:]]*[^[:space:]]" .env && echo "$k: set" || echo "$k: not set"
  done
else
  echo "api/.env missing"
fi

echo "=== status"
status_out="$("$P" lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --status 2>&1)"; status_rc=$?
printf '%s\n' "$status_out" | head -60
echo "(exit $status_rc)"
relay=no; printf '%s\n' "$status_out" | grep -q 'relay configured: yes' && relay=yes

echo "=== mum"
"$P" lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=mum --allocate --negative --expect-ip=mum=148.135.210.65 --timeout=6 2>&1 | head -60
mum_rc="${PIPESTATUS[0]}"; echo "(exit $mum_rc)"

echo "=== ncr"
"$P" lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=ncr --allocate --negative --expect-ip=ncr=103.69.196.150 --timeout=6 2>&1 | head -60
ncr_rc="${PIPESTATUS[0]}"; echo "(exit $ncr_rc)"

echo "=== licence"
"$P" lib/ice-kit/bin/entitlement-fixture.php resolve --bootstrap=config/ice-kit.php --product="$product" --subject=staging-probe 2>&1 | head -5

[ "$strict" = strict ] || exit 0
echo "=== verdict"
echo "relay configured: $relay · mum $([ "$mum_rc" = 0 ] && echo PASS || echo "FAIL (exit $mum_rc)") · ncr $([ "$ncr_rc" = 0 ] && echo PASS || echo "FAIL (exit $ncr_rc)")"
[ "$relay" = yes ] && [ "$mum_rc" = 0 ] && [ "$ncr_rc" = 0 ] && { echo "PASS"; exit 0; }
echo "FAIL: production TURN authentication is not complete"
exit 1
