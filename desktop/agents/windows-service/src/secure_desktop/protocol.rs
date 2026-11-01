//! The dedicated pipe's wire protocol, between the app and the helper.
//!
//! This is a separate channel from the app↔service IPC: that one carries small
//! JSON control messages and nothing that could be a screen frame, so it has a
//! tiny ceiling. This one carries a picture of the Secure Desktop, so it needs
//! a much larger frame cap — which is exactly why it is a channel of its own
//! and not more variants on the existing one.
//!
//! # Framing
//!
//! ```text
//!   ┌────────┬──────┬──────────┬──────────────────────┐
//!   │ u16 LE │ u8   │  u32 LE  │        payload       │
//!   │version │ kind │  length  │  (kind decides shape)│
//!   └────────┴──────┴──────────┴──────────────────────┘
//! ```
//!
//! Length-prefixed because a named pipe in byte mode is a stream, and
//! version-prefixed for the same reason the app↔service pipe is: a half-updated
//! pair must refuse each other rather than misread a frame as input. The kind
//! byte says which message it is, and the length is checked against the kind's
//! own ceiling **before** a byte is allocated, so a hostile writer on the pipe
//! cannot make the reader allocate by claiming a huge frame.
//!
//! # Who sends what
//!
//! ```text
//!   helper → app     State { active }      Frame { w, h, bgra }     Pong
//!   app → helper     Input(HelperInput)    Ping                     Stop
//! ```
//!
//! Nothing here names a program or a path. The widest thing the app can send
//! is a pointer position and a key, and both pass the app's control gate
//! before they are ever turned into one of these.

use serde::{Deserialize, Serialize};

pub use crate::ipc::SecureDesktopMode;
use remote_protocol::{KeyEvent, MouseEvent, PointerPosition, ScrollEvent};

/// The dedicated pipe's wire version.
pub const HELPER_VERSION: u16 = 1;

/// The fixed header: version (u16), kind (u8), length (u32).
pub const HEADER_BYTES: usize = 7;

/// The largest a frame payload may be.
///
/// The helper downscales to at most 1280 px wide; a 1280×1024 BGRA frame is
/// about 5.2 MiB, so eight is generous headroom and still far short of
/// anything that would trouble a modern machine. A frame larger than this is a
/// bug or a hostile writer, and either way is refused rather than allocated.
pub const MAX_FRAME_BYTES: usize = 8 * 1024 * 1024;

/// The largest any non-frame payload may be.
///
/// State, input, ping, pong and stop are tens of bytes. The ceiling is small
/// so a claim of a large control payload is refused from the header.
pub const MAX_CONTROL_BYTES: usize = 8 * 1024;

const KIND_STATE: u8 = 1;
const KIND_FRAME: u8 = 2;
const KIND_INPUT: u8 = 3;
const KIND_PING: u8 = 4;
const KIND_PONG: u8 = 5;
const KIND_STOP: u8 = 6;

/// One frame of the Secure Desktop, downscaled and in BGRA.
///
/// `bgra` is tightly packed: exactly `width * height * 4` bytes, top row
/// first. It is validated on decode so the app never hands a mismatched buffer
/// to the encoder.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct HelperFrame {
    /// Width in pixels.
    pub width: u32,
    /// Height in pixels.
    pub height: u32,
    /// Tightly packed BGRA, `width * height * 4` bytes.
    pub bgra: Vec<u8>,
}

impl HelperFrame {
    /// The byte length the pixels must have for these dimensions.
    #[must_use]
    pub fn expected_len(width: u32, height: u32) -> Option<usize> {
        (width as usize)
            .checked_mul(height as usize)
            .and_then(|pixels| pixels.checked_mul(4))
    }
}

/// What the app may inject through the helper.
///
/// A subset of [`remote_protocol::ControlMessage`] — only the five messages
/// that move the mouse or press a key, plus an optional Ctrl+Alt+Del. There is
/// no clipboard, no monitor selection and no reboot, because the Secure Desktop
/// has nothing to do with any of them, and every message not carried is one
/// the helper cannot be asked to act on. Each one has already passed the app's
/// control gate before it is turned into this.
#[derive(Debug, Clone, Copy, PartialEq, Serialize, Deserialize)]
#[serde(tag = "t", rename_all = "snake_case")]
pub enum HelperInput {
    /// Move the pointer, as a fraction of the captured surface.
    MouseMove {
        /// Where to.
        position: PointerPosition,
    },
    /// A button down, up or double-click.
    MouseButton(MouseEvent),
    /// A wheel turn.
    Scroll(ScrollEvent),
    /// A key down or up.
    Key(KeyEvent),
    /// Send Secure Attention (Ctrl+Alt+Del) through `SendSAS`.
    ///
    /// Carried here rather than as a key chord because the Secure Attention
    /// Sequence cannot be synthesised with `SendInput`: only `SendSAS` raises
    /// it, and only when policy permits a service to.
    SecureAttention,
}

