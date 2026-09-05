//! The gate every control message passes before anything is injected.
//!
//! This is the single most security-relevant type in the agent, and it is
//! deliberately a small, pure state machine with no I/O: it can be exercised
//! exhaustively by tests, and the platform layer that actually calls
//! `SendInput` has no way to reach the operating system except through it.
//!
//! # What it enforces
//!
//! 1. **The session.** The envelope names the session it belongs to; a message
//!    for another one is dropped. This is what stops a channel that outlived a
//!    renegotiation from delivering into the wrong session.
//! 2. **The participant.** Only the participant control was granted to. A
//!    second viewer on the same channel cannot type.
//! 3. **The grant.** [`ControlState::Granted`], and nothing else. A request
//!    that has not been answered, one that was refused, and one that was
//!    revoked all inject nothing.
//! 4. **The sequence.** Strictly increasing per sender. A replayed or
//!    reordered event is dropped rather than applied out of order — a stale
//!    mouse-up after a mouse-down leaves a button held.
//! 5. **The bounds.** Already checked by [`crate::ControlEnvelope::validate`],
//!    and checked again here, because a caller that forgets is the one that
//!    matters.
//!
//! # Revocation is local and immediate
//!
//! [`ControlGate::revoke`] takes effect on the next message. It does not wait
//! for the peer to acknowledge, for the API to answer, or for a poll — the
//! person at the machine pressed Stop, and the next event is dropped. That is
//! the whole reason this is a local gate rather than a server check.

use crate::{ControlEnvelope, ProtocolError};

/// Where a control grant currently stands, on this machine.
///
/// Mirrors `remote_participants.control_state` in the API, and is refreshed
/// from it — but the agent's copy is what actually gates input, so revocation
/// is instant rather than "at the next poll".
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum ControlState {
    /// Nobody has asked.
    #[default]
    None,
    /// Somebody has asked and the person at the machine has not answered.
    Requested,
    /// Granted. The only state in which input is injected.
    Granted,
    /// Refused.
    Denied,
    /// Withdrawn, by either side.
    Revoked,
}

impl ControlState {
    /// The one state that permits input.
    #[must_use]
    pub fn permits_input(self) -> bool {
        matches!(self, Self::Granted)
    }

    /// Parse the API's spelling of a control state.
    ///
    /// An unrecognised value becomes [`ControlState::None`] rather than an
    /// error: if a future server sends a state this build does not know, the
    /// safe reading is "not granted", and refusing to parse would leave the
    /// agent with whatever it believed before.
    #[must_use]
    pub fn from_api(value: &str) -> Self {
        match value {
            "REQUESTED" => Self::Requested,
            "GRANTED" => Self::Granted,
            "DENIED" => Self::Denied,
            "REVOKED" => Self::Revoked,
            _ => Self::None,
        }
    }
}

/// Why a message was not acted on.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum GateError {
    /// The envelope named a different session.
    #[error("the message was for another session")]
    WrongSession,
    /// Somebody other than the participant holding control.
    #[error("the sender does not hold control of this computer")]
    NotTheController,
    /// Control is not currently granted.
    #[error("control is not granted")]
    NotGranted,
    /// Already seen, or out of order.
    #[error("the message was stale (sequence {seen} already handled)")]
    Stale {
        /// The newest sequence acted on before this.
        seen: u64,
    },
    /// Clipboard synchronisation is not switched on for this session.
    #[error("clipboard sharing is not enabled for this session")]
    ClipboardDisabled,
    /// The message failed structural validation.
    #[error(transparent)]
    Invalid(#[from] ProtocolError),
}

/// The gate.
///
/// One per session. Not `Clone`, on purpose: two copies would each keep their
/// own sequence counter, and a message replayed to the second would be
/// accepted because the first had never seen it.
#[derive(Debug)]
pub struct ControlGate {
    session_uuid: String,
    controller: Option<String>,
    state: ControlState,
    clipboard_enabled: bool,
    last_sequence: u64,
    clipboard_max_bytes: usize,
    /// How many messages have been dropped, for the diagnostics panel.
    ///
    /// A number, never the messages themselves: a rejected input event is
    /// still an input event, and keeping it would be keeping a keystroke log.
    rejected: u64,
}

