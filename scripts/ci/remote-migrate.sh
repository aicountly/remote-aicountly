#!/usr/bin/env bash
# remote-migrate.sh — runs ON the server, sent over ssh stdin by the deploy workflows:
#
#   ssh deploy-target "bash -s -- <remote root> <php cli or ''> <status|apply>" < scripts/ci/remote-migrate.sh
#
# Runs CodeIgniter's migration runner (php spark ...) in <remote root>/api. Nothing is left on the
# server (apply's one temporary file, for DBDebug below, is removed on exit). <remote root> is the *_SSH_REMOTE_ROOT secret: a relative path is resolved against the SSH
# user's home directory, exactly as rsync resolves it, and a leading ~/ is expanded.
#
#   status  php spark remote:db-check (read-only: is the database reached, is anything pending), and
#           when something is pending, php spark migrate:status, the runner's own list. Prints one
#           "PENDING <version> <name>" line per pending migration.
#   apply   php spark migrate, then php spark remote:db-check again, which must report the database
#           fully migrated. spark migrate has no dry run, so there is none.
#
# spark migrate runs with database.default.DBDebug on. api/.env sets it false for the web API, and
# with it off a statement that fails returns false instead of throwing, so a broken migration would
# be recorded as applied. With it on the failing migration throws and is not recorded. spark migrate
# still exits 0 after a failed migration, which is why "apply" judges by remote:db-check afterwards.
#
# PHP: the <php cli> argument (the *_PHP_CLI repository variable) when it is not empty, otherwise the
# first of /opt/cpanel/ea-php84/83/82/81, /usr/local/bin/php and the php on PATH that is PHP 8.2+
# with pgsql (CodeIgniter's Postgre driver), intl and mbstring. cPanel's default php is often older or
# lacks pgsql, which is why it comes last.
#
# Exit status: 0 ok (status: nothing pending); 10 status: migrations are pending; 1 the status could
# not be read, or a migration failed; 2 usage; 3 the api folder, spark or api/.env is missing, or no
# usable PHP.
set -uo pipefail

root="${1:-}"
php="${2:-}"
mode="${3:-}"

case "$mode" in
  status | apply) ;;
  *)
    echo "usage: remote-migrate.sh <remote root> <php cli or ''> <status|apply>" >&2
    exit 2
    ;;
esac

# A literal ~/ arrives quoted (printf %q on the runner), so it is expanded here.
# shellcheck disable=SC2088
case "$root" in
  '')
    echo "usage: remote-migrate.sh <remote root> <php cli or ''> <status|apply>" >&2
    exit 2
    ;;
  '~/'*) root="${HOME}/${root#'~/'}" ;;
  /*) ;;
  *) root="${HOME}/${root}" ;;
esac
api="${root%/}/api"

if ! cd "$api" 2> /dev/null; then
  echo "${api} does not exist on the server: the API has not been deployed there." >&2
  exit 3
fi
if [ ! -f spark ]; then
  echo "${api}/spark is missing: the API upload is incomplete." >&2
  exit 3
fi
if [ ! -f .env ]; then
  echo "${api}/.env does not exist, so the migrations have no database to run against. Create it from" >&2
  echo "backend/.env.example (first deploy: run the deploy with migrate = skip, then see docs/DEPLOYMENT.md)." >&2
  exit 3
fi

usable() {
  [ -n "$1" ] && [ -x "$1" ] &&
    "$1" -r 'exit(PHP_VERSION_ID >= 80200 && extension_loaded("pgsql") && extension_loaded("intl") && extension_loaded("mbstring") ? 0 : 1);' > /dev/null 2>&1
}

if [ -n "$php" ]; then
  if ! usable "$php"; then
    echo "The PHP CLI named by the *_PHP_CLI variable (${php}) is not executable, or is not PHP 8.2+ with pgsql, intl and mbstring." >&2
    exit 3
  fi
else
  for candidate in \
    /opt/cpanel/ea-php84/root/usr/bin/php \
    /opt/cpanel/ea-php83/root/usr/bin/php \
    /opt/cpanel/ea-php82/root/usr/bin/php \
    /opt/cpanel/ea-php81/root/usr/bin/php \
    /usr/local/bin/php \
    "$(command -v php 2> /dev/null || true)"; do
    if usable "$candidate"; then
      php="$candidate"
      break
    fi
  done
  if [ -z "$php" ]; then
    echo "No PHP 8.2+ with pgsql, intl and mbstring found (tried ea-php84/83/82/81, /usr/local/bin/php and php on PATH). Set the *_PHP_CLI repository variable." >&2
    exit 3
  fi
fi
echo "Using ${php} (PHP $("$php" -r 'echo PHP_VERSION;')) in ${api}"

if [ "$mode" = status ]; then
  check="$("$php" spark remote:db-check 2>&1)"
  rc=$?
  printf '%s\n' "$check"
  if [ "$rc" -eq 0 ]; then
    exit 0
  fi
  case "$check" in
    *'Reason: migrations_pending'* | *'Reason: not_migrated'*) ;;
    *) exit 1 ;;
  esac

  echo
  echo "php spark migrate:status"
  # Creates the migrations table when it does not exist yet, which spark migrate does next anyway.
  list="$("$php" spark migrate:status 2>&1)"
  rc=$?
  printf '%s\n' "$list"
  [ "$rc" -eq 0 ] || exit 1
  # Rows look like "| App | 2026-10-16-000400 | 2026-10-16-000400_Name | --- | --- | --- |"; a
  # pending one has no group, date or batch. Colour codes, if any, are stripped first.
  printf '%s\n' "$list" | sed 's/\x1b\[[0-9;]*m//g' |
    awk -F'|' 'NF >= 8 { for (i = 2; i <= 7; i++) gsub(/^ +| +$/, "", $i); if ($5 == "---" && $6 == "---") print "PENDING " $3 " " $4 }'
  exit 10
fi

# apply
echo "php spark migrate"
# DBDebug on for this run only. A process environment variable cannot do it: PHP does not import a
# name with dots into $_ENV, and CodeIgniter reads $_ENV, where api/.env's value lands, first. A
# prepended file that sets it before CodeIgniter loads api/.env does (DotEnv keeps a value already set).
prepend="$(mktemp "${TMPDIR:-/tmp}/remote-migrate.XXXXXX")" || exit 1
trap 'rm -f "$prepend"' EXIT
# shellcheck disable=SC2016  # PHP source, written literally
printf '%s\n' '<?php' '$_ENV["database.default.DBDebug"] = "true";' > "$prepend"
out="$("$php" -d auto_prepend_file="$prepend" spark migrate 2>&1)"
rc=$?
printf '%s\n' "$out"
# spark migrate prints the exception ("[CodeIgniter\...\DatabaseException]") and still exits 0.
case "$out" in
  *'Exception]'*) [ "$rc" -ne 0 ] || rc=1 ;;
esac
echo
echo "php spark remote:db-check (after migrate)"
check="$("$php" spark remote:db-check 2>&1)"
check_rc=$?
printf '%s\n' "$check"
if [ "$rc" -ne 0 ] || [ "$check_rc" -ne 0 ]; then
  echo "spark migrate exited ${rc}; remote:db-check afterwards exited ${check_rc} (0 = fully migrated)." >&2
  exit 1
fi
exit 0
