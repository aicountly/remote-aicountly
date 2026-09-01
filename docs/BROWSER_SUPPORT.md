# Browser support

Remote detects capabilities rather than sniffing user agents
(`web/src/services/browser/capabilities.ts`), so a browser that gains a feature
gets it without a release here. The *Settings* page shows a user exactly what
their own browser supports, which makes it the first place to look when
something is unavailable.

## Summary

| | Chrome / Edge 100+ | Firefox 110+ | Safari 16.4+ | Mobile browsers |
|---|---|---|---|---|
| View a shared screen | ✅ | ✅ | ✅ | ✅ |
| Share a screen | ✅ | ✅ | ✅ (macOS) | ❌ |
| Chat, pointer, annotation | ✅ | ✅ | ✅ | ✅ |
| Microphone | ✅ | ✅ | ✅ | ✅ |
| Surface verification (`displaySurface`) | ✅ | ❌ | ❌ | — |
| Safe Share verification (Capture Handle) | ✅ | ❌ | ❌ | — |
| System audio | ✅ (tab / screen) | ❌ | ❌ | — |

Everything needs a **secure context**. `getDisplayMedia` is unavailable over
plain HTTP in every browser; `http://localhost` is a secure context by
specification, `http://192.168.x.x` is not.

## The two gaps that matter

### Surface verification

Chromium reports which surface the user picked, so Remote can confirm it
against company policy. Firefox and Safari do not.

Refusing those browsers outright would make Remote unusable on them. Instead
the session proceeds under the mode the server already authorised in
`share-intent`, and the gap is recorded honestly:

* the audit event carries `verified: false`;
* the session's **Security** panel says the surface could not be verified,
  rather than implying it was;
* the session detail page shows *"Not reported by this browser"*.

An organisation that needs the guarantee should restrict its users to a
Chromium-based browser and say so — Remote will not pretend to a check it could
not make.

### Safe Share verification

Capture Handle lets a shared AICOUNTLY tab prove which organisation it belongs
to, which is what allows Remote to detect a company mismatch mid-session (§12).
Chromium only.

Without it, Safe Share still works exactly as before — it is a browser tab
either way. What is missing is the confirmation, and the UI says so instead of
implying it happened.

## Mobile

Mobile browsers cannot capture a screen. That is an operating-system
limitation, not something Remote can work around.

So on mobile Remote offers what does work — joining, viewing a shared screen,
chat, microphone — and says plainly that sharing is unavailable, rather than
showing a Share button that throws. Every management screen is fully usable on
a phone.

## Known behaviours

* **Firefox** ignores the `displaySurface` constraint hint entirely and offers
  the user every surface. This is why the server re-checks after the fact
  rather than trusting the request.
* **Safari** requires a user gesture very close to the `getDisplayMedia` call.
  Remote's consent dialog leads straight into it, so this holds.
* **Safari on iOS** has no `getDisplayMedia` at all.
* Stopping a share from the browser's own sharing bar fires `ended` on the
  track. Remote listens for it, so the session never claims to be sharing a
  screen that is already black.
* Some corporate networks block UDP entirely; without TURN over TLS on 443/5349
  those users cannot connect at all, and Remote says so rather than retrying
  forever.
