# AICOUNTLY auth workflow (Remote)

How Remote signs a user in. This is the shared AICOUNTLY SaaS flow — the same
one Smart Books and the other products use. The canonical implementation lives
on **my.aicountly.com**; Remote mints, signs and stores no credential of its
own for an AICOUNTLY user.

Two Remote-specific credentials exist alongside it, and neither is an identity:
a **guest token**, bound to one participant in one session, for an external
guest who has no AICOUNTLY account; and a **signalling token**, valid for two
minutes and for one room. See [../SECURITY.md](../SECURITY.md).

## Tokens

| Token | Lifetime | Storage | Use |
|-------|----------|---------|-----|
| `auth_token` | Long-lived | `localStorage` (this origin only) | Mint / refresh a `ses_key` |
| `ses_key` | ~15 minutes | **Memory only** | `Authorization: Bearer` on product APIs |

`ses_key` must **never** be written to `localStorage` or `sessionStorage`. In
this app it lives in a module variable in `web/src/auth/tokens.ts` and dies with
the page.

The shared, JavaScript-readable `.aicountly.com` `auth_token` cookie is
**retired**: any script on any `*.aicountly.com` page could read it. It is never
written or read any more. Cross-product sign-in is the portal hand-off —
`my.aicountly.com/login/authentication_jump/<product_key>`, backed by the
portal's own httpOnly `AIC_AUTH_TOKEN` cookie — so a user arriving from another
AICOUNTLY product still signs in without retyping anything. A leftover cookie
from an older release is purged (on `*.aicountly.com` only) at start-up and on
sign-out.

## Login flow

1. User opens `remote.aicountly.com` (or `remote.gh.aicountly.com`).
2. No `auth_token` → remember the address the tab is on (path + query + hash,
   per tab in `sessionStorage` `remote:returnRoute`; not the bare root, never an
   `/auth/*` path), issue a single-use nonce, and redirect to
   `{portal}/login/authentication_jump/remote?returnUrl=…&state=…&response_type=code`,
   the `returnUrl` being `{origin}/auth/callback?cb_state=…`.
   The portal reuses an existing portal web session — this is what makes moving
   between AICOUNTLY products seamless. With no session it shows its login form.
3. Portal redirects back to `/auth/callback?cb_state=…&state=…&sso_code=…` — a
   one-time code, never the long-lived token (see `web/src/auth/callbackState.ts`).
   The SPA history fallback in `web/public/.htaccess` serves the app at that
   path. `AuthProvider` accepts the answer only when the nonce comes back with
   it, and replaces the URL with the remembered address (or `/`) before anything
   renders; the router's callback route follows the browser there.
4. App redeems the code at `POST /api/sso/exchange` for the `auth_token`, then
   `POST /api/global/seskey` with `Bearer auth_token` → `ses_key`; only then is
   the `auth_token` stored.
5. The remembered screen, else the dashboard.

Logout clears both tokens (and purges any leftover legacy `auth_token`
cookie), tells the portal to invalidate the `auth_token`, and navigates to
`{portal}/login/logout` so the portal's own session cookie goes too. Skipping
that last step leaves the portal session alive and the next visit signs the
user straight back in.

## Host mapping

| Remote host | Login redirect | Auth API | Product API |
|---|---|---|---|
| `remote.aicountly.com` | `my.aicountly.com` | `my.aicountly.com` | `remote.aicountly.com/api` |
| `remote.gh.aicountly.com` | `sandbox.aicountly.com` | `my.aicountly.com` | `remote.gh.aicountly.com/api` |

**`sandbox.aicountly.com` is for the login redirect only.** `seskey`,
`seskey/refresh` and `validatesession` always answer on `my.aicountly.com`, in
sandbox as well as production. Pointing a sandbox build at
`sandbox.aicountly.com` for those calls is the usual way to break sandbox
sign-in.

One build serves both environments: `resolveProductKeyFromHost()` reads
`remote` out of either hostname, and `isSandboxHost()` picks the portal.

## Guests do not go through the portal

An external guest holds a one-time invitation, not an AICOUNTLY account, so
bouncing them to the login form would make guest access impossible. `AuthProvider`
recognises `/join/:token` and `/room/:uuid` and settles as `guest` instead of
redirecting; the API authenticates them by the token their redeemed invitation
returned.

## Why the calls go through this product's own API

The browser calls `/api/global/seskey` on its **own origin**, and the API
relays that to `my.aicountly.com` server-to-server.

