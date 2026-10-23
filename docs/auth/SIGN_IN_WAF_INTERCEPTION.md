# Sign-in fails with "temporarily unavailable" — the firewall is answering

**Symptom.** The user signs in at `my.aicountly.com`, comes back to
`remote.aicountly.com`, and the app shows:

> The AICOUNTLY sign-in service is temporarily unavailable. You are still signed
> in — try again in a moment.

**Try again** starts a fresh portal round trip and lands on the same screen. It
never completes.

## What is actually happening

`POST https://remote.aicountly.com/api/global/sso/exchange` answers **200 OK**
with `Content-Type: text/html` and this body:

```html
<title>One moment, please...</title>
... Please wait while your request is being verified ...
<form action="/z0f76a1d14fd21a8fb5fd0d03e0fdc3d3cedae52f"> ... wsidchk ... </form>
```

That is **Imunify360 WebShield**'s JavaScript challenge. The path
`/z0f76a1d14fd21a8fb5fd0d03e0fdc3d3cedae52f` and the `wsidchk` field are its
signature. The request was stopped at the edge and never reached PHP — nothing
is in the API's logs, because nothing arrived.

Because the challenge carries **200**, the browser's `fetch` saw a success:
`res.ok` was `true`, `res.json()` threw, no `auth_token` came back, and the app
reported an outage. A `fetch` cannot execute the challenge's JavaScript, so this
could never resolve itself.

### Why only this one request

Every request the app makes is same-origin and goes to the same host. One of
them was different:

| Request | `Accept` | Cookies | Outcome |
|---|---|---|---|
| Page load (`GET /`) | `text/html,…` | yes | challenged once, passed, cookie set |
| `apiFetch` (`/api/v1/remote/*`) | `application/json` | yes (default) | never challenged |
| `/api/global/seskey`, `/logout` | *none* → `*/*` | yes (default) | at risk |
| **`/api/global/sso/exchange`** | *none* → `*/*` | **no** (`credentials: 'omit'`) | **challenged every time** |

WebShield serves its HTML page to a client that says it renders HTML (`*/*`
matches) and that it has no clearance cookie for. The exchange call was the only
request in the app that hit both conditions — it sent no `Accept` and
`credentials: 'omit'` stripped the clearance cookie the page load had already
earned.

