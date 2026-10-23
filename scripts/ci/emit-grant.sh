#!/usr/bin/env bash
# emit-grant.sh — mint one short-lived aicountly.ice/1 grant ON the API host, with the API's own topology and node secrets, and write
# it to STDOUT (nothing else goes to stdout). Used by the browser relay E2E workflow:
#
#   ssh deploy-target "bash -s -- <remote root> <php cli or ''> <node> <transports> [ttl] [subject]" < scripts/ci/emit-grant.sh > grant.json
#
# Identical in Remote and Connect. The grant holds TURN credentials valid for <ttl> seconds (60..300 here), NOT the node secret.
# It is minted with the API's own kit and node secret (config/ice-kit.php -> Topology -> TurnNode::credentialsFor), for the
# username "<expiry>:f.<subject>" — the subject is unique per test peer, so concurrent or consecutive tests never share coturn's
# per-user allocation quota (a shared name is what tripped "turn_capacity" in the first runs).
# The workflow never prints it, masks its credentials and deletes it at the end. On the host the temporary files are created mode
# 0600 in the account's home and removed before this script exits. It changes nothing else: no .env, no database, no relay.
# Exit: 0 grant written · 1 the API holds no credential for that node / transport · 2 usage or environment.
set -uo pipefail
umask 077

root="${1:?remote root}"; php="${2:-}"; node="${3:?node id}"; transports="${4:?udp|tcp|tls}"; ttl="${5:-300}"; subject="${6:-relay-check}"
case "$node" in *[!a-z0-9_-]* | '') echo "bad node id" >&2; exit 2 ;; esac
case "$transports" in *[!a-z,]* | '') echo "bad transports" >&2; exit 2 ;; esac
case "$ttl" in *[!0-9]* | '') echo "bad ttl" >&2; exit 2 ;; esac
case "$subject" in *[!A-Za-z0-9_.-]* | '') echo "bad subject" >&2; exit 2 ;; esac
[ "$ttl" -ge 60 ] && [ "$ttl" -le 300 ] || { echo "ttl must be 60..300" >&2; exit 2; }

root="${root#\~/}"
cd "$HOME" 2>/dev/null || true
cd "$root/api" 2>/dev/null || { echo "no API folder" >&2; exit 2; }

P=""
for c in "$php" /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php /usr/local/bin/php php; do
  [ -n "$c" ] || continue
  command -v "$c" >/dev/null 2>&1 || [ -x "$c" ] || continue
  "$c" -r 'exit(PHP_VERSION_ID >= 80200 && extension_loaded("openssl") ? 0 : 1);' 2>/dev/null && { P="$c"; break; }
done
[ -n "$P" ] || { echo "no PHP 8.2+ with openssl" >&2; exit 2; }

code="$(mktemp "$HOME/.relay-grant-php.XXXXXX")" || exit 2
trap 'rm -f "$code"' EXIT
cat > "$code" <<'PHP'
<?php
declare(strict_types=1);

use Aicountly\Ice\CredentialMinter;
use Aicountly\Ice\Probe\TurnProbe;
use Aicountly\Ice\Tier;

[, $nodeId, $transports, $ttl, $subject] = $argv;
require_once 'lib/ice-kit/autoload.php';
ob_start();
$kit = require 'config/ice-kit.php';
ob_end_clean();
$node = ($kit['topology'] ?? null)?->node($nodeId);
if ($node === null) {
    fwrite(STDERR, "unknown node\n");
    exit(1);
}
$want  = explode(',', $transports);
$urls  = array_values(array_filter($node->turnUrls(), static fn (string $u): bool => in_array(TurnProbe::parseTurnUrl($u)['transport'] ?? '', $want, true)));
$creds = $node->credentialsFor(Tier::parse('free')->letter() . '.' . CredentialMinter::subject($subject), (int) $ttl, time());
if ($urls === [] || $creds === null) {
    exit(1);
}
echo json_encode([
    'contract' => 'aicountly.ice/1',
    'iceServers' => [['urls' => $urls, 'username' => $creds['username'], 'credential' => $creds['credential']]],
    'iceTransportPolicy' => 'all', 'iceCandidatePoolSize' => 0, 'relayAvailable' => true,
    'expiresIn' => (int) $ttl, 'refreshAfter' => (int) floor((int) $ttl * 0.8),
    'relayNodes' => [['id' => $node->id, 'label' => $node->describe()['label'], 'region' => $node->region, 'priority' => $node->priority,
        'status' => $node->status->value, 'health' => 'unknown', 'urls' => $urls]],
    'degraded' => false, 'failover' => false,
], JSON_UNESCAPED_SLASHES);
PHP
"$P" "$code" "$node" "$transports" "$ttl" "$subject" 2>/dev/null || { echo "no grant for $node over $transports" >&2; exit 1; }