/// One message on the dedicated pipe.
#[derive(Debug, Clone, PartialEq)]
pub enum HelperMessage {
    /// Helper → app: whether the Secure Desktop is in front right now.
    State {
        /// `true` when the input desktop is the Secure Desktop rather than the
        /// user's own, so the app should show the helper's frames.
        active: bool,
    },
    /// Helper → app: a frame of the Secure Desktop.
    Frame(HelperFrame),
    /// App → helper: inject this.
    Input(HelperInput),
    /// App → helper: are you there?
    Ping,
    /// Helper → app: yes.
    Pong,
    /// App → helper: stop and exit.
    Stop,
}

impl HelperMessage {
    /// The kind byte this message frames as.
    fn kind(&self) -> u8 {
        match self {
            Self::State { .. } => KIND_STATE,
            Self::Frame(_) => KIND_FRAME,
            Self::Input(_) => KIND_INPUT,
            Self::Ping => KIND_PING,
            Self::Pong => KIND_PONG,
            Self::Stop => KIND_STOP,
        }
    }

    /// The payload bytes for this message.
    fn payload(&self) -> Result<Vec<u8>, HelperProtocolError> {
        match self {
            Self::State { active } => Ok(vec![u8::from(*active)]),
            Self::Frame(frame) => {
                let expected = HelperFrame::expected_len(frame.width, frame.height)
                    .filter(|len| *len == frame.bgra.len())
                    .ok_or(HelperProtocolError::FrameSizeMismatch)?;

                let mut payload = Vec::with_capacity(8 + expected);
                payload.extend_from_slice(&frame.width.to_le_bytes());
                payload.extend_from_slice(&frame.height.to_le_bytes());
                payload.extend_from_slice(&frame.bgra);

                Ok(payload)
            }
            Self::Input(input) => {
                serde_json::to_vec(input).map_err(|_| HelperProtocolError::Malformed)
            }
            Self::Ping | Self::Pong | Self::Stop => Ok(Vec::new()),
        }
    }

    /// Frame this message for the pipe.
    pub fn encode(&self) -> Result<Vec<u8>, HelperProtocolError> {
        let payload = self.payload()?;
        let ceiling = payload_ceiling(self.kind());

        if payload.len() > ceiling {
            return Err(HelperProtocolError::TooLarge {
                bytes: payload.len(),
                limit: ceiling,
            });
        }

        let mut framed = Vec::with_capacity(HEADER_BYTES + payload.len());
        framed.extend_from_slice(&HELPER_VERSION.to_le_bytes());
        framed.push(self.kind());
        framed.extend_from_slice(&(payload.len() as u32).to_le_bytes());
        framed.extend_from_slice(&payload);

        Ok(framed)
    }

    /// Read the header: the kind byte and how many payload bytes follow.
    ///
    /// The length is checked against the kind's ceiling here, before the caller
    /// allocates for the payload.
    pub fn decode_header(header: &[u8]) -> Result<(u8, usize), HelperProtocolError> {
        if header.len() < HEADER_BYTES {
            return Err(HelperProtocolError::Truncated);
        }

        let version = u16::from_le_bytes([header[0], header[1]]);
        if version != HELPER_VERSION {
            return Err(HelperProtocolError::VersionMismatch {
                expected: HELPER_VERSION,
                found: version,
            });
        }

        let kind = header[2];
        let length = u32::from_le_bytes([header[3], header[4], header[5], header[6]]) as usize;

        if !is_known_kind(kind) {
            return Err(HelperProtocolError::UnknownKind(kind));
        }

        if length > payload_ceiling(kind) {
            return Err(HelperProtocolError::TooLarge {
                bytes: length,
                limit: payload_ceiling(kind),
            });
        }

        Ok((kind, length))
    }

