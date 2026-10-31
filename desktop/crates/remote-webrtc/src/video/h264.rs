//! The H.264 encoder: OpenH264, configured for a remote desktop.
//!
//! # Licensing — read this before shipping a build
//!
//! OpenH264's *code* is BSD-2-Clause, which this product can ship. H.264 is
//! also covered by patents, licensed through the AVC/H.264 patent pool (Via
//! Licensing Alliance, formerly MPEG LA), and that is a separate question the
//! code licence does not answer.
//!
//! Cisco pays the pool's royalties for one thing only: **Cisco's own
//! prebuilt OpenH264 binary**, downloaded from Cisco by the machine that runs
//! it. This build does not use that binary. The `openh264` crate's default
//! `source` feature compiles OpenH264 from the C++ in the crate, so the
//! encoder that ships inside the agent is ours, and Cisco's licence does not
//! cover it. Distributing it means AICOUNTLY holds — or decides it does not
//! need — its own AVC licence: the pool's terms have historically included a
//! royalty-free tier for a small number of units a year and per-unit fees
//! above it, and some of the essential patents have expired in some
//! countries. That is a decision for whoever signs off the release, with
//! advice, not one this comment can make.
//!
//! The alternative is the crate's `libloading` feature: the agent ships
//! without an encoder and loads Cisco's binary at run time, which must be
//! downloaded from Cisco (`ciscobinary.openh264.org`) on the customer's
//! machine — not bundled in our installer — and checked against the hash the
//! crate pins, for Cisco's licence to apply. That is a download on first use,
//! a network dependency at install time, and a library loaded from disk into
//! a process that injects input, which is why it is not the default here.
//! Switching is a change to `desktop/Cargo.toml` and to `H264Encoder::build`
//! (`OpenH264API::from_blob_path` in place of `from_source`), and to nothing
//! else.
//!
//! # The configuration, and why
//!
//! * **Constrained Baseline** — what every browser negotiates; no B-frames,
//!   so nothing waits for a later frame to be decodable.
//! * **Screen-content real-time usage** — OpenH264's mode for desktops: it
//!   tunes motion search and quantisation for text and flat colour rather
//!   than for camera noise, and detects scrolling.
//! * **Bitrate rate control with frame skipping** — the encoder holds to the
//!   profile's bitrate and *skips* a frame when it is over budget, rather than
//!   producing a large one that queues in the network. A skipped frame is
//!   `Ok(None)`; the next one is predicted from the last one sent, so nothing
//!   is lost but the moment.
//! * **IDRs on our schedule, not OpenH264's** — the first frame, the first
//!   at a new size, every [`KEYFRAME_INTERVAL`](super::pacing::KEYFRAME_INTERVAL)
//!   of *media time*, and on request. OpenH264's own intra period counts
//!   frames, and a capture that only delivers on change has no fixed number
//!   of frames per second to count.
//! * **One slice, one thread** — a slice per picture is what a browser's
//!   decoder handles best, and the encode is already off the async runtime
//!   (see `WebRtcSession::send_frame`). OpenH264 runs one thread per slice,
//!   and the only multi-slice mode the safe API offers is size-limited
//!   slicing; measured at 1080p with two and four threads it was no faster,
//!   because the per-frame cost is dominated by screen-content analysis — a
//!   feature hash of the whole reference picture, which is what makes
//!   scrolling and repeated glyphs cheap to code — and that runs on one
//!   thread whatever the slice count.
//! * **BT.601 limited range, said out loud** — the conversion produces it,
//!   and the SPS's colour description states it, with the sRGB primaries and
//!   transfer a desktop is drawn in.

use std::time::Instant;

use openh264::encoder::{
    BitRate, Complexity, Encoder, EncoderConfig, FrameRate, FrameType, IntraFramePeriod,
    MatrixCoefficients, Profile, RateControlMode, SpsPpsStrategy, TransferCharacteristics,
    UsageType, VuiConfig,
};
use openh264::formats::YUVSource;
use openh264::{OpenH264API, Timestamp};
use remote_device::{CaptureProfile, Frame};

use super::annexb::{self, NAL_IDR, NAL_PPS, NAL_SPS};
use super::convert::{convert_into, encoded_size, ConvertError, I420Picture};
use super::pacing::{FrameGate, KeyframeReason, KeyframeSchedule, MediaClock};

