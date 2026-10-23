#!/usr/bin/env bash
# promote-turn-secrets.sh — give the PRODUCTION Remote and Connect APIs the per-node coturn secrets that the GH staging APIs
# already use (and that passed authenticated + negative allocation tests there), on the cPanel host itself, as root.
#
#   bash promote-turn-secrets.sh check                # read-only: sources agree? targets safe? policy as expected? what would change
#   bash promote-turn-secrets.sh apply                # check, back up, write, verify the files, then probe both APIs (read-only)
#   bash promote-turn-secrets.sh verify               # probe both production APIs only (read-only; refreshes node health like the cron)
#   bash promote-turn-secrets.sh rollback <backup-dir> # put the backed-up production .env files back, atomically
#
# Identical in aicountly/remote-aicountly and aicountly/connect-aicountly (scripts/ops/). It is NOT deployed with either app.
#
# Why on the host: the secrets never leave the machine — not through a workflow, a runner, a log, an annotation or a clipboard.
#
#   * Root only; all four .env files must be on this host (otherwise it stops and changes nothing).
#   * Only WEBRTC_TURN_NODE_MUM_SECRET and WEBRTC_TURN_NODE_NCR_SECRET are copied — no other staging setting.
#   * Each region's secret must be identical in the Remote and the Connect staging .env, non-empty, a valid secret
#     (16..256 of [A-Za-z0-9._~+/=:@%-], as bin/ops/inject-turn-secrets.sh) and Mumbai's must differ from Noida's.
#   * A key that appears twice in a staging .env is refused (CodeIgniter's DotEnv takes the first; ambiguity is not guessed).
#   * Production policy is checked BEFORE anything is written, and a mismatch stops the run: admission observe (or unset),
#     WEBRTC_FORCE_RELAY unset or false, nodes mum and ncr both listed and active, hosts turn-mum/turn-ncr.aicountly.com.
#   * A production secret that is already set to a DIFFERENT value is not overwritten (it may be a rotation) unless
#     --replace-existing is given.
#   * Write: full copy to a root-only backup (/root/aicountly-turn-backups/<UTC time>/, mode 700/600) with owner, group, mode and
#     SHA-256 recorded; the new file is built beside the original, given its owner, group, mode, ACL and SELinux label, proven to
#     differ ONLY in the two keys, then renamed over it (atomic). If the second product fails, the first is rolled back.
#   * Probes run as the account user (never root) with the app's own probe.php: --allocate --negative --expect-ip, per node.
#   * Nothing prints a secret or a hash of one: output says "set", "matches", "written", "already current". Probe output is also
#     passed through a redactor that would blank either secret if it ever appeared.
#
# Options: --replace-existing · --php=/path/to/php · paths: --remote-staging= --connect-staging= --remote-prod= --connect-prod=
# Exit: 0 ok · 1 a probe failed · 2 usage / not root / files missing · 3 sources invalid or disagree · 4 production policy or
# target unsafe · 5 write or verification failed (rolled back).
set -uo pipefail
umask 077
export LC_ALL=C

REMOTE_STAGING=/home/ghaicountly/public_html/remote.gh.aicountly.com/api/.env
CONNECT_STAGING=/home/ghaicountly/public_html/connect.gh.aicountly.com/api/.env
REMOTE_PROD=/home/remoteaicountly/public_html/api/.env
CONNECT_PROD=/home/connectaicountly/public_html/api/.env
BACKUP_ROOT=/root/aicountly-turn-backups
EXPECT_IP_MUM=148.135.210.65
EXPECT_IP_NCR=103.69.196.150
KEYS=(WEBRTC_TURN_NODE_MUM_SECRET WEBRTC_TURN_NODE_NCR_SECRET)
VALUE_RE='^[A-Za-z0-9._~+/=:@%-]{16,256}$'

