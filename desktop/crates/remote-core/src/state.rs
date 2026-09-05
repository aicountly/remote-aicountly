//! What the agent is doing, as a state machine.
//!
//! The tray icon, the window, the status line and the decision about whether
//! to accept an incoming session all read from one [`AgentState`]. Having a
//! single machine rather than a scatter of booleans is what makes "is a
//! session running?" answerable in one place — and what makes it impossible
//! for the tray to say idle while a screen is being shared.
//!
//! # The property this file exists to hold
//!
//! > **A running session is always visible.**
//!
//! There is no transition into a session that does not also make the indicator
//! true, because they are the same value. `is_session_active()` is derived
//! from the status, not stored beside it, so no code path can set one without
//! the other.

use remote_protocol::ControlState;
use serde::{Deserialize, Serialize};

/// What the agent is doing right now.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(tag = "status", rename_all = "snake_case")]
pub enum AgentStatus {
    /// No device key on this machine. Nothing can happen until somebody
    /// signs in and registers it.
    NotEnrolled,

    /// Enrolled, but with no valid device credential — starting up, or the
    /// last authentication failed.
    Authenticating {
        /// How many attempts have failed in a row.
        attempt: u32,
    },

    /// Enrolled and authenticated, with the presence connection down.
    Offline {
        /// What the interface says about it.
        reason: String,
        /// Whether trying again could help.
        retryable: bool,
    },

    /// Enrolled, authenticated, reachable, and nothing happening.
    Online,

    /// A session is running. **The indicator is on whenever this is the state.**
    InSession(SessionSummary),

    /// The device was revoked by an administrator. A terminal state: the agent
    /// stops trying, says so, and waits to be enrolled again.
    Revoked,
}

/// The session the agent is hosting, as the UI shows it.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct SessionSummary {
    /// The session's public identifier.
    pub session_uuid: String,
    /// `AR-10282` — the label to read out loud.
    pub display_id: String,
    /// Who is connected. A person, so the person at the machine knows who.
    pub connected_name: String,
    /// Which organisation the session belongs to.
    pub company_name: Option<String>,
    /// When it started, ISO-8601 UTC.
    pub started_at: String,
    /// Whether the person connected got here through unattended access.
    ///
    /// Shown in the indicator, because "somebody connected while you were
    /// away" is materially different from "you let somebody in".
    pub unattended: bool,
    /// Where control stands.
    pub control: ControlSummary,
}

/// Control, for the interface.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct ControlSummary {
    /// Nobody has asked / asked / granted / refused / withdrawn.
    pub state: ControlStateView,
    /// Whether the clipboard is being synchronised.
    pub clipboard: bool,
}

/// [`ControlState`], in a form that crosses the Tauri boundary.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum ControlStateView {
    /// Nobody has asked.
    #[default]
    None,
    /// Somebody has asked; the person at the machine has not answered.
    Requested,
    /// Granted.
    Granted,
    /// Refused.
    Denied,
    /// Withdrawn.
    Revoked,
}

impl From<ControlState> for ControlStateView {
    fn from(state: ControlState) -> Self {
        match state {
            ControlState::None => Self::None,
            ControlState::Requested => Self::Requested,
            ControlState::Granted => Self::Granted,
            ControlState::Denied => Self::Denied,
            ControlState::Revoked => Self::Revoked,
        }
    }
}

/// Whether this machine can be reached with nobody at it, and who said so.
#[derive(Debug, Clone, PartialEq, Eq, Default, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct UnattendedState {
    /// On or off.
    pub enabled: bool,
    /// When it was switched on, ISO-8601 UTC.
    pub enabled_at: Option<String>,
    /// When somebody last connected this way.
    pub last_used_at: Option<String>,
    /// Whether the organisation permits it at all — so the UI can say
    /// "not available for your organisation" rather than showing a switch
    /// that always refuses.
    pub allowed_by_policy: bool,
}

/// Everything the interface renders from.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct AgentState {
    /// What the agent is doing.
    pub status: AgentStatus,
    /// The device's name, once enrolled.
    pub device_name: Option<String>,
    /// The device's uuid, once enrolled.
    pub device_uuid: Option<String>,
    /// Which organisation it belongs to.
    pub company_name: Option<String>,
    /// The public key fingerprint, so a person can compare it with the console.
    pub key_fingerprint: Option<String>,
    /// Unattended access.
    pub unattended: UnattendedState,
    /// This build's version.
    pub agent_version: String,
    /// The last few sessions, for the home screen.
    pub recent_sessions: Vec<SessionSummary>,
}

