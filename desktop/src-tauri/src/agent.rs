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

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Mutex;
use std::time::Instant;

use remote_core::{AgentConfig, AgentEvent, AgentState, ControlStateView, UnattendedState};
use remote_core::{
    ApiClient, CompanionSummary, ControlDecision, DeviceCredential, MonitorOption, PendingCompanion,
};
use remote_device::{
    AgentCapabilities, DeviceDescription, PermissionSummary, PlatformProviders, PlatformResult,
};
use remote_protocol::{
    ControlEnvelope, ControlGate, ControlMessage, ControlState, GateError, Monitor, MonitorLayout,
    ProtocolError,
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
    /// The control-companion link this machine has joined, if any.
    companion: Mutex<Option<CompanionContext>>,
    /// The person at the machine's answer to a waiting companion link —
    /// `(link, allowed)` — until the connection loop picks it up.
    companion_decision: Mutex<Option<(String, bool)>>,
    /// The monitor the person at the machine picked — `(link, monitor id)` —
    /// until the companion loop reports it.
    monitor_choice: Mutex<Option<(String, u32)>>,
    /// The person at the machine ended the companion link here.
    companion_leave: AtomicBool,
    /// Stop control was pressed and the controller has not been told on the
    /// control channel yet.
    stop_notice: AtomicBool,
    /// When input was last injected, so the local-input override does not
    /// mistake this agent's own events for the person at the machine.
    last_injection: Mutex<Option<Instant>>,
    /// A help session the person at the machine started from the window
    /// ("give your code"), until the connection loop hosts it.
    help_request: Mutex<Option<String>>,
}