mode="${1:-}"; shift || true
backup_arg=""
replace_existing=0
php_arg=""
for arg in "$@"; do
  case "$arg" in
    --replace-existing) replace_existing=1 ;;
    --php=*) php_arg="${arg#--php=}" ;;
    --remote-staging=*) REMOTE_STAGING="${arg#*=}" ;;
    --connect-staging=*) CONNECT_STAGING="${arg#*=}" ;;
    --remote-prod=*) REMOTE_PROD="${arg#*=}" ;;
    --connect-prod=*) CONNECT_PROD="${arg#*=}" ;;
    -*) echo "Unknown option: $arg" >&2; exit 2 ;;
    *) [ "$mode" = rollback ] && [ -z "$backup_arg" ] && { backup_arg="$arg"; continue; }; echo "Unexpected argument." >&2; exit 2 ;;
  esac
done
case "$mode" in check | apply | verify | rollback) ;; *) sed -n '2,9p' "$0"; exit 2 ;; esac
[ "$(id -u)" -eq 0 ] || { echo "Run as root on the cPanel host (WHM Terminal or root SSH)." >&2; exit 2; }

PRODUCTS=(remote connect)
prod_file() { [ "$1" = remote ] && echo "$REMOTE_PROD" || echo "$CONNECT_PROD"; }
owner_of() { stat -c '%U' "$1"; }

# ---- .env reading ---------------------------------------------------------------------------------------------------------
# count_key FILE KEY → how many assignment lines name KEY.  get_key FILE KEY → the FIRST one's value (DotEnv's rule), unquoted.
count_key() {
  K="$2" awk 'BEGIN{n=0; k=ENVIRON["K"]} { l=$0; sub(/\r$/,"",l); sub(/^[[:space:]]*(export[[:space:]]+)?/,"",l)
    if (index(l,k)==1 && substr(l,length(k)+1) ~ /^[[:space:]]*=/) n++ } END{print n}' "$1"
}
get_key() {
  K="$2" awk 'BEGIN{k=ENVIRON["K"]} { l=$0; sub(/\r$/,"",l); sub(/^[[:space:]]*(export[[:space:]]+)?/,"",l)
    if (index(l,k)==1 && substr(l,length(k)+1) ~ /^[[:space:]]*=/) {
      v=substr(l,length(k)+1); sub(/^[[:space:]]*=[[:space:]]*/,"",v); sub(/[[:space:]]+$/,"",v)
      if (v ~ /^".*"$/ || v ~ /^\x27.*\x27$/) v=substr(v,2,length(v)-2)
      printf "%s", v; exit } }' "$1"
}
policy_line() { # non-secret settings only
  local f="$1" admission force nodes
  admission="$(get_key "$f" WEBRTC_TURN_ADMISSION)"; force="$(get_key "$f" WEBRTC_FORCE_RELAY)"; nodes="$(get_key "$f" WEBRTC_TURN_NODES)"
  echo "admission=${admission:-unset(observe)} force_relay=${force:-unset} nodes=${nodes:-unset}" \
       "mum=$(get_key "$f" WEBRTC_TURN_NODE_MUM_STATUS)/$(get_key "$f" WEBRTC_TURN_NODE_MUM_HOST)" \
       "ncr=$(get_key "$f" WEBRTC_TURN_NODE_NCR_STATUS)/$(get_key "$f" WEBRTC_TURN_NODE_NCR_HOST)" \
       "max_nodes=$(get_key "$f" WEBRTC_TURN_MAX_NODES)"
}
policy_ok() {
  local f="$1" a fr nodes
  a="$(get_key "$f" WEBRTC_TURN_ADMISSION)"; fr="$(get_key "$f" WEBRTC_FORCE_RELAY)"; nodes=",$(get_key "$f" WEBRTC_TURN_NODES | tr -d ' '),"
  case "${a:-observe}" in observe) ;; *) echo "WEBRTC_TURN_ADMISSION is '$a', not observe"; return 1 ;; esac
  case "$(echo "${fr:-false}" | tr 'A-Z' 'a-z')" in false | 0 | off | no | '') ;; *) echo "WEBRTC_FORCE_RELAY is on"; return 1 ;; esac
  case "$nodes" in *,mum,*) ;; *) echo "mum is not in WEBRTC_TURN_NODES"; return 1 ;; esac
  case "$nodes" in *,ncr,*) ;; *) echo "ncr is not in WEBRTC_TURN_NODES"; return 1 ;; esac
  [ "$(get_key "$f" WEBRTC_TURN_NODE_MUM_STATUS)" = active ] || { echo "mum is not active"; return 1; }
  [ "$(get_key "$f" WEBRTC_TURN_NODE_NCR_STATUS)" = active ] || { echo "ncr is not active"; return 1; }
  [ "$(get_key "$f" WEBRTC_TURN_NODE_MUM_HOST)" = turn-mum.aicountly.com ] || { echo "mum host is not turn-mum.aicountly.com"; return 1; }
  [ "$(get_key "$f" WEBRTC_TURN_NODE_NCR_HOST)" = turn-ncr.aicountly.com ] || { echo "ncr host is not turn-ncr.aicountly.com"; return 1; }
  return 0
}
target_safe() {
  local f="$1" home
  [ -e "$f" ] || { echo "missing"; return 1; }
  [ -L "$f" ] && { echo "is a symlink"; return 1; }
  [ -f "$f" ] || { echo "not a regular file"; return 1; }
  [ "$(stat -c '%h' "$f")" -eq 1 ] || { echo "has more than one hard link"; return 1; }
  home="$(echo "$f" | cut -d/ -f1-3)"
  [ "$(owner_of "$f")" = "$(owner_of "$home")" ] || { echo "owner $(owner_of "$f") is not the account user $(owner_of "$home")"; return 1; }
  return 0
}

