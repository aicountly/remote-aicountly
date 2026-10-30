# The web portal inside the desktop application

Once a computer is signed in, the desktop window's **Remote** tab shows the
AICOUNTLY Remote web portal: the same Dashboard, Start session, Join session,
Support requests, Sessions, Computers and Settings that a browser shows. The
other tabs (This computer, App settings, and Unattended access where it can be
used) are about the machine itself and exist only in the application.

The window's own chrome — the dark header with the product name, "for
Windows"/"for Mac", the version and the tabs — is the application's; everything
below it on the Remote tab is the web portal. **Back**, **Reload** and **Open in
browser** appear above the portal only while it is on a sign-in page (the
AICOUNTLY sign-in, or an identity provider's), where somebody can get stuck; on
the portal's own pages the header carries Reload and Open in browser as two
small buttons instead. The Rust side tells the window which it is after every
page the portal loads (`portal.rs`, `PORTAL_PAGE_EVENT`).

Inside the application the portal is always **Personal**: no organisation
switcher, no session-context choice, and Start session begins with the entire
screen selected, because only an entire screen can be controlled from this
computer (`CONTROL_COMPANION.md`).

## How it works

The portal is not a second copy of the web pages. It is this deployment's web
application, shown in a **child webview** that Tauri draws over the window's
content area (`desktop/src-tauri/src/portal.rs`, `desktop/src/features/portal/PortalView.tsx`).
So the menus, permissions and behaviour cannot drift from the web portal, and
a change to the web application reaches the desktop the moment it is deployed.

* **Where it points:** `api_base_url` without its `/api` — the same
  deployment the application is configured for. The window only ever chooses a
  *path*; the Rust side refuses anything that is not a plain path on that origin.
* **What it can reach:** nothing of the application's. Tauri refuses every
  command from a remote origin unless a capability names that origin under
  `remote`, and `capabilities/default.json` names none.
* **What it may load:** the portal's own origin, AICOUNTLY's hosts and the
  identity providers the AICOUNTLY sign-in page offers (Google, Microsoft,
  LinkedIn, Apple), so signing in works as it does in a browser. Any other link,
  and any link that asks for a new window, opens in the person's own browser;
  a new-window link to the portal itself opens in place.
* **Sign-in:** handed over when the computer is confirmed (below), and kept in
  the webview's storage like a browser profile.

## Registering a computer

Sign in now opens the confirmation page (`/desktop-signin?code=…`) in the
portal, inside the window, below the code. Confirming there registers the
computer **and** leaves the portal signed in, so the Remote tab is ready
straight after. **Open in browser instead** opens the same page in the
person's browser, exactly as before — for anyone whose identity provider
refuses to sign in inside an embedded web view, which some do.

## Signing the portal in

Whichever way the computer is confirmed — inside the window, or with **Open
in browser instead** — the portal inside the application is signed in as the
person who confirmed it, and stays signed in until they log out of the portal,
the sign-in expires, or the computer is signed out.

1. The confirmation page sends its own AICOUNTLY `auth_token` with the
   confirmation (`POST /desktop-signin/confirm`, `portalToken`).
2. The agent's next poll claims the sign-in. In the same statement the server
   seals the token with a key derived from a fresh 32-byte code, keeps only that
   code's hash, and returns the code to the agent (`portalHandoff`) — once.
   The plain token is in the database only between confirm and that poll.
3. The Rust side keeps the code (the window never sees it) and opens the portal
   at `/auth/desktop#desktop_handoff=<code>&n=<nonce>`. The fragment is never
   sent to a server.
4. The web application takes the code out of the address before anything else
   reads it, redeems it once (`POST /desktop-signin/handoff`), and stages,
   checks and stores the token exactly like any other sign-in.

`n` is a random value created each time the application starts and given to
the portal page — on the portal's own origin only — as
`__AICOUNTLY_REMOTE_DESKTOP__`. A hand-off is accepted only when the two match,
so a link cannot sign the portal in as somebody else. The same marker hides the
web portal's "AICOUNTLY apps" launcher and its download prompts inside the
application.

**Sign this computer out** clears the portal's cookies and storage too, so the
next person to use the computer is not signed in as the last one.

## Things to check on real machines

These are native behaviours that cannot be exercised in CI:

* signing in through each identity provider the AICOUNTLY sign-in page offers;
* screen sharing from the portal's own session room (WebView2 supports
  `getDisplayMedia`; WKWebView's support depends on the macOS version);
* the portal following the window when it is resized, maximised, or moved
  between monitors with different scaling.
