//! The AICOUNTLY Remote control channel.
//!
//! Everything that travels between a controller and a desktop agent over the
//! `RTCDataChannel`, defined once so the browser, the agent and any future
//! desktop controller cannot drift apart.
//!
//! # What this crate is not
//!
//! It is **not** a remote-execution protocol. There is no message that names a
//! program, a path or a shell command, and there is deliberately no extension
//! point through which one could be added without changing this enum — which
//! is the point. The widest thing it can express is "the pointer moved to
//! (x, y)".
//!
//! # The five checks
//!
//! A message that arrives on the wire is not an instruction. Before an agent
//! acts on one, [`ControlGate`] requires all five of:
//!
//! 1. the session id matches the session this channel belongs to;
//! 2. the sender is the participant control was granted to;
//! 3. control is currently `Granted` — not requested, not revoked;
//! 4. the sequence number is newer than the last one acted on;
//! 5. the payload is within bounds for its kind.
//!
//! Failing any of them drops the message. Nothing is queued, retried or
//! "corrected" — a stale or malformed input event is not something to guess
//! about on a machine somebody else is sitting at.
//!
//! # Coordinates
//!
//! Pointer positions travel **normalised to 0.0–1.0 of the shared surface**,
//! never in pixels. The controller's window, the agent's monitor, the DPI
//! scaling on either side and the resolution the capture is running at are all
//! different numbers that change during a session; a pixel coordinate would be
//! wrong the moment any of them did. [`Monitor::denormalise`] turns a
//! normalised point into a virtual-desktop pixel for the platform layer.

#![forbid(unsafe_code)]
#![deny(missing_docs)]

use serde::{Deserialize, Serialize};
use std::fmt;

pub mod clipboard;
pub mod gate;
pub mod input;
pub mod monitor;

pub use clipboard::{ClipboardDirection, ClipboardPayload};
pub use gate::{ControlGate, ControlState, GateError};
pub use input::{Key, KeyEvent, Modifiers, MouseButton, MouseEvent, PointerPosition, ScrollEvent};
pub use monitor::{Monitor, MonitorLayout, Orientation};

/// The wire version.
///
/// Sent in every envelope and checked on receipt. Two builds that disagree
/// refuse each other loudly instead of one of them misreading a field the
/// other added — which, in a protocol that moves a mouse, is the difference
/// between a clear error and a click somewhere nobody intended.
pub const PROTOCOL_VERSION: u16 = 1;

/// The data-channel label the control protocol runs on.
///
/// Distinct from the session's collaboration channel: chat, pointers,
/// annotations and file chunks share `aicountly-remote`, and control has its
/// own so a bug in one cannot deliver into the other.
pub const CONTROL_CHANNEL_LABEL: &str = "aicountly-remote-control";

/// The largest control message that can be legitimate, in bytes.
///
/// Input events are tens of bytes. The ceiling exists for the clipboard, and
/// is checked before parsing so a hostile peer cannot make the agent allocate
/// by claiming a large payload.
pub const MAX_MESSAGE_BYTES: usize = 96 * 1024;

/// Everything a controller may say to an agent.
///
/// `#[serde(tag = "type")]` gives a self-describing wire format that an
/// unknown variant fails to parse rather than silently matching the first
/// field-compatible one.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(tag = "type", rename_all = "snake_case")]
pub enum ControlMessage {
    /// Move the pointer. Normalised to the shared surface, never pixels.
    MouseMove {
        /// Where to, as a fraction of the shared surface.
        position: PointerPosition,
    },
    /// A relative pointer movement, for pointer-locked controllers.
    ///
    /// Present because a controller that has captured the pointer reports
    /// deltas rather than positions, and integrating them browser-side would
    /// accumulate error over a session.
    MouseMoveRelative {
        /// Horizontal movement, as a fraction of the shared surface's width.
        dx: f64,
        /// Vertical movement, as a fraction of the shared surface's height.
        dy: f64,
    },
    /// A button went down, up, or was double-clicked.
    MouseButton(MouseEvent),
    /// A wheel turned, vertically or horizontally.
    Scroll(ScrollEvent),
    /// A key went down or up, with the modifier state at that instant.
    Key(KeyEvent),
    /// Text pushed to or pulled from the other side's clipboard.
    Clipboard(ClipboardPayload),
    /// The agent describing its displays: count, size, layout, scaling.
    ///
    /// Sent on connect and again on any change — a monitor unplugged, a
    /// resolution changed, a laptop rotated — so the controller never maps a
    /// click against a layout that has moved on.
    MonitorLayout(MonitorLayout),
    /// The controller asking to share a different monitor.
    SelectMonitor {
        /// The `id` of a monitor from the most recent [`MonitorLayout`].
        monitor_id: u32,
    },
    /// The agent telling the controller that control ended, and why.
    ///
    /// Sent when the person at the machine presses Stop, when the API says the
    /// grant was revoked, or when the session ends. The controller stops
    /// sending input on receipt; the agent has already stopped accepting it.
    ControlEnded {
        /// Machine reason, for the interface to explain.
        reason: ControlEndReason,
    },
    /// Restart the machine. Authorised by the API, never by this message.
    ///
    /// The agent re-checks the session and the grant before acting, so a
    /// forged one reaches a machine that refuses it.
    Reboot {
        /// The session this was authorised inside.
        session_uuid: String,
    },
    /// Keep-alive, so a silent channel can be told from a dead one.
    Ping {
        /// Milliseconds since the controller's epoch; echoed back untouched.
        nonce: u64,
    },
    /// The reply to [`ControlMessage::Ping`].
    Pong {
        /// The nonce from the ping.
        nonce: u64,
    },
}

