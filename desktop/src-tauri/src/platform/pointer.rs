//! Drawing the pointer into a captured frame.
//!
//! `Windows.Graphics.Capture` draws the pointer into the picture itself. The
//! Mac's `CGDisplayCreateImage` never does: its image is the display without
//! the cursor, whatever the capture profile asks for. So a person helping
//! somebody on a Mac saw a screen with **no pointer on it** — they could not
//! see where the person at the Mac was pointing, and once they had control
//! they could not see the Mac's own pointer follow theirs. The picture and
//! the pointer are two halves of "look, here"; a remote screen with one of
//! them missing is not usable for help.
//!
//! This module is the portable half of the fix: given where the pointer is,
//! in the same global points the display layout is in, it works out where
//! that is in the frame's pixels ([`pointer_in_frame`]) and draws an arrow
//! there ([`draw_pointer`]). The native half is one call that asks macOS
//! where the pointer is (`macos::capture`).
//!
//! # An arrow, not the cursor's own shape
//!
//! What is drawn is always the same arrow, with its tip on the pointer's hot
//! spot. It does not become an I-beam over text or a hand over a link:
//! reading the system's current cursor image is a further piece of native
//! work (and ScreenCaptureKit, which replaces this capture, draws the real
//! one). An arrow in the right place is what the person helping needs.

use remote_device::{Frame, PixelFormat};
use remote_protocol::Monitor;

/// The arrow, one character a point: `X` is the dark outline, `.` the light
/// fill, a space is the picture showing through. Its tip is the top-left
/// corner, which is where a pointer's hot spot is.
const ARROW: [&[u8]; 19] = [
    b"X           ",
    b"XX          ",
    b"X.X         ",
    b"X..X        ",
    b"X...X       ",
    b"X....X      ",
    b"X.....X     ",
    b"X......X    ",
    b"X.......X   ",
    b"X........X  ",
    b"X.........X ",
    b"X......XXXXX",
    b"X...X..X    ",
    b"X..XX..X    ",
    b"X.X  X..X   ",
    b"XX   X..X   ",
    b"X     X..X  ",
    b"      X..X  ",
    b"       XX   ",
];

/// The arrow's size in points.
const ARROW_WIDTH: usize = 12;
const ARROW_HEIGHT: usize = 19;

/// Where the pointer is in a frame of `monitor`, and how many of the frame's
/// pixels make one point there.
///
/// `pointer` and the monitor's origin and size are in the platform's global
/// layout units — points on a Mac — while the frame is the display's backing
/// store in pixels: a Retina display laid out as 1512 × 982 points is
/// captured as 3024 × 1964. The ratio between the two is the scale, taken
/// from the frame itself rather than from what the display reports, so a
/// frame at any size is drawn on correctly.
///
/// `None` when the pointer is on another display, or the monitor or frame
/// has no area.
#[must_use]
pub fn pointer_in_frame(
    monitor: &Monitor,
    frame_width: u32,
    frame_height: u32,
    pointer: (f64, f64),
) -> Option<(f64, f64, f64)> {
    if monitor.width == 0 || monitor.height == 0 || frame_width == 0 || frame_height == 0 {
        return None;
    }

    if !pointer.0.is_finite() || !pointer.1.is_finite() {
        return None;
    }

    let local_x = pointer.0 - f64::from(monitor.x);
    let local_y = pointer.1 - f64::from(monitor.y);

    if local_x < 0.0
        || local_y < 0.0
        || local_x >= f64::from(monitor.width)
        || local_y >= f64::from(monitor.height)
    {
        return None;
    }

    let scale_x = f64::from(frame_width) / f64::from(monitor.width);
    let scale_y = f64::from(frame_height) / f64::from(monitor.height);

    Some((local_x * scale_x, local_y * scale_y, scale_x.min(scale_y)))
}

