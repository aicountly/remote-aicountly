# Running AICOUNTLY Remote locally

Three processes: the API, the signalling service, and the web app. All three
are needed for a live session; the first two alone are enough to work on
policy, sessions or administration.

## Prerequisites

* PHP 8.2+ with `pgsql`, `intl`, `mbstring`, `curl`
* PostgreSQL 14+
* Node.js 22+
* Composer

## One-time setup

```bash
# --- database -------------------------------------------------------------
createuser aicountly_remote --pwprompt          # password: remote_dev
createdb  aicountly_remote      --owner aicountly_remote
createdb  aicountly_remote_test --owner aicountly_remote

# --- backend --------------------------------------------------------------
cd backend
composer install
cp .env.example .env
```

Then edit `backend/.env` for local development:

```dotenv
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost:8080/'
app.forceGlobalSecureRequests = false

database.default.hostname = 127.0.0.1
database.default.database = aicountly_remote
database.default.username = aicountly_remote
database.default.password = remote_dev
database.default.DBDriver = Postgre
database.default.port = 5432

database.tests.hostname = 127.0.0.1
database.tests.database = aicountly_remote_test
database.tests.username = aicountly_remote
database.tests.password = remote_dev
database.tests.DBDriver = Postgre
database.tests.port = 5432

# Development-only values. Anything real belongs in a server .env.
remote.contextSecret = dev-context-secret-not-for-production
remote.signallingSecret = dev-signalling-secret-not-for-production
remote.appUrl = http://localhost:5173
remote.signalUrl = ws://localhost:8787
remote.corsAllowedOrigins = http://localhost:5173
```

`remote.signallingSecret` must match `REMOTE_SIGNALLING_TOKEN_SECRET` in the
signalling service, or every token is rejected and no session connects.

```bash
php spark migrate
php spark db:seed RemotePlatformDefaultsSeeder
php spark db:seed RemoteDevelopmentSeeder     # two companies, three people

# --- signalling -----------------------------------------------------------
cd ../signalling
npm install
cp .env.example .env        # set REMOTE_SIGNALLING_TOKEN_SECRET to match

# --- web ------------------------------------------------------------------
cd ../web
npm install
cp ../.env.example ../.env
```

## Running

Three terminals:

```bash
cd backend    && php spark serve --port 8080
cd signalling && REMOTE_SIGNALLING_TOKEN_SECRET=dev-signalling-secret-not-for-production npm run dev
cd web        && npm run dev
```

| Process | URL |
|---|---|
| Web app | http://localhost:5173 |
| API | http://localhost:8080 |
| Signalling | ws://localhost:8787 |

Point the frontend at the local API by setting `VITE_API_BASE_URL=http://localhost:8080`
in the root `.env`, and add `http://localhost:5173` to `remote.corsAllowedOrigins`
in `backend/.env` — localhost is the one case where the app and the API are not
same-origin.

Sign-in still goes through the real AICOUNTLY sandbox portal: Remote issues no
credentials of its own, so there is no local login to fake. See
[auth/AICOUNTLY_AUTH_WORKFLOW.md](auth/AICOUNTLY_AUTH_WORKFLOW.md).

### Screen capture and localhost

`getDisplayMedia` needs a secure context. `http://localhost` **is** one by
specification, so screen sharing works over plain HTTP on localhost — but not
over `http://192.168.x.x`. To test from a second machine, put a TLS proxy in
front or use a tunnel; Remote will otherwise correctly report that sharing is
unavailable.

---

## The two-browser walkthrough

This is the end-to-end test. It needs two separate browser profiles (not two
tabs — they would share a portal session), for example Chrome and Chrome
Incognito, or Chrome and Firefox.

**Sharer** — profile A, **Viewer** — profile B.

1. **Start all three processes.** Confirm the API is up:
   ```bash
   curl -s http://localhost:8080/health | jq
   # {"status":"ok", "database":"ok", "signalling":"configured", ...}
   ```
   `"signalling":"unconfigured"` means `remote.signallingSecret` is empty and no
   session will connect.

2. **Sign in** as the sharer in profile A. You land on the dashboard.

3. **Create the session.** *Share my screen* → pick Personal or an
   organisation → *Create session*. You arrive in the room, and the header shows
   the session id (`AR-10001`) and a nine-digit code.

