//! The macOS build's entitlements, and why there is exactly one.
//!
//! # Why the application turns library validation off
//!
//! The Mac build is signed with the hardened runtime
//! (`tauri.macos.conf.json`, `hardenedRuntime: true`), which notarisation
//! requires. The hardened runtime brings **library validation**: the process
//! may load only libraries signed by Apple or by the same team as the
//! application. Cisco's OpenH264 dylib is signed by Cisco, if at all — never
//! by AICOUNTLY's team — and it cannot be re-signed by us either: Cisco's
//! patent licence covers *Cisco's* binary, downloaded from Cisco onto the
//! person's Mac, and a copy we had modified or redistributed would not be that
//! (docs/legal/OPENH264.md). So the only way a hardened, notarised build can
//! load the codec is `com.apple.security.cs.disable-library-validation`.
//!
//! # What that costs, and what stands in for it
//!
//! Library validation is a defence against a library being slipped into the
//! process — a dylib planted where the application looks for one, or injected
//! with `DYLD_INSERT_LIBRARIES`. Turning it off widens that door for the whole
//! process, which matters for an application that injects input. What closes
//! it again:
//!
//! * the hardened runtime is otherwise left on: no
//!   `allow-dyld-environment-variables`, so `DYLD_*` injection is still
//!   refused, and no `allow-unsigned-executable-memory` or JIT;
//! * the application loads exactly one library by path, and only after its
//!   SHA-256 matches the hash pinned for Cisco's 2.6.0 build and the
//!   `openh264` crate's own list of Cisco builds — checked again every time it
//!   is loaded (`remote-webrtc/src/video/cisco.rs`);
//! * that file lives in the person's own Application Support folder, which the
//!   application makes `0700` before writing, and is written atomically.
//!
//! A planted library with the right name and the wrong contents is never
//! loaded; one with the right contents is Cisco's codec. That is the trade:
//! library validation's general guarantee for a narrower, checked one, because
//! the general one cannot be had with Cisco's binary at all.
//!
//! These tests keep the file to that one entitlement, so the next one added
//! is a decision somebody makes on purpose, with this explanation beside it.

use std::path::Path;

fn read(relative: &str) -> String {
    let path = Path::new(env!("CARGO_MANIFEST_DIR")).join(relative);

    std::fs::read_to_string(&path).unwrap_or_else(|error| panic!("{}: {error}", path.display()))
}

/// The macOS configuration names the entitlements file. In the platform file,
/// not `tauri.conf.json`: Tauri merges `tauri.macos.conf.json` over the base
/// as a JSON merge patch, where a `null` deletes the key — so an
/// `"entitlements": null` there would erase a value set in the base.
#[test]
fn the_macos_build_is_signed_with_the_entitlements_file() {
    let config: serde_json::Value =
        serde_json::from_str(&read("tauri.macos.conf.json")).expect("valid JSON");
    let macos = &config["bundle"]["macOS"];

    assert_eq!(macos["entitlements"], "./Entitlements.plist");
    assert_eq!(
        macos["hardenedRuntime"], true,
        "the hardened runtime stays on"
    );
    assert!(Path::new(env!("CARGO_MANIFEST_DIR"))
        .join("Entitlements.plist")
        .is_file());

    let base: serde_json::Value =
        serde_json::from_str(&read("tauri.conf.json")).expect("valid JSON");
    assert!(
        base["bundle"]["macOS"]["entitlements"].is_null(),
        "one place for the macOS entitlements, not two that can disagree"
    );
}

/// Exactly one entitlement, and the one it is.
#[test]
fn the_only_entitlement_is_the_one_cisco_s_codec_needs() {
    let plist = read("Entitlements.plist");

    let keys: Vec<&str> = plist
        .lines()
        .map(str::trim)
        .filter_map(|line| line.strip_prefix("<key>")?.strip_suffix("</key>"))
        .collect();

    assert_eq!(keys, ["com.apple.security.cs.disable-library-validation"]);
    assert!(plist.contains("<true/>"));

    // Never these: each would undo what stands in for library validation.
    for forbidden in [
        "allow-dyld-environment-variables",
        "allow-unsigned-executable-memory",
        "allow-jit",
        "disable-executable-page-protection",
        "get-task-allow",
    ] {
        assert!(!plist.contains(forbidden), "{forbidden}");
    }
}
