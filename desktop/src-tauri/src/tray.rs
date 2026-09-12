//! The tray icon and its menu.
//!
//! # Why the tray is not decoration
//!
//! It is where the "a running session is always visible" promise is kept when
//! the window is closed. A person who closed the window and walked away must
//! still be able to see that somebody is connected, and stop them, without
//! finding the window first.
//!
//! # Three different things, spelled differently
//!
//! The menu deliberately distinguishes:
//!
//! * **Close window** — the window goes away, the agent keeps running, the
//!   machine stays reachable. What clicking the X does.
//! * **Quit AICOUNTLY Remote** — this process exits. The background service
//!   keeps running, so an administrator-enabled unattended device is *still*
//!   reachable, and the menu says so rather than letting somebody believe
//!   quitting the UI made their machine private.
//! * **Turn off unattended access** — the actual thing somebody who wants
//!   their machine to stop being reachable is looking for.
//!
//! Collapsing those three into one "Quit" is how a person ends up believing
//! they have switched something off that is still on.

use remote_core::{AgentState, AgentStatus};

/// The menu item ids, in one place so the handler and the builder agree.
pub mod ids {
    /// Show the window.
    pub const OPEN: &str = "open";
    /// The device's status. Not clickable.
    pub const STATUS: &str = "status";
    /// The session's status. Not clickable.
    pub const SESSION: &str = "session";
    /// Stop the person controlling this machine.
    pub const STOP_CONTROL: &str = "stop-control";
    /// End the running session.
    pub const END_SESSION: &str = "end-session";
    /// Unattended access, and switching it off.
    pub const UNATTENDED: &str = "unattended";
    /// Settings.
    pub const SETTINGS: &str = "settings";
    /// Hide the window, keep running.
    pub const CLOSE_WINDOW: &str = "close-window";
    /// Exit this process.
    pub const QUIT: &str = "quit";
}

/// One line in the tray menu.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct MenuLine {
    /// The item's id.
    pub id: &'static str,
    /// What it says.
    pub label: String,
    /// Whether it does anything.
    pub enabled: bool,
}

impl MenuLine {
    fn action(id: &'static str, label: impl Into<String>) -> Self {
        Self {
            id,
            label: label.into(),
            enabled: true,
        }
    }

    fn info(id: &'static str, label: impl Into<String>) -> Self {
        Self {
            id,
            label: label.into(),
            enabled: false,
        }
    }
}

/// Build the menu for a given state.
///
/// Pure, so what the tray offers is tested rather than assumed — including the
/// part that matters most: that Stop control is there whenever somebody is
/// controlling the machine.
#[must_use]
pub fn menu_for(state: &AgentState) -> Vec<MenuLine> {
    let mut lines = vec![MenuLine::action(ids::OPEN, "Open AICOUNTLY Remote")];

    lines.push(MenuLine::info(
        ids::STATUS,
        match &state.status {
            AgentStatus::NotEnrolled => "This device is not registered".to_owned(),
            AgentStatus::Authenticating { .. } => "Connecting…".to_owned(),
            AgentStatus::Offline { reason, .. } => format!("Offline — {reason}"),
            AgentStatus::Online | AgentStatus::InSession(_) => match &state.device_name {
                Some(name) => format!("{name} — online"),
                None => "Online".to_owned(),
            },
            AgentStatus::Revoked => "Removed by an administrator".to_owned(),
        },
    ));

    // The session line, and the two controls that go with it. Present exactly
    // when there is a session, so the tray can never say idle while a screen
    // is being shared.
    if let Some(session) = state.active_session() {
        lines.push(MenuLine::info(
            ids::SESSION,
            format!(
                "Session active — {}{}",
                session.connected_name,
                if session.unattended {
                    " (unattended)"
                } else {
                    ""
                }
            ),
        ));

        if state.is_being_controlled() {
            lines.push(MenuLine::action(ids::STOP_CONTROL, "Stop control"));
        }

        lines.push(MenuLine::action(ids::END_SESSION, "End session"));
    } else {
        lines.push(MenuLine::info(ids::SESSION, "No session is running"));
    }

    // Unattended access is named as itself, and switching it off is a
    // different item from quitting.
    if state.unattended.enabled {
        lines.push(MenuLine::action(
            ids::UNATTENDED,
            "Unattended access is ON — turn it off",
        ));
    } else if state.is_enrolled() {
        lines.push(MenuLine::info(ids::UNATTENDED, "Unattended access is off"));
    }

    lines.push(MenuLine::action(ids::SETTINGS, "Settings"));
    lines.push(MenuLine::action(ids::CLOSE_WINDOW, "Close window"));

    // The wording carries the consequence, because "Quit" on its own reads as
    // "stop being reachable" and is not.
    lines.push(MenuLine::action(
        ids::QUIT,
        if state.unattended.enabled {
            "Quit AICOUNTLY Remote (this device stays reachable)"
        } else {
            "Quit AICOUNTLY Remote"
        },
    ));

    lines
}