# ---- sources -------------------------------------------------------------------------------------------------------------
MUM=""; NCR=""
load_sources() {
  local f k n v rv cv bad=0
  for f in "$REMOTE_STAGING" "$CONNECT_STAGING" "$REMOTE_PROD" "$CONNECT_PROD"; do
    [ -f "$f" ] || { echo "Not on this host: $f — run this on the cPanel server that holds all four applications." >&2; exit 2; }
  done
  for k in "${KEYS[@]}"; do
    for f in "$REMOTE_STAGING" "$CONNECT_STAGING"; do
      n="$(count_key "$f" "$k")"
      [ "$n" = 1 ] || { echo "source $f: $k appears $n times (need exactly 1)"; bad=1; continue; }
      v="$(get_key "$f" "$k")"
      [[ "$v" =~ $VALUE_RE ]] || { echo "source $f: $k is not a valid secret (not shown)"; bad=1; }
    done
    rv="$(get_key "$REMOTE_STAGING" "$k")"; cv="$(get_key "$CONNECT_STAGING" "$k")"
    if [ -n "$rv" ] && [ "$rv" = "$cv" ]; then echo "source $k: set, identical in Remote and Connect staging"
    else echo "source $k: Remote and Connect staging DISAGREE (values not shown)"; bad=1; fi
    [ "$k" = WEBRTC_TURN_NODE_MUM_SECRET ] && MUM="$rv" || NCR="$rv"
  done
  if [ -n "$MUM" ] && [ "$MUM" = "$NCR" ]; then echo "sources: Mumbai and Noida carry the SAME secret — refused"; bad=1
  else echo "sources: Mumbai and Noida secrets differ"; fi
  [ "$bad" -eq 0 ] || { echo "Stopped: the staging sources are not usable. Nothing was changed."; exit 3; }
}
want() { [ "$1" = WEBRTC_TURN_NODE_MUM_SECRET ] && printf '%s' "$MUM" || printf '%s' "$NCR"; }

