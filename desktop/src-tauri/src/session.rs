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
//! # What is not here
//!
//! No frame is sent. `PeerSession::send_frame` exists and there is no encoder
//! behind it yet, so this negotiates a video track the agent does not fill.
//! See `docs/desktop/ARCHITECTURE.md` — the omission is deliberate and stated
//! rather than hidden behind a loop that quietly does nothing.

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

use crate::runtime::StateSink;
use crate::Agent;

/// How often local ICE candidates are trickled and the control channel drained.
///
/// Fast, because both are latency: a candidate held back is a connection that
/// takes longer to establish, and a control message held back is a mouse that
/// lags.
const PUMP_INTERVAL: Duration = Duration::from_millis(50);

/// How often the API is asked who is waiting for control.
///
/// Slower, because somebody is reading a dialog rather than moving a mouse.
const CONTROL_POLL: Duration = Duration::from_secs(3);

/// How long to wait for a peer before giving up on the session.
///
/// A session nobody joins is a session somebody started and abandoned, and an
/// agent that waited for ever would keep an indicator on somebody's screen for
/// a connection that is not coming.
const PEER_TIMEOUT: Duration = Duration::from_secs(180);

/// How long before its token expires the signalling connection is replaced with a fresh one. The relay closes a
/// socket when its token expires; replacing it first means the room never sees this computer leave.
const RENEW_BEFORE_EXPIRY: Duration = Duration::from_secs(30);

/// The room token's lifetime when the join response did not state an expiry (`remote.signallingTokenTtl`).
const ASSUMED_TOKEN_LIFETIME: Duration = Duration::from_secs(120);

/// How long to wait before trying a failed renewal again.
const RENEW_RETRY: Duration = Duration::from_secs(5);

/// How long the session keeps trying to get its signalling connection back after the relay dropped it.
const RECONNECT_WINDOW: Duration = Duration::from_secs(30);

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
struct Joined {
    participant_uuid: String,
    display_id: String,
    company_name: Option<String>,
    signalling_url: String,
    signalling_token: String,
    /// When the room token stops being accepted. `None` when the response did not say, in which case the
    /// connection is renewed on the server's documented two-minute lifetime.
    signalling_expires_at: Option<time::OffsetDateTime>,
    ice: IceConfiguration,
}

