//! Everything the agent says to a controller, in an envelope.
//!
//! The browser's `decodeControlEnvelope` drops anything without `v`, `s`, `p`
//! and `n`, so a bare message from the agent is a message nobody reads — which
//! is how `control_ended` once went unseen. [`OutboundEnvelopes`] is the one
//! place an agent→browser message is built, so there is no way to send one
//! that is not enveloped.
//!
//! # The sequence
//!
//! Strictly increasing per sender, as the gate requires of the controller. It
//! starts at wall-clock milliseconds rather than at one: a companion that
//! reconnects builds a new sender, and a counter that restarted at one would
//! be "older" than everything the browser already acted on from the previous
//! connection.

use std::time::{SystemTime, UNIX_EPOCH};

use crate::{ControlEnvelope, ControlMessage, ProtocolError};

/// Builds and numbers the agent's own envelopes for one session.
#[derive(Debug)]
pub struct OutboundEnvelopes {
    session_uuid: String,
    participant_uuid: String,
    last: u64,
}

impl OutboundEnvelopes {
    /// A sender for one session and participant, numbered from `start`.
    #[must_use]
    pub fn new(
        session_uuid: impl Into<String>,
        participant_uuid: impl Into<String>,
        start: u64,
    ) -> Self {
        Self {
            session_uuid: session_uuid.into(),
            participant_uuid: participant_uuid.into(),
            last: start.saturating_sub(1),
        }
    }

    /// A sender numbered from the current wall-clock millisecond.
    #[must_use]
    pub fn starting_now(
        session_uuid: impl Into<String>,
        participant_uuid: impl Into<String>,
    ) -> Self {
        Self::new(session_uuid, participant_uuid, now_millis())
    }

    /// The participant every envelope names as its sender.
    #[must_use]
    pub fn participant_uuid(&self) -> &str {
        &self.participant_uuid
    }

    /// The next envelope. Each call numbers strictly above the last.
    pub fn envelope(&mut self, message: ControlMessage) -> ControlEnvelope {
        self.last = self.last.saturating_add(1).max(1);

        ControlEnvelope::new(
            self.session_uuid.clone(),
            self.participant_uuid.clone(),
            self.last,
            message,
        )
    }

    /// The next envelope, validated and encoded for the data channel.
    ///
    /// Validated as well as encoded: the agent is held to the same bounds it
    /// holds a controller to, so a layout with a zero-sized monitor never
    /// leaves this machine.
    pub fn encode(&mut self, message: ControlMessage) -> Result<Vec<u8>, ProtocolError> {
        let envelope = self.envelope(message);
        envelope.validate()?;

        envelope.encode()
    }
}

fn now_millis() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|elapsed| u64::try_from(elapsed.as_millis()).unwrap_or(u64::MAX))
        .unwrap_or(1)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::{ControlEndReason, PauseReason};

    #[test]
    fn every_envelope_names_the_session_and_the_sender_and_counts_up() {
        let mut out = OutboundEnvelopes::new("session-1", "companion-1", 10);

        let first = out.envelope(ControlMessage::ControlResumed);
        let second = out.envelope(ControlMessage::ControlPaused {
            reason: PauseReason::LocalInput,
        });

        assert_eq!(first.session_uuid, "session-1");
        assert_eq!(first.participant_uuid, "companion-1");
        assert_eq!(first.sequence, 10);
        assert_eq!(second.sequence, 11);
        assert_eq!(out.participant_uuid(), "companion-1");
    }

    /// What `decodeControlEnvelope` in the browser requires: `v`, `s`, `p`, `n`
    /// and the message inside `m`.
    #[test]
    fn what_is_encoded_decodes_as_a_full_envelope() {
        let mut out = OutboundEnvelopes::starting_now("session-1", "companion-1");

        for message in [
            ControlMessage::ControlPaused {
                reason: PauseReason::LocalInput,
            },
            ControlMessage::ControlResumed,
            ControlMessage::ControlEnded {
                reason: ControlEndReason::StoppedLocally,
            },
            ControlMessage::Pong { nonce: 4 },
        ] {
            let bytes = out.encode(message.clone()).expect("encodes");
            let value: serde_json::Value = serde_json::from_slice(&bytes).unwrap();

            assert_eq!(value["v"], serde_json::json!(crate::PROTOCOL_VERSION));
            assert_eq!(value["s"], "session-1");
            assert_eq!(value["p"], "companion-1");
            assert!(
                value["n"].as_u64().unwrap() > 1_000_000_000_000,
                "wall clock"
            );

            let decoded = ControlEnvelope::decode(&bytes).expect("decodes");
            assert_eq!(decoded.message, message);
        }
    }

    /// A reconnect builds a new sender; its numbers must still be newer than
    /// the old one's.
    #[test]
    fn a_new_sender_numbers_above_an_older_one() {
        let mut old = OutboundEnvelopes::starting_now("s", "p");
        let last_old = (0..50)
            .map(|_| old.envelope(ControlMessage::ControlResumed).sequence)
            .last()
            .unwrap();

        std::thread::sleep(std::time::Duration::from_millis(60));
        let mut fresh = OutboundEnvelopes::starting_now("s", "p");

        assert!(fresh.envelope(ControlMessage::ControlResumed).sequence > last_old);
    }

    /// The agent is held to the bounds it holds a controller to.
    #[test]
    fn an_invalid_message_is_not_encoded() {
        let mut out = OutboundEnvelopes::new("s", "p", 1);
        let broken = crate::MonitorLayout {
            monitors: vec![],
            active_monitor_id: 1,
        };

        assert_eq!(
            out.encode(ControlMessage::MonitorLayout(broken)),
            Err(ProtocolError::OutOfBounds)
        );
    }
}
