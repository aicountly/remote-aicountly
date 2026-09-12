//! The agent: what the window renders, and what actually happens.
//!
//! One [`Agent`] per process. It owns the device identity, the API client, the
//! state machine and the control gate, and everything the UI can do goes
//! through it — so there is exactly one place where "is a session running?"
//! and "may this input be injected?" are answered.
//!
//! # Why the gate lives here and not in the platform layer
//!
//! Because [`ControlGate`] is the *only* path from the network to
//! `SendInput`, and a second path is the bug this design exists to prevent.
//! `platform::windows::input` has no idea a session exists; it injects what it
//! is given. What decides whether to give it anything is [`Agent::handle_control`],
//! and that runs every message through the gate first.

use std::sync::Mutex;

use remote_core::{AgentConfig, AgentEvent, AgentState, ControlStateView, UnattendedState};
use remote_core::{ApiClient, ControlDecision, DeviceCredential};
use remote_device::{
    AgentCapabilities, DeviceDescription, PermissionSummary, PlatformProviders, PlatformResult,
};
use remote_protocol::{
    ControlEnvelope, ControlGate, ControlMessage, ControlState, GateError, ProtocolError,
};
use remote_security::{DeviceKeypair, EnrolmentRecord, KeyError, StorageScope, DEVICE_KEY_ENTRY};

use crate::platform;

/// Everything the process holds.
pub struct Agent {
    state: Mutex<AgentState>,
    config: Mutex<AgentConfig>,
    /// The gate for the session currently running, if one is.
    gate: Mutex<Option<ControlGate>>,
    /// The platform providers. `None` when the platform has none.
    providers: Mutex<Option<PlatformProviders>>,
    /// What this machine is, read once at startup.
    description: Mutex<Option<DeviceDescription>>,
    /// The device credential the connection loop currently holds.
    ///
    /// In memory only, and never written anywhere. It is here rather than
    /// inside the loop so that a decision made in the window — Allow, Not now,
    /// Stop control — can be reported to the API without waiting for the loop
    /// to come round again.
    credential: Mutex<Option<DeviceCredential>>,
}

impl Default for Agent {
    fn default() -> Self {
        Self::new()
    }
}

impl Agent {
    /// A new agent on the default configuration, not yet enrolled.
    #[must_use]
    pub fn new() -> Self {
        Self {
            state: Mutex::new(AgentState::not_enrolled(crate::VERSION)),
            config: Mutex::new(AgentConfig::default()),
            gate: Mutex::new(None),
            providers: Mutex::new(platform::providers().ok()),
            description: Mutex::new(None),
            credential: Mutex::new(None),
        }
    }

    /// A new agent on this machine's stored configuration.
    ///
    /// Read from the machine-wide file the service also reads, so the two
    /// halves of the agent cannot end up pointing at different deployments. A
    /// missing or unreadable file gives the default, which points at
    /// production: an agent that refused to start because somebody edited a
    /// JSON file would be an agent needing a visit to the machine, and that is
    /// the one thing a remote-support tool must not need.
    #[must_use]
    pub fn load() -> Self {
        let agent = Self::new();

        if let Ok(mut held) = agent.config.lock() {
            *held = AgentConfig::load();
        }

        agent
    }

    /// The state the window renders from.
    #[must_use]
    pub fn state(&self) -> AgentState {
        self.state
            .lock()
            .map(|state| state.clone())
            .unwrap_or_else(|_| AgentState::not_enrolled(crate::VERSION))
    }

    /// Apply an event to the state machine.
    pub fn apply(&self, event: AgentEvent) -> AgentState {
        let Ok(mut state) = self.state.lock() else {
            return self.state();
        };

        *state = state.clone().apply(event);

        state.clone()
    }

    /// The configuration.
    #[must_use]
    pub fn config(&self) -> AgentConfig {
        self.config
            .lock()
            .map(|config| config.clone())
            .unwrap_or_default()
    }

