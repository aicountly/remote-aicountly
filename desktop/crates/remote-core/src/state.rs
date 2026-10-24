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

use remote_device::AgentCapabilities;
use remote_protocol::{ControlState, Monitor};
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

    /// This machine is its owner's control companion in a session they are
    /// sharing from the browser — or is asking them to confirm becoming one.
    ///
    /// From [`CompanionPhase::ChoosingMonitor`] on, the machine is in the
    /// session, and the indicator is on exactly as it is for
    /// [`AgentStatus::InSession`].
    Companion(CompanionSummary),

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

/// Where a control companion is, from being asked to being controlled.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum CompanionPhase {
    /// The owner linked this machine to their share from the browser; the
    /// person at the machine has not said yes yet. Nothing is joined.
    AwaitingConfirmation,
    /// Joined, and working out which monitor is being shared — or waiting
    /// for the person at the machine to pick one.
    ChoosingMonitor,
    /// The monitor is known and reported. Somebody the owner grants control
    /// to, in the browser, can use this machine's mouse and keyboard.
    Ready,
    /// Somebody is controlling this machine.
    Controlled,
    /// Somebody holds control, but the person at the machine is using it, so
    /// their input waits.
    Paused,
}

/// A monitor, as the companion's picker offers it.
///
/// Integers only, so it crosses the Tauri boundary in a state that compares
/// exactly: the scale is a whole percentage.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct MonitorOption {
    /// The id the choice is reported with.
    pub id: u32,
    /// What the person at the machine calls it.
    pub name: String,
    /// The main display.
    pub primary: bool,
    /// Width, in the platform's injection space.
    pub width: u32,
    /// Height, in the platform's injection space.
    pub height: u32,
    /// The display scale as a percentage — 200 on a Retina display.
    pub scale_percent: u32,
}

impl From<&Monitor> for MonitorOption {
    fn from(monitor: &Monitor) -> Self {
        Self {
            id: monitor.id,
            name: monitor.name.clone(),
            primary: monitor.primary,
            width: monitor.width,
            height: monitor.height,
            scale_percent: (monitor.scale * 100.0).round().clamp(0.0, 10_000.0) as u32,
        }
    }
}

/// The control companion, as the interface shows it.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct CompanionSummary {
    /// The link's identifier.
    pub companion_uuid: String,
    /// The session the owner is sharing in.
    pub session_uuid: String,
    /// `123-456`.
    pub display_id: String,
    /// Which organisation.
    pub company_name: Option<String>,
    /// Who linked this machine — its owner, from their browser.
    pub requested_by_name: Option<String>,
    /// When an unanswered request lapses.
    pub expires_at: Option<String>,
    /// When the machine joined, ISO-8601 UTC. `None` until it has.
    pub started_at: Option<String>,
    /// Where it is.
    pub phase: CompanionPhase,
    /// What the picker offers, while the person at the machine has to choose.
    /// Empty otherwise.
    pub monitors: Vec<MonitorOption>,
    /// The monitor being controlled through, once known.
    pub monitor: Option<MonitorOption>,
    /// Who holds control, while somebody does.
    pub controller_name: Option<String>,
}

impl CompanionSummary {
    /// Whether the machine is in the session, rather than still being asked.
    #[must_use]
    pub fn is_joined(&self) -> bool {
        self.phase != CompanionPhase::AwaitingConfirmation
    }

    /// Whether somebody holds control, paused or not.
    #[must_use]
    pub fn is_controlled(&self) -> bool {
        matches!(
            self.phase,
            CompanionPhase::Controlled | CompanionPhase::Paused
        )
    }
}

