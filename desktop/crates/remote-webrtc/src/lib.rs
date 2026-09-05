//! The WebRTC seam.
//!
//! The agent talks to [`PeerSession`] and to nothing else. Which library is
//! behind it is a decision made once, here, and changing it later is a change
//! to this crate and to no other — which is the whole reason the trait exists.
//!
//! # It reuses the existing architecture, and adds nothing
//!
//! There is no second relay protocol and no second signalling service. The
//! agent obtains a signalling token from the same
//! `POST /sessions/{uuid}/signalling-token` endpoint the browser uses, gets
//! its ICE configuration in the same response, joins the same room, exchanges
//! the same `offer` / `answer` / `ice-candidate` messages, and speaks the same
//! `aicountly-remote` data channel for chat and files. The API is the
//! authority; the signalling service verifies and relays.
//!
//! # No TURN credential is ever built into this binary
//!
//! [`IceConfiguration`] is deserialised from the API's per-session response.
//! There is no constant here, no environment variable, and no field a build
//! could bake one into — a credential in a shipped binary is a credential
//! anybody who downloads the installer has.
//!
//! # Screen frames
//!
//! Frames go from [`remote_device::Frame`] into the encoder and out over
//! SRTP. They are never written to disk, never sent to the API, never
//! retained past the encode. There is no method here that would let them be.

#![forbid(unsafe_code)]
#![deny(missing_docs)]

use std::fmt;

use remote_device::{CaptureProfile, Frame};
use serde::{Deserialize, Serialize};

#[cfg(feature = "webrtc-rs")]
pub mod webrtc_rs;

/// The ICE servers for one session, exactly as the API sent them.
///
/// Obtained per session from
/// `POST /v1/remote/sessions/{uuid}/signalling-token`, never configured
/// locally. When `relay_available` is false the API is telling us no TURN is
/// configured — which the interface says out loud rather than showing
/// "Reconnecting…" forever at a peer it will never reach.
#[derive(Debug, Clone, Default, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct IceConfiguration {
    /// The `RTCIceServer` list, in the browser's own shape.
    #[serde(default)]
    pub ice_servers: Vec<serde_json::Value>,
    /// Whether a relay exists at all.
    #[serde(default)]
    pub relay_available: bool,
}

impl IceConfiguration {
    /// Whether anything at all was configured.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.ice_servers.is_empty()
    }

    /// The number of servers, for the diagnostics panel.
    ///
    /// The count, never the list: an ICE server entry carries an ephemeral
    /// TURN username and credential, and a diagnostics panel is a screenshot
    /// waiting to happen.
    #[must_use]
    pub fn server_count(&self) -> usize {
        self.ice_servers.len()
    }
}

/// How a peer connection is going.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum PeerState {
    /// Not started.
    New,
    /// Gathering and checking candidates.
    Connecting,
    /// Media and data are flowing.
    Connected,
    /// Lost, and trying to recover.
    Disconnected,
    /// Gone for good.
    Failed,
    /// Closed deliberately.
    Closed,
}

impl PeerState {
    /// Whether media is flowing.
    #[must_use]
    pub fn is_live(self) -> bool {
        matches!(self, Self::Connected)
    }

    /// Whether this is the end of the road.
    #[must_use]
    pub fn is_terminal(self) -> bool {
        matches!(self, Self::Failed | Self::Closed)
    }
}

/// What the transport tells the agent about the link.
///
/// [`Congestion`] is what drives capture quality: the encoder cannot know that
/// the network has stopped keeping up, and a capture that keeps producing
/// 1080p30 into a link that cannot carry it grows a queue of frames nobody
/// will ever see.
#[derive(Debug, Clone, Copy, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Congestion {
    /// What the transport thinks the link will carry, bits per second.
    pub available_bitrate_bps: Option<u64>,
    /// Fraction of packets lost, 0.0–1.0.
    pub packet_loss: f64,
    /// Round-trip time in milliseconds.
    pub round_trip_ms: f64,
}

impl Congestion {
    /// Whether the capture should step down.
    ///
    /// Deliberately conservative on both sides. Reacting to a single bad
    /// reading makes the session oscillate; not reacting at all makes it
    /// stutter and never recover.
    #[must_use]
    pub fn should_degrade(&self) -> bool {
        self.packet_loss > 0.08 || self.round_trip_ms > 500.0
    }

    /// Whether there is headroom to step back up.
    #[must_use]
    pub fn should_improve(&self) -> bool {
        self.packet_loss < 0.01 && self.round_trip_ms < 150.0
    }