    /// Parse a payload whose kind and length the header already agreed.
    pub fn decode_payload(kind: u8, payload: &[u8]) -> Result<Self, HelperProtocolError> {
        match kind {
            KIND_STATE => match payload.first() {
                Some(&byte) => Ok(Self::State { active: byte != 0 }),
                None => Err(HelperProtocolError::Truncated),
            },
            KIND_FRAME => {
                if payload.len() < 8 {
                    return Err(HelperProtocolError::Truncated);
                }

                let width = u32::from_le_bytes([payload[0], payload[1], payload[2], payload[3]]);
                let height = u32::from_le_bytes([payload[4], payload[5], payload[6], payload[7]]);
                let bgra = &payload[8..];

                match HelperFrame::expected_len(width, height) {
                    Some(expected) if expected == bgra.len() => Ok(Self::Frame(HelperFrame {
                        width,
                        height,
                        bgra: bgra.to_vec(),
                    })),
                    _ => Err(HelperProtocolError::FrameSizeMismatch),
                }
            }
            KIND_INPUT => serde_json::from_slice(payload)
                .map(Self::Input)
                .map_err(|_| HelperProtocolError::Malformed),
            KIND_PING => Ok(Self::Ping),
            KIND_PONG => Ok(Self::Pong),
            KIND_STOP => Ok(Self::Stop),
            other => Err(HelperProtocolError::UnknownKind(other)),
        }
    }

    /// Decode a complete frame a caller already has in full.
    pub fn decode(bytes: &[u8]) -> Result<Self, HelperProtocolError> {
        let (kind, length) = Self::decode_header(bytes)?;
        let payload = bytes
            .get(HEADER_BYTES..HEADER_BYTES + length)
            .ok_or(HelperProtocolError::Truncated)?;

        Self::decode_payload(kind, payload)
    }
}

/// Whether a kind byte is one this build knows.
fn is_known_kind(kind: u8) -> bool {
    matches!(
        kind,
        KIND_STATE | KIND_FRAME | KIND_INPUT | KIND_PING | KIND_PONG | KIND_STOP
    )
}

/// The ceiling for a kind's payload: the frame cap for a frame, the small one
/// for everything else.
fn payload_ceiling(kind: u8) -> usize {
    if kind == KIND_FRAME {
        MAX_FRAME_BYTES
    } else {
        MAX_CONTROL_BYTES
    }
}

/// Why a dedicated-pipe message could not be read or written.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum HelperProtocolError {
    /// Fewer bytes than the frame says it has.
    #[error("the message was incomplete")]
    Truncated,
    /// Not valid for its kind.
    #[error("the message could not be read")]
    Malformed,
    /// A half-updated pair of binaries.
    #[error("the helper speaks protocol {found}, this build speaks {expected}")]
    VersionMismatch {
        /// What this build speaks.
        expected: u16,
        /// What arrived.
        found: u16,
    },
    /// Larger than its kind allows.
    #[error("the message is {bytes} bytes, over the {limit}-byte limit")]
    TooLarge {
        /// The claimed size.
        bytes: usize,
        /// The ceiling.
        limit: usize,
    },
    /// A kind byte this build does not know.
    #[error("the message kind {0} is not known")]
    UnknownKind(u8),
    /// A frame whose pixel bytes do not match its stated dimensions.
    #[error("the frame's size did not match its dimensions")]
    FrameSizeMismatch,
}

#[cfg(test)]
mod tests {
    use super::*;
    use remote_protocol::{Key, Modifiers, MouseButton};

    fn frame(width: u32, height: u32) -> HelperFrame {
        HelperFrame {
            width,
            height,
            bgra: vec![0x7f; (width * height * 4) as usize],
        }
    }

    #[test]
    fn every_message_round_trips() {
        let messages = vec![
            HelperMessage::State { active: true },
            HelperMessage::State { active: false },
            HelperMessage::Frame(frame(4, 3)),
            HelperMessage::Input(HelperInput::MouseMove {
                position: PointerPosition { x: 0.25, y: 0.5 },
            }),
            HelperMessage::Input(HelperInput::MouseButton(MouseEvent {
                button: MouseButton::Left,
                pressed: true,
                double: false,
                position: None,
            })),
            HelperMessage::Input(HelperInput::Scroll(ScrollEvent {
                delta_x: 0.0,
                delta_y: -2.0,
                position: None,
            })),
            HelperMessage::Input(HelperInput::Key(KeyEvent {
                key: Key::Character('p'),
                pressed: true,
                modifiers: Modifiers::none(),
            })),
            HelperMessage::Input(HelperInput::SecureAttention),
            HelperMessage::Ping,
            HelperMessage::Pong,
            HelperMessage::Stop,
        ];

        for message in messages {
            let bytes = message.encode().expect("encodes");
            assert_eq!(HelperMessage::decode(&bytes).expect("decodes"), message);
        }
    }