/// Read the join response without trusting its shape.
///
/// Every field is optional in the parse and checked afterwards: this arrived
/// over the network, and a missing `signalling.token` should be a clear
/// refusal rather than a panic in a background task.
fn read_join(body: &serde_json::Value) -> Result<Joined, String> {
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
    )
    .await;

    let _ = crate::ipc::session_ended(&session_uuid);
    sink(agent.end_session());

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
) -> Ended
where
    F: PeerSessionFactory,
{
    // The configured ceiling. It comes down from here under congestion and
    // never goes above it.
    let profile = CaptureProfile::for_quality(quality_from(&agent.config().capture_quality));

    let mut peer = match factory.create(joined.ice.clone(), profile).await {
        Ok(peer) => peer,
        Err(error) => return Ended::Refused(error.to_string()),
    };

    let mut socket =
        match SignallingSocket::connect(&joined.signalling_url, &joined.signalling_token).await {
            Ok(socket) => socket,
            Err(SignallingError::Address(reason)) => return Ended::Refused(reason),
            Err(error) => {
                tracing::warn!(%error, "the signalling connection failed");

                return Ended::Disconnected;
            }
        };

    // The peer to trickle candidates to. Whoever is in the room with us —
    // which is not the same question as who is *controlling*, and conflating
    // the two would mean a session that never connects until control is
    // granted.
    let mut peer_uuid: Option<String> = None;
    let mut last_control_poll = Instant::now() - CONTROL_POLL;
    let joined_at = Instant::now();
    let mut renew_after = renew_at(joined.signalling_expires_at);

    tracing::info!(
        session = %session_uuid,
        participant = %joined.participant_uuid,
        "this computer joined the session"
    );

    loop {
        if stopping.load(Ordering::SeqCst) {
            announce_end(&mut peer, joined, session_uuid, Ended::Stopped).await;
            socket.close().await;

            return Ended::Stopped;
        }

        // --- signalling ---------------------------------------------------
        match tokio::time::timeout(PUMP_INTERVAL, socket.next()).await {
            Ok(Ok(Some(signal))) => {
                match handle_signal(
                    agent,
                    sink,
                    &mut peer,
                    &mut socket,
                    signal,
                    &joined.participant_uuid,
                    &mut peer_uuid,
                )
                .await
                {
                    Continue::Carry => {}
                    Continue::Stop(reason) => {
                        announce_end(&mut peer, joined, session_uuid, reason.clone()).await;
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

                match reconnect_signalling(client, session_uuid, stopping).await {
                    Renewal::Fresh(fresh, expires_at) => {
                        socket = *fresh;
                        renew_after = renew_at(expires_at);

                        continue;
                    }
                    Renewal::Over(reason) => {
                        announce_end(&mut peer, joined, session_uuid, reason.clone()).await;

                        return reason;
                    }
                    Renewal::Retry => {
                        announce_end(&mut peer, joined, session_uuid, Ended::Disconnected).await;

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
            match renew_signalling(client, session_uuid).await {
                Renewal::Fresh(fresh, expires_at) => {
                    let old = std::mem::replace(&mut socket, *fresh);
                    old.close().await;
                    renew_after = renew_at(expires_at);
                    tracing::debug!("the signalling connection was renewed");
                }
                Renewal::Over(reason) => {
                    announce_end(&mut peer, joined, session_uuid, reason.clone()).await;
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
        if let Some(target) = peer_uuid.clone() {
            for candidate in peer.take_local_candidates() {
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
        // Every message goes through the gate. Nothing here inspects one
        // first, and nothing here can act on one the gate refused.
        match peer.receive_control().await {
            Ok(messages) => {
                for bytes in messages {
                    if let Err(error) = agent.handle_control(&bytes) {
                        tracing::debug!(?error, "a control message was refused");
                    }
                }
            }
            Err(error) => tracing::debug!(%error, "the control channel could not be read"),
        }

        // --- who is asking, and what is permitted -------------------------
        if last_control_poll.elapsed() >= CONTROL_POLL {
            last_control_poll = Instant::now();

            match client.session_control(session_uuid).await {
                Ok(view) => adopt_control(agent, sink, &view),
                Err(error) if error.is_device_rejected() => {
                    announce_end(
                        &mut peer,
                        joined,
                        session_uuid,
                        Ended::Refused("removed".into()),
                    )
                    .await;

                    return Ended::Refused("This computer was removed from AICOUNTLY.".into());
                }
                Err(error) => tracing::debug!(%error, "the control state could not be read"),
            }
        }

        // --- give up on a session nobody joins ----------------------------
        if peer_uuid.is_none() && joined_at.elapsed() >= PEER_TIMEOUT {
            socket.close().await;

            return Ended::NoPeer;
        }

        if peer.state() == PeerState::Failed {
            announce_end(&mut peer, joined, session_uuid, Ended::Disconnected).await;
            socket.close().await;

            return Ended::Disconnected;
        }
    }
}

/// Whether the loop carries on.
enum Continue {
    Carry,
    Stop(Ended),
}

#[allow(clippy::too_many_arguments)]
async fn handle_signal<P: PeerSession>(
    agent: &Arc<Agent>,
    sink: &StateSink,
    peer: &mut P,
    socket: &mut SignallingSocket,
    signal: Signal,
    self_uuid: &str,
    peer_uuid: &mut Option<String>,
) -> Continue {
    match signal {
        // We are the newcomer. Whoever is already here offers to us, so there
        // is nothing to do but note who to trickle candidates to — and, if
        // the room said who they are, put their name where the session
        // banner and the consent dialog read it from.
        Signal::Joined { peers, .. } => {
            let identified = peers
                .into_iter()
                .find(|entry| entry.participant_uuid != self_uuid);

            if let Some(entry) = &identified {
                if !entry.display_name.is_empty() {
                    sink(agent.peer_connected(&entry.display_name));
                }
            }

            *peer_uuid = identified.map(|entry| entry.participant_uuid);
        }

        // We were here first, so we offer.
        Signal::PeerJoined { peer: arrived, .. } => {
            if arrived.participant_uuid == self_uuid {
                return Continue::Carry;
            }

            *peer_uuid = Some(arrived.participant_uuid.clone());

            if !arrived.display_name.is_empty() {
                sink(agent.peer_connected(&arrived.display_name));
            }

            match peer.create_offer().await {
                Ok(offer) => {
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
            *peer_uuid = Some(from.clone());

            match peer.accept_offer(payload).await {
                Ok(answer) => {
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

        Signal::Answer { payload, .. } => {
            if let Err(error) = peer.accept_answer(payload).await {
                tracing::warn!(%error, "an answer could not be used");
            }
        }

        Signal::IceCandidate { payload, .. } => {
            // A candidate the library cannot use is normal during trickle.
            let _ = peer.add_ice_candidate(payload).await;
        }

        Signal::PeerLeft { .. } => return Continue::Stop(Ended::Normally),
        Signal::SessionEnded { .. } => return Continue::Stop(Ended::Normally),

        Signal::Error { code, message } => {
            tracing::warn!(%code, %message, "the relay refused something");
        }

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
    let sequence = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|elapsed| elapsed.as_millis() as u64)
        .unwrap_or(1);

    let envelope = remote_protocol::ControlEnvelope::new(
        session_uuid,
        participant_uuid,
        sequence,
        remote_protocol::ControlMessage::ControlEnded {
            reason: ended.wire_reason(),
        },
    );

    serde_json::to_vec(&envelope).ok()
}

/// When to replace the signalling connection: shortly before its token expires.
fn renew_at(expires_at: Option<time::OffsetDateTime>) -> Instant {
    let lifetime = match expires_at {
        Some(at) => {
            let left = at - time::OffsetDateTime::now_utc();
            Duration::try_from(left).unwrap_or(Duration::ZERO)
        }
        None => ASSUMED_TOKEN_LIFETIME,
    };

    // Never sooner than a few seconds from now, so a short or already-past expiry cannot spin the loop.
    Instant::now()
        + lifetime
            .saturating_sub(RENEW_BEFORE_EXPIRY)
            .max(RENEW_RETRY)
}

/// Keep trying [`renew_signalling`] for up to [`RECONNECT_WINDOW`], with a short pause between attempts.
async fn reconnect_signalling(
    client: &ApiClient,
    session_uuid: &str,
    stopping: &Arc<AtomicBool>,
) -> Renewal {
    let started = Instant::now();

    loop {
        if stopping.load(Ordering::SeqCst) {
            return Renewal::Over(Ended::Stopped);
        }

        match renew_signalling(client, session_uuid).await {
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
async fn renew_signalling(client: &ApiClient, session_uuid: &str) -> Renewal {
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

    match SignallingSocket::connect(&fresh.signalling_url, &fresh.signalling_token).await {
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

fn now_iso() -> String {
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
}
