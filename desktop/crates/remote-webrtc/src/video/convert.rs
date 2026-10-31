//! Captured pixels into the picture the encoder takes.
//!
//! Three jobs, done in one pass over the frame because a 1080p frame is
//! eight megabytes and reading it twice is a measurable part of the frame
//! budget:
//!
//! * **Even dimensions.** 4:2:0 chroma covers two-by-two blocks, so H.264
//!   pictures here are always an even number of pixels in each direction. An
//!   odd column or row is cropped — one pixel of a screen edge — rather than
//!   the whole picture being resampled to lose it.
//! * **The profile's ceiling.** A 4K or Retina display is box-filtered down
//!   to fit [`CaptureProfile::max_dimension`]. A box filter — every output
//!   pixel the plain average of the source pixels it covers — is the right
//!   filter for screen content: text is high-contrast and axis-aligned, and a
//!   filter with negative lobes would ring around every glyph. The capture
//!   on Windows hands over the monitor's real size, so this is the one place
//!   the ceiling is enforced.
//! * **BGRA or RGBA to I420, BT.601 limited range.** Y in 16–235, Cb and Cr
//!   in 16–240, from the Rec. 601 luma weights (0.299, 0.587, 0.114). That is
//!   what WebRTC stacks assume when a stream says nothing, so a decoder that
//!   ignores the colour description in the SPS still shows the right colours;
//!   the encoder states it in the SPS as well, for the ones that read it.
//!
//! Strides are honoured everywhere: `Windows.Graphics.Capture` pads rows to
//! an alignment, and reading a padded buffer as though it were packed shears
//! the picture diagonally.
//!
//! Nothing in this file can panic on a malformed frame. Every row is fetched
//! with a bounds-checked slice, and a frame whose geometry does not fit its
//! buffer is refused with [`ConvertError::Malformed`] — the release build
//! aborts on a panic, and a remote-control agent that a crafted frame could
//! abort is not one to ship.

use std::fmt;

use remote_device::{CaptureProfile, Frame, PixelFormat};

/// The longest edge OpenH264 will encode: level 5.2, the highest it
/// implements, is 3840 × 2160 (or 2160 × 3840) at most.
pub const MAX_LONG_EDGE: u32 = 3840;

/// The shortest edge OpenH264 will encode, for the same reason.
pub const MAX_SHORT_EDGE: u32 = 2160;

/// The smallest ceiling a profile may impose: one macroblock. Below that a
/// picture is not worth the bits its headers cost.
const MIN_CEILING: u32 = 16;

/// Why a frame could not be turned into a picture.
#[derive(Debug, Clone, Copy, PartialEq, Eq, thiserror::Error)]
pub enum ConvertError {
    /// The frame's size, stride and byte count disagree.
    #[error("the frame's size, stride and length disagree")]
    Malformed,

    /// A layout this encoder does not take yet.
    #[error("{0:?} frames cannot be encoded")]
    UnsupportedFormat(PixelFormat),

    /// Smaller than one chroma block.
    #[error("a {width}x{height} frame is too small to encode")]
    TooSmall {
        /// The frame's width.
        width: u32,
        /// The frame's height.
        height: u32,
    },
}

/// The size a `width` × `height` frame is encoded at under `profile`.
///
/// The profile's [`CaptureProfile::fit`] — longest edge inside
/// `max_dimension`, both edges even — with the ceiling held to what OpenH264
/// can encode. Never larger than the frame: a picture is scaled down or
/// cropped by a pixel, never up.
#[must_use]
pub fn encoded_size(profile: &CaptureProfile, width: u32, height: u32) -> (u32, u32) {
    let ceiling = CaptureProfile {
        max_dimension: profile.max_dimension.clamp(MIN_CEILING, MAX_LONG_EDGE),
        ..*profile
    };

    let (mut fitted_width, mut fitted_height) = ceiling.fit(width, height);

    // A square or near-square display can be inside the long-edge limit and
    // still past the short-edge one.
    let short = fitted_width.min(fitted_height);
    if short > MAX_SHORT_EDGE {
        let scale = f64::from(MAX_SHORT_EDGE) / f64::from(short);
        fitted_width = even((f64::from(fitted_width) * scale) as u32);
        fitted_height = even((f64::from(fitted_height) * scale) as u32);
    }

    // `fit` rounds a one-pixel edge up to two; that is the only way it could
    // grow a frame, and a frame that small is refused before it gets here.
    (
        fitted_width.min(even(width)),
        fitted_height.min(even(height)),
    )
}

/// Round down to even, and never below two.
fn even(value: u32) -> u32 {
    (value & !1).max(2)
}

/// An I420 picture: a full-resolution luma plane and two quarter-resolution
/// chroma planes, tightly packed.
///
/// One lives for the whole session and is overwritten by every frame, which
/// is what keeps a 3 MB allocation out of the per-frame path. It is never
/// handed out except to the encoder, and `Debug` prints its size, never its
/// pixels.
#[derive(Default)]
pub struct I420Picture {
    width: u32,
    height: u32,
    y: Vec<u8>,
    u: Vec<u8>,
    v: Vec<u8>,
}