impl AgentState {
    /// A machine that has never been enrolled.
    #[must_use]
    pub fn not_enrolled(agent_version: impl Into<String>) -> Self {
        Self {
            status: AgentStatus::NotEnrolled,
            device_name: None,
            device_uuid: None,
            company_name: None,
            key_fingerprint: None,
            unattended: UnattendedState::default(),
            agent_version: agent_version.into(),
            recent_sessions: Vec::new(),
        }
    }

    /// **Whether a session is running.**
    ///
    /// Derived from the status rather than stored beside it, so there is no
    /// way to be in a session without the indicator being on. This is the one
    /// function the "no hidden remote control" property rests on.
    #[must_use]
    pub fn is_session_active(&self) -> bool {
        matches!(self.status, AgentStatus::InSession(_))
    }

    /// The running session, if there is one.
    #[must_use]
    pub fn active_session(&self) -> Option<&SessionSummary> {
        match &self.status {
            AgentStatus::InSession(session) => Some(session),
            _ => None,
        }
    }

    /// Whether somebody is controlling this machine right now.
    #[must_use]
    pub fn is_being_controlled(&self) -> bool {
        self.active_session()
            .is_some_and(|session| session.control.state == ControlStateView::Granted)
    }

    /// Whether the agent is enrolled at all.
    #[must_use]
    pub fn is_enrolled(&self) -> bool {
        !matches!(self.status, AgentStatus::NotEnrolled)
    }

    /// Whether the machine is reachable.
    #[must_use]
    pub fn is_online(&self) -> bool {
        matches!(self.status, AgentStatus::Online | AgentStatus::InSession(_))
    }

    /// The one line the tray tooltip shows.
    #[must_use]
    pub fn tray_summary(&self) -> String {
        match &self.status {
            AgentStatus::NotEnrolled => "AICOUNTLY Remote — not registered".into(),
            AgentStatus::Authenticating { .. } => "AICOUNTLY Remote — connecting".into(),
            AgentStatus::Offline { reason, .. } => format!("AICOUNTLY Remote — offline: {reason}"),
            AgentStatus::Online => "AICOUNTLY Remote — online".into(),
            AgentStatus::Revoked => "AICOUNTLY Remote — this device was removed".into(),
            AgentStatus::InSession(session) => format!(
                "AICOUNTLY Remote — session active with {}{}",
                session.connected_name,
                if session.control.state == ControlStateView::Granted {
                    " (controlling)"
                } else {
                    ""
                }
            ),
        }
    }

    /// Apply an event, returning the new state.
    ///
    /// A single `match` rather than a scatter of setters: every transition is
    /// visible in one place, and a transition that would be wrong — resuming
    /// a session on a revoked device, going online without being enrolled —
    /// has nowhere to be written.
    #[must_use]
    pub fn apply(mut self, event: AgentEvent) -> Self {
        self.status = match (self.status, event) {
            // Revocation is terminal. Nothing brings a revoked device back
            // except enrolling it again, which replaces the whole state.
            (AgentStatus::Revoked, AgentEvent::Enrolled { .. }) => AgentStatus::Authenticating { attempt: 0 },
            (AgentStatus::Revoked, _) => AgentStatus::Revoked,

            (_, AgentEvent::Revoked) => {
                self.unattended.enabled = false;
                self.unattended.enabled_at = None;

                AgentStatus::Revoked
            }

            (_, AgentEvent::Enrolled { device_uuid, device_name, company_name, key_fingerprint }) => {
                self.device_uuid = Some(device_uuid);
                self.device_name = Some(device_name);
                self.company_name = company_name;
                self.key_fingerprint = Some(key_fingerprint);

                AgentStatus::Authenticating { attempt: 0 }
            }

            (_, AgentEvent::EnrolmentRemoved) => {
                self.device_uuid = None;
                self.device_name = None;
                self.company_name = None;
                self.key_fingerprint = None;
                self.unattended = UnattendedState::default();

                AgentStatus::NotEnrolled
            }

            // Nothing happens on a machine that is not enrolled except
            // enrolling it.
            (AgentStatus::NotEnrolled, _) => AgentStatus::NotEnrolled,

            (_, AgentEvent::AuthenticationFailed { attempt }) => AgentStatus::Authenticating { attempt },
            (_, AgentEvent::Authenticated) => AgentStatus::Online,

            (_, AgentEvent::Disconnected { reason, retryable }) => AgentStatus::Offline { reason, retryable },
            (_, AgentEvent::Connected) => AgentStatus::Online,

            (_, AgentEvent::SessionStarted(session)) => AgentStatus::InSession(session),

            (AgentStatus::InSession(session), AgentEvent::ControlChanged { state, clipboard }) => {
                AgentStatus::InSession(SessionSummary {
                    control: ControlSummary { state, clipboard: clipboard && state == ControlStateView::Granted },
                    ..session
                })
            }
            // A control change with no session is not a state; ignore it
            // rather than inventing a session to hang it on.
            (status, AgentEvent::ControlChanged { .. }) => status,

            (AgentStatus::InSession(session), AgentEvent::SessionEnded) => {
                self.recent_sessions.insert(0, session);
                self.recent_sessions.truncate(10);

                AgentStatus::Online
            }
            (status, AgentEvent::SessionEnded) => status,

            (status, AgentEvent::UnattendedChanged(unattended)) => {
                self.unattended = unattended;

                status
            }
        };

        self
    }
}

