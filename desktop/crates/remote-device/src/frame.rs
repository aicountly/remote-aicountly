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
    ///
    /// One buffer, three planes, one after another: the luma plane of
    /// `height` rows `stride` bytes apart, then the U plane and then the V
    /// plane, each of `ceil(height / 2)` rows `ceil(stride / 2)` bytes apart.
    /// That is the layout every I420 producer this product is likely to meet
    /// writes, and writing it down here is what lets a capture and the
    /// encoder agree on it without either reading the other's code.
    I420,
    /// NV12, for a hardware encoder that prefers it.
    ///
    /// The luma plane as for [`PixelFormat::I420`], then one plane of
    /// interleaved U and V samples, `ceil(height / 2)` rows `stride` bytes
    /// apart.
    Nv12,
}

impl PixelFormat {
    /// Bytes per pixel for a packed format, and `None` for a planar one,
    /// whose planes do not share a pixel size.
    #[must_use]
    pub fn bytes_per_pixel(self) -> Option<usize> {
        match self {
            Self::Bgra8 | Self::Rgba8 => Some(4),
            Self::I420 | Self::Nv12 => None,
        }
    }

    /// The narrowest a row of `width` pixels can be, in bytes: the whole
    /// pixel row for a packed format, the luma row for a planar one.
    ///
    /// A stride below this is a buffer whose rows overlap, and reading one is
    /// reading the next row's pixels — or past the end of the buffer.
    #[must_use]
    pub fn min_stride(self, width: u32) -> usize {
        self.bytes_per_pixel()
            .unwrap_or(1)
            .saturating_mul(width as usize)
    }

    /// How many bytes a frame of `height` rows `stride` bytes apart must
    /// carry, planes included. `None` when the arithmetic overflows, which no
    /// real screen does and which is therefore a frame to refuse.
    #[must_use]
    pub fn required_len(self, height: u32, stride: usize) -> Option<usize> {
        let rows = height as usize;
        let luma = stride.checked_mul(rows)?;
        let chroma_rows = rows.div_ceil(2);

        match self {
            Self::Bgra8 | Self::Rgba8 => Some(luma),
            Self::I420 => {
                let chroma = stride.div_ceil(2).checked_mul(chroma_rows)?;

                luma.checked_add(chroma.checked_mul(2)?)
            }
            Self::Nv12 => luma.checked_add(stride.checked_mul(chroma_rows)?),
        }
    }
}

/// The three planes of an I420 [`Frame`], borrowed.
///
/// See [`PixelFormat::I420`] for the layout. Returned by
/// [`Frame::i420_planes`], which has already checked that every row of every
/// plane is inside the buffer.
#[derive(Clone, Copy)]
pub struct I420Planes<'a> {
    /// The luma plane: `height` rows, `y_stride` bytes apart.
    pub y: &'a [u8],
    /// The U plane: `ceil(height / 2)` rows, `uv_stride` bytes apart.
    pub u: &'a [u8],
    /// The V plane, laid out as the U plane.
    pub v: &'a [u8],
    /// Bytes between luma rows.
    pub y_stride: usize,
    /// Bytes between chroma rows.
    pub uv_stride: usize,
}