impl I420Picture {
    /// An empty picture, sized by the first conversion into it.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// Width in pixels. Always even.
    #[must_use]
    pub fn width(&self) -> u32 {
        self.width
    }

    /// Height in pixels. Always even.
    #[must_use]
    pub fn height(&self) -> u32 {
        self.height
    }

    /// The luma plane, `width` bytes a row.
    #[must_use]
    pub fn y(&self) -> &[u8] {
        &self.y
    }

    /// The Cb plane, `width / 2` bytes a row.
    #[must_use]
    pub fn u(&self) -> &[u8] {
        &self.u
    }

    /// The Cr plane, `width / 2` bytes a row.
    #[must_use]
    pub fn v(&self) -> &[u8] {
        &self.v
    }

    /// Resize the planes for a new picture size. Keeps the allocation when
    /// the size has not changed, which is every frame but the first.
    fn reshape(&mut self, width: u32, height: u32) {
        let luma = width as usize * height as usize;

        self.width = width;
        self.height = height;
        self.y.resize(luma, 0);
        self.u.resize(luma / 4, 0);
        self.v.resize(luma / 4, 0);
    }
}

/// Prints the size. Never pixels.
impl fmt::Debug for I420Picture {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("I420Picture")
            .field("width", &self.width)
            .field("height", &self.height)
            .finish_non_exhaustive()
    }
}

/// Convert `frame` into `picture` at `size`.
///
/// `size` is normally [`encoded_size`] of the frame. When it is the frame's
/// own size rounded down to even, the odd column and row are cropped and the
/// pixels copied one for one; any other size is box-filtered from the whole
/// frame.
///
/// # Errors
///
/// [`ConvertError::Malformed`] for a frame whose geometry does not fit its
/// bytes, [`ConvertError::TooSmall`] below 2 × 2, and
/// [`ConvertError::UnsupportedFormat`] for NV12.
pub fn convert_into(
    frame: &Frame,
    size: (u32, u32),
    picture: &mut I420Picture,
) -> Result<(), ConvertError> {
    if !frame.is_well_formed() {
        return Err(ConvertError::Malformed);
    }

    if frame.width < 2 || frame.height < 2 {
        return Err(ConvertError::TooSmall {
            width: frame.width,
            height: frame.height,
        });
    }

    let width = size.0 & !1;
    let height = size.1 & !1;

    if width < 2 || height < 2 {
        return Err(ConvertError::TooSmall { width, height });
    }

    // A crop when the picture is the frame minus its odd pixel; otherwise the
    // whole frame is the source of a resample.
    let cropped = width == frame.width & !1 && height == frame.height & !1;
    let region = if cropped {
        (width as usize, height as usize)
    } else {
        (frame.width as usize, frame.height as usize)
    };

    picture.reshape(width, height);

    match frame.format {
        PixelFormat::Bgra8 => packed::<2, 0>(&frame.data, frame.stride, region, picture),
        PixelFormat::Rgba8 => packed::<0, 2>(&frame.data, frame.stride, region, picture),
        PixelFormat::I420 => planar(frame, cropped, picture),
        PixelFormat::Nv12 => Err(ConvertError::UnsupportedFormat(PixelFormat::Nv12)),
    }
}

// --- the colour transform -------------------------------------------------
//
// BT.601, limited range, in 16-bit fixed point. Each coefficient is the
// Rec. 601 weight scaled to the limited range (219/255 for luma, 224/255 for
// chroma) times 65536, rounded so that each chroma row sums to exactly zero —
// so a grey of any level has Cb = Cr = 128 with no drift.

const Y_R: i32 = 16_829;
const Y_G: i32 = 33_039;
const Y_B: i32 = 6_416;

const U_R: i32 = -9_714;
const U_G: i32 = -19_070;
const U_B: i32 = 28_784;

const V_R: i32 = 28_784;
const V_G: i32 = -24_103;
const V_B: i32 = -4_681;

/// Luma for one pixel.
#[inline(always)]
fn luma(r: i32, g: i32, b: i32) -> u8 {
    let value = (Y_R * r + Y_G * g + Y_B * b + (16 << 16) + (1 << 15)) >> 16;

    // Already inside 16–235 by construction; the clamp is what makes that a
    // guarantee rather than an argument.
    value.clamp(16, 235) as u8
}

/// Cb and Cr for a two-by-two block, from the sums of its four pixels — the
/// average taken inside the fixed-point division rather than before it, so
/// it rounds once.
#[inline(always)]
fn chroma(r: i32, g: i32, b: i32) -> (u8, u8) {
    let u = (U_R * r + U_G * g + U_B * b + (128 << 18) + (1 << 17)) >> 18;
    let v = (V_R * r + V_G * g + V_B * b + (128 << 18) + (1 << 17)) >> 18;

    (u.clamp(16, 240) as u8, v.clamp(16, 240) as u8)
}