# ---- targets -------------------------------------------------------------------------------------------------------------
declare -A CHANGE
plan_targets() {
  local p f k cur why bad=0
  for p in "${PRODUCTS[@]}"; do
    f="$(prod_file "$p")"
    why="$(target_safe "$f")" || { echo "target $p ($f): unsafe — $why"; bad=1; continue; }
    echo "target $p: $f owner $(stat -c '%U:%G' "$f") mode $(stat -c '%a' "$f")"
    echo "target $p policy: $(policy_line "$f")"
    why="$(policy_ok "$f")" || { echo "target $p policy MISMATCH: $why — this run does not change policy"; bad=1; }
    CHANGE[$p]=0
    for k in "${KEYS[@]}"; do
      cur="$(get_key "$f" "$k")"
      if [ -z "$cur" ]; then echo "target $p $k: not set → will be written"; CHANGE[$p]=1
      elif [ "$cur" = "$(want "$k")" ] && [ "$(count_key "$f" "$k")" = 1 ]; then echo "target $p $k: already current"
      elif [ "$cur" = "$(want "$k")" ]; then echo "target $p $k: current, duplicate lines → will be normalised"; CHANGE[$p]=1
      elif [ "$replace_existing" -eq 1 ]; then echo "target $p $k: set to a DIFFERENT value → will be replaced (--replace-existing)"; CHANGE[$p]=1
      else echo "target $p $k: set to a DIFFERENT value — not overwritten (pass --replace-existing if that is intended)"; bad=1; fi
    done
  done
  [ "$bad" -eq 0 ] || { echo "Stopped before writing. Nothing was changed."; exit 4; }
}

# Build FILE's replacement into OUT: first assignment of each key replaced, later ones dropped, missing ones appended.
render() {
  MUMV="$MUM" NCRV="$NCR" awk '
    BEGIN { k[1]="WEBRTC_TURN_NODE_MUM_SECRET"; v[1]=ENVIRON["MUMV"]; k[2]="WEBRTC_TURN_NODE_NCR_SECRET"; v[2]=ENVIRON["NCRV"] }
    { l=$0; sub(/\r$/,"",l); sub(/^[[:space:]]*(export[[:space:]]+)?/,"",l)
      for (i=1;i<=2;i++) if (index(l,k[i])==1 && substr(l,length(k[i])+1) ~ /^[[:space:]]*=/) {
        if (!done[i]) print k[i] "=" v[i]; done[i]=1; next }
      print }
    END { for (i=1;i<=2;i++) if (!done[i]) print k[i] "=" v[i] }' "$1" > "$2"
}
without_keys() { # the file minus every line naming the two keys — must be identical before and after
  awk '{ l=$0; sub(/\r$/,"",l); sub(/^[[:space:]]*(export[[:space:]]+)?/,"",l)
    if (l ~ /^WEBRTC_TURN_NODE_(MUM|NCR)_SECRET[[:space:]]*=/) next; print }' "$1"
}
copy_attrs() { # REF → DST: owner, group, mode, ACL, SELinux label
  chown --reference="$1" "$2" && chmod --reference="$1" "$2" || return 1
  if command -v getfacl >/dev/null 2>&1 && command -v setfacl >/dev/null 2>&1; then
    getfacl --absolute-names -p "$1" 2>/dev/null | setfacl --set-file=- "$2" 2>/dev/null || true
  fi
  if command -v selinuxenabled >/dev/null 2>&1 && selinuxenabled; then chcon --reference="$1" "$2" || return 1; fi
  return 0
}
atomic_replace() { # NEWFILE TARGET (NEWFILE is in TARGET's directory)
  sync -f "$1" 2>/dev/null || sync
  mv -f -T "$1" "$2"
}
attrs() { stat -c '%U:%G %a' "$1"; }

BACKUP_DIR=""
backup() {
  local p f
  BACKUP_DIR="$BACKUP_ROOT/$(date -u +%Y%m%dT%H%M%SZ)"
  mkdir -p -m 700 "$BACKUP_ROOT" && chmod 700 "$BACKUP_ROOT" && mkdir -m 700 "$BACKUP_DIR" || { echo "cannot create $BACKUP_DIR"; exit 5; }
  : > "$BACKUP_DIR/manifest"; chmod 600 "$BACKUP_DIR/manifest"
  for p in "${PRODUCTS[@]}"; do
    f="$(prod_file "$p")"
    cp --preserve=mode,ownership,timestamps "$f" "$BACKUP_DIR/$p.env" && chmod 600 "$BACKUP_DIR/$p.env" || { echo "backup of $p failed"; exit 5; }
    cmp -s "$f" "$BACKUP_DIR/$p.env" || { echo "backup of $p does not match"; exit 5; }
    printf '%s\t%s\t%s\t%s\t%s\n' "$p" "$f" "$(stat -c '%U' "$f")" "$(stat -c '%G' "$f")" "$(stat -c '%a' "$f")" >> "$BACKUP_DIR/manifest"
    printf '%s.sha256\t%s\n' "$p" "$(sha256sum < "$f" | cut -d' ' -f1)" >> "$BACKUP_DIR/manifest"
  done
  echo "backup: $BACKUP_DIR (root only)"
}

