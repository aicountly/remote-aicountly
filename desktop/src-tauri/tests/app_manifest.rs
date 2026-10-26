//! The Windows application manifest, and the one way it can be wrong without
//! anybody noticing until a Windows machine refuses to start the binary.
//!
//! # What actually happens to that file
//!
//! `tauri-winres` does not hand the manifest to the resource compiler as a
//! file. It writes **one quoted RC string per line** into a generated `.rc`,
//! each line trimmed, and the compiler concatenates them with no newlines. So
//! what Windows parses is every line of `windows-app-manifest.xml` joined by
//! single spaces.
//!
//! An XML declaration cannot survive that. `<?xml ... ?>` has to be the very
//! first thing in a document, and after the join it is preceded by a space.
//! Windows then cannot build an activation context from the manifest, and the
//! binary dies before `main` with `ERROR_SXS_CANT_GEN_ACTCTX` (14001) — "the
//! application has failed to start because its side-by-side configuration is
//! incorrect". Nothing fails at build time; the exe simply cannot be
//! executed, which is how it reached CI as a test binary that would not run.
//!
//! These tests do the same transformation and check what comes out, so the
//! next edit to that file cannot rediscover it.

use std::path::{Path, PathBuf};

fn manifest_path() -> PathBuf {
    Path::new(env!("CARGO_MANIFEST_DIR")).join("windows-app-manifest.xml")
}

fn manifest() -> String {
    let path = manifest_path();

    std::fs::read_to_string(&path).unwrap_or_else(|error| panic!("{}: {error}", path.display()))
}

/// The manifest as Windows receives it: every line trimmed and joined by a
/// single space, which is what the generated `.rc` produces.
fn as_embedded(manifest: &str) -> String {
    manifest
        .lines()
        .map(str::trim)
        .collect::<Vec<_>>()
        .join(" ")
}

/// The one that cost a CI run. An XML declaration is legal in a file and
/// fatal once the resource compiler has put a space in front of it.
#[test]
fn the_manifest_carries_no_xml_declaration() {
    let manifest = manifest();

    assert!(
        !manifest.contains("<?xml"),
        "an XML declaration cannot be the first thing in the document once \
         tauri-winres joins the lines, and Windows refuses the whole \
         activation context: ERROR_SXS_CANT_GEN_ACTCTX (14001)"
    );
}

/// Joined into one line, it still has to be the document Windows expects:
/// `<assembly>` first, nothing in front of it.
#[test]
fn the_manifest_is_still_a_document_after_the_lines_are_joined() {
    let embedded = as_embedded(&manifest());

    assert!(
        embedded.starts_with("<assembly "),
        "the document must begin with its root element, not with whitespace \
         or anything else: {}",
        &embedded[..embedded.len().min(80)]
    );
    assert!(embedded.trim_end().ends_with("</assembly>"));

    // A comment joined onto one line is still a comment — but it is also
    // prose compiled into every shipped binary, through a quoting path with
    // its own escaping rules, for no benefit. The reasoning lives in
    // `build.rs`.
    assert!(
        !embedded.contains("<!--"),
        "the manifest is embedded in the binary; explanations belong in build.rs"
    );
}

/// Every line becomes a quoted RC string. ASCII keeps that path boring.
#[test]
fn the_manifest_is_ascii_because_the_resource_compiler_quotes_every_line() {
    for (number, line) in manifest().lines().enumerate() {
        assert!(line.is_ascii(), "line {} is not ASCII: {line}", number + 1);
        assert!(
            !line.contains('\\'),
            "line {} contains a backslash, which RC escaping treats specially: {line}",
            number + 1
        );
    }
}

/// What the manifest is *for*. Each of these is a property something else in
/// this codebase depends on, so losing one silently is the thing to prevent.
#[test]
fn the_manifest_declares_what_the_agent_depends_on() {
    let embedded = as_embedded(&manifest());

    // Every coordinate in the control path is a physical pixel. Without this
    // the process is DPI-unaware and Windows virtualises all of them.
    assert!(
        embedded.contains(">PerMonitorV2<"),
        "per-monitor-v2 DPI awareness is what makes GetSystemMetrics and \
         EnumDisplayMonitors report physical pixels"
    );
    assert!(
        embedded.contains(">true/PM<"),
        "the older dpiAware spelling"
    );

    // The application runs as whoever signed in. Everything privileged is
    // the service's.
    assert!(embedded.contains(r#"level="asInvoker""#));
    assert!(
        !embedded.contains("requireAdministrator") && !embedded.contains("highestAvailable"),
        "the window must never ask for elevation"
    );
    assert!(embedded.contains(r#"uiAccess="false""#));

    // Tauri's dialog APIs need it, and this manifest replaces the default one
    // that used to declare it.
    assert!(embedded.contains("Microsoft.Windows.Common-Controls"));

    // `supportedOS` would have to be edited for every future Windows release;
    // the version is read through RtlGetVersion instead.
    assert!(
        !embedded.contains("supportedOS"),
        "see platform/windows/device.rs for why the compatibility block is absent"
    );
}

/// And the build script has to actually embed it, or none of the above is
/// true of anything that ships.
#[test]
fn the_build_script_embeds_the_manifest() {
    let build = std::fs::read_to_string(Path::new(env!("CARGO_MANIFEST_DIR")).join("build.rs"))
        .expect("build.rs is readable");

    assert!(build.contains("app_manifest"));
    assert!(build.contains("windows-app-manifest.xml"));
}