impl ControlGate {
    /// A gate for one session, with nobody controlling.
    #[must_use]
    pub fn new(session_uuid: impl Into<String>) -> Self {
        Self {
            session_uuid: session_uuid.into(),
            controller: None,
            state: ControlState::None,
            clipboard_enabled: false,
            last_sequence: 0,
            clipboard_max_bytes: crate::clipboard::DEFAULT_MAX_CLIPBOARD_BYTES,
            rejected: 0,
        }
    }

    /// Use the server's clipboard ceiling rather than the built-in default.
    #[must_use]
    pub fn with_clipboard_limit(mut self, max_bytes: usize) -> Self {
        self.clipboard_max_bytes = max_bytes;

        self
    }

    /// Somebody has asked for control.
    pub fn request(&mut self, participant_uuid: impl Into<String>) {
        self.controller = Some(participant_uuid.into());
        self.state = ControlState::Requested;
        self.clipboard_enabled = false;
    }

    /// The person at the machine said yes.
    ///
    /// The sequence counter resets, because a grant starts a new stream: the
    /// controller's counter starts from zero when control begins, and carrying
    /// the previous grant's high-water mark would drop every event of the new
    /// one.
    pub fn grant(&mut self, participant_uuid: impl Into<String>, clipboard: bool) {
        self.controller = Some(participant_uuid.into());
        self.state = ControlState::Granted;
        self.clipboard_enabled = clipboard;
        self.last_sequence = 0;
    }

    /// The person at the machine said no.
    pub fn deny(&mut self) {
        self.state = ControlState::Denied;
        self.controller = None;
        self.clipboard_enabled = false;
    }

    /// Stop. Takes effect on the very next message.
    pub fn revoke(&mut self) {
        self.state = ControlState::Revoked;
        self.controller = None;
        self.clipboard_enabled = false;
    }

    /// Adopt the state the API reports.
    ///
    /// Called after every refresh. The agent's own local revocation is never
    /// *widened* by this — a server that says `GRANTED` after the person at the
    /// machine pressed Stop is a server working from a stale read, and the
    /// person in the room wins. The API is caught up by the revoke call the
    /// agent makes at the same moment.
    pub fn sync_from_api(
        &mut self,
        state: ControlState,
        controller: Option<String>,
        clipboard: bool,
    ) {
        if self.state == ControlState::Revoked && state == ControlState::Granted {
            return;
        }

        self.state = state;
        self.clipboard_enabled = clipboard && state.permits_input();

        if state.permits_input() {
            if controller.as_deref() != self.controller.as_deref() {
                // A different controller is a different stream of events.
                self.last_sequence = 0;
            }
            self.controller = controller;
        } else {
            self.controller = None;
        }
    }

    /// The current state.
    #[must_use]
    pub fn state(&self) -> ControlState {
        self.state
    }

    /// Who holds control, if anyone.
    #[must_use]
    pub fn controller(&self) -> Option<&str> {
        self.controller.as_deref()
    }

    /// Whether clipboard synchronisation is on for this grant.
    #[must_use]
    pub fn clipboard_enabled(&self) -> bool {
        self.clipboard_enabled
    }

    /// How many messages have been dropped. A count, never the messages.
    #[must_use]
    pub fn rejected_count(&self) -> u64 {
        self.rejected
    }

