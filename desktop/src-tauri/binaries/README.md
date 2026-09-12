# Sidecar binaries

`AicountlyRemoteService-<target triple>.exe` is placed here by
`scripts/windows/build.ps1` before `tauri build` runs, and Tauri installs it
beside the application as `AicountlyRemoteService.exe`.

It is a build output, not a source file: nothing here is committed, and a
missing file means the build script has not been run yet rather than that
something is wrong with the checkout.