restore_one() { # PRODUCT BACKUPDIR
  local p="$1" d="$2" f user group perm tmp
  IFS=$'\t' read -r _ f user group perm < <(awk -F'\t' -v p="$p" '$1==p' "$d/manifest")
  [ -n "${f:-}" ] && [ -f "$d/$p.env" ] || { echo "rollback $p: nothing in $d"; return 1; }
  tmp="$(mktemp "$(dirname "$f")/.env.rollback.XXXXXX")" || return 1
  cat "$d/$p.env" > "$tmp" && chown "$user:$group" "$tmp" && chmod "$perm" "$tmp" || { rm -f "$tmp"; return 1; }
  [ -f "$f" ] && { command -v selinuxenabled >/dev/null 2>&1 && selinuxenabled && chcon --reference="$f" "$tmp" 2>/dev/null; }
  atomic_replace "$tmp" "$f" || { rm -f "$tmp"; return 1; }
  echo "rollback $p: restored $f ($(attrs "$f"))"
}

write_targets() {
  local p f tmp before done_list=()
  for p in "${PRODUCTS[@]}"; do
    [ "${CHANGE[$p]}" = 1 ] || { echo "write $p: nothing to change"; continue; }
    f="$(prod_file "$p")"; before="$(attrs "$f")"
    tmp="$(mktemp "$(dirname "$f")/.env.turn.XXXXXX")" || { fail_write "$p" "${done_list[@]}"; }
    if ! render "$f" "$tmp" || ! copy_attrs "$f" "$tmp" \
       || ! cmp -s <(without_keys "$f") <(without_keys "$tmp") \
       || [ "$(get_key "$tmp" WEBRTC_TURN_NODE_MUM_SECRET)" != "$MUM" ] || [ "$(get_key "$tmp" WEBRTC_TURN_NODE_NCR_SECRET)" != "$NCR" ] \
       || [ "$(count_key "$tmp" WEBRTC_TURN_NODE_MUM_SECRET)" != 1 ] || [ "$(count_key "$tmp" WEBRTC_TURN_NODE_NCR_SECRET)" != 1 ] \
       || [ "$(attrs "$tmp")" != "$before" ]; then
      rm -f "$tmp"; fail_write "$p" "${done_list[@]}"
    fi
    atomic_replace "$tmp" "$f" || { rm -f "$tmp"; fail_write "$p" "${done_list[@]}"; }
    done_list+=("$p")
    [ "$(attrs "$f")" = "$before" ] || { echo "write $p: owner/mode changed after rename"; fail_write "$p" "${done_list[@]}"; }
    echo "write $p: written (only the two TURN node secrets changed; owner/mode $before kept)"
  done
}
fail_write() {
  local failed="$1"; shift
  echo "write $failed: FAILED — rolling back"
  restore_one "$failed" "$BACKUP_DIR" || true
  for p in "$@"; do [ "$p" = "$failed" ] || restore_one "$p" "$BACKUP_DIR" || true; done
  exit 5
}