    /// Two messages written back to back separate on the length prefix — the
    /// pipe is a byte stream.
    #[test]
    fn two_messages_back_to_back_are_separable() {
        let mut stream = HelperMessage::Ping.encode().unwrap();
        stream.extend(HelperMessage::State { active: true }.encode().unwrap());

        let (kind, len) = HelperMessage::decode_header(&stream).unwrap();
        assert_eq!(
            HelperMessage::decode_payload(kind, &stream[HEADER_BYTES..HEADER_BYTES + len]).unwrap(),
            HelperMessage::Ping
        );

        let rest = &stream[HEADER_BYTES + len..];
        let (kind, len) = HelperMessage::decode_header(rest).unwrap();
        assert_eq!(
            HelperMessage::decode_payload(kind, &rest[HEADER_BYTES..HEADER_BYTES + len]).unwrap(),
            HelperMessage::State { active: true }
        );
    }

    /// A frame larger than the cap is refused from the header, before its
    /// pixels are allocated.
    #[test]
    fn an_oversized_frame_is_refused_from_the_header() {
        let mut header = Vec::new();
        header.extend_from_slice(&HELPER_VERSION.to_le_bytes());
        header.push(KIND_FRAME);
        header.extend_from_slice(&((MAX_FRAME_BYTES as u32) + 1).to_le_bytes());

        assert!(matches!(
            HelperMessage::decode_header(&header),
            Err(HelperProtocolError::TooLarge { .. })
        ));
    }

    /// A control message claiming a frame-sized payload is refused: a State or
    /// Input cannot borrow the frame ceiling.
    #[test]
    fn a_control_message_cannot_claim_a_frame_sized_payload() {
        let mut header = Vec::new();
        header.extend_from_slice(&HELPER_VERSION.to_le_bytes());
        header.push(KIND_INPUT);
        header.extend_from_slice(&((MAX_CONTROL_BYTES as u32) + 1).to_le_bytes());

        assert!(matches!(
            HelperMessage::decode_header(&header),
            Err(HelperProtocolError::TooLarge { .. })
        ));
    }

    /// A frame whose byte count does not match its dimensions is refused rather
    /// than handed on as a buffer the encoder would read past the end of.
    #[test]
    fn a_frame_with_the_wrong_number_of_bytes_is_refused() {
        let mut payload = Vec::new();
        payload.extend_from_slice(&4u32.to_le_bytes());
        payload.extend_from_slice(&4u32.to_le_bytes());
        payload.extend_from_slice(&[0u8; 10]); // not 4*4*4 = 64

        assert_eq!(
            HelperMessage::decode_payload(KIND_FRAME, &payload),
            Err(HelperProtocolError::FrameSizeMismatch)
        );

        // And encoding one that lies about its size is refused too.
        let bogus = HelperMessage::Frame(HelperFrame {
            width: 4,
            height: 4,
            bgra: vec![0; 10],
        });
        assert_eq!(bogus.encode(), Err(HelperProtocolError::FrameSizeMismatch));
    }

    #[test]
    fn a_version_mismatch_is_refused_with_a_reason() {
        let mut bytes = HelperMessage::Ping.encode().unwrap();
        bytes[0] = 9;

        assert_eq!(
            HelperMessage::decode(&bytes),
            Err(HelperProtocolError::VersionMismatch {
                expected: HELPER_VERSION,
                found: 9,
            })
        );
    }

    #[test]
    fn an_unknown_kind_is_refused() {
        let mut header = Vec::new();
        header.extend_from_slice(&HELPER_VERSION.to_le_bytes());
        header.push(99);
        header.extend_from_slice(&0u32.to_le_bytes());

        assert_eq!(
            HelperMessage::decode_header(&header),
            Err(HelperProtocolError::UnknownKind(99))
        );
    }

    #[test]
    fn a_truncated_header_is_refused_rather_than_blocking() {
        assert_eq!(
            HelperMessage::decode_header(&[1, 0, 2]),
            Err(HelperProtocolError::Truncated)
        );
    }

    /// There is no message that names a program, a path or a command — the
    /// widest input is a pointer and a key.
    #[test]
    fn the_input_vocabulary_expresses_nothing_executable() {
        let json = serde_json::to_string(&HelperInput::MouseMove {
            position: PointerPosition { x: 0.0, y: 0.0 },
        })
        .unwrap()
        .to_lowercase();

        for forbidden in ["command", "exec", "shell", "path", "argv", "script", "dll"] {
            assert!(!json.contains(forbidden), "must not carry {forbidden}");
        }
    }
}