/// Something that happened.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum AgentEvent {
    /// The machine was registered.
    Enrolled {
        /// The uuid the API issued.
        device_uuid: String,
        /// What it was called.
        device_name: String,
        /// Which organisation.
        company_name: Option<String>,
        /// The public key fingerprint.
        key_fingerprint: String,
    },
    /// The local enrolment was removed — the person unregistered this machine.
    EnrolmentRemoved,
    /// A device credential was obtained.
    Authenticated,
    /// Obtaining one failed.
    AuthenticationFailed {
        /// How many in a row.
        attempt: u32,
    },
    /// The presence connection came up.
    Connected,
    /// It went away.
    Disconnected {
        /// What the interface says.
        reason: String,
        /// Whether retrying could help.
        retryable: bool,
    },
    /// A session started.
    SessionStarted(SessionSummary),
    /// It ended.
    SessionEnded,
    /// Control was requested, granted, refused or withdrawn.
    ControlChanged {
        /// The new state.
        state: ControlStateView,
        /// Whether the clipboard is being synchronised.
        clipboard: bool,
    },
    /// Unattended access was switched on or off, here or in the console.
    UnattendedChanged(UnattendedState),
    /// An administrator revoked this device.
    Revoked,
}

#[cfg(test)]
mod tests {
    use super::*;

    fn session(unattended: bool) -> SessionSummary {
        SessionSummary {
            session_uuid: "session-uuid".into(),
            display_id: "AR-10282".into(),
            connected_name: "Sam in support".into(),
            company_name: Some("Northwind".into()),
            started_at: "2026-02-10T09:00:00Z".into(),
            unattended,
            control: ControlSummary { state: ControlStateView::None, clipboard: false },
        }
    }

    fn enrolled() -> AgentState {
        AgentState::not_enrolled("1.0.0").apply(AgentEvent::Enrolled {
            device_uuid: "device-uuid".into(),
            device_name: "WS-01".into(),
            company_name: Some("Northwind".into()),
            key_fingerprint: "AAAA BBBB CCCC DDDD".into(),
        })
    }

    /// The property the whole file exists for: there is no way to be in a
    /// session without the indicator being on, because they are the same value.
    #[test]
    fn a_running_session_is_always_visible() {
        let state = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::SessionStarted(session(false)));

