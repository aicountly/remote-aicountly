//! The screen track's video pipeline: a captured [`remote_device::Frame`] in,
//! H.264 a browser decodes out.
//!
//! ```text
//!   Frame (BGRA / RGBA / I420, any stride, any size)
//!     │  convert   crop the odd pixel, box-filter down to the profile's
//!     │            ceiling, BT.601 limited-range I420
//!     │  pacing    media time from the capture clock, the frame-rate gate,
//!     │            and when the next IDR is due
//!     │  h264      OpenH264, Constrained Baseline, screen-content tuning,
//!     │            bitrate rate control that skips rather than queues
//!     ▼
//!   Annex-B access unit (SPS + PPS + IDR, or one P slice)
//!     │  rtp       webrtc-rs's own H.264 payloader (STAP-A, FU-A) with an
//!     ▼            RTP timestamp taken from the capture, not from a guess
//!   RTP packets ─▶ the session's track ─▶ SRTP
//! ```
//!
//! # Why H.264, and why Constrained Baseline
//!
//! It is the one codec every browser this product supports decodes in
//! WebRTC — Chrome, Edge, Firefox and Safari — and Safari's support for
//! anything else has come and gone between releases. Constrained Baseline
//! (`profile-level-id=42e01f`) is the profile all four offer; it has no
//! B-frames, so a frame is decodable the moment it arrives, which is the only
//! latency property a remote desktop cares about.
//!
//! # Nothing here keeps a frame
//!
//! The working buffers — the I420 picture a frame is converted into, and the
//! reference picture inside OpenH264 that a P-frame is predicted from — hold
//! the most recent picture because that is what encoding *is*. They are
//! overwritten by the next frame, never exposed by any method, and freed with
//! the session. Nothing is written anywhere, and no method returns a frame.

pub mod annexb;
pub mod convert;
pub mod pacing;

#[cfg(feature = "openh264")]
pub mod h264;

#[cfg(feature = "webrtc-rs")]
pub(crate) mod rtp;

#[cfg(all(test, feature = "openh264"))]
pub(crate) mod testing;

pub use convert::{encoded_size, ConvertError, I420Picture};

#[cfg(feature = "openh264")]
pub use h264::{EncodedFrame, EncoderStats, H264Encoder, VideoError};

/// The SDP `fmtp` line the screen track is offered and answered with.
///
/// * `packetization-mode=1` — non-interleaved mode, which allows FU-A
///   fragments and STAP-A aggregates. Mode 0 would require every NAL unit to
///   fit one packet, which a 1080p keyframe never does.
/// * `profile-level-id=42e01f` — Constrained Baseline, level 3.1: the entry
///   every browser offers. The level is the *receiver's* declaration, and
///   with `level-asymmetry-allowed=1` the sender may encode at the level the
///   picture needs (4.0 for 1080p30), which OpenH264 states in the SPS.
///
/// Written in the order Chrome and webrtc-rs write it, because webrtc-rs's
/// statistics match an outbound codec by comparing this string exactly.
pub const H264_FMTP: &str =
    "level-asymmetry-allowed=1;packetization-mode=1;profile-level-id=42e01f";

/// The H.264 MIME type, as SDP and webrtc-rs spell it.
pub const H264_MIME_TYPE: &str = "video/H264";

/// The RTP clock rate for video, fixed by RFC 6184 at 90 kHz.
pub const VIDEO_CLOCK_RATE: u32 = 90_000;