/// Two rows of `PIXEL`-byte pixels into two luma rows and one row of each
/// chroma plane. `R`, `G` and `B` are the channels' offsets in a pixel.
///
/// The one inner loop every packed path ends in: the captured rows directly
/// when nothing is scaled, or the box filter's averaged rows when something
/// is. Const generics so each layout compiles to its own straight-line loop.
#[inline(always)]
fn pair_to_i420<const PIXEL: usize, const R: usize, const G: usize, const B: usize>(
    upper: &[u8],
    lower: &[u8],
    luma_upper: &mut [u8],
    luma_lower: &mut [u8],
    cb: &mut [u8],
    cr: &mut [u8],
) {
    let blocks = upper
        .chunks_exact(2 * PIXEL)
        .zip(lower.chunks_exact(2 * PIXEL))
        .zip(luma_upper.chunks_exact_mut(2))
        .zip(luma_lower.chunks_exact_mut(2))
        .zip(cb.iter_mut())
        .zip(cr.iter_mut());

    for (((((top, bottom), y_top), y_bottom), u), v) in blocks {
        let (r0, g0, b0) = (i32::from(top[R]), i32::from(top[G]), i32::from(top[B]));
        let (r1, g1, b1) = (
            i32::from(top[PIXEL + R]),
            i32::from(top[PIXEL + G]),
            i32::from(top[PIXEL + B]),
        );
        let (r2, g2, b2) = (
            i32::from(bottom[R]),
            i32::from(bottom[G]),
            i32::from(bottom[B]),
        );
        let (r3, g3, b3) = (
            i32::from(bottom[PIXEL + R]),
            i32::from(bottom[PIXEL + G]),
            i32::from(bottom[PIXEL + B]),
        );

        y_top[0] = luma(r0, g0, b0);
        y_top[1] = luma(r1, g1, b1);
        y_bottom[0] = luma(r2, g2, b2);
        y_bottom[1] = luma(r3, g3, b3);

        let (cb_value, cr_value) = chroma(r0 + r1 + r2 + r3, g0 + g1 + g2 + g3, b0 + b1 + b2 + b3);
        *u = cb_value;
        *v = cr_value;
    }
}

/// One row of a strided buffer, bounds-checked.
fn row(data: &[u8], stride: usize, index: usize, length: usize) -> Result<&[u8], ConvertError> {
    let start = index.checked_mul(stride).ok_or(ConvertError::Malformed)?;
    let end = start.checked_add(length).ok_or(ConvertError::Malformed)?;

    data.get(start..end).ok_or(ConvertError::Malformed)
}

/// The picture's planes, split into the rows one pass of the packed paths
/// writes: two luma rows and one row of each chroma plane per step.
fn row_pairs(
    picture: &mut I420Picture,
) -> impl Iterator<Item = (&mut [u8], &mut [u8], &mut [u8], &mut [u8])> {
    let width = picture.width as usize;

    picture
        .y
        .chunks_exact_mut(2 * width)
        .zip(picture.u.chunks_exact_mut(width / 2))
        .zip(picture.v.chunks_exact_mut(width / 2))
        .map(move |((luma_rows, cb), cr)| {
            let (upper, lower) = luma_rows.split_at_mut(width);

            (upper, lower, cb, cr)
        })
}

// --- packed RGB -----------------------------------------------------------

/// BGRA or RGBA into I420, cropping or box-filtering as `region` says.
fn packed<const R: usize, const B: usize>(
    data: &[u8],
    stride: usize,
    region: (usize, usize),
    picture: &mut I420Picture,
) -> Result<(), ConvertError> {
    let size = (picture.width as usize, picture.height as usize);

    if region == size {
        packed_direct::<R, B>(data, stride, picture)
    } else {
        packed_scaled::<R, B>(data, stride, region, picture)
    }
}

/// The common case: the picture is the frame, less any odd pixel.
fn packed_direct<const R: usize, const B: usize>(
    data: &[u8],
    stride: usize,
    picture: &mut I420Picture,
) -> Result<(), ConvertError> {
    let row_bytes = picture.width as usize * 4;

    for (pair, (y_upper, y_lower, cb, cr)) in row_pairs(picture).enumerate() {
        let upper = row(data, stride, 2 * pair, row_bytes)?;
        let lower = row(data, stride, 2 * pair + 1, row_bytes)?;

        pair_to_i420::<4, R, 1, B>(upper, lower, y_upper, y_lower, cb, cr);
    }

    Ok(())
}

