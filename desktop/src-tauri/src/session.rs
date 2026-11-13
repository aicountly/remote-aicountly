//! One session, from joining it to ending it.
//!
//! The connection loop in `runtime.rs` keeps the machine reachable. This is
//! what runs once it has been asked to host something: it joins through the
//! API, opens the **same** signalling socket the browser uses, negotiates a
//! peer connection, and pumps the control channel through the gate.
//!
//! ```text
//!   POST /devices/me/sessions/{uuid}/join   ─▶  a two-minute room token
//!   wss://…/signal?token=…                  ─▶  joined / peer-joined
//!   offer ⇄ answer ⇄ ice                        peer to peer from here on
//!   ═══ aicountly-remote-control ═══════════▶   Agent::handle_control
//!   GET  /devices/me/sessions/{uuid}/control ─▶  who is asking, what is allowed
//! ```
//!
//! # Three rules this file exists to keep
//!
//! **Every control message goes through the gate.** `Agent::handle_control` is
//! the only thing called with bytes from the peer, and it runs
//! [`remote_protocol::ControlGate::admit`] before anything reaches the input
//! providers. There is no other path, and adding one would mean editing this
//! file and the agent together.
//!
//! **A consent dialog is only ever raised by the API.** Who is waiting for
//! control is polled from `GET /devices/me/sessions/{uuid}/control`, never
//! taken from a data-channel message — so a peer cannot put a dialog in front
//! of somebody by sending one, and cannot keep raising them.
//!
//! **The session is visible for as long as it exists.** `Agent::begin_session`
//! is called before the socket is opened and `Agent::end_session` in every exit
//! path, and the indicator is derived from that state rather than set beside
//! it.
//!
//! # The picture
//!
//! Capture starts once the peer connection is up, never before, and stops on
//! every exit from [`host`]. [`CapturePump`] takes a frame from the platform at
//! the profile's rate and hands it to `PeerSession::send_frame`, which encodes
//! it; under congestion the profile steps down and back up towards the
//! configured ceiling, never above it. The display shown is the primary one,
//! and input lands on the same display because the agent maps it onto the
//! capture's active monitor. The controller is told which display that is with
//! a `monitor_layout` message as soon as the control channel opens.

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::time::{Duration, Instant};

use remote_core::{
    ApiClient, ApiError, ControlStateView, Outbound, SessionSummary, Signal, SignallingError,
    SignallingSocket,
};
use remote_device::frame::{CaptureProfile, CaptureQuality};
use remote_protocol::ControlState;
use remote_webrtc::{IceConfiguration, PeerSession, PeerSessionFactory, PeerState};

use aicountly_remote_service::SecureDesktopMode;

use crate::runtime::StateSink;
use crate::Agent;

/// How often local ICE candidates are trickled and the control channel drained.
///
/// Fast, because both are latency: a candidate held back is a connection that
/// takes longer to establish, and a control message held back is a mouse that
/// lags.
pub(crate) const PUMP_INTERVAL: Duration = Duration::from_millis(50);

/// How often the API is asked who is waiting for control.
///
/// Slower, because somebody is reading a dialog rather than moving a mouse.
pub(crate) const CONTROL_POLL: Duration = Duration::from_secs(3);

/// How long to wait for a peer before giving up on the session.
///
/// A session nobody joins is a session somebody started and abandoned, and an
/// agent that waited for ever would keep an indicator on somebody's screen for
/// a connection that is not coming.
const PEER_TIMEOUT: Duration = Duration::from_secs(180);

/// How long a help session started at this machine waits for its helper.
///
/// Longer than an unattended connection's, because a person reads the code
/// out over the phone and the helper types it and is admitted before anybody
/// joins — but still bounded, for the same reason.
const HELP_PEER_TIMEOUT: Duration = Duration::from_secs(15 * 60);

/// How long the session waits for somebody the relay said has left.
///
/// The relay announces a departure whenever a participant's socket closes —
/// which is also what a browser's two-minute room token expiring looks like,
/// and what a page reload and a dropped Wi-Fi connection look like. Every one
/// of those comes straight back. Ending the session on the announcement, as
/// this used to, ended every hosted session about two minutes in.
const REJOIN_GRACE: Duration = Duration::from_secs(30);

/// How often the last picture may be sent again for a viewer waiting on a
/// keyframe while the screen is still.
const REFRESH_INTERVAL: Duration = Duration::from_millis(400);

/// How long to wait before trying to start a capture the platform refused
/// (Screen Recording not yet allowed on a Mac, a display being reconfigured).
const CAPTURE_RETRY: Duration = Duration::from_secs(3);

/// How often the link is read to step the capture down or back up.
const CONGESTION_INTERVAL: Duration = Duration::from_secs(2);

/// How long before its token expires the signalling connection is replaced with a fresh one. The relay closes a
/// socket when its token expires; replacing it first means the room never sees this computer leave.
const RENEW_BEFORE_EXPIRY: Duration = Duration::from_secs(30);

/// The room token's lifetime when the join response did not state an expiry (`remote.signallingTokenTtl`).
const ASSUMED_TOKEN_LIFETIME: Duration = Duration::from_secs(120);

/// How long to wait before trying a failed renewal again.
pub(crate) const RENEW_RETRY: Duration = Duration::from_secs(5);

/// How long the session keeps trying to get its signalling connection back after the relay dropped it.
pub(crate) const RECONNECT_WINDOW: Duration = Duration::from_secs(30);

/// Why a session ended.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Ended {
    /// The peer or the API said so.
    Normally,
    /// Nobody joined.
    NoPeer,
    /// The connection failed and did not recover.
    Disconnected,
    /// The agent is shutting down.
    Stopped,
    /// Something refused it, with a reason for the interface.
    Refused(String),
}

impl Ended {
    /// The reason string sent to the peer on the control channel.
    #[must_use]
    pub fn wire_reason(&self) -> remote_protocol::ControlEndReason {
        match self {
            Self::Normally => remote_protocol::ControlEndReason::SessionEnded,
            Self::NoPeer | Self::Disconnected => remote_protocol::ControlEndReason::ConnectionLost,
            Self::Stopped => remote_protocol::ControlEndReason::ShuttingDown,
            Self::Refused(_) => remote_protocol::ControlEndReason::RevokedByServer,
        }
    }
}

/// What the API returned when the machine joined.
pub(crate) struct Joined {
    pub(crate) participant_uuid: String,
    pub(crate) display_id: String,
    pub(crate) company_name: Option<String>,
    pub(crate) signalling_url: String,
    pub(crate) signalling_token: String,
    /// When the room token stops being accepted. `None` when the response did not say, in which case the
    /// connection is renewed on the server's documented two-minute lifetime.
    pub(crate) signalling_expires_at: Option<time::OffsetDateTime>,
    pub(crate) ice: IceConfiguration,
}

/// Read the join response without trusting its shape.
///
/// Every field is optional in the parse and checked afterwards: this arrived
/// over the network, and a missing `signalling.token` should be a clear
/// refusal rather than a panic in a background task.
pub(crate) fn read_join(body: &serde_json::Value) -> Result<Joined, String> {
    let session = &body["session"];
    let signalling = &body["signalling"];

    let participant_uuid = body["participant"]["uuid"]
        .as_str()
        .ok_or("the join response named no participant")?
        .to_owned();

    let token = signalling["token"]
        .as_str()
        .ok_or("the join response carried no signalling token")?
        .to_owned();

    let url = signalling["url"]
        .as_str()
        .ok_or("the join response carried no signalling address")?
        .to_owned();

    Ok(Joined {
        participant_uuid,
        display_id: session["displayId"].as_str().unwrap_or("").to_owned(),
        company_name: session["companyName"].as_str().map(str::to_owned),
        signalling_url: url,
        signalling_token: token,
        signalling_expires_at: signalling["expiresAt"].as_str().and_then(|at| {
            time::OffsetDateTime::parse(at, &time::format_description::well_known::Rfc3339).ok()
        }),
        ice: IceConfiguration {
            ice_servers: body["iceServers"].as_array().cloned().unwrap_or_default(),
            relay_available: body["relayAvailable"].as_bool().unwrap_or(false),
        },
    })
}

