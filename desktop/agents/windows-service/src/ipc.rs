//! The IPC protocol between the service and the user-session process.
//!
//! Small on purpose. Every message here is one the two halves genuinely need
//! to exchange, and the enum is exhaustive — there is no "command" variant, no
//! string that becomes a path, no passthrough, and no way to add one without
//! editing this file.
//!
//! # Framing
//!
//! ```text
//!   ┌────────┬──────────┬──────────────────────┐
//!   │ u16 LE │  u32 LE  │        payload       │
//!   │version │  length  │      JSON, UTF-8     │
//!   └────────┴──────────┴──────────────────────┘
//! ```
//!
//! Length-prefixed because a named pipe in byte mode is a stream: without a
//! length, two messages written back to back arrive as one read, and a reader
//! that split on a delimiter would be a parser waiting for a payload
//! containing that delimiter. Version-prefixed because a half-finished update
//! leaves a new UI beside an old service, and the two must refuse each other
//! clearly rather than misreading a field.
//!
//! # Authentication
//!
//! The pipe's ACL is the first gate: only `LocalSystem` and `Administrators`
//! may connect ([`crate::PipeSecurity`]). The second is
//! [`IpcRequest::Hello`], which both ends exchange before anything else — the
//! service verifies the client is the signed executable it expects, and the
//! client verifies the service's version. Neither is sufficient alone: an ACL
//! without a handshake accepts any elevated process, and a handshake without
//! an ACL is a protocol anyone can speak.

use serde::{Deserialize, Serialize};

/// The IPC wire version.
///
/// Bumped whenever a message changes shape. A mismatch is refused with
/// [`IpcError::VersionMismatch`], which the UI shows as "restart is needed to
/// finish updating" rather than as a mysterious failure.
pub const IPC_PROTOCOL_VERSION: u16 = 1;

/// The largest frame either side will read.
///
/// Nothing here carries a screen frame, a file or a clipboard payload — those
/// all travel over WebRTC between peers, never through this pipe — so a
/// legitimate message is well under a kilobyte. The ceiling is generous and
/// still small enough that a hostile writer cannot make the reader allocate.
pub const MAX_FRAME_BYTES: usize = 64 * 1024;

/// The fixed header: version, then length.
pub const HEADER_BYTES: usize = 6;

/// What the user-session process asks the service for.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(tag = "request", rename_all = "snake_case")]
pub enum IpcRequest {
    /// Open the conversation and agree a version.
    Hello {
        /// The caller's protocol version.
        protocol_version: u16,
        /// The caller's build.
        agent_version: String,
        /// `ui` or `service`.
        role: String,
    },

    /// What the service knows about this machine's enrolment.
    ///
    /// The device *uuid* and its status — never the private key. The key stays
    /// in DPAPI and is used by whichever process holds it; it is not something
    /// this pipe can be asked to hand over, and there is no variant that would.
    DeviceStatus,

    /// Ask the service to authenticate the device and report the outcome.
    ///
    /// The credential itself does not cross the pipe. The UI learns that
    /// authentication succeeded and when it expires, and makes its own calls
    /// with its own credential — so a compromised UI process cannot lift the
    /// service's.
    Authenticate,

    /// Presence, as the service sees it.
    PresenceStatus,

    /// The UI telling the service a session started, so presence and the
    /// service's own state stay in step.
    SessionStarted {
        /// The session's public identifier.
        session_uuid: String,
    },

    /// The UI telling the service a session ended.
    SessionEnded {
        /// The session's public identifier.
        session_uuid: String,
    },

    /// Restart the machine.
    ///
    /// Carries the session it was authorised inside; the service re-checks it
    /// against what the API said before doing anything. There is no variant
    /// that restarts without a session, because there is no authorisation for
    /// one.
    Reboot {
        /// The session that authorised it.
        session_uuid: String,
        /// Shown to whoever is at the machine, and recorded by Windows.
        reason: String,
    },

    /// A heartbeat, so each side can tell a quiet pipe from a dead one.
    Ping,
}

/// What the service answers.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(tag = "response", rename_all = "snake_case")]
pub enum IpcResponse {
    /// The handshake was accepted.
    Hello {
        /// The service's protocol version.
        protocol_version: u16,
        /// The service's build.
        service_version: String,
    },