/// A larger frame box-filtered down to the picture.
///
/// Each source pixel is read once, in memory order. Because boxes tile a row
/// exactly when scaling down, each source row is one walk along it with a
/// running offset, adding each box's pixels into its output column's sums;
/// the first source row of an output row sets the sums and the rest add to
/// them. One multiply per channel then turns sums into averages, and two
/// averaged rows at a time go through the same inner loop as the direct path.
///
/// Measured on the 2.1 GHz shared CI-class machine this was written on, a
/// 3024×1964 Retina frame takes about 18 ms here against 4 ms for a 1080p
/// frame that needs no scaling — within twice the cost of merely summing the
/// frame's bytes, which is the floor for scalar code. The release profile is
/// size-optimised, so nothing here is auto-vectorised; `opt-level = 3` for
/// this crate was measured too and made no material difference.
fn packed_scaled<const R: usize, const B: usize>(
    data: &[u8],
    stride: usize,
    (source_width, source_height): (usize, usize),
    picture: &mut I420Picture,
) -> Result<(), ConvertError> {
    let width = picture.width as usize;
    let height = picture.height as usize;

    // Scaling up — only ever a frame of a pixel or two — has overlapping
    // boxes, which the cursor cannot walk.
    if source_width < width || source_height < height {
        return packed_resampled_any::<R, B>(data, stride, (source_width, source_height), picture);
    }

    let columns = spans(source_width, width);
    let rows = spans(source_height, height);
    let reciprocals = Reciprocals::for_spans(&columns, &rows);
    let box_widths: Vec<usize> = columns.iter().map(|(start, end)| end - start).collect();

    let mut sums = vec![0_u32; width * 3];
    let mut averaged = [vec![0_u8; width * 3], vec![0_u8; width * 3]];

    for (pair, (y_upper, y_lower, cb, cr)) in row_pairs(picture).enumerate() {
        for (half, output) in averaged.iter_mut().enumerate() {
            let (first, last) = rows
                .get(2 * pair + half)
                .copied()
                .ok_or(ConvertError::Malformed)?;

            // The first source row of a box sets its sums, later ones add to
            // them: no separate clearing pass and no separate vertical pass,
            // which in a size-optimised build — where nothing is vectorised —
            // were two full sweeps of the sums for every source row.
            for source_row in first..last {
                let line = row(data, stride, source_row, source_width * 4)?;
                let accumulate = source_row != first;
                let mut offset = 0_usize;

                for (sum, &box_width) in sums.chunks_exact_mut(3).zip(&box_widths) {
                    let end = offset + box_width * 4;
                    let covered = line.get(offset..end).ok_or(ConvertError::Malformed)?;
                    offset = end;

                    let (r, g, b) = box_row_sum::<R, B>(covered);

                    if accumulate {
                        sum[0] += r;
                        sum[1] += g;
                        sum[2] += b;
                    } else {
                        sum[0] = r;
                        sum[1] = g;
                        sum[2] = b;
                    }
                }
            }

            let box_height = last - first;

            for ((out, sum), &box_width) in output
                .chunks_exact_mut(3)
                .zip(sums.chunks_exact(3))
                .zip(&box_widths)
            {
                let factor = reciprocals.of(box_width * box_height);

                out[0] = average(sum[0], factor);
                out[1] = average(sum[1], factor);
                out[2] = average(sum[2], factor);
            }
        }

        pair_to_i420::<3, 0, 1, 2>(&averaged[0], &averaged[1], y_upper, y_lower, cb, cr);
    }

    Ok(())
}

/// The red, green and blue sums of the pixels in one row of one box.
///
/// Boxes are one or two pixels wide for any ratio under 2 : 1 — every Retina
/// and 1440p display — so those two are written out, and anything wider
/// takes the loop.
#[inline(always)]
fn box_row_sum<const R: usize, const B: usize>(covered: &[u8]) -> (u32, u32, u32) {
    match *covered {
        [p0, p1, p2, p3] => {
            let pixel = [p0, p1, p2, p3];
            (
                u32::from(pixel[R]),
                u32::from(pixel[1]),
                u32::from(pixel[B]),
            )
        }
        [p0, p1, p2, p3, q0, q1, q2, q3] => {
            let (left, right) = ([p0, p1, p2, p3], [q0, q1, q2, q3]);
            (
                u32::from(left[R]) + u32::from(right[R]),
                u32::from(left[1]) + u32::from(right[1]),
                u32::from(left[B]) + u32::from(right[B]),
            )
        }
        _ => covered.chunks_exact(4).fold((0, 0, 0), |(r, g, b), pixel| {
            (
                r + u32::from(pixel[R]),
                g + u32::from(pixel[1]),
                b + u32::from(pixel[B]),
            )
        }),
    }
}