/// The tooltip, which is the whole status in one line.
#[must_use]
pub fn tooltip(state: &AgentState) -> String {
    state.tray_summary()
}

#[cfg(not(test))]
pub use imp::{install, show_window};

#[cfg(test)]
pub use stub::{install, show_window};

#[cfg(test)]
mod stub {
    //! The tray cannot be built without a running Tauri application, and the
    //! part worth testing is `menu_for`, which is pure.

    /// Does nothing under test; the tray needs a running application.
    pub fn install(_app: &tauri::AppHandle) -> Result<(), Box<dyn std::error::Error>> {
        Ok(())
    }

    /// Does nothing under test.
    pub fn show_window(_app: &tauri::AppHandle) {}
}

#[cfg(not(test))]
mod imp {
    use super::{ids, menu_for, tooltip};
    use std::sync::Arc;
    use tauri::menu::{MenuBuilder, MenuItemBuilder};
    use tauri::tray::TrayIconBuilder;
    use tauri::Manager;

    use crate::Agent;

    /// Build the tray icon and its menu.
    pub fn install(app: &tauri::AppHandle) -> Result<(), Box<dyn std::error::Error>> {
        let agent = app.state::<Arc<Agent>>();
        let state = agent.state();

        let mut builder = MenuBuilder::new(app);

        for line in menu_for(&state) {
            builder = builder.item(
                &MenuItemBuilder::with_id(line.id, &line.label)
                    .enabled(line.enabled)
                    .build(app)?,
            );
        }

        let menu = builder.build()?;

        TrayIconBuilder::with_id("aicountly-remote")
            .icon(app.default_window_icon().cloned().ok_or("no window icon")?)
            .tooltip(tooltip(&state))
            .menu(&menu)
            .on_menu_event(move |app, event| handle(app, event.id().as_ref()))
            .build(app)?;

        Ok(())
    }

    /// Bring the window back.
    pub fn show_window(app: &tauri::AppHandle) {
        if let Some(window) = app.get_webview_window("main") {
            let _ = window.show();
            let _ = window.unminimize();
            let _ = window.set_focus();
        }
    }

    fn handle(app: &tauri::AppHandle, id: &str) {
        let agent = app.state::<Arc<Agent>>();

        match id {
            ids::OPEN | ids::SETTINGS => {
                show_window(app);

                if id == ids::SETTINGS {
                    // The window routes itself; the tray only asks.
                    let _ = app.emit("aicountly-remote://navigate", "settings");
                }
            }

            // Stop control from the tray, without finding the window first.
            // This is the same local, immediate revocation the window's button
            // performs — it does not wait for the API.
            ids::STOP_CONTROL => {
                let state = agent.stop_control();
                let _ = app.emit("aicountly-remote://state", state);
            }

            ids::END_SESSION => {
                if let Some(session) = agent.state().active_session() {
                    let _ = crate::ipc::session_ended(&session.session_uuid);
                }

                let state = agent.end_session();
                let _ = app.emit("aicountly-remote://state", state);
            }

            // Switching unattended access off is the web layer's call to make,
            // because it has to reach the API as well. The tray asks for it.
            ids::UNATTENDED => {
                show_window(app);
                let _ = app.emit("aicountly-remote://navigate", "unattended");
            }

            ids::CLOSE_WINDOW => {
                if let Some(window) = app.get_webview_window("main") {
                    let _ = window.hide();
                }
            }

            ids::QUIT => app.exit(0),

            _ => {}
        }
    }

    use tauri::Emitter;
}

#[cfg(test)]
mod tests {
    use super::*;
    use remote_core::{AgentEvent, ControlStateView, SessionSummary, UnattendedState};

    fn session(unattended: bool) -> SessionSummary {
        SessionSummary {
            session_uuid: "s".into(),
            display_id: "AR-10282".into(),
            connected_name: "Sam in support".into(),
            company_name: Some("Northwind".into()),
            started_at: "2026-02-10T09:00:00Z".into(),
            unattended,
            control: remote_core::ControlSummary {
                state: ControlStateView::None,
                clipboard: false,
            },
        }
    }