    /// Replace the configuration, after validating it, and write it back.
    ///
    /// Validated before it is stored and stored before it is written, so a
    /// value the agent would refuse never reaches the file the service reads
    /// on its next start.
    pub fn set_config(&self, config: AgentConfig) -> Result<(), AgentError> {
        config.validate().map_err(AgentError::Config)?;

        if let Ok(mut held) = self.config.lock() {
            *held = config.clone();
        }

        // A write that fails leaves the running agent on the new setting and
        // the next start on the old one. Worth reporting; not worth discarding
        // a change the person just made.
        config.save().map_err(AgentError::Config)
    }

    /// What the machine currently permits.
    ///
    /// Read from the platform every time rather than cached: the background
    /// service can be stopped between two calls, and a cached "Ready" would
    /// leave the Permissions panel showing something untrue.
    pub fn permissions(&self) -> PermissionSummary {
        self.providers
            .lock()
            .ok()
            .and_then(|providers| {
                providers
                    .as_ref()
                    .and_then(|providers| providers.permissions.summary().ok())
            })
            .unwrap_or_else(PermissionSummary::all_unsupported)
    }

    /// What this build declares it can do, given what the machine permits.
    ///
    /// This is the value sent at enrolment. It is an upper bound the server
    /// intersects with policy — and it is already constrained by the machine,
    /// so an agent on a box with no background service does not enrol claiming
    /// it can be reached unattended.
    pub fn capabilities(&self) -> AgentCapabilities {
        self.permissions()
            .constrain(platform::declared_capabilities())
    }

    /// Describe this machine, for enrolment and for the About panel.
    pub fn describe(&self) -> PlatformResult<DeviceDescription> {
        if let Ok(cached) = self.description.lock() {
            if let Some(description) = cached.as_ref() {
                return Ok(description.clone());
            }
        }

        let providers = self
            .providers
            .lock()
            .map_err(|_| remote_device::PlatformError::Os {
                operation: "reading this computer's details",
                detail: "the platform layer was unavailable".into(),
            })?;

        let providers = providers
            .as_ref()
            .ok_or(remote_device::PlatformError::Unsupported(
                "This operating system",
            ))?;

        let description = DeviceDescription::read(providers.device_info.as_ref(), crate::VERSION)?;

        if let Ok(mut cached) = self.description.lock() {
            *cached = Some(description.clone());
        }

        Ok(description)
    }

    // ----------------------------------------------------------- enrolment

    /// Generate a keypair and store the private half.
    ///
    /// The public half is returned for the enrolment call. The private half
    /// goes straight into the platform's key store and is never returned,
    /// logged, or held anywhere a caller could reach it.
    pub fn create_device_key(&self) -> Result<String, AgentError> {
        let keypair = DeviceKeypair::generate();
        let public_key = keypair.public_key_base64();

        platform::secure_storage()
            .store(
                DEVICE_KEY_ENTRY,
                keypair.secret_bytes().as_ref(),
                StorageScope::LocalMachine,
            )
            .map_err(|error| AgentError::Key(KeyError::Storage(error)))?;

        Ok(public_key)
    }

    /// The device key, if this machine has one.
    pub fn device_key(&self) -> Result<DeviceKeypair, AgentError> {
        let bytes = platform::secure_storage()
            .load(DEVICE_KEY_ENTRY, StorageScope::LocalMachine)
            .map_err(|error| AgentError::Key(KeyError::Storage(error)))?
            .ok_or(AgentError::Key(KeyError::NotEnrolled))?;

        DeviceKeypair::from_secret_bytes(&bytes).map_err(AgentError::Key)
    }

    /// Remove the device key from this machine.
    ///
    /// The local half of unregistering. The server-side revocation is a
    /// separate call, and both happen — a key left on a machine whose device
    /// row was revoked is a key that authenticates nothing but is still there.
    pub fn forget_device_key(&self) -> Result<(), AgentError> {
        platform::secure_storage()
            .delete(DEVICE_KEY_ENTRY, StorageScope::LocalMachine)
            .map_err(|error| AgentError::Key(KeyError::Storage(error)))
    }

