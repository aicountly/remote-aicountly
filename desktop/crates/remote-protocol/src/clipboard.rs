//! Clipboard synchronisation. Text only, bounded, and never logged.
//!
//! Three deliberate limits, each with a reason:
//!
//! * **Text only.** An image or a file on the clipboard is a file transfer
//!   wearing a different hat, and Remote already has one of those — with a
//!   ledger, a recipient who has to accept, and a size the server enforces.
//!   Routing bytes around it through the clipboard would be a second file
//!   transfer with none of that. [`ClipboardFormat`] exists so the shape is
//!   written down once, and only `Text` is accepted.
//! * **Bounded.** 64 KiB by default, and the *server's* figure is what
//!   applies — the agent reads it from `GET /devices/me` rather than deciding
//!   for itself.
//! * **Never logged.** The `Debug` implementation prints the length and not
//!   the content, because a clipboard holds passwords and `tracing::debug!`
//!   on a struct is how they reach a log file.

use serde::{Deserialize, Serialize};
use std::fmt;

use crate::ProtocolError;

/// The default ceiling on one clipboard payload.
///
/// The server sends its own figure, and the agent uses that. This is what
/// applies before the first `GET /devices/me` answers.
pub const DEFAULT_MAX_CLIPBOARD_BYTES: usize = 64 * 1024;

/// Which way the clipboard is moving.
///
/// Named from the *host's* point of view — the machine being controlled — so
/// there is no ambiguity about whose clipboard is being read.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum ClipboardDirection {
    /// Controller → host. The host's clipboard is written.
    ToHost,
    /// Host → controller. The host's clipboard is read.
    ToController,
}

/// What is on the clipboard.
///
/// Only `Text` is accepted in this version. The others exist so that adding
/// them later is a change to the agent rather than a change to the protocol,
/// and so a peer that sends one gets a clear refusal rather than silence.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum ClipboardFormat {
    /// UTF-8 text.
    #[default]
    Text,
    /// Not supported in this version.
    Image,
    /// Not supported in this version.
    Files,
}

/// One clipboard synchronisation.
#[derive(Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct ClipboardPayload {
    /// Which way.
    pub direction: ClipboardDirection,
    /// What kind of content. Only [`ClipboardFormat::Text`] is accepted.
    #[serde(default)]
    pub format: ClipboardFormat,
    /// The text.
    ///
    /// A `String` rather than bytes, so the UTF-8 validation happens at the
    /// deserialiser rather than being something a caller has to remember.
    pub text: String,
}

impl ClipboardPayload {
    /// A text payload.
    #[must_use]
    pub fn text(text: impl Into<String>, direction: ClipboardDirection) -> Self {
        Self {
            direction,
            format: ClipboardFormat::Text,
            text: text.into(),
        }
    }

    /// Text, and within the default ceiling.
    pub fn validate(&self) -> Result<(), ProtocolError> {
        self.validate_within(DEFAULT_MAX_CLIPBOARD_BYTES)
    }

    /// Text, and within the ceiling the server gave us.
    pub fn validate_within(&self, max_bytes: usize) -> Result<(), ProtocolError> {
        if self.format != ClipboardFormat::Text {
            return Err(ProtocolError::InvalidText);
        }

        if self.text.len() > max_bytes {
            return Err(ProtocolError::TooLarge {
                bytes: self.text.len(),
                limit: max_bytes,
            });
        }

        // A NUL terminates a C string, and the Windows clipboard is a C API.
        // Text containing one would be silently truncated at the boundary,
        // which is worse than refusing it.
        if self.text.contains('\0') {
            return Err(ProtocolError::InvalidText);
        }

        Ok(())
    }

    /// The size of the payload, for the audit record.
    ///
    /// The byte count is the only thing about a clipboard synchronisation that
    /// may be written down. The content never is.
    #[must_use]
    pub fn byte_len(&self) -> usize {
        self.text.len()
    }
}

/// Prints the length, never the content.
///
/// A clipboard holds passwords. `tracing::debug!(?payload)` somewhere in the
/// agent, or a panic message containing one, is how they reach a log file — so
/// there is no way to get the text out of this type by formatting it.
impl fmt::Debug for ClipboardPayload {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("ClipboardPayload")
            .field("direction", &self.direction)
            .field("format", &self.format)
            .field("bytes", &self.text.len())
            .finish_non_exhaustive()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn text_within_the_limit_is_accepted() {
        let payload = ClipboardPayload::text("hello", ClipboardDirection::ToHost);

        assert!(payload.validate().is_ok());
        assert_eq!(payload.byte_len(), 5);
    }

    #[test]
    fn oversized_text_is_refused() {
        let payload = ClipboardPayload::text(
            "x".repeat(DEFAULT_MAX_CLIPBOARD_BYTES + 1),
            ClipboardDirection::ToController,
        );

        assert!(matches!(
            payload.validate(),
            Err(ProtocolError::TooLarge { .. })
        ));
    }

    /// The server's figure is the one that applies, not the agent's default.
    #[test]
    fn the_servers_ceiling_is_what_applies() {
        let payload = ClipboardPayload::text("x".repeat(2048), ClipboardDirection::ToHost);

        assert!(payload.validate().is_ok());
        assert!(payload.validate_within(1024).is_err());
    }

    #[test]
    fn a_non_text_format_is_refused_in_this_version() {
        let payload = ClipboardPayload {
            direction: ClipboardDirection::ToHost,
            format: ClipboardFormat::Image,
            text: String::new(),
        };

        assert_eq!(payload.validate(), Err(ProtocolError::InvalidText));
    }

    /// A NUL would silently truncate the text at the Windows clipboard's C
    /// API boundary, which is worse than refusing it outright.
    #[test]
    fn embedded_nul_bytes_are_refused() {
        let payload = ClipboardPayload::text("before\0after", ClipboardDirection::ToHost);

        assert_eq!(payload.validate(), Err(ProtocolError::InvalidText));
    }

    /// A clipboard holds passwords. Formatting one must not print it.
    #[test]
    fn debug_output_never_contains_the_content() {
        let payload = ClipboardPayload::text("hunter2-the-actual-password", ClipboardDirection::ToController);

        let rendered = format!("{payload:?}");

        assert!(!rendered.contains("hunter2"));
        assert!(rendered.contains("bytes"));
    }

    #[test]
    fn invalid_utf8_cannot_be_deserialised_at_all() {
        // 0xFF is not valid UTF-8 anywhere in a JSON string.
        let bytes = br#"{"direction":"to_host","format":"text","text":"\xff"}"#;

        assert!(serde_json::from_slice::<ClipboardPayload>(bytes).is_err());
    }

    #[test]
    fn a_payload_with_no_format_field_is_text() {
        let payload: ClipboardPayload =
            serde_json::from_str(r#"{"direction":"to_host","text":"hi"}"#).unwrap();

        assert_eq!(payload.format, ClipboardFormat::Text);
        assert!(payload.validate().is_ok());
    }
}
