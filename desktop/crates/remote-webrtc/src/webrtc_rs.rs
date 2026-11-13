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
//!
//! # The screen track
//!
//! H.264, Constrained Baseline, from the encoder in [`crate::video`] — see
//! that module for the pipeline and for why H.264. Three things here keep it
//! decodable by a browser that did not see the start of the stream:
//!
//! * the next frame is an IDR whenever the connection comes up (or comes
//!   back), whenever the browser sends a PLI or FIR, and every two seconds;
//! * the payload type written on each packet is the one *negotiated* for
//!   H.264 — which is the browser's number when the browser made the offer —
//!   not a constant; and
//! * webrtc-rs's NACK responder keeps the last 1024 packets and resends the
//!   ones the browser reports lost, and its sender reports are what the
//!   browser's reception reports refer back to — which is where
//!   [`PeerSession::congestion`] gets its loss and round trip.

use std::collections::VecDeque;
use std::sync::{
    atomic::{AtomicBool, AtomicU8, Ordering},
    Arc, Mutex,
};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use remote_device::{CaptureProfile, Frame};
use rtc::interceptor::{
    Interceptor, NackResponderBuilder, Packet as InterceptedPacket, Registry, SenderReportBuilder,
    StreamInfo, TaggedPacket,
};
use rtc::media_stream::MediaStreamTrack;
use rtc::peer_connection::configuration::setting_engine::SettingEngine;
use rtc::peer_connection::transport::RTCDtlsRole;
use rtc::rtcp::header::{PacketType, FORMAT_FIR, FORMAT_PLI};
use rtc::rtcp::receiver_report::ReceiverReport;
use rtc::rtcp::reception_report::ReceptionReport;
use rtc::rtcp::sender_report::SenderReport;
use rtc::rtp_transceiver::rtp_sender::{
    RTCPFeedback, RTCRtpCodec, RTCRtpCodecParameters, RTCRtpCodingParameters,
    RTCRtpEncodingParameters, RtpCodecKind,
};
use rtc::sansio::Protocol;
use rtc::shared::error::Error as InterceptorError;
use rtc::shared::time::SystemInstant;
use tokio::task::JoinHandle;
use webrtc::data_channel::{
    DataChannel, DataChannelEvent, RTCDataChannelInit, RTCDataChannelState,
};
use webrtc::media_stream::track_local::static_rtp::TrackLocalStaticRTP;
use webrtc::media_stream::track_local::{TrackLocal, TrackLocalEvent};
use webrtc::peer_connection::{
    MediaEngine, PeerConnection, PeerConnectionBuilder, PeerConnectionEventHandler,
    RTCConfigurationBuilder, RTCIceCandidateInit, RTCIceServer, RTCPeerConnectionIceEvent,
    RTCPeerConnectionState, RTCSessionDescription,
};
use webrtc::rtp_transceiver::RtpSender;

use crate::audio::{
    AudioChunk, AudioSink, PcmuEncoder, AUDIO_CLOCK_RATE, PACKET_SAMPLES, PCMU_MIME_TYPE,
};
use crate::video::rtp::H264Packetizer;
use crate::video::{CodecSource, H264Encoder, H264_FMTP, H264_MIME_TYPE, VIDEO_CLOCK_RATE};
use crate::{
    Congestion, ControlSink, IceConfiguration, PeerSession, PeerSessionFactory, PeerState,
    WebRtcError,
};

/// The collaboration channel Browser V1 already uses.
const DATA_CHANNEL_LABEL: &str = "aicountly-remote";

/// The control channel. See [`remote_protocol::CONTROL_CHANNEL_LABEL`].
const CONTROL_CHANNEL_LABEL: &str = remote_protocol::CONTROL_CHANNEL_LABEL;

/// The payload type the agent registers H.264 under, and so offers it under
/// when it makes the offer.
///
/// The number webrtc-rs's default codec list already gives this exact
/// configuration, so registering ours first replaces that entry rather than
/// adding a second one. When the browser makes the offer its own number is
/// used instead; see [`choose_h264_payload_type`].
const H264_PAYLOAD_TYPE: u8 = 125;

/// The SSRC the screen track is sent on.
const VIDEO_SSRC: u32 = 0x4149_434F;

/// The SSRC the sound track is sent on.
const AUDIO_SSRC: u32 = 0x4149_4153;

/// How many chunks of captured sound may wait for the sender.
///
/// A sound card hands over a chunk every ten milliseconds or so, so this is
/// about half a second. Past it a chunk is dropped rather than queued: sound
/// that arrives late is worse than a gap in it.
const AUDIO_QUEUE: usize = 48;

/// How long a silence has to be before the next packet is the start of a new
/// burst of sound, with the marker bit and a timestamp that says how long the
/// silence was.
///
/// A loopback capture delivers nothing while the computer plays nothing.
const AUDIO_GAP: Duration = Duration::from_millis(200);

/// How long the RTCP watcher waits on the track before letting go of it and
/// looking again.
///
/// The track's event receiver sits behind a lock that webrtc-rs also takes to
/// bind and unbind the track; holding it indefinitely while waiting for a
/// packet would stall a renegotiation or a close until the browser next sent
/// one. A fifth of a second is short enough for that never to be noticed and
/// long enough to cost nothing.
const FEEDBACK_POLL: Duration = Duration::from_millis(200);

/// How many inbound control messages may queue before the oldest are dropped.
///
/// A peer that floods the control channel must not grow this without limit.
/// The gate rejects what it does not like, but only after it has been read, so
/// the ceiling has to be here.
const MAX_QUEUED_CONTROL_MESSAGES: usize = 1024;

/// Builds webrtc-rs sessions.
///
/// Every screen track it builds encodes with the codec in one
/// [`CodecSource`] — the one Cisco's binary is loaded into
/// (`video::cisco::CodecManager::source`). [`WebRtcFactory::default`] has an
/// empty one: sessions it builds connect, and their screen tracks report the
/// codec as not installed.
#[derive(Debug, Clone, Default)]
pub struct WebRtcFactory {
    codec: CodecSource,
}

impl WebRtcFactory {
    /// A factory whose sessions encode with whatever `codec` holds.
    #[must_use]
    pub fn new(codec: CodecSource) -> Self {
        Self { codec }
    }
}

impl PeerSessionFactory for WebRtcFactory {
    type Session = WebRtcSession;

    async fn create(
        &self,
        ice: IceConfiguration,
        profile: CaptureProfile,
    ) -> Result<Self::Session, WebRtcError> {
        WebRtcSession::new(ice, profile, Some(self.codec.clone()), "share").await
    }

    async fn create_control_only(
        &self,
        ice: IceConfiguration,
    ) -> Result<Self::Session, WebRtcError> {
        WebRtcSession::new(ice, CaptureProfile::adaptive(), None, "companion").await
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
    /// Which of the agent's connections this is: `"share"` for the screen
    /// session, `"companion"` for a control-only one. Only ever used to tag
    /// log lines — the agent runs both at once and the library's own lines
    /// name neither, so without this a log cannot say which connection
    /// connected, which one carried a channel, and which one failed.
    role: &'static str,
    /// The connection state, as [`PeerState`] discriminants.
    connection: AtomicU8,
    /// Local ICE candidates the signalling client has not sent yet.
    local_candidates: Mutex<Vec<serde_json::Value>>,
    /// Control messages that have arrived and not been handed over. Filled by
    /// the control channel's reader task while no [`ControlSink`] is set.
    inbox: Mutex<Vec<Vec<u8>>>,
    /// Where the control channel's reader task hands each message the moment
    /// it arrives, once the host has asked for that. `None` leaves them in
    /// `inbox` for `receive_control`.
    control_sink: Mutex<Option<ControlSink>>,
    /// A data channel the peer opened, waiting to be adopted.
    inbound_control: Mutex<Option<Arc<dyn DataChannel>>>,
    inbound_collaboration: Mutex<Option<Arc<dyn DataChannel>>>,
    /// The next frame should be an IDR: the connection just came up, the
    /// browser lost a picture, or the host asked. Set from callbacks and the
    /// RTCP watcher; read, and cleared, by the next `send_frame`.
    keyframe_requested: AtomicBool,
    /// What the browser's latest reception report said about the screen
    /// track. `None` until one has arrived.
    link: Mutex<Option<LinkReading>>,
    /// A new remote description may have renumbered the sound codec: the
    /// sound sender reads its payload type again before its next packet.
    sound_renegotiated: AtomicBool,
}

/// One reception report about the screen track, as `congestion()` reports
/// it.
#[derive(Debug, Clone, Copy, PartialEq)]
struct LinkReading {
    /// Fraction of packets lost since the browser's previous report.
    packet_loss: f64,
    /// Round trip through the browser, when its report referred to one of
    /// our sender reports; `None` before the first has reached it.
    round_trip_ms: Option<f64>,
}

/// The connection's event handler. See the module documentation.
#[derive(Clone)]
struct SessionEvents {
    shared: Arc<SharedState>,
}

#[async_trait::async_trait]
impl PeerConnectionEventHandler for SessionEvents {
    async fn on_connection_state_change(&self, state: RTCPeerConnectionState) {
        let state = map_state(state);

        tracing::info!(
            connection = self.shared.role,
            ?state,
            "the peer connection changed state"
        );

        // Whatever was sent before this moment — before DTLS finished, or
        // while ICE was finding its way back — did not reach a decoder that
        // can use it, so the first frame from now on must not depend on it.
        if state == PeerState::Connected {
            self.shared.keyframe_requested.store(true, Ordering::SeqCst);
        }

        self.shared.connection.store(state as u8, Ordering::SeqCst);
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

        tracing::info!(
            connection = self.shared.role,
            %label,
            "the peer opened a data channel"
        );

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
            // Not a guess: a label this build does not know is left alone.
            // Worth a line all the same, because a peer that renamed the
            // control channel looks exactly like one that never opened it.
            other => tracing::warn!(
                connection = self.shared.role,
                label = other,
                "a data channel arrived under a label this build does not use"
            ),
        }
    }
}

/// The screen track and everything that feeds it.
struct VideoSender {
    track: Arc<TrackLocalStaticRTP>,
    sender: Arc<dyn RtpSender>,
    /// `None` only while a frame is being encoded on the blocking pool — or
    /// after a `send_frame` that was cancelled mid-encode, in which case the
    /// next frame builds a new one and starts with an IDR.
    encoder: Option<Box<H264Encoder>>,
    /// Where every encoder for this track gets OpenH264 from.
    codec: CodecSource,
    packetizer: H264Packetizer,
    /// The payload type negotiated for H.264, once negotiation has settled
    /// it. Forgotten whenever a new remote description is applied.
    payload_type: Option<u8>,
    /// Watches the track's RTCP for PLI and FIR. Aborted on close and drop.
    feedback: JoinHandle<()>,
}