    /// Record the enrolment locally.
    pub fn record_enrolment(&self, record: &EnrolmentRecord) -> AgentState {
        self.apply(AgentEvent::Enrolled {
            device_uuid: record.device_uuid.clone(),
            device_name: record.device_name.clone(),
            company_name: None,
            key_fingerprint: record.key_fingerprint.clone(),
        })
    }

    // ---------------------------------------------------------- credential

    /// Record the credential the connection loop obtained.
    pub fn set_credential(&self, credential: Option<DeviceCredential>) {
        if let Ok(mut held) = self.credential.lock() {
            *held = credential;
        }
    }

    /// A client carrying the current credential, if there is one.
    ///
    /// A fresh client rather than a shared one: sharing would mean a slow
    /// presence request blocking an urgent Stop control report, which is the
    /// wrong way round for the two of them.
    pub fn device_client(&self) -> Option<ApiClient> {
        let credential = self.credential.lock().ok()?.clone()?;

        ApiClient::new(self.config())
            .ok()
            .map(|client| client.with_credential(credential))
    }

    /// Tell the API what the person at this machine decided about control.
    ///
    /// Fire and forget, deliberately. The gate has already applied the
    /// decision locally and no answer from the network can change that — so
    /// this reports, and a failure to report leaves the machine in the state
    /// the person chose rather than in the one the server last heard about.
    pub fn report_control_decision(
        &self,
        session_uuid: &str,
        participant_uuid: &str,
        decision: ControlDecision,
        clipboard: bool,
    ) {
        let Some(client) = self.device_client() else {
            return;
        };

        let session = session_uuid.to_owned();
        let participant = participant_uuid.to_owned();

        tauri::async_runtime::spawn(async move {
            if let Err(error) = client
                .report_control_decision(&session, &participant, decision, clipboard)
                .await
            {
                tracing::warn!(%error, "the control decision could not be reported");
            }
        });
    }

    // ------------------------------------------------------------- control

    /// Begin a session, and with it a fresh gate.
    pub fn begin_session(&self, summary: remote_core::SessionSummary) -> AgentState {
        if let Ok(mut gate) = self.gate.lock() {
            *gate = Some(ControlGate::new(summary.session_uuid.clone()));
        }

        self.apply(AgentEvent::SessionStarted(summary))
    }

    /// End the session, releasing every key the controller was holding.
    ///
    /// The release is the important half: a controller whose tab closed
    /// mid-chord leaves Ctrl down on this machine, which reads to the person
    /// sitting here as a broken keyboard.
    pub fn end_session(&self) -> AgentState {
        if let Ok(mut gate) = self.gate.lock() {
            *gate = None;
        }

        self.release_all_input();

        self.apply(AgentEvent::SessionEnded)
    }

    /// Somebody has asked for control.
    pub fn control_requested(&self, participant_uuid: &str) -> AgentState {
        if let Ok(mut gate) = self.gate.lock() {
            if let Some(gate) = gate.as_mut() {
                gate.request(participant_uuid);
            }
        }

        self.apply(AgentEvent::ControlChanged {
            state: ControlStateView::Requested,
            clipboard: false,
        })
    }

    /// The person at the machine said yes.
    pub fn grant_control(&self, participant_uuid: &str, clipboard: bool) -> AgentState {
        if let Ok(mut gate) = self.gate.lock() {
            if let Some(gate) = gate.as_mut() {
                gate.grant(participant_uuid, clipboard);
            }
        }

        // The gate first, the network second. Nothing below can change what
        // the person just decided; it only tells the rest of the system.
        if let Some(session) = self.session_uuid() {
            self.report_control_decision(
                &session,
                participant_uuid,
                ControlDecision::Grant,
                clipboard,
            );
        }

        self.apply(AgentEvent::ControlChanged {
            state: ControlStateView::Granted,
            clipboard,
        })
    }

