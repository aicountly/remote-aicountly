# macOS scripts

Deliberately empty.

The shared Rust crates compile on macOS and the trait definitions in
`crates/remote-device` are written to be implemented there, but
`src-tauri/src/platform/macos/` returns `PlatformError::Unsupported` for every
one of them today. There is nothing to build, sign or notarise, and a script
that pretended otherwise would be a script somebody ran and believed.

What a macOS build will need when it is written, none of which is done:

* a `ScreenCaptureKit` capture provider, and the **Screen Recording** privacy
  permission that goes with it — which cannot be granted programmatically and
  requires the person at the machine to grant it in System Settings;
* a `CGEvent` input provider, and the **Accessibility** permission, which has
  the same property;
* a launch daemon rather than a Windows service, with its own IPC channel
  (a Unix domain socket with an owner check, not a named pipe);
* Developer ID signing, hardened runtime, and notarisation — a separate
  credential from the Windows one and a separate release path.

See `docs/desktop/ARCHITECTURE.md` for how the platform split is meant to keep
that work confined to `src-tauri/src/platform/macos/`.
