//! What the service knows, and what it will do about a request.
//!
//! Deliberately platform-independent, and deliberately separate from
//! `service.rs`: everything here is a decision, and a decision that only runs
//! on a Windows CI runner is a decision nobody reads the tests for. The
//! Windows file next to this one does the plumbing — the SCM, the pipe, the
//! shutdown call — and takes every answer from here.
//!
//! # The shape of an exchange
//!
//! ```text
//!   UI ──▶ Hello           ── the only message accepted before a handshake
//!   UI ◀── Hello           ── versions agreed, or refused with a reason
//!   UI ──▶ DeviceStatus    ── what is enrolled, never the key
//!   UI ──▶ SessionStarted  ── the service learns a session is live
//!   UI ──▶ Reboot          ── refused unless that session is one it was told about
//! ```
//!
//! # What the handshake is and is not
//!
//! The pipe's ACL is the boundary that matters: only `LocalSystem`,
//! `Administrators` and the interactive user can open the pipe at all, and
//! low-integrity processes are excluded by the mandatory label. The handshake
//! on top of it agrees a protocol version and refuses a half-updated pair of
//! binaries. It is **not** a claim that the peer is the genuine UI — a process
//! already running as the signed-in user could speak this protocol, and
//! nothing at this layer could tell the difference.
//!
//! That is why the protocol is the size it is. The widest thing a peer that
//! got through the ACL can ask for is a restart of a machine it is already
//! signed in to — not code execution, not a file, not the device key.

use crate::ipc::{IpcRequest, IpcResponse, IPC_PROTOCOL_VERSION};

/// How many sessions the service will track at once.
///
/// A machine hosts one Remote session at a time in practice; the headroom is
/// for a fast-user-switch. Bounded because an unbounded list is somewhere a
/// peer can put unbounded data.
pub const MAX_TRACKED_SESSIONS: usize = 4;

/// The shortest gap between two restarts.
///
/// A restart loop is the one thing this service can be made to do that a
/// person at the machine cannot easily stop, so it cannot be asked for twice
/// in quick succession.
pub const REBOOT_COOLDOWN_SECONDS: u64 = 300;

/// The machine, as the service currently understands it.
///
/// There is no field for a token, a credential or a key. What the service
/// holds in memory while it runs is not in this struct and is never answered
/// over the pipe.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct MachineState {
    /// Whether a device key exists on this machine.
    pub enrolled: bool,
    /// The device's public identifier.
    pub device_uuid: Option<String>,
    /// The public key fingerprint, so the UI can show what the console shows.
    pub key_fingerprint: Option<String>,
    /// Whether unattended access is switched on for this device.
    pub unattended_enabled: bool,
    /// Whether the presence connection is up.
    pub online: bool,
    /// When presence was last reported, ISO-8601 UTC.
    pub last_reported_at: Option<String>,
    /// When the service's own device credential expires, ISO-8601 UTC.
    pub credential_expires_at: Option<String>,
    /// Sessions the UI has told the service about and not yet ended.
    pub active_sessions: Vec<String>,
    /// Monotonic seconds at the last restart request that was accepted.
    pub last_reboot_at: Option<u64>,
}

/// A connection's own state. One per pipe instance.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Connection {
    /// Whether [`IpcRequest::Hello`] has been exchanged.
    pub greeted: bool,
    /// What the peer said it was.
    pub peer_version: Option<String>,
}

/// Something the Windows layer has to actually do.
///
/// Returned rather than performed, so the decision is testable and the effect
/// is the only part that needs a Windows runner.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Effect {
    /// Obtain a device credential, and report the expiry back.
    Authenticate,
    /// Restart this machine.
    Reboot {
        /// The session it was authorised inside.
        session_uuid: String,
        /// The message Windows shows before restarting.
        reason: String,
    },
}

/// Whether a string is a session identifier and not something else.
///
/// It becomes part of an API path and is compared against a tracked list, so
/// it is checked rather than trusted — and it arrived over an IPC channel from
/// a process this layer cannot vouch for.
#[must_use]
pub fn is_session_identifier(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 64
        && value.chars().all(|c| c.is_ascii_alphanumeric() || c == '-')
}