/// Why a frame could not be encoded.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum VideoError {
    /// The frame could not be converted.
    #[error(transparent)]
    Convert(#[from] ConvertError),

    /// OpenH264 refused to start or to encode. Its own message, which names
    /// a parameter or an error code and never contains pixels.
    #[error("the encoder failed: {0}")]
    Encoder(String),
}

/// One encoded picture: an H.264 access unit in Annex-B framing.
#[derive(Clone)]
pub struct EncodedFrame {
    /// NAL units with start codes, as webrtc-rs's H.264 payloader expects.
    /// An IDR carries its SPS and PPS ahead of it.
    pub data: Vec<u8>,
    /// Whether this is an IDR, from which a decoder can start.
    pub keyframe: bool,
    /// The picture's width, after cropping and scaling.
    pub width: u32,
    /// The picture's height.
    pub height: u32,
    /// Microseconds since the first frame, from the capture clock. The RTP
    /// timestamp is derived from this.
    pub media_time_us: u64,
}

/// Prints the shape of the frame, never its bytes. An encoded frame is still
/// a picture of somebody's screen.
impl std::fmt::Debug for EncodedFrame {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("EncodedFrame")
            .field("bytes", &self.data.len())
            .field("keyframe", &self.keyframe)
            .field("width", &self.width)
            .field("height", &self.height)
            .field("media_time_us", &self.media_time_us)
            .finish()
    }
}

/// What the encoder has done, for the diagnostics panel and the tests.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct EncoderStats {
    /// Frames that produced an access unit.
    pub encoded: u64,
    /// Of those, IDRs.
    pub keyframes: u64,
    /// Frames dropped before conversion because they arrived faster than
    /// the profile's frame rate.
    pub dropped_by_pacing: u64,
    /// Frames the rate control skipped to stay inside the bitrate.
    pub skipped_by_rate_control: u64,
    /// How many times the OpenH264 encoder was built — once, plus once per
    /// size or profile change.
    pub encoder_builds: u64,
}

/// A screen encoder: [`Frame`] in, [`EncodedFrame`] out.
///
/// Holds an OpenH264 instance built lazily at the first frame's size and
/// rebuilt whenever the size or the profile changes — a fresh encoder rather
/// than a reconfigured one, because a new size needs a new SPS and an IDR
/// anyway, and a fresh instance is the one state OpenH264 is certain to get
/// right.
pub struct H264Encoder {
    profile: CaptureProfile,
    encoder: Option<Encoder>,
    /// The size `encoder` was built for.
    built_for: (u32, u32),
    picture: I420Picture,
    clock: MediaClock,
    gate: FrameGate,
    keyframes: KeyframeSchedule,
    /// The SPS and PPS of the current stream, kept from the last IDR that
    /// carried them, so an IDR that somehow came without them can be sent
    /// with them anyway: an IDR a browser cannot decode is not a keyframe.
    parameter_sets: Vec<u8>,
    stats: EncoderStats,
}

impl H264Encoder {
    /// An encoder for `profile`. Nothing is allocated until the first frame.
    #[must_use]
    pub fn new(profile: CaptureProfile) -> Self {
        Self {
            profile,
            encoder: None,
            built_for: (0, 0),
            picture: I420Picture::new(),
            clock: MediaClock::new(),
            gate: FrameGate::new(),
            keyframes: KeyframeSchedule::default(),
            parameter_sets: Vec::new(),
            stats: EncoderStats::default(),
        }
    }

    /// The profile frames are encoded under.
    #[must_use]
    pub fn profile(&self) -> CaptureProfile {
        self.profile
    }

    /// Follow a new profile — the capture quality changed, or congestion
    /// moved it a step.
    ///
    /// A change to anything that shapes the stream (ceiling, frame rate,
    /// quality, and so the bitrate) rebuilds the encoder at the next frame,
    /// which therefore starts with an IDR. Only the cursor flag changing
    /// leaves the stream alone; that is the capture's business.
    pub fn set_profile(&mut self, profile: CaptureProfile) {
        let reshapes = profile.max_dimension != self.profile.max_dimension
            || profile.max_fps != self.profile.max_fps
            || profile.quality != self.profile.quality;

        self.profile = profile;

        if reshapes {
            self.encoder = None;
        }
    }

    /// Make the next encoded frame an IDR.
    pub fn request_keyframe(&mut self) {
        self.keyframes.request();
    }