        assert!(state.is_session_active());
        assert!(state.active_session().is_some());
        assert!(state.tray_summary().contains("session active"));
        assert!(state.tray_summary().contains("Sam in support"));
    }

    /// An unattended connection is still a visible session — the tray says so,
    /// and there is no state in which it does not.
    #[test]
    fn an_unattended_session_is_just_as_visible() {
        let state = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::SessionStarted(session(true)));

        assert!(state.is_session_active());
        assert!(state.active_session().unwrap().unattended);
        assert!(state.tray_summary().contains("session active"));
    }

    #[test]
    fn control_shows_in_the_tray_summary() {
        let state = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::SessionStarted(session(false)))
            .apply(AgentEvent::ControlChanged {
                state: ControlStateView::Granted,
                clipboard: true,
            });

        assert!(state.is_being_controlled());
        assert!(state.tray_summary().contains("controlling"));
        assert!(state.active_session().unwrap().control.clipboard);
    }

    /// Clipboard synchronisation cannot outlive the grant it belongs to.
    #[test]
    fn clipboard_falls_with_the_grant() {
        let state = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::SessionStarted(session(false)))
            .apply(AgentEvent::ControlChanged { state: ControlStateView::Granted, clipboard: true })
            .apply(AgentEvent::ControlChanged { state: ControlStateView::Revoked, clipboard: true });

        assert!(!state.is_being_controlled());
        assert!(!state.active_session().unwrap().control.clipboard);
    }

    #[test]
    fn a_finished_session_moves_into_the_recent_list() {
        let state = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::SessionStarted(session(false)))
            .apply(AgentEvent::SessionEnded);

        assert!(!state.is_session_active());
        assert_eq!(state.status, AgentStatus::Online);
        assert_eq!(state.recent_sessions.len(), 1);
    }

    #[test]
    fn the_recent_list_is_bounded() {
        let mut state = enrolled().apply(AgentEvent::Authenticated);

        for _ in 0..25 {
            state = state
                .apply(AgentEvent::SessionStarted(session(false)))
                .apply(AgentEvent::SessionEnded);
        }

        assert_eq!(state.recent_sessions.len(), 10);
    }

    /// Revocation is terminal. Nothing brings it back except enrolling again.
    #[test]
    fn revocation_is_terminal_and_takes_unattended_access_with_it() {
        let state = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::UnattendedChanged(UnattendedState {
                enabled: true,
                enabled_at: Some("2026-02-10T08:00:00Z".into()),
                last_used_at: None,
                allowed_by_policy: true,
            }))
            .apply(AgentEvent::Revoked);

        assert_eq!(state.status, AgentStatus::Revoked);
        assert!(!state.unattended.enabled);
        assert!(state.unattended.enabled_at.is_none());

        // Everything short of a fresh enrolment leaves it revoked.
        let still = state
            .clone()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::Connected)
            .apply(AgentEvent::SessionStarted(session(false)));

        assert_eq!(still.status, AgentStatus::Revoked);
        assert!(!still.is_session_active());

        let back = state.apply(AgentEvent::Enrolled {
            device_uuid: "new-uuid".into(),
            device_name: "WS-01".into(),
            company_name: None,
            key_fingerprint: "NEW".into(),
        });

        assert_eq!(back.status, AgentStatus::Authenticating { attempt: 0 });
    }

    /// Nothing happens on a machine that was never registered.
    #[test]
    fn an_unenrolled_machine_cannot_be_put_into_a_session() {
        let state = AgentState::not_enrolled("1.0.0")
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::Connected)
            .apply(AgentEvent::SessionStarted(session(false)));

        assert_eq!(state.status, AgentStatus::NotEnrolled);
        assert!(!state.is_session_active());
        assert!(!state.is_enrolled());
    }

    /// A control change with no session must not invent one to hang itself on.
    #[test]
    fn a_control_change_with_no_session_changes_nothing() {
        let state = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::ControlChanged {
                state: ControlStateView::Granted,
                clipboard: true,
            });

        assert_eq!(state.status, AgentStatus::Online);
        assert!(!state.is_being_controlled());
    }

    #[test]
    fn disconnecting_carries_the_reason_and_whether_to_retry() {
        let state = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::Disconnected {
                reason: "The network connection was lost.".into(),
                retryable: true,
            });

        assert!(!state.is_online());
        assert!(state.tray_summary().contains("offline"));

        match state.status {
            AgentStatus::Offline { retryable, .. } => assert!(retryable),
            other => panic!("expected Offline, got {other:?}"),
        }
    }

    #[test]
    fn unregistering_clears_every_trace_of_the_enrolment() {
        let state = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::EnrolmentRemoved);

        assert_eq!(state.status, AgentStatus::NotEnrolled);
        assert!(state.device_uuid.is_none());
        assert!(state.key_fingerprint.is_none());
        assert!(!state.unattended.enabled);
    }

    #[test]
    fn the_state_crosses_the_tauri_boundary_intact() {
        let state = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::SessionStarted(session(true)));

        let json = serde_json::to_string(&state).unwrap();
        let back: AgentState = serde_json::from_str(&json).unwrap();

        assert_eq!(back, state);
        assert!(back.is_session_active());
    }

    #[test]
    fn the_protocols_control_state_maps_across_without_a_gap() {
        assert_eq!(ControlStateView::from(ControlState::None), ControlStateView::None);
        assert_eq!(ControlStateView::from(ControlState::Requested), ControlStateView::Requested);
        assert_eq!(ControlStateView::from(ControlState::Granted), ControlStateView::Granted);
        assert_eq!(ControlStateView::from(ControlState::Denied), ControlStateView::Denied);
        assert_eq!(ControlStateView::from(ControlState::Revoked), ControlStateView::Revoked);
    }
}
