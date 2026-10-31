//! The displays, as macOS lays them out.
//!
//! Where each monitor is decides where a click lands — on a Mac a control
//! companion's owner is sharing from the browser, or on one this Mac is
//! hosting a session from. Reading the list needs no permission at all:
//! `CGGetActiveDisplayList` and `CGDisplayBounds` describe the displays without
//! looking at what is on them, which is why a companion, whose picture comes
//! from the browser, never needs Screen Recording. Seeing a display is
//! `capture.rs`, and that does.
//!
//! # Points, not pixels
//!
//! Monitors are described in **points** — the global display coordinate space
//! `CGEventPost` takes, with the main display's top-left at the origin and the
//! y axis pointing down. A Retina display of 3024 × 1964 pixels is 1512 × 982
//! points with a scale of 2. The scale is reported beside the size and never
//! applied to it, exactly as on Windows. Matching the browser's capture (which
//! is in pixels) uses only the aspect ratio (`remote_protocol::SurfaceMatch`),
//! and a click on this Mac's own captured picture arrives as a fraction of it
//! (`capture.rs`), so in neither case do the two units meet.

use remote_device::{PlatformError, PlatformResult};
use remote_protocol::{Monitor, MonitorLayout, Orientation, MAX_SCALE, MIN_SCALE};

/// One display as Core Graphics reports it, before it is described.
#[derive(Debug, Clone, Copy, PartialEq)]
pub struct DisplayReading {
    /// `CGDirectDisplayID` — stable while the display stays connected.
    pub id: u32,
    /// `CGDisplayIsMain`.
    pub main: bool,
    /// `CGDisplayIsBuiltin`.
    pub builtin: bool,
    /// `CGDisplayBounds`, in points.
    pub x: f64,
    /// `CGDisplayBounds`, in points.
    pub y: f64,
    /// `CGDisplayBounds`, in points.
    pub width: f64,
    /// `CGDisplayBounds`, in points.
    pub height: f64,
    /// `CGDisplayModeGetPixelWidth` of the current mode — 0 when unknown.
    pub pixel_width: u64,
    /// `CGDisplayRotation`, in degrees.
    pub rotation: f64,
}

/// The scale a pixel width means for a width in points, or 1 when the
/// reading is not one. Same rule as Windows' DPI: an implausible scale is a
/// failed reading, and passing it on would take a working display out of the
/// layout (`Monitor::validate` refuses it).
#[must_use]
pub fn scale_from_pixels(pixel_width: u64, width_points: f64) -> f64 {
    if pixel_width == 0 || !width_points.is_finite() || width_points <= 0.0 {
        return 1.0;
    }

    let scale = pixel_width as f64 / width_points;

    if (MIN_SCALE..=MAX_SCALE).contains(&scale) {
        // Rounded to a hundredth: 2.0000001 is 2.
        (scale * 100.0).round() / 100.0
    } else {
        1.0
    }
}

/// Describe one display for the protocol. `ordinal` numbers the external
/// displays a person sees ("Display 2") — macOS's own localised names need
/// AppKit, and a number the Identify overlay shows on the screen itself is
/// what the picker needs anyway.
#[must_use]
pub fn describe_display(reading: &DisplayReading, ordinal: usize) -> Option<Monitor> {
    if !(reading.width.is_finite() && reading.height.is_finite())
        || reading.width < 1.0
        || reading.height < 1.0
    {
        return None;
    }

    let orientation = match reading.rotation.round() as i64 {
        90 => Orientation::Portrait,
        180 => Orientation::LandscapeFlipped,
        270 => Orientation::PortraitFlipped,
        _ => Orientation::Landscape,
    };

    let monitor = Monitor {
        id: reading.id,
        name: if reading.builtin {
            "Built-in display".to_owned()
        } else {
            format!("Display {ordinal}")
        },
        primary: reading.main,
        x: reading.x.round() as i32,
        y: reading.y.round() as i32,
        width: reading.width.round() as u32,
        height: reading.height.round() as u32,
        scale: scale_from_pixels(reading.pixel_width, reading.width),
        refresh_hz: None,
        orientation,
    };

    monitor.validate().ok().map(|()| monitor)
}

/// The layout from a set of readings: valid displays only, the main one
/// active (or the first, if none says it is main).
pub fn layout_from(readings: &[DisplayReading]) -> PlatformResult<MonitorLayout> {
    let monitors: Vec<Monitor> = readings
        .iter()
        .enumerate()
        .filter_map(|(index, reading)| describe_display(reading, index + 1))
        .collect();

    let active_monitor_id = monitors
        .iter()
        .find(|monitor| monitor.primary)
        .or_else(|| monitors.first())
        .map(|monitor| monitor.id)
        .ok_or(PlatformError::NotFound("A display"))?;

    Ok(MonitorLayout {
        monitors,
        active_monitor_id,
    })
}