/// Host one session until it ends.
///
/// `unattended` only affects what the indicator says. It changes nothing about
/// what is permitted — that was decided by the API before this was called, and
/// is re-checked by the gate on every message.
pub async fn host<F>(
    agent: Arc<Agent>,
    sink: StateSink,
    factory: F,
    session_uuid: String,
    unattended: bool,
    stopping: Arc<AtomicBool>,
) -> Ended
where
    F: PeerSessionFactory,
{
    let Some(client) = agent.device_client() else {
        return Ended::Refused("This computer is not signed in to AICOUNTLY.".into());
    };

    let joined = match client.join_session(&session_uuid).await {
        Ok(body) => match read_join(&body) {
            Ok(joined) => joined,
            Err(reason) => return Ended::Refused(reason.to_owned()),
        },
        Err(ApiError::Refused { message, .. }) => return Ended::Refused(message),
        Err(error) => return Ended::Refused(error.to_string()),
    };

    // Visible before anything is opened. There is no window in which a session
    // exists and the indicator does not.
    sink(agent.begin_session(SessionSummary {
        session_uuid: session_uuid.clone(),
        display_id: joined.display_id.clone(),
        connected_name: String::new(),
        company_name: joined.company_name.clone(),
        started_at: now_iso(),
        unattended,
        control: remote_core::ControlSummary {
            state: ControlStateView::None,
            clipboard: false,
            requester_uuid: None,
            requester_name: None,
        },
    }));

    // The service tracks it too, so a restart request can be refused for a
    // session it was never told about. A service that is not running is not a
    // reason to refuse the session — it is a reason a restart will be refused.
    if let Err(error) = crate::ipc::session_started(&session_uuid) {
        tracing::debug!(%error, "the background service was not told the session started");
    }

    let ended = run(
        &agent,
        &sink,
        &client,
        factory,
        &joined,
        &session_uuid,
        &stopping,
        if unattended {
            PEER_TIMEOUT
        } else {
            HELP_PEER_TIMEOUT
        },
        unattended,
    )
    .await;

    // Nothing is captured for a session that has ended, on any path out.
    agent.stop_capture();

    // And no Secure Desktop helper outlives it. Off the async loop, because
    // telling the service is a blocking pipe round trip; the handle is dropped
    // on purpose — the blocking task detaches and runs to completion.
    {
        let agent = Arc::clone(&agent);
        let session = session_uuid.clone();
        drop(tokio::task::spawn_blocking(move || {
            agent.stop_secure_desktop(&session)
        }));
    }

    let _ = crate::ipc::session_ended(&session_uuid);

    // Ended in the window already, in which case the state says so and there
    // is nothing to release twice.
    if agent.is_hosting(&session_uuid) {
        sink(agent.end_session());
    } else {
        sink(agent.state());
    }

    ended
}

#[allow(clippy::too_many_arguments)]
async fn run<F>(
    agent: &Arc<Agent>,
    sink: &StateSink,
    client: &ApiClient,
    factory: F,
    joined: &Joined,
    session_uuid: &str,
    stopping: &Arc<AtomicBool>,
    peer_timeout: Duration,
    unattended: bool,
) -> Ended
where
    F: PeerSessionFactory,
{
    // The configured ceiling. It comes down from here under congestion and
    // never goes above it.
    let profile = CaptureProfile::for_quality(quality_from(&agent.config().capture_quality));
    let mut pump = CapturePump::new(profile);

    let peer = match new_peer(&factory, agent, &joined.ice, profile).await {
        Ok(peer) => peer,
        Err(error) => return Ended::Refused(error.to_string()),
    };

    let mut link = Link {
        peer,
        remote: None,
        epoch: None,
        negotiated: false,
        left_at: None,
    };

    // This run's epoch: the same on every renewal of the socket, and a new
    // one if the session is ever hosted again — which is how a browser tells
    // "it renewed its token" from "it started again" (`connect_url_with_epoch`).
    let epoch = uuid::Uuid::new_v4().simple().to_string();

    let mut socket = match SignallingSocket::connect_as(
        &joined.signalling_url,
        &joined.signalling_token,
        Some(&epoch),
    )
    .await
    {
        Ok(socket) => socket,
        Err(SignallingError::Address(reason)) => return Ended::Refused(reason),
        Err(error) => {
            tracing::warn!(%error, "the signalling connection failed");

            return Ended::Disconnected;
        }
    };

    let mut last_control_poll = Instant::now() - CONTROL_POLL;
    // The read of the control state that is under way, if one is. Never
    // awaited in line: a slow answer from the API used to stop the picture
    // and the input for as long as it took, every three seconds.
    let mut control_read: Option<
        tokio::task::JoinHandle<Result<remote_core::SessionControlView, ApiError>>,
    > = None;
    let joined_at = Instant::now();
    let mut renew_after = renew_at(joined.signalling_expires_at);
    // Whether a Secure Desktop helper has been asked for, so it is started once
    // control is granted with consent and stopped the moment control stops.
    let mut helper_requested = false;

    tracing::info!(
        session = %session_uuid,
        participant = %joined.participant_uuid,
        "this computer joined the session"
    );

    loop {
        if stopping.load(Ordering::SeqCst) {
            announce_end(&mut link.peer, joined, session_uuid, Ended::Stopped).await;
            socket.close().await;

            return Ended::Stopped;
        }

        // Ended by the person at the machine, in the window.
        if !agent.is_hosting(session_uuid) {
            announce_end(&mut link.peer, joined, session_uuid, Ended::Normally).await;
            socket.close().await;

            return Ended::Normally;
        }

        // --- signalling ---------------------------------------------------
        //
        // Waited on for no longer than the next frame is due, so the picture
        // keeps its frame rate when nothing is being signalled.
        match tokio::time::timeout(pump.wait(Instant::now()), socket.next()).await {
            Ok(Ok(Some(signal))) => {
                match handle_signal(
                    agent,
                    sink,
                    &factory,
                    &mut link,
                    &mut pump,
                    &mut socket,
                    signal,
                    joined,
                )
                .await
                {
                    Continue::Carry => {}
                    // Somebody changed control in the API: read it now
                    // rather than at the next poll.
                    Continue::PollNow => last_control_poll = Instant::now() - CONTROL_POLL,
                    Continue::Stop(reason) => {
                        announce_end(&mut link.peer, joined, session_uuid, reason.clone()).await;
                        socket.close().await;

                        return reason;
                    }
                }
            }
            // The relay closed it (an expired token, a restart of the signalling service) or the network dropped
            // it. Neither ends the session: the peer connection carries the picture and the control channel on its
            // own, so get a fresh token and a new socket, and end only if the API says the session is over or the
            // connection cannot be had back within the window.
            Ok(Ok(None)) | Ok(Err(_)) => {
                tracing::info!("the signalling connection closed; reconnecting");

                match reconnect_signalling(client, session_uuid, &epoch, stopping).await {
                    Renewal::Fresh(fresh, expires_at) => {
                        socket = *fresh;
                        renew_after = renew_at(expires_at);

                        continue;
                    }
                    Renewal::Over(reason) => {
                        announce_end(&mut link.peer, joined, session_uuid, reason.clone()).await;

                        return reason;
                    }
                    Renewal::Retry => {
                        announce_end(&mut link.peer, joined, session_uuid, Ended::Disconnected)
                            .await;

                        return Ended::Disconnected;
                    }
                }
            }
            // Nothing arrived within the pump interval, which is the ordinary
            // case: fall through and do the local work.
            Err(_) => {}
        }

        // --- replace the signalling connection before its token expires ---
        //
        // Make before break: the new socket joins the room as the same participant, the relay closes the old one as
        // replaced, and nobody in the room sees this computer leave.
        if Instant::now() >= renew_after {
            match renew_signalling(client, session_uuid, &epoch).await {
                Renewal::Fresh(fresh, expires_at) => {
                    let old = std::mem::replace(&mut socket, *fresh);
                    old.close().await;
                    renew_after = renew_at(expires_at);
                    tracing::debug!("the signalling connection was renewed");
                }
                Renewal::Over(reason) => {
                    announce_end(&mut link.peer, joined, session_uuid, reason.clone()).await;
                    socket.close().await;

                    return reason;
                }
                Renewal::Retry => renew_after = Instant::now() + RENEW_RETRY,
            }
        }

        // --- trickle our own candidates -----------------------------------
        //
        // Only once there is somebody to send them to. A candidate sent into an
        // empty room is one the relay answers `peer-unavailable` to and the
        // peer never sees.
        if let Some(target) = link.remote.clone() {
            for candidate in link.peer.take_local_candidates() {
                let _ = socket
                    .send(&Outbound::IceCandidate {
                        to: target.clone(),
                        payload: candidate,
                    })
                    .await;
            }
        }

        // --- the control channel ------------------------------------------
        //
        // Every message goes through the gate. Input is handed to
        // `Agent::handle_control` by the connection's own reader the moment
        // it arrives (`new_peer` installs the sink), so a click does not wait
        // for this loop to come round past a frame being encoded. What is
        // read here is whatever a session without such a reader holds; with
        // one, it is empty.
        match link.peer.receive_control().await {
            Ok(messages) => {
                for bytes in messages {
                    if let Err(error) = agent.handle_control(&bytes) {
                        tracing::debug!(?error, "a control message was refused");
                    }
                }
            }
            // Not `debug`: a control channel that cannot be read is why input stops, and
            // the shipped log level is `info`, so this used to be written nowhere.
            Err(error) => tracing::warn!(%error, "the control channel could not be read"),
        }

        // Stop control was pressed here: tell the controller on the control
        // channel too, so their browser stops sending at once and says why.
        if agent.take_stop_notice() {
            if let Some(bytes) = control_ended_bytes_for(
                session_uuid,
                &joined.participant_uuid,
                remote_protocol::ControlEndReason::StoppedLocally,
            ) {
                let _ = link.peer.send_control(&bytes).await;
            }
        }

        // The API has just been told what the person here decided. Nudge the
        // room, so the browser waiting on that answer reads it now.
        if agent.take_control_hint() {
            let _ = socket.send(&Outbound::ControlHint).await;
            last_control_poll = Instant::now() - CONTROL_POLL;
        }

        // --- who is asking, and what is permitted -------------------------
        if control_read.is_none() && last_control_poll.elapsed() >= CONTROL_POLL {
            last_control_poll = Instant::now();

            if let Some(reader) = agent.device_client() {
                let session = session_uuid.to_owned();

                control_read = Some(tokio::spawn(async move {
                    reader.session_control(&session).await
                }));
            }
        }

        if control_read.as_ref().is_some_and(|read| read.is_finished()) {
            let outcome = match control_read.take() {
                Some(read) => read.await.ok(),
                None => None,
            };

            match outcome {
                Some(Ok(view)) => adopt_control(agent, sink, &view),
                Some(Err(error)) if error.is_device_rejected() => {
                    announce_end(
                        &mut link.peer,
                        joined,
                        session_uuid,
                        Ended::Refused("removed".into()),
                    )
                    .await;

                    return Ended::Refused("This computer was removed from AICOUNTLY.".into());
                }
                Some(Err(error)) => {
                    tracing::debug!(%error, "the control state could not be read");
                }
                None => {}
            }
        }

        // --- the Secure Desktop helper ------------------------------------
        //
        // Wanted while control is granted and the Secure Desktop may be
        // answered — the person ticked the box (attended) or the device
        // setting allows it (unattended). Started off the loop because asking
        // the service and connecting the pipe both block; stopped at once when
        // control stops, which the stop shortcut, a revoke and a denied grant
        // all reach by leaving the gate's state no longer Granted.
        let want_helper = agent.control_state() == ControlState::Granted
            && if unattended {
                agent.config().allow_secure_desktop_unattended
            } else {
                agent.secure_desktop_consent()
            };

        if want_helper && !helper_requested {
            helper_requested = true;

            let agent = Arc::clone(agent);
            let session = session_uuid.to_owned();
            let mode = if unattended {
                SecureDesktopMode::Unattended
            } else {
                SecureDesktopMode::Attended
            };

            tokio::task::spawn_blocking(move || agent.start_secure_desktop(&session, mode));
        } else if !want_helper && helper_requested {
            helper_requested = false;

            let agent = Arc::clone(agent);
            let session = session_uuid.to_owned();
            tokio::task::spawn_blocking(move || agent.stop_secure_desktop(&session));
        }

        // --- the picture -------------------------------------------------
        pump.turn(
            agent,
            &mut link.peer,
            session_uuid,
            &joined.participant_uuid,
        )
        .await;

        // --- give up on a session nobody joins ----------------------------
        if link.remote.is_none() && joined_at.elapsed() >= peer_timeout {
            socket.close().await;

            return Ended::NoPeer;
        }

        // --- somebody who left and did not come back ----------------------
        //
        // Only once the connection itself has gone as well: a browser whose
        // signalling dropped while its peer connection is still up is still
        // watching.
        let awaited = link
            .left_at
            .is_some_and(|left| left.elapsed() < REJOIN_GRACE);

        if link.left_at.is_some() && !awaited && link.peer.state() != PeerState::Connected {
            announce_end(&mut link.peer, joined, session_uuid, Ended::Normally).await;
            socket.close().await;

            return Ended::Normally;
        }

        // A page that is reloading takes its connection down with it; while
        // it is being waited for, that is not the end either.
        if link.peer.state() == PeerState::Failed && !awaited {
            // The other end went without the relay saying so (a closed lid, a
            // lost network). If they have already been announced as gone this
            // is simply the session ending; otherwise the connection was lost.
            let ended = if link.left_at.is_some() {
                Ended::Normally
            } else {
                Ended::Disconnected
            };

            announce_end(&mut link.peer, joined, session_uuid, ended.clone()).await;
            socket.close().await;

            return ended;
        }
    }
}