    /// The profile this reading suggests.
    #[must_use]
    pub fn adjust(&self, profile: CaptureProfile) -> CaptureProfile {
        if self.should_degrade() {
            return profile.degraded();
        }

        if self.should_improve() {
            return profile.improved();
        }

        profile
    }
}

/// A message from the signalling service, to be handed to the session.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(tag = "type", rename_all = "kebab-case")]
pub enum SignalMessage {
    /// An SDP offer from the peer.
    Offer {
        /// Who sent it.
        from: String,
        /// The SDP.
        payload: serde_json::Value,
    },
    /// An SDP answer.
    Answer {
        /// Who sent it.
        from: String,
        /// The SDP.
        payload: serde_json::Value,
    },
    /// A trickled ICE candidate.
    IceCandidate {
        /// Who sent it.
        from: String,
        /// The candidate.
        payload: serde_json::Value,
    },
}

/// One peer connection, as the agent uses it.
///
/// Async, because everything underneath is. Deliberately small: the agent
/// negotiates, sends frames, sends and receives on two data channels, reads
/// congestion, and closes.
#[allow(async_fn_in_trait)]
pub trait PeerSession: Send {
    /// Create the offer, and with it the data channels.
    async fn create_offer(&mut self) -> Result<serde_json::Value, WebRtcError>;

    /// Accept a peer's offer and produce the answer.
    async fn accept_offer(&mut self, offer: serde_json::Value) -> Result<serde_json::Value, WebRtcError>;

    /// Accept the answer to our offer.
    async fn accept_answer(&mut self, answer: serde_json::Value) -> Result<(), WebRtcError>;

    /// Add a trickled candidate.
    async fn add_ice_candidate(&mut self, candidate: serde_json::Value) -> Result<(), WebRtcError>;

    /// Push one captured frame into the encoder.
    ///
    /// Takes the frame by value and drops it: nothing here retains a frame,
    /// and there is no method that would return one.
    async fn send_frame(&mut self, frame: Frame) -> Result<(), WebRtcError>;

    /// Send a control-protocol message on the control channel.
    async fn send_control(&mut self, bytes: &[u8]) -> Result<(), WebRtcError>;

    /// Take whatever has arrived on the control channel since the last call.
    async fn receive_control(&mut self) -> Result<Vec<Vec<u8>>, WebRtcError>;

    /// The connection's current state.
    fn state(&self) -> PeerState;

    /// The most recent congestion reading, if the transport has produced one.
    async fn congestion(&self) -> Option<Congestion>;

    /// Close everything.
    async fn close(&mut self) -> Result<(), WebRtcError>;
}

/// Builds peer sessions.
///
/// The agent holds one of these and never names the implementation, which is
/// what makes swapping it a one-file change.
#[allow(async_fn_in_trait)]
pub trait PeerSessionFactory: Send + Sync {
    /// The session type this factory produces.
    type Session: PeerSession;

    /// Create a session for one peer, using the ICE configuration the API gave
    /// us for this session.
    async fn create(
        &self,
        ice: IceConfiguration,
        profile: CaptureProfile,
    ) -> Result<Self::Session, WebRtcError>;

    /// A name for the diagnostics panel: "webrtc-rs 0.20".
    fn implementation(&self) -> &'static str;
}

/// Why a WebRTC operation failed.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum WebRtcError {
    /// The peer connection could not be created.
    #[error("the secure connection could not be created: {0}")]
    Setup(String),

    /// SDP that could not be used.
    #[error("the session description was not usable: {0}")]
    Sdp(String),

    /// A data channel operation failed.
    #[error("the data channel failed: {0}")]
    DataChannel(String),

    /// The frame could not be encoded or sent.
    #[error("the video track failed: {0}")]
    Media(String),

    /// The connection is not in a state where this makes sense.
    #[error("the connection is {0:?}")]
    WrongState(PeerState),

    /// This build has no WebRTC implementation compiled in.
    ///
    /// Only reachable when the `webrtc-rs` feature is off, which is how CI
    /// type-checks the abstraction without building the media stack.
    #[error("this build has no WebRTC implementation")]
    NotCompiledIn,
}

/// A session identifier and the participant it belongs to.
#[derive(Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct SessionIdentity {
    /// The session.
    pub session_uuid: String,
    /// This agent's participant row in it.
    pub participant_uuid: String,
    /// The room to join, which is the session uuid.
    pub room: String,
}

