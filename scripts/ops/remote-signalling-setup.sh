#!/usr/bin/env bash
# remote-signalling-setup.sh — run AICOUNTLY Remote signalling as a systemd service behind Apache on server1 (AlmaLinux + cPanel,
# no CloudLinux, no Passenger). Run as root:   bash scripts/ops/remote-signalling-setup.sh sandbox     (then: production)
# Node 22 is used from /opt/cpanel/ea-nodejs22 (dnf install ea-nodejs22) when the system Node is older. After this, every
# Remote deploy ships the code and restarts the service (scripts/ci/deploy-signalling.sh); re-running this is only needed
# to change the service itself.
#
# What it does, per environment:
#   1. checks Node >= 22, the Apache proxy modules and a free local port (stops if anything is missing; changes nothing then)
#   2. copies signalling/ from GitHub main into the cPanel user's home and installs its one dependency (ws), as that user
#   3. writes the service settings to /etc/aicountly/remote-signalling-<env>.env (root only, mode 0600), taking the token secret
#      from the API's own .env (remote.signallingSecret) so the two are byte-identical; the secret is never printed
#   4. installs remote-signalling-<env>.service (runs as the cPanel user, listens on 127.0.0.1 only) and a restart trigger
#      (writing <app dir>/restart.txt restarts it, so a deploy can restart it without root)
#   5. adds an Apache include that proxies https://<domain>/signal (WebSocket + /signal/health) to the service; the web deploy
#      cannot remove it, because it lives in /etc/apache2, not in the document root
#   6. checks /signal/health locally and through the site
set -euo pipefail

case "${1:-}" in
  sandbox)
    CUSER=ghaicountly; DOMAIN=remote.gh.aicountly.com; DOCROOT=/home/ghaicountly/public_html/remote.gh.aicountly.com
    APPDIR=/home/ghaicountly/remote-signalling-sandbox; PORT=8788 ;;
  production)
    CUSER=remoteaicountly; DOMAIN=remote.aicountly.com; DOCROOT=/home/remoteaicountly/public_html
    APPDIR=/home/remoteaicountly/remote-signalling; PORT=8787 ;;
  *) echo "usage: bash $0 sandbox|production" >&2; exit 2 ;;
