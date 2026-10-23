#!/usr/bin/env bash
# emit-grant.sh — mint one short-lived aicountly.ice/1 grant ON the API host, with the API's own topology and node secrets, and write
# it to STDOUT (nothing else goes to stdout). Used by the browser relay E2E workflow:
#
#   ssh deploy-target "bash -s -- <remote root> <php cli or ''> <node> <transports> [ttl]" < scripts/ci/emit-grant.sh > grant.json
#
# Identical in Remote and Connect. The grant holds TURN credentials valid for <ttl> seconds (60..300 here), NOT the node secret.
# The workflow never prints it, masks its credentials and deletes it at the end. On the host the temporary file is created mode 0600
# in the account's home and removed before this script exits. It changes nothing else: no .env, no database, no relay.
# Exit: 0 grant written · 1 the API holds no credential for that node / transport · 2 usage or environment.
set -uo pipefail
umask 077

root="${1:?remote root}"; php="${2:-}"; node="${3:?node id}"; transports="${4:?udp|tcp|tls}"; ttl="${5:-300}"
case "$node" in *[!a-z0-9_-]* | '') echo "bad node id" >&2; exit 2 ;; esac
case "$transports" in *[!a-z,]* | '') echo "bad transports" >&2; exit 2 ;; esac
case "$ttl" in *[!0-9]* | '') echo "bad ttl" >&2; exit 2 ;; esac
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

tmp="$(mktemp "$HOME/.relay-grant.XXXXXX")" || exit 2
trap 'rm -f "$tmp"' EXIT
"$P" lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node="$node" --transports="$transports" --emit-grant="$tmp" --ttl="$ttl" >/dev/null 2>&1
"$P" -r '$g = json_decode((string) file_get_contents($argv[1]), true); exit(is_array($g) && ($g["relayAvailable"] ?? false) === true ? 0 : 1);' "$tmp" \
  || { echo "no grant for $node over $transports" >&2; exit 1; }
cat "$tmp"