# ---- probes (read-only, as the account user) -----------------------------------------------------------------------------
redact() { MUMV="$MUM" NCRV="$NCR" awk '{ l=$0; for (i=1;i<=2;i++) { s=(i==1?ENVIRON["MUMV"]:ENVIRON["NCRV"]); if (s!="") while ((j=index(l,s))>0) l=substr(l,1,j-1) "[redacted]" substr(l,j+length(s)) } print l; fflush() }'; }
pick_php() {
  local c
  for c in "$php_arg" /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php /usr/local/bin/php; do
    [ -n "$c" ] && [ -x "$c" ] || continue
    "$c" -r 'exit(PHP_VERSION_ID >= 80200 && extension_loaded("openssl") ? 0 : 1);' 2>/dev/null && { echo "$c"; return 0; }
  done
  return 1
}
as_user() { # USER APIDIR CMD...
  local u="$1" api="$2"; shift 2
  runuser -u "$u" -- env -i HOME="/home/$u" PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C bash -c 'cd "$1" && shift && exec "$@"' _ "$api" "$@"
}
declare -A RESULT
probe_all() {
  local P p f api u node ip rc overall=0
  P="$(pick_php)" || { echo "no PHP 8.2+ with openssl (pass --php=)"; exit 2; }
  for p in "${PRODUCTS[@]}"; do
    f="$(prod_file "$p")"; api="$(dirname "$f")"; u="$(owner_of "$f")"
    echo "=== $p · probes as $u in $api"
    for node in mum ncr; do
      [ "$node" = mum ] && ip="$EXPECT_IP_MUM" || ip="$EXPECT_IP_NCR"
      as_user "$u" "$api" "$P" lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node="$node" --allocate --negative \
        --expect-ip="$node=$ip" --timeout=6 2>&1 | redact
      rc="${PIPESTATUS[0]}"; RESULT[$p.$node]="$rc"; [ "$rc" -eq 0 ] || overall=1
      echo "(exit $rc)"
    done
    echo "--- $p · node health refresh (what the turn-probe cron runs every 5 min)"
    as_user "$u" "$api" "$P" lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --allocate --write-health --timeout=6 2>&1 | redact | tail -3
    echo "--- $p · status"
    as_user "$u" "$api" "$P" lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --status 2>&1 | redact | head -12
    as_user "$u" "$api" "$P" lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --status 2>&1 | grep -q 'relay configured: yes' \
      && RESULT[$p.relay]=yes || { RESULT[$p.relay]=NO; overall=1; }
  done
  echo
  echo "=== summary (allocation UDP/TCP/TLS-443 + wrong/expired/anonymous refused + expected IP)"
  for p in "${PRODUCTS[@]}"; do
    for node in mum ncr; do
      [ "${RESULT[$p.$node]}" = 0 ] && r=PASS || r="FAIL (exit ${RESULT[$p.$node]})"
      printf '%-8s %-4s %s\n' "$p" "$node" "$r"
    done
    printf '%-8s relay configured: %s\n' "$p" "${RESULT[$p.relay]}"
  done
  return "$overall"
}

# ---- main ----------------------------------------------------------------------------------------------------------------
case "$mode" in
  check)
    load_sources; plan_targets
    echo "check: OK — 'apply' would change: remote=${CHANGE[remote]} connect=${CHANGE[connect]} (1 = write). Nothing was changed." ;;
  apply)
    load_sources; plan_targets
    if [ "${CHANGE[remote]}" = 1 ] || [ "${CHANGE[connect]}" = 1 ]; then backup; write_targets
      echo "rollback, if ever needed: bash $0 rollback $BACKUP_DIR"
    else echo "apply: both production .env files already current; nothing written"; fi
    probe_all; exit $? ;;
  verify)
    for f in "$REMOTE_PROD" "$CONNECT_PROD"; do [ -f "$f" ] || { echo "Not on this host: $f" >&2; exit 2; }; done
    MUM="$(get_key "$REMOTE_PROD" WEBRTC_TURN_NODE_MUM_SECRET)"; NCR="$(get_key "$REMOTE_PROD" WEBRTC_TURN_NODE_NCR_SECRET)"
    probe_all; exit $? ;;
  rollback)
    [ -n "$backup_arg" ] && [ -f "$backup_arg/manifest" ] || { echo "rollback needs a backup directory with a manifest" >&2; exit 2; }
    rc=0; for p in "${PRODUCTS[@]}"; do restore_one "$p" "$backup_arg" || rc=5
      f="$(prod_file "$p")"; want_sha="$(awk -F'\t' -v k="$p.sha256" '$1==k{print $2}' "$backup_arg/manifest")"
      [ "$(sha256sum < "$f" | cut -d' ' -f1)" = "$want_sha" ] && echo "rollback $p: content identical to the backup" || { echo "rollback $p: content differs!"; rc=5; }
    done; exit "$rc" ;;
esac
