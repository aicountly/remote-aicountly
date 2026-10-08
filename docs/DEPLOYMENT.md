# Deploying AICOUNTLY Remote

Three pieces deploy separately, because they are genuinely different things:

| Piece | Built by | Lands at |
|---|---|---|
| `web/` | Vite on the runner | the cPanel document root |
| `backend/` | Composer on the runner | `<document root>/api/` |
| `signalling/` | nothing to build | a long-running Node process |

Deployment is **manual only** — **Actions → pick a workflow → Run workflow**.
Nothing deploys on push or merge. `.github/workflows/ci.yml` runs the tests on
every pull request and every push to `main` but never deploys. Both deploy
workflows run that full suite first: their first job calls `ci.yml` on the
commit being deployed and the deploy job `needs:` it, so a red suite stops the
deploy.

| Workflow | Target |
|---|---|
| Deploy to cPanel Production | https://remote.aicountly.com |
| Deploy to cPanel Sandbox | https://remote.gh.aicountly.com |

Production and sandbox are separate targets with separate credentials, so
releasing to one cannot disturb the other. Within one environment, the web
build and the API deploy in the same run: they always change together.

---

## Layout on the server

```
<document root>/                 web/dist — the React app
├── index.html
├── assets/
├── .htaccess                    SPA history fallback, cache headers, CSP
└── api/                         backend/ — the CodeIgniter API
    ├── .htaccess                rewrites everything into public/, and refuses the rest
    ├── .env                     created by hand, never uploaded
    ├── public/                  the front controller
    ├── app/                     denied by its own .htaccess
    ├── vendor/                  denied (the workflow adds the rule)
    └── writable/                denied; must be writable by PHP
```

### Why `api/` survives the web deploy

The web step runs `rsync --delete` against the document root, which would
otherwise remove everything not in the build — including `api/`. That step
excludes `api/` explicitly. **Removing that exclude deletes the entire backend
on the next deploy.**

### Why CodeIgniter's `public/` is not the deploy target

CodeIgniter expects `public/` to be the document root, but the target here is a
folder *inside* the site's document root. `backend/.htaccess` bridges the two:
every request to `api/` is rewritten into `api/public/`.

The alternative — deploying `public/` to `api/` and the framework above the
document root — needs a second rsync target and a second secret, and produces a
layout that no longer matches the repository. Instead, every directory that must
not be served carries `Require all denied`: `app/`, `writable/`, `tests/`, and
`vendor/` (the workflow copies the rule in, since `vendor/` is not in the
repository). `backend/.htaccess` also refuses them by name, ahead of the
rewrite, together with `system/`, `scripts/`, `database/`, `build/`, `deploy/`,
the `spark` CLI, `composer.json`, `composer.lock` and `phpunit*`, and any
dotfile. `tests/Deploy/ApiHtaccessTest.php` evaluates those rules against real
paths and against the route table, and fails when something new appears at the
top of `backend/` that is neither refused nor `public/`.

Verify after the first deploy:

```bash
curl -i https://remote.aicountly.com/api/app/Config/Remote.php   # expect 403/404
curl -i https://remote.aicountly.com/api/.env                    # expect 404
curl -i https://remote.aicountly.com/api/writable/logs/          # expect 403/404
curl -i https://remote.aicountly.com/api/spark                   # expect 403/404, never 200 or 500
```

### Why the API's `.env` survives

Both rsync steps run with `--delete`. The API's `.env` is created once by hand
and exists nowhere else, so `--exclude='.env'` and `--exclude='.env.*'` are what
keep it alive. `writable/cache`, `writable/logs` and `writable/session` are
excluded for the same reason — they hold the rate limiter's buckets and the
application log.

Neither `.env` is ever uploaded either: `.gitignore` keeps them out of the
repository, and the workflow fails outright if a committed one appears under
`backend/`.

---

## Configuration: two opposite mechanisms

### `web/` — build time

Vite inlines every `VITE_*` value into the bundle when the app is compiled. The
result is static files that **never read a `.env` from disk**. Putting a `.env`
in the document root has no effect.

To change a frontend value: change it in the workflow (or in the optional
repository variable), then re-run the workflow. The rebuild is what applies it.

Never put a secret in a `VITE_` variable — anything inlined into the bundle is
public. The signalling URL and the ICE servers are deliberately not build-time
configuration: the browser receives them from the API, per session, so a TURN
credential never ends up in a bundle.

### `backend/` — runtime

PHP reads its `.env` on **every request**, so it belongs on the server and only
on the server. Create it once at `<document root>/api/.env` from
`backend/.env.example`.

The minimum for a working deployment:

