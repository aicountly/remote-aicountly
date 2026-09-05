//! The webrtc-rs implementation of [`crate::PeerSession`].
//!
//! Everything that knows the name `webrtc` lives in this file. The agent
//! reaches [`WebRtcFactory`] through [`crate::PeerSessionFactory`] and never
//! names a type from the library, so replacing it is a change here and nowhere
//! else.
//!
//! # Two data channels, and why
//!
//! * `aicountly-remote` — the channel Browser V1 already uses: chat, pointer,
//!   annotation and framed file chunks. The agent speaks the same protocol on
//!   it, which is what lets the existing file transfer work with a desktop
//!   host without a second implementation.
//! * `aicountly-remote-control` — input and clipboard, on its own channel so
//!   that a bug in one cannot deliver into the other, and so control can be
//!   torn down without disturbing an in-flight file.
//!
//! Both are ordered and reliable. An input event applied out of order is a
//! click somewhere nobody intended.
//!
//! # The event handler
//!
//! webrtc-rs 0.20 is driver-based: a background task owns the sockets and
//! dispatches into a [`PeerConnectionEventHandler`]. [`SessionEvents`] is that
//! handler, and it does the least it can — records the connection state,
//! collects local ICE candidates for the signalling client to trickle, and
//! parks an inbound data channel where the session can find it. Anything that
//! needs a decision happens on the agent's own task, not in a callback.

use std::sync::{
    atomic::{AtomicU8, Ordering},
    Arc, Mutex,
};
use std::time::Instant;

use remote_device::{CaptureProfile, Frame};
use rtc::media::Sample;
use rtc::media_stream::MediaStreamTrack;
use rtc::rtp_transceiver::rtp_sender::{
    RTCRtpCodec, RTCRtpCodingParameters, RTCRtpEncodingParameters, RtpCodecKind,
};
use webrtc::data_channel::{DataChannel, RTCDataChannelInit};
use webrtc::media_stream::track_local::static_sample::TrackLocalStaticSample;
use webrtc::media_stream::track_local::TrackLocal;
use webrtc::peer_connection::{
    MediaEngine, PeerConnection, PeerConnectionBuilder, PeerConnectionEventHandler,
    RTCConfigurationBuilder, RTCIceCandidateInit, RTCIceServer, RTCPeerConnectionIceEvent,
    RTCPeerConnectionState, RTCSessionDescription, StatsSelector,
};

use crate::{
    Congestion, IceConfiguration, PeerSession, PeerSessionFactory, PeerState, WebRtcError,
};

/// The collaboration channel Browser V1 already uses.
const DATA_CHANNEL_LABEL: &str = "aicountly-remote";

/// The control channel. See [`remote_protocol::CONTROL_CHANNEL_LABEL`].
const CONTROL_CHANNEL_LABEL: &str = remote_protocol::CONTROL_CHANNEL_LABEL;

/// VP8: every browser decodes it, it needs no hardware and no licensing
/// conversation, and interoperating with browser WebRTC is the only
/// interoperability requirement there is. H.264 would encode more cheaply
/// where a GPU supports it, and is a later change behind this same trait.
const VIDEO_MIME_TYPE: &str = "video/VP8";

/// The RTP clock rate for video, fixed by the RTP specification at 90 kHz.
const VIDEO_CLOCK_RATE: u32 = 90_000;

/// The dynamic payload type the agent offers VP8 under.
const VIDEO_PAYLOAD_TYPE: u8 = 96;

/// The SSRC the screen track is sent on.
const VIDEO_SSRC: u32 = 0x4149_434F;

/// How many inbound control messages may queue before the oldest are dropped.
///
/// A peer that floods the control channel must not grow this without limit.
/// The gate rejects what it does not like, but only after it has been read, so
/// the ceiling has to be here.
const MAX_QUEUED_CONTROL_MESSAGES: usize = 1024;

/// Builds webrtc-rs sessions.
#[derive(Debug, Clone, Copy, Default)]
pub struct WebRtcFactory;

impl PeerSessionFactory for WebRtcFactory {
    type Session = WebRtcSession;

