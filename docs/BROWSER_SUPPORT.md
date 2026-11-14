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
| File transfer | ✅ | ✅ | ✅ | ✅ |
| Microphone | ✅ | ✅ | ✅ | ✅ |
| Surface verification (`displaySurface`) | ✅ | ❌ | ❌ | — |
| Safe Share verification (Capture Handle) | ✅ | ❌ | ❌ | — |
| System audio | ✅ (tab / screen) | ❌ | ❌ | — |

Helping somebody whose computer runs the AICOUNTLY Remote app (Windows or Mac):

| | Chrome / Edge 100+ | Firefox 110+ | Safari 16.4+ | Mobile browsers |
|---|---|---|---|---|
| See the computer's screen (H.264) | ✅ | ✅ | ✅ | ✅ |
| Control it: mouse, keyboard, scroll | ✅ | ✅ | ✅ | ⚠️ taps only — no keyboard, no drag |
| Switch between its displays | ✅ | ✅ | ✅ | ✅ |
| The **Keys** menu (Ctrl+Alt+Del, Start menu, Task Manager; on a Mac, Force Quit, Spotlight…) | ✅ | ✅ | ✅ | ✅ |
| The whole keyboard in full screen (Alt+Tab, the Windows key, Ctrl+W, Esc) | ✅ Keyboard Lock | ❌ use the Keys menu | ❌ use the Keys menu | — |
| Paste the helper's own clipboard, when the clipboard was shared | ✅ | ✅ | ✅ | — |
| Hear the computer's sound (Windows, when switched on there) | ✅ | ✅ | ✅ | ✅ |

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
* **Full screen** (the button on somebody else's shared screen) needs the
  Fullscreen API on elements. Safari on an iPhone has none, and a page embedded
  without `allowfullscreen` is refused it, so neither shows the button.
* **Keys a browser keeps for itself.** Outside full screen every browser keeps
  Alt+Tab, the Windows or Command key, Ctrl+W and Ctrl+T, and no browser ever
  hands a page Ctrl+Alt+Del. In full screen, Chrome and Edge give the page the
  whole keyboard (Keyboard Lock): those keys then go to the computer being
  controlled, Esc included, and leaving full screen is a long press of Esc —
  the browser says so itself. Firefox and Safari have no Keyboard Lock: there
  Esc leaves full screen, and the keys they keep are sent from the room's
  **Keys** menu instead, which works in every browser and needs no full screen.
* **Two kinds of keyboard.** A helper on a Mac controlling a Windows computer
  has ⌘ sent as Ctrl, and a helper on Windows or Linux controlling a Mac has
  Ctrl sent as ⌘, so copy, paste, select all and undo are the keys the helper's
  hands already know (`keyForPlatform`). The key that is given up — the Windows
  key from a Mac, a Mac's own Control from a PC — is in the Keys menu. One gap:
  a character a Mac helper types with Option (`#` on a British layout, `@` on a
  German one) is not typed on a Windows computer.
* **Paste** needs the helper's browser to recognise the paste key itself
  (Ctrl+V, ⌘V or Shift+Insert): the clipboard's text is read from the browser's
  own `paste` event — no clipboard permission is asked for — and sent ahead of
  the key. Nothing travels the other way: what is copied on the controlled
  computer stays there.
* **Sound** from the computer starts at the helper's first click or key press
  in the room. Browsers do not let a page play sound before the person has
  interacted with it; the picture is not held back for it.
* **The picture is H.264.** Every current browser decodes it. A Chromium build
  compiled without proprietary codecs (some Linux distributions' `chromium`
  package) does not, and shows no picture from a computer running the app; a
  browser-to-browser session is unaffected.