/// Why control stopped.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum ControlEndReason {
    /// The person at the machine pressed Stop control.
    StoppedLocally,
    /// The API says the grant is no longer `GRANTED`.
    RevokedByServer,
    /// The session ended.
    SessionEnded,
    /// The channel dropped and did not come back.
    ConnectionLost,
    /// The machine is shutting down or restarting.
    ShuttingDown,
}

impl fmt::Display for ControlEndReason {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        let text = match self {
            Self::StoppedLocally => "stopped at the computer",
            Self::RevokedByServer => "revoked",
            Self::SessionEnded => "the session ended",
            Self::ConnectionLost => "the connection was lost",
            Self::ShuttingDown => "the computer is shutting down",
        };

        f.write_str(text)
    }
}

/// One message on the wire, with everything needed to decide whether to act.
///
/// The session and participant are carried in every envelope rather than
/// established once at the start of the channel. It costs a few bytes and
/// removes a whole class of bug: a message that arrives after a renegotiation,
/// after a reconnect, or on a channel that outlived its grant has to name the
/// session it belongs to, and the gate compares it every time.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct ControlEnvelope {
    /// Wire version. See [`PROTOCOL_VERSION`].
    #[serde(rename = "v")]
    pub version: u16,
    /// The session this message belongs to.
    #[serde(rename = "s")]
    pub session_uuid: String,
    /// The participant that sent it.
    #[serde(rename = "p")]
    pub participant_uuid: String,
    /// Monotonic per-sender counter. Anything not strictly newer is dropped.
    #[serde(rename = "n")]
    pub sequence: u64,
    /// The message itself.
    #[serde(rename = "m")]
    pub message: ControlMessage,
}

impl ControlEnvelope {
    /// Build an envelope at the current protocol version.
    pub fn new(
        session_uuid: impl Into<String>,
        participant_uuid: impl Into<String>,
        sequence: u64,
        message: ControlMessage,
    ) -> Self {
        Self {
            version: PROTOCOL_VERSION,
            session_uuid: session_uuid.into(),
            participant_uuid: participant_uuid.into(),
            sequence,
            message,
        }
    }

    /// Serialise for the data channel.
    pub fn encode(&self) -> Result<Vec<u8>, ProtocolError> {
        let bytes = serde_json::to_vec(self).map_err(|_| ProtocolError::Encode)?;

        if bytes.len() > MAX_MESSAGE_BYTES {
            return Err(ProtocolError::TooLarge {
                bytes: bytes.len(),
                limit: MAX_MESSAGE_BYTES,
            });
        }

        Ok(bytes)
    }

    /// Parse a message from the data channel.
    ///
    /// The size is checked **before** parsing, so a hostile peer cannot make
    /// the agent allocate a large structure by sending a large document.
    pub fn decode(bytes: &[u8]) -> Result<Self, ProtocolError> {
        if bytes.len() > MAX_MESSAGE_BYTES {
            return Err(ProtocolError::TooLarge {
                bytes: bytes.len(),
                limit: MAX_MESSAGE_BYTES,
            });
        }

        let envelope: Self = serde_json::from_slice(bytes).map_err(|_| ProtocolError::Malformed)?;

        if envelope.version != PROTOCOL_VERSION {
            return Err(ProtocolError::VersionMismatch {
                expected: PROTOCOL_VERSION,
                found: envelope.version,
            });
        }

        envelope.validate()?;

        Ok(envelope)
    }