    async fn create(
        &self,
        ice: IceConfiguration,
        profile: CaptureProfile,
    ) -> Result<Self::Session, WebRtcError> {
        WebRtcSession::new(ice, profile).await
    }

    fn implementation(&self) -> &'static str {
        "webrtc-rs 0.20"
    }
}

/// What the connection's background driver hands back to the agent.
///
/// Deliberately three small pieces of shared state rather than a channel: the
/// agent polls on its own schedule, and a callback that blocked on a full
/// channel would stall the driver that owns the sockets.
#[derive(Default)]
struct SharedState {
    /// The connection state, as [`PeerState`] discriminants.
    connection: AtomicU8,
    /// Local ICE candidates the signalling client has not sent yet.
    local_candidates: Mutex<Vec<serde_json::Value>>,
    /// Control messages that have arrived and not been handed over.
    inbox: Mutex<Vec<Vec<u8>>>,
    /// A data channel the peer opened, waiting to be adopted.
    inbound_control: Mutex<Option<Arc<dyn DataChannel>>>,
    inbound_collaboration: Mutex<Option<Arc<dyn DataChannel>>>,
}

/// The connection's event handler. See the module documentation.
#[derive(Clone)]
struct SessionEvents {
    shared: Arc<SharedState>,
}

#[async_trait::async_trait]
impl PeerConnectionEventHandler for SessionEvents {
    async fn on_connection_state_change(&self, state: RTCPeerConnectionState) {
        self.shared
            .connection
            .store(map_state(state) as u8, Ordering::SeqCst);
    }

    async fn on_ice_candidate(&self, event: RTCPeerConnectionIceEvent) {
        // Serialised here rather than in the agent so the library's candidate
        // type does not escape this file.
        let Ok(init) = event.candidate.to_json() else {
            return;
        };

        if let Ok(value) = serde_json::to_value(init) {
            if let Ok(mut queue) = self.shared.local_candidates.lock() {
                queue.push(value);
            }
        }
    }

    async fn on_data_channel(&self, channel: Arc<dyn DataChannel>) {
        // The label is the only thing that decides where a channel goes, and a
        // label this build does not know is left alone rather than guessed at.
        let Ok(label) = channel.label().await else {
            return;
        };

        match label.as_str() {
            CONTROL_CHANNEL_LABEL => {
                if let Ok(mut slot) = self.shared.inbound_control.lock() {
                    *slot = Some(channel);
                }
            }
            DATA_CHANNEL_LABEL => {
                if let Ok(mut slot) = self.shared.inbound_collaboration.lock() {
                    *slot = Some(channel);
                }
            }
            _ => {}
        }
    }
}

/// One peer connection.
pub struct WebRtcSession {
    peer: Arc<dyn PeerConnection>,
    video: Arc<TrackLocalStaticSample>,
    collaboration: Option<Arc<dyn DataChannel>>,
    control: Option<Arc<dyn DataChannel>>,
    shared: Arc<SharedState>,
    profile: CaptureProfile,
    started_at: Instant,
}

