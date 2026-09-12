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

    tracing::info!(
        session = %session_uuid,
        participant = %joined.participant_uuid,
        "this computer joined the session"
    );

    loop {
        if stopping.load(Ordering::SeqCst) {
            announce_end(&mut peer, Ended::Stopped).await;
            socket.close().await;

            return Ended::Stopped;
        }

        // --- signalling ---------------------------------------------------
        match tokio::time::timeout(PUMP_INTERVAL, socket.next()).await {
            Ok(Ok(Some(signal))) => {
                match handle_signal(
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
                        announce_end(&mut peer, reason.clone()).await;
                        socket.close().await;

                        return reason;
                    }
                }
            }
            // The relay closed it: an expired token, the session ending, or a
            // newer connection replacing this one. None is an error.
            Ok(Ok(None)) => {
                announce_end(&mut peer, Ended::Normally).await;

                return Ended::Normally;
            }
            Ok(Err(error)) => {
                tracing::warn!(%error, "the signalling connection dropped");
                announce_end(&mut peer, Ended::Disconnected).await;

                return Ended::Disconnected;
            }
            // Nothing arrived within the pump interval, which is the ordinary
            // case: fall through and do the local work.
            Err(_) => {}
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
                    announce_end(&mut peer, Ended::Refused("removed".into())).await;

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
            announce_end(&mut peer, Ended::Disconnected).await;
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

async fn handle_signal<P: PeerSession>(
    peer: &mut P,
    socket: &mut SignallingSocket,
    signal: Signal,
    self_uuid: &str,
    peer_uuid: &mut Option<String>,
) -> Continue {
    match signal {
        // We are the newcomer. Whoever is already here offers to us, so there
        // is nothing to do but note who to trickle candidates to.
        Signal::Joined { peers, .. } => {
            *peer_uuid = peers
                .into_iter()
                .map(|entry| entry.participant_uuid)
                .find(|uuid| uuid != self_uuid);
        }

        // We were here first, so we offer.
        Signal::PeerJoined { peer: arrived, .. } => {
            if arrived.participant_uuid == self_uuid {
                return Continue::Carry;
            }

            *peer_uuid = Some(arrived.participant_uuid.clone());

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
        // a consent dialog on this machine.
        sink(agent.control_requested(&waiting.participant_uuid));

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
async fn announce_end<P: PeerSession>(peer: &mut P, ended: Ended) {
    let message = remote_protocol::ControlMessage::ControlEnded {
        reason: ended.wire_reason(),
    };

    if let Ok(bytes) = serde_json::to_vec(&message) {
        let _ = peer.send_control(&bytes).await;
    }

    let _ = peer.close().await;
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
