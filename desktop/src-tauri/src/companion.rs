//! The control companion: this machine, joined input-only to a session its
//! owner is sharing from the browser.
//!
//! ```text
//!   GET  /devices/me                       pendingCompanions → ask the person here
//!   POST /devices/me/companions/{id}/join  ─▶ a room token for the companion participant
//!   POST /devices/me/companions/{id}/monitor  which monitor is being shared → ACTIVE
//!   wss://…/signal                          ◀─ offer from the person controlling
//!   ═══ aicountly-remote-control ═══════════▶ Agent::handle_control → ControlGate
//!   GET  /devices/me/sessions/{s}/control   who may offer, who holds control, grantId
//! ```
//!
//! # What a companion is not
//!
//! It is not a host. It **never makes the first offer**, offers no media (the
//! picture is the owner's browser share), and has no consent dialog of its
//! own for control — its owner grants each request in the browser. The one
//! question it asks at the machine is whether to join at all, once per link.
//!
//! # The rules this file keeps
//!
//! * **An offer is accepted only from somebody the API says may make one** —
//!   a participant waiting for control, or the one holding it. Anybody else's
//!   offer makes the companion read the API once, immediately, and is dropped
//!   if the answer is still no.
//! * **One peer at a time.** A different controller replaces it; the person
//!   whose peer it is leaving closes it. Neither ends the companion.
//! * **The companion ends only when the API says the link ENDED, the sharer
//!   left, or the session ended** — or the person here ends it, or the app
//!   quits. Losing the signalling socket is not one of those: it reconnects,
//!   make-before-break, exactly as a hosted session does, and the peer
//!   carries on.
//! * **The person at the machine wins.** Stop is local and immediate (the
//!   gate), and using their own mouse or keyboard pauses remote input until
//!   they have been idle for 1.5 s ([`LocalOverride`]).
//!
//! The loop is written against [`CompanionApi`] and [`SignalChannel`] rather
//! than the network types, so the tests below drive it with a fake API, a fake
//! relay and a fake peer, on a paused clock.

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::time::Duration;

use remote_core::{
    ApiClient, ApiError, CompanionPhase, CompanionSummary, MonitorMatch, Outbound,
    PendingCompanion, SessionControlView, SharedSurface, Signal, SignallingError, SignallingSocket,
};
use remote_protocol::{
    ControlEndReason, ControlMessage, Monitor, MonitorLayout, OutboundEnvelopes, PauseReason,
    SurfaceMatch,
};
use remote_webrtc::{IceConfiguration, PeerSession, PeerSessionFactory, PeerState};
use tokio::time::Instant;

use crate::agent::Handled;
use crate::runtime::StateSink;
use crate::session::{self, Joined, CONTROL_POLL, PUMP_INTERVAL, RECONNECT_WINDOW, RENEW_RETRY};
use crate::Agent;

/// How long the person at the machine has to be idle before paused remote
/// input resumes (the owner's decision).
pub const RESUME_AFTER_IDLE: Duration = Duration::from_millis(1500);

/// Local input older than this is not "now": the override reacts to somebody
/// using the machine, not to the last thing they did before sitting back.
const LOCAL_INPUT_RECENT_SECS: f64 = 0.5;

/// How much more recent than the agent's own last injection the machine's last
/// input has to be before it counts as the person's rather than the agent's.
const OWN_INPUT_MARGIN_SECS: f64 = 0.1;

/// Candidates from an offer that is waiting for one API read. Bounded, because
/// they come from a peer nobody has vouched for yet.
const MAX_BUFFERED_CANDIDATES: usize = 64;

/// How long to wait between attempts to get the signalling connection back.
const RECONNECT_PAUSE: Duration = Duration::from_secs(2);

// ------------------------------------------------------------------- seams

/// The signalling socket, as the companion uses it.
#[allow(async_fn_in_trait)]
pub trait SignalChannel {
    /// The next message; `Ok(None)` when the relay closed the socket.
    async fn next(&mut self) -> Result<Option<Signal>, SignallingError>;

    /// Send one message.
    async fn send(&mut self, message: &Outbound) -> Result<(), SignallingError>;

    /// Close it.
    async fn close(self);
}

impl SignalChannel for SignallingSocket {
    async fn next(&mut self) -> Result<Option<Signal>, SignallingError> {
        SignallingSocket::next(self).await
    }

    async fn send(&mut self, message: &Outbound) -> Result<(), SignallingError> {
        SignallingSocket::send(self, message).await
    }

    async fn close(self) {
        SignallingSocket::close(self).await;
    }
}

/// Everything the companion asks of the API and the machine, for one link.
#[allow(async_fn_in_trait)]
pub trait CompanionApi {
    /// The signalling socket type.
    type Channel: SignalChannel;

    /// `POST /devices/me/companions/{id}/join`. Idempotent: called again, it
    /// returns a fresh room token for the same participant.
    async fn join(&self) -> Result<serde_json::Value, ApiError>;

    /// Open a signalling socket.
    async fn connect(&self, url: &str, token: &str) -> Result<Self::Channel, SignallingError>;

    /// `GET /devices/me/sessions/{s}/control`.
    async fn control(&self) -> Result<SessionControlView, ApiError>;

    /// `POST /devices/me/companions/{id}/monitor`.
    async fn report_monitor(
        &self,
        monitor: &Monitor,
        matched_by: MonitorMatch,
    ) -> Result<(), ApiError>;

    /// `POST /devices/me/companions/{id}/leave`.
    async fn leave(&self) -> Result<(), ApiError>;

    /// Every display, in injection space, now.
    fn monitors(&self) -> Option<MonitorLayout>;

    /// Seconds since the machine last saw input, and since the agent last
    /// injected some — what [`LocalOverride`] reads.
    fn input_activity(&self) -> (Option<f64>, Option<f64>);

    /// Whether the platform would deliver injected input right now — on
    /// macOS, whether Accessibility is granted. Read before the companion
    /// reports itself ready, because without it macOS drops every event
    /// silently.
    fn can_inject(&self) -> bool;
}

/// The real API, the real relay, the real displays.
pub struct LiveCompanionApi {
    client: ApiClient,
    agent: Arc<Agent>,
    companion_uuid: String,
    session_uuid: String,
}

impl LiveCompanionApi {
    /// The API for one link.
    #[must_use]
    pub fn new(client: ApiClient, agent: Arc<Agent>, request: &PendingCompanion) -> Self {
        Self {
            client,
            agent,
            companion_uuid: request.companion_uuid.clone(),
            session_uuid: request.session_uuid.clone(),
        }
    }
}

impl CompanionApi for LiveCompanionApi {
    type Channel = SignallingSocket;

    async fn join(&self) -> Result<serde_json::Value, ApiError> {
        self.client.join_companion(&self.companion_uuid).await
    }

    async fn connect(&self, url: &str, token: &str) -> Result<SignallingSocket, SignallingError> {
        SignallingSocket::connect(url, token).await
    }

    async fn control(&self) -> Result<SessionControlView, ApiError> {
        self.client.session_control(&self.session_uuid).await
    }

    async fn report_monitor(
        &self,
        monitor: &Monitor,
        matched_by: MonitorMatch,
    ) -> Result<(), ApiError> {
        self.client
            .report_companion_monitor(&self.companion_uuid, monitor, matched_by)
            .await
    }

    async fn leave(&self) -> Result<(), ApiError> {
        self.client.leave_companion(&self.companion_uuid).await
    }

    fn monitors(&self) -> Option<MonitorLayout> {
        self.agent.monitor_layout().ok()
    }

    fn input_activity(&self) -> (Option<f64>, Option<f64>) {
        self.agent.input_activity()
    }

    fn can_inject(&self) -> bool {
        self.agent.permissions().can_be_companion()
    }
}

// ---------------------------------------------------------------- outcomes

/// Why a companion ended.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum CompanionEnd {
    /// The API says the link is over (ended, expired, declined).
    LinkEnded,
    /// The owner's browser participant — the sharer — left the room.
    SharerLeft,
    /// The session ended.
    SessionEnded,
    /// The person at the machine ended it here.
    LeftHere,
    /// The application is quitting.
    Stopped,
    /// An administrator removed this device.
    Removed,
    /// Joining was refused, with the API's sentence.
    Refused(String),
    /// The signalling connection could not be had back.
    Disconnected,
}

impl CompanionEnd {
    /// What the controller is told on the way out.
    #[must_use]
    pub fn wire_reason(&self) -> ControlEndReason {
        match self {
            Self::LinkEnded | Self::SharerLeft | Self::SessionEnded => {
                ControlEndReason::SessionEnded
            }
            Self::LeftHere => ControlEndReason::StoppedLocally,
            Self::Stopped => ControlEndReason::ShuttingDown,
            Self::Removed | Self::Refused(_) => ControlEndReason::RevokedByServer,
            Self::Disconnected => ControlEndReason::ConnectionLost,
        }
    }