/// The general resample: every output pixel the average of its box, boxes
/// allowed to overlap. Only reached when a frame is smaller than the picture
/// in some direction, which [`encoded_size`] never asks for; kept so that
/// [`convert_into`] does something sensible with any size it is given.
fn packed_resampled_any<const R: usize, const B: usize>(
    data: &[u8],
    stride: usize,
    (source_width, source_height): (usize, usize),
    picture: &mut I420Picture,
) -> Result<(), ConvertError> {
    let width = picture.width as usize;
    let height = picture.height as usize;

    let columns = spans(source_width, width);
    let rows = spans(source_height, height);
    let reciprocals = Reciprocals::for_spans(&columns, &rows);

    let mut sums = vec![0_u32; width * 3];
    let mut averaged = [vec![0_u8; width * 3], vec![0_u8; width * 3]];

    for (pair, (y_upper, y_lower, cb, cr)) in row_pairs(picture).enumerate() {
        for (half, output) in averaged.iter_mut().enumerate() {
            let (first, last) = rows
                .get(2 * pair + half)
                .copied()
                .ok_or(ConvertError::Malformed)?;

            sums.fill(0);

            for source_row in first..last {
                let line = row(data, stride, source_row, source_width * 4)?;

                for (sum, &(start, end)) in sums.chunks_exact_mut(3).zip(&columns) {
                    let covered = line
                        .get(start * 4..end * 4)
                        .ok_or(ConvertError::Malformed)?;

                    for pixel in covered.chunks_exact(4) {
                        sum[0] += u32::from(pixel[R]);
                        sum[1] += u32::from(pixel[1]);
                        sum[2] += u32::from(pixel[B]);
                    }
                }
            }

            let box_height = last - first;

            for ((out, sum), &(start, end)) in output
                .chunks_exact_mut(3)
                .zip(sums.chunks_exact(3))
                .zip(&columns)
            {
                let reciprocal = reciprocals.of((end - start) * box_height);

                out[0] = average(sum[0], reciprocal);
                out[1] = average(sum[1], reciprocal);
                out[2] = average(sum[2], reciprocal);
            }
        }

        pair_to_i420::<3, 0, 1, 2>(&averaged[0], &averaged[1], y_upper, y_lower, cb, cr);
    }

    Ok(())
}

// --- planar I420 ----------------------------------------------------------

/// I420 in, I420 out: each plane copied, cropped or box-filtered.
fn planar(frame: &Frame, cropped: bool, picture: &mut I420Picture) -> Result<(), ConvertError> {
    let planes = frame.i420_planes().ok_or(ConvertError::Malformed)?;

    let width = picture.width as usize;
    let height = picture.height as usize;

    let (luma_region, chroma_region) = if cropped {
        ((width, height), (width / 2, height / 2))
    } else {
        let source = (frame.width as usize, frame.height as usize);

        (source, (source.0.div_ceil(2), source.1.div_ceil(2)))
    };

    resample_plane(
        planes.y,
        planes.y_stride,
        luma_region,
        &mut picture.y,
        (width, height),
    )?;
    resample_plane(
        planes.u,
        planes.uv_stride,
        chroma_region,
        &mut picture.u,
        (width / 2, height / 2),
    )?;
    resample_plane(
        planes.v,
        planes.uv_stride,
        chroma_region,
        &mut picture.v,
        (width / 2, height / 2),
    )
}

/// One plane into another of `size`: rows copied when the region is the
/// same size, box-filtered when it is not.
fn resample_plane(
    source: &[u8],
    stride: usize,
    (source_width, source_height): (usize, usize),
    target: &mut [u8],
    (width, height): (usize, usize),
) -> Result<(), ConvertError> {
    if (source_width, source_height) == (width, height) {
        for (index, out) in target.chunks_exact_mut(width).enumerate() {
            out.copy_from_slice(row(source, stride, index, width)?);
        }

        return Ok(());
    }

    let columns = spans(source_width, width);
    let rows = spans(source_height, height);
    let reciprocals = Reciprocals::for_spans(&columns, &rows);
    let mut sums = vec![0_u32; width];

    for (out, &(first, last)) in target.chunks_exact_mut(width).zip(&rows) {
        sums.fill(0);

        for source_row in first..last {
            let line = row(source, stride, source_row, source_width)?;

            for (sum, &(start, end)) in sums.iter_mut().zip(&columns) {
                let covered = line.get(start..end).ok_or(ConvertError::Malformed)?;
                *sum += covered.iter().map(|&sample| u32::from(sample)).sum::<u32>();
            }
        }

        for ((sample, &sum), &(start, end)) in out.iter_mut().zip(&sums).zip(&columns) {
            *sample = average(sum, reciprocals.of((end - start) * (last - first)));
        }
    }

    Ok(())
}

// --- the box filter's arithmetic --------------------------------------------

/// The source range each of `target` output samples covers when `source`
/// samples are box-filtered into them: half-open, never empty, never past
/// the end.
///
/// For a whole-number ratio every box is the same size (4K to 1080p is two
/// by two); otherwise boxes alternate between the two sizes either side of
/// the ratio, which is what a box filter at a fractional ratio is.
fn spans(source: usize, target: usize) -> Vec<(usize, usize)> {
    let edge = |index: usize| (index as u64 * source as u64 / target.max(1) as u64) as usize;

    (0..target)
        .map(|index| {
            let start = edge(index).min(source.saturating_sub(1));
            let end = edge(index + 1).clamp(start + 1, source.max(start + 1));

            (start, end)
        })
        .collect()
}