/// The companion link this machine has joined.
#[derive(Debug, Clone, PartialEq, Eq)]
struct CompanionContext {
    companion_uuid: String,
    session_uuid: String,
    /// The monitor input lands on. `None` while it is being worked out, and
    /// then nothing is injected at all.
    monitor_id: Option<u32>,
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
            companion: Mutex::new(None),
            companion_decision: Mutex::new(None),
            monitor_choice: Mutex::new(None),
            companion_leave: AtomicBool::new(false),
            stop_notice: AtomicBool::new(false),
            last_injection: Mutex::new(None),
            help_request: Mutex::new(None),
        }
    }

    /// A new agent on this machine's stored configuration and enrolment.
    ///
    /// The configuration is read from the machine-wide file the service also
    /// reads, so the two halves of the agent cannot end up pointing at
    /// different deployments. A missing or unreadable file gives the default,
    /// which points at production: an agent that refused to start because
    /// somebody edited a JSON file would be an agent needing a visit to the
    /// machine, and that is the one thing a remote-support tool must not need.
    ///
    /// The enrolment record is read the same way, and for the same reason a
    /// missing one is not treated as an error: a machine that was never
    /// registered has none, and this is simply what `NotEnrolled` looks like.
    /// Without this, `device_uuid` would stay `None` on every restart — the
    /// connection loop would never even attempt to authenticate with a key
    /// that is still sitting in secure storage, and the window would say "Not
    /// registered" for a device the server has no reason to disagree with.
    #[must_use]
    pub fn load() -> Self {
        let agent = Self::new();

        if let Ok(mut held) = agent.config.lock() {
            *held = AgentConfig::load();
        }

        if let Some(record) = EnrolmentRecord::load() {
            agent.apply(AgentEvent::Enrolled {
                device_uuid: record.device_uuid,
                device_name: record.device_name,
                company_name: record.company_name,
                key_fingerprint: record.key_fingerprint,
            });
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

    /// Ask the platform to prompt for a permission — on macOS, Accessibility.
    /// It grants nothing itself; the person does, in System Settings.
    pub fn request_permission(&self, permission: &str) -> PlatformResult<()> {
        let providers = self
            .providers
            .lock()
            .map_err(|_| remote_device::PlatformError::NotFound("The platform layer"))?;

        providers
            .as_ref()
            .ok_or(remote_device::PlatformError::Unsupported(
                "Requesting a permission",
            ))?
            .permissions
            .request(permission)
    }

    /// What this build declares it can do, given what the machine permits.
    ///
    /// This is the value sent at enrolment. It is an upper bound the server
    /// intersects with policy — and it is already constrained by the machine,
    /// so an agent on a box with no background service does not enrol claiming
    /// it can be reached unattended.
    ///
    /// A companion-only platform (macOS) is the exception: its one capability,
    /// `remote_control`, depends on Accessibility, which a person grants in
    /// System Settings — typically *after* registering, when first asked. The
    /// declaration is fixed at enrolment, so constraining it by a permission
    /// not granted yet would enrol a Mac that can never be a companion. The
    /// permission is instead checked live, every time, before the companion
    /// reports itself ready (`companion::CompanionApi::can_inject`).
    pub fn capabilities(&self) -> AgentCapabilities {
        let declared = platform::declared_capabilities();

        if platform::supports_companion() && !platform::supports_hosting() {
            return declared;
        }

        self.permissions().constrain(declared)
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

    /// This machine's identity as a person reads it, creating it if this is
    /// the first time the application has run.
    ///
    /// The fingerprint of the device key, grouped the way the console shows
    /// it. It exists before anybody signs in and does not change when they
    /// do, which is what makes it usable as the thing you read out to
    /// somebody: the key is generated once and kept, rather than regenerated
    /// per sign-in attempt.
    ///
    /// It identifies the machine and nothing else. It is a hash of a public
    /// key, so showing it costs nothing — and on its own it reaches nothing,
    /// because every connection still needs a credential this machine only
    /// gets by signing in.
    pub fn device_identity(&self) -> Result<String, AgentError> {
        if let Ok(keypair) = self.device_key() {
            return Ok(keypair.display_fingerprint());
        }

        let keypair = DeviceKeypair::generate();

        platform::secure_storage()
            .store(
                DEVICE_KEY_ENTRY,
                keypair.secret_bytes().as_ref(),
                StorageScope::LocalMachine,
            )
            .map_err(|error| AgentError::Key(KeyError::Storage(error)))?;

        Ok(keypair.display_fingerprint())
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
    ///
    /// The enrolment record goes with it. Leaving it behind would mean the
    /// next restart's [`Agent::load`] reads a record naming a key that no
    /// longer exists — a machine that believes it is still enrolled and
    /// cannot prove it, which is a stranger state than simply being
    /// unenrolled.
    pub fn forget_device_key(&self) -> Result<(), AgentError> {
        platform::secure_storage()
            .delete(DEVICE_KEY_ENTRY, StorageScope::LocalMachine)
            .map_err(|error| AgentError::Key(KeyError::Storage(error)))?;

        // The key is what actually authenticates; losing only the record
        // would leave that stranger state. Reported rather than propagated:
        // the key is already gone, which is the half that matters, and a
        // person who just unregistered should not be told it failed because
        // a leftover file could not be deleted.
        if let Err(error) = EnrolmentRecord::forget() {
            tracing::warn!(%error, "the enrolment record could not be removed from disk");
        }

        Ok(())
    }

    /// Record the enrolment: the running agent's state, and a durable copy so
    /// a restart does not forget it.
    ///
    /// Written the same way [`AgentConfig`] is — atomically, no secret in it —
    /// and a write that fails is reported rather than propagated: the server
    /// has already accepted this enrolment and the key already exists in
    /// secure storage, so failing the whole operation over a file the agent
    /// can simply try to write again next time would discard a real success
    /// over a cosmetic one. Losing the file only costs "since when" on the
    /// next restart; [`Agent::load`] still has nothing to read, so the person
    /// would need to register again — reported so that failure is at least
    /// visible to whoever reads the log.
    pub fn record_enrolment(&self, record: &EnrolmentRecord) -> AgentState {
        if let Err(error) = record.save() {
            tracing::warn!(%error, "the enrolment record could not be written to disk");
        }

        self.apply(AgentEvent::Enrolled {
            device_uuid: record.device_uuid.clone(),
            device_name: record.device_name.clone(),
            company_name: record.company_name.clone(),
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
    pub fn control_requested(&self, participant_uuid: &str, display_name: &str) -> AgentState {
        if let Ok(mut gate) = self.gate.lock() {
            if let Some(gate) = gate.as_mut() {
                gate.request(participant_uuid);
            }
        }

        self.apply(AgentEvent::ControlChanged {
            state: ControlStateView::Requested,
            clipboard: false,
            requester_uuid: Some(participant_uuid.to_owned()),
            // Empty rather than absent when the API sent no name: the dialog
            // falls back to who is connected rather than showing nothing.
            requester_name: Some(display_name)
                .filter(|name| !name.is_empty())
                .map(str::to_owned),
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
            requester_uuid: None,
            requester_name: None,
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
            requester_uuid: None,
            requester_name: None,
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

        // The controller is told on the control channel too, by whichever
        // loop owns it — the gate has already stopped admitting either way.
        if controller.is_some() {
            self.stop_notice.store(true, Ordering::SeqCst);
        }

        // Reported after the fact and never waited on: if the network is the
        // reason somebody pressed Stop, waiting for it would be the worst
        // possible behaviour.
        if let (Some(session), Some(participant)) = (self.session_uuid(), controller) {
            self.report_control_decision(&session, &participant, ControlDecision::Revoke, false);
        }

        if self.state().active_companion().is_some() {
            return self.apply(AgentEvent::CompanionControl {
                controlled: false,
                controller_name: None,
            });
        }

        self.apply(AgentEvent::ControlChanged {
            state: ControlStateView::Revoked,
            clipboard: false,
            requester_uuid: None,
            requester_name: None,
        })
    }

    /// Whether Stop was pressed since the last call — the control-channel
    /// owner's cue to send `control_ended`. Cleared by reading it.
    pub fn take_stop_notice(&self) -> bool {
        self.stop_notice.swap(false, Ordering::SeqCst)
    }

    /// The session currently running, if one is — hosted, or joined as a
    /// companion.
    fn session_uuid(&self) -> Option<String> {
        let state = self.state();

        state
            .active_session()
            .map(|session| session.session_uuid.clone())
            .or_else(|| {
                state
                    .active_companion()
                    .filter(|companion| companion.is_joined())
                    .map(|companion| companion.session_uuid.clone())
            })
    }

    /// Whoever the gate currently names as the controller.
    fn controller_uuid(&self) -> Option<String> {
        self.gate.lock().ok().and_then(|gate| {
            gate.as_ref()
                .and_then(|gate| gate.controller().map(str::to_owned))
        })
    }

    /// Adopt the control state the API reports.
    ///
    /// `grant_id` is the API's identifier for the grant; see
    /// [`ControlGate::sync_from_api`] for why a local Stop needs it.
    pub fn sync_control(
        &self,
        state: ControlState,
        controller: Option<String>,
        clipboard: bool,
        grant_id: Option<String>,
    ) -> AgentState {
        if let Ok(mut gate) = self.gate.lock() {
            if let Some(gate) = gate.as_mut() {
                gate.sync_from_api(state, controller, clipboard, grant_id);
            }
        }

        self.apply(AgentEvent::ControlChanged {
            state: state.into(),
            clipboard,
            requester_uuid: None,
            requester_name: None,
        })
    }

    /// The peer identified itself over signalling — who is actually in the
    /// room, as opposed to who a data-channel message merely claims to be.
    pub fn peer_connected(&self, display_name: &str) -> AgentState {
        self.apply(AgentEvent::PeerConnected {
            display_name: display_name.to_owned(),
        })
    }

    /// Unattended access changed, here or in the console.
    pub fn set_unattended(&self, unattended: UnattendedState) -> AgentState {
        self.apply(AgentEvent::UnattendedChanged(unattended))
    }

    /// What the organisation currently allows, as `GET /devices/me` last
    /// reported it.
    pub fn set_allowed_capabilities(&self, capabilities: AgentCapabilities) -> AgentState {
        self.apply(AgentEvent::CapabilitiesChanged(capabilities))
    }

    // ----------------------------------------------------------- companion

    /// The owner linked this machine to their browser share: ask the person
    /// at the machine. Raised only from `GET /devices/me`, never from anything
    /// a peer sends.
    pub fn companion_requested(&self, pending: &PendingCompanion) -> AgentState {
        self.apply(AgentEvent::CompanionRequested(CompanionSummary {
            companion_uuid: pending.companion_uuid.clone(),
            session_uuid: pending.session_uuid.clone(),
            display_id: pending.display_id.clone(),
            company_name: pending.company_name.clone(),
            requested_by_name: pending.requested_by_name.clone(),
            expires_at: pending.expires_at.clone(),
            started_at: None,
            phase: remote_core::CompanionPhase::AwaitingConfirmation,
            monitors: Vec::new(),
            monitor: None,
            controller_name: None,
        }))
    }

    /// A waiting link went away unanswered here — or was declined.
    pub fn companion_dismissed(&self, companion_uuid: &str) -> AgentState {
        self.apply(AgentEvent::CompanionDismissed {
            companion_uuid: companion_uuid.to_owned(),
        })
    }

    /// The person at the machine answered a waiting link. Picked up by the
    /// connection loop, which joins or declines.
    pub fn decide_companion(&self, companion_uuid: &str, allow: bool) {
        if let Ok(mut decision) = self.companion_decision.lock() {
            *decision = Some((companion_uuid.to_owned(), allow));
        }
    }

    /// The answer for this link, if one was given. Taken: it is acted on once.
    pub fn take_companion_decision(&self, companion_uuid: &str) -> Option<bool> {
        let mut decision = self.companion_decision.lock().ok()?;

        match decision.as_ref() {
            Some((uuid, allow)) if uuid == companion_uuid => {
                let allow = *allow;
                *decision = None;

                Some(allow)
            }
            _ => None,
        }
    }

    /// Joined as a companion: a fresh gate for the session, and the indicator
    /// on.
    pub fn begin_companion(&self, summary: CompanionSummary) -> AgentState {
        if let Ok(mut gate) = self.gate.lock() {
            *gate = Some(ControlGate::new(summary.session_uuid.clone()));
        }

        if let Ok(mut companion) = self.companion.lock() {
            *companion = Some(CompanionContext {
                companion_uuid: summary.companion_uuid.clone(),
                session_uuid: summary.session_uuid.clone(),
                monitor_id: None,
            });
        }

        self.companion_leave.store(false, Ordering::SeqCst);
        self.stop_notice.store(false, Ordering::SeqCst);

        self.apply(AgentEvent::CompanionJoined(summary))
    }

    /// Which monitor is shared cannot be told automatically: offer these, and
    /// inject nothing until one is chosen.
    pub fn companion_choose_monitor(&self, options: &[Monitor]) -> AgentState {
        self.set_companion_monitor(None);

        self.apply(AgentEvent::CompanionChooseMonitor {
            options: options.iter().map(MonitorOption::from).collect(),
        })
    }

    /// The monitor being shared is known and was reported: input lands on it.
    pub fn companion_monitor_chosen(&self, monitor: &Monitor) -> AgentState {
        self.set_companion_monitor(Some(monitor.id));

        self.apply(AgentEvent::CompanionMonitorChosen(MonitorOption::from(
            monitor,
        )))
    }

    fn set_companion_monitor(&self, monitor_id: Option<u32>) {
        if let Ok(mut companion) = self.companion.lock() {
            if let Some(companion) = companion.as_mut() {
                companion.monitor_id = monitor_id;
            }
        }
    }

    /// The person at the machine picked a monitor in the window.
    pub fn choose_companion_monitor(&self, companion_uuid: &str, monitor_id: u32) {
        if let Ok(mut choice) = self.monitor_choice.lock() {
            *choice = Some((companion_uuid.to_owned(), monitor_id));
        }
    }

    /// The pick for this link, if one was made. Taken: it is reported once.
    pub fn take_monitor_choice(&self, companion_uuid: &str) -> Option<u32> {
        let mut choice = self.monitor_choice.lock().ok()?;

        match choice.as_ref() {
            Some((uuid, id)) if uuid == companion_uuid => {
                let id = *id;
                *choice = None;

                Some(id)
            }
            _ => None,
        }
    }

    /// The person at the machine ended the link here.
    pub fn leave_companion(&self) {
        self.companion_leave.store(true, Ordering::SeqCst);
    }

    /// Whether the person at the machine asked to leave. Cleared by reading.
    pub fn take_companion_leave(&self) -> bool {
        self.companion_leave.swap(false, Ordering::SeqCst)
    }

    /// Adopt control as the API reports it for a companion session.
    ///
    /// No consent dialog here: the owner grants each request in their browser.
    /// `grant_id` is what lets a grant made after a local Stop re-arm the gate
    /// — and nothing else does.
    pub fn sync_companion_control(
        &self,
        controller: Option<String>,
        controller_name: Option<String>,
        grant_id: Option<String>,
    ) -> AgentState {
        let state = if controller.is_some() {
            ControlState::Granted
        } else {
            ControlState::None
        };

        let controlled = {
            let Ok(mut gate) = self.gate.lock() else {
                return self.state();
            };

            match gate.as_mut() {
                Some(gate) => {
                    // Companions never share the clipboard in this version.
                    gate.sync_from_api(state, controller, false, grant_id);
                    gate.state().permits_input()
                }
                None => false,
            }
        };

        self.apply(AgentEvent::CompanionControl {
            controlled,
            controller_name: controller_name.filter(|name| !name.is_empty()),
        })
    }

    /// The person at the machine is (`true`) or is no longer (`false`) using
    /// it while somebody holds control. Pausing drops their input at the gate
    /// and lets go of anything held, so a remote Shift does not ride along
    /// with the local person's typing.
    pub fn set_companion_paused(&self, paused: bool) -> AgentState {
        if let Ok(mut gate) = self.gate.lock() {
            if let Some(gate) = gate.as_mut() {
                if paused {
                    gate.pause();
                } else {
                    gate.resume();
                }
            }
        }

        if paused {
            self.release_all_input();
        }

        self.apply(AgentEvent::CompanionPaused(paused))
    }

    /// The companion left the session.
    pub fn end_companion(&self) -> AgentState {
        if let Ok(mut gate) = self.gate.lock() {
            *gate = None;
        }

        if let Ok(mut companion) = self.companion.lock() {
            *companion = None;
        }

        self.release_all_input();
        self.stop_notice.store(false, Ordering::SeqCst);

        self.apply(AgentEvent::CompanionEnded)
    }

    // ------------------------------------------------------- help sessions

    /// Ask the connection loop to host a help session the person at the
    /// machine started here. The loop picks it up within half a second.
    pub fn request_help_session(&self, session_uuid: &str) {
        if let Ok(mut request) = self.help_request.lock() {
            *request = Some(session_uuid.to_owned());
        }
    }

    /// Whether a help session is waiting to be hosted.
    #[must_use]
    pub fn has_help_request(&self) -> bool {
        self.help_request
            .lock()
            .map(|request| request.is_some())
            .unwrap_or(false)
    }

    /// The help session waiting to be hosted, taken so it is hosted once.
    pub fn take_help_request(&self) -> Option<String> {
        self.help_request
            .lock()
            .ok()
            .and_then(|mut request| request.take())
    }

    /// Drop the waiting help session if it is `session_uuid`, so a session
    /// ended before the loop reached it is never hosted.
    pub fn cancel_help_request(&self, session_uuid: &str) {
        if let Ok(mut request) = self.help_request.lock() {
            if request.as_deref() == Some(session_uuid) {
                *request = None;
            }
        }
    }

    /// Whether `session_uuid` is the session this machine is hosting.
    ///
    /// False once the person at the machine has ended it in the window, which
    /// is how the session loop learns to stop: ending a session here only
    /// changes state, and the loop reads that state on every turn.
    #[must_use]
    pub fn is_hosting(&self, session_uuid: &str) -> bool {
        self.state
            .lock()
            .map(|state| {
                state
                    .active_session()
                    .is_some_and(|session| session.session_uuid == session_uuid)
            })
            .unwrap_or(false)
    }

    // ------------------------------------------------------------ capture

    /// Start capturing the display a hosted session shows: the primary one,
    /// or the first the platform lists. Input is then mapped onto the same
    /// display, because `dispatch` reads the capture's active monitor.
    ///
    /// Returns the layout, with the captured display as the active one, for
    /// the controller.
    pub fn start_capture(
        &self,
        profile: remote_device::frame::CaptureProfile,
    ) -> PlatformResult<MonitorLayout> {
        let mut providers = self
            .providers
            .lock()
            .map_err(|_| remote_device::PlatformError::NotFound("The screen capture"))?;

        let capture = &mut providers
            .as_mut()
            .ok_or(remote_device::PlatformError::Unsupported(
                "Showing this computer's screen",
            ))?
            .capture;

        let layout = capture.monitors()?;
        let monitor_id = shared_monitor(&layout)
            .ok_or(remote_device::PlatformError::NotFound("A display to show"))?;

        if capture.is_running() {
            let _ = capture.stop();
        }

        capture.start(monitor_id, profile)?;

        Ok(MonitorLayout {
            active_monitor_id: monitor_id,
            ..layout
        })
    }

    /// The next captured frame, if one is due.
    pub fn next_frame(&self) -> PlatformResult<Option<remote_device::Frame>> {
        let mut providers = self
            .providers
            .lock()
            .map_err(|_| remote_device::PlatformError::NotFound("The screen capture"))?;

        match providers.as_mut() {
            Some(providers) if providers.capture.is_running() => providers.capture.next_frame(),
            _ => Ok(None),
        }
    }

    /// Change the capture's size or frame rate, under congestion.
    pub fn reconfigure_capture(
        &self,
        profile: remote_device::frame::CaptureProfile,
    ) -> PlatformResult<()> {
        let mut providers = self
            .providers
            .lock()
            .map_err(|_| remote_device::PlatformError::NotFound("The screen capture"))?;

        match providers.as_mut() {
            Some(providers) if providers.capture.is_running() => {
                providers.capture.reconfigure(profile)
            }
            _ => Ok(()),
        }
    }

    /// Stop capturing. Called on every exit from a hosted session, so the
    /// screen is never captured for a session that has ended.
    pub fn stop_capture(&self) {
        if let Ok(mut providers) = self.providers.lock() {
            if let Some(providers) = providers.as_mut() {
                if providers.capture.is_running() {
                    if let Err(error) = providers.capture.stop() {
                        tracing::warn!(%error, "the screen capture did not stop cleanly");
                    }
                }
            }
        }
    }

    /// Every display the platform can see, in its injection space.
    pub fn monitor_layout(&self) -> PlatformResult<MonitorLayout> {
        let providers = self
            .providers
            .lock()
            .map_err(|_| remote_device::PlatformError::NotFound("The display list"))?;

        providers
            .as_ref()
            .ok_or(remote_device::PlatformError::Unsupported(
                "Reading the display layout",
            ))?
            .capture
            .monitors()
    }

    /// Seconds since the machine last saw any mouse or keyboard input, and
    /// seconds since this agent last injected some. `None` for the first when
    /// the platform cannot say, which leaves the local-input override off.
    pub fn input_activity(&self) -> (Option<f64>, Option<f64>) {
        let local = self.providers.lock().ok().and_then(|providers| {
            providers
                .as_ref()
                .and_then(|providers| providers.input.seconds_since_input().ok())
        });

        let injected = self
            .last_injection
            .lock()
            .ok()
            .and_then(|last| last.map(|at| at.elapsed().as_secs_f64()));

        (local, injected)
    }

    /// Whether the gate is open and not paused, nor stopped locally.
    #[must_use]
    pub fn control_admits_input(&self) -> bool {
        self.gate
            .lock()
            .ok()
            .and_then(|gate| {
                gate.as_ref()
                    .map(|gate| gate.state().permits_input() && !gate.is_paused())
            })
            .unwrap_or(false)
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
        let handled = self.dispatch(&envelope)?;

        if handled == Handled::Applied && envelope.is_input() {
            if let Ok(mut last) = self.last_injection.lock() {
                *last = Some(Instant::now());
            }
        }

        Ok(handled)
    }

    fn dispatch(&self, envelope: &ControlEnvelope) -> Result<Handled, GateError> {
        // A ping is answered by whoever owns the channel, with the nonce.
        if let ControlMessage::Ping { nonce } = envelope.message {
            return Ok(Handled::Pinged(nonce));
        }

        // A companion's monitor is the one its owner is sharing, chosen at the
        // machine or matched to the browser's surface — never one a message
        // names, which is why `select_monitor` does nothing here. Until it is
        // known, nothing is injected anywhere.
        let companion_monitor = self
            .companion
            .lock()
            .ok()
            .and_then(|companion| companion.as_ref().map(|companion| companion.monitor_id));

        if matches!(companion_monitor, Some(None)) {
            return Ok(Handled::Ignored);
        }

        let Ok(providers) = self.providers.lock() else {
            return Ok(Handled::Ignored);
        };

        let Some(providers) = providers.as_ref() else {
            return Ok(Handled::Unsupported);
        };

        // The monitor a message applies to is the one being shared. A
        // controller cannot address a monitor that is not being shared, which
        // is why the id is taken from the layout rather than from the message.
        let monitor_id = match companion_monitor {
            Some(Some(id)) => id,
            _ => providers
                .capture
                .monitors()
                .map(|layout| layout.active_monitor_id)
                .unwrap_or(1),
        };

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

/// The display a hosted session shows: the primary one, else the first.
fn shared_monitor(layout: &MonitorLayout) -> Option<u32> {
    layout
        .monitors
        .iter()
        .find(|monitor| monitor.primary)
        .or_else(|| layout.monitors.first())
        .map(|monitor| monitor.id)
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
    /// A keep-alive: the channel's owner answers it with this nonce.
    Pinged(u64),
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
                requester_uuid: None,
                requester_name: None,
            },
        }
    }

    /// A fresh agent, enrolled and authenticated.
    ///
    /// `begin_session`, `peer_connected` and `set_allowed_capabilities` are
    /// only ever called, in practice, from a connection loop that reached
    /// them by authenticating first — and the state machine's own rule is
    /// that nothing happens on a device that was never enrolled. A bare
    /// `Agent::new()` is the wrong fixture for exercising any of them.
    fn enrolled_agent() -> Agent {
        let agent = Agent::new();

        agent.apply(AgentEvent::Enrolled {
            device_uuid: "device-uuid".into(),
            device_name: "WS-01".into(),
            company_name: Some("Northwind".into()),
            key_fingerprint: "AAAA BBBB".into(),
        });
        agent.apply(AgentEvent::Authenticated);

        agent
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

        agent.control_requested(CONTROLLER, "Sam in support");
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
        agent.control_requested(CONTROLLER, "Sam in support");
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

        agent.sync_control(ControlState::Granted, Some(CONTROLLER.into()), true, None);

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
        // macOS keeps it under ~/Library/Application Support.
        #[cfg(target_os = "macos")]
        std::env::set_var("HOME", &root);

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

        // A companion-only platform never declares unattended access, whatever
        // the machine permits.
        if !platform::supports_hosting() {
            assert!(!declared.unattended_access);
            assert!(!declared.clipboard_sync);
        }
    }

    /// The consent dialog cannot be built without knowing who to grant to —
    /// this is the data it renders from, and a decision must not leave a
    /// stale name behind for the next request to be misread against.
    #[test]
    fn a_control_request_exposes_who_is_asking_and_a_decision_clears_it() {
        let agent = enrolled_agent();
        agent.begin_session(summary());

        let state = agent.control_requested(CONTROLLER, "Sam in support");
        let control = state.active_session().unwrap().control.clone();

        assert_eq!(control.state, ControlStateView::Requested);
        assert_eq!(control.requester_uuid.as_deref(), Some(CONTROLLER));
        assert_eq!(control.requester_name.as_deref(), Some("Sam in support"));

        let state = agent.grant_control(CONTROLLER, false);
        let control = state.active_session().unwrap().control.clone();

        assert!(control.requester_uuid.is_none());
        assert!(control.requester_name.is_none());
    }

    /// An API that sends no name must not make the dialog print a blank
    /// where a person's name belongs.
    #[test]
    fn a_request_with_no_name_carries_none_rather_than_an_empty_string() {
        let agent = enrolled_agent();
        agent.begin_session(summary());

        let state = agent.control_requested(CONTROLLER, "");
        let control = state.active_session().unwrap().control.clone();

        assert!(control.requester_name.is_none());
    }

    /// The session banner's "Connected person" is blank until somebody is
    /// actually known to be in the room — this is what fills it in.
    #[test]
    fn the_peer_identifying_itself_sets_the_connected_name() {
        let agent = enrolled_agent();
        agent.begin_session(SessionSummary {
            connected_name: String::new(),
            ..summary()
        });

        let state = agent.peer_connected("Sam in support");

        assert_eq!(
            state.active_session().unwrap().connected_name,
            "Sam in support"
        );
    }

    fn companion(agent: &Agent) {
        agent.begin_companion(CompanionSummary {
            companion_uuid: "companion-1".into(),
            session_uuid: SESSION.into(),
            display_id: "123-456".into(),
            company_name: None,
            requested_by_name: Some("Rahul".into()),
            expires_at: None,
            started_at: Some("2026-10-25T09:00:00Z".into()),
            phase: remote_core::CompanionPhase::ChoosingMonitor,
            monitors: Vec::new(),
            monitor: None,
            controller_name: None,
        });
    }

    fn display(id: u32) -> Monitor {
        Monitor {
            id,
            name: format!("Display {id}"),
            primary: id == 1,
            x: 0,
            y: 0,
            width: 1512,
            height: 982,
            scale: 2.0,
            refresh_hz: None,
            orientation: remote_protocol::Orientation::Landscape,
        }
    }

    /// A companion's control comes from the API — granted in the owner's
    /// browser — and lands only once the monitor is known.
    #[test]
    fn a_companion_admits_input_only_when_granted_and_with_a_monitor() {
        let agent = enrolled_agent();
        companion(&agent);

        // Granted, but no monitor yet: past the gate, and still ignored.
        let state = agent.sync_companion_control(
            Some(CONTROLLER.into()),
            Some("Sam".into()),
            Some("grant-1".into()),
        );
        assert!(!state.is_being_controlled(), "no monitor, no control shown");
        assert_eq!(agent.handle_control(&pointer(1)), Ok(Handled::Ignored));

        let state = agent.companion_monitor_chosen(&display(1));
        assert_eq!(
            state.active_companion().unwrap().phase,
            remote_core::CompanionPhase::Ready
        );

        let state = agent.sync_companion_control(
            Some(CONTROLLER.into()),
            Some("Sam".into()),
            Some("grant-1".into()),
        );
        assert!(state.is_being_controlled());
        assert!(agent.control_admits_input());
        // Past the gate; this host has no input provider to apply it with.
        assert!(agent.handle_control(&pointer(2)).is_ok());
    }

    /// Stop at the machine: the gate closes, the loop is owed a
    /// `control_ended`, and the same grant reported again does not reopen it.
    #[test]
    fn stopping_a_companion_locally_holds_until_a_new_grant() {
        let agent = enrolled_agent();
        companion(&agent);
        agent.companion_monitor_chosen(&display(1));
        agent.sync_companion_control(Some(CONTROLLER.into()), None, Some("grant-1".into()));

        let state = agent.stop_control();
        assert!(!state.is_being_controlled());
        assert!(agent.take_stop_notice());
        assert!(!agent.take_stop_notice(), "taken once");

        agent.sync_companion_control(Some(CONTROLLER.into()), None, Some("grant-1".into()));
        assert_eq!(
            agent.handle_control(&pointer(3)),
            Err(GateError::NotGranted)
        );
        assert!(!agent.state().is_being_controlled());

        agent.sync_companion_control(Some(CONTROLLER.into()), None, Some("grant-2".into()));
        assert!(agent.state().is_being_controlled());
        assert!(agent.handle_control(&pointer(1)).is_ok());
    }

    /// The local person wins: paused input is dropped at the gate, and the
    /// banner says so.
    #[test]
    fn a_paused_companion_drops_input_until_resumed() {
        let agent = enrolled_agent();
        companion(&agent);
        agent.companion_monitor_chosen(&display(1));
        agent.sync_companion_control(Some(CONTROLLER.into()), None, Some("grant-1".into()));

        let state = agent.set_companion_paused(true);
        assert_eq!(
            state.active_companion().unwrap().phase,
            remote_core::CompanionPhase::Paused
        );
        assert!(!agent.control_admits_input());
        assert_eq!(agent.handle_control(&pointer(1)), Err(GateError::Paused));

        agent.set_companion_paused(false);
        assert!(agent.handle_control(&pointer(2)).is_ok());
    }

    /// `select_monitor` is ignored in companion mode — the monitor is the one
    /// the owner shares, not one a controller names — and a ping is answered.
    #[test]
    fn a_companion_ignores_select_monitor_and_answers_a_ping() {
        let agent = enrolled_agent();
        companion(&agent);
        agent.companion_monitor_chosen(&display(1));
        agent.sync_companion_control(Some(CONTROLLER.into()), None, Some("grant-1".into()));

        let select = ControlEnvelope::new(
            SESSION,
            CONTROLLER,
            1,
            ControlMessage::SelectMonitor { monitor_id: 2 },
        )
        .encode()
        .unwrap();
        assert_eq!(agent.handle_control(&select), Ok(Handled::Ignored));

        let ping = ControlEnvelope::new(SESSION, CONTROLLER, 2, ControlMessage::Ping { nonce: 9 })
            .encode()
            .unwrap();
        assert_eq!(agent.handle_control(&ping), Ok(Handled::Pinged(9)));
    }

    #[test]
    fn decisions_and_monitor_choices_are_taken_once_and_only_for_their_link() {
        let agent = enrolled_agent();

        agent.decide_companion("companion-1", true);
        assert_eq!(agent.take_companion_decision("companion-2"), None);
        assert_eq!(agent.take_companion_decision("companion-1"), Some(true));
        assert_eq!(agent.take_companion_decision("companion-1"), None);

        agent.choose_companion_monitor("companion-1", 4);
        assert_eq!(agent.take_monitor_choice("other"), None);
        assert_eq!(agent.take_monitor_choice("companion-1"), Some(4));
        assert_eq!(agent.take_monitor_choice("companion-1"), None);

        agent.leave_companion();
        assert!(agent.take_companion_leave());
        assert!(!agent.take_companion_leave());
    }

    #[test]
    fn ending_a_companion_takes_the_gate_and_the_monitor_with_it() {
        let agent = enrolled_agent();
        companion(&agent);
        agent.companion_monitor_chosen(&display(1));
        agent.sync_companion_control(Some(CONTROLLER.into()), None, Some("grant-1".into()));

        let state = agent.end_companion();

        assert!(state.active_companion().is_none());
        assert!(!state.is_session_active());
        assert_eq!(
            agent.handle_control(&pointer(1)),
            Err(GateError::NotGranted)
        );
    }

    /// The Permissions panel's "your organisation" column has nowhere else to
    /// read this from.
    #[test]
    fn the_organisations_capabilities_are_recorded_once_the_api_reports_them() {
        let agent = enrolled_agent();

        assert!(agent.state().allowed_capabilities.is_none());

        let allowed = AgentCapabilities::windows();
        let state = agent.set_allowed_capabilities(allowed);

        assert_eq!(state.allowed_capabilities, Some(allowed));
    }

    /// A help session started in the window is hosted once, and the loop can
    /// see one is waiting without taking it.
    #[test]
    fn a_help_session_request_is_taken_once() {
        let agent = Agent::new();
        assert!(!agent.has_help_request());

        agent.request_help_session("help-1");
        assert!(agent.has_help_request());
        assert_eq!(agent.take_help_request().as_deref(), Some("help-1"));
        assert!(!agent.has_help_request());
        assert_eq!(agent.take_help_request(), None);

        agent.request_help_session("help-2");
        agent.cancel_help_request("help-1");
        assert!(
            agent.has_help_request(),
            "only the named session is dropped"
        );
        agent.cancel_help_request("help-2");
        assert!(!agent.has_help_request());
    }

    /// Ending a session in the window is what tells the session loop to stop.
    #[test]
    fn a_session_ended_in_the_window_is_no_longer_hosted() {
        let agent = enrolled_agent();
        agent.begin_session(summary());
        assert!(agent.is_hosting(SESSION));
        assert!(!agent.is_hosting("another-session"));

        agent.end_session();
        assert!(!agent.is_hosting(SESSION));
    }

    /// A hosted session shows the primary display, or the first one listed
    /// when none says it is primary.
    #[test]
    fn a_hosted_session_shows_the_primary_display() {
        let mut second = display(2);
        second.primary = false;
        let layout = MonitorLayout {
            monitors: vec![second.clone(), display(1)],
            active_monitor_id: 2,
        };
        assert_eq!(shared_monitor(&layout), Some(1));

        let layout = MonitorLayout {
            monitors: vec![second],
            active_monitor_id: 2,
        };
        assert_eq!(shared_monitor(&layout), Some(2));

        let empty = MonitorLayout {
            monitors: Vec::new(),
            active_monitor_id: 0,
        };
        assert_eq!(shared_monitor(&empty), None);
    }
}
