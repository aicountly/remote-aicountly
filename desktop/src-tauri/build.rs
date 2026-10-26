fn main() {
    // The default Tauri manifest declares only the Common Controls dependency.
    // This one also declares per-monitor-v2 DPI awareness, which the whole
    // coordinate system depends on, and `asInvoker`. See
    // `windows-app-manifest.xml` for why each line is there.
    let windows = tauri_build::WindowsAttributes::new()
        .app_manifest(include_str!("windows-app-manifest.xml"));

    tauri_build::try_build(tauri_build::Attributes::new().windows_attributes(windows))
        .expect("the Tauri build script failed");
}
