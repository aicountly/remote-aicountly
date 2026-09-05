# AICOUNTLY Remote

Secure remote assistance for the AICOUNTLY platform. Someone shares a screen;
someone else, with permission, watches it — and on a Windows machine running
the desktop agent, controls it.

| Environment | App | API |
| --- | --- | --- |
| Production | https://remote.aicountly.com | https://remote.aicountly.com/api |
| Sandbox | https://remote.gh.aicountly.com | https://remote.gh.aicountly.com/api |

## What it does

- **Share a screen** — AICOUNTLY Safe Share, a browser tab, an application
  window, or an entire screen where the organisation permits it
- **View a shared screen**, after the host admits you
- **Chat, live pointer and annotation** during a session
- **Send a file to the other person**, browser to browser — the recipient has to
  accept, and the bytes never reach AICOUNTLY
- **Session codes and one-time invitation links**, including external guests
  where policy allows
- **AICOUNTLY Support requests** carrying the product, area and ticket the
  customer was working in
- **Company policy, role and user permissions**, resolved server-side
- **Session history and an audit trail** — without ever storing screen content

On a Windows machine with **AICOUNTLY Remote for Windows** installed:

- **Remote control**, after the person at the machine agrees — visible while it
  is running, and stoppable from the machine instantly, without the network
- **Registered computers** with their own cryptographic identity, revocable
  server-side
- **Unattended access** as its own deliberate, audited, revocable setting —
  never a remembered approval
- **Restarting a machine**, separately authorised and recorded before it happens

## What it deliberately does not do

**A browser still cannot be controlled.** The Screen Capture API cannot do it,
so a browser participant reports `remote_control: false` and the session screen
offers nothing — no "Control computer" button exists to be disappointed by. The
control UI appears when a participant's *negotiated capabilities* say a machine
can be controlled and the organisation's policy allows it, never because of
what kind of client something is.

**A UAC prompt cannot be answered remotely.** It runs on the Windows Secure
Desktop, which a user-session process cannot capture or inject into. The agent
notices and says so; it does not disable UAC, and it does not ask you to. See
[docs/desktop/WINDOWS_AGENT.md](docs/desktop/WINDOWS_AGENT.md).

**The Windows agent does not yet send a picture.** Device identity, policy,
consent, control, unattended access and the whole session model are built and
tested; the video encoder and the agent's signalling client are not. See
[docs/desktop/ARCHITECTURE.md](docs/desktop/ARCHITECTURE.md#what-is-built-and-what-is-not).

**macOS and Linux agents are not built.** Every provider returns
`Unsupported` rather than pretending.

## Layout

```
web/          React 19 + TypeScript + Vite. Builds to web/dist, deployed to the document root.
backend/      CodeIgniter 4 API + PostgreSQL. Deployed to the api/ folder inside it.
signalling/   Node WebSocket relay for WebRTC signalling. A long-running process.
desktop/      AICOUNTLY Remote for Windows: React + Tauri 2 + Rust, and the Windows service.
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

# desktop agent — needs Rust 1.82+ and Node 22+. Builds and tests on any host;
# the Windows-only half needs Windows (or a mingw cross-check — see
# docs/desktop/TESTING.md).
cd desktop && npm install
cargo test --workspace
npm run tauri dev
```

Signing in is the AICOUNTLY portal's job, the same as every other AICOUNTLY
SaaS — Remote issues no credentials of its own for an AICOUNTLY user, so there
is no local login to fake. See
[docs/auth/AICOUNTLY_AUTH_WORKFLOW.md](docs/auth/AICOUNTLY_AUTH_WORKFLOW.md).

## Tests

```bash
cd backend    && vendor/bin/phpunit      # 208 — policy, tenant isolation, devices, control
cd web        && npm test                # 104 — capture, capability gating, control input
cd signalling && npm test                #  24 — tokens, rooms, device rooms, live relay
cd desktop    && cargo test --workspace  # 235 — protocol, gate, identity, state, service
cd desktop    && npm test                #  12 — the agent's own interface
```

The backend suite runs against a real PostgreSQL and applies the migrations
itself. `.github/workflows/ci.yml` runs the first three on every push and
verifies that the migrations roll back cleanly;
`.github/workflows/desktop-ci.yml` runs the desktop workspace on Linux **and**
on a Windows runner, with `cargo audit` and `npm audit`.

What is **not** covered — no Windows-only code path has been executed on a
Windows machine — is set out in
[docs/desktop/TESTING.md](docs/desktop/TESTING.md), and the manual pass that
has to precede a release is `desktop/tests/MANUAL.md`.

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
| Release AICOUNTLY Remote for Windows | the desktop installers | a GitHub release — never cPanel |
| CI / Desktop CI | nothing — tests only | — |

The Windows release takes a version and a channel (`sandbox`, `beta`,
`production`), signs beta and production inside a protected GitHub Environment,
and has no branch by which an unsigned artifact becomes a release. See
[docs/desktop/WINDOWS_RELEASE.md](docs/desktop/WINDOWS_RELEASE.md).

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
| [DESKTOP_AGENT.md](docs/DESKTOP_AGENT.md) | How the desktop agent plugs into this |
| [desktop/ARCHITECTURE.md](docs/desktop/ARCHITECTURE.md) | The agent's design, and what is not built |
| [desktop/SECURITY.md](docs/desktop/SECURITY.md) | What has to be true before a keystroke lands |
| [desktop/DEVICE_ENROLMENT.md](docs/desktop/DEVICE_ENROLMENT.md) | Registering a computer, and un-registering it |
| [desktop/WINDOWS_AGENT.md](docs/desktop/WINDOWS_AGENT.md) | Windows specifics, and what Windows forbids |
| [desktop/WINDOWS_RELEASE.md](docs/desktop/WINDOWS_RELEASE.md) | Building, signing and releasing the agent |
| [desktop/TESTING.md](docs/desktop/TESTING.md) | Coverage, and its gaps |
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