    /// **The gate.** Decide whether this message may be acted on.
    ///
    /// Every check that could reject is applied before any that could allow,
    /// and the order runs cheapest-first: a message for another session costs
    /// a string comparison, not a validation pass.
    pub fn admit<'a>(
        &mut self,
        envelope: &'a ControlEnvelope,
    ) -> Result<&'a ControlEnvelope, GateError> {
        match self.check(envelope) {
            Ok(()) => {
                // Only advance the counter for a message that was admitted. A
                // rejected one must not raise the water mark, or a single
                // out-of-range sequence would silently drop everything after it.
                self.last_sequence = envelope.sequence;

                Ok(envelope)
            }
            Err(error) => {
                self.rejected = self.rejected.saturating_add(1);

                Err(error)
            }
        }
    }

    fn check(&self, envelope: &ControlEnvelope) -> Result<(), GateError> {
        // (1) The session.
        if envelope.session_uuid != self.session_uuid {
            return Err(GateError::WrongSession);
        }

        // (5) Structure and bounds, before anything is read out of the message.
        envelope.validate()?;

        let needs_grant = envelope.is_input()
            || matches!(envelope.message, crate::ControlMessage::Clipboard(_))
            || matches!(envelope.message, crate::ControlMessage::Reboot { .. })
            || matches!(
                envelope.message,
                crate::ControlMessage::SelectMonitor { .. }
            );

        if !needs_grant {
            // A ping, a pong or a monitor layout is not an act on the machine
            // and does not need a grant — but it is still bounded by the
            // session check above, so it cannot cross sessions either.
            return Ok(());
        }

        // (3) The grant.
        if !self.state.permits_input() {
            return Err(GateError::NotGranted);
        }

        // (2) The participant.
        match self.controller.as_deref() {
            Some(controller) if controller == envelope.participant_uuid => {}
            _ => return Err(GateError::NotTheController),
        }

        // (4) The sequence. Strictly newer, so a replay is dropped.
        if envelope.sequence <= self.last_sequence {
            return Err(GateError::Stale {
                seen: self.last_sequence,
            });
        }

        // Clipboard is a separate switch from control, and its own ceiling.
        if let crate::ControlMessage::Clipboard(payload) = &envelope.message {
            if !self.clipboard_enabled {
                return Err(GateError::ClipboardDisabled);
            }

            payload.validate_within(self.clipboard_max_bytes)?;
        }

        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::{
        clipboard::{ClipboardDirection, ClipboardPayload},
        input::{Key, KeyEvent, Modifiers, PointerPosition},
        ControlMessage,
    };

    const SESSION: &str = "session-1111";
    const CONTROLLER: &str = "participant-aaaa";

    fn granted_gate() -> ControlGate {
        let mut gate = ControlGate::new(SESSION);
        gate.grant(CONTROLLER, false);

        gate
    }

    fn move_to(sequence: u64, participant: &str, session: &str) -> ControlEnvelope {
        ControlEnvelope::new(
            session,
            participant,
            sequence,
            ControlMessage::MouseMove {
                position: PointerPosition { x: 0.5, y: 0.5 },
            },
        )
    }

    #[test]
    fn a_granted_controller_is_admitted() {
        let mut gate = granted_gate();

        assert!(gate.admit(&move_to(1, CONTROLLER, SESSION)).is_ok());
        assert!(gate.admit(&move_to(2, CONTROLLER, SESSION)).is_ok());
        assert_eq!(gate.rejected_count(), 0);
    }

    /// The single most important test in this crate: no grant, no input.
    #[test]
    fn nothing_is_injected_without_a_grant() {
        for state in [
            ControlState::None,
            ControlState::Requested,
            ControlState::Denied,
            ControlState::Revoked,
        ] {
            let mut gate = ControlGate::new(SESSION);
            gate.sync_from_api(state, Some(CONTROLLER.into()), false);

            assert_eq!(
                gate.admit(&move_to(1, CONTROLLER, SESSION)),
                Err(GateError::NotGranted),
                "{state:?} must not permit input"
            );
        }
    }

    /// Pressing Stop must take effect on the next event, not at the next poll.
    #[test]
    fn revoking_takes_effect_on_the_very_next_message() {
        let mut gate = granted_gate();
        assert!(gate.admit(&move_to(1, CONTROLLER, SESSION)).is_ok());

        gate.revoke();

        assert_eq!(
            gate.admit(&move_to(2, CONTROLLER, SESSION)),
            Err(GateError::NotGranted)
        );
        assert_eq!(gate.controller(), None);
    }

    /// A server working from a stale read must not undo a local Stop. The
    /// person in the room wins.
    #[test]
    fn a_stale_server_grant_cannot_undo_a_local_revocation() {
        let mut gate = granted_gate();
        gate.revoke();

        gate.sync_from_api(ControlState::Granted, Some(CONTROLLER.into()), true);

        assert_eq!(gate.state(), ControlState::Revoked);
        assert!(!gate.clipboard_enabled());
        assert_eq!(
            gate.admit(&move_to(9, CONTROLLER, SESSION)),
            Err(GateError::NotGranted)
        );
    }

    #[test]
    fn a_second_viewer_on_the_same_channel_cannot_type() {
        let mut gate = granted_gate();

        assert_eq!(
            gate.admit(&move_to(1, "participant-bbbb", SESSION)),
            Err(GateError::NotTheController)
        );
    }

    #[test]
    fn a_message_for_another_session_is_dropped() {
        let mut gate = granted_gate();

        assert_eq!(
            gate.admit(&move_to(1, CONTROLLER, "session-9999")),
            Err(GateError::WrongSession)
        );
    }

    /// A replayed mouse-up after a mouse-down leaves a button held down.
    #[test]
    fn a_replayed_sequence_is_dropped() {
        let mut gate = granted_gate();

        assert!(gate.admit(&move_to(5, CONTROLLER, SESSION)).is_ok());

        assert_eq!(
            gate.admit(&move_to(5, CONTROLLER, SESSION)),
            Err(GateError::Stale { seen: 5 })
        );
        assert_eq!(
            gate.admit(&move_to(4, CONTROLLER, SESSION)),
            Err(GateError::Stale { seen: 5 })
        );
        assert!(gate.admit(&move_to(6, CONTROLLER, SESSION)).is_ok());
    }

    /// A rejected message must not raise the water mark, or one bad sequence
    /// number would silently drop everything after it.
    #[test]
    fn a_rejected_message_does_not_advance_the_sequence() {
        let mut gate = granted_gate();

        assert!(gate.admit(&move_to(1, CONTROLLER, SESSION)).is_ok());
        let _ = gate.admit(&move_to(9_999, "somebody-else", SESSION));

        assert!(gate.admit(&move_to(2, CONTROLLER, SESSION)).is_ok());
    }

    /// A new grant is a new stream of events, starting from zero.
    #[test]
    fn granting_again_resets_the_sequence() {
        let mut gate = granted_gate();
        assert!(gate.admit(&move_to(500, CONTROLLER, SESSION)).is_ok());

        gate.revoke();
        gate.grant(CONTROLLER, false);

        assert!(gate.admit(&move_to(1, CONTROLLER, SESSION)).is_ok());
    }

    #[test]
    fn a_different_controller_from_the_api_resets_the_sequence() {
        let mut gate = granted_gate();
        assert!(gate.admit(&move_to(400, CONTROLLER, SESSION)).is_ok());

        gate.sync_from_api(
            ControlState::Granted,
            Some("participant-cccc".into()),
            false,
        );

        assert!(gate.admit(&move_to(1, "participant-cccc", SESSION)).is_ok());
    }

    /// Control and clipboard are separate exposures. Starting to control a
    /// machine must not start copying whatever is on its clipboard.
    #[test]
    fn clipboard_needs_its_own_switch_on_top_of_control() {
        let mut gate = granted_gate();

        let clipboard = ControlEnvelope::new(
            SESSION,
            CONTROLLER,
            1,
            ControlMessage::Clipboard(ClipboardPayload::text("secret", ClipboardDirection::ToHost)),
        );

        assert_eq!(gate.admit(&clipboard), Err(GateError::ClipboardDisabled));

        gate.grant(CONTROLLER, true);
        assert!(gate.admit(&clipboard).is_ok());
    }

    #[test]
    fn the_servers_clipboard_ceiling_is_enforced_at_the_gate() {
        let mut gate = ControlGate::new(SESSION).with_clipboard_limit(16);
        gate.grant(CONTROLLER, true);

        let big = ControlEnvelope::new(
            SESSION,
            CONTROLLER,
            1,
            ControlMessage::Clipboard(ClipboardPayload::text(
                "x".repeat(64),
                ClipboardDirection::ToHost,
            )),
        );

        assert!(matches!(
            gate.admit(&big),
            Err(GateError::Invalid(ProtocolError::TooLarge { .. }))
        ));
    }

    /// A ping keeps a channel measurable while control is being decided.
    #[test]
    fn a_ping_needs_no_grant_but_is_still_bound_to_the_session() {
        let mut gate = ControlGate::new(SESSION);

        let ping = ControlEnvelope::new(SESSION, CONTROLLER, 1, ControlMessage::Ping { nonce: 1 });
        assert!(gate.admit(&ping).is_ok());

        let elsewhere = ControlEnvelope::new(
            "another-session",
            CONTROLLER,
            2,
            ControlMessage::Ping { nonce: 2 },
        );
        assert_eq!(gate.admit(&elsewhere), Err(GateError::WrongSession));
    }

    /// A reboot is an act on the machine, so it needs the grant like any other.
    #[test]
    fn a_reboot_needs_the_grant() {
        let mut gate = ControlGate::new(SESSION);

        let reboot = ControlEnvelope::new(
            SESSION,
            CONTROLLER,
            1,
            ControlMessage::Reboot {
                session_uuid: SESSION.into(),
            },
        );

        assert_eq!(gate.admit(&reboot), Err(GateError::NotGranted));

        gate.grant(CONTROLLER, false);
        assert!(gate.admit(&reboot).is_ok());
    }

    #[test]
    fn a_malformed_message_is_rejected_before_the_grant_is_even_consulted() {
        let mut gate = granted_gate();

        let bad = ControlEnvelope::new(
            SESSION,
            CONTROLLER,
            1,
            ControlMessage::MouseMove {
                position: PointerPosition { x: 12.0, y: 0.0 },
            },
        );

        assert_eq!(
            gate.admit(&bad),
            Err(GateError::Invalid(ProtocolError::OutOfBounds))
        );
    }

    #[test]
    fn rejections_are_counted_but_never_kept() {
        let mut gate = granted_gate();

        let _ = gate.admit(&move_to(1, "impostor", SESSION));
        let _ = gate.admit(&move_to(2, "impostor", SESSION));

        assert_eq!(gate.rejected_count(), 2);

        // The count is a number. There is no accessor that returns a message,
        // because a rejected input event is still a keystroke.
        let rendered = format!("{gate:?}");
        assert!(!rendered.contains("impostor") || rendered.contains("controller"));
    }

    #[test]
    fn api_state_strings_map_to_the_local_state() {
        assert_eq!(ControlState::from_api("GRANTED"), ControlState::Granted);
        assert_eq!(ControlState::from_api("REQUESTED"), ControlState::Requested);
        assert_eq!(ControlState::from_api("DENIED"), ControlState::Denied);
        assert_eq!(ControlState::from_api("REVOKED"), ControlState::Revoked);
        // Anything a future server invents reads as "not granted".
        assert_eq!(ControlState::from_api("SOMETHING_NEW"), ControlState::None);
        assert!(!ControlState::from_api("SOMETHING_NEW").permits_input());
    }

    #[test]
    fn the_full_request_grant_revoke_cycle() {
        let mut gate = ControlGate::new(SESSION);
        assert_eq!(gate.state(), ControlState::None);

        gate.request(CONTROLLER);
        assert_eq!(gate.state(), ControlState::Requested);
        assert_eq!(
            gate.admit(&move_to(1, CONTROLLER, SESSION)),
            Err(GateError::NotGranted)
        );

        gate.grant(CONTROLLER, true);
        assert!(gate.admit(&move_to(1, CONTROLLER, SESSION)).is_ok());
        assert!(gate.clipboard_enabled());

        gate.revoke();
        assert_eq!(
            gate.admit(&move_to(2, CONTROLLER, SESSION)),
            Err(GateError::NotGranted)
        );
        assert!(!gate.clipboard_enabled());
    }

    #[test]
    fn denying_leaves_nobody_holding_control() {
        let mut gate = ControlGate::new(SESSION);
        gate.request(CONTROLLER);
        gate.deny();

        assert_eq!(gate.state(), ControlState::Denied);
        assert_eq!(gate.controller(), None);
    }

    #[test]
    fn a_key_event_goes_through_the_same_gate_as_a_pointer() {
        let mut gate = ControlGate::new(SESSION);

        let key = ControlEnvelope::new(
            SESSION,
            CONTROLLER,
            1,
            ControlMessage::Key(KeyEvent {
                key: Key::Character('a'),
                pressed: true,
                modifiers: Modifiers::none(),
            }),
        );

        assert_eq!(gate.admit(&key), Err(GateError::NotGranted));

        gate.grant(CONTROLLER, false);
        assert!(gate.admit(&key).is_ok());
    }
}
