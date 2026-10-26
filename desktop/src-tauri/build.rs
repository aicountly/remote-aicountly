//! The Tauri build script, and the application manifest it embeds.
//!
//! # Why there is a manifest here at all
//!
//! Tauri embeds a default one that declares a single thing: the Common
//! Controls v6 dependency its dialog APIs need. `windows-app-manifest.xml`
//! keeps that and adds the two declarations a remote-control agent cannot be
//! left without.
//!
//! **Per-monitor-v2 DPI awareness.** Without it the process is DPI *unaware*
//! and Windows virtualises every coordinate it reports: `GetSystemMetrics(
//! SM_CXVIRTUALSCREEN)` and the rectangles from `EnumDisplayMonitors` come
//! back in scaled, logical pixels rather than physical ones. On a 150%
//! display that is the difference between a click landing where the viewer
//! pointed and landing two thirds of the way there; across a mixed-DPI pair,
//! the difference between the right screen and the wrong one. It is the
//! failure `platform/windows/input.rs` calls "the single most common way
//! remote control lands clicks on the wrong monitor".
//!
//! Tao, the windowing layer under Tauri, calls `SetProcessDpiAwarenessContext`
//! when it creates the event loop, so this was *nearly* true already. Nearly
//! is not a property to rest a coordinate system on: a manifest is in force
//! from the first instruction of the process and cannot be reordered by a
//! change to when something else initialises.
//!
//! **`asInvoker`.** Stated rather than inferred. The application is not an
//! installer and must never ask for elevation: it runs as whoever signed in,
//! and everything that genuinely needs privilege is the service's.
//!
//! There is deliberately no `<compatibility>` / `supportedOS` block. That
//! exists to make the frozen `GetVersionEx` report the real Windows version,
//! and it has to be edited for every future Windows release;
//! `platform/windows/device.rs` reads the version through `RtlGetVersion`
//! instead, which is not subject to the shim.
//!
//! # The shape that file has to keep
//!
//! `tauri-winres` does not hand the manifest to the resource compiler as a
//! file. It writes **one quoted RC string per line** into a generated `.rc`,
//! each line trimmed, and the compiler concatenates them with no newlines —
//! so what Windows finally parses is every line of that file joined by single
//! spaces.
//!
//! An XML declaration cannot survive that: `<?xml ... ?>` has to be the very
//! first thing in a document, and after the join it is preceded by a space.
//! Windows then cannot build an activation context from it and the binary
//! dies on startup with `ERROR_SXS_CANT_GEN_ACTCTX` (14001), "the
//! side-by-side configuration is incorrect", before `main` is reached — which
//! is how it reached CI as a test binary that could not be executed rather
//! than as a build failure. Comments and non-ASCII characters go in the same
//! category: they are prose compiled into every shipped binary, through a
//! quoting path with its own escaping rules, for no benefit. The explanation
//! lives here instead.
//!
//! `tests/app_manifest.rs` applies that same transformation and checks the
//! result, so the next edit to the manifest cannot rediscover this.

fn main() {
    let windows = tauri_build::WindowsAttributes::new()
        .app_manifest(include_str!("windows-app-manifest.xml"));

    tauri_build::try_build(tauri_build::Attributes::new().windows_attributes(windows))
        .expect("the Tauri build script failed");
}