impl WebRtcSession {
    async fn new(ice: IceConfiguration, profile: CaptureProfile) -> Result<Self, WebRtcError> {
        let shared = Arc::new(SharedState::default());

        let configuration = RTCConfigurationBuilder::default()
            .with_ice_servers(Self::ice_servers(&ice))
            .build();

        // The default codec set, which is what a browser offers and answers
        // with. Registering only VP8 would produce a connection that no
        // browser could negotiate audio on later, and interoperating with
        // browser WebRTC is the only interoperability requirement there is.
        let mut media = MediaEngine::default();
        media
            .register_default_codecs()
            .map_err(|error| WebRtcError::Setup(error.to_string()))?;

        let peer = PeerConnectionBuilder::new()
            .with_configuration(configuration)
            .with_media_engine(media)
            .with_handler(Arc::new(SessionEvents {
                shared: Arc::clone(&shared),
            }))
            // Bind on every interface, ephemeral port. The agent makes only
            // outbound connections and this listener exists for ICE, which is
            // why there is no fixed port to open on a firewall.
            .with_udp_addrs(vec!["0.0.0.0:0".to_owned()])
            .build()
            .await
            .map_err(|error| WebRtcError::Setup(error.to_string()))?;

        let peer: Arc<dyn PeerConnection> = Arc::new(peer);

        let video = Arc::new(
            TrackLocalStaticSample::new(MediaStreamTrack::new(
                "aicountly-remote".to_owned(),
                "screen".to_owned(),
                "Shared screen".to_owned(),
                RtpCodecKind::Video,
                vec![RTCRtpEncodingParameters {
                    rtp_coding_parameters: RTCRtpCodingParameters {
                        ssrc: Some(VIDEO_SSRC),
                        ..Default::default()
                    },
                    codec: RTCRtpCodec {
                        mime_type: VIDEO_MIME_TYPE.to_owned(),
                        clock_rate: VIDEO_CLOCK_RATE,
                        channels: 0,
                        sdp_fmtp_line: String::new(),
                        rtcp_feedback: vec![],
                    },
                    ..Default::default()
                }],
            ))
            .map_err(|error| WebRtcError::Media(error.to_string()))?,
        );

        peer.add_track(Arc::clone(&video) as Arc<dyn TrackLocal>)
            .await
            .map_err(|error| WebRtcError::Media(error.to_string()))?;

        Ok(Self {
            peer,
            video,
            collaboration: None,
            control: None,
            shared,
            profile,
            started_at: Instant::now(),
        })
    }

    /// Translate the API's ICE list into the library's shape.
    ///
    /// Entries that do not parse are dropped rather than failing the whole
    /// session: an unusable STUN entry should not stop a connection that TURN
    /// would have carried.
    fn ice_servers(ice: &IceConfiguration) -> Vec<RTCIceServer> {
        ice.ice_servers
            .iter()
            .filter_map(|entry| {
                let urls = match entry.get("urls") {
                    Some(serde_json::Value::String(one)) => vec![one.clone()],
                    Some(serde_json::Value::Array(many)) => many
                        .iter()
                        .filter_map(|value| value.as_str().map(str::to_owned))
                        .collect(),
                    _ => return None,
                };

                if urls.is_empty() {
                    return None;
                }

                Some(RTCIceServer {
                    urls,
                    username: entry
                        .get("username")
                        .and_then(serde_json::Value::as_str)
                        .unwrap_or_default()
                        .to_owned(),
                    credential: entry
                        .get("credential")
                        .and_then(serde_json::Value::as_str)
                        .unwrap_or_default()
                        .to_owned(),
                })
            })
            .collect()
    }

    /// The channels the peer opened, once it has opened them.
    ///
    /// Called by the agent's loop after negotiation. Adopting them here rather
    /// than in the callback keeps every decision on the agent's own task.
    fn adopt_inbound_channels(&mut self) {
        if self.control.is_none() {
            if let Ok(mut slot) = self.shared.inbound_control.lock() {
                self.control = slot.take();
            }
        }

        if self.collaboration.is_none() {
            if let Ok(mut slot) = self.shared.inbound_collaboration.lock() {
                self.collaboration = slot.take();
            }
        }
    }

    /// Move whatever has arrived on the control channel into the inbox.
    ///
    /// webrtc-rs 0.20 delivers data-channel events by polling rather than by
    /// callback, so this is where reading actually happens.
    async fn drain_control_channel(&mut self) {
        self.adopt_inbound_channels();

        let Some(channel) = self.control.clone() else {
            return;
        };

        // Bounded: one drain does not spin forever on a peer that is writing
        // faster than the agent can read.
        for _ in 0..MAX_QUEUED_CONTROL_MESSAGES {
            let Some(event) = channel.poll().await else {
                break;
            };

            if let webrtc::data_channel::DataChannelEvent::OnMessage(message) = event {
                // Checked before anything is copied.
                if message.data.len() > remote_protocol::MAX_MESSAGE_BYTES {
                    continue;
                }

                if let Ok(mut inbox) = self.shared.inbox.lock() {
                    if inbox.len() < MAX_QUEUED_CONTROL_MESSAGES {
                        inbox.push(message.data.to_vec());
                    }
                }
            }
        }
    }