    /// Whether the API has to be told this machine left. When the API ended
    /// the link itself, or the session is gone, there is nothing to leave.
    fn needs_leave(&self) -> bool {
        matches!(self, Self::LeftHere | Self::Stopped | Self::Disconnected)
    }
}

/// The companion join response: the hosted join's shape, plus the link.
pub(crate) struct CompanionJoin {
    joined: Joined,
    session_uuid: String,
    /// The browser participant whose share this companion belongs to.
    sharer_uuid: Option<String>,
}

/// Read `POST …/companions/{id}/join` without trusting its shape.
pub(crate) fn read_companion_join(
    body: &serde_json::Value,
    expected_session: &str,
) -> Result<CompanionJoin, String> {
    let joined = session::read_join(body)?;

    let session_uuid = body["session"]["uuid"]
        .as_str()
        .unwrap_or(expected_session)
        .to_owned();

    // The link names one session; a response for another is not one to join.
    if session_uuid != expected_session {
        return Err("the join response was for a different session".into());
    }

    let sharer_uuid = body["companion"]["linkedParticipantUuid"]
        .as_str()
        .filter(|uuid| !uuid.is_empty())
        .map(str::to_owned);

    Ok(CompanionJoin {
        joined,
        session_uuid,
        sharer_uuid,
    })
}

// ---------------------------------------------------- the local-input override

/// Decision 7: any real mouse or keyboard activity at the machine pauses
/// remote input; 1.5 s of local idleness resumes it.
///
/// Pure: it is given how long since the machine last saw input and how long
/// since the agent last injected some, and says whether to pause or resume.
/// The platforms count injected events in their idle time too, so input only
/// counts as the person's when it is clearly more recent than the agent's own.
#[derive(Debug, Default, Clone, Copy, PartialEq, Eq)]
pub struct LocalOverride {
    paused: bool,
}

impl LocalOverride {
    /// Whether remote input is paused for the person at the machine.
    #[must_use]
    pub fn is_paused(self) -> bool {
        self.paused
    }

    /// One reading. `Some(true)` — pause now; `Some(false)` — resume now.
    ///
    /// `controlled` is whether somebody holds control; with nobody holding
    /// it there is nothing to pause, and a pause in progress is simply
    /// forgotten (control ending has been announced by other means).
    pub fn observe(
        &mut self,
        controlled: bool,
        local_idle_secs: Option<f64>,
        since_injection_secs: Option<f64>,
    ) -> Option<bool> {
        if !controlled {
            self.paused = false;

            return None;
        }

        // A platform that cannot say leaves the override off.
        let idle = local_idle_secs.filter(|idle| idle.is_finite() && *idle >= 0.0)?;

        if self.paused {
            if idle >= RESUME_AFTER_IDLE.as_secs_f64() {
                self.paused = false;

                return Some(false);
            }

            return None;
        }

        let recent = idle < LOCAL_INPUT_RECENT_SECS;
        let not_ours =
            since_injection_secs.is_none_or(|injected| idle + OWN_INPUT_MARGIN_SECS < injected);

        if recent && not_ours {
            self.paused = true;

            return Some(true);
        }

        None
    }
}

// --------------------------------------------------------------- the loop

/// The peer connection to whoever is controlling (or asking to).
struct ActivePeer<P> {
    remote: String,
    session: P,
    /// Whether the monitor layout has gone out on this peer's channel.
    layout_sent: bool,
}

/// An offer from somebody the API had not mentioned, waiting for one read.
struct Deferred {
    from: String,
    payload: serde_json::Value,
    candidates: Vec<serde_json::Value>,
}

/// Which monitor input lands on, and what it was decided against.
#[derive(Default)]
struct MonitorState {
    chosen: Option<Monitor>,
    /// The candidates the person at the machine is being asked to pick from.
    asking: Option<Vec<u32>>,
    /// The layout the decision was made with.
    layout: Option<MonitorLayout>,
    /// The surface size the decision was made for.
    size: Option<(u32, u32)>,
    /// How the chosen monitor was decided, to report it again the same way.
    matched_by: Option<MonitorMatch>,
}

impl MonitorState {
    fn settled_for(&self, layout: &MonitorLayout, size: Option<(u32, u32)>) -> bool {
        (self.chosen.is_some() || self.asking.is_some())
            && self.layout.as_ref() == Some(layout)
            && self.size == size
    }
}

struct Companion<'a, A: CompanionApi, F: PeerSessionFactory> {
    agent: &'a Arc<Agent>,
    sink: &'a StateSink,
    api: &'a A,
    factory: &'a F,
    companion_uuid: String,
    self_uuid: String,
    sharer_uuid: Option<String>,
    ice: IceConfiguration,
    outbound: OutboundEnvelopes,
    peer: Option<ActivePeer<F::Session>>,
    deferred: Option<Deferred>,
    /// Who may offer: everybody waiting for control, and whoever holds it.
    allowed: Vec<String>,
    poll_now: bool,
    surface: Option<(u32, u32)>,
    /// Whether the owner is sharing an entire screen right now. Nothing is
    /// matched or reported while they are not: there is no picture to match.
    sharing_monitor: bool,
    /// Whether the API has the link back in `JOINED` — it does whenever the
    /// share changed or stopped — so the monitor has to be reported again.
    link_joined: bool,
    monitor: MonitorState,
    overrides: LocalOverride,
}

/// Be this machine's owner's control companion for one link, until it ends.
///
/// Called by the connection loop after the person at the machine confirmed
/// the link. Everything that happens is visible: the indicator goes on before
/// the socket opens and off in every exit path.
pub async fn run<A, F>(
    agent: Arc<Agent>,
    sink: StateSink,
    api: A,
    factory: F,
    request: PendingCompanion,
    stopping: Arc<AtomicBool>,
) -> CompanionEnd
where
    A: CompanionApi,
    F: PeerSessionFactory,
{
    let joined = match api.join().await {
        Ok(body) => match read_companion_join(&body, &request.session_uuid) {
            Ok(joined) => joined,
            Err(reason) => return CompanionEnd::Refused(reason),
        },
        Err(error) if error.is_device_rejected() => return CompanionEnd::Removed,
        Err(ApiError::Refused { message, .. }) => return CompanionEnd::Refused(message),
        Err(error) => return CompanionEnd::Refused(error.to_string()),
    };

    // Visible before anything is opened.
    sink(
        agent.begin_companion(CompanionSummary {
            companion_uuid: request.companion_uuid.clone(),
            session_uuid: joined.session_uuid.clone(),
            display_id: if joined.joined.display_id.is_empty() {
                request.display_id.clone()
            } else {
                joined.joined.display_id.clone()
            },
            company_name: joined
                .joined
                .company_name
                .clone()
                .or_else(|| request.company_name.clone()),
            requested_by_name: request.requested_by_name.clone(),
            expires_at: None,
            started_at: Some(session::now_iso()),
            phase: CompanionPhase::ChoosingMonitor,
            monitors: Vec::new(),
            monitor: None,
            controller_name: None,
        }),
    );

    let mut companion = Companion {
        agent: &agent,
        sink: &sink,
        api: &api,
        factory: &factory,
        companion_uuid: request.companion_uuid.clone(),
        self_uuid: joined.joined.participant_uuid.clone(),
        sharer_uuid: joined.sharer_uuid.clone(),
        ice: joined.joined.ice.clone(),
        outbound: OutboundEnvelopes::starting_now(
            joined.session_uuid.clone(),
            joined.joined.participant_uuid.clone(),
        ),
        peer: None,
        deferred: None,
        allowed: Vec::new(),
        poll_now: true,
        surface: None,
        sharing_monitor: false,
        link_joined: false,
        monitor: MonitorState::default(),
        overrides: LocalOverride::default(),
    };

    tracing::info!(
        session = %joined.session_uuid,
        participant = %joined.joined.participant_uuid,
        "this computer joined the session as its owner's control companion"
    );

    let outcome = match api
        .connect(
            &joined.joined.signalling_url,
            &joined.joined.signalling_token,
        )
        .await
    {
        Ok(socket) => {
            companion
                .drive(socket, joined.joined.signalling_expires_at, &stopping)
                .await
        }
        Err(SignallingError::Address(reason)) => CompanionEnd::Refused(reason),
        Err(error) => {
            tracing::warn!(%error, "the signalling connection failed");

            match companion.reconnect(&stopping).await {
                Renewal::Fresh(socket, expires_at) => {
                    companion.drive(socket, expires_at, &stopping).await
                }
                Renewal::Over(end) => end,
                Renewal::Retry => CompanionEnd::Disconnected,
            }
        }
    };

    companion.finish(&outcome).await;
    sink(agent.end_companion());

    tracing::info!(?outcome, "the control companion ended");

    outcome
}