/// The connection to whoever is watching, and what is known about them.
struct Link<P> {
    peer: P,
    /// Who the connection is with, once somebody has been in the room.
    remote: Option<String>,
    /// Which page of theirs it is with — the relay's epoch for them.
    epoch: Option<String>,
    /// Whether an offer or an answer has been exchanged on `peer`. A
    /// connection that has negotiated with one page cannot be offered to
    /// another, so a new page gets a new one.
    negotiated: bool,
    /// When the relay said they left, until they come back.
    left_at: Option<Instant>,
}

/// A peer connection for a hosted session, with its control input going
/// straight to the gate.
async fn new_peer<F: PeerSessionFactory>(
    factory: &F,
    agent: &Arc<Agent>,
    ice: &IceConfiguration,
    profile: CaptureProfile,
) -> Result<F::Session, remote_webrtc::WebRtcError> {
    let mut peer = factory.create(ice.clone(), profile).await?;

    // A new connection is a new stream of control messages, numbered from
    // the start again.
    agent.restart_control_stream();

    let agent = Arc::clone(agent);
    peer.set_control_sink(Arc::new(move |bytes: Vec<u8>| {
        // The only thing ever called with bytes from the peer, and it runs
        // the gate before anything reaches the input providers.
        if let Err(error) = agent.handle_control(&bytes) {
            tracing::debug!(?error, "a control message was refused");
        }
    }));

    Ok(peer)
}

/// Close the connection and start a fresh one, for a page that is not the
/// one the old connection was negotiated with. `false` when no new
/// connection could be made, in which case the old one is gone too and the
/// next arrival tries again.
async fn replace_peer<F: PeerSessionFactory>(
    factory: &F,
    agent: &Arc<Agent>,
    link: &mut Link<F::Session>,
    pump: &mut CapturePump,
    joined: &Joined,
) -> bool {
    let _ = link.peer.close().await;

    // Nothing is captured for a connection that has gone, and whatever its
    // controller was holding is let go.
    agent.stop_capture();
    agent.release_input();
    pump.restart(Instant::now());

    match new_peer(factory, agent, &joined.ice, pump.ceiling).await {
        Ok(peer) => {
            link.peer = peer;
            link.negotiated = false;

            true
        }
        Err(error) => {
            tracing::warn!(%error, "a new connection could not be created");

            false
        }
    }
}

/// What to do about somebody arriving in the room.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Arrival {
    /// The page we are already connected to, back after its signalling
    /// dropped or its token was renewed. The connection never went away.
    Returned,
    /// Offer on the connection we hold: it was still being set up with this
    /// same page.
    Reoffer,
    /// Offer on a connection of its own: a new page, or a new person.
    Offer,
    /// Somebody else, while the session is connected to one person already.
    Busy,
}

/// Decide what an arrival means.
///
/// `remote` and `epoch` are who the session is connected to and which page of
/// theirs; `left` is whether the relay has said they left; `state` is the
/// connection's own state.
fn arrival(
    remote: Option<&str>,
    epoch: Option<&str>,
    left: bool,
    state: PeerState,
    arrived: &str,
    arrived_epoch: Option<&str>,
) -> Arrival {
    let Some(current) = remote else {
        return Arrival::Offer;
    };

    if current != arrived {
        // One at a time. Somebody new is taken only when the person the
        // session was with has gone.
        let live = matches!(state, PeerState::Connected | PeerState::Connecting);

        return if live && !left {
            Arrival::Busy
        } else {
            Arrival::Offer
        };
    }

    // The same person. The same page too, if the relay repeats its epoch.
    let same_page = matches!((epoch, arrived_epoch), (Some(known), Some(now)) if known == now);

    match state {
        // The connection is up. Unless this is plainly a different page, it
        // is the one we are already talking to.
        PeerState::Connected if same_page || arrived_epoch.is_none() || epoch.is_none() => {
            Arrival::Returned
        }
        PeerState::New | PeerState::Connecting if same_page => Arrival::Reoffer,
        _ => Arrival::Offer,
    }
}