    /// The machine's enrolment, without the key.
    DeviceStatus {
        /// Whether a device key exists on this machine.
        enrolled: bool,
        /// The device's uuid, when enrolled.
        device_uuid: Option<String>,
        /// The public key fingerprint, so the UI can show what the console shows.
        key_fingerprint: Option<String>,
        /// Whether unattended access is switched on.
        unattended_enabled: bool,
    },

    /// Authentication succeeded.
    Authenticated {
        /// When the service's credential expires, ISO-8601 UTC.
        expires_at: String,
    },

    /// Presence.
    Presence {
        /// Whether the service's presence connection is up.
        online: bool,
        /// When it last reported, ISO-8601 UTC.
        last_reported_at: Option<String>,
    },

    /// The request was accepted and nothing more needs saying.
    Acknowledged,

    /// The heartbeat.
    Pong,

    /// The request was refused, with a reason the UI can render.
    Error {
        /// A machine code the UI switches on.
        code: String,
        /// A sentence for a person.
        message: String,
    },
}

/// One framed message.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct IpcFrame {
    /// The version this frame was written at.
    pub version: u16,
    /// The JSON payload.
    pub payload: Vec<u8>,
}

impl IpcFrame {
    /// Frame a serialisable message at the current version.
    pub fn encode<T: Serialize>(message: &T) -> Result<Vec<u8>, IpcError> {
        let payload = serde_json::to_vec(message).map_err(|_| IpcError::Malformed)?;

        if payload.len() > MAX_FRAME_BYTES {
            return Err(IpcError::TooLarge {
                bytes: payload.len(),
                limit: MAX_FRAME_BYTES,
            });
        }

        let mut framed = Vec::with_capacity(HEADER_BYTES + payload.len());
        framed.extend_from_slice(&IPC_PROTOCOL_VERSION.to_le_bytes());
        framed.extend_from_slice(&(payload.len() as u32).to_le_bytes());
        framed.extend_from_slice(&payload);

        Ok(framed)
    }

    /// Read the header, and say how many payload bytes to read next.
    ///
    /// Separate from [`Self::decode_payload`] because a pipe is a stream: the
    /// reader has to know the length before it can know it has a whole message,
    /// and a reader that guessed would either block on a partial frame or
    /// concatenate two.
    pub fn decode_header(header: &[u8]) -> Result<(u16, usize), IpcError> {
        if header.len() < HEADER_BYTES {
            return Err(IpcError::Truncated);
        }

        let version = u16::from_le_bytes([header[0], header[1]]);
        let length = u32::from_le_bytes([header[2], header[3], header[4], header[5]]) as usize;

        if version != IPC_PROTOCOL_VERSION {
            return Err(IpcError::VersionMismatch {
                expected: IPC_PROTOCOL_VERSION,
                found: version,
            });
        }

        // Checked before a single byte is allocated for the payload.
        if length > MAX_FRAME_BYTES {
            return Err(IpcError::TooLarge {
                bytes: length,
                limit: MAX_FRAME_BYTES,
            });
        }

        Ok((version, length))
    }

    /// Parse a payload whose length the header already agreed.
    pub fn decode_payload<T: for<'de> Deserialize<'de>>(payload: &[u8]) -> Result<T, IpcError> {
        serde_json::from_slice(payload).map_err(|_| IpcError::Malformed)
    }

    /// Decode a complete frame in one go, for a caller that already has it all.
    pub fn decode<T: for<'de> Deserialize<'de>>(bytes: &[u8]) -> Result<T, IpcError> {
        let (_, length) = Self::decode_header(bytes)?;

        let payload = bytes
            .get(HEADER_BYTES..HEADER_BYTES + length)
            .ok_or(IpcError::Truncated)?;

        Self::decode_payload(payload)
    }
}

