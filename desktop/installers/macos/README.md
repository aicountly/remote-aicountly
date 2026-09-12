# macOS installers

Deliberately empty. See `../../scripts/macos/README.md`.

There is no macOS build to package: every provider in
`src-tauri/src/platform/macos/` returns `PlatformError::Unsupported`, and
shipping a `.dmg` of that would be shipping an application that opens and
cannot do anything.