/// What became of an attempt to get a fresh signalling connection.
enum Renewal<C> {
    Fresh(C, Option<time::OffsetDateTime>),
    Over(CompanionEnd),
    Retry,
}

impl<A: CompanionApi, F: PeerSessionFactory> Companion<'_, A, F> {
    async fn drive(
        &mut self,
        mut socket: A::Channel,
        expires_at: Option<time::OffsetDateTime>,
        stopping: &Arc<AtomicBool>,
    ) -> CompanionEnd {
        let mut renew_after = Instant::now() + session::renew_in(expires_at);
        let mut last_poll: Option<Instant> = None;

        loop {
            if stopping.load(Ordering::SeqCst) {
                socket.close().await;

                return CompanionEnd::Stopped;
            }

            if self.agent.take_companion_leave() {
                socket.close().await;

                return CompanionEnd::LeftHere;
            }

            // --- signalling -----------------------------------------------
            match tokio::time::timeout(PUMP_INTERVAL, socket.next()).await {
                Ok(Ok(Some(signal))) => {
                    if let Some(end) = self.on_signal(signal, &mut socket).await {
                        socket.close().await;

                        return end;
                    }
                }
                // The relay closed it or the network dropped it. Neither ends
                // a companion: the peer carries control on its own.
                Ok(Ok(None)) | Ok(Err(_)) => {
                    tracing::info!("the signalling connection closed; reconnecting");

                    match self.reconnect(stopping).await {
                        Renewal::Fresh(fresh, expires_at) => {
                            socket = fresh;
                            renew_after = Instant::now() + session::renew_in(expires_at);

                            continue;
                        }
                        Renewal::Over(end) => return end,
                        Renewal::Retry => return CompanionEnd::Disconnected,
                    }
                }
                Err(_) => {}
            }

            // --- make before break ----------------------------------------
            if Instant::now() >= renew_after {
                match self.renew().await {
                    Renewal::Fresh(fresh, expires_at) => {
                        let old = std::mem::replace(&mut socket, fresh);
                        old.close().await;
                        renew_after = Instant::now() + session::renew_in(expires_at);
                        tracing::debug!("the companion's signalling connection was renewed");
                    }
                    Renewal::Over(end) => {
                        socket.close().await;

                        return end;
                    }
                    Renewal::Retry => renew_after = Instant::now() + RENEW_RETRY,
                }
            }

            self.pump_peer(&mut socket).await;
            self.watch_local_input().await;

            // --- a monitor picked in the window ---------------------------
            if let Some(id) = self.agent.take_monitor_choice(&self.companion_uuid) {
                self.choose_monitor(id).await;
            }

            // --- what the API says ----------------------------------------
            let due = last_poll.is_none_or(|at| at.elapsed() >= CONTROL_POLL);

            if self.poll_now || due {
                self.poll_now = false;
                last_poll = Some(Instant::now());

                match self.api.control().await {
                    Ok(view) => {
                        if let Some(end) = self.on_view(&view, &mut socket).await {
                            socket.close().await;

                            return end;
                        }
                    }
                    Err(error) if error.is_device_rejected() => {
                        socket.close().await;

                        return CompanionEnd::Removed;
                    }
                    Err(ApiError::Refused {
                        status: 404 | 410, ..
                    }) => {
                        socket.close().await;

                        return CompanionEnd::SessionEnded;
                    }
                    Err(error) => {
                        tracing::debug!(%error, "the control state could not be read");

                        // An offer waiting on this read is dropped rather than
                        // accepted on no evidence.
                        self.deferred = None;
                    }
                }
            }

            // --- a peer that failed is gone; the companion is not ----------
            if self
                .peer
                .as_ref()
                .is_some_and(|peer| peer.session.state() == PeerState::Failed)
            {
                self.drop_peer("the connection failed").await;
            }
        }
    }

    /// One relay message. `Some` ends the companion.
    async fn on_signal(&mut self, signal: Signal, socket: &mut A::Channel) -> Option<CompanionEnd> {
        match signal {
            Signal::Offer { from, payload } => {
                if from == self.self_uuid {
                    return None;
                }

                if self.allowed.contains(&from) {
                    self.accept_offer(from, payload, Vec::new(), socket).await;
                } else {
                    // Not somebody the API has mentioned. Ask it once, now,
                    // and drop the offer if the answer is still no.
                    tracing::debug!("an offer arrived from somebody not waiting for control");
                    self.deferred = Some(Deferred {
                        from,
                        payload,
                        candidates: Vec::new(),
                    });
                    self.poll_now = true;
                }
            }

            Signal::IceCandidate { from, payload } => {
                if let Some(peer) = self.peer.as_mut().filter(|peer| peer.remote == from) {
                    // A candidate the library cannot use is normal during trickle.
                    let _ = peer.session.add_ice_candidate(payload).await;
                } else if let Some(deferred) = self
                    .deferred
                    .as_mut()
                    .filter(|deferred| deferred.from == from)
                {
                    if deferred.candidates.len() < MAX_BUFFERED_CANDIDATES {
                        deferred.candidates.push(payload);
                    }
                }
            }

            Signal::PeerLeft { from } => {
                if self.sharer_uuid.as_deref() == Some(from.as_str()) {
                    return Some(CompanionEnd::SharerLeft);
                }

                if self.peer.as_ref().is_some_and(|peer| peer.remote == from) {
                    self.drop_peer("the person controlling left").await;
                }

                if self
                    .deferred
                    .as_ref()
                    .is_some_and(|deferred| deferred.from == from)
                {
                    self.deferred = None;
                }

                // Somebody leaving may have been waiting for control.
                self.poll_now = true;
            }

            Signal::SessionEnded { .. } => return Some(CompanionEnd::SessionEnded),

            // "Poll now": read the API, which is the only authority.
            Signal::ControlHint { .. } | Signal::PeerJoined { .. } => self.poll_now = true,

            Signal::Error { code, message } => {
                tracing::warn!(%code, %message, "the relay refused something");
            }

            // A companion never offers, so it has no answer to take; the rest
            // needs nothing.
            Signal::Answer { .. }
            | Signal::Joined { .. }
            | Signal::Renegotiate { .. }
            | Signal::PeerUnavailable { .. }
            | Signal::DeviceInvite { .. }
            | Signal::Pong
            | Signal::Unknown => {}
        }

        None
    }

    /// Answer an offer from somebody allowed to make one.
    async fn accept_offer(
        &mut self,
        from: String,
        payload: serde_json::Value,
        candidates: Vec<serde_json::Value>,
        socket: &mut A::Channel,
    ) {
        // A renegotiation from the same person goes to the same peer; if that
        // peer cannot take it (the browser reloaded), a fresh one does.
        if let Some(peer) = self.peer.as_mut().filter(|peer| peer.remote == from) {
            if let Ok(answer) = peer.session.accept_offer(payload.clone()).await {
                peer.layout_sent = false;
                let _ = socket
                    .send(&Outbound::Answer {
                        to: from,
                        payload: answer,
                    })
                    .await;

                return;
            }
        }

        // One peer at a time.
        if self.peer.is_some() {
            self.drop_peer("another participant is now controlling")
                .await;
        }

        let mut session = match self.factory.create_control_only(self.ice.clone()).await {
            Ok(session) => session,
            Err(error) => {
                tracing::warn!(%error, "a control-only connection could not be created");

                return;
            }
        };

        match session.accept_offer(payload).await {
            Ok(answer) => {
                for candidate in candidates {
                    let _ = session.add_ice_candidate(candidate).await;
                }

                let _ = socket
                    .send(&Outbound::Answer {
                        to: from.clone(),
                        payload: answer,
                    })
                    .await;

                self.peer = Some(ActivePeer {
                    remote: from,
                    session,
                    layout_sent: false,
                });
            }
            Err(error) => {
                tracing::warn!(%error, "an offer could not be answered");
                let _ = session.close().await;
            }
        }
    }

    async fn drop_peer(&mut self, why: &str) {
        if let Some(mut peer) = self.peer.take() {
            tracing::info!(why, "the control connection was closed");
            let _ = peer.session.close().await;
        }
    }

    /// Trickle candidates, send the monitor layout once the channel opens, and
    /// put whatever arrived on the control channel through the gate.
    async fn pump_peer(&mut self, socket: &mut A::Channel) {
        let layout = match (&self.monitor.chosen, &self.monitor.layout) {
            (Some(chosen), Some(layout)) => layout.with_active(chosen.id),
            _ => None,
        };

        let Some(peer) = self.peer.as_mut() else {
            // Nobody to tell; a Stop is still a Stop.
            let _ = self.agent.take_stop_notice();

            return;
        };

        for candidate in peer.session.take_local_candidates() {
            let _ = socket
                .send(&Outbound::IceCandidate {
                    to: peer.remote.clone(),
                    payload: candidate,
                })
                .await;
        }

        let mut replies = Vec::new();

        if !peer.layout_sent {
            if let Some(layout) = layout {
                if peer.session.control_channel_open().await {
                    replies.push(ControlMessage::MonitorLayout(layout));
                    peer.layout_sent = true;
                }
            }
        }

        // Every message goes through the gate. Nothing here inspects one first.
        match peer.session.receive_control().await {
            Ok(messages) => {
                for bytes in messages {
                    match self.agent.handle_control(&bytes) {
                        Ok(Handled::Pinged(nonce)) => replies.push(ControlMessage::Pong { nonce }),
                        Ok(_) => {}
                        Err(error) => tracing::debug!(?error, "a control message was refused"),
                    }
                }
            }
            Err(error) => tracing::debug!(%error, "the control channel could not be read"),
        }

        if self.agent.take_stop_notice() {
            replies.push(ControlMessage::ControlEnded {
                reason: ControlEndReason::StoppedLocally,
            });
        }

        for message in replies {
            self.send_control(message).await;
        }
    }

    async fn send_control(&mut self, message: ControlMessage) {
        let Some(peer) = self.peer.as_mut() else {
            return;
        };

        match self.outbound.encode(message) {
            Ok(bytes) => {
                if let Err(error) = peer.session.send_control(&bytes).await {
                    tracing::debug!(%error, "a control message could not be sent");
                }
            }
            Err(error) => tracing::warn!(%error, "a control message could not be encoded"),
        }
    }

    /// The person at the machine wins: their own input pauses remote input.
    async fn watch_local_input(&mut self) {
        let controlled = self
            .agent
            .state()
            .active_companion()
            .is_some_and(CompanionSummary::is_controlled)
            && (self.overrides.is_paused() || self.agent.control_admits_input());

        let (local, injected) = self.api.input_activity();

        match self.overrides.observe(controlled, local, injected) {
            Some(true) => {
                (self.sink)(self.agent.set_companion_paused(true));
                self.send_control(ControlMessage::ControlPaused {
                    reason: PauseReason::LocalInput,
                })
                .await;
            }
            Some(false) => {
                (self.sink)(self.agent.set_companion_paused(false));
                self.send_control(ControlMessage::ControlResumed).await;
            }
            None => {}
        }
    }

    /// What the API currently says. `Some` ends the companion.
    async fn on_view(
        &mut self,
        view: &SessionControlView,
        socket: &mut A::Channel,
    ) -> Option<CompanionEnd> {
        if let Some(link) = &view.companion {
            if link.is_over() {
                return Some(CompanionEnd::LinkEnded);
            }

            if self.sharer_uuid.is_none() {
                self.sharer_uuid = link.linked_participant_uuid.clone();
            }
        }

        // Who may drive this machine's mouse and keyboard -- and nobody at
        // all when the organisation has remote control switched off. A
        // companion exists only to be controlled, so that switch is the whole
        // of its permission, and the peer check below closes a connection that
        // was already open when the policy changed.
        self.allowed = if view.allow_remote_control {
            view.pending_requests
                .iter()
                .map(|request| request.participant_uuid.clone())
                .chain(view.controller_uuid.clone())
                .collect()
        } else {
            if !view.pending_requests.is_empty() || view.controller_uuid.is_some() {
                tracing::warn!(
                    "this organisation does not permit remote control; no companion peer is \
                     being admitted"
                );
            }

            Vec::new()
        };

        // One peer at a time, for somebody who may hold it. A peer for
        // somebody no longer waiting or controlling — refused, revoked, or
        // replaced by another controller — is closed.
        if self
            .peer
            .as_ref()
            .is_some_and(|peer| !self.allowed.contains(&peer.remote))
        {
            self.drop_peer("that participant no longer holds or awaits control")
                .await;
        }

        // An offer that was waiting for this read: accepted if the API now
        // names its sender, dropped if not.
        if let Some(deferred) = self.deferred.take() {
            if self.allowed.contains(&deferred.from) {
                self.accept_offer(deferred.from, deferred.payload, deferred.candidates, socket)
                    .await;
            } else {
                tracing::info!("an offer from somebody not waiting for control was dropped");
            }
        }

        // The monitor first: control is only shown, and only lands, once
        // there is a monitor for it to land on.
        self.surface = view.shared_surface.as_ref().and_then(SharedSurface::size);
        self.sharing_monitor = view
            .shared_surface
            .as_ref()
            .is_some_and(|surface| surface.display_surface == remote_core::SurfaceKind::Monitor);
        self.link_joined = view
            .companion
            .as_ref()
            .is_some_and(|link| link.status == "JOINED");
        self.settle_monitor().await;

        (self.sink)(self.agent.sync_companion_control(
            view.controller_uuid.clone(),
            view.controller_name.clone(),
            view.grant_id.clone(),
        ));

        None
    }

    /// Work out which monitor is being shared, or ask; again whenever the
    /// layout or the shared surface's size changes.
    async fn settle_monitor(&mut self) {
        let Some(layout) = self.api.monitors() else {
            return;
        };

        // Only an entire-screen share can be matched to a monitor; while the
        // owner shares anything else, or nothing, the last decision stands.
        if !self.sharing_monitor {
            return;
        }

        if self.monitor.settled_for(&layout, self.surface) {
            // The same share, but the API moved the link back to JOINED (the
            // share stopped and started again): report the same monitor again,
            // the same way. Posting the same monitor never revokes anything.
            if self.link_joined {
                if let (Some(chosen), Some(matched_by)) =
                    (self.monitor.chosen.clone(), self.monitor.matched_by)
                {
                    self.report_monitor(&layout, chosen.id, matched_by).await;
                }
            }

            return;
        }

        let (width, height) = self.surface.unwrap_or((0, 0));

        match layout.match_surface(width, height) {
            SurfaceMatch::Single(id) => {
                self.report_monitor(&layout, id, MonitorMatch::AutoSingle)
                    .await
            }
            SurfaceMatch::Aspect(id) => {
                self.report_monitor(&layout, id, MonitorMatch::AutoAspect)
                    .await
            }
            SurfaceMatch::Ambiguous { candidates } => {
                let options: Vec<Monitor> = candidates
                    .iter()
                    .filter_map(|id| layout.find(*id).cloned())
                    .collect();

                (self.sink)(self.agent.companion_choose_monitor(&options));

                self.monitor = MonitorState {
                    chosen: None,
                    asking: Some(candidates),
                    layout: Some(layout),
                    size: self.surface,
                    matched_by: None,
                };
            }
        }
    }

    /// The person at the machine picked one.
    async fn choose_monitor(&mut self, id: u32) {
        let Some(layout) = self.api.monitors() else {
            return;
        };

        let offered = self
            .monitor
            .asking
            .as_ref()
            .is_none_or(|candidates| candidates.contains(&id));

        if offered && layout.find(id).is_some() {
            self.report_monitor(&layout, id, MonitorMatch::User).await;
        }
    }

    async fn report_monitor(&mut self, layout: &MonitorLayout, id: u32, matched_by: MonitorMatch) {
        let Some(monitor) = layout.find(id).cloned() else {
            return;
        };

        // Not ready while macOS would drop every event: left unsettled, so
        // the read after Accessibility is granted reports it.
        if !self.api.can_inject() {
            tracing::info!("waiting for permission to send input before reporting ready");

            return;
        }

        if let Err(error) = self.api.report_monitor(&monitor, matched_by).await {
            // Left unsettled, so the next read tries again.
            tracing::warn!(%error, "the shared monitor could not be reported");

            return;
        }

        (self.sink)(self.agent.companion_monitor_chosen(&monitor));

        self.monitor = MonitorState {
            chosen: Some(monitor),
            asking: None,
            layout: Some(layout.clone()),
            size: self.surface,
            matched_by: Some(matched_by),
        };

        // The controller maps against the layout: send it again.
        if let Some(peer) = self.peer.as_mut() {
            peer.layout_sent = false;
        }
    }

    /// Join again and open a new socket with the fresh token.
    async fn renew(&self) -> Renewal<A::Channel> {
        let body = match self.api.join().await {
            Ok(body) => body,
            Err(error) if error.is_device_rejected() => {
                return Renewal::Over(CompanionEnd::Removed)
            }
            Err(ApiError::Refused {
                status: 404 | 409 | 410,
                ..
            }) => return Renewal::Over(CompanionEnd::LinkEnded),
            Err(error) => {
                tracing::debug!(%error, "the companion's token could not be renewed yet");

                return Renewal::Retry;
            }
        };

        let fresh = match session::read_join(&body) {
            Ok(fresh) => fresh,
            Err(reason) => {
                tracing::warn!(%reason, "the renewal response could not be read");

                return Renewal::Retry;
            }
        };

        match self
            .api
            .connect(&fresh.signalling_url, &fresh.signalling_token)
            .await
        {
            Ok(socket) => Renewal::Fresh(socket, fresh.signalling_expires_at),
            Err(error) => {
                tracing::debug!(%error, "the renewed signalling connection failed");

                Renewal::Retry
            }
        }
    }

    /// [`Self::renew`], for up to [`RECONNECT_WINDOW`].
    async fn reconnect(&self, stopping: &Arc<AtomicBool>) -> Renewal<A::Channel> {
        let started = Instant::now();

        loop {
            if stopping.load(Ordering::SeqCst) {
                return Renewal::Over(CompanionEnd::Stopped);
            }

            match self.renew().await {
                Renewal::Retry if started.elapsed() < RECONNECT_WINDOW => {
                    tokio::time::sleep(RECONNECT_PAUSE).await;
                }
                outcome => return outcome,
            }
        }
    }

    /// Tell the controller, close the peer, and tell the API this machine
    /// left when it did not already know.
    async fn finish(&mut self, outcome: &CompanionEnd) {
        if self.peer.is_some() {
            self.send_control(ControlMessage::ControlEnded {
                reason: outcome.wire_reason(),
            })
            .await;
        }

        self.drop_peer("the companion ended").await;

        if outcome.needs_leave() {
            if let Err(error) = self.api.leave().await {
                tracing::debug!(%error, "leaving the companion link could not be reported");
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use std::collections::VecDeque;
    use std::sync::Mutex;

    use remote_core::{AgentEvent, CompanionView, PendingControlRequest};
    use remote_device::CaptureProfile;
    use remote_protocol::{ControlEnvelope, Orientation};
    use remote_webrtc::WebRtcError;

    use super::*;

    const SESSION: &str = "session-1";
    const SELF: &str = "companion-participant";
    const SHARER: &str = "participant-a";
    const B: &str = "participant-b";
    const C: &str = "participant-c";

    // ------------------------------------------------------------ the world

    /// Everything the fakes share and the test inspects.
    #[derive(Default)]
    struct World {
        view: SessionControlView,
        layout: Option<MonitorLayout>,
        /// Signals queued per socket, by connection index.
        inboxes: Vec<VecDeque<Inbound>>,
        sent: Vec<(usize, Outbound)>,
        closed_sockets: Vec<usize>,
        joins: usize,
        polls: usize,
        reported: Vec<(u32, MonitorMatch)>,
        left: bool,
        token_lifetime_secs: i64,
        peers: Vec<PeerLog>,
        /// Bytes waiting to arrive on the newest peer's control channel.
        control_inbox: Vec<Vec<u8>>,
        /// Whether Accessibility (or its platform's equivalent) is granted.
        can_inject: bool,
    }

    enum Inbound {
        Signal(Signal),
        /// The relay drops the socket.
        Drop,
    }

    #[derive(Default, Clone)]
    struct PeerLog {
        offers: usize,
        candidates: usize,
        closed: bool,
        sent: Vec<ControlMessage>,
    }

    type Shared = Arc<Mutex<World>>;

    impl World {
        fn push(world: &Shared, signal: Signal) {
            let mut world = world.lock().unwrap();
            let newest = world.inboxes.len() - 1;
            world.inboxes[newest].push_back(Inbound::Signal(signal));
        }

        fn drop_socket(world: &Shared) {
            let mut world = world.lock().unwrap();
            let newest = world.inboxes.len() - 1;
            world.inboxes[newest].push_back(Inbound::Drop);
        }
    }

    struct FakeChannel {
        index: usize,
        world: Shared,
    }

    impl SignalChannel for FakeChannel {
        async fn next(&mut self) -> Result<Option<Signal>, SignallingError> {
            loop {
                let next = self.world.lock().unwrap().inboxes[self.index].pop_front();

                match next {
                    Some(Inbound::Signal(signal)) => return Ok(Some(signal)),
                    Some(Inbound::Drop) => return Ok(None),
                    None => tokio::time::sleep(Duration::from_millis(10)).await,
                }
            }
        }

        async fn send(&mut self, message: &Outbound) -> Result<(), SignallingError> {
            self.world
                .lock()
                .unwrap()
                .sent
                .push((self.index, message.clone()));

            Ok(())
        }

        async fn close(self) {
            self.world.lock().unwrap().closed_sockets.push(self.index);
        }
    }

    struct FakeApi {
        world: Shared,
    }

    fn join_body(lifetime_secs: i64) -> serde_json::Value {
        let expires = (time::OffsetDateTime::now_utc() + time::Duration::seconds(lifetime_secs))
            .format(&time::format_description::well_known::Rfc3339)
            .unwrap();

        serde_json::json!({
            "session": { "uuid": SESSION, "displayId": "123-456", "companyName": "Northwind" },
            "participant": { "uuid": SELF },
            "signalling": { "token": "t", "url": "wss://relay.test/signal", "expiresAt": expires },
            "iceServers": [],
            "relayAvailable": false,
            "companion": { "uuid": "companion-1", "status": "JOINED", "linkedParticipantUuid": SHARER }
        })
    }

    impl CompanionApi for FakeApi {
        type Channel = FakeChannel;

        async fn join(&self) -> Result<serde_json::Value, ApiError> {
            let mut world = self.world.lock().unwrap();
            world.joins += 1;

            Ok(join_body(world.token_lifetime_secs))
        }

        async fn connect(&self, _url: &str, _token: &str) -> Result<FakeChannel, SignallingError> {
            let mut world = self.world.lock().unwrap();
            world.inboxes.push(VecDeque::new());

            Ok(FakeChannel {
                index: world.inboxes.len() - 1,
                world: Arc::clone(&self.world),
            })
        }

        async fn control(&self) -> Result<SessionControlView, ApiError> {
            let mut world = self.world.lock().unwrap();
            world.polls += 1;

            Ok(world.view.clone())
        }

        async fn report_monitor(
            &self,
            monitor: &Monitor,
            matched_by: MonitorMatch,
        ) -> Result<(), ApiError> {
            self.world
                .lock()
                .unwrap()
                .reported
                .push((monitor.id, matched_by));

            Ok(())
        }

        async fn leave(&self) -> Result<(), ApiError> {
            self.world.lock().unwrap().left = true;

            Ok(())
        }

        fn monitors(&self) -> Option<MonitorLayout> {
            self.world.lock().unwrap().layout.clone()
        }

        fn input_activity(&self) -> (Option<f64>, Option<f64>) {
            // Nobody at this keyboard: the override is exercised on its own,
            // below, rather than by whoever runs the tests typing.
            (None, None)
        }

        fn can_inject(&self) -> bool {
            self.world.lock().unwrap().can_inject
        }
    }

    struct FakePeer {
        index: usize,
        world: Shared,
    }

    impl FakePeer {
        fn with<T>(&self, f: impl FnOnce(&mut PeerLog) -> T) -> T {
            f(&mut self.world.lock().unwrap().peers[self.index])
        }
    }

    impl PeerSession for FakePeer {
        async fn create_offer(&mut self) -> Result<serde_json::Value, WebRtcError> {
            panic!("a companion never makes the first offer");
        }

        async fn accept_offer(
            &mut self,
            _offer: serde_json::Value,
        ) -> Result<serde_json::Value, WebRtcError> {
            self.with(|log| log.offers += 1);

            Ok(serde_json::json!({ "type": "answer", "sdp": "v=0" }))
        }

        async fn accept_answer(&mut self, _answer: serde_json::Value) -> Result<(), WebRtcError> {
            Ok(())
        }

        async fn add_ice_candidate(
            &mut self,
            _candidate: serde_json::Value,
        ) -> Result<(), WebRtcError> {
            self.with(|log| log.candidates += 1);

            Ok(())
        }

        fn take_local_candidates(&mut self) -> Vec<serde_json::Value> {
            Vec::new()
        }

        async fn send_frame(&mut self, _frame: remote_device::Frame) -> Result<(), WebRtcError> {
            panic!("a companion sends no frames");
        }

        async fn send_control(&mut self, bytes: &[u8]) -> Result<(), WebRtcError> {
            let envelope = ControlEnvelope::decode(bytes).expect("every message is enveloped");
            assert_eq!(envelope.session_uuid, SESSION);
            assert_eq!(envelope.participant_uuid, SELF);
            self.with(|log| log.sent.push(envelope.message));

            Ok(())
        }

        async fn receive_control(&mut self) -> Result<Vec<Vec<u8>>, WebRtcError> {
            Ok(std::mem::take(
                &mut self.world.lock().unwrap().control_inbox,
            ))
        }

        async fn control_channel_open(&mut self) -> bool {
            true
        }

        fn state(&self) -> PeerState {
            PeerState::Connected
        }

        async fn congestion(&self) -> Option<remote_webrtc::Congestion> {
            None
        }

        async fn close(&mut self) -> Result<(), WebRtcError> {
            self.with(|log| log.closed = true);

            Ok(())
        }
    }

    struct FakeFactory {
        world: Shared,
    }

    impl PeerSessionFactory for FakeFactory {
        type Session = FakePeer;

        async fn create(
            &self,
            _ice: IceConfiguration,
            _profile: CaptureProfile,
        ) -> Result<FakePeer, WebRtcError> {
            panic!("a companion has no video track");
        }

        async fn create_control_only(
            &self,
            _ice: IceConfiguration,
        ) -> Result<FakePeer, WebRtcError> {
            let mut world = self.world.lock().unwrap();
            world.peers.push(PeerLog::default());

            Ok(FakePeer {
                index: world.peers.len() - 1,
                world: Arc::clone(&self.world),
            })
        }

        fn implementation(&self) -> &'static str {
            "fake"
        }
    }

    // ------------------------------------------------------------- fixtures

    fn display(id: u32, x: i32) -> Monitor {
        Monitor {
            id,
            name: format!("Display {id}"),
            primary: id == 1,
            x,
            y: 0,
            width: 2560,
            height: 1440,
            scale: 1.0,
            refresh_hz: None,
            orientation: Orientation::Landscape,
        }
    }

    fn view(pending: &[&str], controller: Option<&str>, grant: Option<&str>) -> SessionControlView {
        SessionControlView {
            controller_uuid: controller.map(str::to_owned),
            controller_name: controller.map(|_| "Sam".to_owned()),
            pending_requests: pending
                .iter()
                .map(|uuid| PendingControlRequest {
                    participant_uuid: (*uuid).to_owned(),
                    display_name: "Sam".into(),
                    requested_at: None,
                })
                .collect(),
            allow_remote_control: true,
            grant_id: grant.map(str::to_owned),
            companion: Some(CompanionView {
                uuid: "companion-1".into(),
                status: "ACTIVE".into(),
                linked_participant_uuid: Some(SHARER.into()),
                participant_uuid: Some(SELF.into()),
                ready: true,
                ..CompanionView::default()
            }),
            shared_surface: Some(SharedSurface {
                display_surface: remote_core::SurfaceKind::Monitor,
                width: Some(2560),
                height: Some(1440),
                verified: true,
            }),
            ..SessionControlView::default()
        }
    }

    fn world(view: SessionControlView, monitors: Vec<Monitor>) -> Shared {
        let active = monitors[0].id;

        Arc::new(Mutex::new(World {
            view,
            layout: Some(MonitorLayout {
                monitors,
                active_monitor_id: active,
            }),
            token_lifetime_secs: 120,
            can_inject: true,
            ..World::default()
        }))
    }

    fn agent() -> Arc<Agent> {
        let agent = Agent::new();
        agent.apply(AgentEvent::Enrolled {
            device_uuid: "device-1".into(),
            device_name: "MacBook".into(),
            company_name: None,
            key_fingerprint: "AAAA".into(),
        });
        agent.apply(AgentEvent::Authenticated);

        Arc::new(agent)
    }

    fn request() -> PendingCompanion {
        PendingCompanion {
            companion_uuid: "companion-1".into(),
            session_uuid: SESSION.into(),
            display_id: "123-456".into(),
            company_name: Some("Northwind".into()),
            requested_by_name: Some("Rahul".into()),
            requested_at: None,
            expires_at: None,
        }
    }

    fn offer(from: &str) -> Signal {
        Signal::Offer {
            from: from.into(),
            payload: serde_json::json!({ "type": "offer", "sdp": "v=0" }),
        }
    }

    fn candidate(from: &str) -> Signal {
        Signal::IceCandidate {
            from: from.into(),
            payload: serde_json::json!({ "candidate": "candidate:1" }),
        }
    }

    /// Run the companion against `world`, with `script` acting on it
    /// alongside, on a paused clock.
    async fn run_with<S>(world: &Shared, agent: &Arc<Agent>, script: S) -> CompanionEnd
    where
        S: std::future::Future<Output = ()>,
    {
        let sink: StateSink = Arc::new(|_| {});
        let stopping = Arc::new(AtomicBool::new(false));

        let companion = run(
            Arc::clone(agent),
            sink,
            FakeApi {
                world: Arc::clone(world),
            },
            FakeFactory {
                world: Arc::clone(world),
            },
            request(),
            stopping,
        );

        let (outcome, ()) = tokio::join!(companion, script);

        outcome
    }

    async fn wait(millis: u64) {
        tokio::time::sleep(Duration::from_millis(millis)).await;
    }

    fn answers_to(world: &Shared, to: &str) -> usize {
        world
            .lock()
            .unwrap()
            .sent
            .iter()
            .filter(|(_, message)| matches!(message, Outbound::Answer { to: target, .. } if target == to))
            .count()
    }

    // ---------------------------------------------------------------- tests

    /// The rule the whole data path rests on: an offer from somebody the API
    /// has not named is not answered. The companion reads the API once, at
    /// once, and drops the offer when the answer is still no.
    #[tokio::test(start_paused = true)]
    async fn an_offer_from_somebody_who_has_not_asked_is_ignored() {
        let world = world(view(&[], None, None), vec![display(1, 0)]);
        let agent = agent();

        let outcome = run_with(&world, &agent, {
            let world = Arc::clone(&world);
            async move {
                wait(1_000).await;
                let polls_before = world.lock().unwrap().polls;

                World::push(&world, offer("intruder"));
                World::push(&world, candidate("intruder"));
                wait(200).await;

                // Read again immediately — not at the next 3 s poll.
                assert_eq!(world.lock().unwrap().polls, polls_before + 1);

                wait(4_000).await;
                World::push(
                    &world,
                    Signal::SessionEnded {
                        from: SHARER.into(),
                    },
                );
            }
        })
        .await;

        assert_eq!(outcome, CompanionEnd::SessionEnded);

        let world = world.lock().unwrap();
        assert!(
            world.peers.is_empty(),
            "no connection was made for the intruder"
        );
        assert!(world.sent.iter().all(|(_, message)| !matches!(
            message,
            Outbound::Answer { .. } | Outbound::Offer { .. }
        )));
    }

    /// The organisation's switch, applied where it lands. Somebody the API
    /// says is waiting still cannot drive this machine when remote control is
    /// switched off -- a companion exists only to be controlled, so that
    /// switch is the whole of its permission.
    #[tokio::test(start_paused = true)]
    async fn nobody_is_admitted_when_the_organisation_forbids_remote_control() {
        let mut refused = view(&[B], None, None);
        refused.allow_remote_control = false;

        let world = world(refused, vec![display(1, 0)]);
        let agent = agent();

        let outcome = run_with(&world, &agent, {
            let world = Arc::clone(&world);
            async move {
                wait(1_000).await;

                World::push(&world, offer(B));
                World::push(&world, candidate(B));
                wait(4_000).await;

                World::push(
                    &world,
                    Signal::SessionEnded {
                        from: SHARER.into(),
                    },
                );
            }
        })
        .await;

        assert_eq!(outcome, CompanionEnd::SessionEnded);

        let world = world.lock().unwrap();
        assert!(
            world.peers.is_empty(),
            "policy refuses control, so there is nobody to connect to"
        );
        assert!(world.sent.iter().all(|(_, message)| !matches!(
            message,
            Outbound::Answer { .. } | Outbound::Offer { .. }
        )));
    }

    /// A requester's offer is answered with a control-only peer, the monitor
    /// is matched and reported, and the layout goes out on the channel.
    #[tokio::test(start_paused = true)]
    async fn a_requesters_offer_is_answered_and_the_layout_follows() {
        let world = world(view(&[B], None, None), vec![display(1, 0)]);
        let agent = agent();

        let outcome = run_with(&world, &agent, {
            let world = Arc::clone(&world);
            async move {
                wait(500).await;
                World::push(&world, offer(B));
                wait(500).await;
                World::push(
                    &world,
                    Signal::SessionEnded {
                        from: SHARER.into(),
                    },
                );
            }
        })
        .await;

        assert_eq!(outcome, CompanionEnd::SessionEnded);
        assert_eq!(answers_to(&world, B), 1);

        let world = world.lock().unwrap();
        assert_eq!(world.peers.len(), 1);
        assert_eq!(world.reported, vec![(1, MonitorMatch::AutoSingle)]);

        let sent = &world.peers[0].sent;
        assert!(matches!(
            sent.first(),
            Some(ControlMessage::MonitorLayout(layout)) if layout.active_monitor_id == 1
        ));
        // On the way out, the controller is told why.
        assert!(matches!(
            sent.last(),
            Some(ControlMessage::ControlEnded {
                reason: ControlEndReason::SessionEnded
            })
        ));
        assert!(world.peers[0].closed);
        assert!(!world.left, "the session ended; there is nothing to leave");
    }

    /// The person controlling leaving closes their peer and nothing else; the
    /// sharer leaving ends the companion.
    #[tokio::test(start_paused = true)]
    async fn the_controller_leaving_does_not_end_the_companion_but_the_sharer_leaving_does() {
        let world = world(view(&[], Some(B), Some("grant-1")), vec![display(1, 0)]);
        let agent = agent();

        let outcome = run_with(&world, &agent, {
            let world = Arc::clone(&world);
            async move {
                wait(500).await;
                World::push(&world, offer(B));
                wait(500).await;
                assert_eq!(world.lock().unwrap().peers.len(), 1);

                World::push(&world, Signal::PeerLeft { from: B.into() });
                world.lock().unwrap().view = view(&[], None, None);
                wait(500).await;
                assert!(world.lock().unwrap().peers[0].closed, "B's peer is closed");

                // Still running: it keeps reading the API.
                let polls = world.lock().unwrap().polls;
                wait(7_000).await;
                assert!(
                    world.lock().unwrap().polls > polls,
                    "B leaving ended nothing"
                );

                World::push(
                    &world,
                    Signal::PeerLeft {
                        from: SHARER.into(),
                    },
                );
            }
        })
        .await;

        assert_eq!(outcome, CompanionEnd::SharerLeft);
        assert!(agent.state().active_companion().is_none());
    }

    /// Make before break: about 30 s before the token expires the companion
    /// joins again and opens a new socket — and the peer, which carries the
    /// control channel, is the same one before and after.
    #[tokio::test(start_paused = true)]
    async fn the_peer_survives_a_signalling_renewal() {
        let world = world(view(&[], Some(B), Some("grant-1")), vec![display(1, 0)]);
        world.lock().unwrap().token_lifetime_secs = 40;
        let agent = agent();

        let outcome = run_with(&world, &agent, {
            let world = Arc::clone(&world);
            async move {
                wait(500).await;
                World::push(&world, offer(B));
                wait(500).await;

                // Renewal is due ten seconds in.
                wait(12_000).await;
                {
                    let world = world.lock().unwrap();
                    assert_eq!(world.inboxes.len(), 2, "a second socket was opened");
                    assert!(world.joins >= 2, "by joining again");
                    assert_eq!(world.closed_sockets, vec![0], "and the first one closed");
                    assert!(!world.peers[0].closed, "the peer was not touched");
                }

                // The new socket carries the same peer's candidates.
                World::push(&world, candidate(B));
                wait(200).await;
                assert_eq!(world.lock().unwrap().peers[0].candidates, 1);

                World::push(
                    &world,
                    Signal::SessionEnded {
                        from: SHARER.into(),
                    },
                );
            }
        })
        .await;

        assert_eq!(outcome, CompanionEnd::SessionEnded);
        assert_eq!(world.lock().unwrap().peers.len(), 1, "one peer throughout");
    }

    /// The relay dropping the socket is not an ending: the companion gets a
    /// new one and the peer carries on.
    #[tokio::test(start_paused = true)]
    async fn a_dropped_socket_reconnects_and_keeps_the_peer() {
        let world = world(view(&[], Some(B), Some("grant-1")), vec![display(1, 0)]);
        let agent = agent();

        let outcome = run_with(&world, &agent, {
            let world = Arc::clone(&world);
            async move {
                wait(500).await;
                World::push(&world, offer(B));
                wait(500).await;

                World::drop_socket(&world);
                wait(500).await;
                assert_eq!(world.lock().unwrap().inboxes.len(), 2);
                assert!(!world.lock().unwrap().peers[0].closed);

                World::push(
                    &world,
                    Signal::SessionEnded {
                        from: SHARER.into(),
                    },
                );
            }
        })
        .await;

        assert_eq!(outcome, CompanionEnd::SessionEnded);
        assert_eq!(world.lock().unwrap().peers.len(), 1);
    }

    /// The API saying the link ended is an ending.
    #[tokio::test(start_paused = true)]
    async fn the_link_ending_in_the_api_ends_the_companion() {
        let world = world(view(&[], None, None), vec![display(1, 0)]);
        let agent = agent();

        let outcome = run_with(&world, &agent, {
            let world = Arc::clone(&world);
            async move {
                wait(1_000).await;
                let mut ended = view(&[], None, None);
                ended.companion.as_mut().unwrap().status = "ENDED".into();
                world.lock().unwrap().view = ended;
                World::push(
                    &world,
                    Signal::ControlHint {
                        from: SHARER.into(),
                    },
                );
            }
        })
        .await;

        assert_eq!(outcome, CompanionEnd::LinkEnded);
    }

    /// One peer at a time: a new controller's offer replaces the old peer.
    #[tokio::test(start_paused = true)]
    async fn a_new_controller_replaces_the_peer() {
        let world = world(view(&[], Some(B), Some("grant-1")), vec![display(1, 0)]);
        let agent = agent();

        let outcome = run_with(&world, &agent, {
            let world = Arc::clone(&world);
            async move {
                wait(500).await;
                World::push(&world, offer(B));
                wait(500).await;

                world.lock().unwrap().view = view(&[], Some(C), Some("grant-2"));
                World::push(
                    &world,
                    Signal::ControlHint {
                        from: SHARER.into(),
                    },
                );
                wait(300).await;
                assert!(
                    world.lock().unwrap().peers[0].closed,
                    "B's peer went with B's control"
                );

                World::push(&world, offer(C));
                wait(300).await;
                World::push(
                    &world,
                    Signal::SessionEnded {
                        from: SHARER.into(),
                    },
                );
            }
        })
        .await;

        assert_eq!(outcome, CompanionEnd::SessionEnded);
        assert_eq!(answers_to(&world, C), 1);
        let world = world.lock().unwrap();
        assert_eq!(world.peers.len(), 2);
    }

    /// Stop at the machine: the controller is told on the channel, at once,
    /// in an envelope.
    #[tokio::test(start_paused = true)]
    async fn a_local_stop_tells_the_controller() {
        let world = world(view(&[], Some(B), Some("grant-1")), vec![display(1, 0)]);
        let agent = agent();

        let outcome = run_with(&world, &agent, {
            let world = Arc::clone(&world);
            let agent = Arc::clone(&agent);
            async move {
                wait(500).await;
                World::push(&world, offer(B));
                wait(500).await;
                assert!(agent.state().is_being_controlled());

                agent.stop_control();
                wait(200).await;
                assert!(world.lock().unwrap().peers[0]
                    .sent
                    .iter()
                    .any(|message| matches!(
                        message,
                        ControlMessage::ControlEnded {
                            reason: ControlEndReason::StoppedLocally
                        }
                    )));

                // The same grant, reported again before the API caught up,
                // does not hand control back.
                wait(3_500).await;
                assert!(!agent.state().is_being_controlled());

                agent.leave_companion();
            }
        })
        .await;

        assert_eq!(outcome, CompanionEnd::LeftHere);
        assert!(world.lock().unwrap().left, "leaving here is reported");
    }

    /// Two identical monitors: the person at the machine picks, and the pick
    /// is reported as theirs.
    #[tokio::test(start_paused = true)]
    async fn identical_monitors_are_offered_to_the_person_and_their_pick_is_reported() {
        let world = world(view(&[], None, None), vec![display(1, 0), display(2, 2560)]);
        let agent = agent();

        let outcome = run_with(&world, &agent, {
            let world = Arc::clone(&world);
            let agent = Arc::clone(&agent);
            async move {
                wait(500).await;
                let state = agent.state();
                let companion = state.active_companion().unwrap();
                assert_eq!(companion.phase, CompanionPhase::ChoosingMonitor);
                assert_eq!(companion.monitors.len(), 2);
                assert!(world.lock().unwrap().reported.is_empty());

                agent.choose_companion_monitor("companion-1", 2);
                wait(200).await;
                assert_eq!(
                    world.lock().unwrap().reported,
                    vec![(2, MonitorMatch::User)]
                );
                assert_eq!(
                    agent.state().active_companion().unwrap().phase,
                    CompanionPhase::Ready
                );

                // Settled: later reads do not ask again.
                wait(7_000).await;
                assert_eq!(world.lock().unwrap().reported.len(), 1);

                // The share changes size: matched again.
                world.lock().unwrap().view.shared_surface = Some(SharedSurface {
                    display_surface: remote_core::SurfaceKind::Monitor,
                    width: Some(1920),
                    height: Some(1200),
                    verified: true,
                });
                wait(3_500).await;
                assert_eq!(
                    agent.state().active_companion().unwrap().phase,
                    CompanionPhase::ChoosingMonitor,
                    "no 16:10 monitor: asked again"
                );

                agent.leave_companion();
            }
        })
        .await;

        assert_eq!(outcome, CompanionEnd::LeftHere);
    }

    /// Without Accessibility macOS drops every event silently, so the
    /// companion does not report itself ready until it is granted — and does
    /// as soon as it is.
    #[tokio::test(start_paused = true)]
    async fn the_companion_is_not_ready_until_input_can_be_delivered() {
        let world = world(view(&[], None, None), vec![display(1, 0)]);
        world.lock().unwrap().can_inject = false;
        let agent = agent();

        let outcome = run_with(&world, &agent, {
            let world = Arc::clone(&world);
            let agent = Arc::clone(&agent);
            async move {
                wait(7_000).await;
                assert!(world.lock().unwrap().reported.is_empty(), "not ready");
                assert_eq!(
                    agent.state().active_companion().unwrap().phase,
                    CompanionPhase::ChoosingMonitor
                );

                world.lock().unwrap().can_inject = true;
                wait(3_500).await;
                assert_eq!(
                    world.lock().unwrap().reported,
                    vec![(1, MonitorMatch::AutoSingle)]
                );

                agent.leave_companion();
            }
        })
        .await;

        assert_eq!(outcome, CompanionEnd::LeftHere);
    }

    /// The server moves an ACTIVE link back to JOINED when the share stops or
    /// changes; the companion reports its monitor again — and reports nothing
    /// while there is no entire-screen share to match.
    #[tokio::test(start_paused = true)]
    async fn a_link_back_in_joined_reports_its_monitor_again_once_sharing() {
        let world = world(view(&[], None, None), vec![display(1, 0)]);
        let agent = agent();

        let outcome = run_with(&world, &agent, {
            let world = Arc::clone(&world);
            let agent = Arc::clone(&agent);
            async move {
                wait(500).await;
                assert_eq!(world.lock().unwrap().reported.len(), 1);

                // Sharing stopped: JOINED, and no surface. Nothing to report.
                {
                    let mut world = world.lock().unwrap();
                    world.view.companion.as_mut().unwrap().status = "JOINED".into();
                    world.view.shared_surface = None;
                }
                wait(7_000).await;
                assert_eq!(
                    world.lock().unwrap().reported.len(),
                    1,
                    "not while not sharing"
                );

                // Sharing again, the same screen: reported again, the same way.
                world.lock().unwrap().view.shared_surface = view(&[], None, None).shared_surface;
                wait(3_500).await;
                assert_eq!(
                    world.lock().unwrap().reported,
                    vec![(1, MonitorMatch::AutoSingle), (1, MonitorMatch::AutoSingle)]
                );

                // The server has it ACTIVE again: no more reports.
                world
                    .lock()
                    .unwrap()
                    .view
                    .companion
                    .as_mut()
                    .unwrap()
                    .status = "ACTIVE".into();
                wait(7_000).await;
                assert!(world.lock().unwrap().reported.len() <= 3);
                let settled = world.lock().unwrap().reported.len();
                wait(7_000).await;
                assert_eq!(world.lock().unwrap().reported.len(), settled);

                agent.leave_companion();
            }
        })
        .await;

        assert_eq!(outcome, CompanionEnd::LeftHere);
    }

    /// A ping on the control channel is answered with an enveloped pong.
    #[tokio::test(start_paused = true)]
    async fn a_ping_is_answered() {
        let world = world(view(&[], Some(B), Some("grant-1")), vec![display(1, 0)]);
        let agent = agent();

        run_with(&world, &agent, {
            let world = Arc::clone(&world);
            async move {
                wait(500).await;
                World::push(&world, offer(B));
                wait(500).await;

                let ping = ControlEnvelope::new(SESSION, B, 1, ControlMessage::Ping { nonce: 42 })
                    .encode()
                    .unwrap();
                world.lock().unwrap().control_inbox.push(ping);
                wait(200).await;

                World::push(
                    &world,
                    Signal::SessionEnded {
                        from: SHARER.into(),
                    },
                );
            }
        })
        .await;

        assert!(world.lock().unwrap().peers[0]
            .sent
            .contains(&ControlMessage::Pong { nonce: 42 }));
    }

    #[test]
    fn the_companion_join_response_is_read_with_its_link() {
        let joined = read_companion_join(&join_body(120), SESSION).expect("reads");

        assert_eq!(joined.joined.participant_uuid, SELF);
        assert_eq!(joined.session_uuid, SESSION);
        assert_eq!(joined.sharer_uuid.as_deref(), Some(SHARER));
        assert!(joined.joined.signalling_expires_at.is_some());

        // A response for another session is not one to join.
        assert!(read_companion_join(&join_body(120), "session-2").is_err());

        // No token, no join — refused rather than unwrapped.
        let mut broken = join_body(120);
        broken["signalling"]["token"] = serde_json::Value::Null;
        assert!(read_companion_join(&broken, SESSION).is_err());
    }

    // --------------------------------------------------- the local override

    /// Somebody at the machine moves the mouse: pause. 1.5 s of their
    /// idleness: resume.
    #[test]
    fn local_input_pauses_and_idleness_resumes() {
        let mut overrides = LocalOverride::default();

        // The agent injected 2 s ago; the machine saw input 0.1 s ago: a person.
        assert_eq!(overrides.observe(true, Some(0.1), Some(2.0)), Some(true));
        assert!(overrides.is_paused());

        // Still active: stays paused, says nothing new.
        assert_eq!(overrides.observe(true, Some(0.2), Some(3.0)), None);
        assert_eq!(overrides.observe(true, Some(1.4), Some(4.0)), None);

        // 1.5 s idle: resume.
        assert_eq!(overrides.observe(true, Some(1.5), Some(5.0)), Some(false));
        assert!(!overrides.is_paused());
    }

    /// The agent's own injected input is not the person at the machine.
    #[test]
    fn the_agents_own_input_is_not_mistaken_for_a_person() {
        let mut overrides = LocalOverride::default();

        // The machine's last input is the agent's own, injected just now.
        assert_eq!(overrides.observe(true, Some(0.02), Some(0.02)), None);
        assert_eq!(overrides.observe(true, Some(0.05), Some(0.1)), None);
        // Old local input is not "now".
        assert_eq!(overrides.observe(true, Some(30.0), None), None);
        // Nothing injected yet and fresh input: a person.
        assert_eq!(overrides.observe(true, Some(0.05), None), Some(true));
    }

    /// No control, nothing to pause; a platform that cannot say leaves the
    /// override off.
    #[test]
    fn without_control_or_a_reading_nothing_is_paused() {
        let mut overrides = LocalOverride::default();

        assert_eq!(overrides.observe(false, Some(0.0), None), None);
        assert_eq!(overrides.observe(true, None, Some(5.0)), None);
        assert_eq!(overrides.observe(true, Some(f64::NAN), None), None);

        // A pause in progress is forgotten when control goes.
        assert_eq!(overrides.observe(true, Some(0.0), Some(1.0)), Some(true));
        assert_eq!(overrides.observe(false, Some(0.0), Some(1.0)), None);
        assert!(!overrides.is_paused());
    }

    #[test]
    fn every_ending_tells_the_controller_why_and_leaves_only_when_needed() {
        assert_eq!(
            CompanionEnd::SharerLeft.wire_reason(),
            ControlEndReason::SessionEnded
        );
        assert_eq!(
            CompanionEnd::LeftHere.wire_reason(),
            ControlEndReason::StoppedLocally
        );
        assert_eq!(
            CompanionEnd::Stopped.wire_reason(),
            ControlEndReason::ShuttingDown
        );

        assert!(CompanionEnd::LeftHere.needs_leave());
        assert!(CompanionEnd::Stopped.needs_leave());
        assert!(!CompanionEnd::LinkEnded.needs_leave());
        assert!(!CompanionEnd::SharerLeft.needs_leave());
        assert!(!CompanionEnd::SessionEnded.needs_leave());
    }
}