/// The picture a hosted session sends.
///
/// Capture starts when the peer connection reports `Connected` and not
/// before, so nothing is captured for a session nobody has joined. A refused
/// start (Screen Recording not allowed on a Mac) is tried again every few
/// seconds rather than on every turn, and logged once.
struct CapturePump {
    /// The configured quality: the profile never steps above it.
    ceiling: CaptureProfile,
    /// What the capture is running at now.
    profile: CaptureProfile,
    /// The display the controller asked for, kept so a capture that is
    /// started again shows the same one.
    monitor: Option<u32>,
    /// When the last picture may next be sent again for a waiting viewer.
    next_refresh_at: Instant,
    /// The display being shown, once capture has started.
    layout: Option<remote_protocol::MonitorLayout>,
    /// Whether the controller has been told which display that is.
    layout_sent: bool,
    next_frame_at: Instant,
    next_attempt_at: Instant,
    next_congestion_at: Instant,
    refusal_logged: bool,
    /// Whether "the H.264 codec is not installed" has been logged for this
    /// session. Once: every frame says it again.
    codec_missing_logged: bool,
    /// Whether the helper's frames were the source last turn, so a switch to
    /// or from them asks the encoder for a keyframe.
    secure_desktop_was_active: bool,
    /// The last `secure_desktop { active, reachable }` told to the controller,
    /// so the message goes out only when it changes.
    secure_desktop_told: Option<(bool, bool)>,
}

impl CapturePump {
    fn new(profile: CaptureProfile) -> Self {
        let now = Instant::now();

        Self {
            ceiling: profile,
            profile,
            monitor: None,
            next_refresh_at: now,
            layout: None,
            layout_sent: false,
            next_frame_at: now,
            next_attempt_at: now,
            next_congestion_at: now + CONGESTION_INTERVAL,
            refusal_logged: false,
            codec_missing_logged: false,
            secure_desktop_was_active: false,
            secure_desktop_told: None,
        }
    }

    /// Start again from nothing, for a new connection: the capture has been
    /// stopped, and starts when the new connection is up.
    fn restart(&mut self, now: Instant) {
        self.layout = None;
        self.layout_sent = false;
        self.next_attempt_at = now;
        self.next_frame_at = now;
        self.secure_desktop_told = None;
    }

    /// How long the loop may wait on signalling before the next frame is due.
    fn wait(&self, now: Instant) -> Duration {
        if self.layout.is_none() {
            return PUMP_INTERVAL;
        }

        self.next_frame_at
            .saturating_duration_since(now)
            .clamp(Duration::from_millis(1), PUMP_INTERVAL)
    }

    /// Whether to try starting the capture now.
    fn should_start(&self, state: PeerState, now: Instant) -> bool {
        self.layout.is_none() && state == PeerState::Connected && now >= self.next_attempt_at
    }

    /// The profile a congestion reading asks for, never above the ceiling.
    fn adjusted(&self, congestion: &remote_webrtc::Congestion) -> CaptureProfile {
        let next = congestion.adjust(self.profile);

        CaptureProfile {
            max_dimension: next.max_dimension.min(self.ceiling.max_dimension),
            max_fps: next.max_fps.clamp(1, self.ceiling.max_fps.max(1)),
            ..next
        }
    }

    /// One turn of the loop: start, tell the controller, follow the link, send.
    async fn turn<P: PeerSession>(
        &mut self,
        agent: &Arc<Agent>,
        peer: &mut P,
        session_uuid: &str,
        participant_uuid: &str,
    ) {
        let now = Instant::now();

        // The controller asked for another of this computer's displays. The
        // gate admitted the request; here the capture moves, and input moves
        // with it because it lands on the capture's active display.
        if let Some(requested) = agent.take_monitor_request() {
            self.monitor = Some(requested);

            if self.layout.is_some() {
                match agent.start_capture(self.monitor, self.profile) {
                    Ok(layout) => {
                        tracing::info!(
                            monitor = layout.active_monitor_id,
                            "the controller switched to another display"
                        );
                        self.layout = Some(layout);
                        // The controller is told which display it now sees.
                        self.layout_sent = false;
                        self.next_frame_at = now;
                        // A different picture: the decoder starts again.
                        peer.request_keyframe();
                    }
                    Err(error) => {
                        tracing::warn!(%error, "the display could not be switched");
                        // Whatever was being shown has stopped; start again.
                        self.layout = None;
                        self.layout_sent = false;
                        self.next_attempt_at = now;
                    }
                }
            }
        }

        if self.should_start(peer.state(), now) {
            match agent.start_capture(self.monitor, self.profile) {
                Ok(layout) => {
                    tracing::info!(
                        monitor = layout.active_monitor_id,
                        "this computer's screen is being shown"
                    );
                    self.layout = Some(layout);
                    self.next_frame_at = now;

                    // The viewer can draw nothing until a keyframe arrives.
                    peer.request_keyframe();
                }
                Err(error) => {
                    self.next_attempt_at = now + CAPTURE_RETRY;

                    if !self.refusal_logged {
                        self.refusal_logged = true;
                        tracing::warn!(%error, "this computer's screen could not be captured");
                    }
                }
            }
        }

        let Some(layout) = self.layout.clone() else {
            return;
        };

        if !self.layout_sent && peer.control_channel_open().await {
            if let Some(bytes) = layout_bytes(session_uuid, participant_uuid, layout) {
                if peer.send_control(&bytes).await.is_ok() {
                    self.layout_sent = true;
                }
            }
        }

        if now >= self.next_congestion_at {
            self.next_congestion_at = now + CONGESTION_INTERVAL;

            if let Some(congestion) = peer.congestion().await {
                let next = self.adjusted(&congestion);

                if next != self.profile {
                    match agent.reconfigure_capture(next) {
                        Ok(()) => {
                            // The encoder follows the capture: its size limit,
                            // frame rate and bitrate come from the same profile.
                            peer.set_capture_profile(next);
                            self.profile = next;
                        }
                        Err(error) => tracing::debug!(%error, "the capture was not reconfigured"),
                    }
                }
            }
        }

        // Tell the controller what the Secure Desktop is doing, and switch the
        // picture to the helper while one is forwarding it. `active` is true
        // whenever a prompt is in front — from the helper when one is
        // connected, otherwise from the app's own detection — and `reachable`
        // says whether it can be answered from the browser.
        self.follow_secure_desktop(agent, peer, session_uuid, participant_uuid)
            .await;
        let secure_active = agent.secure_desktop_active();

        if now < self.next_frame_at || !peer.state().is_live() {
            return;
        }

        self.next_frame_at = now + Duration::from_millis(self.profile.frame_interval_ms());

        // While the Secure Desktop is reachable, its helper's frames are the
        // picture; otherwise the ordinary capture is, exactly as before.
        let frame = if secure_active {
            match agent.latest_secure_desktop_frame() {
                Some(frame) => Some(frame),
                // No frame yet (the helper has not sent one since the switch):
                // nothing to send this turn, try again next.
                None => return,
            }
        } else {
            match agent.next_frame() {
                Ok(frame) => frame,
                Err(error) => {
                    tracing::debug!(%error, "no frame was captured");

                    return;
                }
            }
        };

        let Some(frame) = frame else {
            // Nothing changed on the screen. A viewer who is waiting for a
            // keyframe — they have just connected, or lost a packet — gets
            // the picture they should be looking at, rather than nothing
            // until something moves.
            if now >= self.next_refresh_at {
                self.next_refresh_at = now + REFRESH_INTERVAL;

                if let Err(error) = peer.refresh_picture().await {
                    tracing::debug!(%error, "the picture was not sent again");
                }
            }

            return;
        };

        match peer.send_frame(frame).await {
            Ok(()) => {}
            // The connection is over: nothing more is captured for it.
            // A new connection in the same session starts it again.
            Err(remote_webrtc::WebRtcError::WrongState(state)) => {
                tracing::info!(?state, "the connection ended; the capture stops");
                agent.stop_capture();
                self.layout = None;
                self.layout_sent = false;
                self.next_attempt_at = now + CAPTURE_RETRY;
            }
            // No codec on this computer: the person helping sees no
            // picture. Said once here; the window says it to the person at the
            // computer, naming the setting, from the codec's own status
            // (`commands::codec`), and the session carries on so the picture
            // starts the moment the codec arrives.
            Err(error) if error.is_codec_unavailable() => {
                if !self.codec_missing_logged {
                    self.codec_missing_logged = true;
                    tracing::warn!(%error, "this computer's screen cannot be sent");
                }
            }
            Err(error) => tracing::debug!(%error, "a frame was not sent"),
        }
    }

