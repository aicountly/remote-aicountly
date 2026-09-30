#!/usr/bin/env bash
# post-deploy-checks.sh — is the live Remote really this app, and is it the build just deployed?
#
# Run by the deploy workflows after a deploy, and by verify-live.yml to check the live app without
# deploying anything. Every check goes through verify-live.sh, which passes only on the app's real
# answer, never on the host's anti-bot page, and repeats the request from the server (VERIFY_SSH)
# when the runner is shown that page.
#
# Usage: scripts/ci/post-deploy-checks.sh <production|sandbox>
#
# Environment:
#   VERIFY_SSH         command prefix that runs one command on the server ("ssh deploy-target"
#                      in the workflows); see verify-live.sh.
#   EXPECTED_ENTRY     the hashed entry script of the build just deployed, e.g.
#                      assets/index-C5tx8mVh.js from web/dist/index.html. Empty (checking without
#                      a deploy): the page's <title> is checked instead.
#   EXPECTED_REVISION  the commit just deployed. The Remote API does not report the revision it
#                      runs, so it is not compared; the entry script stands for the build.
#   VERIFY_BASE_URL    tests only: check this origin (e.g. http://127.0.0.1:18777) instead of the
#                      environment's real one.
set -uo pipefail

target="${1:-}"
case "$target" in
  production) origin="https://remote.aicountly.com" ;;
  sandbox) origin="https://remote.gh.aicountly.com" ;;
  *) echo "usage: $0 <production|sandbox>" >&2; exit 2 ;;
esac
base="${VERIFY_BASE_URL:-$origin}"
verify="$(cd "$(dirname "$0")" && pwd)/verify-live.sh"
entry="${EXPECTED_ENTRY:-}"

echo "Checking Remote ${target} at ${base}"
if [ -n "${EXPECTED_REVISION:-}" ]; then
  echo "The Remote API does not report its revision, so ${EXPECTED_REVISION} is not compared; the web entry script stands for the build."
fi

failed=0
# check <verify-live.sh arguments...>: one check; a failure is counted, the rest still run.
check() {
  bash "$verify" "$@" || failed=$((failed + 1))
}
# check_with_hint <hint> <verify-live.sh arguments...>: the same, and prints <hint> when it warned.
check_with_hint() {
  local hint="$1" out
  shift
  out="$(bash "$verify" "$@")" || failed=$((failed + 1))
  printf '%s\n' "$out"
  case "$out" in
    *'::warning title=Post-deploy check::'*) echo "  ${hint}" ;;
  esac
}

# It is Remote's API (fatal; the old check printed an error but let the deploy pass). "degraded"
# (HTTP 503) means it cannot reach its database: configuration on the server, not this deploy, so
# it only warns.
check_with_hint "degraded: the API cannot reach its database. Check api/.env, and writable/logs/log-$(date +%Y-%m-%d).log on the server for the exception." \
  json "Remote API (${target})" "${base}/api/health" \
  '.app == "AICOUNTLY Remote"' \
  '.status == "ok"'

# The web root serves the build just deployed, or at least the Remote page (fatal).
if [ -n "$entry" ]; then
  check page "Remote web (${target})" "${base}/" "$entry"
else
  check page "Remote web (${target})" "${base}/" '<title>AICOUNTLY Remote</title>'
fi

if [ "$failed" -gt 0 ]; then
  echo "${failed} post-deploy check(s) failed for Remote ${target}."
  exit 1
fi
echo "All post-deploy checks passed for Remote ${target}."
