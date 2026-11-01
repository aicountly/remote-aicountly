//! Downscaling and pacing the Secure Desktop capture.
//!
//! Pure arithmetic, so the two things most easily got wrong — the size a frame
//! is reduced to, and how often one is sent — are tested on every host rather
//! than only on a Windows runner. The GDI capture in [`super::helper`] does the
//! `BitBlt`; everything about *what* it then sends is decided here.
//!
//! # Why downscale, and why pace
//!
//! The Secure Desktop is a logon-style screen: a few controls on a plain
//! background. It does not need a full-resolution, high-frame-rate stream, and
//! sending one over the dedicated pipe and then through the encoder would cost
//! far more than the picture is worth. At most 1280 px wide and at most ten
//! frames a second is comfortably enough to read a UAC prompt and click its
//! buttons, and it keeps the pipe payloads small.

use super::protocol::HelperFrame;

/// The widest a helper frame is sent. Taller-than-wide screens scale by the
/// same factor, so the long edge is what this bounds.
pub const MAX_HELPER_WIDTH: u32 = 1280;

/// The most frames a second the helper sends.
pub const MAX_HELPER_FPS: u32 = 10;

/// The size a capture of `width`×`height` is reduced to, preserving aspect and
/// never enlarging.
///
/// Both dimensions are floored at one: a zero-sized frame is not a frame, and
/// a round-to-zero on a very thin capture would produce one. A capture already
/// within the ceiling keeps its size exactly.
#[must_use]
pub fn target_dimensions(width: u32, height: u32, max_width: u32) -> (u32, u32) {
    if width == 0 || height == 0 {
        return (1, 1);
    }

    if width <= max_width {
        return (width, height);
    }

    // Scale the long edge (width) down to the ceiling and the short edge by the
    // same ratio. Done in f64 and rounded so a 1366-wide screen does not lose a
    // column to integer truncation.
    let ratio = f64::from(max_width) / f64::from(width);
    let height = ((f64::from(height) * ratio).round() as u32).max(1);

    (max_width, height)
}

/// Nearest-neighbour downscale of a tightly-or-strided BGRA buffer to at most
/// `max_width` wide.
///
/// Nearest-neighbour rather than anything smoother on purpose: the Secure
/// Desktop is text and flat fills, the helper runs as SYSTEM where spending CPU
/// on a bilinear kernel is least welcome, and a sharp logon screen reads better
/// than a blurred one. `src_stride` is the byte distance between rows, which a
/// DIB section reports and which equals `width * 4` for the DIBs this uses but
/// is taken as an argument rather than assumed.
///
/// Returns `None` when the source buffer is too small for the dimensions and
/// stride it was given — a mismatch the caller should treat as a failed frame
/// rather than read past.
#[must_use]
pub fn downscale_bgra(
    src: &[u8],
    width: u32,
    height: u32,
    src_stride: usize,
    max_width: u32,
) -> Option<HelperFrame> {
    if width == 0 || height == 0 {
        return None;
    }

    // The last row must be fully present: (height - 1) full strides, then one
    // row of width*4 pixels.
    let needed = src_stride
        .checked_mul(height.saturating_sub(1) as usize)?
        .checked_add((width as usize).checked_mul(4)?)?;

    if src.len() < needed || src_stride < (width as usize) * 4 {
        return None;
    }

    let (dst_width, dst_height) = target_dimensions(width, height, max_width);

    // The common case — no reduction needed — still repacks to a tight buffer,
    // because the source may be strided and the protocol frame is always tight.
    let mut out = vec![0_u8; (dst_width as usize) * (dst_height as usize) * 4];

    for dy in 0..dst_height {
        // Map the destination row to a source row. Multiply before divide so
        // the rounding is even across the image rather than biased to the top.
        let sy = ((dy as u64 * height as u64) / dst_height as u64) as usize;
        let src_row = sy * src_stride;
        let dst_row = (dy as usize) * (dst_width as usize) * 4;

        for dx in 0..dst_width {
            let sx = ((dx as u64 * width as u64) / dst_width as u64) as usize;
            let src_px = src_row + sx * 4;
            let dst_px = dst_row + (dx as usize) * 4;

            // Four bytes, BGRA. The bounds were checked above for the largest
            // sx/sy this loop produces, so these indices are in range.
            out[dst_px..dst_px + 4].copy_from_slice(&src[src_px..src_px + 4]);
        }
    }

    Some(HelperFrame {
        width: dst_width,
        height: dst_height,
        bgra: out,
    })
}

/// The gap between frames for a given frame-rate ceiling, in milliseconds.
#[must_use]
pub fn frame_interval_ms(max_fps: u32) -> u64 {
    1000 / u64::from(max_fps.max(1))
}