    /// What has happened so far.
    #[must_use]
    pub fn stats(&self) -> EncoderStats {
        self.stats
    }

    /// Encode one frame, handed over at `now`.
    ///
    /// `Ok(None)` is a frame that was deliberately not encoded — it arrived
    /// faster than the profile's frame rate, or the rate control skipped it
    /// to stay inside the bitrate. Neither is an error, and the caller has
    /// nothing to send.
    ///
    /// # Errors
    ///
    /// [`VideoError::Convert`] for a frame that does not describe its own
    /// bytes or is in a layout this does not take, and
    /// [`VideoError::Encoder`] if OpenH264 refuses.
    pub fn encode(
        &mut self,
        frame: &Frame,
        now: Instant,
    ) -> Result<Option<EncodedFrame>, VideoError> {
        // Checked before anything else, so a malformed frame cannot so much
        // as move the clock.
        if !frame.is_well_formed() {
            return Err(ConvertError::Malformed.into());
        }

        let media_time_us = self.clock.stamp(frame.timestamp_us, now);

        if !self.gate.admit(media_time_us, self.profile.max_fps) {
            self.stats.dropped_by_pacing += 1;

            return Ok(None);
        }

        if frame.width < 2 || frame.height < 2 {
            return Err(ConvertError::TooSmall {
                width: frame.width,
                height: frame.height,
            }
            .into());
        }

        let size = encoded_size(&self.profile, frame.width, frame.height);

        convert_into(frame, size, &mut self.picture)?;

        if self.encoder.is_none() || self.built_for != size {
            self.encoder = Some(Self::build(&self.profile, size)?);
            self.built_for = size;
            self.parameter_sets.clear();
            self.keyframes.restart();
            self.stats.encoder_builds += 1;
        }

        let Some(encoder) = self.encoder.as_mut() else {
            return Err(VideoError::Encoder("the encoder was not built".into()));
        };

        // Forced whatever the reason. A fresh encoder's first frame is an IDR
        // anyway, and OpenH264 declines the call harmlessly before its first
        // encode; but a fresh encoder whose first frame the rate control
        // skipped is no longer fresh, and its next frame must still be one.
        let wanted: Option<KeyframeReason> = self.keyframes.due(media_time_us);
        if wanted.is_some() {
            encoder.force_intra_frame();
        }

        let bitstream = encoder
            .encode_at(&self.picture, Timestamp::from_millis(media_time_us / 1000))
            .map_err(|error| VideoError::Encoder(error.to_string()))?;

        let frame_type = bitstream.frame_type();
        let mut data = bitstream.to_vec();

        if matches!(frame_type, FrameType::Skip | FrameType::Invalid) || data.is_empty() {
            self.stats.skipped_by_rate_control += 1;

            return Ok(None);
        }

        let keyframe = frame_type == FrameType::IDR;

        if keyframe {
            data = self.with_parameter_sets(data);
            self.keyframes.produced_idr(media_time_us);
            self.stats.keyframes += 1;
        }

        self.stats.encoded += 1;

        Ok(Some(EncodedFrame {
            data,
            keyframe,
            width: size.0,
            height: size.1,
            media_time_us,
        }))
    }

    /// An IDR access unit that certainly starts with its SPS and PPS.
    ///
    /// OpenH264 writes them ahead of every IDR, and webrtc-rs's payloader
    /// then sends them as one STAP-A in front of the IDR's fragments — which
    /// is what a browser that joined late needs to start decoding. This keeps
    /// the last pair it saw and puts them back if an IDR ever arrives without
    /// them, rather than trusting that it never will.
    fn with_parameter_sets(&mut self, access_unit: Vec<u8>) -> Vec<u8> {
        let types = annexb::nal_types(&access_unit);
        let carries_both = types.contains(&NAL_SPS) && types.contains(&NAL_PPS);

        if carries_both {
            self.parameter_sets.clear();

            for unit in annexb::nal_units(&access_unit) {
                if matches!(annexb::nal_type(unit), Some(NAL_SPS | NAL_PPS)) {
                    self.parameter_sets.extend_from_slice(&[0, 0, 0, 1]);
                    self.parameter_sets.extend_from_slice(unit);
                }
            }

            return access_unit;
        }

        if self.parameter_sets.is_empty() || !types.contains(&NAL_IDR) {
            return access_unit;
        }

        let mut complete = self.parameter_sets.clone();
        complete.extend_from_slice(&access_unit);
        complete
    }

