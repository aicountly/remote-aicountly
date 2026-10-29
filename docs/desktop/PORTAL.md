# The web portal inside the desktop application

Once a computer is signed in, the desktop window's **Remote** tab shows the
AICOUNTLY Remote web portal: the same Dashboard, Start session, Join session,
Support requests, Sessions, Computers, Settings and, for administrators, the
administration pages that a browser shows. The other tabs (This computer,
Unattended access, App settings) are about the machine itself and exist only
in the application.

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
* **Sign-in:** the portal's own, kept in the webview's storage like a browser
  profile, so a person signs in once.

## Registering a computer

Sign in now opens the confirmation page (`/desktop-signin?code=…`) in the
portal, inside the window, below the code. Confirming there registers the
computer **and** leaves the portal signed in, so the Remote tab is ready
straight after. **Open in browser instead** opens the same page in the
person's browser, exactly as before — for anyone whose identity provider
refuses to sign in inside an embedded web view, which some do.

## Things to check on real machines

These are native behaviours that cannot be exercised in CI:

* signing in through each identity provider the AICOUNTLY sign-in page offers;
* screen sharing from the portal's own session room (WebView2 supports
  `getDisplayMedia`; WKWebView's support depends on the macOS version);
* the portal following the window when it is resized, maximised, or moved
  between monitors with different scaling.