    /// Send a `secure_desktop` notice when the state changes, and ask the
    /// encoder for a keyframe when the picture switches between the ordinary
    /// capture and the helper's frames (the two are different sizes and the
    /// decoder cannot start from a mid-stream frame).
    async fn follow_secure_desktop<P: PeerSession>(
        &mut self,
        agent: &Arc<Agent>,
        peer: &mut P,
        session_uuid: &str,
        participant_uuid: &str,
    ) {
        let helper_active = agent.secure_desktop_active();
        let active = helper_active || agent.secure_desktop_detected();
        let reachable = agent.secure_desktop_reachable();

        if self.secure_desktop_was_active != helper_active {
            self.secure_desktop_was_active = helper_active;
            self.next_frame_at = Instant::now();
            peer.request_keyframe();
        }

        if self.secure_desktop_told != Some((active, reachable)) {
            self.secure_desktop_told = Some((active, reachable));

            if let Some(bytes) =
                secure_desktop_bytes(session_uuid, participant_uuid, active, reachable)
            {
                let _ = peer.send_control(&bytes).await;
            }
        }
    }
}

/// `secure_desktop { active, reachable }`, in the same envelope as every other
/// control message.
fn secure_desktop_bytes(
    session_uuid: &str,
    participant_uuid: &str,
    active: bool,
    reachable: bool,
) -> Option<Vec<u8>> {
    let sequence = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|elapsed| elapsed.as_millis() as u64)
        .unwrap_or(1);

    let envelope = remote_protocol::ControlEnvelope::new(
        session_uuid,
        participant_uuid,
        sequence,
        remote_protocol::ControlMessage::SecureDesktop { active, reachable },
    );

    envelope.validate().ok()?;

    serde_json::to_vec(&envelope).ok()
}

/// `monitor_layout`, telling the controller which display it is looking at.
fn layout_bytes(
    session_uuid: &str,
    participant_uuid: &str,
    layout: remote_protocol::MonitorLayout,
) -> Option<Vec<u8>> {
    let sequence = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|elapsed| elapsed.as_millis() as u64)
        .unwrap_or(1);

    let envelope = remote_protocol::ControlEnvelope::new(
        session_uuid,
        participant_uuid,
        sequence,
        remote_protocol::ControlMessage::MonitorLayout(layout),
    );

    envelope.validate().ok()?;

    serde_json::to_vec(&envelope).ok()
}

/// Whether the loop carries on.
enum Continue {
    Carry,
    PollNow,
    Stop(Ended),
}

#[allow(clippy::too_many_arguments)]
async fn handle_signal<F: PeerSessionFactory>(
    agent: &Arc<Agent>,
    sink: &StateSink,
    factory: &F,
    link: &mut Link<F::Session>,
    pump: &mut CapturePump,
    socket: &mut SignallingSocket,
    signal: Signal,
    joined: &Joined,
) -> Continue {
    let self_uuid = joined.participant_uuid.as_str();

    match signal {
        // We are the newcomer — or our own signalling connection was just
        // renewed, which the relay answers the same way. Whoever is already
        // here offers to us, so there is nothing to do but note who to
        // trickle candidates to — and, if the room said who they are, put
        // their name where the session banner and the consent dialog read it
        // from.
        Signal::Joined { peers, .. } => {
            let others: Vec<_> = peers
                .into_iter()
                .filter(|entry| entry.participant_uuid != self_uuid)
                .collect();

            // The person we are already connected to, if they are listed;
            // otherwise whoever is there.
            let identified = others
                .iter()
                .find(|entry| link.remote.as_deref() == Some(entry.participant_uuid.as_str()))
                .or_else(|| others.first())
                .cloned();

            if let Some(entry) = identified {
                if !entry.display_name.is_empty() {
                    sink(agent.peer_connected(&entry.display_name));
                }

                if link.remote.as_deref() == Some(entry.participant_uuid.as_str()) {
                    // Still in the room: they did not leave after all.
                    link.left_at = None;
                } else if link.remote.is_none() {
                    link.remote = Some(entry.participant_uuid);
                    link.epoch = entry.epoch;
                }
            }
        }

        // We were here first, so we offer — unless this is the page we are
        // already connected to coming back.
        Signal::PeerJoined { peer: arrived, .. } => {
            if arrived.participant_uuid == self_uuid {
                return Continue::Carry;
            }

            let decision = arrival(
                link.remote.as_deref(),
                link.epoch.as_deref(),
                link.left_at.is_some(),
                link.peer.state(),
                &arrived.participant_uuid,
                arrived.epoch.as_deref(),
            );

            match decision {
                Arrival::Busy => {
                    tracing::info!(
                        "somebody else joined the session; this computer is already connected \
                         to one person and serves one at a time"
                    );

                    return Continue::Carry;
                }
                Arrival::Returned => {
                    tracing::info!("the person connected is back in the room");
                    link.left_at = None;

                    if arrived.epoch.is_some() {
                        link.epoch = arrived.epoch;
                    }

                    // They may have missed a change of control while away.
                    return Continue::PollNow;
                }
                Arrival::Offer | Arrival::Reoffer => {}
            }

            // A connection that has negotiated with one page cannot be
            // offered to another.
            if decision == Arrival::Offer
                && link.negotiated
                && !replace_peer(factory, agent, link, pump, joined).await
            {
                return Continue::Carry;
            }

            link.remote = Some(arrived.participant_uuid.clone());
            link.epoch = arrived.epoch.clone();
            link.left_at = None;

            if !arrived.display_name.is_empty() {
                sink(agent.peer_connected(&arrived.display_name));
            }

            match link.peer.create_offer().await {
                Ok(offer) => {
                    link.negotiated = true;

                    let _ = socket
                        .send(&Outbound::Offer {
                            to: arrived.participant_uuid,
                            payload: offer,
                        })
                        .await;
                }
                Err(error) => tracing::warn!(%error, "an offer could not be created"),
            }
        }

        Signal::Offer { from, payload } => {
            if from == self_uuid {
                return Continue::Carry;
            }

            let current = link.remote.as_deref() == Some(from.as_str());
            let live = matches!(
                link.peer.state(),
                PeerState::Connected | PeerState::Connecting
            );

            if !current && link.remote.is_some() && live && link.left_at.is_none() {
                tracing::info!(
                    "an offer arrived from somebody else; this computer is already connected \
                     to one person and serves one at a time"
                );

                return Continue::Carry;
            }

            // Somebody new takes a connection of their own.
            if !current
                && link.negotiated
                && !replace_peer(factory, agent, link, pump, joined).await
            {
                return Continue::Carry;
            }

            let mut answer = link.peer.accept_offer(payload.clone()).await;

            // The connection could not take it. While it is up that is a
            // renegotiation gone wrong, and the connection is worth more than
            // the offer. While it is not, the offer is for a connection we do
            // not hold — ours crossed theirs, or their page is new — so it
            // gets a fresh one.
            if answer.is_err()
                && link.negotiated
                && link.peer.state() != PeerState::Connected
                && replace_peer(factory, agent, link, pump, joined).await
            {
                answer = link.peer.accept_offer(payload).await;
            }

            match answer {
                Ok(answer) => {
                    link.negotiated = true;
                    link.remote = Some(from.clone());
                    link.left_at = None;

                    let _ = socket
                        .send(&Outbound::Answer {
                            to: from,
                            payload: answer,
                        })
                        .await;
                }
                Err(error) => tracing::warn!(%error, "an offer could not be answered"),
            }
        }

        Signal::Answer { from, payload } => {
            if link.remote.as_deref() != Some(from.as_str()) {
                return Continue::Carry;
            }

            if let Err(error) = link.peer.accept_answer(payload).await {
                tracing::warn!(%error, "an answer could not be used");
            }
        }

        Signal::IceCandidate { from, payload } => {
            if link.remote.as_deref() == Some(from.as_str()) {
                // A candidate the library cannot use is normal during trickle.
                let _ = link.peer.add_ice_candidate(payload).await;
            }
        }

        // **Not the end of the session.** The relay says this whenever a
        // participant's socket closes: their two-minute room token expired,
        // their page reloaded, their network dropped for a moment. They are
        // waited for (`REJOIN_GRACE`), and whatever they were holding on this
        // machine is let go meanwhile.
        Signal::PeerLeft { from } => {
            if link.remote.as_deref() == Some(from.as_str()) && link.left_at.is_none() {
                tracing::info!("the person connected left the room; waiting for them to come back");
                link.left_at = Some(Instant::now());
                agent.release_input();
            }
        }

        Signal::SessionEnded { .. } => return Continue::Stop(Ended::Normally),

        Signal::Error { code, message } => {
            tracing::warn!(%code, %message, "the relay refused something");
        }

        // A nudge to read the API now. It grants nothing by itself.
        Signal::ControlHint { .. } => return Continue::PollNow,

        // Only ever sent into a device's presence room, not a session's.
        Signal::DeviceInvite { .. } => {}

        Signal::Renegotiate { .. } | Signal::PeerUnavailable { .. } | Signal::Pong => {}

        // A relay that grows a message type must not drop the connection.
        Signal::Unknown => {}
    }

    Continue::Carry
}