    fn enrolled() -> AgentState {
        AgentState::not_enrolled("1.0.0")
            .apply(AgentEvent::Enrolled {
                device_uuid: "d".into(),
                device_name: "WS-01".into(),
                company_name: Some("Northwind".into()),
                key_fingerprint: "AAAA".into(),
            })
            .apply(AgentEvent::Authenticated)
    }

    fn labels(state: &AgentState) -> Vec<String> {
        menu_for(state).into_iter().map(|line| line.label).collect()
    }

    fn has_action(state: &AgentState, id: &str) -> bool {
        menu_for(state)
            .into_iter()
            .any(|line| line.id == id && line.enabled)
    }

    /// The tray can never say idle while a screen is being shared.
    #[test]
    fn a_running_session_always_appears_in_the_tray() {
        let state = enrolled().apply(AgentEvent::SessionStarted(session(false)));

        assert!(labels(&state)
            .iter()
            .any(|label| label.contains("Session active")));
        assert!(labels(&state)
            .iter()
            .any(|label| label.contains("Sam in support")));
        assert!(has_action(&state, ids::END_SESSION));
    }

    /// An unattended session says so, because "somebody connected while you
    /// were away" is materially different from "you let somebody in".
    #[test]
    fn an_unattended_session_is_labelled_as_one() {
        let state = enrolled().apply(AgentEvent::SessionStarted(session(true)));

        assert!(labels(&state)
            .iter()
            .any(|label| label.contains("(unattended)")));
    }

    /// The whole point of the tray: stopping somebody controlling the machine
    /// without having to find the window first.
    #[test]
    fn stop_control_is_offered_whenever_somebody_is_controlling() {
        let idle = enrolled().apply(AgentEvent::SessionStarted(session(false)));
        assert!(!has_action(&idle, ids::STOP_CONTROL));

        let controlled = idle.apply(AgentEvent::ControlChanged {
            state: ControlStateView::Granted,
            clipboard: false,
        });

        assert!(has_action(&controlled, ids::STOP_CONTROL));
    }

    /// Collapsing these into one "Quit" is how somebody ends up believing they
    /// switched something off that is still on.
    #[test]
    fn closing_the_window_quitting_and_disabling_unattended_are_three_things() {
        let state = enrolled().apply(AgentEvent::UnattendedChanged(UnattendedState {
            enabled: true,
            enabled_at: Some("2026-02-10T08:00:00Z".into()),
            last_used_at: None,
            allowed_by_policy: true,
        }));

        assert!(has_action(&state, ids::CLOSE_WINDOW));
        assert!(has_action(&state, ids::QUIT));
        assert!(has_action(&state, ids::UNATTENDED));

        // …and quitting says what it does not do.
        let quit = menu_for(&state)
            .into_iter()
            .find(|line| line.id == ids::QUIT)
            .expect("has a quit item");

        assert!(quit.label.contains("stays reachable"));
    }

    #[test]
    fn quitting_says_nothing_about_reachability_when_unattended_access_is_off() {
        let state = enrolled();

        let quit = menu_for(&state)
            .into_iter()
            .find(|line| line.id == ids::QUIT)
            .expect("has a quit item");

        assert_eq!(quit.label, "Quit AICOUNTLY Remote");
    }

    #[test]
    fn an_unregistered_machine_says_so_and_offers_nothing_it_cannot_do() {
        let state = AgentState::not_enrolled("1.0.0");

        assert!(labels(&state)
            .iter()
            .any(|label| label.contains("not registered")));
        assert!(!has_action(&state, ids::STOP_CONTROL));
        assert!(!has_action(&state, ids::END_SESSION));
        assert!(!has_action(&state, ids::UNATTENDED));
    }

    #[test]
    fn a_revoked_device_says_what_happened_to_it() {
        let state = enrolled().apply(AgentEvent::Revoked);

        assert!(labels(&state)
            .iter()
            .any(|label| label.contains("Removed by an administrator")));
        assert!(tooltip(&state).contains("removed"));
    }

    #[test]
    fn the_tooltip_is_the_whole_status_in_one_line() {
        let state = enrolled()
            .apply(AgentEvent::SessionStarted(session(false)))
            .apply(AgentEvent::ControlChanged {
                state: ControlStateView::Granted,
                clipboard: false,
            });

        let tooltip = tooltip(&state);

        assert!(tooltip.contains("session active"));
        assert!(tooltip.contains("controlling"));
    }
}
