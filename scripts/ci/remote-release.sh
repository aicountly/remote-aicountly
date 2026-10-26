#!/usr/bin/env bash
# remote-release.sh — runs ON the server (piped by the deploy workflows over `ssh deploy-target`):
#
#   ssh deploy-target "bash -s -- <document root> <stage> <revision> <check url>" < scripts/ci/remote-release.sh
#
# Atomic deploys (console-react-app ops/estate/kit/DEPLOY_SAFETY.md §1): backend/ is no longer rsynced over the
# live api/. The workflow uploads it to <stage> (~/.aicountly-deploy/remote-<env>/stage, outside the document
# root, same filters the api/ rsync had); this then makes api/ a symlink to a complete release folder built from
# the stage, switched in ONE rename by backend/bin/ops/atomic-switch.sh (the ops kit's copy):
#
#   - writable/ is shared between releases: it is the folder the old rsync protected ('P /writable/***' —
#     TURN node health, admission books, licence cache, logs, sessions). Its cache/ logs/ session/ uploads/
#     debugbar/ subfolders are created first (CodeIgniter 500s every request without writable/cache/).
#   - api/.env is carried over from the current release (atomic-switch.sh); the steps after this one (TURN
#     secrets, TURN config, the scheduled jobs' monitor key) edit the new release's own copy, which the next
#     release carries forward.
#   - --check-url (/api/health) must answer 2xx/3xx afterwards or the previous code is put back (exit 21; on
#     the first run the original plain folder).
#   - The migrations stay where they were: the next workflow step, php spark migrate in <document root>/api
#     (now the new release, through the symlink), idempotent and checked by remote:db-check.
#
# Exit codes: 0 live · 3 the server is not ready (no api/ or api/.env — first deploy: see docs/DEPLOYMENT.md)
# · atomic-switch.sh's own otherwise (10 stage incomplete, 17 switch failed, 21 check failed and previous code
# restored, 22 check failed and NOT restored: act now).
set -uo pipefail

root="${1:?document root}"
stage="${2:?stage}"
revision="${3:?revision}"
check_url="${4:-}"

root="${root#\~/}"
cd "$HOME" || exit 3
cd "$root" 2>/dev/null || { echo "::error::the document root $root does not exist on the server"; exit 3; }
doc="$(pwd -P)"      # the document root is a real folder; only api/ becomes a symlink
live="$doc/api"
case "$stage" in /*) ;; *) stage="$HOME/${stage#\~/}" ;; esac

[ -f "$stage/public/index.php" ] && [ -f "$stage/bin/ops/atomic-switch.sh" ] \
  || { echo "::error::the stage $stage is incomplete (no public/index.php or bin/ops/atomic-switch.sh) — the upload did not finish"; exit 10; }
if [ ! -e "$live" ] || [ ! -f "$live/.env" ]; then
  echo "::error::$live/.env does not exist. The first deploy of a new server creates api/ and its .env by hand (docs/DEPLOYMENT.md); the atomic switch never invents one."
  exit 3
fi

# The runtime folders the old deploy created after its rsync: in the live writable/ (the plain folder's before
# the conversion, the releases' shared one after it), so the release switched to finds them.
mkdir -p "$live/writable/cache" "$live/writable/logs" "$live/writable/session" "$live/writable/uploads" "$live/writable/debugbar" \
  || { echo "::error::could not create the writable/ subfolders in $live/writable"; exit 17; }

check_args=()
[ -n "$check_url" ] && check_args=(--check-url="$check_url")
rc=0
bash "$stage/bin/ops/atomic-switch.sh" --src="$stage" --live="$live" --revision="$revision" \
  --shared=writable ${check_args[@]+"${check_args[@]}"} || rc=$?
case "$rc" in
  0) echo "remote-release: $live -> $(readlink "$live") ($revision)" ;;
  21) echo "::error::the new release failed its check ($check_url); the previous code is live again (atomic-switch exit 21)" ;;
  22) echo "::error::the check failed AND the previous code could not be restored — act now (atomic-switch exit 22)" ;;
  *) echo "::error::the atomic switch failed (exit $rc); the previous code is still live" ;;
esac
exit "$rc"