/// Why an IPC exchange failed.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum IpcError {
    /// Fewer bytes than the frame says it has.
    #[error("the message was incomplete")]
    Truncated,

    /// Not valid JSON, or not a message this build knows.
    #[error("the message could not be read")]
    Malformed,

    /// The two ends speak different versions — usually a half-finished update.
    #[error("the background service speaks protocol {found}, this build speaks {expected}")]
    VersionMismatch {
        /// What this build speaks.
        expected: u16,
        /// What arrived.
        found: u16,
    },

    /// Larger than any legitimate IPC message.
    #[error("the message is {bytes} bytes, over the {limit}-byte limit")]
    TooLarge {
        /// The claimed size.
        bytes: usize,
        /// The ceiling.
        limit: usize,
    },

    /// The pipe is not there — the service is not running.
    #[error("the AICOUNTLY Remote background service is not running")]
    NotRunning,

    /// The operating system refused.
    #[error("the connection to the background service failed: {0}")]
    Transport(String),

    /// The handshake did not happen, or did not check out.
    #[error("the background service did not accept this connection")]
    NotAuthenticated,
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

    #[test]
    fn a_request_round_trips_through_a_frame() {
        let bytes = IpcFrame::encode(&hello()).expect("encodes");
        let decoded: IpcRequest = IpcFrame::decode(&bytes).expect("decodes");

        assert_eq!(decoded, hello());
    }

    #[test]
    fn every_request_and_response_round_trips() {
        let requests = vec![
            hello(),
            IpcRequest::DeviceStatus,
            IpcRequest::Authenticate,
            IpcRequest::PresenceStatus,
            IpcRequest::SessionStarted {
                session_uuid: "s".into(),
            },
            IpcRequest::SessionEnded {
                session_uuid: "s".into(),
            },
            IpcRequest::Reboot {
                session_uuid: "s".into(),
                reason: "update".into(),
            },
            IpcRequest::Ping,
        ];

        for request in requests {
            let bytes = IpcFrame::encode(&request).unwrap();
            assert_eq!(IpcFrame::decode::<IpcRequest>(&bytes).unwrap(), request);
        }

        let responses = vec![
            IpcResponse::Hello {
                protocol_version: 1,
                service_version: "1.0.0".into(),
            },
            IpcResponse::DeviceStatus {
                enrolled: true,
                device_uuid: Some("u".into()),
                key_fingerprint: Some("AAAA BBBB".into()),
                unattended_enabled: false,
            },
            IpcResponse::Authenticated {
                expires_at: "2026-02-10T09:15:00Z".into(),
            },
            IpcResponse::Presence {
                online: true,
                last_reported_at: None,
            },
            IpcResponse::Acknowledged,
            IpcResponse::Pong,
            IpcResponse::Error {
                code: "X".into(),
                message: "y".into(),
            },
        ];

        for response in responses {
            let bytes = IpcFrame::encode(&response).unwrap();
            assert_eq!(IpcFrame::decode::<IpcResponse>(&bytes).unwrap(), response);
        }
    }

    /// A pipe is a stream: two messages written back to back arrive as one
    /// read, and the length prefix is what separates them.
    #[test]
    fn two_frames_written_back_to_back_are_separable() {
        let mut stream = IpcFrame::encode(&IpcRequest::Ping).unwrap();
        stream.extend(IpcFrame::encode(&IpcRequest::DeviceStatus).unwrap());

        let (_, first_len) = IpcFrame::decode_header(&stream).unwrap();
        let first: IpcRequest =
            IpcFrame::decode_payload(&stream[HEADER_BYTES..HEADER_BYTES + first_len]).unwrap();
        assert_eq!(first, IpcRequest::Ping);

        let rest = &stream[HEADER_BYTES + first_len..];
        let (_, second_len) = IpcFrame::decode_header(rest).unwrap();
        let second: IpcRequest =
            IpcFrame::decode_payload(&rest[HEADER_BYTES..HEADER_BYTES + second_len]).unwrap();
        assert_eq!(second, IpcRequest::DeviceStatus);
    }

    /// A half-finished update leaves a new UI beside an old service. They must
    /// refuse each other clearly rather than misreading a field.
    #[test]
    fn a_version_mismatch_is_refused_with_a_reason() {
        let mut bytes = IpcFrame::encode(&IpcRequest::Ping).unwrap();
        bytes[0] = 99;

        assert_eq!(
            IpcFrame::decode::<IpcRequest>(&bytes),
            Err(IpcError::VersionMismatch {
                expected: IPC_PROTOCOL_VERSION,
                found: 99
            })
        );
    }

    /// The ceiling is checked from the header, before a byte is allocated.
    #[test]
    fn an_oversized_frame_is_refused_before_anything_is_allocated() {
        let mut header = Vec::new();
        header.extend_from_slice(&IPC_PROTOCOL_VERSION.to_le_bytes());
        header.extend_from_slice(&u32::MAX.to_le_bytes());

        assert!(matches!(
            IpcFrame::decode_header(&header),
            Err(IpcError::TooLarge { .. })
        ));
    }

    #[test]
    fn a_truncated_frame_is_refused_rather_than_blocking() {
        assert_eq!(
            IpcFrame::decode_header(&[1, 0, 4]),
            Err(IpcError::Truncated)
        );

        let mut bytes = IpcFrame::encode(&IpcRequest::Ping).unwrap();
        bytes.truncate(bytes.len() - 1);

        assert_eq!(
            IpcFrame::decode::<IpcRequest>(&bytes),
            Err(IpcError::Truncated)
        );
    }

    #[test]
    fn a_payload_that_is_not_a_message_is_refused() {
        let mut bytes = Vec::new();
        let payload = b"this is not json";
        bytes.extend_from_slice(&IPC_PROTOCOL_VERSION.to_le_bytes());
        bytes.extend_from_slice(&(payload.len() as u32).to_le_bytes());
        bytes.extend_from_slice(payload);

        assert_eq!(
            IpcFrame::decode::<IpcRequest>(&bytes),
            Err(IpcError::Malformed)
        );
    }

    /// **The property this protocol exists to hold.** There is no message that
    /// names a program, a path, an argument or a command line — so a
    /// compromised UI process cannot ask the service, which runs as
    /// `LocalSystem`, to run anything.
    #[test]
    fn the_protocol_can_express_nothing_executable() {
        let every_request = vec![
            hello(),
            IpcRequest::DeviceStatus,
            IpcRequest::Authenticate,
            IpcRequest::PresenceStatus,
            IpcRequest::SessionStarted {
                session_uuid: "s".into(),
            },
            IpcRequest::SessionEnded {
                session_uuid: "s".into(),
            },
            IpcRequest::Reboot {
                session_uuid: "s".into(),
                reason: "r".into(),
            },
            IpcRequest::Ping,
        ];

        for request in every_request {
            let json = serde_json::to_string(&request).unwrap().to_lowercase();

            for forbidden in [
                "command",
                "exec",
                "shell",
                "cmd",
                "powershell",
                "path",
                "argument",
                "argv",
                "script",
                "dll",
                "load",
            ] {
                assert!(
                    !json.contains(forbidden),
                    "{request:?} must not carry a {forbidden} field"
                );
            }
        }
    }

    /// The private key stays in DPAPI. The pipe cannot be asked to hand it
    /// over, because no message requests one and no response carries one.
    #[test]
    fn the_protocol_never_carries_a_key_or_a_credential() {
        let status = IpcResponse::DeviceStatus {
            enrolled: true,
            device_uuid: Some("device-uuid".into()),
            key_fingerprint: Some("AAAA BBBB CCCC DDDD".into()),
            unattended_enabled: true,
        };

        let json = serde_json::to_string(&status).unwrap().to_lowercase();

        assert!(json.contains("fingerprint"));
        assert!(!json.contains("privatekey"));
        assert!(!json.contains("private_key"));
        assert!(!json.contains("secret"));
        // The authenticated response carries an expiry, never the token.
        let authenticated = IpcResponse::Authenticated {
            expires_at: "2026-02-10T09:15:00Z".into(),
        };
        let json = serde_json::to_string(&authenticated)
            .unwrap()
            .to_lowercase();
        assert!(!json.contains("token"));
        assert!(!json.contains("bearer"));
    }

    /// A reboot has to name the session that authorised it. There is no
    /// variant that restarts without one.
    #[test]
    fn a_reboot_always_names_the_session_that_authorised_it() {
        let json = serde_json::to_string(&IpcRequest::Reboot {
            session_uuid: "session-uuid".into(),
            reason: "Applying updates".into(),
        })
        .unwrap();

        assert!(json.contains("session_uuid"));
        assert!(json.contains("session-uuid"));
    }
}
