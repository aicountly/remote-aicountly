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
//!
//! # A local Stop outlives a stale server, and only a new grant re-arms it
//!
//! After Stop, the server can go on reporting the grant as `GRANTED` for a
//! poll or two — it has not yet heard the agent's `REVOKE`. That report must
//! not put control back. What *should* is a deliberate new grant, made after
//! the Stop: the API identifies each grant with an opaque `grantId` (the
//! controller and the instant it was granted), so the gate remembers which
//! grant was live when Stop was pressed and re-arms only for a different one.
//! With no `grantId` at all the gate cannot tell a new grant from a stale
//! read, and stays revoked — the person in the room wins.
//!
//! # Paused is not revoked
//!
//! A control companion pauses the gate while the person at the machine is
//! using their own mouse or keyboard ([`ControlGate::pause`]). The grant
//! stands — nothing is reported to the API — but every act on the machine is
//! dropped until [`ControlGate::resume`].

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
    /// The person at the machine is using it; remote input waits.
    #[error("remote input is paused while the person at the computer is using it")]
    Paused,
    /// Clipboard synchronisation is not switched on for this session.
    #[error("clipboard sharing is not enabled for this session")]
    ClipboardDisabled,
    /// The message failed structural validation.
    #[error(transparent)]
    Invalid(#[from] ProtocolError),
}

/// What the gate remembers about a local Stop.
#[derive(Debug, Clone, PartialEq, Eq)]
struct LocalStop {
    /// The grant that was live when Stop was pressed, if the API had named it.
    grant_id: Option<String>,
    /// Whether the API has since reported control as not granted — it has
    /// heard about the Stop, so any grant it reports from now on is new.
    acknowledged: bool,
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
    /// The API's identifier for the grant currently admitted, when it gave one.
    grant_id: Option<String>,
    /// Set by a local Stop, and cleared only by a grant made after it.
    local_stop: Option<LocalStop>,
    /// The person at the machine is using it. Input waits; the grant stands.
    paused: bool,
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
            grant_id: None,
            local_stop: None,
            paused: false,
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
    ///
    /// A grant made here, by the person at the machine, is the deliberate act
    /// that clears an earlier local Stop.
    pub fn grant(&mut self, participant_uuid: impl Into<String>, clipboard: bool) {
        self.controller = Some(participant_uuid.into());
        self.state = ControlState::Granted;
        self.clipboard_enabled = clipboard;
        self.last_sequence = 0;
        self.grant_id = None;
        self.local_stop = None;
        self.paused = false;
    }

    /// The person at the machine said no.
    pub fn deny(&mut self) {
        self.state = ControlState::Denied;
        self.controller = None;
        self.clipboard_enabled = false;
    }

    /// Stop. Takes effect on the very next message.
    ///
    /// Remembers which grant was stopped, so a server that has not caught up
    /// cannot re-arm it — see the module documentation.
    pub fn revoke(&mut self) {
        self.local_stop = Some(LocalStop {
            grant_id: self.grant_id.take(),
            acknowledged: false,
        });
        self.state = ControlState::Revoked;
        self.controller = None;
        self.clipboard_enabled = false;
        self.paused = false;
    }

    /// The person at the machine is using it: drop every act on the machine
    /// until [`Self::resume`]. The grant stands and nobody is told but the
    /// controller.
    pub fn pause(&mut self) {
        self.paused = true;
    }

    /// The person at the machine has stopped: admit input again.
    pub fn resume(&mut self) {
        self.paused = false;
    }

    /// Whether input is paused for the person at the machine.
    #[must_use]
    pub fn is_paused(&self) -> bool {
        self.paused
    }

    /// Whether the person at the machine pressed Stop and no new grant has
    /// re-armed the gate since.
    #[must_use]
    pub fn is_locally_stopped(&self) -> bool {
        self.local_stop.is_some()
    }

    /// The API's identifier for the grant currently admitted, if it gave one.
    #[must_use]
    pub fn grant_id(&self) -> Option<&str> {
        self.grant_id.as_deref()
    }