/// The sound track and the task that feeds it.
struct AudioSender {
    /// Where captured sound is handed in, from any thread.
    queue: tokio::sync::mpsc::Sender<AudioChunk>,
    /// Encodes, packetises and sends. Aborted on close and drop.
    task: JoinHandle<()>,
}

/// One peer connection.
pub struct WebRtcSession {
    peer: Arc<dyn PeerConnection>,
    /// The screen track. `None` for a control-only session, which has none.
    video: Option<VideoSender>,
    /// The sound track. Beside the screen track, and absent with it.
    audio: Option<AudioSender>,
    collaboration: Option<Arc<dyn DataChannel>>,
    control: Option<Arc<dyn DataChannel>>,
    /// One task per data channel, reading it for as long as it is open. See
    /// [`read_control_channel`] for why reading is a task and not a call.
    /// Aborted on close and drop.
    readers: Vec<JoinHandle<()>>,
    shared: Arc<SharedState>,
    profile: CaptureProfile,
}

impl WebRtcSession {
    /// A session, with a screen track encoded from `video`'s codec, or —
    /// `None` — a control-only one with no track at all.
    async fn new(
        ice: IceConfiguration,
        profile: CaptureProfile,
        video: Option<CodecSource>,
        role: &'static str,
    ) -> Result<Self, WebRtcError> {
        let shared = Arc::new(SharedState {
            role,
            ..SharedState::default()
        });

        let configuration = RTCConfigurationBuilder::default()
            .with_ice_servers(Self::ice_servers(&ice))
            .build();

        // H.264 first, under our own payload type, so that what the screen
        // track offers is this registration and not whichever entry of the
        // library's default list happens to match; then the default codec
        // set, which is what a browser offers and answers with. Registering
        // only H.264 would produce a connection no browser could negotiate
        // audio on later, and interoperating with browser WebRTC is the only
        // interoperability requirement there is.
        let mut media = MediaEngine::default();
        media
            .register_codec(
                RTCRtpCodecParameters {
                    rtp_codec: h264_codec(video_feedback()),
                    payload_type: H264_PAYLOAD_TYPE,
                },
                RtpCodecKind::Video,
            )
            .map_err(|error| WebRtcError::Setup(error.to_string()))?;
        media
            .register_default_codecs()
            .map_err(|error| WebRtcError::Setup(error.to_string()))?;

        // Three interceptors, all for the sending side. The NACK responder
        // keeps recent packets and resends the ones the browser reports
        // missing — without it every lost packet is a broken picture until the
        // next IDR. Sender reports are what the browser's reception reports
        // refer back to, which is how a round trip is measured at all. The
        // forwarder, outermost so it sees inbound RTCP first, is what lets a
        // PLI, a FIR or a reception report reach the session. The codecs
        // already carry the `nack`, `nack pli` and `ccm fir` feedback that
        // tells the browser it may ask.
        let interceptors = Registry::new()
            .with(NackResponderBuilder::new().build())
            .with(SenderReportBuilder::new().build())
            .with(FeedbackForwarder::wrap);

        let peer = PeerConnectionBuilder::new()
            .with_configuration(configuration)
            .with_media_engine(media)
            .with_interceptor_registry(interceptors)
            .with_setting_engine(Self::setting_engine()?)
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

        let video = match video {
            Some(codec) => Some(Self::add_screen_track(&peer, &shared, profile, codec).await?),
            None => None,
        };

        // A session that shows this computer's screen can carry its sound
        // too. The track is always offered, so that switching the sound on
        // mid-session needs no renegotiation; whether anything is ever put on
        // it is the host's decision, and the person at the computer's.
        let audio = match video {
            Some(_) => Some(Self::add_sound_track(&peer, &shared).await?),
            None => None,
        };

        Ok(Self {
            peer,
            video,
            audio,
            collaboration: None,
            control: None,
            readers: Vec::new(),
            shared,
            profile,
        })
    }

    /// The screen track: one H.264 encoding on a fixed SSRC, and the task
    /// that listens for the browser asking for a keyframe.
    async fn add_screen_track(
        peer: &Arc<dyn PeerConnection>,
        shared: &Arc<SharedState>,
        profile: CaptureProfile,
        codec: CodecSource,
    ) -> Result<VideoSender, WebRtcError> {
        let track = Arc::new(TrackLocalStaticRTP::new(MediaStreamTrack::new(
            "aicountly-remote".to_owned(),
            "screen".to_owned(),
            "Shared screen".to_owned(),
            RtpCodecKind::Video,
            vec![RTCRtpEncodingParameters {
                rtp_coding_parameters: RTCRtpCodingParameters {
                    ssrc: Some(VIDEO_SSRC),
                    ..Default::default()
                },
                codec: h264_codec(vec![]),
                ..Default::default()
            }],
        )));

        let sender = peer
            .add_track(Arc::clone(&track) as Arc<dyn TrackLocal>)
            .await
            .map_err(|error| WebRtcError::Media(error.to_string()))?;

        let feedback = tokio::spawn(watch_feedback(Arc::clone(&track), Arc::clone(shared)));

        Ok(VideoSender {
            track,
            sender,
            encoder: Some(Box::new(H264Encoder::new(profile, codec.clone()))),
            codec,
            packetizer: H264Packetizer::new(VIDEO_SSRC),
            payload_type: None,
            feedback,
        })
    }

    /// The sound track: G.711 on a fixed SSRC, in the same media stream as
    /// the screen so a browser plays them together, and the task that sends
    /// what the capture hands in.
    async fn add_sound_track(
        peer: &Arc<dyn PeerConnection>,
        shared: &Arc<SharedState>,
    ) -> Result<AudioSender, WebRtcError> {
        let track = Arc::new(TrackLocalStaticRTP::new(MediaStreamTrack::new(
            "aicountly-remote".to_owned(),
            "sound".to_owned(),
            "Computer sound".to_owned(),
            RtpCodecKind::Audio,
            vec![RTCRtpEncodingParameters {
                rtp_coding_parameters: RTCRtpCodingParameters {
                    ssrc: Some(AUDIO_SSRC),
                    ..Default::default()
                },
                codec: pcmu_codec(),
                ..Default::default()
            }],
        )));

        let sender = peer
            .add_track(Arc::clone(&track) as Arc<dyn TrackLocal>)
            .await
            .map_err(|error| WebRtcError::Media(error.to_string()))?;

        let (queue, chunks) = tokio::sync::mpsc::channel(AUDIO_QUEUE);
        let task = tokio::spawn(send_sound(track, sender, Arc::clone(shared), chunks));

        Ok(AudioSender { queue, task })
    }