    /// The person at the machine said no.
    pub fn deny_control(&self) -> AgentState {
        let requester = self.controller_uuid();

        if let Ok(mut gate) = self.gate.lock() {
            if let Some(gate) = gate.as_mut() {
                gate.deny();
            }
        }

        if let (Some(session), Some(participant)) = (self.session_uuid(), requester) {
            self.report_control_decision(&session, &participant, ControlDecision::Deny, false);
        }

        self.apply(AgentEvent::ControlChanged {
            state: ControlStateView::Denied,
            clipboard: false,
        })
    }

    /// **Stop.** Immediately, locally, without asking anybody.
    ///
    /// This is the Stop control button, and it is the reason the gate is local
    /// rather than a server check: the next input event is dropped whatever
    /// the API thinks and whatever the network is doing. The API is told
    /// afterwards, and if that call fails the machine is still not being
    /// controlled.
    pub fn stop_control(&self) -> AgentState {
        let controller = self.controller_uuid();

        if let Ok(mut gate) = self.gate.lock() {
            if let Some(gate) = gate.as_mut() {
                gate.revoke();
            }
        }

        self.release_all_input();

        // Reported after the fact and never waited on: if the network is the
        // reason somebody pressed Stop, waiting for it would be the worst
        // possible behaviour.
        if let (Some(session), Some(participant)) = (self.session_uuid(), controller) {
            self.report_control_decision(&session, &participant, ControlDecision::Revoke, false);
        }

        self.apply(AgentEvent::ControlChanged {
            state: ControlStateView::Revoked,
            clipboard: false,
        })
    }

    /// The session currently running, if one is.
    fn session_uuid(&self) -> Option<String> {
        self.state()
            .active_session()
            .map(|session| session.session_uuid.clone())
    }

    /// Whoever the gate currently names as the controller.
    fn controller_uuid(&self) -> Option<String> {
        self.gate.lock().ok().and_then(|gate| {
            gate.as_ref()
                .and_then(|gate| gate.controller().map(str::to_owned))
        })
    }

    /// Adopt the control state the API reports.
    pub fn sync_control(
        &self,
        state: ControlState,
        controller: Option<String>,
        clipboard: bool,
    ) -> AgentState {
        if let Ok(mut gate) = self.gate.lock() {
            if let Some(gate) = gate.as_mut() {
                gate.sync_from_api(state, controller, clipboard);
            }
        }

        self.apply(AgentEvent::ControlChanged {
            state: state.into(),
            clipboard,
        })
    }

    /// Unattended access changed, here or in the console.
    pub fn set_unattended(&self, unattended: UnattendedState) -> AgentState {
        self.apply(AgentEvent::UnattendedChanged(unattended))
    }

    /// An administrator revoked this device.
    pub fn revoked(&self) -> AgentState {
        if let Ok(mut gate) = self.gate.lock() {
            *gate = None;
        }

        self.release_all_input();

        self.apply(AgentEvent::Revoked)
    }

    /// **The only path from the network to the operating system.**
    ///
    /// A message arrives, the gate decides, and only then does anything reach
    /// the platform layer. Every rejection is a drop: nothing is queued,
    /// retried or approximated, because a stale or unauthorised input event is
    /// not something to guess about on a machine somebody else is sitting at.
    pub fn handle_control(&self, bytes: &[u8]) -> Result<Handled, GateError> {
        let envelope = ControlEnvelope::decode(bytes).map_err(GateError::Invalid)?;

        let mut gate = self
            .gate
            .lock()
            .map_err(|_| GateError::Invalid(ProtocolError::Malformed))?;

        let gate = gate.as_mut().ok_or(GateError::NotGranted)?;

        gate.admit(&envelope)?;

        // Past the gate. From here the message is acted on.
        self.dispatch(&envelope)
    }

