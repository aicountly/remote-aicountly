//! `AicountlyRemote.exe` — the user-session half of the Windows agent.
//!
//! Everything a person sees and everything that touches the desktop they are
//! looking at: the window, the tray, the screen capture, the input injection,
//! the clipboard, and the WebRTC session. The machine's own lifecycle — device
//! identity, presence, restart — belongs to the service, which runs in
//! Session 0 and has no desktop. See `agents/windows-service`.
//!
//! # The property the whole application is built around
//!
//! > **A running session is always visible, and can always be stopped from
//! > here.**
//!
//! There is no state in which the agent is sharing a screen or accepting input
//! without the window and the tray saying so, because both render from one
//! [`AgentState`] and `is_session_active()` is derived from its status rather
//! than stored beside it. And `stop_control` needs no permission and no
//! network round trip to take effect — [`ControlGate::revoke`] is local, and
//! the next input event is dropped whatever the server thinks.

#![deny(missing_docs)]

pub mod agent;
pub mod commands;
pub mod ipc;
pub mod platform;
pub mod tray;

pub use agent::{Agent, AgentError};

use remote_core::AGENT_VERSION;

/// The product name, as a person sees it.
pub const PRODUCT_NAME: &str = "AICOUNTLY Remote";

/// This build's version. One authoritative value — see `desktop/Cargo.toml`.
pub const VERSION: &str = AGENT_VERSION;

/// Build and run the Tauri application.
///
/// Kept out of `main.rs` so `main.rs` is four lines and everything testable is
/// in the library.
#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tracing_subscriber::fmt()
        .with_env_filter(
            tracing_subscriber::EnvFilter::try_from_env("AICOUNTLY_REMOTE_LOG")
                .unwrap_or_else(|_| tracing_subscriber::EnvFilter::new("info")),
        )
        // No timestamps in the message body and no ANSI: this goes to a file
        // a support engineer reads.
        .with_ansi(false)
        .init();

    let agent = std::sync::Arc::new(agent::Agent::new());

    tauri::Builder::default()
        // One instance. A second copy of the agent would mean two tray icons,
        // two presence connections claiming the same device, and two processes
        // each believing it owns the session.
        .plugin(tauri_plugin_single_instance::init(|app, _argv, _cwd| {
            tray::show_window(app);
        }))
        .plugin(tauri_plugin_shell::init())
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_updater::Builder::new().build())
        .manage(agent)
        .invoke_handler(tauri::generate_handler![
            commands::get_state,
            commands::get_permissions,
            commands::get_configuration,
            commands::save_configuration,
            commands::enrol_device,
            commands::unregister_device,
            commands::enable_unattended,
            commands::disable_unattended,
            commands::grant_control,
            commands::deny_control,
            commands::stop_control,
            commands::end_session,
            commands::open_url,
            commands::about,
        ])
        .setup(|app| {
            tray::install(app.handle())?;

            Ok(())
        })
        .on_window_event(|window, event| {
            // Closing the window hides it; it does not quit. The tray menu
            // spells the difference out — "Close window" and "Quit AICOUNTLY
            // Remote" are separate items — because quitting the UI on a
            // machine an administrator enabled unattended access for is a
            // decision, not an accident.
            if let tauri::WindowEvent::CloseRequested { api, .. } = event {
                api.prevent_close();
                let _ = window.hide();
            }
        })
        .run(tauri::generate_context!())
        .expect("AICOUNTLY Remote failed to start");
}