    /// An OpenH264 encoder for `size` under `profile`.
    fn build(profile: &CaptureProfile, (width, height): (u32, u32)) -> Result<Encoder, VideoError> {
        // Adaptive quantisation and background detection are camera-mode
        // features that OpenH264 switches off for screen content anyway —
        // with a warning on stderr for every encoder built — so they are off
        // here to begin with. Scene-change detection, conversely, it insists
        // on for screen content, and it is what turns an alt-tab into an
        // I-frame rather than a smear of motion vectors.
        let config = EncoderConfig::new()
            .usage_type(UsageType::ScreenContentRealTime)
            .profile(Profile::Baseline)
            .rate_control_mode(RateControlMode::Bitrate)
            .bitrate(BitRate::from_bps(profile.target_bitrate_bps(width, height)))
            .max_frame_rate(FrameRate::from_hz(profile.max_fps.max(1) as f32))
            .skip_frames(true)
            .intra_frame_period(IntraFramePeriod::from_num_frames(0))
            .sps_pps_strategy(SpsPpsStrategy::ConstantId)
            .complexity(Complexity::Low)
            .num_threads(1)
            .long_term_reference(false)
            .scene_change_detect(true)
            .adaptive_quantization(false)
            .background_detection(false)
            .vui(
                VuiConfig::bt709()
                    .transfer_characteristics(TransferCharacteristics::Srgb)
                    .matrix_coefficients(MatrixCoefficients::Smpte170M)
                    .full_range(false),
            );

        // Compiled from source: see the licensing note at the top of this
        // file before changing this to Cisco's binary or back.
        Encoder::with_api_config(OpenH264API::from_source(), config)
            .map_err(|error| VideoError::Encoder(error.to_string()))
    }
}

impl std::fmt::Debug for H264Encoder {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("H264Encoder")
            .field("profile", &self.profile)
            .field("built_for", &self.built_for)
            .field("stats", &self.stats)
            .finish_non_exhaustive()
    }
}

/// The converted picture, as OpenH264 reads it: tightly packed planes, so
/// each stride is the plane's width.
impl YUVSource for I420Picture {
    fn dimensions(&self) -> (usize, usize) {
        (self.width() as usize, self.height() as usize)
    }

    fn strides(&self) -> (usize, usize, usize) {
        let width = self.width() as usize;

        (width, width / 2, width / 2)
    }

    fn y(&self) -> &[u8] {
        I420Picture::y(self)
    }

    fn u(&self) -> &[u8] {
        I420Picture::u(self)
    }

    fn v(&self) -> &[u8] {
        I420Picture::v(self)
    }
}

#[cfg(test)]
mod tests {
    use std::time::Duration;

    use openh264::decoder::Decoder;
    use remote_device::PixelFormat;

    use super::*;
    use crate::video::annexb::{is_self_contained_keyframe, NAL_SLICE};
    use crate::video::testing::{busy_pattern, pattern};

    const FRAME_US: u64 = 33_333;

    /// Encode `count` frames of the pattern, 30 fps of capture time apart.
    fn encode_run(
        encoder: &mut H264Encoder,
        size: (u32, u32),
        first: u64,
        count: u64,
    ) -> Vec<EncodedFrame> {
        let start = Instant::now();

        (first..first + count)
            .filter_map(|index| {
                let frame = pattern(size.0, size.1, index, index * FRAME_US);
                encoder
                    .encode(&frame, start + Duration::from_micros(index * FRAME_US))
                    .expect("encodes")
            })
            .collect()
    }

    fn decoded_size(decoder: &mut Decoder, access_unit: &[u8]) -> Option<(usize, usize)> {
        decoder
            .decode(access_unit)
            .expect("decodes")
            .map(|yuv| yuv.dimensions())
    }

    /// The first frame is an IDR with its SPS and PPS ahead of it, in
    /// Annex-B, and it decodes at the size it says.
    #[test]
    fn the_first_frame_is_a_self_contained_idr() {
        let mut encoder = H264Encoder::new(CaptureProfile::adaptive());
        let frames = encode_run(&mut encoder, (96, 64), 0, 1);
        let first = &frames[0];

        assert!(first.keyframe);
        assert_eq!((first.width, first.height), (96, 64));
        assert_eq!(&first.data[..4], &[0, 0, 0, 1], "Annex-B start code");
        assert!(
            is_self_contained_keyframe(&first.data),
            "{:?}",
            annexb::nal_types(&first.data)
        );

        let mut decoder = Decoder::new().expect("decoder");
        assert_eq!(decoded_size(&mut decoder, &first.data), Some((96, 64)));
    }

