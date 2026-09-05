//! Captured frames, and the profiles that decide how many of them there are.
//!
//! A [`Frame`] exists to be handed to the encoder and then dropped. It is
//! never written to disk, never sent to the API, never cached and never
//! retained past the encode — screen pixels are not stored anywhere in this
//! product, and nothing in this module offers a way to store one.

use serde::{Deserialize, Serialize};
use std::fmt;

/// How a captured frame's bytes are laid out.
///
/// `Bgra8` is what `Windows.Graphics.Capture` hands back, so it is listed
/// first and is the one the Windows path uses without a conversion.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum PixelFormat {
    /// 32-bit BGRA, the Windows capture format.
    Bgra8,
    /// 32-bit RGBA.
    Rgba8,
    /// Planar I420 — what most encoders actually want.
    I420,
    /// NV12, for a hardware encoder that prefers it.
    Nv12,
}

/// One captured frame.
///
/// `Debug` prints the geometry and the byte count, never the bytes: a frame in
/// a log line is a screenshot in a log file.
#[derive(Clone)]
pub struct Frame {
    /// Width in pixels.
    pub width: u32,
    /// Height in pixels.
    pub height: u32,
    /// Bytes per row, which is not always `width * 4` — the platform pads.
    pub stride: usize,
    /// The layout of `data`.
    pub format: PixelFormat,
    /// Microseconds since the capture started. Monotonic.
    pub timestamp_us: u64,
    /// The pixels.
    pub data: Vec<u8>,
}

impl Frame {
    /// A frame whose declared geometry matches the bytes it carries.
    ///
    /// Returns `None` when they disagree, rather than handing an encoder a
    /// buffer it will read past the end of.
    #[must_use]
    pub fn new(
        width: u32,
        height: u32,
        stride: usize,
        format: PixelFormat,
        timestamp_us: u64,
        data: Vec<u8>,
    ) -> Option<Self> {
        if width == 0 || height == 0 {
            return None;
        }

        if stride < (width as usize) || data.len() < stride * (height as usize) {
            return None;
        }

        Some(Self { width, height, stride, format, timestamp_us, data })
    }

    /// How many bytes this frame occupies.
    #[must_use]
    pub fn byte_len(&self) -> usize {
        self.data.len()
    }
}

/// Prints geometry and size. Never pixels.
impl fmt::Debug for Frame {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Frame")
            .field("width", &self.width)
            .field("height", &self.height)
            .field("format", &self.format)
            .field("timestamp_us", &self.timestamp_us)
            .field("bytes", &self.data.len())
            .finish_non_exhaustive()
    }
}

/// How hard to capture.
///
/// The three named profiles are the ones the product offers. `Adaptive` is the
/// default and is what congestion feedback moves between: it starts at the
/// ceiling and comes down when the link says it must, rather than pretending
/// 1080p30 is always achievable.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum CaptureQuality {
    /// Up to 1920×1080 at up to 30 fps, coming down under congestion.
    Adaptive,
    /// Deliberately small: 1280×720 at 12 fps, for a metered or poor link.
    LowBandwidth,
    /// The best the peer and the network say they can carry.
    HighQuality,
}

/// A concrete capture configuration.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
pub struct CaptureProfile {
    /// Which named profile this came from.
    pub quality: CaptureQuality,
    /// Longest edge, in pixels. The capture is scaled to fit inside it.
    pub max_dimension: u32,
    /// Frames per second.
    pub max_fps: u32,
    /// Whether to draw the cursor into the frame.
    ///
    /// On by default: a viewer helping somebody needs to see where they are
    /// pointing, and a screen share with an invisible cursor is much harder to
    /// follow than people expect.
    pub include_cursor: bool,
}

impl CaptureProfile {
    /// The default: 1080p at 30, adapting downwards.
    #[must_use]
    pub fn adaptive() -> Self {
        Self {
            quality: CaptureQuality::Adaptive,
            max_dimension: 1920,
            max_fps: 30,
            include_cursor: true,
        }
    }

    /// For a metered or poor link.
    #[must_use]
    pub fn low_bandwidth() -> Self {
        Self {
            quality: CaptureQuality::LowBandwidth,
            max_dimension: 1280,
            max_fps: 12,
            include_cursor: true,
        }
    }

    /// The best the link will carry.
    #[must_use]
    pub fn high_quality() -> Self {
        Self {
            quality: CaptureQuality::HighQuality,
            max_dimension: 2560,
            max_fps: 30,
            include_cursor: true,
        }
    }

    /// The profile for a named quality.
    #[must_use]
    pub fn for_quality(quality: CaptureQuality) -> Self {
        match quality {
            CaptureQuality::Adaptive => Self::adaptive(),
            CaptureQuality::LowBandwidth => Self::low_bandwidth(),
            CaptureQuality::HighQuality => Self::high_quality(),
        }
    }

    /// Step down when the link says it cannot keep up.
    ///
    /// Resolution first, then frame rate. A smaller, smooth picture is easier
    /// to work in than a sharp one that stutters — and stepping both at once
    /// overshoots, so the next reading says there is headroom and it steps
    /// back up, and the session oscillates.
    #[must_use]
    pub fn degraded(self) -> Self {
        if self.max_dimension > 1280 {
            return Self { max_dimension: 1280, ..self };
        }

        if self.max_dimension > 960 {
            return Self { max_dimension: 960, ..self };
        }

        if self.max_fps > 15 {
            return Self { max_fps: 15, ..self };
        }

        Self { max_fps: self.max_fps.max(5).min(8), ..self }
    }

