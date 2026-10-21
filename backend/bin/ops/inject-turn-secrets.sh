#!/usr/bin/env bash
# inject-turn-secrets.sh — put coturn's static-auth-secrets into this API's .env, from STDIN only.
#
#   printf 'WEBRTC_TURN_NODE_MUM_SECRET=%s\n' "$SECRET" | ssh host "bash <api>/bin/ops/inject-turn-secrets.sh"
#
# Identical in AICOUNTLY Remote (backend/bin/ops) and Connect (server-php/bin/ops). The deploy sends one
# line per relay node it holds a repository secret for; nothing else ever carries a TURN secret to a host.
#
#   * STDIN only. A secret in an argument is in `ps`, in shell history and in a workflow log.
#   * Only these keys are accepted — WEBRTC_TURN_STATIC_AUTH_SECRET, WEBRTC_TURN_NODE_<ID>_SECRET and
#     WEBRTC_TURN_LEDGER_PASSWORD (the shared capacity ledger's database password; it may never equal a TURN secret) and
#     WEBRTC_LICENCE_S2S_SECRET (this product's AIC-S2S v1 secret for the central licence API; likewise never a TURN secret).
#     Anything else is refused, so this cannot be turned into a general .env writer.
#   * A value must look like a secret: 16..256 characters of [A-Za-z0-9._~+/=:@%-]. That refuses a
#     placeholder ("changeme", "<secret>"), and every character that would make CodeIgniter's .env
#     parser throw (a space, a quote, '#') — a parse error there stops the whole API, not one feature.
#   * Two nodes must not receive the same secret (--allow-shared overrides): a credential minted for one
#     would be valid at the other, so a relay that is compromised could replay what it sees at its sibling.
#   * An empty value is ignored — a repository secret that is not set must not blank a working .env.
#   * Output names keys and outcomes ("written", "already current"), never a value.
#
# The .env is rewritten in place, so it keeps its owner and mode. Exit: 0 done (or nothing to do) ·
# 2 refused (bad key, bad value, duplicate secret, or usage) · 3 there is no .env to write into.
set -uo pipefail
umask 077

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
api_dir="$(cd "$here/../.." && pwd)"
env_file="$api_dir/.env"
allow_shared=0

for arg in "$@"; do
  case "$arg" in
    --env=*) env_file="${arg#--env=}" ;;
    --allow-shared) allow_shared=1 ;;
    -h | --help) sed -n '2,25p' "$0"; exit 0 ;;
    *) echo "Unknown option: $arg" >&2; exit 2 ;;
  esac
done

if [ -t 0 ]; then
  echo "Secrets are read from STDIN: printf 'WEBRTC_TURN_NODE_MUM_SECRET=%s\n' \"\$SECRET\" | bash $0" >&2
  exit 2
fi
[ -f "$env_file" ] || { echo "No .env at $env_file — create it from .env.example first." >&2; exit 3; }

key_re='^WEBRTC_(TURN_(STATIC_AUTH_SECRET|LEDGER_PASSWORD|NODE_[A-Z0-9_]+_SECRET)|LICENCE_S2S_SECRET)$'
value_re='^[A-Za-z0-9._~+/=:@%-]{16,256}$'

keys=()
values=()
while IFS= read -r line || [ -n "$line" ]; do
  line="${line%$'\r'}"
  case "$line" in '' | '#'*) continue ;; esac
  case "$line" in *=*) ;; *) echo "Refused: a line without '=' (its content is not shown)." >&2; exit 2 ;; esac

  key="${line%%=*}"
  value="${line#*=}"

  if ! [[ "$key" =~ $key_re ]]; then
    echo "Refused: '$key' is not an injectable key (WEBRTC_TURN_STATIC_AUTH_SECRET, WEBRTC_TURN_NODE_<ID>_SECRET, WEBRTC_TURN_LEDGER_PASSWORD or WEBRTC_LICENCE_S2S_SECRET)." >&2
    exit 2
  fi
  if [ -z "$value" ]; then
    echo "$key: empty, ignored (the existing value, if any, is kept)."
    continue
  fi
  if ! [[ "$value" =~ $value_re ]]; then
    echo "Refused: the value for $key is not 16-256 characters of [A-Za-z0-9._~+/=:@%-] (it is not shown)." >&2
    exit 2
  fi
  for seen in "${keys[@]:-}"; do
    [ "$seen" = "$key" ] && { echo "Refused: $key was given twice." >&2; exit 2; }
  done

  keys+=("$key")
  values+=("$value")
done

# No two NODE secrets alike.
if [ "$allow_shared" -eq 0 ]; then
  for i in "${!keys[@]}"; do
    for j in "${!keys[@]}"; do
      [ "$j" -le "$i" ] && continue
      case "${keys[$i]}${keys[$j]}" in *NODE_*NODE_*)
        if [ "${values[$i]}" = "${values[$j]}" ]; then
          echo "Refused: ${keys[$i]} and ${keys[$j]} carry the same secret. Each coturn has its own; pass --allow-shared only if they truly share one." >&2
          exit 2
        fi ;;
      esac
    done
  done
fi

# The ledger password and the licence secret protect a database and a licensing API, not a relay: each must differ from every other secret (no override).
for i in "${!keys[@]}"; do
  case "${keys[$i]}" in WEBRTC_TURN_LEDGER_PASSWORD | WEBRTC_LICENCE_S2S_SECRET) ;; *) continue ;; esac
  for j in "${!keys[@]}"; do
    [ "$j" -eq "$i" ] && continue
    if [ "${values[$i]}" = "${values[$j]}" ]; then
      echo "Refused: ${keys[$i]} and ${keys[$j]} carry the same value; a ledger or licence secret must be its own." >&2
      exit 2
    fi
  done
done

[ "${#keys[@]}" -gt 0 ] || { echo "No TURN secret supplied; .env left alone."; exit 0; }

for i in "${!keys[@]}"; do
  key="${keys[$i]}"
  tmp="$(mktemp "${env_file}.XXXXXX")" || exit 2
  K="$key" V="${values[$i]}" awk '
    BEGIN { name = ENVIRON["K"]; line = name "=" ENVIRON["V"] }
    {
      probe = $0; sub(/^[[:space:]]*/, "", probe)
      if (index(probe, name) == 1 && substr(probe, length(name) + 1) ~ /^[[:space:]]*=/) {
        if (!done) print line
        done = 1
        next
      }
      print
    }
    END { if (!done) print line }' "$env_file" > "$tmp" || { rm -f "$tmp"; exit 2; }

  if cmp -s "$tmp" "$env_file"; then
    echo "$key: already current."
  else
    cat "$tmp" > "$env_file"   # in place: keeps the file's owner and mode
    echo "$key: written."
  fi
  rm -f "$tmp"
done

# The file holds host secrets: never world-readable (the deploy's permissions step does the same).
chmod o-rwx "$env_file" 2>/dev/null || true
exit 0