esac
ENVNAME="$1"; UNIT="remote-signalling-$ENVNAME"; SETTINGS="/etc/aicountly/$UNIT.env"
say() { printf '\n== %s\n' "$*"; }
die() { printf 'STOPPED: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" = 0 ] || die "run as root"
id "$CUSER" >/dev/null 2>&1 || die "no account $CUSER"
[ -f "$DOCROOT/api/.env" ] || die "no $DOCROOT/api/.env"

say "1. pre-checks"
for c in "${NODE:-}" /opt/cpanel/ea-nodejs22/bin/node /opt/node22/bin/node "$(command -v node || true)"; do [ -n "$c" ] && [ -x "$c" ] && "$c" -p "process.versions.node.split(\".\")[0] >= 22 ? 0 : process.exit(1)" >/dev/null 2>&1 && { NODE="$c"; break; }; done; NODE="${NODE:-$(command -v node || true)}"; [ -n "$NODE" ] || die "node is not installed"
NODE_MAJOR=$("$NODE" -p 'process.versions.node.split(".")[0]')
echo "node $("$NODE" -v) at $NODE"
[ "$NODE_MAJOR" -ge 22 ] || die "node 22 or newer is needed (found $("$NODE" -v))"
NODEBIN=$(dirname "$NODE"); [ -x "$NODEBIN/npm" ] || die "npm is not installed next to $NODE"
HTTPD_VER=$(httpd -v | sed -n 's|.*Apache/\([0-9.]*\).*|\1|p')
echo "apache $HTTPD_VER"
printf '%s\n2.4.47\n' "$HTTPD_VER" | sort -V -C -r || die "Apache 2.4.47 or newer is needed for WebSocket proxying (found $HTTPD_VER)"
MODS=$(httpd -M 2>/dev/null)
for m in proxy_module proxy_http_module; do
  grep -q "$m" <<<"$MODS" || die "Apache module $m is not loaded (dnf install ea-apache24-mod_proxy ea-apache24-mod_proxy_http)"
done
echo "apache proxy modules: ok"
if ss -ltnH "sport = :$PORT" | grep -q . && ! systemctl is-active --quiet "$UNIT"; then
  die "port $PORT is already in use by something else"
fi
echo "port $PORT: ok"

say "2. code and dependencies (as $CUSER)"
CHOME=$(getent passwd "$CUSER" | cut -d: -f6)
runuser -u "$CUSER" -- env HOME="$CHOME" PATH="$NODEBIN:/usr/bin:/bin" bash -c '
  set -euo pipefail
  tmp="$1.tmp"; rm -rf "$tmp"; mkdir -p "$tmp" "$1"
  curl -fsSL https://github.com/aicountly/remote-aicountly/archive/refs/heads/main.tar.gz | tar xz -C "$tmp"
  rsync -a --delete --exclude node_modules --exclude restart.txt "$tmp/remote-aicountly-main/signalling/" "$1/"
  rm -rf "$tmp"
  cd "$1" && npm ci --omit=dev --no-audit --no-fund --loglevel=error
  ls node_modules/ws/package.json >/dev/null && echo "installed ws $(node -p "require(\"./node_modules/ws/package.json\").version")"
' _ "$APPDIR"

say "3. service settings (secret taken from $DOCROOT/api/.env, not printed)"
SECRET=$(grep -E '^\s*remote\.signallingSecret\s*=' "$DOCROOT/api/.env" | tail -1 | sed -E "s/^[^=]*=\s*//; s/^[\"']//; s/[\"']\s*$//; s/\s+$//")
[ -n "$SECRET" ] || die "remote.signallingSecret is empty in $DOCROOT/api/.env"
# systemd reads quotes, backslashes and '#' specially; refuse rather than hand the service a different secret from the API's.
[[ "$SECRET" =~ ^[A-Za-z0-9+/=_.:-]+$ ]] || die "remote.signallingSecret has characters a systemd settings file would alter; use a hex secret"
install -d -m 0700 /etc/aicountly
( umask 077
  printf 'REMOTE_SIGNALLING_TOKEN_SECRET=%s\nREMOTE_SIGNAL_ALLOWED_ORIGINS=https://%s\nREMOTE_SIGNAL_HOST=127.0.0.1\nREMOTE_SIGNAL_PORT=%s\nNODE_ENV=production\n' \
    "$SECRET" "$DOMAIN" "$PORT" > "$SETTINGS" )
unset SECRET
echo "wrote $SETTINGS ($(stat -c '%a %U' "$SETTINGS"))"

say "4. systemd service and restart trigger"
cat > "/etc/systemd/system/$UNIT.service" <<EOF
[Unit]
Description=AICOUNTLY Remote signalling ($ENVNAME, $DOMAIN)
After=network.target

[Service]
Type=simple
User=$CUSER
WorkingDirectory=$APPDIR
EnvironmentFile=$SETTINGS
ExecStart=$NODE src/server.js
Restart=always
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=read-only

[Install]
WantedBy=multi-user.target
EOF
cat > "/etc/systemd/system/$UNIT-restart.service" <<EOF
[Unit]
Description=Restart $UNIT after a deploy wrote $APPDIR/restart.txt

[Service]
Type=oneshot
ExecStart=/usr/bin/systemctl restart $UNIT.service
EOF
cat > "/etc/systemd/system/$UNIT-restart.path" <<EOF
[Unit]
Description=Watch $APPDIR/restart.txt

[Path]
PathModified=$APPDIR/restart.txt

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now "$UNIT-restart.path" >/dev/null
systemctl enable "$UNIT.service" >/dev/null
systemctl restart "$UNIT.service"
sleep 3
systemctl is-active --quiet "$UNIT" || { journalctl -u "$UNIT" -n 20 --no-pager; die "$UNIT did not start"; }
echo "$UNIT: $(systemctl is-active "$UNIT")"
curl -fsS "http://127.0.0.1:$PORT/signal/health"; echo

say "5. Apache proxy for https://$DOMAIN/signal"
INC="/etc/apache2/conf.d/userdata/ssl/2_4/$CUSER/$DOMAIN"
mkdir -p "$INC"
cat > "$INC/remote-signalling.conf" <<EOF
# AICOUNTLY Remote signalling -> $UNIT.service on 127.0.0.1:$PORT (written by remote-signalling-setup.sh)
ProxyPass        /signal http://127.0.0.1:$PORT/signal upgrade=websocket timeout=120
ProxyPassReverse /signal http://127.0.0.1:$PORT/signal
EOF
/scripts/rebuildhttpdconf >/dev/null
if ! apachectl -t; then
  rm -f "$INC/remote-signalling.conf"; /scripts/rebuildhttpdconf >/dev/null
  die "Apache rejected the include; it was removed again and Apache was not restarted"
fi
/scripts/restartsrv_httpd >/dev/null
sleep 2

say "6. check through the site"
out=$(curl -sS "https://$DOMAIN/signal/health" || true)
echo "${out:0:200}"
if grep -q '"aicountly-remote-signalling"' <<<"$out"; then
  echo "PASS: https://$DOMAIN/signal/health answers from the signalling service"
else
  echo "FAIL: https://$DOMAIN/signal/health is not the signalling service yet (send this output)"
  exit 1
fi