    /// Step back up towards the ceiling for this quality, one step at a time.
    #[must_use]
    pub fn improved(self) -> Self {
        let ceiling = Self::for_quality(self.quality);

        if self.max_fps < ceiling.max_fps {
            return Self { max_fps: (self.max_fps * 2).min(ceiling.max_fps), ..self };
        }

        if self.max_dimension < ceiling.max_dimension {
            return Self {
                max_dimension: (self.max_dimension * 4 / 3).min(ceiling.max_dimension),
                ..self
            };
        }

        self
    }

    /// Scale a monitor's real size down to fit this profile.
    ///
    /// Even dimensions, because every video encoder wants them and an odd
    /// height is how a stream fails to start with an error nobody can read.
    #[must_use]
    pub fn fit(&self, width: u32, height: u32) -> (u32, u32) {
        let longest = width.max(height);

        if longest <= self.max_dimension || longest == 0 {
            return (even(width), even(height));
        }

        let scale = f64::from(self.max_dimension) / f64::from(longest);

        (
            even((f64::from(width) * scale).round() as u32),
            even((f64::from(height) * scale).round() as u32),
        )
    }

    /// The gap between frames, in milliseconds.
    #[must_use]
    pub fn frame_interval_ms(&self) -> u64 {
        1000 / u64::from(self.max_fps.max(1))
    }
}

fn even(value: u32) -> u32 {
    value.max(2) & !1
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_frame_must_carry_the_bytes_it_claims() {
        assert!(Frame::new(2, 2, 8, PixelFormat::Bgra8, 0, vec![0; 16]).is_some());

        // Fewer bytes than the geometry says: an encoder would read past the
        // end of the buffer.
        assert!(Frame::new(2, 2, 8, PixelFormat::Bgra8, 0, vec![0; 15]).is_none());
        assert!(Frame::new(0, 2, 8, PixelFormat::Bgra8, 0, vec![0; 16]).is_none());
        assert!(Frame::new(4, 2, 2, PixelFormat::Bgra8, 0, vec![0; 16]).is_none());
    }

    /// A frame in a log line is a screenshot in a log file.
    #[test]
    fn debug_output_never_contains_pixels() {
        let frame = Frame::new(2, 2, 8, PixelFormat::Bgra8, 42, vec![0xAB; 16]).unwrap();

        let rendered = format!("{frame:?}");

        assert!(rendered.contains("bytes: 16"));
        assert!(!rendered.contains("171"));
        assert!(!rendered.contains("ab"));
    }

    #[test]
    fn the_named_profiles_are_what_the_product_offers() {
        assert_eq!(CaptureProfile::adaptive().max_dimension, 1920);
        assert_eq!(CaptureProfile::adaptive().max_fps, 30);
        assert_eq!(CaptureProfile::low_bandwidth().max_fps, 12);
        assert_eq!(CaptureProfile::high_quality().max_dimension, 2560);
    }

    #[test]
    fn a_monitor_is_scaled_to_fit_the_profile() {
        let profile = CaptureProfile::adaptive();

        // Already inside the ceiling: untouched.
        assert_eq!(profile.fit(1920, 1080), (1920, 1080));

        // A 4K display comes down to the ceiling, keeping its aspect ratio.
        assert_eq!(profile.fit(3840, 2160), (1920, 1080));

        // A portrait display is bounded by its longest edge.
        assert_eq!(profile.fit(1080, 3840), (540, 1920));
    }

    /// Every encoder wants even dimensions; an odd one fails to start with an
    /// error nobody can read.
    #[test]
    fn scaled_dimensions_are_always_even() {
        let profile = CaptureProfile::low_bandwidth();

        for (width, height) in [(1366, 768), (1920, 1017), (3441, 1440), (1, 1)] {
            let (w, h) = profile.fit(width, height);

            assert_eq!(w % 2, 0, "{width}x{height} gave an odd width");
            assert_eq!(h % 2, 0, "{width}x{height} gave an odd height");
        }
    }

    /// Resolution first, then frame rate: a smaller smooth picture beats a
    /// sharp stuttering one, and stepping both at once makes the session
    /// oscillate.
    #[test]
    fn degrading_drops_resolution_before_frame_rate() {
        let start = CaptureProfile::adaptive();

        let first = start.degraded();
        assert_eq!(first.max_dimension, 1280);
        assert_eq!(first.max_fps, 30);

        let second = first.degraded();
        assert_eq!(second.max_dimension, 960);
        assert_eq!(second.max_fps, 30);

        let third = second.degraded();
        assert_eq!(third.max_fps, 15);
    }

    #[test]
    fn degrading_bottoms_out_rather_than_reaching_zero() {
        let mut profile = CaptureProfile::adaptive();

        for _ in 0..20 {
            profile = profile.degraded();
        }

        assert!(profile.max_fps >= 5, "frame rate must not collapse to nothing");
        assert!(profile.max_dimension >= 960);
    }

    #[test]
    fn improving_climbs_back_to_the_profiles_ceiling_and_stops() {
        let mut profile = CaptureProfile::adaptive().degraded().degraded().degraded();

        for _ in 0..20 {
            profile = profile.improved();
        }

        let ceiling = CaptureProfile::adaptive();
        assert_eq!(profile.max_dimension, ceiling.max_dimension);
        assert_eq!(profile.max_fps, ceiling.max_fps);
    }

    #[test]
    fn the_frame_interval_follows_the_frame_rate() {
        assert_eq!(CaptureProfile::adaptive().frame_interval_ms(), 33);
        assert_eq!(CaptureProfile::low_bandwidth().frame_interval_ms(), 83);

        let zero = CaptureProfile { max_fps: 0, ..CaptureProfile::adaptive() };
        assert_eq!(zero.frame_interval_ms(), 1000, "must never divide by zero");
    }
}