/// Draw the arrow into `frame` with its tip at pixel (`tip_x`, `tip_y`),
/// `scale` pixels to a point.
///
/// Clipped at the frame's edges, so a pointer at the right or bottom edge
/// shows as much of the arrow as fits rather than wrapping onto the next row.
/// A frame that is not four bytes a pixel is left alone: the Mac capture
/// hands over BGRA, and guessing at another layout would draw noise.
pub fn draw_pointer(frame: &mut Frame, tip_x: f64, tip_y: f64, scale: f64) {
    let (dark, light): ([u8; 4], [u8; 4]) = match frame.format {
        // Opaque black and white are the same bytes in either order.
        PixelFormat::Bgra8 | PixelFormat::Rgba8 => ([0, 0, 0, 255], [255, 255, 255, 255]),
        _ => return,
    };

    if !tip_x.is_finite() || !tip_y.is_finite() || !scale.is_finite() || scale <= 0.0 {
        return;
    }

    // Never smaller than the 1× arrow, never absurdly large.
    let scale = scale.clamp(1.0, 8.0);

    let width = frame.width as i64;
    let height = frame.height as i64;
    let stride = frame.stride;

    let origin_x = tip_x.floor() as i64;
    let origin_y = tip_y.floor() as i64;

    let drawn_width = (ARROW_WIDTH as f64 * scale).ceil() as i64;
    let drawn_height = (ARROW_HEIGHT as f64 * scale).ceil() as i64;

    for row in 0..drawn_height {
        let y = origin_y + row;
        if y < 0 || y >= height {
            continue;
        }

        let source_row = ((row as f64 / scale) as usize).min(ARROW_HEIGHT - 1);

        for column in 0..drawn_width {
            let x = origin_x + column;
            if x < 0 || x >= width {
                continue;
            }

            let source_column = ((column as f64 / scale) as usize).min(ARROW_WIDTH - 1);

            let colour = match ARROW[source_row][source_column] {
                b'X' => dark,
                b'.' => light,
                _ => continue,
            };

            let offset = y as usize * stride + x as usize * 4;
            if let Some(pixel) = frame.data.get_mut(offset..offset + 4) {
                pixel.copy_from_slice(&colour);
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use remote_protocol::Orientation;

    fn monitor(x: i32, y: i32, width: u32, height: u32) -> Monitor {
        Monitor {
            id: 1,
            name: "Display".into(),
            primary: true,
            x,
            y,
            width,
            height,
            scale: 1.0,
            refresh_hz: None,
            orientation: Orientation::Landscape,
        }
    }

    /// A grey BGRA frame, with `padding` unused bytes at the end of each row
    /// as the platform pads them.
    fn frame(width: u32, height: u32, padding: usize) -> Frame {
        let stride = width as usize * 4 + padding;

        Frame {
            width,
            height,
            stride,
            format: PixelFormat::Bgra8,
            timestamp_us: 0,
            data: vec![0x80; stride * height as usize],
        }
    }

    fn pixel(frame: &Frame, x: u32, y: u32) -> [u8; 4] {
        let offset = y as usize * frame.stride + x as usize * 4;

        [
            frame.data[offset],
            frame.data[offset + 1],
            frame.data[offset + 2],
            frame.data[offset + 3],
        ]
    }

    const GREY: [u8; 4] = [0x80; 4];
    const BLACK: [u8; 4] = [0, 0, 0, 255];
    const WHITE: [u8; 4] = [255, 255, 255, 255];

    /// The arrow's rows are all as wide as the arrow, so the lookup in
    /// `draw_pointer` cannot read past one.
    #[test]
    fn the_arrow_is_a_whole_rectangle() {
        assert_eq!(ARROW.len(), ARROW_HEIGHT);
        for row in ARROW {
            assert_eq!(row.len(), ARROW_WIDTH);
        }
        assert_eq!(ARROW[0][0], b'X', "the tip is the top-left corner");
    }

    /// A Retina display is laid out in points and captured in pixels: the
    /// pointer at the middle of the layout is at the middle of the picture,
    /// and a point is two pixels there.
    #[test]
    fn a_pointer_in_points_lands_at_the_same_place_in_a_retina_picture() {
        let retina = monitor(0, 0, 1512, 982);

        let (x, y, scale) =
            pointer_in_frame(&retina, 3024, 1964, (756.0, 491.0)).expect("on this display");

        assert_eq!((x, y), (1512.0, 982.0));
        assert_eq!(scale, 2.0);
    }

    /// A second display to the left has a negative origin; the pointer on it
    /// is measured from that display's own corner, and a pointer on the other
    /// display is not on this one at all.
    #[test]
    fn a_pointer_is_measured_from_its_own_displays_corner() {
        let left = monitor(-1920, 0, 1920, 1080);

        let (x, y, scale) =
            pointer_in_frame(&left, 1920, 1080, (-1900.0, 30.0)).expect("on the left display");
        assert_eq!((x, y, scale), (20.0, 30.0, 1.0));

        assert_eq!(pointer_in_frame(&left, 1920, 1080, (10.0, 10.0)), None);
        assert_eq!(pointer_in_frame(&left, 1920, 1080, (-1921.0, 10.0)), None);
        assert_eq!(pointer_in_frame(&left, 1920, 1080, (-10.0, 1080.0)), None);
        assert_eq!(pointer_in_frame(&left, 1920, 1080, (f64::NAN, 10.0)), None);
        assert_eq!(
            pointer_in_frame(&monitor(0, 0, 0, 0), 10, 10, (1.0, 1.0)),
            None
        );
    }

    /// The tip is drawn dark on the pointer's own pixel, the body light
    /// inside a dark outline, and the picture beside the arrow is untouched —
    /// with row padding in the frame, as the capture really hands it over.
    #[test]
    fn the_arrow_is_drawn_with_its_tip_on_the_pointer() {
        let mut picture = frame(64, 64, 12);

        draw_pointer(&mut picture, 10.0, 20.0, 1.0);

        assert_eq!(pixel(&picture, 10, 20), BLACK, "the tip");
        assert_eq!(pixel(&picture, 11, 23), WHITE, "inside the arrow");
        assert_eq!(pixel(&picture, 10, 24), BLACK, "its left edge");
        assert_eq!(pixel(&picture, 9, 20), GREY, "left of the tip");
        assert_eq!(pixel(&picture, 11, 20), GREY, "right of the tip");
        assert_eq!(pixel(&picture, 10, 19), GREY, "above the tip");
        assert_eq!(pixel(&picture, 30, 40), GREY, "well away from it");

        // Nothing was written into the padding.
        let stride = picture.stride;
        for row in 0..64 {
            let padding = &picture.data[row * stride + 64 * 4..(row + 1) * stride];
            assert!(padding.iter().all(|byte| *byte == 0x80), "row {row}");
        }
    }

    /// On a Retina picture the arrow is twice the size, so it looks the same
    /// size to the person watching as it does to the person at the Mac.
    #[test]
    fn the_arrow_is_scaled_with_the_picture() {
        let mut picture = frame(96, 96, 0);

        draw_pointer(&mut picture, 10.0, 10.0, 2.0);

        // The 1× tip pixel covers a 2 × 2 block.
        for (x, y) in [(10, 10), (11, 10), (10, 11), (11, 11)] {
            assert_eq!(pixel(&picture, x, y), BLACK, "({x}, {y})");
        }
        // The fill starts two rows down in the 1× arrow: four here.
        assert_eq!(pixel(&picture, 12, 14), WHITE);
        // Twice as tall: the arrow's last row is 18 at 1×, so 36–37 here.
        assert_eq!(pixel(&picture, 10 + 15, 10 + 36), BLACK);
        assert_eq!(pixel(&picture, 10, 10 + 38), GREY, "below the arrow");
    }

    /// A pointer at the bottom-right corner shows as much of the arrow as
    /// fits. Nothing wraps onto the next row and nothing is written outside
    /// the frame.
    #[test]
    fn the_arrow_is_clipped_at_the_edges() {
        let mut picture = frame(32, 32, 8);
        let before = picture.data.len();

        draw_pointer(&mut picture, 30.0, 30.0, 1.0);

        assert_eq!(picture.data.len(), before);
        assert_eq!(pixel(&picture, 30, 30), BLACK, "the tip is still drawn");
        // The row below continues the arrow's left edge, not a wrapped piece
        // of the row above.
        assert_eq!(pixel(&picture, 30, 31), BLACK);
        assert_eq!(
            pixel(&picture, 0, 31),
            GREY,
            "nothing wrapped to the left edge"
        );

        // Entirely off the picture: nothing changes.
        let mut untouched = frame(16, 16, 0);
        draw_pointer(&mut untouched, -40.0, -40.0, 1.0);
        draw_pointer(&mut untouched, 500.0, 500.0, 1.0);
        draw_pointer(&mut untouched, f64::NAN, 2.0, 1.0);
        draw_pointer(&mut untouched, 2.0, 2.0, 0.0);
        assert!(untouched.data.iter().all(|byte| *byte == 0x80));
    }

    /// A layout this does not draw into is left exactly as it was.
    #[test]
    fn a_frame_that_is_not_four_bytes_a_pixel_is_left_alone() {
        let mut planar = Frame {
            width: 16,
            height: 16,
            stride: 16,
            format: PixelFormat::I420,
            timestamp_us: 0,
            data: vec![0x80; 16 * 16 * 3 / 2],
        };

        draw_pointer(&mut planar, 4.0, 4.0, 1.0);

        assert!(planar.data.iter().all(|byte| *byte == 0x80));
    }
}