/// `2^16 / area`, rounded, for every box area the filter will meet — so an
/// average is a multiply and a shift rather than a division per sample,
/// which at 1080p is two million divisions a frame saved.
struct Reciprocals(Vec<u32>);

impl Reciprocals {
    fn for_spans(columns: &[(usize, usize)], rows: &[(usize, usize)]) -> Self {
        let widest = columns
            .iter()
            .map(|(start, end)| end - start)
            .max()
            .unwrap_or(1);
        let tallest = rows
            .iter()
            .map(|(start, end)| end - start)
            .max()
            .unwrap_or(1);

        Self(
            (0..=widest * tallest)
                .map(|area| {
                    let area = area.max(1) as u32;
                    ((1 << 16) + area / 2) / area
                })
                .collect(),
        )
    }

    fn of(&self, area: usize) -> u32 {
        self.0.get(area).copied().unwrap_or(1)
    }
}

/// `sum / area`, rounded, given `area`'s reciprocal.
#[inline(always)]
fn average(sum: u32, reciprocal: u32) -> u8 {
    ((sum * reciprocal + (1 << 15)) >> 16).min(255) as u8
}

#[cfg(test)]
mod tests {
    use super::*;

    /// A BGRA frame of `width` × `height` from a function of the pixel
    /// position, with `padding` bytes of 0xEE after each row so a stride bug
    /// shows up as wrong colours rather than passing by luck.
    fn bgra_frame(
        width: u32,
        height: u32,
        padding: usize,
        colour: impl Fn(u32, u32) -> [u8; 3],
    ) -> Frame {
        let stride = width as usize * 4 + padding;
        let mut data = vec![0xEE; stride * height as usize];

        for y in 0..height {
            for x in 0..width {
                let [r, g, b] = colour(x, y);
                let offset = y as usize * stride + x as usize * 4;
                data[offset..offset + 4].copy_from_slice(&[b, g, r, 0xFF]);
            }
        }

        Frame::new(width, height, stride, PixelFormat::Bgra8, 0, data).expect("well formed")
    }

    /// The same picture as RGBA.
    fn rgba_from_bgra(frame: &Frame) -> Frame {
        let mut data = frame.data.clone();

        for pixel in data.chunks_exact_mut(4) {
            pixel.swap(0, 2);
        }

        Frame {
            format: PixelFormat::Rgba8,
            data,
            ..frame.clone()
        }
    }

    fn convert(frame: &Frame, size: (u32, u32)) -> I420Picture {
        let mut picture = I420Picture::new();
        convert_into(frame, size, &mut picture).expect("converts");
        picture
    }

    fn solid(colour: [u8; 3]) -> I420Picture {
        convert(&bgra_frame(2, 2, 0, |_, _| colour), (2, 2))
    }

    /// Reference values from the BT.601 equations in floating point, rounded:
    /// Y = 16 + 219·(0.299 R + 0.587 G + 0.114 B)/255, and the chroma
    /// equivalents with 224/255.
    #[test]
    fn known_colours_land_on_their_bt601_limited_range_values() {
        let cases: [([u8; 3], [u8; 3]); 7] = [
            ([0, 0, 0], [16, 128, 128]),
            ([255, 255, 255], [235, 128, 128]),
            ([128, 128, 128], [126, 128, 128]),
            ([255, 0, 0], [81, 90, 240]),
            ([0, 255, 0], [145, 54, 34]),
            ([0, 0, 255], [41, 240, 110]),
            ([255, 255, 0], [210, 16, 146]),
        ];

        for (rgb, [y, u, v]) in cases {
            let picture = solid(rgb);

            assert_eq!(picture.y(), &[y; 4], "Y of {rgb:?}");
            assert_eq!(picture.u(), &[u], "Cb of {rgb:?}");
            assert_eq!(picture.v(), &[v], "Cr of {rgb:?}");
        }
    }

    /// The channel order is the only difference between the two packed
    /// layouts, and getting it backwards swaps red and blue on every screen.
    #[test]
    fn rgba_and_bgra_of_the_same_picture_convert_identically() {
        let bgra = bgra_frame(6, 4, 0, |x, y| [(x * 40) as u8, (y * 60) as u8, 200]);
        let rgba = rgba_from_bgra(&bgra);

        let from_bgra = convert(&bgra, (6, 4));
        let from_rgba = convert(&rgba, (6, 4));

        assert_eq!(from_bgra.y(), from_rgba.y());
        assert_eq!(from_bgra.u(), from_rgba.u());
        assert_eq!(from_bgra.v(), from_rgba.v());

        // And red really is red: Cr high, not Cb.
        let red = solid([255, 0, 0]);
        assert!(red.v()[0] > 200 && red.u()[0] < 100);
    }

