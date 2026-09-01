# AICOUNTLY Remote

Secure browser assistance for the AICOUNTLY platform. Someone shares a screen;
someone else, with permission, watches it and helps.

| Environment | App | API |
| --- | --- | --- |
| Production | https://remote.aicountly.com | https://remote.aicountly.com/api |
| Sandbox | https://remote.gh.aicountly.com | https://remote.gh.aicountly.com/api |

## What it does

- **Share a screen** — AICOUNTLY Safe Share, a browser tab, an application
  window, or an entire screen where the organisation permits it
- **View a shared screen**, after the host admits you
- **Chat, live pointer and annotation** during a session
- **Session codes and one-time invitation links**, including external guests
  where policy allows
- **AICOUNTLY Support requests** carrying the product, area and ticket the
  customer was working in
- **Company policy, role and user permissions**, resolved server-side
- **Session history and an audit trail** — without ever storing screen content

## What it deliberately does not do

Browser V1 is **attended assistance**. It shares and views; it does not control
a computer and it has no unattended access. A browser cannot do either, and
nothing in the interface implies otherwise — no "Control computer" button
exists to be disappointed by.

The desktop agents that will do those things are designed for
([docs/DESKTOP_AGENT.md](docs/DESKTOP_AGENT.md)) and plug into this same
session, policy and audit model. They are not built.

## Layout

```
web/          React 19 + TypeScript + Vite. Builds to web/dist, deployed to the document root.
backend/      CodeIgniter 4 API + PostgreSQL. Deployed to the api/ folder inside it.
signalling/   Node WebSocket relay for WebRTC signalling. A long-running process.
docs/         architecture, security, deployment, integration
```

Media never touches AICOUNTLY infrastructure: video and audio go directly
between the two browsers, or through TURN when a network forces it. The
signalling service carries only the handshake, holds no database, and decides
nothing — authorisation happens in the API, which mints it a two-minute signed
token naming exactly one room.

## Getting started

Full instructions, including the two-browser end-to-end walkthrough, are in
[docs/DEVELOPMENT.md](docs/DEVELOPMENT.md).

```bash
# backend — needs PHP 8.2+ with pgsql, and PostgreSQL 14+
cd backend && composer install && cp .env.example .env
#   edit .env: database credentials, remote.signallingSecret, remote.contextSecret
php spark migrate
php spark db:seed RemotePlatformDefaultsSeeder
php spark db:seed RemoteDevelopmentSeeder     # two companies with opposite policies
php spark serve --port 8080

# signalling — needs Node 22+
cd signalling && npm install && cp .env.example .env
#   REMOTE_SIGNALLING_TOKEN_SECRET must match remote.signallingSecret exactly
npm run dev

# web
cd web && npm install && cp ../.env.example ../.env
npm run dev                                   # http://localhost:5173
```

Signing in is the AICOUNTLY portal's job, the same as every other AICOUNTLY
SaaS — Remote issues no credentials of its own for an AICOUNTLY user, so there
is no local login to fake. See
[docs/auth/AICOUNTLY_AUTH_WORKFLOW.md](docs/auth/AICOUNTLY_AUTH_WORKFLOW.md).

## Tests

```bash
cd backend    && vendor/bin/phpunit      # 106 — policy, tenant isolation, HTTP
cd web        && npm test                # 27  — capture, capability, UI gating
cd signalling && npm test                # 16  — tokens, rooms, live relay
```

The backend suite runs against a real PostgreSQL and applies the migrations
itself. `.github/workflows/ci.yml` runs all three on every push, and verifies
that the migrations roll back cleanly.

## Environment variables

Two `.env` files, working in opposite ways.

| File | Read | Used by |
| --- | --- | --- |
| `.env.example` | **build time**, inlined into the bundle | `web/` |
| `backend/.env.example` | **runtime**, on every request | `backend/` |
| `signalling/.env.example` | process start | `signalling/` |

Only `VITE_`-prefixed variables reach the browser bundle, and Vite inlines them
at build time, so **treat every one of them as public**. Never put a secret in
one. The signalling URL and the ICE servers are deliberately not build-time
values: the browser receives them from the API, per session, so a TURN
credential never ends up in a JavaScript bundle.

## Deployment

Manual only — **Actions → pick a workflow → Run workflow**. Nothing deploys on
push or merge. See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

| Workflow | Deploys | To |
| --- | --- | --- |
| Deploy to cPanel Production | `web/dist/` then `backend/` | document root, then `api/` inside it |
| Deploy to cPanel Sandbox | `web/dist/` then `backend/` | document root, then `api/` inside it |
| CI | nothing — tests only | — |

Migrations are run by hand over SSH, deliberately: an automated migration
inside a `--delete` deploy is a schema change nobody reviewed, running against
production, with no way to stop it half-way.

## Brand assets

The AICOUNTLY logo is **not** in this repository and none was invented for it.
Drop the real asset at `web/public/brand/aicountly-logo.svg` and it appears
everywhere; until then the header shows the plain wordmark. See
[docs/BRANDING.md](docs/BRANDING.md).

## Documentation

| Document | Covers |
| --- | --- |
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | How the four pieces fit together |
| [DATABASE.md](docs/DATABASE.md) | Every table, and why it is shaped that way |
| [SECURITY.md](docs/SECURITY.md) | The security model, and its honest limits |
| [DEVELOPMENT.md](docs/DEVELOPMENT.md) | Running it, and the two-browser walkthrough |
| [DEPLOYMENT.md](docs/DEPLOYMENT.md) | cPanel deployment and first-run setup |
| [SAFE_SHARE_INTEGRATION.md](docs/SAFE_SHARE_INTEGRATION.md) | Launching Remote from another AICOUNTLY product |
| [DESKTOP_AGENT.md](docs/DESKTOP_AGENT.md) | How the future agents plug into this |
| [BROWSER_SUPPORT.md](docs/BROWSER_SUPPORT.md) | What works where, and what degrades |
| [BRANDING.md](docs/BRANDING.md) | Logo, colour, typography, icons |
| [auth/AICOUNTLY_AUTH_WORKFLOW.md](docs/auth/AICOUNTLY_AUTH_WORKFLOW.md) | Sign-in |

## A note on the CodeIgniter version

The specification named CodeIgniter **4.6**. This builds on **4.7.4** because
the 4.6 line carries five open advisories — two critical, including SQL
injection in the query builder's `deleteBatch()` and an upload-validation bypass
— all fixed only in 4.7.4, with no patched 4.6 release. 4.7 is the same major
line with the same conventions, and `composer audit` runs in CI so this stays
visible. Everything else in the stack is as specified: React, PostgreSQL,
WebRTC, and AICOUNTLY SSO.