The same shape existed server-to-server: `PortalClient` used curl with no
`Accept` (curl's default is `*/*`) and no `User-Agent` at all, which is what a
WAF in front of `my.aicountly.com` challenges first.

## The fix in the code (shipped)

`web/src/auth/portal.ts`

- `AUTH_REQUEST_HEADERS` — every portal auth call sends
  `Accept: application/json` and `X-Requested-With: XMLHttpRequest`.
- `fetchPortalAuth` sets credentials **per hop**: `same-origin` for the relay
  (the clearance cookie rides along), `omit` for the direct call to the portal
  (another origin; it authenticates by Bearer token and must never see this
  site's cookies). Call sites no longer set `credentials` at all.
- `isChallengePage()` — an HTML body is never read as the API's answer. From the
  relay it falls through to the portal directly; from both, it raises a
  **retryable** `AuthError` (503), so the session is kept and the one-time
  `sso_code` is treated as unspent.
- `performLogout()` sends the same headers and cookies, so the portal token is
  actually revoked instead of the revoke call being challenged.

`backend/app/Domain/Auth/PortalClient.php`

- Sends `Accept: application/json` (unless the caller set one) and a real
  `User-Agent`.

`backend/app/Controllers/PortalRelayController.php`

- `isNotJson()` — a non-JSON answer from the portal is never passed through as
  the portal's own. It becomes `503 {"error":"auth_unavailable","retryable":true}`
  with `Retry-After: 5`, and is logged.

Regression tests: `web/src/auth/portalChallenge.test.ts`, and
`testTheRelayDoesNotPassAnInterceptorsPageOffAsThePortalsAnswer` /
`testThePortalIsAskedForJsonByACallerThatSaysWhoItIs` in
`backend/tests/Feature/AuthOutageApiTest.php`.

## The fix on the server (do this too)

The code above stops the challenge being *served* and stops it being *mistaken
for an answer*. It does not stop WebShield deciding to challenge `/api/` for
some other reason — a flagged IP range, a rule update. An API path should never
be challenged at all, because no API client can pass a JavaScript check.

### Verify the diagnosis first

In cPanel → **Terminal** (or over SSH), as the site's user:

```bash
# 1. Does the API answer JSON? Expect a JSON body, not HTML.
curl -sS -i https://remote.aicountly.com/api/health | head -20

# 2. Reproduce the failure exactly: the old headers (no Accept, no cookies).
curl -sS -i -X POST https://remote.aicountly.com/api/global/sso/exchange \
  -H 'Content-Type: application/json' \
  -H 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36' \
  --data '{"sso_code":"0000000000000000000000000000000000000000000000000000000000000000","redirect_uri":"https://remote.aicountly.com/auth/callback?cb_state=test","product":"remote"}' \
  | head -30
# A "One moment, please..." / wsidchk page => WebShield is intercepting.
# 401 invalid_grant => the request reached PHP (this code is not real).

# 3. The same call with the headers the fix sends. This should now reach PHP.
curl -sS -i -X POST https://remote.aicountly.com/api/global/sso/exchange \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -H 'X-Requested-With: XMLHttpRequest' \
  --data '{"sso_code":"0000000000000000000000000000000000000000000000000000000000000000","redirect_uri":"https://remote.aicountly.com/auth/callback?cb_state=test","product":"remote"}' \
  | head -30
```

```bash
# Confirm nothing reached the application during a failed sign-in: an
# intercepted request leaves no trace here at all. The API lives in
# <document root>/api (docs/DEPLOYMENT.md); public_html below is the
# production document root.
tail -n 100 ~/public_html/api/writable/logs/log-$(date +%Y-%m-%d).log

# The Apache access log, which does record the intercepted request. A site
# user normally reads it under ~/logs; the first path is root's copy.
tail -n 100 /etc/apache2/logs/domlogs/remote.aicountly.com 2>/dev/null \
  || tail -n 100 ~/logs/remote.aicountly.com.http.access.log
```

### Stop the challenge being served to this domain

WebShield settings are root-level, so this is the server administrator's to do
(or your host's, on a managed box). **Imunify360 has no path-level exclusion** —
you cannot say “skip the challenge for `/api/*`”. The lever that exists is the
domain whitelist, which skips the CAPTCHA and splash screen for that domain:

```bash
# As root. Skips the CAPTCHA / splash screen for these hosts.
imunify360-agent whitelist domain add remote.aicountly.com
imunify360-agent whitelist domain add my.aicountly.com
imunify360-agent whitelist domain list

# What WebShield is set to, and what it decided for a given request.
imunify360-agent config show | grep -A20 WEBSHIELD
grep -i 'remote.aicountly.com' /var/log/imunify360/console.log | tail -50
tail -n 200 /var/log/imunify360-webshield/*.log
```

Ask for `my.aicountly.com` as well as `remote.aicountly.com`. The note above
about sign-out being relayed exists because that host challenges browsers it
distrusts, and the relay's server-to-server calls are challengeable in exactly
the same way — a server can never pass a JavaScript check.

Blunter options, if the whitelist is refused or is not enough:

```bash
# Server-wide: turns the splash screen off for all traffic on this machine.
imunify360-agent config update '{"WEBSHIELD": {"splash_screen": false}}'

# Related options live here (e.g. disable_splash_for_cloudflare).
grep -n 'splash\|webshield' /etc/imunify360-wafd/wafd.conf
```

If the host runs a different WAF, ask for the same thing in its terms:
**`/api/*` on `remote.aicountly.com` and `my.aicountly.com` must bypass any
JavaScript, CAPTCHA or splash challenge.** Those paths answer JSON to programs,
and a program cannot run a browser check. Where only an IP whitelist is on
offer, whitelisting the two servers' own addresses covers the relay's
server-to-server calls but not the browser's, so the domain whitelist is still
what the browser hop needs.

### After the change

```bash
# Expect JSON (401 invalid_grant for a made-up code), never HTML.
curl -sS -o /dev/null -w '%{http_code} %{content_type}\n' \
  -X POST https://remote.aicountly.com/api/global/sso/exchange \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  --data '{"sso_code":"0000000000000000000000000000000000000000000000000000000000000000","redirect_uri":"https://remote.aicountly.com/auth/callback?cb_state=test","product":"remote"}'
```

Then in a browser: sign out fully, open `https://remote.aicountly.com`, sign in,
and confirm the dashboard loads. In DevTools → Network, `sso/exchange` must be
`200 application/json` carrying an `auth_token` — not `text/html`.

## If it comes back

Check `Content-Type` first. `text/html` on anything under `/api/` means
something in front of PHP answered, and the application logs will be empty for
that request. The app will now say so plainly rather than reporting a parse
failure, and `writable/logs/` will carry
`portal relay: … was answered by an interceptor, not the portal`.