    /// Chroma covers a two-by-two block, and is the average of its four
    /// pixels — not the top-left one.
    #[test]
    fn chroma_is_the_average_of_each_block() {
        let quad = bgra_frame(2, 2, 0, |x, y| match (x, y) {
            (0, 0) => [255, 0, 0],
            (1, 0) => [0, 255, 0],
            (0, 1) => [0, 0, 255],
            _ => [255, 255, 255],
        });
        let picture = convert(&quad, (2, 2));

        // The four lumas are each pixel's own.
        assert_eq!(picture.y(), &[81, 145, 41, 235]);

        // The average colour is (127.5, 127.5, 127.5) — grey — so the
        // chroma is neutral, which it would not be if one pixel were used.
        assert_eq!(picture.u(), &[128]);
        assert_eq!(picture.v(), &[128]);
    }

    /// Windows pads rows. The padding is never read as pixels.
    #[test]
    fn row_padding_is_skipped() {
        let colour = |x: u32, y: u32| [(x * 30) as u8, (y * 30) as u8, ((x + y) * 10) as u8];

        let packed = convert(&bgra_frame(8, 6, 0, colour), (8, 6));
        let padded = convert(&bgra_frame(8, 6, 32, colour), (8, 6));
        let unaligned = convert(&bgra_frame(8, 6, 3, colour), (8, 6));

        assert_eq!(packed.y(), padded.y());
        assert_eq!(packed.u(), padded.u());
        assert_eq!(packed.v(), padded.v());
        assert_eq!(packed.y(), unaligned.y());
        assert_eq!(packed.v(), unaligned.v());
    }

    /// An odd column and row are cropped, not resampled: the even part of the
    /// picture comes through untouched, and the odd edge leaves no trace.
    #[test]
    fn an_odd_size_crops_the_last_column_and_row() {
        let profile = CaptureProfile::adaptive();
        let frame = bgra_frame(5, 3, 4, |x, y| {
            if x == 4 || y == 2 {
                [255, 0, 255] // the edge that must disappear
            } else {
                [0, 128, 0]
            }
        });

        let size = encoded_size(&profile, frame.width, frame.height);
        assert_eq!(size, (4, 2));

        let picture = convert(&frame, size);
        let green = solid([0, 128, 0]);

        assert_eq!((picture.width(), picture.height()), (4, 2));
        assert!(picture.y().iter().all(|&y| y == green.y()[0]));
        assert!(picture.u().iter().all(|&u| u == green.u()[0]));
        assert!(picture.v().iter().all(|&v| v == green.v()[0]));
    }

    /// A frame larger than the picture is averaged down: at an exact 2:1 each
    /// output pixel is the mean of its two-by-two source block.
    #[test]
    fn a_larger_frame_is_box_filtered_down() {
        // Each 2×2 source block is two black and two white pixels.
        let checker = bgra_frame(8, 8, 8, |x, y| {
            if (x + y) % 2 == 0 {
                [255, 255, 255]
            } else {
                [0, 0, 0]
            }
        });

        let picture = convert(&checker, (4, 4));
        let mid_grey = solid([128, 128, 128]).y()[0];

        assert_eq!((picture.width(), picture.height()), (4, 4));
        for &y in picture.y() {
            assert!(y.abs_diff(mid_grey) <= 1, "{y} is not the average");
        }
        assert!(picture.u().iter().all(|&u| u == 128));

        // A smooth gradient survives a fractional ratio as a gradient:
        // monotonic, no ringing, ends near the source's ends.
        let ramp = bgra_frame(30, 4, 0, |x, _| {
            let level = (x * 255 / 29) as u8;
            [level, level, level]
        });
        let scaled = convert(&ramp, (12, 2));
        let first_row = &scaled.y()[..12];

        assert!(
            first_row.windows(2).all(|pair| pair[0] <= pair[1]),
            "{first_row:?}"
        );
        assert!(first_row[0] < 30 && first_row[11] > 220, "{first_row:?}");
    }

    /// The sizes that matter: a 4K or Retina display comes down to the
    /// profile's ceiling, aspect kept, edges even; a display already inside
    /// it keeps its size less any odd pixel.
    #[test]
    fn large_displays_are_encoded_at_the_profiles_ceiling() {
        let adaptive = CaptureProfile::adaptive();

        assert_eq!(encoded_size(&adaptive, 1920, 1080), (1920, 1080));
        assert_eq!(encoded_size(&adaptive, 3840, 2160), (1920, 1080));
        assert_eq!(encoded_size(&adaptive, 5120, 2880), (1920, 1080));
        assert_eq!(encoded_size(&adaptive, 2880, 1800), (1920, 1200));
        assert_eq!(encoded_size(&adaptive, 1366, 767), (1366, 766));
        assert_eq!(encoded_size(&adaptive, 1080, 1920), (1080, 1920));

        let low = CaptureProfile::low_bandwidth();
        assert_eq!(encoded_size(&low, 3840, 2160), (1280, 720));

        // A profile that asks for more than OpenH264 can encode is held to
        // what it can: 3840 on the long edge, 2160 on the short one.
        let unbounded = CaptureProfile {
            max_dimension: 10_000,
            ..CaptureProfile::high_quality()
        };
        assert_eq!(encoded_size(&unbounded, 7680, 4320), (3840, 2160));
        let (width, height) = encoded_size(&unbounded, 4000, 4000);
        assert!(width <= 2160 && height <= 2160, "{width}x{height}");
    }