    fn dispatch(&self, envelope: &ControlEnvelope) -> Result<Handled, GateError> {
        let Ok(providers) = self.providers.lock() else {
            return Ok(Handled::Ignored);
        };

        let Some(providers) = providers.as_ref() else {
            return Ok(Handled::Unsupported);
        };

        // The monitor a message applies to is the one being captured. A
        // controller cannot address a monitor that is not being shared, which
        // is why the id is taken from the layout rather than from the message.
        let monitor_id = providers
            .capture
            .monitors()
            .map(|layout| layout.active_monitor_id)
            .unwrap_or(1);

        let outcome = match &envelope.message {
            ControlMessage::MouseMove { position } => {
                providers.input.move_pointer(monitor_id, *position)
            }
            ControlMessage::MouseMoveRelative { dx, dy } => {
                providers.input.move_pointer_relative(*dx, *dy)
            }
            ControlMessage::MouseButton(event) => providers.input.mouse_button(monitor_id, *event),
            ControlMessage::Scroll(event) => providers.input.scroll(monitor_id, *event),
            ControlMessage::Key(event) => providers.input.key(*event),
            ControlMessage::Clipboard(payload) => providers.clipboard.apply(payload),
            // Everything else is either informational or handled elsewhere:
            // a reboot goes to the service over IPC with the session it was
            // authorised inside, and a ping is answered by the caller.
            _ => return Ok(Handled::Ignored),
        };

        match outcome {
            Ok(()) => Ok(Handled::Applied),
            Err(remote_device::PlatformError::Unsupported(_)) => Ok(Handled::Unsupported),
            Err(_) => Ok(Handled::Failed),
        }
    }

    /// Release every key this agent is holding.
    fn release_all_input(&self) {
        if let Ok(providers) = self.providers.lock() {
            if let Some(providers) = providers.as_ref() {
                let _ = providers.input.release_all();
            }
        }
    }

    /// The gate's current state, for the UI and the tests.
    #[must_use]
    pub fn control_state(&self) -> ControlState {
        self.gate
            .lock()
            .ok()
            .and_then(|gate| gate.as_ref().map(ControlGate::state))
            .unwrap_or(ControlState::None)
    }

    /// How many control messages have been dropped in this session.
    ///
    /// A count, never the messages: a rejected input event is still a
    /// keystroke.
    #[must_use]
    pub fn rejected_control_messages(&self) -> u64 {
        self.gate
            .lock()
            .ok()
            .and_then(|gate| gate.as_ref().map(ControlGate::rejected_count))
            .unwrap_or(0)
    }
}

/// What happened to a control message that got past the gate.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Handled {
    /// It reached the operating system.
    Applied,
    /// Not something this build acts on here.
    Ignored,
    /// The platform has no implementation.
    Unsupported,
    /// The platform refused.
    Failed,
}

/// Why an agent operation failed.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum AgentError {
    /// The device key could not be created, read or removed.
    #[error(transparent)]
    Key(#[from] KeyError),

    /// The configuration was refused.
    #[error(transparent)]
    Config(remote_core::ConfigError),

    /// The platform said no.
    #[error("{0}")]
    Platform(String),

    /// The API said no.
    #[error("{0}")]
    Api(String),
}

#[cfg(test)]
mod tests {
    use super::*;
    use remote_core::SessionSummary;
    use remote_protocol::{ControlEnvelope, ControlMessage, PointerPosition};

    const SESSION: &str = "session-uuid";
    const CONTROLLER: &str = "participant-uuid";

    fn summary() -> SessionSummary {
        SessionSummary {
            session_uuid: SESSION.into(),
            display_id: "AR-10282".into(),
            connected_name: "Sam in support".into(),
            company_name: Some("Northwind".into()),
            started_at: "2026-02-10T09:00:00Z".into(),
            unattended: false,
            control: remote_core::ControlSummary {
                state: ControlStateView::None,
                clipboard: false,
            },
        }
    }

    fn pointer(sequence: u64) -> Vec<u8> {
        ControlEnvelope::new(
            SESSION,
            CONTROLLER,
            sequence,
            ControlMessage::MouseMove {
                position: PointerPosition { x: 0.5, y: 0.5 },
            },
        )
        .encode()
        .expect("encodes")
    }

