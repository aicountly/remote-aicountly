#!/usr/bin/env bash
# atomic-release-test.sh — the deploy's atomic switch (backend/bin/ops/atomic-switch.sh, the ops kit's
# copy) against this repo's REAL API (backend/) under php -S, across releases, the way
# scripts/ci/remote-release.sh runs it. Needs php, curl, rsync and backend/vendor (composer install).
#
#   bash scripts/ci/atomic-release-test.sh
#
#  1. a plain <docroot>/api folder (as the rsync deploy left it) is converted: api/ becomes a symlink,
#     .env and writable/ (shared) are kept, /api/health answers through the symlink (200 with a database,
#     503 "degraded" without one — the API's own JSON either way)
#  2. releases are switched while requests run: every answer is the API's own JSON (never a blank 500)
#  3. a stale worker that opened the PREVIOUS release's front controller is handed to the current one by
#     the atomic entry guard: the API's answer, no "Cannot redeclare" fatal
#  4. the guarded entry files declare nothing at top level (functions/classes would be bound at compile
#     time, before the guard, and redeclared when it hands the request to the other release's copy)
set -uo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
API_SRC="$REPO/backend"
SWITCH="$API_SRC/bin/ops/atomic-switch.sh"
PORT="${PORT:-34189}"
[ -f "$API_SRC/vendor/autoload.php" ] || { echo "backend/vendor is missing: composer install first"; exit 2; }
WORK="$(mktemp -d "${TMPDIR:-/tmp}/remote-atomic.XXXXXX")"
export HOME="$WORK/home"
mkdir -p "$HOME"
pass=0 fail=0
ok() { echo "PASS $*"; pass=$((pass + 1)); }
no() { echo "FAIL $*"; fail=$((fail + 1)); }
PID=""
cleanup() { [ -n "$PID" ] && kill "$PID" 2>/dev/null; rm -rf "${WORK:?}"; }
trap cleanup EXIT

DOC="$HOME/public_html"
LIVE="$DOC/api"
REL_DIR="$HOME/aicountly-releases/public_html-api"

make_release() { # dir revision
  mkdir -p "$1"
  # The deploy's own filters (deploy-*.yml "Deploy backend to the api folder"), vendor/ as the runner built it.
  rsync -a --exclude='/vendor/' --exclude='/writable/' --exclude='.env' --exclude='.env.*' --exclude='.git*' \
    --exclude='tests/' --exclude='deploy/' --exclude='phpunit*' --exclude='composer.json' --exclude='composer.lock' "$API_SRC/" "$1/"
  # Hard links where the temp folder shares the file system; a real copy otherwise (a failed cp -al leaves a
  # partial vendor/ that a second cp would nest inside, so it is removed first).
  cp -al "$API_SRC/vendor" "$1/vendor" 2>/dev/null || { rm -rf "$1/vendor"; cp -a "$API_SRC/vendor" "$1/vendor"; }
  mkdir -p "$1/writable/logs" "$1/writable/cache" "$1/writable/session" "$1/writable/uploads" "$1/writable/debugbar"
  echo "$2" > "$1/REVISION"
}

# ---- 4. nothing declared at top level in the guarded entries
for entry in public/index.php; do
  out="$(php -r '
    $t = token_get_all(file_get_contents($argv[1])); $depth = 0; $bad = [];
    $guard = strpos(file_get_contents($argv[1]), "// >>> aicountly atomic entry") !== false;
    foreach ($t as $i => $tok) {
      if ($tok === "{") { $depth++; continue; } if ($tok === "}") { $depth--; continue; }
      if (!is_array($tok)) continue;
      if (in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) { $depth++; continue; }
      if ($depth !== 0) continue;
      $kinds = [T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT] + (defined("T_ENUM") ? [4 => T_ENUM] : []);
      if (!in_array($tok[0], $kinds, true)) continue;
      for ($j = $i + 1; isset($t[$j]) && is_array($t[$j]) && $t[$j][0] === T_WHITESPACE; $j++);
      if (isset($t[$j]) && is_array($t[$j]) && $t[$j][0] === T_STRING) $bad[] = $t[$j][1] . " (line " . $tok[2] . ")";
    }
    echo ($guard ? "guard" : "noguard"), " ", implode(", ", $bad);' "$API_SRC/$entry")"
  case "$out" in
    "guard ") ok "$entry carries the guard and declares nothing at top level" ;;
    *) no "$entry: $out" ;;
  esac
done

# ---- a plain live folder, as every deploy before this one left it
make_release "$LIVE" v0
# The CI test database when there is one (ci.yml), else none: health then answers 503 "degraded", still JSON.
printf 'CI_ENVIRONMENT = production\napp.forceGlobalSecureRequests = false\ndatabase.default.hostname = %s\ndatabase.default.database = %s\ndatabase.default.username = %s\ndatabase.default.password = %s\ndatabase.default.DBDriver = Postgre\ndatabase.default.port = %s\n' \
  "${ATOMIC_TEST_DB_HOST:-127.0.0.1}" "${ATOMIC_TEST_DB_NAME:-aicountly_remote_test}" "${ATOMIC_TEST_DB_USER:-aicountly_remote}" \
  "${ATOMIC_TEST_DB_PASS:-remote_ci}" "${ATOMIC_TEST_DB_PORT:-5432}" > "$LIVE/.env"