```dotenv
CI_ENVIRONMENT = production
app.baseURL = 'https://remote.aicountly.com/api/'

CONSOLE_API_URL = https://console.aicountly.org/api
CONSOLE_DB_DETAILS_KEY = <the key generated on Remote's row in Console, starting sdb_>

database.default.hostname = localhost
database.default.password = <password of the user Console names>
database.default.DBDriver = Postgre
database.default.DBDebug = false

remote.signallingSecret = <openssl rand -base64 48>
remote.contextSecret    = <openssl rand -base64 48>
remote.appUrl    = https://remote.aicountly.com
remote.signalUrl = wss://remote.aicountly.com/signal
```

On cPanel the account name prefixes both the database and the user, so a
database created as `remote` becomes `<cpaneluser>_remote`. Record the full
prefixed names in Console (below) and grant the user ALL PRIVILEGES.

**The database name and username are not set in `.env`.** Console > SaaS Database Details
records them per product and environment, and the API asks Console for them
(`GET $CONSOLE_API_URL/database-details/resolve`, the key as a bearer token) whenever it builds
its database config, which every request, `php spark` command and worker does. This is the same
split Connect uses: Console holds no password, host or port, so
`database.default.password`, `.hostname` and `.port` stay in this file, and any other field in
Console's answer is ignored. The password must belong to the (prefixed) user Console names for
this row.

**The variables must be named exactly `CONSOLE_API_URL` and `CONSOLE_DB_DETAILS_KEY`.** The key is
the per-row key Console shows once under *Generate key* (it starts with `sdb_`); Console shows
it only once, so *Rotate key* gives a new one if it was not saved, and rotating kills the old
one. `CONSOLE_SERVICE_KEY` is a different credential and Console rejects it here. If either variable
is missing, misspelled, commented out or empty, Console is simply never asked: the API quietly uses
`database.default.database` / `.username`, and **commenting those out then leaves no database at
all**. A key for the other environment (production vs sandbox) is refused: the environment is read
from the host of `app.baseURL` (a `*.gh.aicountly.com` or `gh-*.aicountly.com` host is the sandbox),
or from `REMOTE_ENVIRONMENT = production|sandbox` when you set it. `database.default.database` /
`.username` are a local-development fallback only: they are not read while both Console variables
are set.

To see where the connection really comes from, and whether the database accepts it, run on the
server (from `api/`): `php spark remote:db-check`. It asks Console right now (cache bypassed), prints
what Console answered, connects, and checks that every migration is applied; it never prints
the key or the password, and ends with a `Reason:` and what to do when something is wrong.
`/api/health` reports the same: `databaseSource` is `console` when Console supplies the name and
username and `env` when `database.default.*` do (on a deployed server it should say `console`),
and a failure to obtain them is reported by its own `databaseReason` with a `databaseHint`:

| `databaseReason` | What it means | Fix |
| --- | --- | --- |
| `console_key_missing` | `CONSOLE_API_URL` is set but `CONSOLE_DB_DETAILS_KEY` is not (and no database is named locally), so Console is never asked | put the `sdb_` key generated in Console > SaaS Database Details in `CONSOLE_DB_DETAILS_KEY` |
| `console_url_missing` | `CONSOLE_DB_DETAILS_KEY` is set but `CONSOLE_API_URL` is not | `CONSOLE_API_URL = https://console.aicountly.org/api` |
| `console_config` | the URL or the key is malformed | https URL; a key with no spaces or line breaks |
| `console_key_rejected` | Console answered 401: the key is revoked, rotated or wrong | generate a key on this deployment's row in Console |
| `console_row_inactive` | Console answered 403: the row is inactive | activate it in Console > SaaS Database Details |
| `console_unreachable` | this server could not reach Console (and no earlier answer is cached) | check `CONSOLE_API_URL` and outbound HTTPS |
| `console_environment_mismatch` | the key belongs to the other environment | use the key from this deployment's own row |
| `console_no_database_recorded` | Console has no database name and username for this row | record them in Console |

---

## First deploy

1. **Create the subdomain** in cPanel and note its document root.
2. **Add the five SSH secrets** for that environment:
   `PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_PRIVATE_KEY`,
   `PROD_SSH_REMOTE_ROOT` — and the same five with a `SANDBOX_` prefix.
   Because the deploys run with `--delete`, a `*_SSH_REMOTE_ROOT` that would
   resolve to the home directory itself, a system directory, or anything
   containing `..` is refused by the workflow.
3. **Create the PostgreSQL database and user**, and grant ALL PRIVILEGES.
4. **Run the deploy workflow.** The API is deployed but unconfigured.
5. **Create `api/.env`** on the server, from `backend/.env.example`.
6. **Run the migrations**, over SSH:
   ```bash
   cd ~/public_html/api
   php spark remote:db-check     # is the database reached, and from where?
   php spark migrate
   php spark db:seed RemotePlatformDefaultsSeeder
   ```