    /// The SPS says Constrained Baseline — profile_idc 66 with
    /// constraint_set1 — which is what `profile-level-id=42e01f` promised.
    #[test]
    fn the_stream_is_constrained_baseline() {
        let mut encoder = H264Encoder::new(CaptureProfile::adaptive());
        let frames = encode_run(&mut encoder, (64, 64), 0, 1);

        let sps = annexb::nal_units(&frames[0].data)
            .find(|unit| annexb::nal_type(unit) == Some(NAL_SPS))
            .expect("an SPS");

        assert_eq!(sps[1], 66, "profile_idc: Baseline");
        assert_ne!(sps[2] & 0x40, 0, "constraint_set1_flag: Constrained");
    }

    /// After the IDR come P-frames — single non-IDR slices — that decode
    /// in order, and are much smaller than the IDR.
    #[test]
    fn p_frames_follow_and_decode() {
        let mut encoder = H264Encoder::new(CaptureProfile::adaptive());
        let frames = encode_run(&mut encoder, (128, 96), 0, 10);
        let mut decoder = Decoder::new().expect("decoder");

        assert_eq!(frames.len(), 10, "{:?}", encoder.stats());
        assert!(frames[0].keyframe);
        for frame in &frames[1..] {
            assert!(!frame.keyframe);
            assert!(annexb::nal_types(&frame.data).contains(&NAL_SLICE));
        }

        for frame in &frames {
            assert_eq!(decoded_size(&mut decoder, &frame.data), Some((128, 96)));
        }

        let average_p: usize = frames[1..]
            .iter()
            .map(|frame| frame.data.len())
            .sum::<usize>()
            / (frames.len() - 1);
        assert!(
            average_p < frames[0].data.len(),
            "P {average_p} vs IDR {}",
            frames[0].data.len()
        );
    }

    /// An IDR every two seconds of capture time, on its own.
    #[test]
    fn a_keyframe_goes_out_every_two_seconds() {
        let mut encoder = H264Encoder::new(CaptureProfile::adaptive());
        let frames = encode_run(&mut encoder, (64, 64), 0, 125);

        let keyframes: Vec<u64> = frames
            .iter()
            .filter(|frame| frame.keyframe)
            .map(|frame| frame.media_time_us)
            .collect();

        assert_eq!(keyframes.len(), 3, "{keyframes:?}");
        assert_eq!(keyframes[0], 0);
        for pair in keyframes.windows(2) {
            let gap = pair[1] - pair[0];
            assert!(
                (2_000_000..2_100_000).contains(&gap),
                "{gap} µs between IDRs"
            );
        }
    }

    /// A request is answered by the very next encoded frame.
    #[test]
    fn a_requested_keyframe_is_the_next_frame() {
        let mut encoder = H264Encoder::new(CaptureProfile::adaptive());
        let before = encode_run(&mut encoder, (64, 64), 0, 5);
        assert_eq!(before.iter().filter(|frame| frame.keyframe).count(), 1);

        encoder.request_keyframe();
        let after = encode_run(&mut encoder, (64, 64), 5, 3);

        assert!(after[0].keyframe, "the frame after the request");
        assert!(is_self_contained_keyframe(&after[0].data));
        assert!(!after[1].keyframe && !after[2].keyframe);
    }

    /// A different size rebuilds the encoder and starts the new stream with
    /// an IDR that decodes at the new size — a display change, or a
    /// different monitor, mid-session.
    #[test]
    fn a_new_size_rebuilds_the_encoder_with_an_idr() {
        let mut encoder = H264Encoder::new(CaptureProfile::adaptive());
        let mut decoder = Decoder::new().expect("decoder");

        let small = encode_run(&mut encoder, (64, 48), 0, 3);
        let large = encode_run(&mut encoder, (160, 90), 3, 3);

        assert_eq!(encoder.stats().encoder_builds, 2);
        assert!(large[0].keyframe);
        assert!(is_self_contained_keyframe(&large[0].data));
        assert_eq!((large[0].width, large[0].height), (160, 90));

        for frame in small.iter().chain(&large) {
            let expected = (frame.width as usize, frame.height as usize);
            assert_eq!(decoded_size(&mut decoder, &frame.data), Some(expected));
        }

        // A profile change that reshapes the stream rebuilds too.
        encoder.set_profile(CaptureProfile::adaptive().degraded().degraded().degraded());
        let slower = encode_run(&mut encoder, (160, 90), 6, 1);
        assert!(slower[0].keyframe);
        assert_eq!(encoder.stats().encoder_builds, 3);
    }