/// Prints the identifiers, which are not secrets, and never a token.
impl fmt::Debug for SessionIdentity {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("SessionIdentity")
            .field("session_uuid", &self.session_uuid)
            .field("participant_uuid", &self.participant_uuid)
            .finish_non_exhaustive()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn reading(packet_loss: f64, round_trip_ms: f64) -> Congestion {
        Congestion {
            available_bitrate_bps: Some(2_000_000),
            packet_loss,
            round_trip_ms,
        }
    }

    /// A capture that keeps producing 1080p30 into a link that cannot carry it
    /// grows a queue of frames nobody will ever see.
    #[test]
    fn a_congested_link_steps_the_capture_down() {
        let profile = CaptureProfile::adaptive();

        let degraded = reading(0.15, 120.0).adjust(profile);
        assert!(degraded.max_dimension < profile.max_dimension);

        let slow = reading(0.0, 900.0).adjust(profile);
        assert!(slow.max_dimension < profile.max_dimension);
    }

    #[test]
    fn a_healthy_link_steps_it_back_up_towards_the_ceiling() {
        let degraded = CaptureProfile::adaptive().degraded().degraded();

        let improved = reading(0.0, 20.0).adjust(degraded);

        assert!(improved.max_dimension >= degraded.max_dimension);
    }

    /// Reacting to every reading makes the session oscillate. A middling link
    /// is left alone.
    #[test]
    fn a_middling_link_is_left_alone() {
        let profile = CaptureProfile::adaptive();
        let middling = reading(0.03, 300.0);

        assert!(!middling.should_degrade());
        assert!(!middling.should_improve());
        assert_eq!(middling.adjust(profile), profile);
    }

    #[test]
    fn peer_states_answer_the_two_questions_the_ui_asks() {
        assert!(PeerState::Connected.is_live());
        assert!(!PeerState::Connecting.is_live());

        assert!(PeerState::Failed.is_terminal());
        assert!(PeerState::Closed.is_terminal());
        assert!(!PeerState::Disconnected.is_terminal());
    }

    /// The ICE configuration is deserialised from the API's response. There is
    /// no constant and no field a build could bake a credential into.
    #[test]
    fn the_ice_configuration_comes_from_the_api_and_nowhere_else() {
        let from_api: IceConfiguration = serde_json::from_str(
            r#"{
                "iceServers": [
                    {"urls": ["stun:stun.l.google.com:19302"]},
                    {"urls": ["turn:turn.aicountly.com:3478"], "username": "1770000000", "credential": "ephemeral"}
                ],
                "relayAvailable": true
            }"#,
        )
        .expect("parses the API's shape");

        assert_eq!(from_api.server_count(), 2);
        assert!(from_api.relay_available);

        // The default is empty: nothing is configured until the API says so.
        let default = IceConfiguration::default();
        assert!(default.is_empty());
        assert!(!default.relay_available);
    }

    /// A diagnostics panel showing the ICE list would show an ephemeral TURN
    /// credential — and a diagnostics panel is a screenshot waiting to happen.
    #[test]
    fn the_diagnostics_view_of_ice_is_a_count_not_a_list() {
        let ice = IceConfiguration {
            ice_servers: vec![serde_json::json!({
                "urls": ["turn:turn.aicountly.com:3478"],
                "username": "1770000000",
                "credential": "a-real-ephemeral-credential"
            })],
            relay_available: true,
        };

        assert_eq!(ice.server_count(), 1);
        assert!(!format!("{}", ice.server_count()).contains("credential"));
    }

    #[test]
    fn signal_messages_match_the_signalling_services_wire_format() {
        let offer: SignalMessage = serde_json::from_str(
            r#"{"type":"offer","from":"peer-uuid","payload":{"sdp":"v=0","type":"offer"}}"#,
        )
        .expect("parses");

        assert!(matches!(offer, SignalMessage::Offer { .. }));

        let candidate: SignalMessage = serde_json::from_str(
            r#"{"type":"ice-candidate","from":"peer-uuid","payload":{"candidate":"candidate:1"}}"#,
        )
        .expect("parses");

        assert!(matches!(candidate, SignalMessage::IceCandidate { .. }));
    }

    #[test]
    fn a_session_identity_prints_no_credential() {
        let identity = SessionIdentity {
            session_uuid: "session-uuid".into(),
            participant_uuid: "participant-uuid".into(),
            room: "session-uuid".into(),
        };

        let rendered = format!("{identity:?}");

        assert!(rendered.contains("session_uuid"));
        assert!(!rendered.to_lowercase().contains("token"));
    }
}
