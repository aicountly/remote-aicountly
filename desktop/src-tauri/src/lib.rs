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
pub mod companion;
pub mod ipc;
pub mod platform;
pub mod runtime;
pub mod session;
pub mod tray;

pub use agent::{Agent, AgentError};

use remote_core::AGENT_VERSION;

/// The product name, as a person sees it.
pub const PRODUCT_NAME: &str = "AICOUNTLY Remote";

/// This build's version. One authoritative value — see `desktop/Cargo.toml`.
pub const VERSION: &str = AGENT_VERSION;

/// The argument the installer's sign-in `Run` entry starts the agent with
/// (`installers/windows/hooks.nsh`).
pub const BACKGROUND_ARGUMENT: &str = "--background";

/// Stop control from anywhere: a system-wide shortcut, registered by the Rust
/// side alone, that revokes control locally exactly as the Stop button does.
pub const STOP_CONTROL_SHORTCUT: &str = "CommandOrControl+Alt+Shift+S";

/// [`STOP_CONTROL_SHORTCUT`], the way this platform writes it.
#[must_use]
pub fn stop_shortcut_label() -> &'static str {
    if cfg!(target_os = "macos") {
        "⌘⌥⇧S"
    } else {
        "Ctrl+Alt+Shift+S"
    }
}

/// Whether a state change should bring the window forward: a companion link
/// waiting for an answer, or a monitor to pick — each once, when it first
/// appears. `last` is the previous answer's key.
#[must_use]
pub fn needs_attention(state: &remote_core::AgentState, last: &mut Option<String>) -> bool {
    use remote_core::CompanionPhase;

    let key = state
        .active_companion()
        .filter(|companion| {
            matches!(
                companion.phase,
                CompanionPhase::AwaitingConfirmation | CompanionPhase::ChoosingMonitor
            ) && !(companion.phase == CompanionPhase::ChoosingMonitor
                && companion.monitors.is_empty())
        })
        .map(|companion| format!("{}:{:?}", companion.companion_uuid, companion.phase));

    let raise = key.is_some() && key != *last;
    *last = key;

    raise
}

/// Whether the agent was started in the background — by the sign-in `Run`
/// entry — rather than by a person opening it.
///
/// A background start goes to the tray without putting a window in front of
/// whoever just signed in; every other start shows the window. Only the exact
/// argument counts, and the program name (the first item) never does.
#[must_use]
pub fn is_background_launch<I>(arguments: I) -> bool
where
    I: IntoIterator,
    I::Item: AsRef<std::ffi::OsStr>,
{
    arguments
        .into_iter()
        .skip(1)
        .any(|argument| argument.as_ref() == std::ffi::OsStr::new(BACKGROUND_ARGUMENT))
}

/// Whether closing the window hides it (true) or lets the application exit.
///
/// The setting decides — except that a running session always keeps the
/// application, and with it the tray icon and the way to stop control, alive.
#[must_use]
pub fn keep_running_after_close(run_in_background: bool, session_active: bool) -> bool {
    run_in_background || session_active
}