/// Adopt the control state the API reports, and raise a dialog when somebody
/// is waiting.
///
/// `sync_control` deliberately cannot un-revoke a locally revoked gate: if the
/// person at the machine pressed Stop, a server that has not caught up does
/// not put control back.
fn adopt_control(agent: &Arc<Agent>, sink: &StateSink, view: &remote_core::SessionControlView) {
    // The organisation's switch, which is exactly what the field is for --
    // "so the dialog cannot offer what would be refused" -- and which nothing
    // was reading. Without this the consent dialog appears for an
    // organisation that has remote control switched off, and a grant reported
    // by the API opens the gate through `sync_from_api` whether anybody was
    // asked or not.
    //
    // Unlike the share modes, an absent `allowRemoteControl` is not a sign of
    // an older server: the API sets it on every control payload and defaults
    // it to false itself when there is no policy to read. False here means
    // the same thing it means to the browser.
    if !view.allow_remote_control {
        if !view.pending_requests.is_empty() || view.controller_uuid.is_some() {
            tracing::warn!(
                waiting = view.pending_requests.len(),
                controller = ?view.controller_uuid,
                "this organisation does not permit remote control; nobody is being asked and no \
                 grant is being adopted"
            );
        }

        // Not merely "do not ask": a grant already in force is dropped, so an
        // administrator switching remote control off ends control at the next
        // read rather than at the end of the session.
        sink(agent.sync_control(ControlState::None, None, false, None));

        return;
    }

    // Somebody is controlling this machine right now, by the gate's own
    // account. Two reads must not take that away:
    //
    // * the one that crosses the Allow just pressed here — the API still
    //   lists the request, because the report of the answer has not landed.
    //   Raising the request again put the gate back to "asking", dropped the
    //   controller's input and showed the dialog a second time;
    // * a second person asking while the first is controlling, which did the
    //   same to the first on every read until they were turned down.
    //
    // So while the gate is granted, a pending request changes nothing unless
    // the API also says the person the gate names is no longer involved.
    if agent.control_state() == ControlState::Granted {
        let controller = agent.controller_uuid();

        let still_theirs = controller.is_some()
            && (view.controller_uuid == controller
                || view
                    .pending_requests
                    .iter()
                    .any(|waiting| Some(&waiting.participant_uuid) == controller.as_ref()));

        if still_theirs && !view.pending_requests.is_empty() {
            return;
        }
    }

    if let Some(waiting) = view.pending_requests.first() {
        // The API said somebody is waiting. That is the only thing that raises
        // a consent dialog on this machine, and their name travels with it —
        // the dialog has no other way to say who it is asking about.
        sink(agent.control_requested(&waiting.participant_uuid, &waiting.display_name));

        return;
    }

    let state = if view.controller_uuid.is_some() {
        ControlState::Granted
    } else {
        ControlState::None
    };

    sink(agent.sync_control(
        state,
        view.controller_uuid.clone(),
        view.clipboard_enabled && view.allow_clipboard_sync,
        view.grant_id.clone(),
    ));
}

/// Tell the peer control has ended, then close.
///
/// Best effort and never waited on for long: the gate has already stopped
/// admitting input, so this is courtesy rather than enforcement.
async fn announce_end<P: PeerSession>(
    peer: &mut P,
    joined: &Joined,
    session_uuid: &str,
    ended: Ended,
) {
    if let Some(bytes) = control_ended_bytes(session_uuid, &joined.participant_uuid, &ended) {
        let _ = peer.send_control(&bytes).await;
    }

    let _ = peer.close().await;
}

/// `control_ended`, in the same envelope as every other control message.
///
/// It used to go out as a bare message, which the browser's `decodeControlEnvelope` drops (no `v`, no `s`), so the
/// person controlling never learned why control stopped. The sequence is wall-clock milliseconds: it only has to be
/// newer than anything this sender sent before, across reconnects too.
fn control_ended_bytes(
    session_uuid: &str,
    participant_uuid: &str,
    ended: &Ended,
) -> Option<Vec<u8>> {
    control_ended_bytes_for(session_uuid, participant_uuid, ended.wire_reason())
}

/// `control_ended` with this reason.
fn control_ended_bytes_for(
    session_uuid: &str,
    participant_uuid: &str,
    reason: remote_protocol::ControlEndReason,
) -> Option<Vec<u8>> {
    let sequence = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|elapsed| elapsed.as_millis() as u64)
        .unwrap_or(1);

    let envelope = remote_protocol::ControlEnvelope::new(
        session_uuid,
        participant_uuid,
        sequence,
        remote_protocol::ControlMessage::ControlEnded { reason },
    );

    serde_json::to_vec(&envelope).ok()
}

/// When to replace the signalling connection: shortly before its token expires.
fn renew_at(expires_at: Option<time::OffsetDateTime>) -> Instant {
    Instant::now() + renew_in(expires_at)
}

/// How long from now to replace the signalling connection.
pub(crate) fn renew_in(expires_at: Option<time::OffsetDateTime>) -> Duration {
    let lifetime = match expires_at {
        Some(at) => {
            let left = at - time::OffsetDateTime::now_utc();
            Duration::try_from(left).unwrap_or(Duration::ZERO)
        }
        None => ASSUMED_TOKEN_LIFETIME,
    };

    // Never sooner than a few seconds from now, so a short or already-past expiry cannot spin the loop.
    lifetime
        .saturating_sub(RENEW_BEFORE_EXPIRY)
        .max(RENEW_RETRY)
}

/// Keep trying [`renew_signalling`] for up to [`RECONNECT_WINDOW`], with a short pause between attempts.
async fn reconnect_signalling(
    client: &ApiClient,
    session_uuid: &str,
    epoch: &str,
    stopping: &Arc<AtomicBool>,
) -> Renewal {
    let started = Instant::now();

    loop {
        if stopping.load(Ordering::SeqCst) {
            return Renewal::Over(Ended::Stopped);
        }

        match renew_signalling(client, session_uuid, epoch).await {
            Renewal::Retry if started.elapsed() < RECONNECT_WINDOW => {
                tokio::time::sleep(Duration::from_secs(2)).await
            }
            outcome => return outcome,
        }
    }
}

/// What became of an attempt to get a fresh signalling connection.
enum Renewal {
    /// A new socket, already in the room, and when its own token expires.
    Fresh(Box<SignallingSocket>, Option<time::OffsetDateTime>),
    /// The session is over: there is nothing to reconnect to.
    Over(Ended),
    /// Try again shortly.
    Retry,
}

/// Join again (the API returns the same participant and a fresh room token) and open a new socket with it.
async fn renew_signalling(client: &ApiClient, session_uuid: &str, epoch: &str) -> Renewal {
    let body = match client.join_session(session_uuid).await {
        Ok(body) => body,
        Err(error) if error.is_device_rejected() => {
            return Renewal::Over(Ended::Refused(
                "This computer was removed from AICOUNTLY.".into(),
            ));
        }
        Err(ApiError::Refused {
            status: 404 | 409 | 410,
            ..
        }) => return Renewal::Over(Ended::Normally),
        Err(error) => {
            tracing::debug!(%error, "the signalling token could not be renewed yet");

            return Renewal::Retry;
        }
    };

    let fresh = match read_join(&body) {
        Ok(fresh) => fresh,
        Err(reason) => {
            tracing::warn!(%reason, "the renewal response could not be read");

            return Renewal::Retry;
        }
    };

    match SignallingSocket::connect_as(&fresh.signalling_url, &fresh.signalling_token, Some(epoch))
        .await
    {
        Ok(socket) => Renewal::Fresh(Box::new(socket), fresh.signalling_expires_at),
        Err(error) => {
            tracing::debug!(%error, "the renewed signalling connection failed");

            Renewal::Retry
        }
    }
}

/// The configured quality name, as a profile.
///
/// An unrecognised name is Adaptive rather than an error: the configuration
/// file is validated when it is written, and an agent that refused to start
/// because somebody typed into a JSON file would be an agent needing a visit
/// to the machine.
fn quality_from(name: &str) -> CaptureQuality {
    match name {
        "low_bandwidth" => CaptureQuality::LowBandwidth,
        "high_quality" => CaptureQuality::HighQuality,
        _ => CaptureQuality::Adaptive,
    }
}

