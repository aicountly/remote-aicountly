#!/usr/bin/env bash
# turn-secret-presence.sh — does the API's .env hold a TURN secret for every ACTIVE relay node? Run ON the API host by the
# production deploy and the read-only TURN check, after bin/ops/inject-turn-secrets.sh:
#
#   ssh deploy-target "bash -s -- <remote root>" < scripts/ci/turn-secret-presence.sh
#
# Identical in Remote and Connect. Read-only; prints key names and "set" / "MISSING", never a value. A node is active unless its
# WEBRTC_TURN_NODE_<ID>_STATUS says otherwise; it is covered by its own WEBRTC_TURN_NODE_<ID>_SECRET or by the shared
# WEBRTC_TURN_STATIC_AUTH_SECRET. Exit 0 every active node covered · 1 at least one is not · 2 no .env.
#
# Why: an empty repository secret is (rightly) ignored by the injector, so a deploy whose secrets are missing in GitHub AND on the
# host used to finish green while the API served no relay. This makes that state a failure the workflow can see.
set -uo pipefail

root="${1:?remote root}"
root="${root#\~/}"
cd "$HOME" 2>/dev/null || true
env_file="$root/api/.env"
[ -f "$env_file" ] || { echo "api/.env missing at $env_file"; exit 2; }

value_of() { # first assignment wins, as CodeIgniter's DotEnv reads it
  K="$1" awk 'BEGIN{k=ENVIRON["K"]} { l=$0; sub(/\r$/,"",l); sub(/^[[:space:]]*(export[[:space:]]+)?/,"",l)
    if (index(l,k)==1 && substr(l,length(k)+1) ~ /^[[:space:]]*=/) { v=substr(l,length(k)+1); sub(/^[[:space:]]*=[[:space:]]*/,"",v)
      sub(/[[:space:]]+$/,"",v); gsub(/^["\x27]|["\x27]$/,"",v); print v; exit } }' "$env_file"
}
is_set() { [ -n "$(value_of "$1")" ]; }

nodes="$(value_of WEBRTC_TURN_NODES | tr ',' ' ' | tr 'a-z' 'A-Z')"
[ -n "${nodes// /}" ] || { echo "WEBRTC_TURN_NODES not set: no relay node configured"; exit 1; }
shared=no; is_set WEBRTC_TURN_STATIC_AUTH_SECRET && shared=yes

rc=0
for id in $nodes; do
  status="$(value_of "WEBRTC_TURN_NODE_${id}_STATUS")"; status="${status:-active}"
  key="WEBRTC_TURN_NODE_${id}_SECRET"
  if is_set "$key"; then echo "$key: set (node $status)"
  elif [ "$shared" = yes ]; then echo "$key: not set — covered by WEBRTC_TURN_STATIC_AUTH_SECRET (node $status)"
  elif [ "$status" = active ]; then echo "$key: MISSING — node ${id,,} is active but cannot be served"; rc=1
  else echo "$key: not set (node $status, not served)"; fi
done
exit "$rc"