7. **Confirm:**
   ```bash
   curl -s https://remote.aicountly.com/api/health | jq
   # {"status":"ok","database":"ok","signalling":"configured", ...}
   ```
   `"database":"unavailable"` means the credentials are wrong;
   `"signalling":"unconfigured"` means `remote.signallingSecret` is empty and no
   session will connect.
8. **Deploy the signalling service** (below).
9. **Open the site** and sign in. See
   [auth/AICOUNTLY_AUTH_WORKFLOW.md](auth/AICOUNTLY_AUTH_WORKFLOW.md) for what a
   healthy login looks like.

---

## The signalling service

A long-running Node process — the one part of Remote that cannot be a PHP
request. On cPanel, **Setup Node.js App** runs it under Passenger; elsewhere,
systemd or a container.

```
Application root:    signalling
Application URL:     /signal
Startup file:        src/server.js
Node version:        22
```

Environment variables — **set these in cPanel's Setup Node.js App "Environment
variables" section, not a `.env` file.** This service has no `dotenv`
dependency (see `signalling/package.json`); a `.env` file dropped next to
`src/server.js` is never read on cPanel and silently does nothing:

```
REMOTE_SIGNALLING_TOKEN_SECRET   the same value as remote.signallingSecret
REMOTE_SIGNAL_ALLOWED_ORIGINS    https://remote.aicountly.com
```

Leave `REMOTE_SIGNAL_HOST` and `REMOTE_SIGNAL_PORT` unset on cPanel. Passenger
assigns the app a port through its own `PORT` variable and expects the app to
listen on exactly that; `server.js` checks `PORT` before `REMOTE_SIGNAL_PORT`
for this reason. Setting `REMOTE_SIGNAL_PORT` yourself is for a deployment
where this process owns its port outright — systemd, a container, a bare
`node src/server.js` — never Passenger.

The secret must be **byte-identical** to the API's. If it differs, every token
is rejected and no session connects — the signalling service logs a refused
upgrade for each attempt, which is the fastest way to diagnose it.

On cPanel, Passenger's own reverse proxy handles the WebSocket upgrade once
the Application URL is set to `/signal` — no Apache config to add by hand.
The Apache snippet below is only for a deployment where you are running
Apache yourself, in front of a systemd-managed process:

```apache
RewriteEngine On
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteRule ^/?signal/?(.*) ws://127.0.0.1:8787/signal/$1 [P,L]
ProxyPass        /signal http://127.0.0.1:8787/signal
ProxyPassReverse /signal http://127.0.0.1:8787/signal
```

Check it is up:

```bash
curl -s https://remote.aicountly.com/signal/health | jq
# {"status":"ok","service":"aicountly-remote-signalling","rooms":0, ...}
```

### systemd, where cPanel is not in the way

```ini
[Unit]
Description=AICOUNTLY Remote signalling
After=network.target

[Service]
Type=simple
User=aicountly
WorkingDirectory=/srv/aicountly-remote/signalling
EnvironmentFile=/srv/aicountly-remote/signalling/.env
ExecStart=/usr/bin/node src/server.js
Restart=always
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true

[Install]
WantedBy=multi-user.target
```

The service holds no state worth preserving — rooms exist only while people are
connected — so a restart is safe. Clients reconnect with exponential backoff
and jitter, so a restart does not produce a thundering herd.

---

## TURN

STUN alone covers most networks. TURN is what makes the strict ones work, and
without it two peers behind symmetric NAT simply cannot connect.

See [ARCHITECTURE.md](ARCHITECTURE.md) for a working coturn configuration and
the ephemeral-credential arrangement, which is the one to prefer.

When no TURN is configured, `GET /health` reports `"relay":"unconfigured"` and
the UI explains an unreachable peer honestly rather than retrying forever.

---

## Upgrading

1. Merge to the deployment branch.
2. Run the workflow. It reinstalls Composer dependencies, rebuilds the web app
   and rsyncs both.
3. **Run new migrations by hand** over SSH — they are deliberately not part of
   the deploy:
   ```bash
   cd ~/public_html/api && php spark migrate
   ```
   An automated migration in a `--delete` deploy is a schema change nobody
   reviewed, running against production, with no way to stop it half-way.
4. Re-check `/api/health`.

Rolling back the code is re-running the workflow from an earlier commit.
Rolling back a schema change is `php spark migrate:rollback`, which is tested in
CI — but check first whether the newer code can still read the older schema.
