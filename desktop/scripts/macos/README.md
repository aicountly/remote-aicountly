# macOS

AICOUNTLY Remote on macOS is a **control companion**: its owner shares an
entire screen from Chrome or Edge, links this Mac to that share, and somebody
they grant control to in the browser uses this Mac's mouse and keyboard. The
Mac captures nothing — the picture is the browser's. See
`docs/desktop/CONTROL_COMPANION.md` and `src-tauri/src/platform/macos/`.

## Building a test build

`.github/workflows/build-macos-test.yml` (manual) builds an Apple Silicon
`.app` and `.dmg` with `npx tauri build --target aarch64-apple-darwin --bundles
app,dmg`, signed with one stable Apple Development certificate when the
repository has one, ad hoc (`-`) otherwise. `src-tauri/tauri.macos.conf.json`
sets macOS 13 as the minimum and the hardened runtime; it needs no
entitlements, no notarisation and no Developer ID.

The same build locally, on a Mac with Xcode's command-line tools:

```sh
cd desktop
npm ci
APPLE_SIGNING_IDENTITY=- npx tauri build --target aarch64-apple-darwin --bundles app,dmg
```

The configuration is `~/Library/Application Support/AICOUNTLY/Remote/config.json`
(the same keys as on Windows); the workflow ships one for the chosen
environment.

## What the person at the Mac grants

* **Accessibility** — System Settings → Privacy & Security → Accessibility.
  Without it macOS drops every posted event silently, so the companion does not
  report itself ready until it is granted; the window explains this and opens
  macOS's own prompt. macOS ties the grant to the signing identity: an ad-hoc
  build has to be allowed again after every install.
* **Not Screen Recording.** It is never requested.

## Only verifiable on a real Mac

* that CGEvents from a private source land where `to_global_point` says, on a
  Retina display and on an external one at a different scale;
* shortcuts (⌘C, ⌘⇧Z) and plain typing in a non-US layout;
* that `CGEventSourceSecondsSinceLastEventType(HIDSystemState)` reflects the
  person's own input and not the agent's — the local-input pause depends on it;
* the Keychain item's accessibility class in a signed build, and the login
  keychain fallback in an ad-hoc one;
* the Identify labels' placement on each monitor.

## Still to do before anybody else installs it

* Developer ID Application certificate, hardened runtime with a
  `keychain-access-groups` entitlement (so the device key uses the
  data-protection keychain in every build), notarisation and stapling — a
  separate credential and release path from Windows.
* A release workflow and an update feed, as Windows has.
