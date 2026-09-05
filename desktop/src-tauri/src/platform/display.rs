//! Describing a display, and the Secure Desktop, without touching an API.
//!
//! This is part of the arithmetic that decides **where a click lands**, and it
//! sits beside the platform modules rather than inside one because it is plain
//! numbers: a monitor description has no native call in it, so it should not
//! need a particular operating system to be compiled or run.
//!
//! Windows fills these values in from `EnumDisplayMonitors`, `GetMonitorInfoW`
//! and `GetDpiForMonitor`. What happens to them afterwards is here, and
//! `platform::windows::capture` re-exports it so the enumeration loop reads in
//! one place.

use remote_protocol::{Monitor, Orientation, MAX_SCALE, MIN_SCALE};

/// The DPI Windows means by "100%".
const DEFAULT_DPI: f64 = 96.0;

/// The longest display name that is carried.
///
/// A display name is a string the operating system read out of the monitor's
/// own EDID — which is to say, out of hardware somebody else manufactured.
const MAX_NAME_CHARS: usize = 120;

/// Whether the Secure Desktop is in front of the user's own.
///
/// The agent polls this while capturing. When it becomes `Active`, the viewer
/// is told "the person at this computer is answering a Windows security
/// prompt" instead of being left looking at a frozen frame and wondering
/// whether the connection died.
///
/// It is deliberately only a *notification*. Nothing anywhere attempts to
/// capture the Secure Desktop or to inject into it — Windows prevents both by
/// design, and defeating that protection is not something a remote-assistance
/// product should be trying to do.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SecureDesktopState {
    /// The user's own desktop is in front. Capture is showing what they see.
    UserDesktop,
    /// A UAC prompt, Ctrl+Alt+Del or the sign-in screen is in front.
    ///
    /// Frames are still arriving, but they show what is *underneath* the
    /// prompt rather than the prompt, and input is not delivered.
    Active,
}

impl SecureDesktopState {
    /// What the viewer is told.
    #[must_use]
    pub fn describe(self) -> Option<&'static str> {
        match self {
            Self::UserDesktop => None,
            Self::Active => Some(
                "The person at this computer is answering a Windows security prompt. \
                 Windows hides it from screen sharing, and remote control does not reach it.",
            ),
        }
    }
}

/// The scale a DPI reading means, or 100% when the reading is not one.
///
/// `GetDpiForMonitor` can fail, and it reports a failure by leaving the value
/// alone rather than by returning something obviously wrong. A monitor whose
/// DPI came back as 0 is a monitor at the ordinary 96 as far as everything
/// downstream is concerned.
///
/// Dividing an implausible reading by 96 and passing the result on is worse
/// than useless: `Monitor::validate()` refuses a scale outside
/// [`MIN_SCALE`]..=[`MAX_SCALE`], so a single bad reading would take a real,
/// working display out of the layout — and a display that is not in the layout
/// is a display nobody can be helped on.
#[must_use]
pub fn scale_from_dpi(dpi: u32) -> f64 {
    let scale = f64::from(dpi) / DEFAULT_DPI;

    if (MIN_SCALE..=MAX_SCALE).contains(&scale) {
        scale
    } else {
        1.0
    }
}

/// Describe one display for the protocol.
///
/// Nine arguments, and they stay nine: every one is a separate value the
/// platform hands back from a different call, and bundling them into a struct
/// would only move the same nine assignments to the caller while making the
/// enumeration loop harder to read.
#[allow(clippy::too_many_arguments)]
#[must_use]
pub fn describe_monitor(
    id: u32,
    name: &str,
    primary: bool,
    x: i32,
    y: i32,
    width: u32,
    height: u32,
    dpi: u32,
    orientation: Orientation,
) -> Monitor {
    Monitor {
        id,
        name: name
            .chars()
            .filter(|c| !c.is_control())
            .take(MAX_NAME_CHARS)
            .collect::<String>(),
        primary,
        x,
        y,
        // Physical pixels. The scale is reported *beside* them rather than
        // applied to them — multiplying by it as well is the classic way a
        // click on a 150% display lands half a screen away.
        width,
        height,
        scale: scale_from_dpi(dpi),
        refresh_hz: None,
        orientation,
    }
}

