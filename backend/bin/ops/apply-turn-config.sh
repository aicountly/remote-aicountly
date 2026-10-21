#!/usr/bin/env bash
# apply-turn-config.sh — put NON-SECRET WEBRTC_* settings into this API's .env, from STDIN.
#
#   cat staging-turn.env | ssh host "bash <api>/bin/ops/apply-turn-config.sh"
#
# Identical in AICOUNTLY Remote (backend/bin/ops) and Connect (server-php/bin/ops). The companion of
# inject-turn-secrets.sh, which carries the secrets: this one carries everything else a TURN rollout sets
# (nodes, regions, capacity, admission mode, ledger DSN, licence-feed settings) so it is reviewable and repeatable.
#
#   * Only WEBRTC_TURN_* / WEBRTC_STUN_* / WEBRTC_GEO_* / WEBRTC_LICENCE_* keys are accepted — nothing else in the .env can be written.
#   * Anything that looks like a secret (…SECRET, …PASSWORD, …CREDENTIAL, …TOKEN, …KEY) is refused: those go through
#     inject-turn-secrets.sh, so a secret never lands in a reviewable file.
#   * Values: no spaces, quotes, '#' or '$' (each would break CodeIgniter's .env parser or expand).
#   * WEBRTC_TURN_ADMISSION=enforce is refused unless --allow-enforce is given: first real-server and product testing
#     runs in `observe`, and enforcement waits for the licence mappings and feed (docs/STAGING_TURN_DEPLOYMENT.md).
#   * A node's status may not be `active` for a node named in --keep-out (e.g. --keep-out=ncr keeps Noida out of
#     a production .env). The deploy for production passes it; the sandbox does not.
#   * An empty value is ignored. Output names keys and outcomes, and echoes the value of non-secret keys.
#
# Exit: 0 done · 2 refused · 3 no .env to write into.
set -uo pipefail
umask 077

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
api_dir="$(cd "$here/../.." && pwd)"
env_file="$api_dir/.env"
allow_enforce=0
keep_out=""

for arg in "$@"; do
  case "$arg" in
    --env=*) env_file="${arg#--env=}" ;;
    --allow-enforce) allow_enforce=1 ;;
    --keep-out=*) keep_out="${arg#--keep-out=}" ;;
    -h | --help) sed -n '2,22p' "$0"; exit 0 ;;
    *) echo "Unknown option: $arg" >&2; exit 2 ;;
  esac
done

if [ -t 0 ]; then
  echo "Settings are read from STDIN: printf 'WEBRTC_TURN_ADMISSION=observe\n' | bash $0" >&2
  exit 2
fi
[ -f "$env_file" ] || { echo "No .env at $env_file — create it from .env.example first." >&2; exit 3; }

key_re='^WEBRTC_(TURN|STUN|GEO|LICENCE)_[A-Z0-9_]+$'
secret_re='(SECRET|PASSWORD|CREDENTIAL|TOKEN|PRIVATE|APIKEY|API_KEY)'
value_re='^[A-Za-z0-9._~+/=:@%,;?&|*-]{1,400}$'

keys=()
values=()
while IFS= read -r line || [ -n "$line" ]; do
  line="${line%$'\r'}"
  case "$line" in '' | '#'*) continue ;; esac
  case "$line" in *=*) ;; *) echo "Refused: a line without '='." >&2; exit 2 ;; esac

  key="${line%%=*}"
  value="${line#*=}"
  key="${key%"${key##*[![:space:]]}"}"
  value="${value#"${value%%[![:space:]]*}"}"; value="${value%"${value##*[![:space:]]}"}"

  if ! [[ "$key" =~ $key_re ]]; then
    echo "Refused: '$key' is not a WEBRTC_TURN_/STUN_/GEO_/LICENCE_ setting." >&2
    exit 2
  fi
  if [[ "$key" =~ $secret_re ]]; then
    echo "Refused: '$key' looks like a secret; send it through inject-turn-secrets.sh (stdin), never through this file." >&2
    exit 2
  fi
  [ -n "$value" ] || { echo "$key: empty, ignored."; continue; }
  if ! [[ "$value" =~ $value_re ]]; then
    echo "Refused: the value for $key has a character the .env parser cannot take (spaces, quotes, '#', '\$') or is too long." >&2
    exit 2
  fi
  if [ "$key" = "WEBRTC_TURN_ADMISSION" ]; then
    case "$value" in
      off | observe) ;;
      enforce) [ "$allow_enforce" -eq 1 ] || { echo "Refused: WEBRTC_TURN_ADMISSION=enforce needs --allow-enforce (testing runs in observe until the licence mappings and feed are complete)." >&2; exit 2; } ;;
      *) echo "Refused: WEBRTC_TURN_ADMISSION must be off, observe or enforce." >&2; exit 2 ;;
    esac
  fi
  if [ -n "$keep_out" ]; then
    for node in ${keep_out//,/ }; do
      n="$(printf '%s' "$node" | tr '[:lower:]-' '[:upper:]_')"
      if [ "$key" = "WEBRTC_TURN_NODE_${n}_STATUS" ] && [ "$value" = "active" ]; then
        echo "Refused: node '$node' is kept out of this environment (--keep-out) and may not be active." >&2
        exit 2
      fi
    done
  fi
  for seen in "${keys[@]:-}"; do
    [ "$seen" = "$key" ] && { echo "Refused: $key was given twice." >&2; exit 2; }
  done

  keys+=("$key")
  values+=("$value")
done

[ "${#keys[@]}" -gt 0 ] || { echo "No setting supplied; .env left alone."; exit 0; }

for i in "${!keys[@]}"; do
  key="${keys[$i]}"
  tmp="$(mktemp "${env_file}.XXXXXX")" || exit 2
  K="$key" V="${values[$i]}" awk '
    BEGIN { name = ENVIRON["K"]; line = name " = " ENVIRON["V"] }
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
    echo "$key = ${values[$i]}: already current."
  else
    cat "$tmp" > "$env_file"
    echo "$key = ${values[$i]}: written."
  fi
  rm -f "$tmp"
done

chmod o-rwx "$env_file" 2>/dev/null || true
exit 0