/// Decide what to answer, and what to do.
///
/// `now_seconds` is a monotonic clock the caller supplies, so the cooldown is
/// testable without waiting five minutes.
pub fn handle(
    state: &mut MachineState,
    connection: &mut Connection,
    request: IpcRequest,
    now_seconds: u64,
) -> (IpcResponse, Option<Effect>) {
    // The handshake comes first, always. A peer that skips it gets the same
    // answer whatever it asked for, so a probe learns nothing about the
    // machine from the shape of the refusal.
    if !connection.greeted && !matches!(request, IpcRequest::Hello { .. }) {
        return (
            IpcResponse::Error {
                code: "NOT_AUTHENTICATED".into(),
                message: "The connection has not been opened.".into(),
            },
            None,
        );
    }

    match request {
        IpcRequest::Hello {
            protocol_version,
            agent_version,
            role,
        } => {
            if protocol_version != IPC_PROTOCOL_VERSION {
                // A half-finished update: a new UI beside an old service, or
                // the other way round. Said plainly, because the fix is a
                // restart rather than a reinstall.
                return (
                    IpcResponse::Error {
                        code: "VERSION_MISMATCH".into(),
                        message: format!(
                            "AICOUNTLY Remote needs restarting to finish updating \
                             (this service speaks protocol {IPC_PROTOCOL_VERSION}, \
                             the application speaks {protocol_version})."
                        ),
                    },
                    None,
                );
            }

            if role != "ui" {
                return (
                    IpcResponse::Error {
                        code: "UNEXPECTED_ROLE".into(),
                        message: "Only the AICOUNTLY Remote application connects here.".into(),
                    },
                    None,
                );
            }

            connection.greeted = true;
            connection.peer_version = Some(agent_version);

            (
                IpcResponse::Hello {
                    protocol_version: IPC_PROTOCOL_VERSION,
                    service_version: env!("CARGO_PKG_VERSION").to_owned(),
                },
                None,
            )
        }

        IpcRequest::DeviceStatus => (
            IpcResponse::DeviceStatus {
                enrolled: state.enrolled,
                device_uuid: state.device_uuid.clone(),
                key_fingerprint: state.key_fingerprint.clone(),
                unattended_enabled: state.unattended_enabled,
            },
            None,
        ),

        // The credential itself never crosses the pipe. The UI learns that
        // authentication worked and when it expires, and makes its own calls
        // with its own credential.
        IpcRequest::Authenticate => match state.credential_expires_at.clone() {
            Some(expires_at) => (IpcResponse::Authenticated { expires_at }, None),
            None => (IpcResponse::Acknowledged, Some(Effect::Authenticate)),
        },

        IpcRequest::PresenceStatus => (
            IpcResponse::Presence {
                online: state.online,
                last_reported_at: state.last_reported_at.clone(),
            },
            None,
        ),

        IpcRequest::SessionStarted { session_uuid } => {
            if !is_session_identifier(&session_uuid) {
                return (rejected("That is not a session identifier."), None);
            }

            if !state.active_sessions.contains(&session_uuid) {
                if state.active_sessions.len() >= MAX_TRACKED_SESSIONS {
                    return (
                        IpcResponse::Error {
                            code: "TOO_MANY_SESSIONS".into(),
                            message:
                                "This computer is already hosting as many sessions as it will."
                                    .into(),
                        },
                        None,
                    );
                }

                state.active_sessions.push(session_uuid);
            }

            (IpcResponse::Acknowledged, None)
        }

        IpcRequest::SessionEnded { session_uuid } => {
            state.active_sessions.retain(|held| held != &session_uuid);

            (IpcResponse::Acknowledged, None)
        }

        IpcRequest::Reboot {
            session_uuid,
            reason,
        } => {
            if !is_session_identifier(&session_uuid) {
                return (rejected("That is not a session identifier."), None);
            }

            // The API authorised the restart — the policy switch, the
            // permission and an active control grant were all checked there,
            // and the audit entry is already written. This is the service
            // refusing to do it for a session it was never told about, which
            // is the most it can independently check from here.
            if !state.active_sessions.contains(&session_uuid) {
                return (
                    IpcResponse::Error {
                        code: "SESSION_NOT_ACTIVE".into(),
                        message: "That session is not running on this computer.".into(),
                    },
                    None,
                );
            }

            if let Some(previous) = state.last_reboot_at {
                if now_seconds.saturating_sub(previous) < REBOOT_COOLDOWN_SECONDS {
                    return (
                        IpcResponse::Error {
                            code: "REBOOT_TOO_SOON".into(),
                            message: "This computer was asked to restart a moment ago.".into(),
                        },
                        None,
                    );
                }
            }

            state.last_reboot_at = Some(now_seconds);

            (
                IpcResponse::Acknowledged,
                Some(Effect::Reboot {
                    session_uuid,
                    reason,
                }),
            )
        }

        IpcRequest::Ping => (IpcResponse::Pong, None),
    }
}

