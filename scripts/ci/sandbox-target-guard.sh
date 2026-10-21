#!/usr/bin/env bash
# sandbox-target-guard.sh — refuse a SANDBOX deploy whose target is a production account or path.
#
#   scripts/ci/sandbox-target-guard.sh <ssh-user> <remote-root>
#
# Identical in AICOUNTLY Remote and Connect. The sandbox workflows deploy with `rsync --delete` and write a
# .env, so a SANDBOX_* secret that was pointed (by mistake) at the production cPanel account would overwrite
# the live application. This runs before anything is built or sent and fails closed:
#
#   * the SSH user must not be a production account;
#   * the remote root must not be inside a production account's home (/home/<account>/...);
#   * a relative root is resolved against the SSH user's home, so it is covered by the user check.
#
# Production accounts: remoteaicountly connectaicountly (override with PRODUCTION_ACCOUNTS="a b c" — it can add
# to the list but the two defaults are always refused). Exit 0 = acceptable sandbox target, 1 = refused.
set -uo pipefail

user="${1:-}"
root="${2:-}"
[ -n "$user" ] && [ -n "$root" ] || { echo "usage: $0 <ssh-user> <remote-root>" >&2; exit 2; }

accounts="remoteaicountly connectaicountly ${PRODUCTION_ACCOUNTS:-}"
lower() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]'; }

u="$(lower "$user")"
r="$(lower "$root")"

for account in $accounts; do
  a="$(lower "$account")"
  if [ "$u" = "$a" ]; then
    echo "::error::The sandbox SSH user is the production account '$account'. Point SANDBOX_* at the sandbox cPanel account (the one serving the .gh.aicountly.com host), never at production." >&2
    exit 1
  fi
  case "$r" in
    "/home/$a" | "/home/$a/"* | "~$a" | "~$a/"*)
      echo "::error::The sandbox remote root is inside the production account '$account' ($root). Refusing: a --delete deploy there would overwrite the live application." >&2
      exit 1 ;;
  esac
done

echo "Sandbox target is not a production account (user '$user')."
exit 0