echo "old log" > "$LIVE/writable/logs/keep.log"
echo '<!doctype html><title>Remote</title>' > "$DOC/index.html"
cat > "$WORK/router.php" <<'EOF'
<?php
// api/.htaccess: everything under /api/ that is not a file runs api/public/index.php; Apache passes
// SCRIPT_FILENAME as the path THROUGH the api symlink. X-Stale-Entry makes this worker open another
// release's copy of the front controller first — a PHP worker whose realpath cache still holds it.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($path, '/api/') !== 0) { return false; }
if (strpos($path, '/api/public/') === 0 && is_file($_SERVER['DOCUMENT_ROOT'] . $path) && substr($path, -4) !== '.php') {
    return false;   // a static file of the release (robots.txt), served through the symlink
}
$file = $_SERVER['DOCUMENT_ROOT'] . '/api/public/index.php';
$_SERVER['SCRIPT_FILENAME'] = $file;
$_SERVER['SCRIPT_NAME'] = substr($file, strlen($_SERVER['DOCUMENT_ROOT']));
chdir(dirname($file));
$stale = $_SERVER['HTTP_X_STALE_ENTRY'] ?? '';
require $stale !== '' ? $stale : $file;
return true;
EOF
start_server() {
  [ -n "$PID" ] && { kill "$PID" 2>/dev/null; wait "$PID" 2>/dev/null; }
  (cd "$HOME" && PHP_CLI_SERVER_WORKERS=4 exec php -S "127.0.0.1:$PORT" -t "$DOC" "$WORK/router.php" > "$WORK/server.log" 2>&1) &
  PID=$!
  for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$PORT/api/health" && break; sleep 0.1; done
}
URL="http://127.0.0.1:$PORT/api/health"
is_api_answer() { # "<body> <code>" -> the API's own JSON health answer (200 ok, or 503 degraded: no DB here)
  case "$1" in *'AICOUNTLY Remote'*' 200' | *'AICOUNTLY Remote'*' 503') return 0 ;; *) return 1 ;; esac
}
start_server
a="$(curl -s -w ' %{http_code}' "$URL")"
is_api_answer "$a" && ok "the plain folder answers /api/health" || no "plain folder: ${a:0:200}"

# ---- 1. the first run converts
make_release "$WORK/stage" v1
out="$(bash "$SWITCH" --src="$WORK/stage" --live="$LIVE" --revision=v1 --shared=writable --check-url="http://127.0.0.1:$PORT/api/public/robots.txt" 2>&1)"
rc=$?
[ "$rc" = 0 ] && ok "conversion exit 0" || { no "conversion exit $rc"; echo "$out"; }
echo "$out" | grep -q '(converted from a plain folder)' && ok "the log says it converted the plain folder" || no "no conversion line"
[ -L "$LIVE" ] && ok "api/ is a symlink" || no "api/ is not a symlink"
[ "$(cat "$LIVE/REVISION")" = v1 ] && ok "REVISION written" || no "REVISION missing"
[ "$(cat "$LIVE/writable/logs/keep.log" 2>/dev/null)" = "old log" ] && ok "writable/ kept (shared)" || no "writable/ lost"
grep -q 'database.default.DBDriver = Postgre' "$LIVE/.env" && ok ".env carried into the release" || no ".env not carried"
a="$(curl -s -w ' %{http_code}' "$URL")"
is_api_answer "$a" && ok "/api/health answers through the symlink" || no "after conversion: ${a:0:200}"

# Workers recycle (or the realpath cache expires) before the next deploy.
echo $(( $(date +%s) - 300 )) > "$REL_DIR/.converted-at"
start_server

# ---- 2. switches under load
make_release "$WORK/stage2" v2
: > "$WORK/answers"
load() { for _ in $(seq 1 25); do curl -s -m 20 -w ' %{http_code}\n' "$URL" | tr -d '\n' >> "$WORK/answers.$1"; echo >> "$WORK/answers.$1"; done; }
loaders=()
for n in 1 2 3 4; do load "$n" & loaders+=($!); done
for i in 1 2 3 4; do
  if [ $((i % 2)) = 1 ]; then src="$WORK/stage2"; rev=v2; else src="$WORK/stage"; rev=v1; fi
  bash "$SWITCH" --src="$src" --live="$LIVE" --revision="$rev" --shared=writable > /dev/null 2>&1 || no "switch $i failed"
  sleep 0.3
done
wait "${loaders[@]}"
cat "$WORK"/answers.* > "$WORK/answers"
total="$(grep -c . "$WORK/answers")"
bad="$(grep -vcE 'AICOUNTLY Remote.* (200|503)$' "$WORK/answers")"
[ "$total" -ge 90 ] && [ "$bad" = 0 ] && ok "$total answers across 4 switches: every one the API's own" || { no "$bad of $total answers were not the API's"; grep -vE 'AICOUNTLY Remote.* (200|503)$' "$WORK/answers" | cut -c1-160 | sort | uniq -c | head -5; }

# ---- 3. a stale worker: it opened the previous release's front controller
prev=""
cur="$(readlink "$LIVE")"
for r in $(ls -1 "$REL_DIR/releases" | LC_ALL=C sort); do
  [ "$REL_DIR/releases/$r" = "$cur" ] && break
  prev="$REL_DIR/releases/$r"
done
[ -n "$prev" ] && [ -f "$prev/public/index.php" ] || no "no previous release to open"
a="$(curl -s -w ' %{http_code}' -H "X-Stale-Entry: $prev/public/index.php" "$URL")"
if is_api_answer "$a" && ! grep -q 'Cannot redeclare' "$WORK/server.log"; then ok "a worker holding the previous release's entry is handed to the current one"
else no "stale entry: ${a:0:200}"; grep -m3 'Cannot redeclare\|Fatal' "$WORK/server.log"; fi

echo "atomic-release-test: $pass passed, $fail failed"
[ "$fail" = 0 ]
