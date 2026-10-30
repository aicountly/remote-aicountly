//! What the installer and the binaries have to agree about.
//!
//! Every assertion here is a mistake that has actually been made in this
//! repository, and every one of them was invisible until somebody installed
//! the product on a real machine: the installer referred to a file that was
//! never built, or gave a directory an ACL the application could not write
//! in. Neither a compiler nor a Windows runner catches that — the installer
//! is a text file, and nothing had ever read it.
//!
//! So these tests read it. They run on any host, because `hooks.nsh`,
//! `Cargo.toml` and `tauri.conf.json` are files.

use std::path::{Path, PathBuf};

/// `desktop/`.
fn desktop() -> PathBuf {
    // CARGO_MANIFEST_DIR is `desktop/src-tauri`.
    Path::new(env!("CARGO_MANIFEST_DIR"))
        .parent()
        .expect("src-tauri has a parent")
        .to_path_buf()
}

fn read(relative: &str) -> String {
    let path = desktop().join(relative);

    std::fs::read_to_string(&path).unwrap_or_else(|error| panic!("{}: {error}", path.display()))
}

/// The `[[bin]]` name from a manifest — the file name cargo produces, and
/// therefore the one Tauri installs.
///
/// Tauri renames the main binary only when `mainBinaryName` is set in
/// `tauri.conf.json`; it is not, so the bundler installs, shortcuts and
/// registers exactly this name.
fn binary_name(manifest: &str) -> String {
    let text = read(manifest);
    let after_bin = text
        .split("[[bin]]")
        .nth(1)
        .unwrap_or_else(|| panic!("{manifest} has no [[bin]] section"));

    for line in after_bin.lines() {
        let line = line.trim();

        if let Some(value) = line.strip_prefix("name") {
            return value
                .trim_start_matches([' ', '=', '"'])
                .trim_end_matches('"')
                .to_owned();
        }
    }

    panic!("{manifest}'s [[bin]] section has no name");
}

/// The value of an NSIS `!define`.
fn nsis_define(hooks: &str, name: &str) -> String {
    let needle = format!("!define {name} ");

    for line in hooks.lines() {
        let line = line.trim();

        // Not a comment. NSIS comments start with ';' or '#', and this file
        // has plenty that quote the defines they are explaining.
        if line.starts_with(';') || line.starts_with('#') {
            continue;
        }

        if let Some(value) = line.strip_prefix(&needle) {
            return value.trim().trim_matches('"').to_owned();
        }
    }

    panic!("the installer hooks define no {name}");
}

/// The sign-in `Run` entry pointed at `"$INSTDIR\AICOUNTLY Remote.exe"` while
/// the installed file was `AicountlyRemote.exe`, so the tray application never
/// started with Windows — on every machine, silently, because a `Run` value
/// naming a missing file is not an error Windows reports to anybody.
#[test]
fn the_installer_names_the_executable_that_is_actually_built() {
    let hooks = read("installers/windows/hooks.nsh");

    assert_eq!(
        nsis_define(&hooks, "AICOUNTLY_APP_EXE"),
        format!("{}.exe", binary_name("src-tauri/Cargo.toml")),
        "the installer must name the application binary cargo produces"
    );

    assert_eq!(
        nsis_define(&hooks, "AICOUNTLY_SERVICE_EXE"),
        format!("{}.exe", binary_name("agents/windows-service/Cargo.toml")),
        "the installer must name the service binary cargo produces"
    );
}

/// And the `Run` entry has to start it the way the application expects to be
/// started in the background — [`aicountly_remote_desktop::BACKGROUND_ARGUMENT`],
/// which `is_background_launch` matches exactly and nothing else.
#[test]
fn the_sign_in_entry_starts_the_application_in_the_background() {
    let hooks = read("installers/windows/hooks.nsh");
    let executable = nsis_define(&hooks, "AICOUNTLY_APP_EXE");

    let run_entry = hooks
        .lines()
        .find(|line| line.trim_start().starts_with("WriteRegStr HKLM"))
        .expect("the installer writes a Run entry")
        .to_owned();

    assert!(
        run_entry.contains("${AICOUNTLY_RUN_KEY}"),
        "the Run entry goes in the Run key: {run_entry}"
    );
    assert!(
        run_entry.contains(aicountly_remote_desktop::BACKGROUND_ARGUMENT),
        "the Run entry must pass {}: {run_entry}",
        aicountly_remote_desktop::BACKGROUND_ARGUMENT
    );
    assert!(
        run_entry.contains("${AICOUNTLY_APP_EXE}") || run_entry.contains(&executable),
        "the Run entry must name the application: {run_entry}"
    );
}