/// Every display, now. Read on each call: a monitor plugged in mid-session
/// changes the answer, and a cached layout would put a click in the wrong
/// place.
pub fn current_layout() -> PlatformResult<MonitorLayout> {
    #[cfg(target_os = "macos")]
    {
        layout_from(&imp::readings()?)
    }

    #[cfg(not(target_os = "macos"))]
    {
        Err(PlatformError::Unsupported("Reading the display layout"))
    }
}

#[cfg(target_os = "macos")]
pub(crate) mod imp {
    //! `CGGetActiveDisplayList` and friends. Compiled only on macOS.

    use core_graphics::display::CGDisplay;
    use remote_device::{PlatformError, PlatformResult};

    use super::DisplayReading;

    /// Every active display, mirrors folded into the display they mirror.
    pub fn readings() -> PlatformResult<Vec<DisplayReading>> {
        let ids = CGDisplay::active_displays().map_err(|error| PlatformError::Os {
            operation: "listing the displays",
            detail: format!("Core Graphics error {error}"),
        })?;

        Ok(ids
            .into_iter()
            .map(CGDisplay::new)
            // A display mirroring another shows the same picture at the same
            // place; offering it separately would offer one screen twice.
            .filter(|display| display.mirrors_display() == 0)
            .map(|display| {
                let bounds = display.bounds();

                DisplayReading {
                    id: display.id,
                    main: display.is_main(),
                    builtin: display.is_builtin(),
                    x: bounds.origin.x,
                    y: bounds.origin.y,
                    width: bounds.size.width,
                    height: bounds.size.height,
                    pixel_width: display
                        .display_mode()
                        .map(|mode| mode.pixel_width())
                        .unwrap_or(0),
                    rotation: display.rotation(),
                }
            })
            .collect())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn reading(id: u32, x: f64, width: f64, height: f64, pixel_width: u64) -> DisplayReading {
        DisplayReading {
            id,
            main: id == 1,
            builtin: id == 1,
            x,
            y: 0.0,
            width,
            height,
            pixel_width,
            rotation: 0.0,
        }
    }

    /// A MacBook's built-in Retina display and a 4K external at "looks like
    /// 1920 × 1080": points, with the scale beside them.
    #[test]
    fn a_retina_layout_is_described_in_points_with_its_scale() {
        let layout = layout_from(&[
            reading(1, 0.0, 1512.0, 982.0, 3024),
            reading(2, 1512.0, 1920.0, 1080.0, 3840),
        ])
        .expect("a layout");

        assert_eq!(layout.active_monitor_id, 1);
        let builtin = layout.find(1).unwrap();
        assert_eq!((builtin.width, builtin.height), (1512, 982));
        assert_eq!(builtin.scale, 2.0);
        assert_eq!(builtin.name, "Built-in display");

        let external = layout.find(2).unwrap();
        assert_eq!(external.x, 1512);
        assert_eq!(external.name, "Display 2");
        assert!(layout.validate().is_ok());
    }

    /// An implausible or missing pixel width is a failed reading, not a
    /// reason to drop a working display.
    #[test]
    fn a_failed_scale_reading_is_one_rather_than_a_lost_display() {
        assert_eq!(scale_from_pixels(0, 1512.0), 1.0);
        assert_eq!(scale_from_pixels(3024, 0.0), 1.0);
        assert_eq!(scale_from_pixels(1_000_000, 1512.0), 1.0);
        assert_eq!(scale_from_pixels(2880, 1440.0), 2.0);
        assert_eq!(scale_from_pixels(1920, 1920.0), 1.0);
    }

    /// A display to the left of or above the main one has a negative origin.
    #[test]
    fn a_display_left_of_the_main_one_keeps_its_negative_origin() {
        let layout = layout_from(&[
            reading(1, 0.0, 1512.0, 982.0, 3024),
            DisplayReading {
                y: -1080.0,
                ..reading(2, -1920.0, 1920.0, 1080.0, 1920)
            },
        ])
        .unwrap();

        let left = layout.find(2).unwrap();
        assert_eq!((left.x, left.y), (-1920, -1080));
    }

    #[test]
    fn a_rotated_display_says_so() {
        let portrait = DisplayReading {
            rotation: 90.0,
            ..reading(2, 0.0, 1080.0, 1920.0, 1080)
        };

        assert_eq!(
            describe_display(&portrait, 2).unwrap().orientation,
            Orientation::Portrait
        );
    }

    #[test]
    fn a_display_with_no_area_is_left_out_and_an_empty_list_is_an_error() {
        assert!(describe_display(&reading(3, 0.0, 0.0, 900.0, 0), 3).is_none());
        assert!(layout_from(&[]).is_err());

        // No display says it is main: the first one is active.
        let layout = layout_from(&[DisplayReading {
            main: false,
            ..reading(5, 0.0, 1920.0, 1080.0, 1920)
        }])
        .unwrap();
        assert_eq!(layout.active_monitor_id, 5);
    }
}