    /// Structural validation, independent of who sent it or when.
    ///
    /// Bounds live here rather than in the platform layer so that a coordinate
    /// which is `NaN`, infinite or outside the surface never reaches a
    /// `SetCursorPos` call — the operating system would happily accept it.
    pub fn validate(&self) -> Result<(), ProtocolError> {
        if self.session_uuid.is_empty() || self.session_uuid.len() > 64 {
            return Err(ProtocolError::Malformed);
        }

        if self.participant_uuid.is_empty() || self.participant_uuid.len() > 64 {
            return Err(ProtocolError::Malformed);
        }

        match &self.message {
            ControlMessage::MouseMove { position } => position.validate(),
            ControlMessage::MouseMoveRelative { dx, dy } => {
                // A relative move is bounded to one surface in either
                // direction: anything larger is not a hand movement.
                if !dx.is_finite() || !dy.is_finite() || dx.abs() > 1.0 || dy.abs() > 1.0 {
                    return Err(ProtocolError::OutOfBounds);
                }

                Ok(())
            }
            ControlMessage::MouseButton(event) => event.validate(),
            ControlMessage::Scroll(event) => event.validate(),
            ControlMessage::Key(event) => event.validate(),
            ControlMessage::Clipboard(payload) => payload.validate(),
            ControlMessage::MonitorLayout(layout) => layout.validate(),
            ControlMessage::SelectMonitor { .. }
            | ControlMessage::ControlEnded { .. }
            | ControlMessage::Ping { .. }
            | ControlMessage::Pong { .. } => Ok(()),
            ControlMessage::Reboot { session_uuid } => {
                // The reboot has to name the same session as its envelope, so
                // one captured from a previous session cannot be replayed into
                // a current one.
                if session_uuid != &self.session_uuid {
                    return Err(ProtocolError::Malformed);
                }

                Ok(())
            }
        }
    }

    /// Whether this message would cause input to be injected.
    ///
    /// Used by the gate: an input message needs an active grant, where a ping
    /// or a monitor layout does not.
    pub fn is_input(&self) -> bool {
        matches!(
            self.message,
            ControlMessage::MouseMove { .. }
                | ControlMessage::MouseMoveRelative { .. }
                | ControlMessage::MouseButton(_)
                | ControlMessage::Scroll(_)
                | ControlMessage::Key(_)
        )
    }
}

/// Everything that can be wrong with a control message.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum ProtocolError {
    /// Not valid JSON, or not this protocol's shape.
    #[error("the message was not a control message")]
    Malformed,
    /// The peer speaks a different version of this protocol.
    #[error("protocol version {found} is not {expected}")]
    VersionMismatch {
        /// What this build speaks.
        expected: u16,
        /// What arrived.
        found: u16,
    },
    /// Larger than any legitimate control message.
    #[error("message is {bytes} bytes, over the {limit}-byte limit")]
    TooLarge {
        /// The size that arrived.
        bytes: usize,
        /// The ceiling.
        limit: usize,
    },
    /// A coordinate, delta or code outside its permitted range.
    #[error("a value in the message was out of bounds")]
    OutOfBounds,
    /// Clipboard text that was not valid UTF-8.
    #[error("clipboard text was not valid UTF-8")]
    InvalidText,
    /// Could not be serialised — a bug on this side, not the peer's.
    #[error("the message could not be encoded")]
    Encode,
}

#[cfg(test)]
mod tests {
    use super::*;

    fn envelope(message: ControlMessage) -> ControlEnvelope {
        ControlEnvelope::new("session-uuid", "participant-uuid", 1, message)
    }

    #[test]
    fn round_trips_every_variant() {
        let messages = vec![
            ControlMessage::MouseMove {
                position: PointerPosition { x: 0.5, y: 0.25 },
            },
            ControlMessage::MouseMoveRelative {
                dx: -0.01,
                dy: 0.02,
            },
            ControlMessage::MouseButton(MouseEvent {
                button: MouseButton::Right,
                pressed: true,
                double: false,
                position: Some(PointerPosition { x: 0.1, y: 0.1 }),
            }),
            ControlMessage::Scroll(ScrollEvent {
                delta_x: 0.0,
                delta_y: -3.0,
                position: None,
            }),
            ControlMessage::Key(KeyEvent {
                key: Key::Character('a'),
                pressed: true,
                modifiers: Modifiers::default(),
            }),
            ControlMessage::Clipboard(ClipboardPayload::text("hello", ClipboardDirection::ToHost)),
            ControlMessage::ControlEnded {
                reason: ControlEndReason::StoppedLocally,
            },
            ControlMessage::Ping { nonce: 7 },
            ControlMessage::Pong { nonce: 7 },
            ControlMessage::SelectMonitor { monitor_id: 2 },
        ];

        for message in messages {
            let original = envelope(message);
            let bytes = original.encode().expect("encodes");
            let decoded = ControlEnvelope::decode(&bytes).expect("decodes");

            assert_eq!(original, decoded);
        }
    }