    /// An odd-sized screen is encoded one pixel smaller, at even dimensions,
    /// and a screen over the profile's ceiling is scaled down to it.
    #[test]
    fn odd_and_oversized_frames_are_encoded_at_the_size_the_profile_allows() {
        let mut decoder = Decoder::new().expect("decoder");

        let mut encoder = H264Encoder::new(CaptureProfile::adaptive());
        let odd = encode_run(&mut encoder, (65, 33), 0, 1);
        assert_eq!((odd[0].width, odd[0].height), (64, 32));
        assert_eq!(decoded_size(&mut decoder, &odd[0].data), Some((64, 32)));

        let small_ceiling = CaptureProfile {
            max_dimension: 160,
            ..CaptureProfile::adaptive()
        };
        let mut encoder = H264Encoder::new(small_ceiling);
        let scaled = encode_run(&mut encoder, (640, 360), 0, 1);
        assert_eq!((scaled[0].width, scaled[0].height), (160, 90));

        let mut decoder = Decoder::new().expect("decoder");
        assert_eq!(decoded_size(&mut decoder, &scaled[0].data), Some((160, 90)));
    }

    /// The colour survives the round trip: white decodes as limited-range
    /// white, black as limited-range black.
    #[test]
    fn colours_survive_encode_and_decode() {
        let mut encoder = H264Encoder::new(CaptureProfile::adaptive());
        let mut decoder = Decoder::new().expect("decoder");
        let start = Instant::now();

        for (second, (value, expected_luma)) in [(255_u8, 235_i32), (0, 16)].into_iter().enumerate()
        {
            let second = second as u64;
            encoder.request_keyframe();
            let frame = Frame::new(
                32,
                32,
                128,
                PixelFormat::Bgra8,
                second * 1_000_000,
                vec![value; 128 * 32],
            )
            .expect("well formed");

            let encoded = encoder
                .encode(&frame, start + Duration::from_secs(second))
                .expect("encodes")
                .expect("not skipped");
            let decoded = decoder
                .decode(&encoded.data)
                .expect("decodes")
                .expect("a picture");

            let luma = i32::from(decoded.y()[decoded.strides().0 * 16 + 16]);
            assert!(
                (luma - expected_luma).abs() <= 3,
                "{value} decoded as Y={luma}"
            );
        }
    }

    /// Frames faster than the profile's rate are dropped before they cost
    /// anything, and the drop is not an error.
    #[test]
    fn frames_above_the_profiles_rate_are_dropped_not_queued() {
        let mut encoder = H264Encoder::new(CaptureProfile::low_bandwidth());
        let start = Instant::now();

        for index in 0..60_u64 {
            // 60 Hz for one second at a 12 fps profile.
            let frame = pattern(64, 64, index, index * 16_667);
            encoder
                .encode(&frame, start + Duration::from_micros(index * 16_667))
                .expect("a dropped frame is not an error");
        }

        let stats = encoder.stats();
        assert_eq!(stats.dropped_by_pacing, 48, "{stats:?}");
        assert_eq!(
            stats.encoded + stats.skipped_by_rate_control,
            12,
            "{stats:?}"
        );
    }

    /// A frame that does not describe its own bytes is refused before the
    /// encoder sees it.
    #[test]
    fn a_malformed_frame_is_an_error_not_a_crash() {
        let mut encoder = H264Encoder::new(CaptureProfile::adaptive());
        let mut frame = pattern(64, 64, 0, 0);
        frame.height = 640;

        assert_eq!(
            encoder.encode(&frame, Instant::now()).unwrap_err(),
            VideoError::Convert(ConvertError::Malformed)
        );
        assert_eq!(encoder.stats(), EncoderStats::default());
    }