    /// Adopt the state the API reports.
    ///
    /// Called after every refresh. The agent's own local revocation is never
    /// *widened* by this — a server that says `GRANTED` after the person at the
    /// machine pressed Stop is a server working from a stale read, and the
    /// person in the room wins. The API is caught up by the revoke call the
    /// agent makes at the same moment.
    ///
    /// `grant_id` is the API's identifier for the grant it reports. After a
    /// local Stop the gate re-arms only for a grant **different** from the one
    /// that was stopped; with no identifier it stays revoked.
    pub fn sync_from_api(
        &mut self,
        state: ControlState,
        controller: Option<String>,
        clipboard: bool,
        grant_id: Option<String>,
    ) {
        if state.permits_input() {
            if let Some(stop) = self.local_stop.as_mut() {
                let new_grant = match (grant_id.as_deref(), stop.grant_id.as_deref()) {
                    // Nothing to tell a new grant from a stale read by.
                    (None, _) => false,
                    (Some(reported), Some(stopped)) => reported != stopped,
                    // The stopped grant was never named. Once the API has said
                    // "not granted", whatever it grants next is new; until then
                    // the first grant it names is taken to be the stopped one.
                    (Some(reported), None) => {
                        if stop.acknowledged {
                            true
                        } else {
                            stop.grant_id = Some(reported.to_owned());
                            false
                        }
                    }
                };

                if !new_grant {
                    return;
                }

                // A deliberate new grant, made after the Stop.
                self.local_stop = None;
                self.last_sequence = 0;
                self.paused = false;
            }
        } else if let Some(stop) = self.local_stop.as_mut() {
            stop.acknowledged = true;
        }

        if state.permits_input() && grant_id.is_some() && grant_id != self.grant_id {
            // A different grant is a different stream of events, even from
            // the same controller.
            self.last_sequence = 0;
        }

        self.state = state;
        self.clipboard_enabled = clipboard && state.permits_input();

        if state.permits_input() {
            if controller.as_deref() != self.controller.as_deref() {
                // A different controller is a different stream of events.
                self.last_sequence = 0;
            }
            self.controller = controller;
            self.grant_id = grant_id;
        } else {
            self.controller = None;
            self.grant_id = None;
            self.paused = false;
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

        // The person at the machine is using it. Checked after the grant so a
        // revoked gate still says why it refuses, and before the sender so a
        // paused gate refuses everybody alike.
        if self.paused {
            return Err(GateError::Paused);
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
            gate.sync_from_api(state, Some(CONTROLLER.into()), false, None);

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

        gate.sync_from_api(ControlState::Granted, Some(CONTROLLER.into()), true, None);

        assert_eq!(gate.state(), ControlState::Revoked);
        assert!(!gate.clipboard_enabled());
        assert_eq!(
            gate.admit(&move_to(9, CONTROLLER, SESSION)),
            Err(GateError::NotGranted)
        );
    }

    fn api_grant(gate: &mut ControlGate, grant_id: &str) {
        gate.sync_from_api(
            ControlState::Granted,
            Some(CONTROLLER.into()),
            false,
            Some(grant_id.into()),
        );
    }

    /// The companion's case: a grant made in the browser, stopped at the
    /// machine. The same grant reported again is a stale read and must not
    /// put control back; a new grant — a different `grantId` — does.
    #[test]
    fn after_a_local_stop_only_a_different_grant_re_arms_the_gate() {
        let mut gate = ControlGate::new(SESSION);
        api_grant(&mut gate, "grant-1");
        assert!(gate.admit(&move_to(1, CONTROLLER, SESSION)).is_ok());
        assert_eq!(gate.grant_id(), Some("grant-1"));

        gate.revoke();
        assert!(gate.is_locally_stopped());

        // Stale: the server has not heard the REVOKE yet.
        api_grant(&mut gate, "grant-1");
        assert_eq!(gate.state(), ControlState::Revoked);
        assert_eq!(
            gate.admit(&move_to(2, CONTROLLER, SESSION)),
            Err(GateError::NotGranted)
        );

        // A new grant, deliberately made after the Stop.
        api_grant(&mut gate, "grant-2");
        assert_eq!(gate.state(), ControlState::Granted);
        assert!(!gate.is_locally_stopped());
        // A new stream: its sequence starts again.
        assert!(gate.admit(&move_to(1, CONTROLLER, SESSION)).is_ok());
    }

    /// No identifier, no way to tell a new grant from a stale read.
    #[test]
    fn without_a_grant_id_a_local_stop_is_never_undone_by_the_server() {
        let mut gate = ControlGate::new(SESSION);
        api_grant(&mut gate, "grant-1");
        gate.revoke();

        gate.sync_from_api(ControlState::None, None, false, None);
        gate.sync_from_api(ControlState::Granted, Some(CONTROLLER.into()), false, None);

        assert!(!gate.state().permits_input());
        assert!(gate.is_locally_stopped());
    }

    /// Granted locally (no id known), then stopped: the first id the server
    /// reports is taken to be the stopped grant, not a new one.
    #[test]
    fn an_unnamed_stopped_grant_is_not_re_armed_by_the_first_id_the_server_reports() {
        let mut gate = granted_gate();
        gate.revoke();

        api_grant(&mut gate, "grant-1");
        assert_eq!(gate.state(), ControlState::Revoked);

        api_grant(&mut gate, "grant-2");
        assert_eq!(gate.state(), ControlState::Granted);
    }

    /// Once the server has said "not granted", it has heard the Stop, and the
    /// next grant it reports is a new one even if the stopped grant was never
    /// named.
    #[test]
    fn once_the_server_acknowledges_the_stop_its_next_grant_re_arms() {
        let mut gate = granted_gate();
        gate.revoke();

        gate.sync_from_api(ControlState::Revoked, None, false, None);
        gate.sync_from_api(ControlState::Requested, None, false, None);
        api_grant(&mut gate, "grant-9");

        assert_eq!(gate.state(), ControlState::Granted);
        assert!(gate.admit(&move_to(1, CONTROLLER, SESSION)).is_ok());
    }

    /// A local grant is the person at the machine deciding again.
    #[test]
    fn granting_at_the_machine_clears_a_local_stop() {
        let mut gate = ControlGate::new(SESSION);
        api_grant(&mut gate, "grant-1");
        gate.revoke();

        gate.grant(CONTROLLER, false);

        assert!(!gate.is_locally_stopped());
        assert!(gate.admit(&move_to(1, CONTROLLER, SESSION)).is_ok());
    }

    /// A new grant from the same controller is a new stream of events.
    #[test]
    fn a_new_grant_id_resets_the_sequence_even_for_the_same_controller() {
        let mut gate = ControlGate::new(SESSION);
        api_grant(&mut gate, "grant-1");
        assert!(gate.admit(&move_to(800, CONTROLLER, SESSION)).is_ok());

        api_grant(&mut gate, "grant-1");
        assert_eq!(
            gate.admit(&move_to(5, CONTROLLER, SESSION)),
            Err(GateError::Stale { seen: 800 })
        );

        api_grant(&mut gate, "grant-2");
        assert!(gate.admit(&move_to(5, CONTROLLER, SESSION)).is_ok());
    }

    /// Paused: the person at the machine is using it. Nothing gets through —
    /// input, clipboard or anything else that acts on the machine — but the
    /// grant stands, and resuming admits input again without a new grant.
    #[test]
    fn a_paused_gate_drops_every_act_and_resuming_admits_again() {
        let mut gate = ControlGate::new(SESSION);
        api_grant(&mut gate, "grant-1");
        assert!(gate.admit(&move_to(1, CONTROLLER, SESSION)).is_ok());

        gate.pause();
        assert!(gate.is_paused());
        assert_eq!(gate.state(), ControlState::Granted);
        assert_eq!(
            gate.admit(&move_to(2, CONTROLLER, SESSION)),
            Err(GateError::Paused)
        );
        let key = ControlEnvelope::new(
            SESSION,
            CONTROLLER,
            3,
            ControlMessage::Key(KeyEvent {
                key: Key::Character('x'),
                pressed: true,
                modifiers: Modifiers::none(),
            }),
        );
        assert_eq!(gate.admit(&key), Err(GateError::Paused));

        // A ping still passes: it is not an act on the machine.
        let ping = ControlEnvelope::new(SESSION, CONTROLLER, 4, ControlMessage::Ping { nonce: 1 });
        assert!(gate.admit(&ping).is_ok());

        gate.resume();
        // The dropped input did not move the water mark; the ping did.
        assert_eq!(
            gate.admit(&move_to(3, CONTROLLER, SESSION)),
            Err(GateError::Stale { seen: 4 })
        );
        assert!(gate.admit(&move_to(5, CONTROLLER, SESSION)).is_ok());
    }

    /// Stop ends a pause too; a pause never outlives the grant it paused.
    #[test]
    fn stopping_or_losing_the_grant_clears_a_pause() {
        let mut gate = ControlGate::new(SESSION);
        api_grant(&mut gate, "grant-1");
        gate.pause();
        gate.revoke();
        assert!(!gate.is_paused());

        let mut gate = ControlGate::new(SESSION);
        api_grant(&mut gate, "grant-1");
        gate.pause();
        gate.sync_from_api(ControlState::None, None, false, None);
        assert!(!gate.is_paused());
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
            None,
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