/// Control, for the interface.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct ControlSummary {
    /// Nobody has asked / asked / granted / refused / withdrawn.
    pub state: ControlStateView,
    /// Whether the clipboard is being synchronised.
    pub clipboard: bool,
    /// Who is asking, while `state` is `Requested`.
    ///
    /// This is what a grant is made out to — the window cannot answer a
    /// request it has no participant uuid for. Cleared the moment the
    /// request is answered one way or the other, so a stale value cannot be
    /// granted control of a later, different request.
    pub requester_uuid: Option<String>,
    /// Their name, as the consent dialog shows it.
    pub requester_name: Option<String>,
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
    /// What the organisation currently allows, as `GET /devices/me` last
    /// reported it — the declaration intersected with the company's policy.
    ///
    /// `None` until the first successful poll. The Permissions panel's "your
    /// organisation" column renders from this, and stays blank rather than
    /// guessing until the server has actually said something.
    pub allowed_capabilities: Option<AgentCapabilities>,
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
            allowed_capabilities: None,
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
        match &self.status {
            AgentStatus::InSession(_) => true,
            AgentStatus::Companion(companion) => companion.is_joined(),
            _ => false,
        }
    }

    /// The control companion, if this machine is one or is being asked to be.
    #[must_use]
    pub fn active_companion(&self) -> Option<&CompanionSummary> {
        match &self.status {
            AgentStatus::Companion(companion) => Some(companion),
            _ => None,
        }
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
            || self
                .active_companion()
                .is_some_and(CompanionSummary::is_controlled)
    }

    /// Whether the agent is enrolled at all.
    #[must_use]
    pub fn is_enrolled(&self) -> bool {
        !matches!(self.status, AgentStatus::NotEnrolled)
    }

    /// Whether the machine is reachable.
    #[must_use]
    pub fn is_online(&self) -> bool {
        matches!(
            self.status,
            AgentStatus::Online | AgentStatus::InSession(_) | AgentStatus::Companion(_)
        )
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
            AgentStatus::Companion(companion) => match companion.phase {
                CompanionPhase::AwaitingConfirmation => {
                    "AICOUNTLY Remote — waiting for you to allow control".into()
                }
                CompanionPhase::ChoosingMonitor | CompanionPhase::Ready => format!(
                    "AICOUNTLY Remote — ready to be controlled in session {}",
                    companion.display_id
                ),
                CompanionPhase::Controlled => format!(
                    "AICOUNTLY Remote — {} is controlling this computer",
                    companion.controller_name.as_deref().unwrap_or("someone")
                ),
                CompanionPhase::Paused => {
                    "AICOUNTLY Remote — control paused while you use this computer".into()
                }
            },
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
            (AgentStatus::Revoked, AgentEvent::Enrolled { .. }) => {
                AgentStatus::Authenticating { attempt: 0 }
            }
            (AgentStatus::Revoked, _) => AgentStatus::Revoked,

            (_, AgentEvent::Revoked) => {
                self.unattended.enabled = false;
                self.unattended.enabled_at = None;

                AgentStatus::Revoked
            }

            (
                _,
                AgentEvent::Enrolled {
                    device_uuid,
                    device_name,
                    company_name,
                    key_fingerprint,
                },
            ) => {
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

            (_, AgentEvent::AuthenticationFailed { attempt }) => {
                AgentStatus::Authenticating { attempt }
            }

            // The connection loop renews its credential and reports presence
            // while a confirmation is on screen; neither dismisses it.
            (
                AgentStatus::Companion(companion),
                AgentEvent::Authenticated | AgentEvent::Connected,
            ) => AgentStatus::Companion(companion),
            (_, AgentEvent::Authenticated) => AgentStatus::Online,

            (_, AgentEvent::Disconnected { reason, retryable }) => {
                AgentStatus::Offline { reason, retryable }
            }
            (_, AgentEvent::Connected) => AgentStatus::Online,

            (_, AgentEvent::SessionStarted(session)) => AgentStatus::InSession(session),

            // ----------------------------------------------------- companion
            //
            // A request is only raised on an idle machine, and only replaces a
            // request — never a session or a companion that already joined.
            (AgentStatus::Online, AgentEvent::CompanionRequested(request)) => {
                AgentStatus::Companion(CompanionSummary {
                    phase: CompanionPhase::AwaitingConfirmation,
                    ..request
                })
            }
            (AgentStatus::Companion(current), AgentEvent::CompanionRequested(request))
                if !current.is_joined() =>
            {
                AgentStatus::Companion(CompanionSummary {
                    phase: CompanionPhase::AwaitingConfirmation,
                    ..request
                })
            }
            (status, AgentEvent::CompanionRequested(_)) => status,

            (
                AgentStatus::Companion(current),
                AgentEvent::CompanionDismissed { companion_uuid },
            ) if !current.is_joined() && current.companion_uuid == companion_uuid => {
                AgentStatus::Online
            }
            (status, AgentEvent::CompanionDismissed { .. }) => status,

            // Joining: from the confirmation, or from an idle machine when the
            // confirmation was given in a window the state had not caught up
            // with yet.
            (
                AgentStatus::Companion(_) | AgentStatus::Online,
                AgentEvent::CompanionJoined(joined),
            ) => AgentStatus::Companion(CompanionSummary {
                phase: CompanionPhase::ChoosingMonitor,
                monitors: Vec::new(),
                monitor: None,
                controller_name: None,
                ..joined
            }),
            (status, AgentEvent::CompanionJoined(_)) => status,

            (AgentStatus::Companion(current), AgentEvent::CompanionChooseMonitor { options })
                if current.is_joined() =>
            {
                AgentStatus::Companion(CompanionSummary {
                    phase: CompanionPhase::ChoosingMonitor,
                    monitors: options,
                    monitor: None,
                    controller_name: None,
                    ..current
                })
            }
            (status, AgentEvent::CompanionChooseMonitor { .. }) => status,

            (AgentStatus::Companion(current), AgentEvent::CompanionMonitorChosen(monitor))
                if current.is_joined() =>
            {
                AgentStatus::Companion(CompanionSummary {
                    phase: if current.is_controlled() {
                        current.phase
                    } else {
                        CompanionPhase::Ready
                    },
                    monitors: Vec::new(),
                    monitor: Some(monitor),
                    ..current
                })
            }
            (status, AgentEvent::CompanionMonitorChosen(_)) => status,

            // Control only means something once the monitor is known: before
            // that the gate has nowhere to put a click, and the API does not
            // let anybody be granted it.
            (
                AgentStatus::Companion(current),
                AgentEvent::CompanionControl {
                    controlled,
                    controller_name,
                },
            ) if current.monitor.is_some() => {
                let phase = match (controlled, current.phase) {
                    (true, CompanionPhase::Paused) => CompanionPhase::Paused,
                    (true, _) => CompanionPhase::Controlled,
                    (false, _) => CompanionPhase::Ready,
                };

                AgentStatus::Companion(CompanionSummary {
                    phase,
                    controller_name: if controlled { controller_name } else { None },
                    ..current
                })
            }
            (status, AgentEvent::CompanionControl { .. }) => status,

            (AgentStatus::Companion(current), AgentEvent::CompanionPaused(paused))
                if current.is_controlled() =>
            {
                AgentStatus::Companion(CompanionSummary {
                    phase: if paused {
                        CompanionPhase::Paused
                    } else {
                        CompanionPhase::Controlled
                    },
                    ..current
                })
            }
            (status, AgentEvent::CompanionPaused(_)) => status,

            (AgentStatus::Companion(current), AgentEvent::CompanionEnded) => {
                if let Some(started_at) = current.started_at.clone() {
                    self.recent_sessions.insert(
                        0,
                        SessionSummary {
                            session_uuid: current.session_uuid.clone(),
                            display_id: current.display_id.clone(),
                            connected_name: current
                                .controller_name
                                .clone()
                                .or_else(|| current.requested_by_name.clone())
                                .unwrap_or_default(),
                            company_name: current.company_name.clone(),
                            started_at,
                            unattended: false,
                            control: ControlSummary {
                                state: ControlStateView::None,
                                clipboard: false,
                                requester_uuid: None,
                                requester_name: None,
                            },
                        },
                    );
                    self.recent_sessions.truncate(10);
                }

                AgentStatus::Online
            }
            (status, AgentEvent::CompanionEnded) => status,

            (
                AgentStatus::InSession(session),
                AgentEvent::ControlChanged {
                    state,
                    clipboard,
                    requester_uuid,
                    requester_name,
                },
            ) => AgentStatus::InSession(SessionSummary {
                control: ControlSummary {
                    state,
                    clipboard: clipboard && state == ControlStateView::Granted,
                    requester_uuid,
                    requester_name,
                },
                ..session
            }),
            // A control change with no session is not a state; ignore it
            // rather than inventing a session to hang it on.
            (status, AgentEvent::ControlChanged { .. }) => status,

            // The peer identified itself over signalling — who is actually in
            // the room. Set once, from the room, never from a data-channel
            // message a peer could forge.
            (AgentStatus::InSession(session), AgentEvent::PeerConnected { display_name }) => {
                AgentStatus::InSession(SessionSummary {
                    connected_name: display_name,
                    ..session
                })
            }
            (status, AgentEvent::PeerConnected { .. }) => status,

            (status, AgentEvent::CapabilitiesChanged(capabilities)) => {
                self.allowed_capabilities = Some(capabilities);

                status
            }

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
        /// Who is asking, while `state` is `Requested` — `None` otherwise.
        requester_uuid: Option<String>,
        /// Their name, as the consent dialog shows it.
        requester_name: Option<String>,
    },
    /// The peer in the room said who they are.
    PeerConnected {
        /// What to show beside the pointer, and in the session banner.
        display_name: String,
    },
    /// Unattended access was switched on or off, here or in the console.
    UnattendedChanged(UnattendedState),
    /// What the organisation currently allows changed — a fresh read of
    /// `GET /devices/me`, not necessarily a different value.
    CapabilitiesChanged(AgentCapabilities),
    /// An administrator revoked this device.
    Revoked,
    /// The owner linked this machine to their browser share; ask the person
    /// at the machine. The summary's phase is ignored — it is a request.
    CompanionRequested(CompanionSummary),
    /// The request went away unanswered here: declined, lapsed, or withdrawn
    /// from the browser.
    CompanionDismissed {
        /// Which link.
        companion_uuid: String,
    },
    /// The person at the machine said yes and the machine joined.
    CompanionJoined(CompanionSummary),
    /// Which monitor is being shared cannot be told automatically; offer these.
    CompanionChooseMonitor {
        /// The candidates, in layout order.
        options: Vec<MonitorOption>,
    },
    /// The monitor being shared is known and was reported.
    CompanionMonitorChosen(MonitorOption),
    /// Control was granted to somebody, or is no longer held.
    CompanionControl {
        /// Whether somebody holds control.
        controlled: bool,
        /// Who, for the banner.
        controller_name: Option<String>,
    },
    /// The person at the machine started (`true`) or stopped (`false`) using it
    /// while somebody holds control.
    CompanionPaused(bool),
    /// The companion left the session.
    CompanionEnded,
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
            control: ControlSummary {
                state: ControlStateView::None,
                clipboard: false,
                requester_uuid: None,
                requester_name: None,
            },
        }
    }

    /// The common case in these tests: a state change nobody is mid-request
    /// for. `a_pending_request_carries_who_is_asking_and_a_decision_clears_it`
    /// below is the one that cares about the other two fields.
    fn control_changed(state: ControlStateView, clipboard: bool) -> AgentEvent {
        AgentEvent::ControlChanged {
            state,
            clipboard,
            requester_uuid: None,
            requester_name: None,
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
            .apply(control_changed(ControlStateView::Granted, true));

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
            .apply(control_changed(ControlStateView::Granted, true))
            .apply(control_changed(ControlStateView::Revoked, true));

        assert!(!state.is_being_controlled());
        assert!(!state.active_session().unwrap().control.clipboard);
    }

    /// The property the consent dialog depends on: a request names who is
    /// asking, and answering it — either way — clears that name rather than
    /// leaving it to be misread against a later request.
    #[test]
    fn a_pending_request_carries_who_is_asking_and_a_decision_clears_it() {
        let state = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::SessionStarted(session(false)))
            .apply(AgentEvent::ControlChanged {
                state: ControlStateView::Requested,
                clipboard: false,
                requester_uuid: Some("participant-uuid".into()),
                requester_name: Some("Sam in support".into()),
            });

        let control = state.active_session().unwrap().control.clone();
        assert_eq!(control.state, ControlStateView::Requested);
        assert_eq!(control.requester_uuid.as_deref(), Some("participant-uuid"));
        assert_eq!(control.requester_name.as_deref(), Some("Sam in support"));

        let granted = state.apply(control_changed(ControlStateView::Granted, false));
        let control = granted.active_session().unwrap().control.clone();
        assert!(control.requester_uuid.is_none());
        assert!(control.requester_name.is_none());
    }

    /// The peer's name comes from the room it joined, never from a message it
    /// could send itself over the control channel.
    #[test]
    fn a_peer_identifying_itself_sets_the_connected_name() {
        let state = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::SessionStarted(SessionSummary {
                connected_name: String::new(),
                ..session(false)
            }));

        assert_eq!(state.active_session().unwrap().connected_name, "");

        let state = state.apply(AgentEvent::PeerConnected {
            display_name: "Sam in support".into(),
        });

        assert_eq!(
            state.active_session().unwrap().connected_name,
            "Sam in support"
        );
    }

    /// A poll result is recorded even when nothing changed, and a session
    /// merely finishing must not erase what the last poll said.
    #[test]
    fn capabilities_the_organisation_allows_are_recorded_once_learned() {
        let allowed = AgentCapabilities {
            screen_share: true,
            screen_view: true,
            remote_control: false,
            unattended_access: false,
            file_transfer: true,
            clipboard_sync: false,
            reboot: false,
        };

        let state = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::CapabilitiesChanged(allowed));

        assert_eq!(state.allowed_capabilities, Some(allowed));

        let state = state
            .apply(AgentEvent::SessionStarted(session(false)))
            .apply(AgentEvent::SessionEnded);

        assert_eq!(state.allowed_capabilities, Some(allowed));
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
            .apply(control_changed(ControlStateView::Granted, true));

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

    fn companion_request() -> CompanionSummary {
        CompanionSummary {
            companion_uuid: "companion-1".into(),
            session_uuid: "session-1".into(),
            display_id: "123-456".into(),
            company_name: Some("Northwind".into()),
            requested_by_name: Some("Rahul".into()),
            expires_at: Some("2026-10-25T09:05:00Z".into()),
            started_at: None,
            phase: CompanionPhase::Ready,
            monitors: Vec::new(),
            monitor: None,
            controller_name: None,
        }
    }

    fn option(id: u32) -> MonitorOption {
        MonitorOption {
            id,
            name: format!("Display {id}"),
            primary: id == 1,
            width: 1512,
            height: 982,
            scale_percent: 200,
        }
    }

    fn joined() -> CompanionSummary {
        CompanionSummary {
            started_at: Some("2026-10-25T09:01:00Z".into()),
            ..companion_request()
        }
    }

    /// The whole companion life: asked, joined, monitor chosen, controlled,
    /// paused by the person at the machine, resumed, released, ended.
    #[test]
    fn a_companion_moves_through_its_phases_in_order() {
        let phase = |state: &AgentState| state.active_companion().map(|c| c.phase);

        let state = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::CompanionRequested(companion_request()));
        assert_eq!(phase(&state), Some(CompanionPhase::AwaitingConfirmation));
        // Being asked is not being in a session.
        assert!(!state.is_session_active());
        assert!(state.tray_summary().contains("allow control"));

        let state = state.apply(AgentEvent::CompanionJoined(joined()));
        assert_eq!(phase(&state), Some(CompanionPhase::ChoosingMonitor));
        assert!(state.is_session_active(), "joined is visible");

        let state = state.apply(AgentEvent::CompanionChooseMonitor {
            options: vec![option(1), option(2)],
        });
        assert_eq!(state.active_companion().unwrap().monitors.len(), 2);

        let state = state.apply(AgentEvent::CompanionMonitorChosen(option(2)));
        assert_eq!(phase(&state), Some(CompanionPhase::Ready));
        assert!(state.active_companion().unwrap().monitors.is_empty());
        assert!(!state.is_being_controlled());

        let state = state.apply(AgentEvent::CompanionControl {
            controlled: true,
            controller_name: Some("Sam".into()),
        });
        assert_eq!(phase(&state), Some(CompanionPhase::Controlled));
        assert!(state.is_being_controlled());
        assert!(state.tray_summary().contains("Sam is controlling"));

        let state = state.apply(AgentEvent::CompanionPaused(true));
        assert_eq!(phase(&state), Some(CompanionPhase::Paused));
        assert!(
            state.is_being_controlled(),
            "Stop stays on offer while paused"
        );

        // A control poll while paused does not lift the pause.
        let state = state.apply(AgentEvent::CompanionControl {
            controlled: true,
            controller_name: Some("Sam".into()),
        });
        assert_eq!(phase(&state), Some(CompanionPhase::Paused));

        let state = state.apply(AgentEvent::CompanionPaused(false));
        assert_eq!(phase(&state), Some(CompanionPhase::Controlled));

        let state = state.apply(AgentEvent::CompanionControl {
            controlled: false,
            controller_name: None,
        });
        assert_eq!(phase(&state), Some(CompanionPhase::Ready));
        assert!(state.active_companion().unwrap().controller_name.is_none());

        let state = state.apply(AgentEvent::CompanionEnded);
        assert_eq!(state.status, AgentStatus::Online);
        assert_eq!(state.recent_sessions[0].display_id, "123-456");
    }

    /// Presence and renewal keep running under a confirmation on screen, and
    /// must not wipe it away.
    #[test]
    fn a_confirmation_survives_the_connection_loop_reporting_in() {
        let state = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::CompanionRequested(companion_request()))
            .apply(AgentEvent::Connected)
            .apply(AgentEvent::Authenticated);

        assert_eq!(
            state.active_companion().map(|c| c.phase),
            Some(CompanionPhase::AwaitingConfirmation)
        );
    }

    /// A request never interrupts a hosted session or a companion that has
    /// already joined; dismissing applies only to the request it names.
    #[test]
    fn a_request_never_displaces_a_session_and_dismissal_is_by_name() {
        let hosting = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::SessionStarted(session(false)))
            .apply(AgentEvent::CompanionRequested(companion_request()));
        assert!(hosting.active_session().is_some());

        let companion = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::CompanionRequested(companion_request()))
            .apply(AgentEvent::CompanionJoined(joined()))
            .apply(AgentEvent::CompanionRequested(CompanionSummary {
                companion_uuid: "companion-2".into(),
                ..companion_request()
            }));
        assert_eq!(
            companion.active_companion().unwrap().companion_uuid,
            "companion-1"
        );
        assert!(companion.active_companion().unwrap().is_joined());

        let asked = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::CompanionRequested(companion_request()));
        let still = asked.clone().apply(AgentEvent::CompanionDismissed {
            companion_uuid: "someone-else".into(),
        });
        assert!(still.active_companion().is_some());

        let gone = asked.apply(AgentEvent::CompanionDismissed {
            companion_uuid: "companion-1".into(),
        });
        assert_eq!(gone.status, AgentStatus::Online);
        assert!(
            gone.recent_sessions.is_empty(),
            "never joined, nothing to list"
        );
    }

    /// Control cannot be shown before there is a monitor for it to land on,
    /// and a pause cannot exist without control.
    #[test]
    fn control_needs_a_monitor_and_a_pause_needs_control() {
        let state = enrolled()
            .apply(AgentEvent::Authenticated)
            .apply(AgentEvent::CompanionJoined(joined()))
            .apply(AgentEvent::CompanionControl {
                controlled: true,
                controller_name: Some("Sam".into()),
            })
            .apply(AgentEvent::CompanionPaused(true));

        assert_eq!(
            state.active_companion().unwrap().phase,
            CompanionPhase::ChoosingMonitor
        );
        assert!(!state.is_being_controlled());
    }

    #[test]
    fn a_monitor_option_carries_its_scale_as_a_whole_percentage() {
        let monitor = Monitor {
            id: 3,
            name: "Studio Display".into(),
            primary: false,
            x: 1512,
            y: 0,
            width: 2560,
            height: 1440,
            scale: 2.0,
            refresh_hz: Some(60),
            orientation: remote_protocol::Orientation::Landscape,
        };

        let option = MonitorOption::from(&monitor);
        assert_eq!(option.scale_percent, 200);
        assert_eq!((option.width, option.height), (2560, 1440));

        let json = serde_json::to_value(&option).unwrap();
        assert_eq!(json["scalePercent"], 200);
    }

    #[test]
    fn the_protocols_control_state_maps_across_without_a_gap() {
        assert_eq!(
            ControlStateView::from(ControlState::None),
            ControlStateView::None
        );
        assert_eq!(
            ControlStateView::from(ControlState::Requested),
            ControlStateView::Requested
        );
        assert_eq!(
            ControlStateView::from(ControlState::Granted),
            ControlStateView::Granted
        );
        assert_eq!(
            ControlStateView::from(ControlState::Denied),
            ControlStateView::Denied
        );
        assert_eq!(
            ControlStateView::from(ControlState::Revoked),
            ControlStateView::Revoked
        );
    }
}