    /// The release-build cost of a frame at the default profile, for a
    /// 1080p screen and for the two that are box-filtered down to it — a
    /// Retina MacBook's 3024×1964 and a 4K monitor. Conversion alone, then
    /// conversion plus encode for P-frames and IDRs separately. Prints the
    /// numbers; fails only if a 1080p P-frame takes longer, on average, than
    /// the frame interval.
    ///
    /// Slow in a debug build, and only meaningful in a release one:
    ///
    /// ```text
    /// cd desktop
    /// cargo test -p remote-webrtc --release --no-default-features --features openh264 \
    ///     --lib encoding_fits_the_frame_interval -- --ignored --nocapture
    /// ```
    ///
    /// `OPENH264_NO_ASM=1` in the environment builds OpenH264 without NASM,
    /// which is what a Windows runner without `nasm` on its path produces.
    #[test]
    #[ignore = "a release-build measurement; see the doc comment for the command"]
    fn encoding_fits_the_frame_interval() {
        const FRAMES: u64 = 150;

        let profile = CaptureProfile::adaptive();
        let interval_us = u128::from(profile.frame_interval_ms()) * 1000;

        let summary = |label: &str, samples: &mut Vec<u128>| -> u128 {
            samples.sort_unstable();
            let count = samples.len().max(1);
            let mean = samples.iter().sum::<u128>() / count as u128;
            let at = |fraction: usize| {
                samples
                    .get((count * fraction / 100).min(count - 1))
                    .copied()
                    .unwrap_or(0)
            };
            println!(
                "  {label:<24} n={:<4} mean {:>6.2} ms  p50 {:>6.2} ms  p95 {:>6.2} ms  max {:>6.2} ms",
                samples.len(),
                mean as f64 / 1000.0,
                at(50) as f64 / 1000.0,
                at(95) as f64 / 1000.0,
                samples.last().copied().unwrap_or(0) as f64 / 1000.0,
            );
            mean
        };

        println!(
            "adaptive profile: {} fps (frame interval {} ms), OpenH264 {}",
            profile.max_fps,
            profile.frame_interval_ms(),
            if option_env!("OPENH264_NO_ASM").is_some() {
                "without NASM"
            } else {
                "as built"
            },
        );

        for (width, height) in [(1920, 1080), (3024, 1964), (3840, 2160)] {
            let size = encoded_size(&profile, width, height);
            let mut encoder = H264Encoder::new(profile);
            let mut picture = I420Picture::new();
            let start = Instant::now();
            let (mut convert_us, mut p_frame_us, mut idr_us) = (Vec::new(), Vec::new(), Vec::new());
            let mut bytes = 0_usize;

            for index in 0..FRAMES {
                // Drawn outside the timed region: it is the test's cost, not
                // the agent's.
                let frame = busy_pattern(width, height, index, index * FRAME_US);

                let began = Instant::now();
                convert_into(&frame, size, &mut picture).expect("converts");
                convert_us.push(began.elapsed().as_micros());

                let began = Instant::now();
                let encoded = encoder
                    .encode(&frame, start + Duration::from_micros(index * FRAME_US))
                    .expect("encodes");
                let took = began.elapsed().as_micros();

                if let Some(encoded) = encoded {
                    bytes += encoded.data.len();
                    if encoded.keyframe {
                        idr_us.push(took);
                    } else {
                        p_frame_us.push(took);
                    }
                }
            }

            println!(
                "{width}x{height} BGRA -> {}x{} H.264, {} kbit/s target",
                size.0,
                size.1,
                profile.target_bitrate_bps(size.0, size.1) / 1000
            );
            summary("convert alone", &mut convert_us);
            let p_mean = summary("P-frame, convert+encode", &mut p_frame_us);
            summary("IDR, convert+encode", &mut idr_us);
            println!(
                "  {} encoded, {} skipped by rate control, {:.0} kbit/s produced",
                encoder.stats().encoded,
                encoder.stats().skipped_by_rate_control,
                bytes as f64 * 8.0 / (FRAMES as f64 * FRAME_US as f64 / 1_000_000.0) / 1000.0
            );

            // The requirement is the default profile's own ceiling. The
            // larger screens are reported, not asserted: they depend on the
            // machine far more than on this code, and on a slow one they run
            // below 30 fps — dropped frames, not a queue.
            if (width, height) == (1920, 1080) {
                assert!(
                    p_mean < interval_us,
                    "a 1080p P-frame took longer than the frame interval"
                );
            }
        }
    }
}
