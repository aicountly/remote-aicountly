# Integrating AICOUNTLY Remote into another AICOUNTLY product

Two things another AICOUNTLY SaaS can do:

1. **Launch Remote with signed context**, so the session already knows the
   company, branch, financial year, product and area the user was working in.
2. **Publish a capture handle**, so Remote can confirm that a shared AICOUNTLY
   tab belongs to the same organisation as the session — and notice when it
   stops doing so.

Both are optional. Remote works without either; the first makes company
sessions possible without a directory API, and the second is what makes Safe
Share *safe* rather than merely convenient.

---

## 1. Launching Remote with signed context

### Why not a query parameter

`https://remote.aicountly.com/?company_id=481` is a request, not a fact. Anyone
can type it. Remote therefore never reads company context from a query
parameter — the only thing that gives a session a company is a token it can
verify.

### The token

A compact JWS, `HS256`, signed with a secret shared between the issuing product
and Remote. Any JWT library produces one.

```json
{
  "iss": "https://my.aicountly.com",
  "aud": "aicountly-remote",
  "sub": "<the user's AICOUNTLY uuid>",
  "company_id": 481,
  "branch_id": 12,
  "financial_year_id": 2026,
  "product": "BOOKS",
  "route": "/gst/gstr2b-reconciliation",
  "support_ticket_id": null,
  "iat": 1793612400,
  "exp": 1793612520,
  "jti": "b1f4c0a9e2d34f77"
}
```

| Claim | Required | Notes |
|---|---|---|
| `iss` | yes | Must equal `remote.contextIssuer`. |
| `aud` | yes | Must equal `remote.contextAudience` (`aicountly-remote`). |
| `sub` | yes | The user's AICOUNTLY UUID. |
| `jti` | yes | Unique, ≤ 64 chars. Consumed once and never again. |
| `iat` / `exp` | yes | Keep the lifetime short — 120 seconds is plenty. |
| `product` | yes | Must be on `remote.sourceProductAllowlist`. |
| `company_id` | no | Omit for a personal session. |
| `branch_id`, `financial_year_id` | no | Carried onto the session. |
| `route` | no | The area the user was in, shown to the technician. |
| `support_ticket_id` | no | Opaque. Any ticketing system's id. |
| `source_agent`, `source_conversation_id`, `issue_summary` | no | For Pulse / Advisor escalation. |

### What Remote checks

In order, all of which must pass:

1. a signing secret is configured at all — an unconfigured deployment refuses
   context rather than accepting it unverified;
2. `alg` is exactly `HS256` (no `none`, no algorithm confusion);
3. the signature matches, compared in constant time;
4. `iss` and `aud` are the configured issuer and this product;
5. `exp` is in the future and `iat` is not, within 30 seconds of skew;
6. the token is younger than `remote.contextMaxAgeSeconds`, **whatever it
   claims** — a generous `exp` from the issuer does not extend Remote's window;
7. `product` is on the allowlist;
8. `jti` has not been used before — enforced by a unique index, so two
   simultaneous redemptions cannot both win.

A failure returns `CONTEXT_INVALID` with one message for every reason. The
specific cause goes to the audit log and the server log, so a token cannot be
probed for *why* it failed.

### Issuing one (PHP)

```php
function remoteLaunchUrl(array $context, string $secret): string
{
    $header  = base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64url(json_encode($context + [
        'iss' => 'https://my.aicountly.com',
        'aud' => 'aicountly-remote',
        'iat' => time(),
        'exp' => time() + 120,
        'jti' => bin2hex(random_bytes(16)),
    ]));

    $signature = base64url(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));

    return 'https://remote.aicountly.com/?context=' . rawurlencode("{$header}.{$payload}.{$signature}");
}

function base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
```

Mint the token **at click time**, server-side, and redirect straight away. A
token minted when the page rendered has usually expired by the time somebody
presses the button, and a token in rendered HTML is a token that can be
scraped.

Remote reads `?context=` at boot, moves it into memory, removes it from the
address bar, and sends it once on `X-Remote-Context`.

### "Need help?" — going straight to the support queue

Same token; Remote's *Request AICOUNTLY Support* form opens pre-filled with the
company, product and area, and the resulting session is scoped
`AICOUNTLY_SUPPORT` with that company. It cannot be turned into a personal
session — that is the point of §13.

---

## 2. Publishing a capture handle

When a user shares an AICOUNTLY tab, the browser's Capture Handle API lets that
tab expose a small identifier the capturing page can read. Remote uses it to
answer one question: **is this tab the same organisation as this session?**

```ts
/**
 * Call once per page, wherever the company context is known. Safe to call on
 * every navigation — it is a cheap assignment, not a request.
 */
export function registerRemoteCaptureContext(context: {
  product: string      // 'BOOKS'
  companyId: number    // 481
}): boolean {
  const devices = navigator.mediaDevices as MediaDevices & {
    setCaptureHandleConfig?: (config: {
      handle: string
      exposeOrigin: boolean
      permittedOrigins: string[]
    }) => void
  }

  // Chromium-only today. Absence is not a failure — sharing works exactly as
  // before, and Remote says verification is unavailable rather than implying
  // it happened.
  if (typeof devices?.setCaptureHandleConfig !== 'function') return false

  devices.setCaptureHandleConfig({
    handle: `aicountly:${context.product}:${context.companyId}`,
    exposeOrigin: true,
    permittedOrigins: ['https://remote.aicountly.com'],
  })

  return true
}
```

### The handle format

```
aicountly:<PRODUCT>:<companyId>
```

A product code and an organisation id. **Nothing else.** No user, no name, no
token, no data. Remote parses exactly this shape and ignores anything else.

`permittedOrigins` must name Remote and only Remote: any page that captures the
tab could otherwise read the handle.

### What Remote does with it

When a shared tab reports a company that is not the session's, Remote:

1. stops sharing immediately;
2. pauses the session;
3. records `COMPANY_CONTEXT_MISMATCH` with both organisation ids;
4. tells the user the tab belongs to a different organisation.

This is the §12 guarantee: a user who switches company in another tab cannot
accidentally expose one tenant to a viewer admitted for another.

Where the browser has no Capture Handle support, sharing proceeds normally and
the session's Security panel says verification is unavailable. Reduced
verification is communicated, never silently assumed.

---

## Checklist for an integrating product

- [ ] The launch token is minted **server-side, at click time**, with a short
      `exp` and a unique `jti`.
- [ ] The signing secret matches `remote.contextSecret` and lives only in a
      server `.env` — never in a frontend bundle.
- [ ] The product code is on `remote.sourceProductAllowlist`.
- [ ] `registerRemoteCaptureContext()` runs wherever company context is known,
      and again after a company switch.
- [ ] `permittedOrigins` names only the Remote origin.
- [ ] The handle carries the product and the company id, and nothing else.
