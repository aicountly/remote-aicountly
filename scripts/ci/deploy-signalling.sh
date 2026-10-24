#!/usr/bin/env bash
# deploy-signalling.sh — update the Remote signalling service on the server after a deploy. Runs on the CI runner.
#
#   bash scripts/ci/deploy-signalling.sh <app dir under the cPanel user's home>     e.g. remote-signalling-sandbox
#
# The service itself (a systemd unit running as the cPanel user, an Apache proxy for /signal, and a path unit that
# restarts it when <app dir>/restart.txt is written) is installed once, as root, by remote-signalling-setup.sh. This
# only ships the code: rsync signalling/ (src and the lockfile, not the tests), `npm ci --omit=dev` with Node 22, then
# write restart.txt. node_modules and restart.txt are excluded from the rsync, so --delete never removes them.
#
# Needs the `deploy-target` SSH alias the deploy workflow configures. A server where the service was never set up is a
# notice, not a failure: the web and API deploy still stand, and the post-deploy signalling check says what is missing.
set -euo pipefail

app="${1:?app dir}"
case "$app" in *[!A-Za-z0-9._-]* | '') echo "::error::bad app dir" >&2; exit 2 ;; esac

if ! ssh deploy-target "test -f ~/$app/package.json"; then
  echo "::notice title=Signalling service::~/$app is not set up on this server, so the signalling code was not updated. Install it once as root with remote-signalling-setup.sh."
  exit 0
fi

rsync -az --delete \
  --exclude='node_modules/' \
  --exclude='restart.txt' \
  --exclude='test/' \
  --exclude='.git*' \
  --chmod=D755,F644 \
  -e ssh \
  signalling/ "deploy-target:$app/"

# Node 22 lives beside the system Node on the cPanel host (ea-nodejs22); the system Node is older and other apps use it.
ssh deploy-target "bash -s -- $(printf '%q' "$app")" <<'REMOTE'
set -euo pipefail
cd ~/"$1"
for bin in /opt/cpanel/ea-nodejs22/bin /opt/node22/bin; do
  if [ -x "$bin/npm" ]; then PATH="$bin:$PATH"; break; fi
done
major=$(node -p 'process.versions.node.split(".")[0]')
[ "$major" -ge 22 ] || { echo "::error title=Signalling service::Node 22 or newer is needed on the server (found $(node -v))"; exit 1; }
npm ci --omit=dev --no-audit --no-fund --loglevel=error
date -u +%Y-%m-%dT%H:%M:%SZ > restart.txt
echo "signalling code updated in ~/$1 (node $(node -v)); restart requested"
REMOTE

# The path unit restarts the service within a second or two; give it time before the live checks ask /signal/health.
sleep 6