/// Build and run the Tauri application.
///
/// Kept out of `main.rs` so `main.rs` is four lines and everything testable is
/// in the library.
#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    // To a file, and to standard error. A release build has
    // `windows_subsystem = "windows"` and therefore no console at all, so a
    // subscriber that only wrote to standard output was a subscriber whose
    // every line Windows discarded — see `remote_core::logging`.
    remote_core::logging::init("agent");

    let agent = std::sync::Arc::new(agent::Agent::load());

    let builder = tauri::Builder::default();

    // Stop control from anywhere. Registered only where there is something to
    // stop; a shortcut that cannot be registered (another application holds
    // it) costs the shortcut, never the application.
    #[cfg(any(target_os = "windows", target_os = "macos"))]
    let builder =
        match tauri_plugin_global_shortcut::Builder::new().with_shortcut(STOP_CONTROL_SHORTCUT) {
            Ok(shortcuts) => builder.plugin(
                shortcuts
                    .with_handler(|app, _shortcut, event| {
                        use tauri::Manager;

                        if event.state() != tauri_plugin_global_shortcut::ShortcutState::Pressed {
                            return;
                        }

                        let agent = app.state::<std::sync::Arc<Agent>>();

                        if agent.state().is_being_controlled() {
                            let state = agent.stop_control();
                            tray::refresh(app, &state);
                            let _ = tauri::Emitter::emit(app, "aicountly-remote://state", state);
                        }
                    })
                    .build(),
            ),
            Err(error) => {
                tracing::warn!(%error, "the Stop control shortcut could not be registered");

                builder
            }
        };

    builder
        // One instance. A second copy of the agent would mean two tray icons,
        // two presence connections claiming the same device, and two processes
        // each believing it owns the session.
        .plugin(tauri_plugin_single_instance::init(|app, argv, _cwd| {
            // A second sign-in start (`--background`) must not pull the window
            // up over what the person is doing; a person opening the agent
            // again must.
            if !is_background_launch(argv) {
                tray::show_window(app);
            }
        }))
        .plugin(tauri_plugin_shell::init())
        .plugin(tauri_plugin_dialog::init())
        .manage(agent)
        .invoke_handler(tauri::generate_handler![
            commands::get_state,
            commands::get_permissions,
            commands::get_configuration,
            commands::save_configuration,
            commands::enrol_device,
            commands::confirm_enrolment,
            commands::unregister_device,
            commands::enable_unattended,
            commands::disable_unattended,
            commands::grant_control,
            commands::deny_control,
            commands::stop_control,
            commands::end_session,
            commands::allow_companion,
            commands::decline_companion,
            commands::choose_companion_monitor,
            commands::leave_companion,
            commands::request_permission,
            commands::identify_monitors,
            commands::open_url,
            commands::about,
        ])
        .setup(|app| {
            use tauri::Manager;

            tray::install(app.handle())?;

            // The main window is created hidden (tauri.conf.json). The sign-in
            // `Run` entry passes `--background`, and that start belongs in the
            // tray; any other start is a person opening the agent.
            if !is_background_launch(std::env::args_os()) {
                tray::show_window(app.handle());
            }

            // The connection loop: authenticate, stay reachable, and notice
            // what changed on the server. Its handle lives in Tauri's managed
            // state so it is dropped — and the loop stops — when the
            // application does.
            let agent = app.state::<std::sync::Arc<Agent>>().inner().clone();
            let handle = app.handle().clone();
            let attention = std::sync::Mutex::new(None::<String>);

            app.manage(runtime::start(
                agent,
                std::sync::Arc::new(move |state: remote_core::AgentState| {
                    // A question for the person at this machine — a companion
                    // link to allow, a monitor to pick — brings the window
                    // forward once, rather than waiting in a hidden window.
                    if let Ok(mut last) = attention.lock() {
                        if needs_attention(&state, &mut last) {
                            tray::show_window(&handle);
                        }
                    }

                    tray::refresh(&handle, &state);

                    // One event, one payload, the same one the tray and the
                    // window both render from — so there is no path by which
                    // the two disagree about whether a session is running.
                    let _ = tauri::Emitter::emit(&handle, "aicountly-remote://state", state);
                }),
                remote_webrtc::webrtc_rs::WebRtcFactory,
            ));

            Ok(())
        })
        .on_window_event(|window, event| {
            // Closing the window hides it; it does not quit. The tray menu
            // spells the difference out — "Close window" and "Quit AICOUNTLY
            // Remote" are separate items — because quitting the UI on a
            // machine an administrator enabled unattended access for is a
            // decision, not an accident.
            //
            // "Keep running when the window is closed" in Settings turns that
            // off, but never while a session is running: the window and the
            // tray are how a person sees and stops it.
            if let tauri::WindowEvent::CloseRequested { api, .. } = event {
                use tauri::Manager;

                let agent = window.app_handle().state::<std::sync::Arc<Agent>>();

                if keep_running_after_close(
                    agent.config().run_in_background,
                    agent.state().is_session_active(),
                ) {
                    api.prevent_close();
                    let _ = window.hide();
                }
            }
        })
        .run(tauri::generate_context!())
        .expect("AICOUNTLY Remote failed to start");
}

#[cfg(test)]
mod tests {
    use super::{is_background_launch, keep_running_after_close, needs_attention};
    use remote_core::{AgentEvent, AgentState, CompanionPhase, CompanionSummary};

    fn asked() -> AgentState {
        AgentState::not_enrolled("1.0.0")
            .apply(AgentEvent::Enrolled {
                device_uuid: "d".into(),
                device_name: "Mac".into(),
                company_name: None,
                key_fingerprint: "AAAA".into(),
            })
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::CompanionRequested(CompanionSummary {
                companion_uuid: "companion-1".into(),
                session_uuid: "s".into(),
                display_id: "123-456".into(),
                company_name: None,
                requested_by_name: None,
                expires_at: None,
                started_at: None,
                phase: CompanionPhase::AwaitingConfirmation,
                monitors: Vec::new(),
                monitor: None,
                controller_name: None,
            }))
    }

    /// The window comes forward once per question, not on every poll that
    /// repeats it.
    #[test]
    fn a_companion_question_raises_the_window_once() {
        let mut last = None;

        assert!(needs_attention(&asked(), &mut last));
        assert!(
            !needs_attention(&asked(), &mut last),
            "the same question again"
        );

        let idle = asked().apply(AgentEvent::CompanionDismissed {
            companion_uuid: "companion-1".into(),
        });
        assert!(!needs_attention(&idle, &mut last));
        assert!(needs_attention(&asked(), &mut last), "asked again later");
    }

    #[test]
    fn closing_the_window_exits_only_when_the_setting_is_off_and_nothing_is_running() {
        assert!(keep_running_after_close(true, false));
        assert!(keep_running_after_close(true, true));
        assert!(keep_running_after_close(false, true));
        assert!(!keep_running_after_close(false, false));
    }

    #[test]
    fn the_sign_in_run_entry_starts_in_the_background() {
        assert!(is_background_launch([
            "AICOUNTLY Remote.exe",
            "--background"
        ]));
    }

    #[test]
    fn a_plain_start_shows_the_window() {
        assert!(!is_background_launch(["AICOUNTLY Remote.exe"]));
        assert!(!is_background_launch(Vec::<String>::new()));
    }

    #[test]
    fn only_the_exact_argument_counts() {
        assert!(!is_background_launch([
            "AICOUNTLY Remote.exe",
            "--background=false"
        ]));
        assert!(!is_background_launch([
            "AICOUNTLY Remote.exe",
            "background"
        ]));
        assert!(!is_background_launch([
            "AICOUNTLY Remote.exe",
            "--Background"
        ]));
    }

    #[test]
    fn the_program_name_is_never_an_argument() {
        assert!(!is_background_launch(["--background"]));
    }
}