    #[test]
    fn a_new_agent_is_not_enrolled_and_has_no_session() {
        let agent = Agent::new();

        assert!(!agent.state().is_enrolled());
        assert!(!agent.state().is_session_active());
        assert_eq!(agent.control_state(), ControlState::None);
    }

    /// **The single most important test in the crate.** With no session there
    /// is no gate, and with no gate nothing reaches the operating system.
    #[test]
    fn no_input_is_acted_on_without_a_session() {
        let agent = Agent::new();

        assert_eq!(
            agent.handle_control(&pointer(1)),
            Err(GateError::NotGranted)
        );
    }

    #[test]
    fn no_input_is_acted_on_before_control_is_granted() {
        let agent = Agent::new();
        agent.begin_session(summary());

        assert_eq!(
            agent.handle_control(&pointer(1)),
            Err(GateError::NotGranted)
        );

        agent.control_requested(CONTROLLER);
        assert_eq!(
            agent.handle_control(&pointer(1)),
            Err(GateError::NotGranted)
        );
    }

    /// Stop control takes effect on the next message, with no network round
    /// trip and no permission check.
    #[test]
    fn stopping_control_takes_effect_immediately_and_locally() {
        let agent = Agent::new();
        agent.begin_session(summary());
        agent.control_requested(CONTROLLER);
        agent.grant_control(CONTROLLER, false);

        // Past the gate. What the platform does with it depends on the host.
        assert!(agent.handle_control(&pointer(1)).is_ok());

        agent.stop_control();

        assert_eq!(
            agent.handle_control(&pointer(2)),
            Err(GateError::NotGranted)
        );
        assert!(!agent.state().is_being_controlled());
    }

    #[test]
    fn a_second_viewer_on_the_same_channel_cannot_type() {
        let agent = Agent::new();
        agent.begin_session(summary());
        agent.grant_control(CONTROLLER, false);

        let impostor = ControlEnvelope::new(
            SESSION,
            "somebody-else",
            1,
            ControlMessage::MouseMove {
                position: PointerPosition { x: 0.1, y: 0.1 },
            },
        )
        .encode()
        .unwrap();

        assert_eq!(
            agent.handle_control(&impostor),
            Err(GateError::NotTheController)
        );
    }

    #[test]
    fn a_message_for_another_session_is_dropped() {
        let agent = Agent::new();
        agent.begin_session(summary());
        agent.grant_control(CONTROLLER, false);

        let elsewhere = ControlEnvelope::new(
            "another-session",
            CONTROLLER,
            1,
            ControlMessage::MouseMove {
                position: PointerPosition { x: 0.1, y: 0.1 },
            },
        )
        .encode()
        .unwrap();

        assert_eq!(
            agent.handle_control(&elsewhere),
            Err(GateError::WrongSession)
        );
    }

    #[test]
    fn a_replayed_message_is_dropped() {
        let agent = Agent::new();
        agent.begin_session(summary());
        agent.grant_control(CONTROLLER, false);

        assert!(agent.handle_control(&pointer(5)).is_ok());
        assert_eq!(
            agent.handle_control(&pointer(5)),
            Err(GateError::Stale { seen: 5 })
        );
    }

    /// Control and clipboard are separate exposures.
    #[test]
    fn clipboard_needs_its_own_switch_on_top_of_control() {
        let agent = Agent::new();
        agent.begin_session(summary());
        agent.grant_control(CONTROLLER, false);

        let clipboard = ControlEnvelope::new(
            SESSION,
            CONTROLLER,
            1,
            ControlMessage::Clipboard(remote_protocol::ClipboardPayload::text(
                "secret",
                remote_protocol::ClipboardDirection::ToHost,
            )),
        )
        .encode()
        .unwrap();

        assert_eq!(
            agent.handle_control(&clipboard),
            Err(GateError::ClipboardDisabled)
        );
    }