pub(crate) fn now_iso() -> String {
    time::OffsetDateTime::now_utc()
        .format(&time::format_description::well_known::Rfc3339)
        .unwrap_or_default()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn join_body() -> serde_json::Value {
        serde_json::json!({
            "session": { "uuid": "session-1", "displayId": "AR-10282", "companyName": "Northwind" },
            "participant": { "uuid": "device-participant" },
            "signalling": {
                "token": "signal.token.value",
                "url": "wss://remote.aicountly.com/signal",
                "room": "session-1",
                "expiresAt": "2026-02-10T09:02:00Z"
            },
            "iceServers": [{ "urls": ["stun:stun.example.test:3478"] }],
            "relayAvailable": true
        })
    }

    fn control_agent() -> Arc<Agent> {
        let agent = Agent::new();
        agent.apply(remote_core::AgentEvent::Enrolled {
            device_uuid: "device-1".into(),
            device_name: "WS-01".into(),
            company_name: None,
            key_fingerprint: "AAAA".into(),
        });
        agent.apply(remote_core::AgentEvent::Authenticated);
        agent.begin_session(remote_core::SessionSummary {
            session_uuid: "session-1".into(),
            display_id: "AR-10282".into(),
            connected_name: "Sam".into(),
            company_name: None,
            started_at: "2026-02-10T09:00:00Z".into(),
            unattended: false,
            control: remote_core::ControlSummary {
                state: remote_core::ControlStateView::None,
                clipboard: false,
                requester_uuid: None,
                requester_name: None,
            },
        });

        Arc::new(agent)
    }

    fn requester(agent: &Arc<Agent>) -> Option<String> {
        agent
            .state()
            .active_session()
            .and_then(|session| session.control.requester_uuid.clone())
    }

    fn waiting_view(allow: bool) -> remote_core::SessionControlView {
        remote_core::SessionControlView {
            pending_requests: vec![remote_core::PendingControlRequest {
                participant_uuid: "operator-1".into(),
                display_name: "Sam".into(),
                requested_at: None,
            }],
            allow_remote_control: allow,
            ..remote_core::SessionControlView::default()
        }
    }

    /// The organisation's switch. `allow_remote_control` exists "so the dialog
    /// cannot offer what would be refused", and nothing used to read it: the
    /// consent dialog appeared for an organisation that had remote control
    /// switched off.
    #[test]
    fn nobody_is_asked_when_the_organisation_forbids_remote_control() {
        let agent = control_agent();
        let sink: StateSink = Arc::new(|_| {});

        adopt_control(&agent, &sink, &waiting_view(false));

        assert!(
            requester(&agent).is_none(),
            "no dialog is raised for a request policy would refuse"
        );

        // And the same view with the switch on does ask, so the test above is
        // about the policy rather than about the view being ignored.
        adopt_control(&agent, &sink, &waiting_view(true));

        assert_eq!(requester(&agent).as_deref(), Some("operator-1"));
    }

    /// Switching it off mid-session ends control at the next read, rather than
    /// leaving the grant in force until the session happens to end.
    /// The read that crosses an Allow still lists the request, because the
    /// report of the answer has not landed. It must not take the grant away
    /// again: that dropped the controller's input and put the dialog back.
    #[test]
    fn a_read_that_crosses_an_allow_does_not_undo_it() {
        let agent = control_agent();
        let sink: StateSink = Arc::new(|_| {});

        adopt_control(&agent, &sink, &waiting_view(true));
        assert_eq!(requester(&agent).as_deref(), Some("operator-1"));

        // The person at the machine presses Allow.
        agent.grant_control("operator-1", false, false);
        assert_eq!(agent.control_state(), ControlState::Granted);

        // The API has not heard yet: the same request is still listed.
        adopt_control(&agent, &sink, &waiting_view(true));

        assert_eq!(
            agent.control_state(),
            ControlState::Granted,
            "a stale read is not a new request"
        );
        assert!(agent.state().is_being_controlled());
    }

    /// A second person asking must not cut off the one who is controlling.
    #[test]
    fn somebody_else_asking_does_not_interrupt_the_controller() {
        let agent = control_agent();
        let sink: StateSink = Arc::new(|_| {});

        let granted = remote_core::SessionControlView {
            controller_uuid: Some("operator-1".into()),
            controller_name: Some("Sam".into()),
            grant_id: Some("grant-1".into()),
            allow_remote_control: true,
            ..remote_core::SessionControlView::default()
        };
        adopt_control(&agent, &sink, &granted);
        assert_eq!(agent.control_state(), ControlState::Granted);

        adopt_control(
            &agent,
            &sink,
            &remote_core::SessionControlView {
                pending_requests: vec![remote_core::PendingControlRequest {
                    participant_uuid: "operator-2".into(),
                    display_name: "Priya".into(),
                    requested_at: None,
                }],
                ..granted
            },
        );

        assert_eq!(agent.control_state(), ControlState::Granted);
        assert_eq!(agent.controller_uuid().as_deref(), Some("operator-1"));
    }

    /// Once the API no longer names the controller at all, a waiting request
    /// is a real one and is asked about.
    #[test]
    fn a_request_is_raised_once_the_previous_controller_is_gone() {
        let agent = control_agent();
        let sink: StateSink = Arc::new(|_| {});

        adopt_control(
            &agent,
            &sink,
            &remote_core::SessionControlView {
                controller_uuid: Some("operator-2".into()),
                grant_id: Some("grant-1".into()),
                allow_remote_control: true,
                ..remote_core::SessionControlView::default()
            },
        );
        assert_eq!(agent.control_state(), ControlState::Granted);

        // operator-2 is neither controlling nor asking any more; operator-1 asks.
        adopt_control(&agent, &sink, &waiting_view(true));

        assert_eq!(requester(&agent).as_deref(), Some("operator-1"));
        assert_ne!(agent.control_state(), ControlState::Granted);
    }

    /// **A departure the relay announces is not the end of the session.** It
    /// says so whenever a socket closes — a browser's two-minute room token
    /// expiring, a reload, a dropped connection — and the same page is back a
    /// moment later with its peer connection intact.
    #[test]
    fn the_same_page_coming_back_keeps_its_connection() {
        // Announced as gone, then back with the same epoch, connection up.
        assert_eq!(
            arrival(
                Some("viewer"),
                Some("page-1"),
                true,
                PeerState::Connected,
                "viewer",
                Some("page-1")
            ),
            Arrival::Returned
        );
        // A renewed token with no departure announced at all.
        assert_eq!(
            arrival(
                Some("viewer"),
                Some("page-1"),
                false,
                PeerState::Connected,
                "viewer",
                Some("page-1")
            ),
            Arrival::Returned
        );
        // A relay or a browser that sends no epoch: the live connection is
        // the evidence.
        assert_eq!(
            arrival(
                Some("viewer"),
                None,
                true,
                PeerState::Connected,
                "viewer",
                None
            ),
            Arrival::Returned
        );
    }

    /// A reloaded page has a new epoch and no peer connection: the one this
    /// computer holds died with the old page, so the new page gets a new one.
    #[test]
    fn a_reloaded_page_gets_a_connection_of_its_own() {
        assert_eq!(
            arrival(
                Some("viewer"),
                Some("page-1"),
                true,
                PeerState::Connected,
                "viewer",
                Some("page-2")
            ),
            Arrival::Offer
        );
        // The same page, but the connection it had is gone.
        assert_eq!(
            arrival(
                Some("viewer"),
                Some("page-1"),
                true,
                PeerState::Failed,
                "viewer",
                Some("page-1")
            ),
            Arrival::Offer
        );
        // Still being set up with this very page: offer again on it.
        assert_eq!(
            arrival(
                Some("viewer"),
                Some("page-1"),
                false,
                PeerState::Connecting,
                "viewer",
                Some("page-1")
            ),
            Arrival::Reoffer
        );
    }

    /// One person at a time: a second arrival does not take the connection
    /// from the first, and is taken once the first has gone.
    #[test]
    fn a_second_person_waits_until_the_first_has_gone() {
        assert_eq!(
            arrival(None, None, false, PeerState::New, "viewer", Some("p")),
            Arrival::Offer,
            "the first to arrive is offered to"
        );
        assert_eq!(
            arrival(
                Some("viewer"),
                Some("p"),
                false,
                PeerState::Connected,
                "other",
                Some("q")
            ),
            Arrival::Busy
        );
        assert_eq!(
            arrival(
                Some("viewer"),
                Some("p"),
                true,
                PeerState::Connected,
                "other",
                Some("q")
            ),
            Arrival::Offer,
            "the first was announced as gone"
        );
        assert_eq!(
            arrival(
                Some("viewer"),
                Some("p"),
                false,
                PeerState::Failed,
                "other",
                Some("q")
            ),
            Arrival::Offer
        );
    }

    #[test]
    fn a_grant_is_dropped_when_the_switch_goes_off() {
        let agent = control_agent();
        let sink: StateSink = Arc::new(|_| {});

        let granted = remote_core::SessionControlView {
            controller_uuid: Some("operator-1".into()),
            controller_name: Some("Sam".into()),
            grant_id: Some("grant-1".into()),
            allow_remote_control: true,
            ..remote_core::SessionControlView::default()
        };

        adopt_control(&agent, &sink, &granted);
        assert!(agent.state().is_being_controlled());

        adopt_control(
            &agent,
            &sink,
            &remote_core::SessionControlView {
                allow_remote_control: false,
                ..granted
            },
        );

        assert!(
            !agent.state().is_being_controlled(),
            "the organisation withdrew remote control, so the grant goes with it"
        );
    }

    #[test]
    fn a_join_response_is_read_without_trusting_its_shape() {
        let joined = read_join(&join_body()).expect("reads");

        assert_eq!(joined.participant_uuid, "device-participant");
        assert_eq!(joined.display_id, "AR-10282");
        assert_eq!(joined.company_name.as_deref(), Some("Northwind"));
        assert_eq!(joined.signalling_url, "wss://remote.aicountly.com/signal");
        assert_eq!(joined.ice.server_count(), 1);
        assert!(joined.ice.relay_available);
    }

    /// A missing token is a clear refusal, not a panic in a background task.
    #[test]
    fn a_join_response_without_a_token_is_refused_rather_than_unwrapped() {
        let mut body = join_body();
        body["signalling"]["token"] = serde_json::Value::Null;

        assert!(read_join(&body).is_err());

        let mut body = join_body();
        body["participant"] = serde_json::Value::Null;

        assert!(read_join(&body).is_err());

        assert!(read_join(&serde_json::json!({})).is_err());
    }

    /// The ICE configuration comes from the API, per session. There is no
    /// constant here a TURN credential could have been built into.
    #[test]
    fn the_ice_configuration_comes_from_the_response_and_nowhere_else() {
        let mut body = join_body();
        body["iceServers"] = serde_json::json!([]);
        body["relayAvailable"] = serde_json::json!(false);

        let joined = read_join(&body).expect("reads");

        assert!(joined.ice.is_empty());
        assert!(!joined.ice.relay_available);
    }

    #[test]
    fn control_ended_travels_in_the_envelope_the_browser_decodes() {
        let bytes = control_ended_bytes("sess-1", "agent-1", &Ended::Stopped).expect("encodes");
        let value: serde_json::Value = serde_json::from_slice(&bytes).unwrap();

        // What web/src/services/webrtc/remoteControl.ts decodeControlEnvelope requires.
        assert_eq!(
            value["v"],
            serde_json::json!(remote_protocol::PROTOCOL_VERSION)
        );
        assert_eq!(value["s"], "sess-1");
        assert_eq!(value["p"], "agent-1");
        assert!(value["n"].as_u64().unwrap() > 0);
        assert!(
            value["m"].is_object(),
            "the message sits inside the envelope"
        );

        let envelope: remote_protocol::ControlEnvelope = serde_json::from_slice(&bytes).unwrap();
        assert!(matches!(
            envelope.message,
            remote_protocol::ControlMessage::ControlEnded { .. }
        ));
    }

    #[test]
    fn the_signalling_connection_is_renewed_before_its_token_expires() {
        let now = Instant::now();

        let in_two_minutes = time::OffsetDateTime::now_utc() + time::Duration::seconds(120);
        let renew = renew_at(Some(in_two_minutes));
        let wait = renew.duration_since(now);
        assert!(
            wait >= Duration::from_secs(85) && wait <= Duration::from_secs(91),
            "about 30 s early: {wait:?}"
        );

        // An expiry already passed, or one too close to act on, is retried shortly rather than spun on.
        let past = time::OffsetDateTime::now_utc() - time::Duration::seconds(10);
        let wait = renew_at(Some(past)).duration_since(now);
        assert!(wait >= RENEW_RETRY && wait <= RENEW_RETRY + Duration::from_secs(1));

        // No stated expiry: the documented two-minute lifetime.
        let wait = renew_at(None).duration_since(now);
        assert!(wait >= Duration::from_secs(89) && wait <= Duration::from_secs(91));
    }

    #[test]
    fn a_join_response_states_when_its_token_expires() {
        let joined = read_join(&join_body()).expect("reads");
        assert!(joined.signalling_expires_at.is_some());
    }

    #[test]
    fn every_ending_has_a_reason_the_peer_can_render() {
        use remote_protocol::ControlEndReason;

        assert_eq!(
            Ended::Normally.wire_reason(),
            ControlEndReason::SessionEnded
        );
        assert_eq!(
            Ended::NoPeer.wire_reason(),
            ControlEndReason::ConnectionLost
        );
        assert_eq!(
            Ended::Disconnected.wire_reason(),
            ControlEndReason::ConnectionLost
        );
        assert_eq!(Ended::Stopped.wire_reason(), ControlEndReason::ShuttingDown);
        assert_eq!(
            Ended::Refused("x".into()).wire_reason(),
            ControlEndReason::RevokedByServer
        );
    }

    fn congestion(packet_loss: f64, round_trip_ms: f64) -> remote_webrtc::Congestion {
        remote_webrtc::Congestion {
            available_bitrate_bps: None,
            packet_loss,
            round_trip_ms,
        }
    }

    /// Nothing is captured for a session nobody has joined, and a refused
    /// start is not retried on every turn.
    #[test]
    fn capture_starts_only_once_the_peer_is_connected() {
        let mut pump = CapturePump::new(CaptureProfile::adaptive());
        let now = Instant::now();

        for state in [
            PeerState::New,
            PeerState::Connecting,
            PeerState::Disconnected,
            PeerState::Failed,
            PeerState::Closed,
        ] {
            assert!(!pump.should_start(state, now), "{state:?}");
        }
        assert!(pump.should_start(PeerState::Connected, now));

        pump.next_attempt_at = now + CAPTURE_RETRY;
        assert!(!pump.should_start(PeerState::Connected, now));
        assert!(pump.should_start(PeerState::Connected, now + CAPTURE_RETRY));
    }

    /// While capturing, the loop waits on signalling only until the next frame
    /// is due, so a quiet room does not cap the frame rate at the pump's 20.
    #[test]
    fn the_loop_waits_no_longer_than_the_next_frame() {
        let now = Instant::now();
        let mut pump = CapturePump::new(CaptureProfile::adaptive());
        assert_eq!(pump.wait(now), PUMP_INTERVAL, "not capturing");

        pump.layout = Some(remote_protocol::MonitorLayout {
            monitors: Vec::new(),
            active_monitor_id: 1,
        });
        pump.next_frame_at = now + Duration::from_millis(20);
        assert_eq!(pump.wait(now), Duration::from_millis(20));

        pump.next_frame_at = now;
        assert_eq!(
            pump.wait(now),
            Duration::from_millis(1),
            "never a busy spin"
        );

        pump.next_frame_at = now + Duration::from_secs(1);
        assert_eq!(pump.wait(now), PUMP_INTERVAL);
    }

    /// The picture steps down on a bad link and back up on a good one, and
    /// never above the quality the person chose.
    #[test]
    fn the_capture_follows_the_link_but_never_exceeds_the_setting() {
        let mut pump = CapturePump::new(CaptureProfile::low_bandwidth());
        let ceiling = CaptureProfile::low_bandwidth();

        let worse = pump.adjusted(&congestion(0.2, 900.0));
        assert!(
            worse.max_dimension < ceiling.max_dimension || worse.max_fps < ceiling.max_fps,
            "{worse:?}"
        );

        pump.profile = worse;
        let better = pump.adjusted(&congestion(0.0, 20.0));
        assert!(better.max_dimension <= ceiling.max_dimension);
        assert!(better.max_fps <= ceiling.max_fps);

        pump.profile = ceiling;
        assert_eq!(pump.adjusted(&congestion(0.0, 20.0)), ceiling);
    }

    /// The controller is told which display it is looking at, in the same
    /// envelope as every other control message.
    #[test]
    fn the_monitor_layout_goes_out_in_an_envelope() {
        let layout = remote_protocol::MonitorLayout {
            monitors: vec![remote_protocol::Monitor {
                id: 1,
                name: "Built-in display".into(),
                primary: true,
                x: 0,
                y: 0,
                width: 1920,
                height: 1080,
                scale: 1.0,
                refresh_hz: Some(60),
                orientation: remote_protocol::Orientation::Landscape,
            }],
            active_monitor_id: 1,
        };

        let bytes = layout_bytes("session-1", "host-1", layout).expect("encoded");
        let envelope = remote_protocol::ControlEnvelope::decode(&bytes).expect("decodes");

        assert!(matches!(
            envelope.message,
            remote_protocol::ControlMessage::MonitorLayout(ref sent) if sent.active_monitor_id == 1
        ));
    }
}