/// Prints the strides and plane sizes. Never pixels, for the reason
/// [`Frame`]'s `Debug` gives.
impl fmt::Debug for I420Planes<'_> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("I420Planes")
            .field("y_stride", &self.y_stride)
            .field("uv_stride", &self.uv_stride)
            .field("y_bytes", &self.y.len())
            .field("uv_bytes", &self.u.len())
            .finish_non_exhaustive()
    }
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
    /// buffer it will read past the end of. "Agree" is per format: a BGRA
    /// row is four bytes a pixel, so a stride of `width` — which an earlier
    /// version of this check accepted — is a quarter of a row.
    #[must_use]
    pub fn new(
        width: u32,
        height: u32,
        stride: usize,
        format: PixelFormat,
        timestamp_us: u64,
        data: Vec<u8>,
    ) -> Option<Self> {
        let frame = Self {
            width,
            height,
            stride,
            format,
            timestamp_us,
            data,
        };

        frame.is_well_formed().then_some(frame)
    }

    /// Whether the declared geometry matches the bytes.
    ///
    /// The same test [`Frame::new`] applies, available on its own because the
    /// fields are public and a frame can be built — or altered — without
    /// going through `new`. The encoder asks again rather than trusting that
    /// nobody did.
    #[must_use]
    pub fn is_well_formed(&self) -> bool {
        if self.width == 0 || self.height == 0 {
            return false;
        }

        if self.stride < self.format.min_stride(self.width) {
            return false;
        }

        self.format
            .required_len(self.height, self.stride)
            .is_some_and(|required| self.data.len() >= required)
    }

    /// The planes of an I420 frame, or `None` for any other format or for a
    /// buffer too short to hold them.
    #[must_use]
    pub fn i420_planes(&self) -> Option<I420Planes<'_>> {
        if self.format != PixelFormat::I420 || !self.is_well_formed() {
            return None;
        }

        let rows = self.height as usize;
        let uv_stride = self.stride.div_ceil(2);
        let luma = self.stride.checked_mul(rows)?;
        let chroma = uv_stride.checked_mul(rows.div_ceil(2))?;

        let (y, rest) = self.data.split_at_checked(luma)?;
        let (u, rest) = rest.split_at_checked(chroma)?;
        let v = rest.get(..chroma)?;

        Some(I420Planes {
            y,
            u,
            v,
            y_stride: self.stride,
            uv_stride,
        })
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
            return Self {
                max_dimension: 1280,
                ..self
            };
        }

        if self.max_dimension > 960 {
            return Self {
                max_dimension: 960,
                ..self
            };
        }

        if self.max_fps > 15 {
            return Self {
                max_fps: 15,
                ..self
            };
        }

        Self {
            max_fps: self.max_fps.clamp(5, 8),
            ..self
        }
    }

    /// Step back up towards the ceiling for this quality, one step at a time.
    #[must_use]
    pub fn improved(self) -> Self {
        let ceiling = Self::for_quality(self.quality);

        if self.max_fps < ceiling.max_fps {
            return Self {
                max_fps: (self.max_fps * 2).min(ceiling.max_fps),
                ..self
            };
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

    /// The bitrate to encode a `width` × `height` picture at under this
    /// profile, in bits per second.
    ///
    /// Derived rather than stored, so that every step [`Self::degraded`] and
    /// [`Self::improved`] take — and therefore every step
    /// `Congestion::adjust` takes — moves the bitrate with the resolution and
    /// frame rate, and there is no third number to forget to move with them.
    ///
    /// The figure is bits per pixel per frame, the usual way to compare
    /// encodings of different sizes. Screen content is mostly still and mostly
    /// flat, and H.264 spends almost nothing on a macroblock that has not
    /// changed, so the rates are lower than camera video would need:
    /// 0.06 puts 1080p30 at about 3.7 Mbit/s, which keeps 9-point text sharp
    /// while a window is dragged. Low bandwidth asks for less per pixel as
    /// well as fewer pixels — it is the profile for a link that is the
    /// problem. The floor keeps a heavily degraded session readable; the
    /// ceiling is what a home uplink will carry without becoming the reason
    /// the session stutters.
    #[must_use]
    pub fn target_bitrate_bps(&self, width: u32, height: u32) -> u32 {
        const FLOOR_BPS: f64 = 150_000.0;
        const CEILING_BPS: f64 = 8_000_000.0;

        let bits_per_pixel = match self.quality {
            CaptureQuality::LowBandwidth => 0.05,
            CaptureQuality::Adaptive => 0.06,
            CaptureQuality::HighQuality => 0.08,
        };

        let pixels_per_second =
            f64::from(width) * f64::from(height) * f64::from(self.max_fps.max(1));

        (pixels_per_second * bits_per_pixel).clamp(FLOOR_BPS, CEILING_BPS) as u32
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

    /// A BGRA row is four bytes a pixel. A stride of `width` is a quarter of
    /// a row, and an encoder trusting it reads three quarters of the frame
    /// from the wrong place.
    #[test]
    fn a_packed_stride_is_measured_in_bytes_not_pixels() {
        // 4 pixels wide needs at least 16 bytes a row.
        assert!(Frame::new(4, 2, 4, PixelFormat::Bgra8, 0, vec![0; 64]).is_none());
        assert!(Frame::new(4, 2, 16, PixelFormat::Rgba8, 0, vec![0; 32]).is_some());

        // Windows pads rows to an alignment; a padded stride is fine as long
        // as the buffer holds every padded row.
        assert!(Frame::new(4, 2, 64, PixelFormat::Bgra8, 0, vec![0; 128]).is_some());
        assert!(Frame::new(4, 2, 64, PixelFormat::Bgra8, 0, vec![0; 127]).is_none());
    }

    /// The fields are public, so a frame can be built without `new`. The
    /// encoder asks the frame itself rather than trusting how it was made.
    #[test]
    fn a_frame_built_by_hand_can_still_be_checked() {
        let mut frame = Frame::new(2, 2, 8, PixelFormat::Bgra8, 0, vec![0; 16]).unwrap();
        assert!(frame.is_well_formed());

        frame.width = 3;
        assert!(!frame.is_well_formed(), "a wider frame on the same bytes");

        frame.width = 2;
        frame.data.truncate(15);
        assert!(!frame.is_well_formed(), "a truncated buffer");
    }

    /// The I420 layout is written down once, on `PixelFormat::I420`, and both
    /// the length check and the plane split follow it — including for an odd
    /// height, whose last chroma row covers a single luma row.
    #[test]
    fn i420_planes_follow_the_documented_layout() {
        // 4×3 with a stride of 6: luma 6×3, chroma ceil(6/2)=3 × ceil(3/2)=2.
        assert_eq!(PixelFormat::I420.required_len(3, 6), Some(18 + 6 + 6));
        assert_eq!(PixelFormat::Nv12.required_len(3, 6), Some(18 + 12));

        let mut data = vec![1; 18];
        data.extend([2; 6]);
        data.extend([3; 6]);

        let frame = Frame::new(4, 3, 6, PixelFormat::I420, 0, data).unwrap();
        let planes = frame.i420_planes().expect("an I420 frame has planes");

        assert_eq!(planes.y_stride, 6);
        assert_eq!(planes.uv_stride, 3);
        assert!(planes.y.iter().all(|&b| b == 1) && planes.y.len() == 18);
        assert!(planes.u.iter().all(|&b| b == 2) && planes.u.len() == 6);
        assert!(planes.v.iter().all(|&b| b == 3) && planes.v.len() == 6);

        // One byte short of the V plane.
        assert!(Frame::new(4, 3, 6, PixelFormat::I420, 0, vec![0; 29]).is_none());

        // And a packed frame has no planes to hand out.
        let packed = Frame::new(2, 2, 8, PixelFormat::Bgra8, 0, vec![0; 16]).unwrap();
        assert!(packed.i420_planes().is_none());
    }

    /// Geometry nobody's screen has must be refused, not wrapped round into a
    /// small number that a short buffer then satisfies.
    #[test]
    fn overflowing_geometry_is_refused() {
        assert_eq!(PixelFormat::Bgra8.required_len(u32::MAX, usize::MAX), None);
        assert!(Frame::new(u32::MAX, u32::MAX, usize::MAX, PixelFormat::I420, 0, vec![]).is_none());
    }

    /// The bitrate follows the profile, so every step congestion takes moves
    /// it too, and it stays inside the floor and the ceiling.
    #[test]
    fn the_bitrate_follows_resolution_frame_rate_and_quality() {
        let adaptive = CaptureProfile::adaptive();
        let full_hd = adaptive.target_bitrate_bps(1920, 1080);

        assert!(
            (3_000_000..=4_500_000).contains(&full_hd),
            "1080p30 adaptive was {full_hd}"
        );

        // Fewer pixels, fewer bits.
        assert!(adaptive.target_bitrate_bps(1280, 720) < full_hd);

        // Fewer frames, fewer bits.
        let slower = CaptureProfile {
            max_fps: 15,
            ..adaptive
        };
        assert!(slower.target_bitrate_bps(1920, 1080) < full_hd);

        // Low bandwidth means less than adaptive at the same size.
        let low = CaptureProfile::low_bandwidth();
        assert!(low.target_bitrate_bps(1280, 720) < adaptive.target_bitrate_bps(1280, 720));

        // Bounded at both ends, whatever the profile says.
        let crawling = CaptureProfile {
            max_fps: 1,
            ..CaptureProfile::low_bandwidth()
        };
        assert_eq!(crawling.target_bitrate_bps(64, 64), 150_000);
        assert_eq!(
            CaptureProfile::high_quality().target_bitrate_bps(3840, 2160),
            8_000_000
        );

        // Every congestion step lowers it; none raises it.
        let mut profile = adaptive;
        let mut previous = profile.target_bitrate_bps(1920, 1080);
        for _ in 0..5 {
            profile = profile.degraded();
            let (width, height) = profile.fit(1920, 1080);
            let now = profile.target_bitrate_bps(width, height);
            assert!(now <= previous, "{now} after {previous}");
            previous = now;
        }
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

        assert!(
            profile.max_fps >= 5,
            "frame rate must not collapse to nothing"
        );
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

        let zero = CaptureProfile {
            max_fps: 0,
            ..CaptureProfile::adaptive()
        };
        assert_eq!(zero.frame_interval_ms(), 1000, "must never divide by zero");
    }
}