/// One refusal, so a probe cannot tell two failures apart by their wording.
fn rejected(message: &str) -> IpcResponse {
    IpcResponse::Error {
        code: "REJECTED".into(),
        message: message.to_owned(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn hello() -> IpcRequest {
        IpcRequest::Hello {
            protocol_version: IPC_PROTOCOL_VERSION,
            agent_version: "1.0.0".into(),
            role: "ui".into(),
        }
    }

    /// A greeted connection with one session running — the ordinary case.
    fn live() -> (MachineState, Connection) {
        let mut state = MachineState {
            enrolled: true,
            device_uuid: Some("device-uuid".into()),
            key_fingerprint: Some("AAAA BBBB CCCC DDDD".into()),
            ..MachineState::default()
        };
        let mut connection = Connection::default();

        handle(&mut state, &mut connection, hello(), 0);
        handle(
            &mut state,
            &mut connection,
            IpcRequest::SessionStarted {
                session_uuid: "session-1".into(),
            },
            0,
        );

        (state, connection)
    }

    #[test]
    fn nothing_is_answered_before_the_handshake() {
        let mut state = MachineState::default();
        let mut connection = Connection::default();

        for request in [
            IpcRequest::DeviceStatus,
            IpcRequest::PresenceStatus,
            IpcRequest::Ping,
            IpcRequest::Reboot {
                session_uuid: "session-1".into(),
                reason: "no".into(),
            },
        ] {
            let (response, effect) = handle(&mut state, &mut connection, request, 0);

            assert_eq!(
                response,
                IpcResponse::Error {
                    code: "NOT_AUTHENTICATED".into(),
                    message: "The connection has not been opened.".into(),
                }
            );
            assert_eq!(effect, None);
        }
    }

    /// A half-finished update leaves a new UI beside an old service. The
    /// refusal has to say what to do about it.
    #[test]
    fn a_version_mismatch_is_refused_with_the_fix_in_the_message() {
        let mut state = MachineState::default();
        let mut connection = Connection::default();

        let (response, _) = handle(
            &mut state,
            &mut connection,
            IpcRequest::Hello {
                protocol_version: IPC_PROTOCOL_VERSION + 7,
                agent_version: "9.9.9".into(),
                role: "ui".into(),
            },
            0,
        );

        match response {
            IpcResponse::Error { code, message } => {
                assert_eq!(code, "VERSION_MISMATCH");
                assert!(message.contains("restarting"));
            }
            other => panic!("expected a version mismatch, got {other:?}"),
        }

        assert!(!connection.greeted, "a mismatched peer is not greeted");
    }

    #[test]
    fn a_peer_claiming_another_role_is_refused() {
        let mut state = MachineState::default();
        let mut connection = Connection::default();

        let (response, _) = handle(
            &mut state,
            &mut connection,
            IpcRequest::Hello {
                protocol_version: IPC_PROTOCOL_VERSION,
                agent_version: "1.0.0".into(),
                role: "service".into(),
            },
            0,
        );

        assert!(matches!(response, IpcResponse::Error { .. }));
        assert!(!connection.greeted);
    }

    #[test]
    fn device_status_carries_the_fingerprint_and_no_key() {
        let (mut state, mut connection) = live();

        let (response, effect) = handle(&mut state, &mut connection, IpcRequest::DeviceStatus, 0);

        assert_eq!(effect, None);
        assert_eq!(
            response,
            IpcResponse::DeviceStatus {
                enrolled: true,
                device_uuid: Some("device-uuid".into()),
                key_fingerprint: Some("AAAA BBBB CCCC DDDD".into()),
                unattended_enabled: false,
            }
        );
    }

    /// **The restart rule.** A session the service was never told about is not
    /// a session it will restart the machine for.
    #[test]
    fn a_restart_is_refused_for_a_session_the_service_never_heard_of() {
        let (mut state, mut connection) = live();

        let (response, effect) = handle(
            &mut state,
            &mut connection,
            IpcRequest::Reboot {
                session_uuid: "some-other-session".into(),
                reason: "Applying updates".into(),
            },
            0,
        );

        assert_eq!(effect, None);
        assert!(matches!(
            response,
            IpcResponse::Error { ref code, .. } if code == "SESSION_NOT_ACTIVE"
        ));
    }

    #[test]
    fn a_restart_for_a_live_session_is_performed_once() {
        let (mut state, mut connection) = live();

        let (response, effect) = handle(
            &mut state,
            &mut connection,
            IpcRequest::Reboot {
                session_uuid: "session-1".into(),
                reason: "Applying updates".into(),
            },
            1_000,
        );

        assert_eq!(response, IpcResponse::Acknowledged);
        assert_eq!(
            effect,
            Some(Effect::Reboot {
                session_uuid: "session-1".into(),
                reason: "Applying updates".into(),
            })
        );
    }

    /// A restart loop is the one thing this service can be made to do that
    /// somebody at the machine cannot easily stop.
    #[test]
    fn a_second_restart_within_the_cooldown_is_refused() {
        let (mut state, mut connection) = live();

        let reboot = || IpcRequest::Reboot {
            session_uuid: "session-1".into(),
            reason: "Applying updates".into(),
        };

        handle(&mut state, &mut connection, reboot(), 1_000);

        let (response, effect) = handle(&mut state, &mut connection, reboot(), 1_010);

        assert_eq!(effect, None);
        assert!(matches!(
            response,
            IpcResponse::Error { ref code, .. } if code == "REBOOT_TOO_SOON"
        ));

        // And allowed again once the cooldown has passed.
        let (response, effect) = handle(
            &mut state,
            &mut connection,
            reboot(),
            1_000 + REBOOT_COOLDOWN_SECONDS,
        );

        assert_eq!(response, IpcResponse::Acknowledged);
        assert!(matches!(effect, Some(Effect::Reboot { .. })));
    }

    /// A session identifier becomes part of an API path and is compared
    /// against a tracked list. It arrived over IPC, so it is checked.
    #[test]
    fn a_session_identifier_that_is_not_one_is_refused() {
        let (mut state, mut connection) = live();

        for hostile in [
            "../../etc/passwd",
            "session-1/../admin",
            "session 1",
            "",
            &"x".repeat(65),
        ] {
            let (response, effect) = handle(
                &mut state,
                &mut connection,
                IpcRequest::SessionStarted {
                    session_uuid: hostile.to_owned(),
                },
                0,
            );

            assert_eq!(effect, None);
            assert!(matches!(response, IpcResponse::Error { .. }), "{hostile:?}");
        }

        assert_eq!(state.active_sessions, vec!["session-1".to_owned()]);
    }

    #[test]
    fn the_tracked_session_list_is_bounded() {
        let (mut state, mut connection) = live();

        for index in 0..20 {
            handle(
                &mut state,
                &mut connection,
                IpcRequest::SessionStarted {
                    session_uuid: format!("session-{index}"),
                },
                0,
            );
        }

        assert_eq!(state.active_sessions.len(), MAX_TRACKED_SESSIONS);
    }

    #[test]
    fn a_session_that_ended_stops_authorising_a_restart() {
        let (mut state, mut connection) = live();

        handle(
            &mut state,
            &mut connection,
            IpcRequest::SessionEnded {
                session_uuid: "session-1".into(),
            },
            0,
        );

        let (response, effect) = handle(
            &mut state,
            &mut connection,
            IpcRequest::Reboot {
                session_uuid: "session-1".into(),
                reason: "no".into(),
            },
            5_000,
        );

        assert_eq!(effect, None);
        assert!(matches!(
            response,
            IpcResponse::Error { ref code, .. } if code == "SESSION_NOT_ACTIVE"
        ));
    }

    #[test]
    fn starting_the_same_session_twice_tracks_it_once() {
        let (mut state, mut connection) = live();

        handle(
            &mut state,
            &mut connection,
            IpcRequest::SessionStarted {
                session_uuid: "session-1".into(),
            },
            0,
        );

        assert_eq!(state.active_sessions, vec!["session-1".to_owned()]);
    }

    #[test]
    fn authentication_reports_an_expiry_and_asks_for_one_when_there_is_none() {
        let (mut state, mut connection) = live();

        let (response, effect) = handle(&mut state, &mut connection, IpcRequest::Authenticate, 0);
        assert_eq!(response, IpcResponse::Acknowledged);
        assert_eq!(effect, Some(Effect::Authenticate));

        state.credential_expires_at = Some("2026-02-10T09:15:00Z".into());

        let (response, effect) = handle(&mut state, &mut connection, IpcRequest::Authenticate, 0);
        assert_eq!(effect, None);
        assert_eq!(
            response,
            IpcResponse::Authenticated {
                expires_at: "2026-02-10T09:15:00Z".into(),
            }
        );
    }

    #[test]
    fn a_heartbeat_is_answered() {
        let (mut state, mut connection) = live();

        assert_eq!(
            handle(&mut state, &mut connection, IpcRequest::Ping, 0),
            (IpcResponse::Pong, None)
        );
    }

    /// **The property the whole service rests on.** Whatever a peer that got
    /// through the ACL sends, the widest effect it can produce is a restart.
    #[test]
    fn the_only_effect_a_peer_can_cause_is_a_restart_or_an_authentication() {
        let (mut state, mut connection) = live();

        let every_request = vec![
            hello(),
            IpcRequest::DeviceStatus,
            IpcRequest::Authenticate,
            IpcRequest::PresenceStatus,
            IpcRequest::SessionStarted {
                session_uuid: "session-2".into(),
            },
            IpcRequest::SessionEnded {
                session_uuid: "session-2".into(),
            },
            IpcRequest::Reboot {
                session_uuid: "session-1".into(),
                reason: "r".into(),
            },
            IpcRequest::Ping,
        ];

        for request in every_request {
            let (_, effect) = handle(&mut state, &mut connection, request, 10_000);

            match effect {
                None | Some(Effect::Authenticate) | Some(Effect::Reboot { .. }) => {}
            }
        }
    }
}
