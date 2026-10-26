# macOS installers

None of its own: Tauri's bundler produces the `.app` and the `.dmg`
(`src-tauri/tauri.macos.conf.json`), and `.github/workflows/build-macos-test.yml`
packages a test build with its `config.json` and install steps. See
`../../scripts/macos/README.md` for what is and is not done.