    async fn open_channels(&mut self) -> Result<(), WebRtcError> {
        let ordered = RTCDataChannelInit {
            ordered: true,
            ..RTCDataChannelInit::default()
        };

        let collaboration = self
            .peer
            .create_data_channel(DATA_CHANNEL_LABEL, Some(ordered.clone()))
            .await
            .map_err(|error| WebRtcError::DataChannel(error.to_string()))?;

        let control = self
            .peer
            .create_data_channel(CONTROL_CHANNEL_LABEL, Some(ordered))
            .await
            .map_err(|error| WebRtcError::DataChannel(error.to_string()))?;

        self.collaboration = Some(collaboration);
        self.control = Some(control);

        Ok(())
    }
}

fn map_state(state: RTCPeerConnectionState) -> PeerState {
    match state {
        RTCPeerConnectionState::New | RTCPeerConnectionState::Unspecified => PeerState::New,
        RTCPeerConnectionState::Connecting => PeerState::Connecting,
        RTCPeerConnectionState::Connected => PeerState::Connected,
        RTCPeerConnectionState::Disconnected => PeerState::Disconnected,
        RTCPeerConnectionState::Failed => PeerState::Failed,
        RTCPeerConnectionState::Closed => PeerState::Closed,
    }
}

fn state_from_u8(value: u8) -> PeerState {
    match value {
        1 => PeerState::Connecting,
        2 => PeerState::Connected,
        3 => PeerState::Disconnected,
        4 => PeerState::Failed,
        5 => PeerState::Closed,
        _ => PeerState::New,
    }
}

impl PeerSession for WebRtcSession {
    async fn create_offer(&mut self) -> Result<serde_json::Value, WebRtcError> {
        self.open_channels().await?;

        let offer = self
            .peer
            .create_offer(None)
            .await
            .map_err(|error| WebRtcError::Sdp(error.to_string()))?;

        self.peer
            .set_local_description(offer.clone())
            .await
            .map_err(|error| WebRtcError::Sdp(error.to_string()))?;

        serde_json::to_value(offer).map_err(|error| WebRtcError::Sdp(error.to_string()))
    }

    async fn accept_offer(
        &mut self,
        offer: serde_json::Value,
    ) -> Result<serde_json::Value, WebRtcError> {
        let description: RTCSessionDescription =
            serde_json::from_value(offer).map_err(|error| WebRtcError::Sdp(error.to_string()))?;

        self.peer
            .set_remote_description(description)
            .await
            .map_err(|error| WebRtcError::Sdp(error.to_string()))?;

        let answer = self
            .peer
            .create_answer(None)
            .await
            .map_err(|error| WebRtcError::Sdp(error.to_string()))?;

        self.peer
            .set_local_description(answer.clone())
            .await
            .map_err(|error| WebRtcError::Sdp(error.to_string()))?;

        serde_json::to_value(answer).map_err(|error| WebRtcError::Sdp(error.to_string()))
    }

    async fn accept_answer(&mut self, answer: serde_json::Value) -> Result<(), WebRtcError> {
        let description: RTCSessionDescription =
            serde_json::from_value(answer).map_err(|error| WebRtcError::Sdp(error.to_string()))?;

        self.peer
            .set_remote_description(description)
            .await
            .map_err(|error| WebRtcError::Sdp(error.to_string()))
    }

    async fn add_ice_candidate(&mut self, candidate: serde_json::Value) -> Result<(), WebRtcError> {
        let init: RTCIceCandidateInit = serde_json::from_value(candidate)
            .map_err(|error| WebRtcError::Sdp(error.to_string()))?;

        // A candidate the library cannot use is normal during trickle — the
        // connection succeeds on another one — so this is not fatal.
        let _ = self.peer.add_ice_candidate(init).await;

        Ok(())
    }