4. **Join from the second browser.** Sign in as a different user in profile B,
   go to *Join session*, and enter the code. Expect: *"Waiting for the host to
   admit you."*

5. **Approve.** The sharer's room shows *"…would like to join this Remote
   session"* with **Decline** / **Allow**. Choose Allow.

   *Before* approving, confirm the viewer is genuinely blocked — this is the
   guarantee, not a nicety:
   ```bash
   # As the viewer, before approval — expect 403 AWAITING_APPROVAL
   curl -i -X POST http://localhost:8080/v1/remote/sessions/<uuid>/signalling-token \
        -H "Authorization: Bearer <ses_key>"
   ```

6. **Verify the peer connection.** Both rooms should show **Connected** within a
   second or two. If it stays on *Establishing secure connection…*, look at the
   signalling service's output — a refused upgrade is logged there.

7. **Share the screen.** The sharer presses **Share**. The consent dialog names
   the organisation and the session; **Continue to choose screen** opens the
   browser's own picker. Pick a tab.

8. **Check surface enforcement.** With an organisation whose policy forbids
   entire-screen sharing (the seeded *XYZ Enterprises*), choose *Entire screen*
   in the picker instead. Expect sharing to stop immediately with
   *"Entire-screen sharing is not permitted"*, and a `POLICY_REJECTED` event on
   the session timeline. The seeded *ABC Private Limited* permits it, so the
   same user gets the opposite answer there — that is §77 in the interface.

9. **Viewer sees the screen.** The stream appears in profile B, with
   *"<name> is sharing"* along the bottom.

10. **Chat.** Send a message each way. They appear immediately (data channel).
    Reload the viewer's page — the history is still there (the API copy).

11. **Pointer and annotation.** The viewer presses **Pointer** and moves over
    the video; a labelled cursor appears on the sharer's screen. **Draw**, then
    **Clear**.

12. **Stop sharing.** Press **Stop sharing**, or use the browser's own sharing
    bar. Both must leave the session open, with chat still working — stopping a
    share is not ending a session.

13. **End.** **End** on either side. Both land on the summary: duration,
    participants, screen sharing stopped.

14. **History and audit.** The session appears in *Sessions* with its duration.
    Open it: the timeline shows created → joined → approved → share started →
    surface → share stopped → ended. As a company administrator, *Audit trail*
    shows the same events with actor and IP.

15. **Confirm the code is dead.** Re-entering the session code now fails with
    *"That session code is not valid."*

### Testing an external guest

With `allow_external_guest` on for the organisation (the seeded ABC has it):
in the room's **People** panel, create an external invitation, then open the
link in a **third** profile with no AICOUNTLY session. Expect a name prompt,
then the waiting state, then the host's approval prompt. Opening the same link
a second time must fail with *"already been used"*.

---

## Tests

```bash
cd backend    && vendor/bin/phpunit      # 106 tests — policy, isolation, HTTP
cd web        && npm test                # 27 tests — capture, capability, UI gating
cd signalling && npm test                # 16 tests — tokens, rooms, live relay
```

The backend suite needs the `aicountly_remote_test` database and runs the
migrations itself. It uses a real PostgreSQL because the schema depends on
JSONB, partial unique indexes, `CHECK` constraints and `ON CONFLICT` — testing
against anything else would test a different database than production runs.

## Useful commands

```bash
cd backend
php spark routes                       # every endpoint and its filters
php spark migrate:status
php spark migrate:rollback             # the rollback is supported, and tested
php spark db:seed RemoteDevelopmentSeeder
```

## Troubleshooting

| Symptom | Cause |
|---|---|
| Every API call returns 401 | Apache is not passing `Authorization`. The API's `.htaccess` copies it; check it deployed. |
| `"signalling":"unconfigured"` | `remote.signallingSecret` is empty in `backend/.env`. |
| Signalling refuses every upgrade | The two secrets differ. They must be byte-identical. |
| Stuck on *Establishing secure connection…* | The signalling service is not running or not reachable at `remote.signalUrl`. |
| Connects locally, not across networks | No TURN. Expected — see [ARCHITECTURE.md](ARCHITECTURE.md). |
| Share button disabled | Not a secure context, or policy. *Settings* shows which. |
| `CONTEXT_INVALID` on launch | The launch token expired (5 minutes), was replayed, or the secrets differ. |