/// Every file in the data directory is written by the tray application running
/// as an ordinary, non-elevated person: the configuration when they change a
/// setting, the enrolment record and the DPAPI key blob when they register the
/// computer. The installer granted the `Users` group read-only access, so all
/// three writes failed with "Access is denied" and a freshly installed product
/// could not be registered at all.
#[test]
fn the_data_directory_is_writable_by_the_person_who_registers_the_computer() {
    let hooks = read("installers/windows/hooks.nsh");

    // S-1-5-32-545 is BUILTIN\Users.
    let grant = hooks
        .lines()
        .map(str::trim)
        .find(|line| !line.starts_with(';') && line.contains("S-1-5-32-545"))
        .expect("the installer grants the Users group something");

    assert!(
        grant.contains("(OI)(CI)M"),
        "the Users group needs modify on the data directory, not {grant}"
    );

    // And the two principals that must keep full control: the service runs as
    // LocalSystem, and support happens as an administrator.
    for (sid, who) in [
        ("S-1-5-18", "LocalSystem"),
        ("S-1-5-32-544", "Administrators"),
    ] {
        assert!(
            hooks.lines().map(str::trim).any(|line| {
                !line.starts_with(';') && line.contains(sid) && line.contains("(OI)(CI)F")
            }),
            "{who} must keep full control of the data directory"
        );
    }

    assert!(
        hooks.contains("/inheritance:r"),
        "the ACL is rebuilt rather than inherited"
    );
}

/// The uninstaller removes the files the agent actually writes. A name that
/// drifted here would leave a usable device identity on a machine somebody
/// just uninstalled the agent from, which is the opposite of what uninstalling
/// a remote-support agent means.
#[test]
fn the_uninstaller_removes_every_file_the_agent_writes() {
    let hooks = read("installers/windows/hooks.nsh");

    for file in [
        // `remote_security::DEVICE_KEY_ENTRY` plus the `.key` suffix the
        // Windows provider appends, and the temporary file its atomic write
        // can leave behind.
        "device-signing-key.key",
        "device-signing-key.key.new",
        "config.json",
        "config.json.new",
        "enrolment.json",
        "enrolment.json.new",
    ] {
        assert!(
            hooks.contains(&format!("Delete \"${{AICOUNTLY_DATA_DIR}}\\{file}\"")),
            "the uninstaller must remove {file}"
        );
    }

    assert_eq!(
        remote_security::DEVICE_KEY_ENTRY,
        "device-signing-key",
        "the key entry name is half of the file name above"
    );
}

/// The log directory goes as well. It was the one thing the uninstaller left
/// in the data directory, so the `RMDir` of the directory itself — which only
/// removes an empty one — failed, and `%ProgramData%\AICOUNTLY\Remote`
/// outlived the uninstall.
///
/// And not with `RMDir /r`, the obvious line: the uninstaller runs elevated in
/// a directory every account on the machine can write in, and NSIS's recursive
/// delete walks into a junction as though it were a folder.
#[test]
fn the_uninstaller_removes_the_log_directory_without_walking_into_links() {
    let hooks = read("installers/windows/hooks.nsh");

    // `remote_core::logging`: one file per component, rolled once to `.1`.
    for component in ["agent", "service"] {
        if let Some(path) = remote_core::log_path(component) {
            assert!(
                path.ends_with(Path::new("logs").join(format!("{component}.log"))),
                "{component} logs to {}, which the uninstaller does not name",
                path.display()
            );
        }

        for file in [format!("{component}.log"), format!("{component}.log.1")] {
            assert!(
                hooks.contains(&format!("Delete \"${{AICOUNTLY_DATA_DIR}}\\logs\\{file}\"")),
                "the uninstaller must remove logs\\{file}"
            );
        }
    }

    let logs = hooks
        .find("RMDir \"${AICOUNTLY_DATA_DIR}\\logs\"")
        .expect("the uninstaller removes the log directory");
    let data = hooks
        .find("RMDir \"${AICOUNTLY_DATA_DIR}\"")
        .expect("the uninstaller removes the data directory");
    assert!(
        logs < data,
        "the log directory has to go before the directory it is in"
    );

    for line in hooks.lines().map(str::trim) {
        if line.starts_with(';') {
            continue;
        }

        let mut words = line.split_whitespace();
        let recursive = words
            .next()
            .is_some_and(|word| word.eq_ignore_ascii_case("RMDir"))
            && words.any(|word| word.eq_ignore_ascii_case("/r"));

        assert!(
            !recursive,
            "no recursive delete in an elevated uninstaller: {line}"
        );
    }
}

/// One version, in `desktop/Cargo.toml`'s `workspace.package`. The release
/// workflow checks this too, but a mismatch found by a test is a mismatch
/// found before somebody starts a release.
#[test]
fn the_version_is_the_same_everywhere() {
    let workspace = read("Cargo.toml");
    let version = workspace
        .lines()
        .map(str::trim)
        .find_map(|line| line.strip_prefix("version = "))
        .map(|value| value.trim().trim_matches('"').to_owned())
        .expect("the workspace declares a version");

    assert_eq!(version, aicountly_remote_desktop::VERSION);
    assert!(
        read("src-tauri/tauri.conf.json").contains(&format!("\"version\": \"{version}\"")),
        "tauri.conf.json must agree with the workspace version"
    );
    assert!(
        read("package.json").contains(&format!("\"version\": \"{version}\"")),
        "package.json must agree with the workspace version"
    );
}
