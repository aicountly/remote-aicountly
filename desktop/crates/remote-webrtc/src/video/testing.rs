//! Synthetic frames for the tests.

use remote_device::{Frame, PixelFormat};

/// Bytes of padding after each row, so every test exercises the stride.
const ROW_PADDING: usize = 16;

/// A moving pattern: eight colour bars and a white square that travels a
/// few pixels a frame. Flat enough that the rate control has no reason to
/// skip a frame at any size a test uses, so the tests that count frames can
/// count them exactly.
pub(crate) fn pattern(width: u32, height: u32, index: u64, timestamp_us: u64) -> Frame {
    draw(width, height, index, timestamp_us, false)
}

/// The same with a band of fine texture along the bottom quarter — closer to
/// a desktop full of text, and what the benchmark measures.
pub(crate) fn busy_pattern(width: u32, height: u32, index: u64, timestamp_us: u64) -> Frame {
    draw(width, height, index, timestamp_us, true)
}

fn draw(width: u32, height: u32, index: u64, timestamp_us: u64, textured: bool) -> Frame {
    let stride = width as usize * 4 + ROW_PADDING;
    let mut data = vec![0xEE_u8; stride * height as usize];
    let square = (width.min(height) / 6).max(2);
    let travel = ((index * 7) % u64::from(width.saturating_sub(square).max(1))) as u32;
    let rows = height / 3..height / 3 + square;
    let columns = travel..travel + square;

    for y in 0..height {
        for x in 0..width {
            let bar = (x * 8 / width.max(1)) as u8;
            let mut bgra = [
                if bar & 1 != 0 { 230 } else { 20 },
                if bar & 2 != 0 { 230 } else { 20 },
                if bar & 4 != 0 { 230 } else { 20 },
                255,
            ];

            if textured && y > height * 3 / 4 {
                let grain = ((x * 31 + y * 17) ^ (x * y)) as u8;
                bgra = [grain, grain.wrapping_mul(3), grain.wrapping_add(90), 255];
            }

            if columns.contains(&x) && rows.contains(&y) {
                bgra = [255, 255, 255, 255];
            }

            let offset = y as usize * stride + x as usize * 4;
            data[offset..offset + 4].copy_from_slice(&bgra);
        }
    }

    Frame::new(
        width,
        height,
        stride,
        PixelFormat::Bgra8,
        timestamp_us,
        data,
    )
    .expect("the pattern is well formed")
}