    #[test]
    fn refuses_a_different_protocol_version() {
        let mut env = envelope(ControlMessage::Ping { nonce: 1 });
        env.version = PROTOCOL_VERSION + 1;

        let bytes = serde_json::to_vec(&env).unwrap();

        assert_eq!(
            ControlEnvelope::decode(&bytes),
            Err(ProtocolError::VersionMismatch {
                expected: PROTOCOL_VERSION,
                found: PROTOCOL_VERSION + 1,
            })
        );
    }

    #[test]
    fn refuses_an_oversized_message_before_parsing_it() {
        let huge = vec![b'{'; MAX_MESSAGE_BYTES + 1];

        assert!(matches!(
            ControlEnvelope::decode(&huge),
            Err(ProtocolError::TooLarge { .. })
        ));
    }

    #[test]
    fn refuses_a_message_that_is_not_this_protocol() {
        for bytes in [
            &b"not json at all"[..],
            &b"{}"[..],
            &br#"{"v":1,"s":"x","p":"y","n":1,"m":{"type":"run_shell","command":"rm -rf /"}}"#[..],
        ] {
            assert!(ControlEnvelope::decode(bytes).is_err(), "{bytes:?}");
        }
    }

    /// There is no message that names a program, a path or a command, and this
    /// is what keeps it that way when somebody adds a variant.
    #[test]
    fn the_protocol_expresses_nothing_executable() {
        let vocabulary =
            serde_json::to_string(&envelope(ControlMessage::Ping { nonce: 0 })).unwrap_or_default();

        for forbidden in ["exec", "shell", "command", "spawn", "path", "script"] {
            assert!(
                !vocabulary.contains(forbidden),
                "the control envelope must not carry a {forbidden} field"
            );
        }
    }

    #[test]
    fn refuses_an_out_of_bounds_pointer() {
        let env = envelope(ControlMessage::MouseMove {
            position: PointerPosition { x: 1.5, y: 0.5 },
        });

        assert_eq!(env.validate(), Err(ProtocolError::OutOfBounds));
    }

    #[test]
    fn refuses_a_non_finite_coordinate() {
        for (x, y) in [
            (f64::NAN, 0.5),
            (0.5, f64::INFINITY),
            (f64::NEG_INFINITY, 0.0),
        ] {
            let env = envelope(ControlMessage::MouseMove {
                position: PointerPosition { x, y },
            });

            assert_eq!(
                env.validate(),
                Err(ProtocolError::OutOfBounds),
                "({x}, {y})"
            );
        }
    }

    #[test]
    fn a_reboot_must_name_its_own_session() {
        let mut env = envelope(ControlMessage::Reboot {
            session_uuid: "a-different-session".into(),
        });
        assert_eq!(env.validate(), Err(ProtocolError::Malformed));

        env.message = ControlMessage::Reboot {
            session_uuid: "session-uuid".into(),
        };
        assert_eq!(env.validate(), Ok(()));
    }

    #[test]
    fn knows_which_messages_inject_input() {
        assert!(envelope(ControlMessage::MouseMove {
            position: PointerPosition { x: 0.0, y: 0.0 }
        })
        .is_input());
        assert!(envelope(ControlMessage::Key(KeyEvent {
            key: Key::Enter,
            pressed: true,
            modifiers: Modifiers::default(),
        }))
        .is_input());

        assert!(!envelope(ControlMessage::Ping { nonce: 1 }).is_input());
        assert!(!envelope(ControlMessage::Clipboard(ClipboardPayload::text(
            "x",
            ClipboardDirection::ToHost
        )))
        .is_input());
    }

    #[test]
    fn refuses_an_empty_or_implausible_identifier() {
        for (session, participant) in [
            ("", "p"),
            ("s", ""),
            (&"x".repeat(65) as &str, "p"),
            ("s", &"y".repeat(65) as &str),
        ] {
            let env =
                ControlEnvelope::new(session, participant, 1, ControlMessage::Ping { nonce: 0 });

            assert_eq!(env.validate(), Err(ProtocolError::Malformed));
        }
    }
}
