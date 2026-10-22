#!/usr/bin/env bash
# turn-preflight.sh — READ-ONLY readiness check before the first staging deployment and real-server TURN tests.
#
#   scripts/staging/turn-preflight.sh --repo aicountly/remote-aicountly --product remote \
#       --ssh <sandbox-user>@<sandbox-host> --root <SANDBOX document root> [--port 22] [--php /opt/cpanel/ea-php83/root/usr/bin/php] \
#       [--turn-hosts turn-mum.aicountly.com,turn-ncr.aicountly.com] [--print]
#
# Identical in Remote and Connect. Run from the operator's machine (it needs `gh`, `ssh`, `openssl`; `--print` needs none and
# only shows what it would run). It changes NOTHING anywhere: no deploy, no write, no restart, no secret read — secrets are
# checked by NAME only, and the API host's .env is inspected for key names and the admission mode, never for values of secrets.
#
#   1  GitHub     the repository secrets the staging deploy needs exist (names)
#   2  Target     the SSH account / root is not a production account (scripts/ci/sandbox-target-guard.sh)
#   3  API host   who/where, PHP extensions (sodium, pdo_pgsql, openssl, curl), api/.env present, admission mode is not `enforce`,
#                 kit version, the shared ledger reachable (read-only probe), the licence gate's reasons
#   4  TURN nodes DNS, TCP 3478 and 443, and the TLS certificate (verified, days remaining) from where you run this
#
# Exit 0 = every check passed, 1 = at least one failed, 2 = usage. A FAIL names what to fix and which doc section covers it.
set -uo pipefail

repo=""; product=""; ssh_target=""; root=""; port=22; php_bin="php"; hosts="turn-mum.aicountly.com,turn-ncr.aicountly.com"; print=0
while [ $# -gt 0 ]; do
  case "$1" in
    --repo) repo="$2"; shift 2 ;; --product) product="$2"; shift 2 ;; --ssh) ssh_target="$2"; shift 2 ;; --root) root="$2"; shift 2 ;;
    --port) port="$2"; shift 2 ;; --php) php_bin="$2"; shift 2 ;; --turn-hosts) hosts="$2"; shift 2 ;; --print) print=1; shift ;;
    -h|--help) sed -n '2,22p' "$0"; exit 0 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done
{ [ -n "$repo" ] && [ -n "$product" ] && [ -n "$ssh_target" ] && [ -n "$root" ]; } || { echo "usage: $0 --repo OWNER/REPO --product remote|chat --ssh USER@HOST --root DOCROOT [options]" >&2; exit 2; }
case "$product" in remote|chat) ;; *) echo "--product must be remote or chat" >&2; exit 2 ;; esac
[[ "$root" =~ ^[A-Za-z0-9._/~-]+$ ]] || { echo "--root contains characters that are not a plain path" >&2; exit 2; }
[[ "$ssh_target" =~ ^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+$ ]] || { echo "--ssh must look like user@host" >&2; exit 2; }
[[ "$php_bin" =~ ^[A-Za-z0-9._/-]+$ ]] || { echo "--php is not a plain path" >&2; exit 2; }

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
fails=0
pass() { echo "  PASS  $*"; }
fail() { echo "  FAIL  $*"; fails=$((fails + 1)); }
info() { echo "  info  $*"; }
run()  { if [ "$print" -eq 1 ]; then echo "  would run: $*"; return 0; fi; "$@"; }
remote() { # one read-only command line on the API host
  if [ "$print" -eq 1 ]; then echo "  would run on $ssh_target: $1"; return 0; fi
  ssh -p "$port" -o BatchMode=yes -o ConnectTimeout=15 "$ssh_target" "$1"
}

echo "1. GitHub secrets (names only) — $repo"
need="SANDBOX_TURN_MUM_SECRET SANDBOX_TURN_NCR_SECRET SANDBOX_TURN_LEDGER_PASSWORD"
if [ "$product" = remote ]; then need="SANDBOX_SSH_HOST SANDBOX_SSH_USER SANDBOX_SSH_PRIVATE_KEY SANDBOX_SSH_REMOTE_ROOT $need"
else need="SANDBOX_SFTP_HOST SANDBOX_SFTP_USER SANDBOX_SSH_PRIVATE_KEY SANDBOX_SFTP_REMOTE_ROOT $need"; fi
if [ "$print" -eq 1 ]; then echo "  would run: gh secret list --repo $repo   (expect: $need)"
else
  have="$(gh secret list --repo "$repo" 2>/dev/null | awk '{print $1}')" || have=""
  if [ -z "$have" ]; then fail "could not list secrets (gh not authenticated, or no access to $repo)"; else
    for n in $need; do grep -qx "$n" <<<"$have" && pass "$n" || fail "$n is missing — docs/STAGING_TURN_DEPLOYMENT.md §3"; done
    grep -qx "SANDBOX_TURN_MUM_SECRET" <<<"$have" && info "(cannot compare the Mumbai and Noida secrets: values are never read. The injector refuses equal node secrets at deploy time.)"
  fi
fi

echo "2. Target is not production"
user="${ssh_target%@*}"
if [ "$print" -eq 1 ]; then echo "  would run: $here/../ci/sandbox-target-guard.sh $user $root"
elif bash "$here/../ci/sandbox-target-guard.sh" "$user" "$root" >/dev/null 2>&1; then pass "'$user' / '$root' is not a production account or path"
else fail "the target is a production account or path — fix the SANDBOX_* secrets (docs/STAGING_TURN_DEPLOYMENT.md §2)"; fi

