#!/usr/bin/env bash
# turn-staging-check.sh — the first real-server TURN tests, run ON the sandbox API host by the sandbox deploy workflow.
#
#   ssh deploy-target "bash -s -- <remote root> <php cli or ''> <remote|chat>" < scripts/ci/turn-staging-check.sh
#
# Identical in Remote and Connect. READ-ONLY and non-disruptive: it reads the API's own configuration and asks each relay for
# a short-lived allocation with a freshly minted credential (and proves wrong / expired / missing credentials are refused), then
# releases it. It changes no file, no database row and nothing on any TURN server. Output contains no secret and no credential
# (lib/ice-kit/bin/probe.php never prints them); the workflow turns each section into an annotation.
#
# Sections (lines "=== <name>"): environment, status, mum, ncr, licence. Exit 0 always — a failing node is a test RESULT, reported in its
# section with the probe's own exit code, not a deploy failure.
set -uo pipefail

root="${1:?remote root}"; php="${2:-}"; product="${3:-remote}"
root="${root#\~/}"
cd "$HOME" 2>/dev/null || true
api="$root/api"
[ -d "$api" ] || { echo "=== environment"; echo "no API folder at $api"; exit 0; }
cd "$api" || exit 0

pick() {
  for c in "$php" /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php /usr/local/bin/php php; do
    [ -n "$c" ] || continue
    command -v "$c" >/dev/null 2>&1 || [ -x "$c" ] || continue
    if "$c" -r 'exit(PHP_VERSION_ID >= 80200 && extension_loaded("openssl") ? 0 : 1);' 2>/dev/null; then echo "$c"; return 0; fi
  done
  return 1
}

echo "=== environment"
P="$(pick)" || { echo "no PHP 8.2+ with openssl found (set SANDBOX_PHP_CLI)"; exit 0; }
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
"$P" lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --status 2>&1 | head -60
echo "(exit ${PIPESTATUS[0]})"

echo "=== mum"
"$P" lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=mum --allocate --negative --expect-ip=mum=148.135.210.65 --timeout=6 2>&1 | head -60
echo "(exit ${PIPESTATUS[0]})"

echo "=== ncr"
"$P" lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=ncr --allocate --negative --expect-ip=ncr=103.69.196.150 --timeout=6 2>&1 | head -60
echo "(exit ${PIPESTATUS[0]})"

echo "=== licence"
"$P" lib/ice-kit/bin/entitlement-fixture.php resolve --bootstrap=config/ice-kit.php --product="$product" --subject=staging-probe 2>&1 | head -5
exit 0