/// Decides, from a monotonic millisecond clock, whether a frame is due.
///
/// Stateful but still pure: the caller supplies the time, so the pacing is
/// tested without waiting. Holding the pacer rather than sleeping is what lets
/// the helper's loop also watch its pipe and its stop signal between frames.
#[derive(Debug, Clone, Copy)]
pub struct FramePacer {
    interval_ms: u64,
    next_due_ms: u64,
}

impl FramePacer {
    /// A pacer at `max_fps`, with the first frame due immediately.
    #[must_use]
    pub fn new(max_fps: u32) -> Self {
        Self {
            interval_ms: frame_interval_ms(max_fps),
            next_due_ms: 0,
        }
    }

    /// Whether a frame should be sent at `now_ms`, advancing the schedule when
    /// it is.
    ///
    /// The next slot is `now + interval`, not `previous + interval`, so a
    /// helper that stalled does not then burst a backlog of frames to catch up.
    pub fn due(&mut self, now_ms: u64) -> bool {
        if now_ms >= self.next_due_ms {
            self.next_due_ms = now_ms.saturating_add(self.interval_ms);

            true
        } else {
            false
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_capture_within_the_ceiling_keeps_its_size() {
        assert_eq!(target_dimensions(1024, 768, MAX_HELPER_WIDTH), (1024, 768));
        assert_eq!(target_dimensions(1280, 800, MAX_HELPER_WIDTH), (1280, 800));
    }

    #[test]
    fn a_wide_capture_scales_to_the_ceiling_keeping_aspect() {
        // 1920×1080 → 1280×720.
        assert_eq!(target_dimensions(1920, 1080, 1280), (1280, 720));
        // 2560×1440 → 1280×720.
        assert_eq!(target_dimensions(2560, 1440, 1280), (1280, 720));
        // 3840×2160 → 1280×720.
        assert_eq!(target_dimensions(3840, 2160, 1280), (1280, 720));
    }

    #[test]
    fn dimensions_never_round_to_zero() {
        assert_eq!(target_dimensions(10_000, 1, 1280), (1280, 1));
        assert_eq!(target_dimensions(0, 0, 1280), (1, 1));
    }

    #[test]
    fn downscaling_produces_a_tight_buffer_of_the_right_size() {
        // A 4×2 red/blue checker, strided tightly.
        let (w, h) = (4u32, 2u32);
        let src = vec![0x10; (w * h * 4) as usize];

        let frame = downscale_bgra(&src, w, h, (w * 4) as usize, 2).expect("downscales");

        assert_eq!((frame.width, frame.height), (2, 1));
        assert_eq!(frame.bgra.len(), (frame.width * frame.height * 4) as usize);
    }

    /// A source smaller than its stated size is refused rather than read past.
    #[test]
    fn a_too_small_source_is_refused() {
        assert!(downscale_bgra(&[0; 10], 4, 4, 16, 1280).is_none());
        // Stride narrower than a row is also refused.
        assert!(downscale_bgra(&[0; 64], 4, 4, 8, 1280).is_none());
    }

    /// A strided source (row padding beyond the pixels) is read at the right
    /// offsets and repacked tightly.
    #[test]
    fn a_strided_source_is_repacked_tightly() {
        let (w, h) = (2u32, 2u32);
        let stride = 16; // 8 bytes of pixels + 8 bytes of padding per row
        let mut src = vec![0u8; stride * h as usize];
        // First pixel of each row is distinctive.
        src[0] = 0xAA;
        src[stride] = 0xBB;

        let frame = downscale_bgra(&src, w, h, stride, 1280).expect("downscales");

        assert_eq!((frame.width, frame.height), (2, 2));
        assert_eq!(frame.bgra.len(), 16);
        assert_eq!(frame.bgra[0], 0xAA);
        assert_eq!(
            frame.bgra[8], 0xBB,
            "the second row starts after the pixels, not the stride"
        );
    }

    #[test]
    fn the_frame_interval_follows_the_fps_ceiling() {
        assert_eq!(frame_interval_ms(10), 100);
        assert_eq!(frame_interval_ms(1), 1000);
        // Never divides by zero.
        assert_eq!(frame_interval_ms(0), 1000);
    }

    #[test]
    fn the_pacer_emits_at_the_interval_and_does_not_burst_after_a_stall() {
        let mut pacer = FramePacer::new(10); // 100 ms

        assert!(pacer.due(0), "the first frame is due at once");
        assert!(!pacer.due(50), "too soon");
        assert!(pacer.due(100));
        assert!(!pacer.due(150));

        // A long stall: the next frame is due, but only one — the schedule
        // resets from now rather than firing the backlog.
        assert!(pacer.due(5_000));
        assert!(!pacer.due(5_050));
        assert!(pacer.due(5_100));
    }
}