    /// A new remote description may have renumbered the codecs.
    fn forget_payload_type(&mut self) {
        if let Some(video) = self.video.as_mut() {
            video.payload_type = None;
        }

        self.shared.sound_renegotiated.store(true, Ordering::SeqCst);
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
    /// than in the callback keeps every decision on the agent's own task. Each
    /// channel gets its reader the moment it is adopted.
    fn adopt_inbound_channels(&mut self) {
        if self.control.is_none() {
            let arrived = self
                .shared
                .inbound_control
                .lock()
                .ok()
                .and_then(|mut slot| slot.take());

            if let Some(channel) = arrived {
                self.read_control(&channel);
                self.control = Some(channel);
            }
        }

        if self.collaboration.is_none() {
            let arrived = self
                .shared
                .inbound_collaboration
                .lock()
                .ok()
                .and_then(|mut slot| slot.take());

            if let Some(channel) = arrived {
                self.read_collaboration(&channel);
                self.collaboration = Some(channel);
            }
        }
    }

    /// Start reading the control channel. See [`read_control_channel`].
    fn read_control(&mut self, channel: &Arc<dyn DataChannel>) {
        self.readers.push(tokio::spawn(read_control_channel(
            Arc::clone(channel),
            Arc::clone(&self.shared),
        )));
    }

    /// Start reading — and discarding — the collaboration channel. See
    /// [`drain_collaboration_channel`].
    fn read_collaboration(&mut self, channel: &Arc<dyn DataChannel>) {
        self.readers
            .push(tokio::spawn(drain_collaboration_channel(Arc::clone(
                channel,
            ))));
    }

    /// Stop every channel reader. They hold the channels, so this comes
    /// before the channels and the connection are closed.
    fn stop_readers(&mut self) {
        for reader in self.readers.drain(..) {
            reader.abort();
        }
    }

    /// **Answer an offer as the DTLS server, so a data channel can open.**
    ///
    /// Without this, control never worked through a control companion, and
    /// could not have: the agent's data channel was refused before a single
    /// input event was sent.
    ///
    /// A browser offers `a=setup:actpass`, leaving the role to the answerer.
    /// `rtc`'s `derive_role` then falls through to `DEFAULT_DTLS_ROLE_ANSWER`,
    /// which is `Client` — and its SCTP transport builds a *client* endpoint
    /// with **no server config**:
    ///
    /// ```text
    ///   if dtls_role == RTCDtlsRole::Client {
    ///       sctp::Endpoint::new(.., None)                      // cannot accept an association
    ///   } else {
    ///       sctp::Endpoint::new(.., Some(ServerConfig::new(..)))
    ///   }
    /// ```
    ///
    /// WebRTC data channels are established by SCTP simultaneous open — the
    /// same file says so — so the browser sends an `INIT` whichever DTLS role
    /// it holds. An endpoint with no server config refuses it:
    ///
    /// ```text
    ///   WARN rtc_sctp::endpoint: refusing first packet due to empty server_config
    ///   WARN rtc::peer_connection::handler: DtlsHandler.handle_read got error: Alert is Fatal or Close Notify
    ///   INFO rtc_ice::agent: [controlled]: Setting new connection state: Disconnected
    /// ```
    ///
    /// That is this agent answering, which is **always** what a companion
    /// does: it never makes the first offer, so it never took the one path
    /// that worked. A hosted session offers, the browser answers
    /// `a=setup:active`, `derive_role` returns `Server` on its first arm, and
    /// the endpoint gets a server config — which is why control works there
    /// and nowhere else.
    ///
    /// Answering as the server puts the companion on that same working path.
    /// It changes nothing when this agent offers: `derive_role` takes the
    /// remote's explicit role first and never reaches this setting.
    fn setting_engine() -> Result<SettingEngine, WebRtcError> {
        let mut engine = SettingEngine::default();

        engine
            .set_answering_dtls_role(RTCDtlsRole::Server)
            .map_err(|error| WebRtcError::Setup(error.to_string()))?;

        Ok(engine)
    }

    /// Run `work` on the screen track's encoder and send what it produces.
    ///
    /// The common half of sending a new frame and repeating the last one:
    /// the state check, the negotiated payload type, the encode on the
    /// blocking pool, the packetising and the write.
    async fn encode_and_send<W>(&mut self, work: W) -> Result<(), WebRtcError>
    where
        W: FnOnce(
                &mut H264Encoder,
                Instant,
            )
                -> Result<Option<crate::video::EncodedFrame>, crate::video::VideoError>
            + Send
            + 'static,
    {
        let state = self.state();

        // A control-only session has no track to put a frame on, and says so
        // rather than dropping it as though it had been sent.
        let Some(video) = self.video.as_mut() else {
            return Err(WebRtcError::Media(
                "this is a control-only connection with no video track".into(),
            ));
        };

        match state {
            PeerState::Connected => {}
            // Gone for good: the host should stop capturing, and is told so.
            PeerState::Failed | PeerState::Closed => return Err(WebRtcError::WrongState(state)),
            // Not up yet, or briefly lost. Nobody can receive this frame, so
            // encoding it would only spend a core; the first frame once the
            // connection is up is an IDR either way.
            PeerState::New | PeerState::Connecting | PeerState::Disconnected => return Ok(()),
        }

        let payload_type = match video.payload_type {
            Some(payload_type) => payload_type,
            None => {
                let parameters = video
                    .sender
                    .get_parameters()
                    .await
                    .map_err(|error| WebRtcError::Media(error.to_string()))?;

                let payload_type = choose_h264_payload_type(&parameters.rtp_parameters.codecs)
                    .ok_or_else(|| {
                        WebRtcError::Media("the viewer's browser did not accept H.264 video".into())
                    })?;

                video.payload_type = Some(payload_type);
                payload_type
            }
        };

        let mut encoder = video
            .encoder
            .take()
            .unwrap_or_else(|| Box::new(H264Encoder::new(self.profile, video.codec.clone())));

        if self.shared.keyframe_requested.swap(false, Ordering::SeqCst) {
            encoder.request_keyframe();
        }

        // The encode runs on tokio's blocking pool, not on the session's own
        // thread. The host drives every session from one current-thread
        // runtime, and that thread is also the one webrtc-rs's driver reads
        // the sockets on — so an encode in place would hold up every control
        // message, every ICE check and every data-channel chunk for as long as
        // it took.
        let now = Instant::now();
        let (encoder, encoded) = tokio::task::spawn_blocking(move || {
            let encoded = work(&mut encoder, now);

            (encoder, encoded)
        })
        .await
        .map_err(|error| WebRtcError::Media(format!("the encoder stopped: {error}")))?;

        video.encoder = Some(encoder);

        let Some(encoded) = encoded.map_err(|error| WebRtcError::Media(error.to_string()))? else {
            // Paced out or skipped by rate control: nothing to send, and
            // nothing wrong.
            return Ok(());
        };

        let packets = video
            .packetizer
            .packetize(encoded.data.into(), payload_type, encoded.media_time_us)
            .map_err(WebRtcError::Media)?;

        // Every packet is offered even if one is refused: a frame with a
        // missing packet is one the NACK path can still mend, a frame cut off
        // halfway is not.
        let mut failure = None;
        for packet in packets {
            if let Err(error) = video.track.write_rtp(packet).await {
                failure.get_or_insert_with(|| error.to_string());
            }
        }

        failure.map_or(Ok(()), |error| Err(WebRtcError::Media(error)))
    }

    async fn open_channels(&mut self) -> Result<(), WebRtcError> {
        // A second offer on the same connection — an ICE restart — carries
        // the channels it already has. Opening another pair would leave the
        // browser with two control channels and this end reading the wrong
        // one.
        if self.control.is_some() && self.collaboration.is_some() {
            return Ok(());
        }

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

        self.read_collaboration(&collaboration);
        self.read_control(&control);

        self.collaboration = Some(collaboration);
        self.control = Some(control);

        Ok(())
    }
}

/// Read the control channel for as long as it is open, handing each message
/// on the moment it arrives.
///
/// **A task, because the library's read waits.** `DataChannel::poll` in
/// webrtc-rs 0.20 is `recv().await` on the channel's event queue: it returns
/// when the *next* event arrives, not when the queue is empty. The session
/// used to call it from `receive_control`, in a loop, on the agent's own
/// task — so the first read after the channel opened did not come back until
/// a message did, and then went straight back to waiting for the next one,
/// up to 1024 of them. Everything else that task does stopped with it: the
/// picture (a hosted session froze the moment its control channel opened),
/// the question to the API about who is asking for control (so the consent
/// dialog never appeared), ICE candidates, the local-input override — and the
/// input itself, which sat in the inbox until enough of it had piled up for
/// the read to give up. Both ends said "controlling", and nothing moved.
///
/// Read here instead, a message either goes straight to the [`ControlSink`]
/// the host installed — applied while the agent's own task is busy encoding
/// a frame or waiting on the API — or waits in the inbox for the next
/// `receive_control`, which now only empties it and never waits.
async fn read_control_channel(channel: Arc<dyn DataChannel>, shared: Arc<SharedState>) {
    while let Some(event) = channel.poll().await {
        let message = match event {
            DataChannelEvent::OnMessage(message) => message,
            DataChannelEvent::OnClose => break,
            _ => continue,
        };

        // Checked before anything is copied.
        if message.data.len() > remote_protocol::MAX_MESSAGE_BYTES {
            continue;
        }

        let sink = shared
            .control_sink
            .lock()
            .ok()
            .and_then(|sink| sink.as_ref().map(Arc::clone));

        match sink {
            Some(sink) => sink(message.data.to_vec()),
            None => {
                if let Ok(mut inbox) = shared.inbox.lock() {
                    // A peer that floods the channel must not grow this
                    // without limit; what does not fit is dropped, and the
                    // gate's sequence check takes the gap in its stride.
                    if inbox.len() < MAX_QUEUED_CONTROL_MESSAGES {
                        inbox.push(message.data.to_vec());
                    }
                }
            }
        }
    }
}

/// Read the collaboration channel and discard what arrives.
///
/// The agent does not act on chat, pointers or annotations — but a channel
/// nobody reads is not free. webrtc-rs queues at most 256 events per channel
/// and logs an error for every one after that, so a viewer moving their
/// pointer over the picture filled the queue in a few seconds and the capped
/// log with the same line from then on.
async fn drain_collaboration_channel(channel: Arc<dyn DataChannel>) {
    while let Some(event) = channel.poll().await {
        if matches!(event, DataChannelEvent::OnClose) {
            break;
        }
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

/// The screen track's codec: H.264, Constrained Baseline, packetization
/// mode 1. See [`H264_FMTP`].
fn h264_codec(rtcp_feedback: Vec<RTCPFeedback>) -> RTCRtpCodec {
    RTCRtpCodec {
        mime_type: H264_MIME_TYPE.to_owned(),
        clock_rate: VIDEO_CLOCK_RATE,
        channels: 0,
        sdp_fmtp_line: H264_FMTP.to_owned(),
        rtcp_feedback,
    }
}

/// The sound track's codec: G.711 µ-law, 8 kHz, one channel. See
/// [`crate::audio`] for why.
fn pcmu_codec() -> RTCRtpCodec {
    RTCRtpCodec {
        mime_type: PCMU_MIME_TYPE.to_owned(),
        clock_rate: AUDIO_CLOCK_RATE,
        channels: 0,
        sdp_fmtp_line: String::new(),
        rtcp_feedback: vec![],
    }
}

/// The payload type to send G.711 under, from the negotiated codecs. Zero in
/// every SDP there has ever been — but read rather than assumed, because a
/// packet under a number nobody negotiated is dropped after `write_rtp` has
/// said `Ok`, exactly as for the screen track.
fn choose_pcmu_payload_type(codecs: &[RTCRtpCodecParameters]) -> Option<u8> {
    codecs
        .iter()
        .find(|codec| {
            codec
                .rtp_codec
                .mime_type
                .eq_ignore_ascii_case(PCMU_MIME_TYPE)
        })
        .map(|codec| codec.payload_type)
}

/// Numbers and stamps the sound track's packets.
struct SoundPacketizer {
    sequencer: Box<dyn rtc::rtp::sequence::Sequencer>,
    timestamp: u32,
    last_at: Option<Instant>,
}

impl SoundPacketizer {
    fn new() -> Self {
        Self {
            sequencer: Box::new(rtc::rtp::sequence::new_random_sequencer()),
            // The clock: where it starts says nothing, as RFC 3550 asks.
            timestamp: SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .map(|elapsed| elapsed.subsec_nanos())
                .unwrap_or_default(),
            last_at: None,
        }
    }

    /// The packet for one 20 ms payload, handed over at `now`.
    ///
    /// Consecutive packets are one packet's worth of samples apart. After a
    /// silence the timestamp moves on by as long as the silence lasted and
    /// the marker bit says a new burst begins, so the browser plays the gap
    /// as a gap instead of stretching what came before across it.
    fn packet(
        &mut self,
        payload: Vec<u8>,
        payload_type: u8,
        now: Instant,
    ) -> rtc::rtp::packet::Packet {
        let step = payload.len() as u32;

        let marker = match self.last_at {
            Some(last) => {
                let gap = now.saturating_duration_since(last);

                if gap > AUDIO_GAP {
                    let ticks = gap.as_micros() as u64 * u64::from(AUDIO_CLOCK_RATE) / 1_000_000;
                    self.timestamp = self.timestamp.wrapping_add(ticks as u32);

                    true
                } else {
                    self.timestamp = self.timestamp.wrapping_add(step);

                    false
                }
            }
            None => true,
        };

        self.last_at = Some(now);

        rtc::rtp::packet::Packet {
            header: rtc::rtp::header::Header {
                version: 2,
                marker,
                payload_type,
                sequence_number: self.sequencer.next_sequence_number(),
                timestamp: self.timestamp,
                ssrc: AUDIO_SSRC,
                ..Default::default()
            },
            payload: bytes::Bytes::from(payload),
        }
    }
}

/// Send the computer's sound: encode each chunk the capture hands in and put
/// the packets on the sound track.
///
/// A task of its own, fed through a queue, so sound does not wait for the
/// host's loop — which encodes a frame of video at a time — and the capture's
/// thread never waits for the network.
async fn send_sound(
    track: Arc<TrackLocalStaticRTP>,
    sender: Arc<dyn RtpSender>,
    shared: Arc<SharedState>,
    mut chunks: tokio::sync::mpsc::Receiver<AudioChunk>,
) {
    let mut encoder = PcmuEncoder::new();
    let mut packets = SoundPacketizer::new();
    let mut payload_type: Option<u8> = None;

    while let Some(chunk) = chunks.recv().await {
        // Nobody can hear it: not up yet, or lost. The partly filled packet
        // goes too, so what is sent first afterwards is not half old sound.
        if state_from_u8(shared.connection.load(Ordering::SeqCst)) != PeerState::Connected {
            encoder.reset();

            continue;
        }

        if shared.sound_renegotiated.swap(false, Ordering::SeqCst) {
            payload_type = None;
        }

        if payload_type.is_none() {
            payload_type = match sender.get_parameters().await {
                Ok(parameters) => choose_pcmu_payload_type(&parameters.rtp_parameters.codecs),
                Err(_) => None,
            };
        }

        // The viewer's browser did not take G.711. Every browser does; if one
        // ever does not, nothing is sent under a number it never agreed to.
        let Some(payload_type) = payload_type else {
            continue;
        };

        let now = Instant::now();

        for payload in encoder.push(&chunk) {
            debug_assert_eq!(payload.len(), PACKET_SAMPLES);

            let _ = track
                .write_rtp(packets.packet(payload, payload_type, now))
                .await;
        }
    }
}

/// The feedback the screen track invites: NACK for a lost packet, PLI and FIR
/// for a lost picture, and REMB, which browsers send alongside them. The same
/// set webrtc-rs's defaults and every browser use.
fn video_feedback() -> Vec<RTCPFeedback> {
    [
        ("goog-remb", ""),
        ("ccm", "fir"),
        ("nack", ""),
        ("nack", "pli"),
    ]
    .into_iter()
    .map(|(typ, parameter)| RTCPFeedback {
        typ: typ.to_owned(),
        parameter: parameter.to_owned(),
    })
    .collect()
}

/// Listen to the screen track's RTCP: a browser asking for a keyframe, and
/// the browser's reception reports, which are what `congestion()` reads.
///
/// A task of its own because the track delivers feedback whether or not
/// frames are being sent — a still screen sends nothing for minutes — and
/// webrtc-rs's queue for it is bounded: left undrained it fills, and the
/// driver logs an error for every report after that. What reaches it is what
/// [`FeedbackForwarder`] lets through.
async fn watch_feedback(track: Arc<TrackLocalStaticRTP>, shared: Arc<SharedState>) {
    loop {
        match tokio::time::timeout(FEEDBACK_POLL, track.poll()).await {
            Ok(Some(event)) => {
                let TrackLocalEvent::OnRtcpPacket(packets) = event;

                for packet in &packets {
                    if asks_for_keyframe(packet.as_ref()) {
                        shared.keyframe_requested.store(true, Ordering::SeqCst);
                    }

                    if let Some(report) = report_on(packet.as_ref(), VIDEO_SSRC) {
                        let reading = link_reading(&report, SystemTime::now());

                        if let Ok(mut link) = shared.link.lock() {
                            *link = Some(reading);
                        }
                    }
                }
            }
            // Not bound yet — negotiation has not finished — or unbound
            // again. Look later.
            Ok(None) => tokio::time::sleep(FEEDBACK_POLL).await,
            // Nothing arrived; the lock is let go, and taken again.
            Err(_) => {}
        }
    }
}

/// The reception report a receiver or sender report carries about `ssrc`.
fn report_on(packet: &dyn rtc::rtcp::Packet, ssrc: u32) -> Option<ReceptionReport> {
    let any = packet.as_any();

    let reports = match any.downcast_ref::<ReceiverReport>() {
        Some(receiver) => &receiver.reports,
        None => &any.downcast_ref::<SenderReport>()?.reports,
    };

    reports.iter().find(|report| report.ssrc == ssrc).cloned()
}

/// Loss and round trip from one reception report, received at `now`.
///
/// RFC 3550 §6.4.1: the report echoes the middle 32 bits of the NTP time in
/// our last sender report (LSR) and how long the browser held it (DLSR), both
/// in 1/65536 s; the round trip is the time now, in the same units, less
/// both. The sender reports are stamped from the wall clock, so this is too.
/// A report that refers to no sender report yet, or a figure that could only
/// come from the clock moving underneath, gives no round trip at all rather
/// than a wrong one.
fn link_reading(report: &ReceptionReport, now: SystemTime) -> LinkReading {
    let packet_loss = f64::from(report.fraction_lost) / 256.0;

    let round_trip_ms = (report.last_sender_report != 0)
        .then(|| now.duration_since(UNIX_EPOCH).ok())
        .flatten()
        .map(|since_epoch| {
            let middle = (SystemInstant::unix2ntp(since_epoch) >> 16) as u32;
            let units = middle
                .wrapping_sub(report.last_sender_report)
                .wrapping_sub(report.delay);

            f64::from(units) * 1000.0 / 65536.0
        })
        .filter(|milliseconds| *milliseconds < 60_000.0);

    LinkReading {
        packet_loss,
        round_trip_ms,
    }
}

/// The outermost interceptor: hands every PLI, FIR and reception report the
/// browser sends about the screen track up to the application, as well as
/// passing it on down the chain.
///
/// It has to exist because of where RTCP ends in webrtc-rs 0.20. Every
/// interceptor chain bottoms out in `NoopInterceptor`, which drops inbound
/// RTCP by design ("if any RTCP packet needs to be forwarded to the
/// PeerConnection, add an interceptor that forwards it"), and none of the
/// stock interceptors forwards anything. Without this:
///
/// * the track's `poll` — documented as where PLI and FIR arrive — never
///   yields one, and a browser that lost a picture waits for the next
///   periodic IDR: up to two seconds of frozen screen per lost packet. The
///   loopback test in this file is the one that found that out;
/// * the connection's statistics never learn what the browser reported —
///   the hook that would feed them is never called in this release — so loss
///   and round trip read as zero for ever, and congestion feedback would say
///   "step up" on any link at all.
///
/// Each packet goes up on its own, because webrtc-rs routes what comes up by
/// the first packet's destination: an empty receiver report, which browsers
/// send before media flows, has none, and would take a PLI in the same
/// compound packet down with it.
struct FeedbackForwarder<P> {
    next: P,
    forwarded: VecDeque<TaggedPacket>,
}

impl<P> FeedbackForwarder<P> {
    fn wrap(next: P) -> Self {
        Self {
            next,
            forwarded: VecDeque::new(),
        }
    }
}

impl<P: Interceptor> Protocol<TaggedPacket, TaggedPacket, ()> for FeedbackForwarder<P> {
    type Rout = TaggedPacket;
    type Wout = TaggedPacket;
    type Eout = ();
    type Error = InterceptorError;
    type Time = Instant;

    fn handle_read(&mut self, message: TaggedPacket) -> Result<(), Self::Error> {
        if let InterceptedPacket::Rtcp(packets) = &message.message {
            let wanted = packets.iter().filter(|packet| {
                asks_for_keyframe(packet.as_ref())
                    || report_on(packet.as_ref(), VIDEO_SSRC).is_some()
            });

            for packet in wanted {
                self.forwarded.push_back(TaggedPacket {
                    now: message.now,
                    transport: message.transport,
                    message: InterceptedPacket::Rtcp(vec![packet.cloned()]),
                });
            }
        }

        self.next.handle_read(message)
    }

    fn poll_read(&mut self) -> Option<Self::Rout> {
        self.forwarded.pop_front().or_else(|| self.next.poll_read())
    }

    fn handle_write(&mut self, message: TaggedPacket) -> Result<(), Self::Error> {
        self.next.handle_write(message)
    }

    fn poll_write(&mut self) -> Option<Self::Wout> {
        self.next.poll_write()
    }

    fn handle_event(&mut self, event: ()) -> Result<(), Self::Error> {
        self.next.handle_event(event)
    }

    fn poll_event(&mut self) -> Option<Self::Eout> {
        self.next.poll_event()
    }

    fn handle_timeout(&mut self, now: Instant) -> Result<(), Self::Error> {
        self.next.handle_timeout(now)
    }

    fn poll_timeout(&mut self) -> Option<Instant> {
        self.next.poll_timeout()
    }

    fn close(&mut self) -> Result<(), Self::Error> {
        self.forwarded.clear();
        self.next.close()
    }
}

impl<P: Interceptor> Interceptor for FeedbackForwarder<P> {
    fn bind_local_stream(&mut self, info: &StreamInfo) {
        self.next.bind_local_stream(info);
    }

    fn unbind_local_stream(&mut self, info: &StreamInfo) {
        self.next.unbind_local_stream(info);
    }

    fn bind_remote_stream(&mut self, info: &StreamInfo) {
        self.next.bind_remote_stream(info);
    }

    fn unbind_remote_stream(&mut self, info: &StreamInfo) {
        self.next.unbind_remote_stream(info);
    }
}

/// A Picture Loss Indication or a Full Intra Request: the browser cannot
/// decode what it has and needs an IDR to start again from.
fn asks_for_keyframe(packet: &dyn rtc::rtcp::Packet) -> bool {
    let header = packet.header();

    header.packet_type == PacketType::PayloadSpecificFeedback
        && matches!(header.count, FORMAT_PLI | FORMAT_FIR)
}

/// The payload type to send H.264 under, from the negotiated codecs.
///
/// webrtc-rs checks every packet's payload type against the negotiated list
/// and drops one that is not on it — in the driver, after `write_rtp` has
/// returned `Ok` — so a constant here would be a stream that silently never
/// arrives whenever the browser made the offer and numbered H.264 itself.
///
/// The best match is exactly what the track asked for: packetization mode 1,
/// Constrained Baseline (`42e0…`, the profile-iop with constraint_set0 and
/// set1). Failing that, any Baseline-family entry; failing that, any H.264
/// with mode 1, since a Constrained Baseline stream is also valid Main and
/// High. Mode 0 never: it forbids the FU-A fragments a keyframe needs.
fn choose_h264_payload_type(codecs: &[RTCRtpCodecParameters]) -> Option<u8> {
    let rank = |codec: &RTCRtpCodecParameters| -> Option<u8> {
        if !codec
            .rtp_codec
            .mime_type
            .eq_ignore_ascii_case(H264_MIME_TYPE)
        {
            return None;
        }

        let parameter = |name: &str| {
            codec
                .rtp_codec
                .sdp_fmtp_line
                .split(';')
                .filter_map(|pair| pair.trim().split_once('='))
                .find(|(key, _)| key.trim().eq_ignore_ascii_case(name))
                .map(|(_, value)| value.trim().to_ascii_lowercase())
        };

        if parameter("packetization-mode").as_deref() != Some("1") {
            return None;
        }

        // RFC 6184: an absent profile-level-id means 42000a, Baseline.
        let profile = parameter("profile-level-id").unwrap_or_else(|| "42000a".to_owned());

        Some(if profile.starts_with("42e0") {
            0
        } else if profile.starts_with("42") {
            1
        } else {
            2
        })
    };

    codecs
        .iter()
        .filter_map(|codec| rank(codec).map(|rank| (rank, codec.payload_type)))
        .min_by_key(|(rank, _)| *rank)
        .map(|(_, payload_type)| payload_type)
}

impl PeerSession for WebRtcSession {
    async fn create_offer(&mut self) -> Result<serde_json::Value, WebRtcError> {
        tracing::info!(connection = self.shared.role, "offering");

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

        // A companion only ever answers, so this line is the proof that a
        // viewer's offer reached it at all — the half the log never showed.
        tracing::info!(connection = self.shared.role, "answering an offer");

        self.forget_payload_type();

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

        self.forget_payload_type();

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
        self.encode_and_send(move |encoder, now| {
            let encoded = encoder.encode(&frame, now);
            // Dropped here, the moment it has been encoded.
            drop(frame);

            encoded
        })
        .await
    }

    async fn refresh_picture(&mut self) -> Result<(), WebRtcError> {
        // Only when somebody is waiting for a keyframe. Otherwise a still
        // screen is exactly what the viewer already has.
        if self.video.is_none() || !self.shared.keyframe_requested.load(Ordering::SeqCst) {
            return Ok(());
        }

        self.encode_and_send(|encoder, now| encoder.encode_again(now))
            .await
    }

    fn request_keyframe(&mut self) {
        self.shared.keyframe_requested.store(true, Ordering::SeqCst);
    }

    fn set_capture_profile(&mut self, profile: CaptureProfile) {
        self.profile = profile;

        if let Some(encoder) = self.video.as_mut().and_then(|video| video.encoder.as_mut()) {
            encoder.set_profile(profile);
        }
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

    fn audio_sink(&self) -> Option<AudioSink> {
        let queue = self.audio.as_ref()?.queue.clone();

        // `try_send`: a full queue drops the chunk, and never makes the
        // capture's thread wait.
        Some(AudioSink::new(move |chunk| queue.try_send(chunk).is_ok()))
    }

    fn set_control_sink(&mut self, sink: ControlSink) {
        if let Ok(mut held) = self.shared.control_sink.lock() {
            *held = Some(Arc::clone(&sink));
        }

        // Anything that arrived before the sink did goes to it now, in order,
        // rather than being left for a `receive_control` the host may no
        // longer call.
        let waiting = self
            .shared
            .inbox
            .lock()
            .map(|mut inbox| std::mem::take(&mut *inbox))
            .unwrap_or_default();

        for bytes in waiting {
            sink(bytes);
        }
    }

    async fn receive_control(&mut self) -> Result<Vec<Vec<u8>>, WebRtcError> {
        // Never waits: the channel's reader task fills the inbox, and this
        // empties it. See `read_control_channel`.
        self.adopt_inbound_channels();

        Ok(self
            .shared
            .inbox
            .lock()
            .map(|mut inbox| std::mem::take(&mut *inbox))
            .unwrap_or_default())
    }

    async fn control_channel_open(&mut self) -> bool {
        self.adopt_inbound_channels();

        let Some(channel) = self.control.clone() else {
            return false;
        };

        matches!(channel.ready_state().await, Ok(RTCDataChannelState::Open))
    }

    fn state(&self) -> PeerState {
        state_from_u8(self.shared.connection.load(Ordering::SeqCst))
    }

    /// The browser's latest reception report about the screen track.
    ///
    /// Read from the report itself, as `FeedbackForwarder` hands it up,
    /// rather than from `get_stats()`: in webrtc-rs 0.20 the statistics are
    /// never updated from inbound reports, so their loss and round trip are
    /// zero whatever the link is doing — and a reading of "no loss, no delay"
    /// on a bad link is exactly the one that must never be given.
    async fn congestion(&self) -> Option<Congestion> {
        // No receiver report yet. Saying "no congestion" here would make the
        // capture step up before a single frame had been acknowledged, so the
        // honest answer is that we do not know.
        let reading = (*self.shared.link.lock().ok()?)?;

        Some(Congestion {
            available_bitrate_bps: None,
            packet_loss: reading.packet_loss.clamp(0.0, 1.0),
            // Before the browser has seen a sender report there is a loss
            // figure but no round trip; zero is what the trait's readers
            // treat as "nothing to worry about on that count".
            round_trip_ms: reading.round_trip_ms.unwrap_or(0.0),
        })
    }

    async fn close(&mut self) -> Result<(), WebRtcError> {
        // The RTCP watcher first: it may be holding the track's event lock,
        // which closing the connection unbinds the track through.
        if let Some(video) = self.video.as_ref() {
            video.feedback.abort();
        }

        // The channel readers too, for the same reason, and the sound sender.
        self.stop_readers();

        if let Some(audio) = self.audio.as_ref() {
            audio.task.abort();
        }

        // Channels next, then the connection: closing the peer while a
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

/// A session dropped without `close` — the host's task ended, or panicked —
/// still stops its RTCP watcher, which would otherwise keep the track alive
/// and poll it for ever.
impl Drop for WebRtcSession {
    fn drop(&mut self) {
        if let Some(video) = self.video.as_ref() {
            video.feedback.abort();
        }

        if let Some(audio) = self.audio.as_ref() {
            audio.task.abort();
        }

        self.stop_readers();
    }
}

#[cfg(all(test, feature = "bundled-openh264"))]
mod tests {
    use super::*;

    /// Sessions that encode with OpenH264 compiled from source: these tests
    /// run where Cisco's server cannot be reached.
    fn bundled() -> WebRtcFactory {
        WebRtcFactory::new(CodecSource::bundled())
    }

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
        let factory = bundled();

        let mut session = factory
            .create(IceConfiguration::default(), CaptureProfile::adaptive())
            .await
            .expect("creates a peer connection");

        assert_eq!(session.state(), PeerState::New);

        let offer = session.create_offer().await.expect("creates an offer");

        let sdp = offer["sdp"].as_str().expect("carries sdp");
        assert!(sdp.contains("v=0"));
        assert!(sdp.contains("m=video"), "the agent must offer video");

        // H.264 Constrained Baseline, mode 1, under the payload type the
        // agent registered — and the feedback that lets a browser ask for a
        // resend or a keyframe.
        let video: String = sdp
            .split("m=")
            .find(|section| section.starts_with("video"))
            .expect("a video section")
            .to_owned();
        assert!(
            video.contains(&format!("a=rtpmap:{H264_PAYLOAD_TYPE} H264/90000")),
            "the agent must offer a codec browsers decode"
        );
        for parameter in [
            "packetization-mode=1",
            "profile-level-id=42e01f",
            "level-asymmetry-allowed=1",
        ] {
            assert!(video.contains(parameter), "{parameter} missing");
        }
        for feedback in ["nack", "nack pli", "ccm fir"] {
            assert!(
                video.contains(&format!("a=rtcp-fb:{H264_PAYLOAD_TYPE} {feedback}")),
                "{feedback} missing"
            );
        }
        assert!(!video.contains("VP8"), "the track can only send H.264");
        assert!(
            sdp.contains("m=application"),
            "the agent must offer data channels"
        );

        session.close().await.expect("closes");
        assert_eq!(session.state(), PeerState::Closed);
    }

    /// **Answering takes the DTLS server role.** Control through a companion
    /// depended on this and never had it.
    ///
    /// A browser offers `a=setup:actpass`. Answering `active` makes this agent
    /// the DTLS client, and `rtc`'s SCTP transport then builds a client
    /// endpoint with no server config — which refuses the browser's `INIT`,
    /// because data channels are established by simultaneous open and the
    /// browser sends one whichever role it holds:
    ///
    /// ```text
    ///   WARN rtc_sctp::endpoint: refusing first packet due to empty server_config
    /// ```
    ///
    /// No association, no data channel, no control — and a companion *always*
    /// answers, so this was every companion session. A hosted session offers
    /// and the browser answers `active`, which is why control worked there and
    /// only there.
    #[tokio::test]
    async fn answering_takes_the_dtls_server_role_so_a_data_channel_can_open() {
        let factory = bundled();

        let mut controller = factory
            .create_control_only(IceConfiguration::default())
            .await
            .expect("controller");
        let mut companion = factory
            .create_control_only(IceConfiguration::default())
            .await
            .expect("companion");

        let offer = controller.create_offer().await.expect("offer");
        assert!(
            offer["sdp"]
                .as_str()
                .expect("sdp")
                .contains("a=setup:actpass"),
            "an offer leaves the role to the answerer, as a browser's does",
        );

        let answer = companion.accept_offer(offer).await.expect("answer");
        let answer_sdp = answer["sdp"].as_str().expect("sdp");

        assert!(
            answer_sdp.contains("a=setup:passive"),
            "answering passive is what gives the SCTP endpoint a server config; got: {answer_sdp}",
        );
        assert!(
            !answer_sdp.contains("a=setup:active"),
            "active is the role that cannot accept the browser's INIT",
        );
    }

    /// A companion offers no media: its picture comes from its owner's
    /// browser. Data channels only, in an offer and in an answer, and a frame
    /// is refused rather than silently dropped.
    #[tokio::test]
    async fn a_control_only_session_has_data_channels_and_no_video() {
        let factory = bundled();

        // The controller's side: a browser offering only the data channels.
        let mut controller = factory
            .create_control_only(IceConfiguration::default())
            .await
            .expect("controller");
        let mut companion = factory
            .create_control_only(IceConfiguration::default())
            .await
            .expect("companion");

        let offer = controller.create_offer().await.expect("offer");
        let offer_sdp = offer["sdp"].as_str().expect("sdp");
        assert!(offer_sdp.contains("m=application"));
        assert!(!offer_sdp.contains("m=video"), "no video track to offer");

        let answer = companion.accept_offer(offer).await.expect("answer");
        let answer_sdp = answer["sdp"].as_str().expect("sdp");
        assert!(answer_sdp.contains("m=application"));
        assert!(!answer_sdp.contains("m=video"));

        controller.accept_answer(answer).await.expect("accepts");

        let frame = Frame {
            width: 2,
            height: 2,
            stride: 8,
            format: remote_device::PixelFormat::Bgra8,
            data: vec![0; 16],
            timestamp_us: 0,
        };
        assert!(matches!(
            companion.send_frame(frame).await,
            Err(WebRtcError::Media(_))
        ));
        assert!(!companion.control_channel_open().await, "not connected yet");

        controller.close().await.expect("closes");
        companion.close().await.expect("closes");
    }

    #[tokio::test]
    async fn two_sessions_complete_an_offer_answer_exchange() {
        let factory = bundled();

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
        let factory = bundled();

        let mut session = factory
            .create(IceConfiguration::default(), CaptureProfile::adaptive())
            .await
            .expect("session");

        assert!(session.congestion().await.is_none());

        session.close().await.expect("closes");
    }

    #[tokio::test]
    async fn sending_control_before_the_channel_exists_is_refused_not_dropped() {
        let factory = bundled();

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
        assert!(bundled().implementation().contains("webrtc-rs"));
    }

    fn codec(mime_type: &str, fmtp: &str, payload_type: u8) -> RTCRtpCodecParameters {
        RTCRtpCodecParameters {
            rtp_codec: RTCRtpCodec {
                mime_type: mime_type.to_owned(),
                clock_rate: VIDEO_CLOCK_RATE,
                channels: 0,
                sdp_fmtp_line: fmtp.to_owned(),
                rtcp_feedback: vec![],
            },
            payload_type,
        }
    }

    /// When the browser makes the offer it numbers H.264 itself, differently
    /// in every browser and version. The packets must carry its number.
    #[test]
    fn the_h264_payload_type_is_the_negotiated_one_whoever_numbered_it() {
        // Chrome: several H.264 entries; the Constrained Baseline mode 1 one
        // is the match, not the first H.264 listed.
        let chrome = [
            codec("video/VP8", "", 96),
            codec("video/rtx", "apt=96", 97),
            codec(
                "video/H264",
                "level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=42001f",
                103,
            ),
            codec(
                "video/H264",
                "level-asymmetry-allowed=1;packetization-mode=0;profile-level-id=42e01f",
                107,
            ),
            codec(
                "video/H264",
                "level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=42e01f",
                109,
            ),
            codec(
                "video/H264",
                "level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=4d001f",
                117,
            ),
        ];
        assert_eq!(choose_h264_payload_type(&chrome), Some(109));

        // Firefox orders the parameters its own way.
        let firefox = [
            codec(
                "video/H264",
                "profile-level-id=42e01f;level-asymmetry-allowed=1;packetization-mode=1",
                126,
            ),
            codec(
                "video/H264",
                "profile-level-id=42e01f;level-asymmetry-allowed=1",
                97,
            ),
        ];
        assert_eq!(choose_h264_payload_type(&firefox), Some(126));

        // Safari lists High first, and may write hex in upper case.
        let safari = [
            codec(
                "video/H264",
                "level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=640c1f",
                96,
            ),
            codec(
                "video/h264",
                "level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=42E01F",
                98,
            ),
        ];
        assert_eq!(choose_h264_payload_type(&safari), Some(98));

        // A Constrained Baseline stream is valid Baseline, Main and High, so
        // any mode-1 entry will do when the exact one is missing.
        let baseline = [codec(
            "video/H264",
            "packetization-mode=1;profile-level-id=42001f",
            102,
        )];
        assert_eq!(choose_h264_payload_type(&baseline), Some(102));
        let high = [codec(
            "video/H264",
            "packetization-mode=1;profile-level-id=640032",
            123,
        )];
        assert_eq!(choose_h264_payload_type(&high), Some(123));

        // Mode 0 cannot carry a keyframe, and VP8 is not H.264.
        let unusable = [
            codec("video/VP8", "", 96),
            codec(
                "video/H264",
                "packetization-mode=0;profile-level-id=42e01f",
                108,
            ),
        ];
        assert_eq!(choose_h264_payload_type(&unusable), None);
        assert_eq!(choose_h264_payload_type(&[]), None);
    }

    /// PLI and FIR ask for a keyframe. A NACK has the same format number as
    /// a PLI but is transport feedback, and must not be mistaken for one —
    /// a lossy link would otherwise turn every resend request into an IDR.
    #[test]
    fn pli_and_fir_ask_for_a_keyframe_and_nothing_else_does() {
        use rtc::rtcp::payload_feedbacks::full_intra_request::FullIntraRequest;
        use rtc::rtcp::payload_feedbacks::picture_loss_indication::PictureLossIndication;
        use rtc::rtcp::receiver_report::ReceiverReport;
        use rtc::rtcp::transport_feedbacks::transport_layer_nack::TransportLayerNack;

        assert!(asks_for_keyframe(&PictureLossIndication {
            sender_ssrc: 1,
            media_ssrc: VIDEO_SSRC,
        }));
        assert!(asks_for_keyframe(&FullIntraRequest {
            sender_ssrc: 1,
            media_ssrc: VIDEO_SSRC,
            fir: vec![],
        }));

        assert!(!asks_for_keyframe(&TransportLayerNack::default()));
        assert!(!asks_for_keyframe(&ReceiverReport::default()));
    }

    /// Until the connection is up nobody can receive a frame, so it is
    /// dropped — not refused, because the host is doing nothing wrong.
    #[tokio::test]
    async fn a_frame_before_the_connection_is_up_is_dropped_not_refused() {
        let mut session = bundled()
            .create(IceConfiguration::default(), CaptureProfile::adaptive())
            .await
            .expect("session");

        let frame = crate::video::testing::pattern(64, 64, 0, 0);
        assert_eq!(session.send_frame(frame).await, Ok(()));

        session.close().await.expect("closes");

        let frame = crate::video::testing::pattern(64, 64, 1, 33_333);
        assert_eq!(
            session.send_frame(frame).await,
            Err(WebRtcError::WrongState(PeerState::Closed))
        );
    }

    /// Connect two of the agent's own sessions over the loopback interfaces:
    /// `offerer` makes the offer, `answerer` answers, and candidates are
    /// trickled both ways until both report `Connected`.
    async fn connect_pair(offerer: &mut WebRtcSession, answerer: &mut WebRtcSession) {
        let offer = offerer.create_offer().await.expect("offer");
        let answer = answerer.accept_offer(offer).await.expect("answer");
        offerer.accept_answer(answer).await.expect("accepts");

        let deadline = Instant::now() + Duration::from_secs(20);
        while offerer.state() != PeerState::Connected || answerer.state() != PeerState::Connected {
            assert!(
                Instant::now() < deadline,
                "never connected: {:?} / {:?}",
                offerer.state(),
                answerer.state()
            );

            for candidate in offerer.take_local_candidates() {
                answerer.add_ice_candidate(candidate).await.expect("added");
            }
            for candidate in answerer.take_local_candidates() {
                offerer.add_ice_candidate(candidate).await.expect("added");
            }

            tokio::time::sleep(Duration::from_millis(20)).await;
        }
    }

    /// Wait for the control channel to open on both ends.
    async fn control_open(a: &mut WebRtcSession, b: &mut WebRtcSession) {
        let deadline = Instant::now() + Duration::from_secs(20);
        while !(a.control_channel_open().await && b.control_channel_open().await) {
            assert!(
                Instant::now() < deadline,
                "the control channel never opened"
            );
            tokio::time::sleep(Duration::from_millis(20)).await;
        }
    }

    /// **Reading the control channel never waits for a message.**
    ///
    /// Both of the agent's loops — a hosted session and a control companion —
    /// call `receive_control` once a turn, between handling signalling,
    /// sending the picture and asking the API who may control. It has to hand
    /// back what has arrived and return: a read that waits for the next
    /// message stops the turn, and with it the picture, the consent dialog and
    /// the very input it was waiting for.
    #[tokio::test]
    async fn reading_the_control_channel_returns_at_once_when_nothing_has_arrived() {
        let factory = bundled();

        let mut controller = factory
            .create_control_only(IceConfiguration::default())
            .await
            .expect("controller");
        let mut companion = factory
            .create_control_only(IceConfiguration::default())
            .await
            .expect("companion");

        connect_pair(&mut controller, &mut companion).await;
        control_open(&mut controller, &mut companion).await;

        // Nothing has been sent. Both ends — the one that opened the channel
        // and the one that was handed it — must come straight back, every
        // time they are asked.
        for _ in 0..3 {
            let nothing =
                tokio::time::timeout(Duration::from_millis(500), companion.receive_control())
                    .await
                    .expect("the answering end must not wait for a message")
                    .expect("reads");
            assert!(nothing.is_empty());

            let nothing =
                tokio::time::timeout(Duration::from_millis(500), controller.receive_control())
                    .await
                    .expect("the offering end must not wait for a message")
                    .expect("reads");
            assert!(nothing.is_empty());
        }

        // Three messages go out; all three come back, in order, without the
        // reader having to be sent a fourth to let go.
        for text in ["one", "two", "three"] {
            controller
                .send_control(text.as_bytes())
                .await
                .expect("sends");
        }

        let mut received: Vec<Vec<u8>> = Vec::new();
        let deadline = Instant::now() + Duration::from_secs(10);
        while received.len() < 3 {
            assert!(Instant::now() < deadline, "only {} arrived", received.len());

            received.extend(
                tokio::time::timeout(Duration::from_millis(500), companion.receive_control())
                    .await
                    .expect("must not wait for more than has arrived")
                    .expect("reads"),
            );

            tokio::time::sleep(Duration::from_millis(10)).await;
        }

        assert_eq!(
            received,
            vec![b"one".to_vec(), b"two".to_vec(), b"three".to_vec()]
        );

        controller.close().await.expect("closes");
        companion.close().await.expect("closes");
    }

    /// With a sink, a message is handed over as it comes off the channel —
    /// nobody has to call `receive_control` for input to be applied, which is
    /// what keeps a mouse moving while the host's loop encodes a frame.
    #[tokio::test]
    async fn a_control_sink_gets_each_message_without_the_loop_asking() {
        let factory = bundled();

        let mut controller = factory
            .create_control_only(IceConfiguration::default())
            .await
            .expect("controller");
        let mut companion = factory
            .create_control_only(IceConfiguration::default())
            .await
            .expect("companion");

        connect_pair(&mut controller, &mut companion).await;
        control_open(&mut controller, &mut companion).await;

        // One message arrives before anybody has installed a sink: it waits
        // in the inbox, and installing the sink hands it over first.
        controller.send_control(b"early").await.expect("sends");
        tokio::time::sleep(Duration::from_millis(200)).await;

        let seen: Arc<Mutex<Vec<Vec<u8>>>> = Arc::default();
        let into = Arc::clone(&seen);
        companion.set_control_sink(Arc::new(move |bytes| {
            into.lock().unwrap().push(bytes);
        }));

        for text in ["one", "two"] {
            controller
                .send_control(text.as_bytes())
                .await
                .expect("sends");
        }

        // Deliberately no `receive_control` here.
        let deadline = Instant::now() + Duration::from_secs(10);
        while seen.lock().unwrap().len() < 3 {
            assert!(Instant::now() < deadline, "the sink was not called");
            tokio::time::sleep(Duration::from_millis(10)).await;
        }

        assert_eq!(
            *seen.lock().unwrap(),
            vec![b"early".to_vec(), b"one".to_vec(), b"two".to_vec()]
        );
        assert!(
            companion.receive_control().await.expect("reads").is_empty(),
            "a message handed to the sink is not handed over twice"
        );

        controller.close().await.expect("closes");
        companion.close().await.expect("closes");
    }

    /// The viewer side of the loopback test: a plain webrtc-rs peer that
    /// receives video the way a browser does, and nothing else.
    #[derive(Clone, Default)]
    struct ViewerEvents {
        candidates: Arc<Mutex<Vec<RTCIceCandidateInit>>>,
        /// The screen track.
        track: Arc<Mutex<Option<Arc<dyn webrtc::media_stream::track_remote::TrackRemote>>>>,
        /// The sound track.
        sound: Arc<Mutex<Option<Arc<dyn webrtc::media_stream::track_remote::TrackRemote>>>>,
    }

    #[async_trait::async_trait]
    impl PeerConnectionEventHandler for ViewerEvents {
        async fn on_ice_candidate(&self, event: RTCPeerConnectionIceEvent) {
            if let Ok(init) = event.candidate.to_json() {
                self.candidates.lock().unwrap().push(init);
            }
        }

        async fn on_track(&self, track: Arc<dyn webrtc::media_stream::track_remote::TrackRemote>) {
            let slot = if track.kind().await == RtpCodecKind::Audio {
                &self.sound
            } else {
                &self.track
            };

            *slot.lock().unwrap() = Some(track);
        }
    }

    /// The G.711 expansion, to hear what arrived.
    fn expand_mulaw(byte: u8) -> i16 {
        let byte = !byte;
        let magnitude =
            (((i32::from(byte & 0x0F) << 3) + 0x84) << i32::from((byte >> 4) & 0x07)) - 0x84;

        if byte & 0x80 != 0 {
            -magnitude as i16
        } else {
            magnitude as i16
        }
    }

    /// A hosted session offers its sound beside its screen, as G.711 — the
    /// codec every browser must decode — and a control-only one offers none.
    #[tokio::test]
    async fn a_session_that_shows_a_screen_offers_its_sound_too() {
        let factory = bundled();

        let mut host = factory
            .create(IceConfiguration::default(), CaptureProfile::adaptive())
            .await
            .expect("host");
        let offer = host.create_offer().await.expect("offer");
        let sdp = offer["sdp"].as_str().expect("sdp");

        let audio: String = sdp
            .split("m=")
            .find(|section| section.starts_with("audio"))
            .expect("an audio section")
            .to_owned();
        assert!(audio.contains("PCMU/8000"), "G.711 is offered: {audio}");
        assert!(host.audio_sink().is_some());

        let companion = factory
            .create_control_only(IceConfiguration::default())
            .await
            .expect("companion");
        assert!(
            companion.audio_sink().is_none(),
            "a companion sends no media"
        );

        host.close().await.expect("closes");
    }

    /// End to end: sound handed to the session's sink arrives at a second
    /// peer as 20 ms G.711 packets on the negotiated payload type, evenly
    /// stamped, and is the tone that went in.
    #[tokio::test]
    async fn sound_handed_to_the_sink_reaches_the_viewer() {
        use webrtc::media_stream::track_remote::TrackRemoteEvent;

        let mut agent = bundled()
            .create(IceConfiguration::default(), CaptureProfile::adaptive())
            .await
            .expect("agent");

        let events = ViewerEvents::default();
        let mut media = MediaEngine::default();
        media.register_default_codecs().expect("codecs");
        let viewer = PeerConnectionBuilder::new()
            .with_configuration(RTCConfigurationBuilder::default().build())
            .with_media_engine(media)
            .with_handler(Arc::new(events.clone()))
            .with_udp_addrs(vec!["0.0.0.0:0".to_owned()])
            .build()
            .await
            .expect("viewer");

        let offer = agent.create_offer().await.expect("offer");
        viewer
            .set_remote_description(serde_json::from_value(offer).expect("an offer"))
            .await
            .expect("the viewer takes the offer");
        let answer = viewer.create_answer(None).await.expect("answer");
        viewer
            .set_local_description(answer.clone())
            .await
            .expect("the viewer sets its answer");
        agent
            .accept_answer(serde_json::to_value(answer).expect("serialises"))
            .await
            .expect("the agent takes the answer");

        let deadline = Instant::now() + Duration::from_secs(20);
        while agent.state() != PeerState::Connected {
            assert!(Instant::now() < deadline, "never connected");

            for candidate in agent.take_local_candidates() {
                let init = serde_json::from_value(candidate).expect("a candidate");
                let _ = viewer.add_ice_candidate(init).await;
            }

            let from_viewer = std::mem::take(&mut *events.candidates.lock().unwrap());
            for candidate in from_viewer {
                let value = serde_json::to_value(candidate).expect("serialises");
                agent.add_ice_candidate(value).await.expect("added");
            }

            tokio::time::sleep(Duration::from_millis(20)).await;
        }

        // A 440 Hz tone at half scale, 48 kHz stereo, in the 10 ms chunks a
        // sound card hands over.
        let sink = agent.audio_sink().expect("a sound track");
        let mut received: Vec<rtc::rtp::packet::Packet> = Vec::new();
        let mut frame = 0_u64;
        let start = Instant::now();

        while received.len() < 40 {
            assert!(
                start.elapsed() < Duration::from_secs(20),
                "no sound arrived"
            );

            let mut samples = Vec::with_capacity(480 * 2);
            for _ in 0..480 {
                let value =
                    (std::f64::consts::TAU * 440.0 * frame as f64 / 48_000.0).sin() as f32 * 0.5;
                samples.extend([value, value]);
                frame += 1;
            }
            sink.push(AudioChunk {
                sample_rate: 48_000,
                channels: 2,
                samples,
            });

            let sound = events.sound.lock().unwrap().clone();
            if let Some(sound) = sound {
                while let Ok(Some(event)) =
                    tokio::time::timeout(Duration::from_millis(2), sound.poll()).await
                {
                    if let TrackRemoteEvent::OnRtpPacket(packet) = event {
                        received.push(packet);
                    }
                }
            }

            tokio::time::sleep(Duration::from_millis(10)).await;
        }

        // Skip the first few: the far side attaches to the track a moment
        // after sound starts flowing.
        let run = &received[received.len() - 20..];

        for pair in run.windows(2) {
            assert_eq!(pair[0].header.payload_type, 0, "PCMU's payload type");
            assert_eq!(pair[0].header.ssrc, AUDIO_SSRC);
            assert_eq!(pair[0].payload.len(), PACKET_SAMPLES, "20 ms a packet");
            assert_eq!(
                pair[1]
                    .header
                    .sequence_number
                    .wrapping_sub(pair[0].header.sequence_number),
                1
            );
            assert_eq!(
                pair[1]
                    .header
                    .timestamp
                    .wrapping_sub(pair[0].header.timestamp),
                PACKET_SAMPLES as u32,
                "consecutive packets are one packet of samples apart"
            );
        }

        let heard: Vec<i16> = run
            .iter()
            .flat_map(|packet| packet.payload.iter().map(|byte| expand_mulaw(*byte)))
            .collect();
        let peak = heard
            .iter()
            .map(|sample| sample.unsigned_abs())
            .max()
            .unwrap();
        assert!((14_000..=17_500).contains(&peak), "half scale: {peak}");

        // 20 packets are 0.4 s: 440 Hz crosses zero about 352 times.
        let crossings = heard
            .windows(2)
            .filter(|pair| (pair[0] < 0) != (pair[1] < 0))
            .count();
        assert!((340..=364).contains(&crossings), "{crossings} crossings");

        agent.close().await.expect("closes");
        viewer.close().await.expect("closes viewer");
    }

    /// After a silence — a loopback capture delivers nothing while nothing
    /// plays — the next packet starts a new burst: marked, and stamped as
    /// late as the silence was long.
    #[test]
    fn a_silence_is_a_gap_in_the_timestamps_not_a_stretch() {
        let mut packets = SoundPacketizer::new();
        let start = Instant::now();
        let payload = || vec![0xFF_u8; PACKET_SAMPLES];

        let first = packets.packet(payload(), 0, start);
        let second = packets.packet(payload(), 0, start + Duration::from_millis(20));
        let after = packets.packet(payload(), 0, start + Duration::from_millis(2_020));
        let next = packets.packet(payload(), 0, start + Duration::from_millis(2_040));

        assert!(first.header.marker, "the first packet starts a burst");
        assert!(!second.header.marker);
        assert_eq!(
            second.header.timestamp.wrapping_sub(first.header.timestamp),
            160
        );

        assert!(after.header.marker, "and so does the first after a silence");
        assert_eq!(
            after.header.timestamp.wrapping_sub(second.header.timestamp),
            16_000,
            "two seconds of silence are two seconds of timestamp"
        );
        assert_eq!(
            next.header.timestamp.wrapping_sub(after.header.timestamp),
            160
        );
        assert_eq!(
            next.header
                .sequence_number
                .wrapping_sub(first.header.sequence_number),
            3,
            "sequence numbers never skip"
        );
    }

    /// End to end inside one process: the agent's session offers, a second
    /// webrtc-rs peer answers, ICE connects over the loopback interfaces, and
    /// the agent streams a moving 640×360 pattern through a 320-pixel
    /// profile. On the far side the RTP is depacketised with webrtc-rs's
    /// H.264 depacketiser and decoded with OpenH264 — so this checks the
    /// payload type, the packetisation, the timestamps, the downscale, and
    /// that the far side asking for a keyframe with a PLI gets one, long
    /// before the periodic one would have come.
    #[tokio::test]
    async fn a_webrtc_peer_decodes_the_stream_and_gets_a_keyframe_when_it_asks() {
        use openh264::decoder::Decoder;
        use rtc::rtcp::payload_feedbacks::picture_loss_indication::PictureLossIndication;
        use rtc::rtp::codec::h264::H264Packet;
        use rtc::rtp::packetizer::Depacketizer;
        use webrtc::media_stream::track_remote::TrackRemoteEvent;

        use crate::video::annexb::is_self_contained_keyframe;

        let profile = CaptureProfile {
            max_dimension: 320,
            ..CaptureProfile::adaptive()
        };
        let codec = CodecSource::bundled();
        let mut agent = WebRtcFactory::new(codec.clone())
            .create(IceConfiguration::default(), profile)
            .await
            .expect("agent");

        let events = ViewerEvents::default();
        let mut media = MediaEngine::default();
        media.register_default_codecs().expect("codecs");
        // Receiver reports, as a browser sends them, more often than the
        // default so the test does not wait for them.
        let reports = Registry::new().with(
            rtc::interceptor::ReceiverReportBuilder::new()
                .with_interval(Duration::from_millis(200))
                .build(),
        );
        let viewer = PeerConnectionBuilder::new()
            .with_configuration(RTCConfigurationBuilder::default().build())
            .with_media_engine(media)
            .with_interceptor_registry(reports)
            .with_handler(Arc::new(events.clone()))
            .with_udp_addrs(vec!["0.0.0.0:0".to_owned()])
            .build()
            .await
            .expect("viewer");

        // --- negotiate ---------------------------------------------------
        let offer = agent.create_offer().await.expect("offer");
        viewer
            .set_remote_description(serde_json::from_value(offer).expect("an offer"))
            .await
            .expect("the viewer takes the offer");
        let answer = viewer.create_answer(None).await.expect("answer");
        viewer
            .set_local_description(answer.clone())
            .await
            .expect("the viewer sets its answer");
        agent
            .accept_answer(serde_json::to_value(answer).expect("serialises"))
            .await
            .expect("the agent takes the answer");

        // --- connect -----------------------------------------------------
        let deadline = Instant::now() + Duration::from_secs(20);
        while agent.state() != PeerState::Connected {
            assert!(
                Instant::now() < deadline,
                "never connected: {:?}",
                agent.state()
            );

            for candidate in agent.take_local_candidates() {
                let init = serde_json::from_value(candidate).expect("a candidate");
                let _ = viewer.add_ice_candidate(init).await;
            }

            let from_viewer = std::mem::take(&mut *events.candidates.lock().unwrap());
            for candidate in from_viewer {
                let value = serde_json::to_value(candidate).expect("serialises");
                agent.add_ice_candidate(value).await.expect("added");
            }

            tokio::time::sleep(Duration::from_millis(20)).await;
        }

        // --- stream ------------------------------------------------------
        let mut depacketizer = H264Packet::default();
        let mut pending = Vec::new();
        let mut access_units: Vec<(u32, Vec<u8>)> = Vec::new();
        let mut payload_types = std::collections::BTreeSet::new();
        let mut pli_after: Option<usize> = None;
        let start = Instant::now();

        for index in 0..450_u64 {
            assert!(start.elapsed() < Duration::from_secs(30), "ran out of time");

            let frame = crate::video::testing::pattern(640, 360, index, index * 33_333);
            agent.send_frame(frame).await.expect("sends");

            let track = events.track.lock().unwrap().clone();
            if let Some(track) = track {
                while let Ok(Some(event)) =
                    tokio::time::timeout(Duration::from_millis(5), track.poll()).await
                {
                    let TrackRemoteEvent::OnRtpPacket(packet) = event else {
                        continue;
                    };

                    payload_types.insert(packet.header.payload_type);
                    pending.extend_from_slice(
                        &depacketizer
                            .depacketize(&packet.payload)
                            .expect("depacketises"),
                    );

                    if packet.header.marker {
                        access_units.push((packet.header.timestamp, std::mem::take(&mut pending)));
                    }
                }

                // Ten frames after the first keyframe, ask for another.
                let first_key = access_units
                    .iter()
                    .position(|(_, unit)| is_self_contained_keyframe(unit));
                if let (None, Some(first)) = (pli_after, first_key) {
                    if access_units.len() > first + 10 {
                        track
                            .write_rtcp(vec![Box::new(PictureLossIndication {
                                sender_ssrc: 1,
                                media_ssrc: VIDEO_SSRC,
                            })])
                            .await
                            .expect("a PLI is sent");
                        pli_after = Some(access_units.len());
                    }
                }

                if let Some(sent) = pli_after {
                    if access_units[sent..]
                        .iter()
                        .any(|(_, unit)| is_self_contained_keyframe(unit))
                    {
                        break;
                    }
                }
            }

            tokio::time::sleep(Duration::from_millis(25)).await;
        }

        // --- check what arrived -----------------------------------------
        assert_eq!(
            payload_types.into_iter().collect::<Vec<_>>(),
            vec![H264_PAYLOAD_TYPE],
            "every packet carries the negotiated H.264 payload type"
        );

        let first = access_units
            .iter()
            .position(|(_, unit)| is_self_contained_keyframe(unit))
            .expect("a keyframe with its SPS and PPS arrived");
        let sent = pli_after.expect("enough frames arrived to send a PLI");
        let answered = access_units[sent..]
            .iter()
            .position(|(_, unit)| is_self_contained_keyframe(unit))
            .map(|offset| sent + offset)
            .expect("the PLI was answered with a keyframe");

        // Long before the periodic IDR, two seconds — 180 000 ticks — on.
        let since_first = access_units[answered].0.wrapping_sub(access_units[first].0);
        assert!(
            since_first < 90_000,
            "{since_first} ticks after the first IDR"
        );

        // Every frame from the first keyframe on decodes, at the profile's
        // size, and the timestamps advance by a frame's worth at 90 kHz.
        let mut decoder = Decoder::new().expect("decoder");
        for (timestamp, unit) in &access_units[first..] {
            let picture = decoder
                .decode(unit)
                .expect("decodes")
                .map(|yuv| openh264::formats::YUVSource::dimensions(&yuv));
            assert_eq!(picture, Some((320, 180)), "frame at {timestamp}");
        }

        for pair in access_units[first..].windows(2) {
            let step = pair[1].0.wrapping_sub(pair[0].0);
            assert!(
                (2_999..=9_000).contains(&step),
                "{step} ticks between consecutive frames"
            );
        }

        // --- congestion from the viewer's receiver reports ---------------
        // The viewer reports every fifth of a second; the agent's sender
        // reports go out every second, and only a receiver report that
        // echoes one carries a round trip. Keep streaming until one has.
        let deadline = Instant::now() + Duration::from_secs(10);
        let mut extra_frames = 0_u64;
        let reading = loop {
            if let Some(reading) = agent.congestion().await {
                if reading.round_trip_ms > 0.0 {
                    break reading;
                }
            }
            assert!(Instant::now() < deadline, "no round trip was ever reported");

            extra_frames += 1;
            let index = 1_000 + extra_frames;
            let frame = crate::video::testing::pattern(640, 360, index, index * 33_333);
            agent.send_frame(frame).await.expect("sends");

            // Drained so the viewer's queue never fills; the frames have
            // been checked already.
            let track = events.track.lock().unwrap().clone();
            if let Some(track) = track {
                while let Ok(Some(_)) =
                    tokio::time::timeout(Duration::from_millis(5), track.poll()).await
                {
                }
            }
            tokio::time::sleep(Duration::from_millis(30)).await;
        };

        assert_eq!(reading.packet_loss, 0.0, "nothing is lost on loopback");
        assert!(
            reading.round_trip_ms < 250.0,
            "{} ms is not a loopback round trip",
            reading.round_trip_ms
        );

        // --- the codec switched off mid-session, and on again -------------
        // Off: the next frame is refused with the codec's own error, which
        // the host recognises and tells the person at the computer about —
        // not sent as nothing in silence. On again: the session carries on.
        codec.set(None);
        let frame = crate::video::testing::pattern(640, 360, 3_000, 3_000 * 33_333);
        let refused = agent
            .send_frame(frame)
            .await
            .expect_err("no codec, no frame");
        assert!(refused.is_codec_unavailable(), "{refused:?}");
        assert_eq!(
            refused,
            WebRtcError::Media(crate::video::CODEC_UNAVAILABLE.to_owned())
        );
        assert_eq!(
            agent.state(),
            PeerState::Connected,
            "the connection is fine"
        );

        codec.set(Some(crate::video::codec::Codec::Bundled));
        let frame = crate::video::testing::pattern(640, 360, 3_100, 3_100 * 33_333);
        agent.send_frame(frame).await.expect("sends again");

        viewer.close().await.expect("viewer closes");
        agent.close().await.expect("agent closes");
    }

    /// RFC 3550 §6.4.1 arithmetic: the round trip is now, less the echoed
    /// sender-report time, less the time the receiver held it — all in
    /// 1/65536 s on the NTP clock. A report that echoes no sender report has
    /// a loss figure and no round trip.
    #[test]
    fn a_reception_report_gives_loss_and_round_trip() {
        let now = UNIX_EPOCH + Duration::from_secs(1_790_000_000) + Duration::from_millis(250);
        let compact = |at: SystemTime| {
            (SystemInstant::unix2ntp(at.duration_since(UNIX_EPOCH).unwrap()) >> 16) as u32
        };

        // Our sender report left 80 ms ago; the browser held it for 30 ms.
        let report = ReceptionReport {
            ssrc: VIDEO_SSRC,
            fraction_lost: 64,
            last_sender_report: compact(now - Duration::from_millis(80)),
            delay: 30 * 65_536 / 1000,
            ..ReceptionReport::default()
        };

        let reading = link_reading(&report, now);
        assert_eq!(reading.packet_loss, 0.25);
        let round_trip = reading.round_trip_ms.expect("a round trip");
        assert!((round_trip - 50.0).abs() < 0.2, "{round_trip} ms");

        let before_any_sender_report = ReceptionReport {
            last_sender_report: 0,
            ..report.clone()
        };
        assert_eq!(
            link_reading(&before_any_sender_report, now).round_trip_ms,
            None
        );

        // A clock that jumped makes nonsense, which is dropped, not reported.
        let echoed_from_the_future = ReceptionReport {
            last_sender_report: compact(now + Duration::from_secs(5)),
            delay: 0,
            ..report
        };
        assert_eq!(
            link_reading(&echoed_from_the_future, now).round_trip_ms,
            None
        );
    }

    /// Only a report about the screen track counts; one about some other
    /// stream is not this link's loss.
    #[test]
    fn only_reports_about_the_screen_track_are_read() {
        let about = |ssrc: u32| ReceiverReport {
            ssrc: 1,
            reports: vec![ReceptionReport {
                ssrc,
                fraction_lost: 10,
                ..ReceptionReport::default()
            }],
            ..ReceiverReport::default()
        };

        assert!(report_on(&about(VIDEO_SSRC), VIDEO_SSRC).is_some());
        assert!(report_on(&about(VIDEO_SSRC + 1), VIDEO_SSRC).is_none());
        assert!(report_on(&ReceiverReport::default(), VIDEO_SSRC).is_none());
    }
}