    /// A stale server grant must not undo a local Stop. The person in the room
    /// wins.
    #[test]
    fn a_stale_server_grant_cannot_undo_a_local_stop() {
        let agent = Agent::new();
        agent.begin_session(summary());
        agent.grant_control(CONTROLLER, true);
        agent.stop_control();

        agent.sync_control(ControlState::Granted, Some(CONTROLLER.into()), true);

        assert_eq!(agent.control_state(), ControlState::Revoked);
        assert_eq!(
            agent.handle_control(&pointer(9)),
            Err(GateError::NotGranted)
        );
    }

    #[test]
    fn ending_a_session_takes_the_gate_with_it() {
        let agent = Agent::new();
        agent.begin_session(summary());
        agent.grant_control(CONTROLLER, false);

        let state = agent.end_session();

        assert!(!state.is_session_active());
        assert_eq!(agent.control_state(), ControlState::None);
        assert_eq!(
            agent.handle_control(&pointer(1)),
            Err(GateError::NotGranted)
        );
    }

    #[test]
    fn revocation_ends_everything_and_is_terminal() {
        let agent = Agent::new();
        agent.begin_session(summary());
        agent.grant_control(CONTROLLER, false);

        let state = agent.revoked();

        assert!(!state.is_session_active());
        assert_eq!(
            agent.handle_control(&pointer(1)),
            Err(GateError::NotGranted)
        );
    }

    #[test]
    fn a_malformed_message_never_reaches_the_gate_state() {
        let agent = Agent::new();
        agent.begin_session(summary());
        agent.grant_control(CONTROLLER, false);

        assert!(matches!(
            agent.handle_control(b"not a control message"),
            Err(GateError::Invalid(_))
        ));

        // …and the sequence counter is untouched, so the next real message
        // still gets through.
        assert!(agent.handle_control(&pointer(1)).is_ok());
    }

    /// A rejected input event is still a keystroke. The diagnostics panel gets
    /// a number, and there is no accessor that returns a message.
    #[test]
    fn rejections_are_counted_and_not_kept() {
        let agent = Agent::new();
        agent.begin_session(summary());
        agent.grant_control(CONTROLLER, false);

        let _ = agent.handle_control(&pointer(0));
        let _ = agent.handle_control(&pointer(0));

        assert_eq!(agent.rejected_control_messages(), 2);
    }

    /// A setting the agent would refuse must never reach the file the service
    /// reads on its next start.
    #[test]
    fn the_configuration_is_validated_before_it_is_stored_or_written() {
        // Written into a directory of this test's own, so the test does not
        // depend on — or disturb — whatever this machine has configured.
        let root = std::env::temp_dir().join(format!(
            "aicountly-remote-config-test-{}",
            std::process::id()
        ));
        std::env::set_var("XDG_CONFIG_HOME", &root);
        std::env::set_var("ProgramData", &root);

        let agent = Agent::new();

        let bad = AgentConfig {
            presence_interval_seconds: 1,
            ..AgentConfig::default()
        };

        assert!(agent.set_config(bad).is_err());
        assert_eq!(agent.config(), AgentConfig::default());

        let good = AgentConfig {
            presence_interval_seconds: 90,
            ..AgentConfig::default()
        };

        assert!(agent.set_config(good.clone()).is_ok());
        assert_eq!(agent.config(), good);

        // And it survives a restart, which is the whole reason it is written.
        assert_eq!(Agent::load().config(), good);

        let _ = std::fs::remove_dir_all(&root);
    }

    /// The declaration is already constrained by what the machine permits, so
    /// an agent on a box with no background service does not enrol claiming it
    /// can be reached unattended.
    #[test]
    fn the_declaration_is_constrained_by_the_machine() {
        let agent = Agent::new();
        let declared = agent.capabilities();

        if !platform::is_supported() {
            assert!(!declared.remote_control);
            assert!(!declared.unattended_access);
        }
    }
}