    /// Local ICE candidates gathered since the last call.
    ///
    /// Drained rather than accumulated: the signalling client trickles each
    /// one exactly once, and a candidate re-sent after a reconnect is a
    /// candidate the peer has already tried.
    fn take_local_candidates(&mut self) -> Vec<serde_json::Value> {
        self.shared
            .local_candidates
            .lock()
            .map(|mut queue| std::mem::take(&mut *queue))
            .unwrap_or_default()
    }

    async fn send_frame(&mut self, frame: Frame) -> Result<(), WebRtcError> {
        // The frame is moved in and dropped at the end of this call. Nothing
        // here keeps one, and there is no method that returns one.
        let sample = Sample {
            data: frame.data.into(),
            duration: std::time::Duration::from_millis(self.profile.frame_interval_ms()),
            ..Sample::default()
        };

        self.video
            .write_sample(VIDEO_SSRC, VIDEO_PAYLOAD_TYPE, &sample, &[])
            .await
            .map_err(|error| WebRtcError::Media(error.to_string()))
    }

    async fn send_control(&mut self, bytes: &[u8]) -> Result<(), WebRtcError> {
        self.adopt_inbound_channels();

        let Some(channel) = self.control.clone() else {
            return Err(WebRtcError::WrongState(self.state()));
        };

        channel
            .send(bytes::BytesMut::from(bytes))
            .await
            .map_err(|error| WebRtcError::DataChannel(error.to_string()))
    }

    async fn receive_control(&mut self) -> Result<Vec<Vec<u8>>, WebRtcError> {
        self.drain_control_channel().await;

        Ok(self
            .shared
            .inbox
            .lock()
            .map(|mut inbox| std::mem::take(&mut *inbox))
            .unwrap_or_default())
    }

    fn state(&self) -> PeerState {
        state_from_u8(self.shared.connection.load(Ordering::SeqCst))
    }

    async fn congestion(&self) -> Option<Congestion> {
        let report = self
            .peer
            .get_stats(
                self.started_at + self.started_at.elapsed(),
                StatsSelector::None,
            )
            .await;

        let mut round_trip_ms = 0.0_f64;
        let mut fraction_lost = 0.0_f64;
        let mut saw_reading = false;

        for entry in report.iter() {
            if let webrtc::peer_connection::RTCStatsReportEntry::RemoteInboundRtp(stats) = entry {
                saw_reading = true;
                round_trip_ms = round_trip_ms.max(stats.round_trip_time * 1000.0);
                fraction_lost = fraction_lost.max(stats.fraction_lost);
            }
        }

        if !saw_reading {
            // No RTCP receiver report yet. Saying "no congestion" here would
            // make the capture step up before a single frame had been
            // acknowledged, so the honest answer is that we do not know.
            return None;
        }

        Some(Congestion {
            available_bitrate_bps: None,
            packet_loss: fraction_lost.clamp(0.0, 1.0),
            round_trip_ms,
        })
    }