echo "3. API host ($ssh_target)"
if [ "$print" -eq 1 ]; then
  remote "id -un; echo \$HOME; $php_bin -v | head -1; $php_bin -m | grep -E '^(sodium|pdo_pgsql|openssl|curl)\$'"
  remote "test -f '$root/api/.env' && echo env-present; grep -E '^WEBRTC_TURN_ADMISSION' '$root/api/.env'; cat '$root/api/lib/ice-kit/VERSION'"
  remote "cd '$root/api' && $php_bin lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --status"
else
  out="$(remote "id -un; echo HOME=\$HOME; $php_bin -r 'echo \"php \".PHP_VERSION.\"\\n\"; foreach ([\"sodium\",\"pdo_pgsql\",\"openssl\",\"curl\"] as \$e) echo \$e.\"=\".(extension_loaded(\$e)?\"yes\":\"NO\").\"\\n\";'; if [ -f '$root/api/.env' ]; then echo env=present; else echo env=MISSING; fi; grep -E '^WEBRTC_TURN_ADMISSION' '$root/api/.env' 2>/dev/null | sed 's/ //g'; cat '$root/api/lib/ice-kit/VERSION' 2>/dev/null | sed 's/^/kit=/'" 2>&1)" || { fail "cannot reach $ssh_target over SSH"; out=""; }
  if [ -n "$out" ]; then
    got_user="$(head -1 <<<"$out")"
    case "$got_user" in remoteaicountly|connectaicountly) fail "logged in as the PRODUCTION account '$got_user' — stop";; *) pass "logged in as '$got_user'";; esac
    grep -q '^env=present' <<<"$out" && pass "api/.env present" || fail "api/.env missing — create it from .env.example, then rerun the deploy"
    for e in sodium pdo_pgsql openssl curl; do grep -q "^$e=yes" <<<"$out" && pass "PHP extension $e" || fail "PHP extension $e is missing (docs/STAGING_TURN_DEPLOYMENT.md §7)"; done
    mode="$(grep -E '^WEBRTC_TURN_ADMISSION' <<<"$out" | head -1 | sed 's/.*=//')"
    case "${mode:-}" in enforce) fail "WEBRTC_TURN_ADMISSION=enforce — staging must run observe";; "") info "WEBRTC_TURN_ADMISSION not set (defaults to observe)";; *) pass "WEBRTC_TURN_ADMISSION=$mode";; esac
    grep -q '^kit=' <<<"$out" && info "$(grep '^kit=' <<<"$out" | head -1)" || fail "the ICE kit is not deployed (lib/ice-kit/VERSION missing) — run the sandbox deploy"
    if grep -q '^kit=' <<<"$out"; then
      st="$(remote "cd '$root/api' && $php_bin lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --status 2>&1 | head -40" 2>&1)" || st=""
      if grep -q 'relay configured: yes' <<<"$st"; then pass "probe --status: relay configured"; else fail "probe --status does not show a configured relay — apply the observe config (§5)"; fi
      grep -qi 'warning:' <<<"$st" && fail "probe --status printed a warning (e.g. two nodes sharing a secret):"$'\n'"$(grep -i 'warning:' <<<"$st" | head -3)"
      grep -qi 'enforceBlockedBy\|held back\|licence' <<<"$st" && info "licence gate: $(grep -iE 'enforceBlockedBy|held back' <<<"$st" | head -1)"
    fi
  fi
fi

echo "4. TURN nodes (from this machine)"
IFS=',' read -r -a hl <<<"$hosts"
for h in "${hl[@]}"; do
  [[ "$h" =~ ^[A-Za-z0-9.-]+$ ]] || { fail "bad host name '$h'"; continue; }
  if [ "$print" -eq 1 ]; then echo "  would run: getent hosts $h; bash -c '</dev/tcp/$h/3478'; bash -c '</dev/tcp/$h/443'; openssl s_client -connect $h:443 -servername $h -verify_return_error"; continue; fi
  ip="$(getent hosts "$h" | awk '{print $1; exit}')"
  [ -n "$ip" ] && pass "$h resolves to $ip" || { fail "$h does not resolve"; continue; }
  for p in 3478 443; do
    if timeout 5 bash -c "</dev/tcp/$ip/$p" 2>/dev/null; then pass "$h:$p accepts TCP"; else fail "$h:$p does not accept TCP from here (a firewall between you and the node? the API host may differ)"; fi
  done
  cert="$(timeout 10 openssl s_client -connect "$ip:443" -servername "$h" -verify_hostname "$h" -verify_return_error </dev/null 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | sed 's/notAfter=//')"
  if [ -n "$cert" ]; then
    days=$(( ( $(date -d "$cert" +%s) - $(date +%s) ) / 86400 ))
    [ "$days" -ge 14 ] && pass "$h TLS certificate verified, $days days remaining" || fail "$h TLS certificate has only $days days left"
  else fail "$h TLS on 443 did not verify (expired, wrong name, untrusted, or not TLS)"; fi
done

echo
if [ "$print" -eq 1 ]; then echo "(--print: nothing was run)"; exit 0; fi
[ "$fails" -eq 0 ] && { echo "preflight: all checks passed"; exit 0; } || { echo "preflight: $fails check(s) failed"; exit 1; }