/// Windows' `DMDO_*` display orientation, as the protocol spells it.
#[must_use]
pub fn orientation_from_windows(value: u32) -> Orientation {
    match value {
        1 => Orientation::Portrait,
        2 => Orientation::LandscapeFlipped,
        3 => Orientation::PortraitFlipped,
        _ => Orientation::Landscape,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use remote_protocol::PointerPosition;

    /// The arithmetic that decides where a click lands. On a 150% display the
    /// physical pixels are what travel and the scale is reported beside them,
    /// so the middle of a 2880-wide screen is pixel 1440 — not 960, which is
    /// what applying the scale as well would give.
    #[test]
    fn a_monitor_description_keeps_physical_pixels_and_reports_scale_beside_them() {
        let monitor = describe_monitor(
            1,
            r"\\.\DISPLAY1",
            true,
            0,
            0,
            2880,
            1620,
            144,
            Orientation::Landscape,
        );

        assert_eq!(monitor.width, 2880);
        assert_eq!(monitor.height, 1620);
        assert!((monitor.scale - 1.5).abs() < f64::EPSILON);
        assert!(monitor.validate().is_ok());

        assert_eq!(
            monitor.denormalise(PointerPosition { x: 0.5, y: 0.5 }),
            Some((1440, 810))
        );
    }

    /// The two ends of the range are the pixels that actually exist: 1.0 is the
    /// last pixel, not one past it — `x + width` is the first pixel of whatever
    /// is to the right, which on a multi-monitor desktop is another screen.
    #[test]
    fn the_edges_map_to_the_first_and_last_pixel() {
        let monitor = describe_monitor(1, "X", true, 0, 0, 2880, 1620, 144, Orientation::Landscape);

        assert_eq!(
            monitor.denormalise(PointerPosition { x: 0.0, y: 0.0 }),
            Some((0, 0))
        );
        assert_eq!(
            monitor.denormalise(PointerPosition { x: 1.0, y: 1.0 }),
            Some((2879, 1619))
        );
    }

    /// A display name comes from hardware somebody else manufactured.
    #[test]
    fn a_display_name_is_stripped_and_bounded() {
        let monitor = describe_monitor(
            1,
            &format!("Dell\u{0}\u{7}U2720Q{}", "x".repeat(200)),
            false,
            0,
            0,
            1920,
            1080,
            96,
            Orientation::Landscape,
        );

        assert!(!monitor.name.contains('\0'));
        assert!(!monitor.name.contains('\u{7}'));
        assert!(monitor.name.chars().count() <= MAX_NAME_CHARS);
        assert!(monitor.validate().is_ok());
    }

    #[test]
    fn a_secondary_monitor_at_a_negative_origin_is_described_correctly() {
        let left = describe_monitor(
            2,
            "Left",
            false,
            -1920,
            0,
            1920,
            1080,
            96,
            Orientation::Landscape,
        );

        assert_eq!(left.x, -1920);
        assert_eq!(
            left.denormalise(PointerPosition { x: 0.0, y: 0.0 }),
            Some((-1920, 0))
        );
    }

    #[test]
    fn windows_orientation_values_map_to_the_protocols() {
        assert_eq!(orientation_from_windows(0), Orientation::Landscape);
        assert_eq!(orientation_from_windows(1), Orientation::Portrait);
        assert_eq!(orientation_from_windows(2), Orientation::LandscapeFlipped);
        assert_eq!(orientation_from_windows(3), Orientation::PortraitFlipped);
        // Anything else is the ordinary way up rather than a failure.
        assert_eq!(orientation_from_windows(99), Orientation::Landscape);
    }

    /// A frozen frame with no explanation reads as a dropped connection. The
    /// agent says what is happening instead.
    #[test]
    fn the_secure_desktop_is_explained_rather_than_left_as_a_frozen_frame() {
        assert!(SecureDesktopState::UserDesktop.describe().is_none());

        let explanation = SecureDesktopState::Active
            .describe()
            .expect("says something");

        assert!(explanation.contains("Windows security prompt"));
        assert!(explanation.contains("remote control does not reach it"));
    }

    /// The real DPI values Windows reports, and the scales they mean.
    #[test]
    fn the_scales_windows_actually_reports_survive_the_conversion() {
        for (dpi, expected) in [
            (96, 1.0),
            (120, 1.25),
            (144, 1.5),
            (192, 2.0),
            (240, 2.5),
            (288, 3.0),
            (384, 4.0),
        ] {
            assert!(
                (scale_from_dpi(dpi) - expected).abs() < f64::EPSILON,
                "{dpi} DPI should be {expected}, was {}",
                scale_from_dpi(dpi)
            );
        }
    }

    /// **A failed DPI reading must not take a working display out of the
    /// layout.** `GetDpiForMonitor` can fail, and 0/96 is a scale the protocol
    /// refuses — so the monitor would validate as broken and disappear.
    #[test]
    fn an_implausible_dpi_reading_falls_back_to_a_hundred_percent() {
        for dpi in [0, 1, 10, 23, 769, 100_000, u32::MAX] {
            let monitor =
                describe_monitor(1, "X", true, 0, 0, 1920, 1080, dpi, Orientation::Landscape);

            assert!(
                (monitor.scale - 1.0).abs() < f64::EPSILON,
                "{dpi} DPI should fall back to 100%, was {}",
                monitor.scale
            );
            assert!(
                monitor.validate().is_ok(),
                "{dpi} DPI produced a monitor the protocol refuses"
            );
        }
    }

    /// The bounds come from the protocol, so the two cannot drift apart about
    /// what is plausible.
    #[test]
    fn the_edges_of_the_permitted_range_are_kept_rather_than_replaced() {
        assert!((scale_from_dpi(24) - MIN_SCALE).abs() < f64::EPSILON);
        assert!((scale_from_dpi(768) - MAX_SCALE).abs() < f64::EPSILON);
    }
}