    async fn close(&mut self) -> Result<(), WebRtcError> {
        // Channels first, then the connection: closing the peer while a
        // channel is mid-poll is how a teardown produces a warning nobody can
        // act on.
        if let Some(channel) = self.control.take() {
            let _ = channel.close().await;
        }

        if let Some(channel) = self.collaboration.take() {
            let _ = channel.close().await;
        }

        self.peer
            .close()
            .await
            .map_err(|error| WebRtcError::Setup(error.to_string()))?;

        self.shared
            .connection
            .store(PeerState::Closed as u8, Ordering::SeqCst);

        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_apis_ice_list_translates_into_the_libraries_shape() {
        let ice = IceConfiguration {
            ice_servers: vec![
                serde_json::json!({"urls": "stun:stun.l.google.com:19302"}),
                serde_json::json!({
                    "urls": ["turn:turn.aicountly.com:3478", "turns:turn.aicountly.com:5349"],
                    "username": "1770000000",
                    "credential": "ephemeral"
                }),
            ],
            relay_available: true,
        };

        let servers = WebRtcSession::ice_servers(&ice);

        assert_eq!(servers.len(), 2);
        assert_eq!(servers[0].urls.len(), 1);
        assert_eq!(servers[1].urls.len(), 2);
        assert_eq!(servers[1].username, "1770000000");
    }

    /// An unusable STUN entry must not stop a connection TURN would carry.
    #[test]
    fn an_unusable_entry_is_dropped_rather_than_failing_the_session() {
        let ice = IceConfiguration {
            ice_servers: vec![
                serde_json::json!({"nonsense": true}),
                serde_json::json!({"urls": []}),
                serde_json::json!({"urls": ["stun:stun.l.google.com:19302"]}),
            ],
            relay_available: false,
        };

        assert_eq!(WebRtcSession::ice_servers(&ice).len(), 1);
    }

    #[test]
    fn the_two_channel_labels_are_the_ones_the_browser_uses() {
        assert_eq!(DATA_CHANNEL_LABEL, "aicountly-remote");
        assert_eq!(CONTROL_CHANNEL_LABEL, "aicountly-remote-control");
        assert_ne!(DATA_CHANNEL_LABEL, CONTROL_CHANNEL_LABEL);
    }

    #[test]
    fn peer_states_survive_the_round_trip_through_the_atomic() {
        for state in [
            PeerState::New,
            PeerState::Connecting,
            PeerState::Connected,
            PeerState::Disconnected,
            PeerState::Failed,
            PeerState::Closed,
        ] {
            assert_eq!(state_from_u8(state as u8), state);
        }
    }

    #[tokio::test]
    async fn a_session_offers_video_and_both_data_channels() {
        let factory = WebRtcFactory;

        let mut session = factory
            .create(IceConfiguration::default(), CaptureProfile::adaptive())
            .await
            .expect("creates a peer connection");

        assert_eq!(session.state(), PeerState::New);

        let offer = session.create_offer().await.expect("creates an offer");

        let sdp = offer["sdp"].as_str().expect("carries sdp");
        assert!(sdp.contains("v=0"));
        assert!(sdp.contains("m=video"), "the agent must offer video");
        assert!(
            sdp.contains("VP8"),
            "the agent must offer a codec browsers decode"
        );
        assert!(
            sdp.contains("m=application"),
            "the agent must offer data channels"
        );

        session.close().await.expect("closes");
        assert_eq!(session.state(), PeerState::Closed);
    }

    #[tokio::test]
    async fn two_sessions_complete_an_offer_answer_exchange() {
        let factory = WebRtcFactory;

        let mut host = factory
            .create(IceConfiguration::default(), CaptureProfile::adaptive())
            .await
            .expect("host");
        let mut viewer = factory
            .create(IceConfiguration::default(), CaptureProfile::adaptive())
            .await
            .expect("viewer");

        let offer = host.create_offer().await.expect("offer");
        let answer = viewer.accept_offer(offer).await.expect("answer");

        let answer_sdp = answer["sdp"].as_str().expect("carries sdp").to_owned();
        assert!(answer_sdp.contains("m=video"));

        host.accept_answer(answer)
            .await
            .expect("accepts the answer");

        host.close().await.expect("closes host");
        viewer.close().await.expect("closes viewer");
    }

    /// No RTCP receiver report yet means "we do not know", not "no
    /// congestion" — otherwise the capture steps up before a single frame has
    /// been acknowledged.
    #[tokio::test]
    async fn congestion_is_unknown_before_any_receiver_report() {
        let factory = WebRtcFactory;

        let mut session = factory
            .create(IceConfiguration::default(), CaptureProfile::adaptive())
            .await
            .expect("session");

        assert!(session.congestion().await.is_none());

        session.close().await.expect("closes");
    }

    #[tokio::test]
    async fn sending_control_before_the_channel_exists_is_refused_not_dropped() {
        let factory = WebRtcFactory;

        let mut session = factory
            .create(IceConfiguration::default(), CaptureProfile::adaptive())
            .await
            .expect("session");

        assert!(matches!(
            session.send_control(b"{}").await,
            Err(WebRtcError::WrongState(_))
        ));

        session.close().await.expect("closes");
    }

    #[test]
    fn the_factory_names_its_implementation_for_the_diagnostics_panel() {
        assert!(WebRtcFactory.implementation().contains("webrtc-rs"));
    }
}