A brand-new product domain is not in the portal's CORS allowlist on day one, so
a direct browser call would fail with nothing but a CORS message to show for it.
The relay sidesteps that entirely. The app still falls back to calling the
portal directly if the relay is missing — useful before the API is deployed.

The relay is an **allowlist** (`RELAYED_PATHS` in
`backend/app/Controllers/PortalRelayController.php`): `seskey`,
`seskey/refresh`, `refresh_authtoken`, `auth/exchange`, `sso/exchange` and
`logout`. Sign-out goes this way too because production hosting answers a
browser it distrusts with a challenge page that carries no CORS headers, so a
direct `POST my.aicountly.com/api/logout` could fail and leave the token live.
Forwarding arbitrary paths would turn
this host into an open proxy for the portal's whole auth surface, with the
portal seeing this server's IP instead of the caller's. It is rate limited at
30 requests a minute per IP.

## When a firewall answers instead of the API

Both hosts sit behind a WAF (Imunify360 WebShield). It answers a request it
cannot vouch for with an HTML “checking your browser” page — carrying
**HTTP 200**, because as far as it is concerned the page was served. To a
browser that is a redirect it will follow; to `fetch` it is a successful
response whose body will not parse.

This broke sign-in in production: `POST /api/global/sso/exchange` was answered
with that page, `res.ok` was true, there was no `auth_token` in it, and the app
reported “the sign-in service is temporarily unavailable” — every time, with a
Try again that started a fresh portal round trip and was intercepted
identically. See [SIGN_IN_WAF_INTERCEPTION.md](SIGN_IN_WAF_INTERCEPTION.md).

Three rules keep it from coming back. They are cheap, and each one is load
bearing:

1. **Every auth call says `Accept: application/json`.** A WAF serves its HTML
   challenge only to a client that says it renders HTML, and a `fetch` with no
   `Accept` of its own sends the catch-all, which does. This is why every
   `apiFetch` call — which has always sent this header — sailed through while
   the auth calls did not. `X-Requested-With: XMLHttpRequest` goes with it.
2. **The same-origin relay hop gets the browser's cookies**
   (`credentials: 'same-origin'`, the default). The WAF's own clearance cookie,
   earned when this page was loaded, has to ride along with the request it is
   gating. `credentials: 'omit'` — right for the cross-origin call to the
   portal, which authenticates by Bearer token and must never see this site's
   cookies — made every exchange look like a brand-new unvouched-for client.
   `fetchPortalAuth` therefore sets credentials per hop; call sites do not.
3. **An HTML answer is never read as an answer.** `isChallengePage()` in the
   browser and `isNotJson()` in `PortalRelayController` both treat a non-JSON
   body as *the request never arrived*: the browser falls through to the portal
   directly, the relay answers `503 auth_unavailable` with `Retry-After`, and
   neither pretends a one-time code was spent. `PortalClient` sends an `Accept`
   and a `User-Agent` of its own for the same reason — curl sends neither
   unless told to, and a request with neither is what a WAF challenges first.

A server handed a JavaScript challenge can never pass it, so the standing ask of
the host is that these domains are not challenged at all — Imunify360 whitelists
by domain, not by path. The runbook has the commands.

## This product's backend

- Does **not** issue `auth_token` or `ses_key`.
- Does **not** implement `/api/seskey`, `/api/seskey/refresh` or `/api/logout` —
  it only relays the first two.
- Validates a caller by `POST https://my.aicountly.com/api/validatesession` with
  the Bearer `ses_key`; `status: 1` means the session is live. A transport
  failure counts as *not* authenticated, so a portal outage denies access rather
  than granting it. The answer is cached for 60 seconds against a SHA-256 of the
  key, so a page making six API calls does not make six round trips to the
  portal.
- Projects the portal's answer into `remote_identities` — a display name and a
  stable integer for foreign keys, and nothing else. It holds no password, no
  credential and no session, and is **not** an authentication store.

`GET /api/session` is kept from the previous API so the flow can still be
verified end to end with one curl. The product API lives under
`/api/v1/remote`.

## Verifying an environment

```bash
# 1. The API is up and says which environment it is
curl https://remote.gh.aicountly.com/api/health

# 2. The relay reaches the portal (401 without a token is the correct answer —
#    a 404 means the API is not deployed, a 504 means it cannot reach the portal)
curl -i -X POST https://remote.gh.aicountly.com/api/global/seskey

# 3. Unrelayed paths are refused
curl -i -X POST https://remote.gh.aicountly.com/api/global/login   # expect 404
```

In the browser: open the site, expect a jump to the portal, sign in, expect to
land back on the dashboard. Then press **Log out** and confirm that reopening
the site does **not** sign you straight back in.