    /// The real-size path end to end: a 4K BGRA frame box-filtered to 1080p.
    #[test]
    fn a_4k_frame_converts_to_the_adaptive_ceiling() {
        let frame = bgra_frame(3840, 2160, 0, |x, _| {
            if x < 1920 {
                [255, 0, 0]
            } else {
                [0, 0, 255]
            }
        });
        let size = encoded_size(&CaptureProfile::adaptive(), frame.width, frame.height);
        let picture = convert(&frame, size);

        assert_eq!((picture.width(), picture.height()), (1920, 1080));
        assert_eq!(picture.y()[0], 81, "red on the left");
        assert_eq!(picture.y()[1919], 41, "blue on the right");
    }

    /// I420 in is cropped and copied plane by plane, or box-filtered.
    #[test]
    fn an_i420_frame_is_cropped_or_scaled_plane_by_plane() {
        // 5×3 with stride 6: luma ramp, Cb 100, Cr 200.
        let mut data: Vec<u8> = (0..18).map(|i| i as u8 * 10).collect();
        data.extend([100; 6]);
        data.extend([200; 6]);
        let frame = Frame::new(5, 3, 6, PixelFormat::I420, 0, data).unwrap();

        let cropped = convert(&frame, (4, 2));
        assert_eq!(cropped.y(), &[0, 10, 20, 30, 60, 70, 80, 90]);
        assert_eq!(cropped.u(), &[100, 100]);
        assert_eq!(cropped.v(), &[200, 200]);

        let scaled = convert(&frame, (2, 2));
        assert_eq!((scaled.width(), scaled.height()), (2, 2));
        assert_eq!(scaled.u(), &[100]);
        assert_eq!(scaled.v(), &[200]);
    }

    /// A malformed frame is refused, not read past the end of — the release
    /// build aborts on a panic.
    #[test]
    fn malformed_and_unsupported_frames_are_refused_without_panicking() {
        let mut picture = I420Picture::new();

        let mut short = bgra_frame(4, 4, 0, |_, _| [1, 2, 3]);
        short.data.truncate(40);
        assert_eq!(
            convert_into(&short, (4, 4), &mut picture),
            Err(ConvertError::Malformed)
        );

        let mut lying = bgra_frame(4, 4, 0, |_, _| [1, 2, 3]);
        lying.stride = 4; // one byte a pixel
        assert_eq!(
            convert_into(&lying, (4, 4), &mut picture),
            Err(ConvertError::Malformed)
        );

        let tiny = bgra_frame(1, 7, 0, |_, _| [1, 2, 3]);
        assert!(matches!(
            convert_into(&tiny, (2, 6), &mut picture),
            Err(ConvertError::TooSmall { .. })
        ));

        let nv12 = Frame::new(2, 2, 2, PixelFormat::Nv12, 0, vec![0; 6]).unwrap();
        assert_eq!(
            convert_into(&nv12, (2, 2), &mut picture),
            Err(ConvertError::UnsupportedFormat(PixelFormat::Nv12))
        );
    }

    /// Box spans tile the source exactly: no gaps, no overlaps, no empty
    /// boxes, whatever the ratio.
    #[test]
    fn box_spans_tile_the_source() {
        for (source, target) in [
            (3840, 1920),
            (2560, 1920),
            (1366, 960),
            (33, 32),
            (7, 7),
            (3, 8),
        ] {
            let boxes = spans(source, target);

            assert_eq!(boxes.len(), target);
            assert_eq!(boxes[0].0, 0);
            for (start, end) in &boxes {
                assert!(start < end, "{source}->{target}: empty box");
                assert!(*end <= source, "{source}->{target}: past the end");
            }
            if source >= target {
                assert_eq!(boxes[target - 1].1, source, "{source}->{target}");
                for pair in boxes.windows(2) {
                    assert_eq!(pair[0].1, pair[1].0, "{source}->{target}: gap or overlap");
                }
            }
        }
    }

    /// A picture prints its size, never its pixels.
    #[test]
    fn a_picture_prints_no_pixels() {
        let picture = solid([171, 171, 171]);
        let rendered = format!("{picture:?}");

        assert!(rendered.contains("width: 2"));
        assert!(!rendered.contains("171"));
    }
}
