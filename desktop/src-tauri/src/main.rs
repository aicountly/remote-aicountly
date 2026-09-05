// The Windows subsystem, so launching the agent does not flash a console
// window. `cfg_attr` rather than a bare attribute: a debug build keeps its
// console, which is where `AICOUNTLY_REMOTE_LOG` output goes during
// development.
#![cfg_attr(
    all(not(debug_assertions), target_os = "windows"),
    windows_subsystem = "windows"
)]

fn main() {
    aicountly_remote_desktop::run();
}
