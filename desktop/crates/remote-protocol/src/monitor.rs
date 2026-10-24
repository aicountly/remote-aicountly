//! Displays: how many, how big, where, and at what scale.
//!
//! This is what turns a normalised pointer position into a pixel on the right
//! screen. It has to survive everything Windows does to a desktop while a
//! session is running — a monitor unplugged, a resolution changed, a laptop
//! docked, a tablet rotated, a per-monitor DPI that differs between two
//! screens — because every one of those changes where a click lands.

use serde::{Deserialize, Serialize};

use crate::{input::PointerPosition, ProtocolError};

/// The smallest display scale the protocol will carry.
///
/// A platform whose DPI reading falls outside [`MIN_SCALE`]..=[`MAX_SCALE`] has
/// not reported a scale — it has reported a failure — and the caller should
/// substitute 100% rather than pass the number on. Exposed so the platform
/// layer and [`Monitor::validate`] cannot drift apart about what is plausible.
pub const MIN_SCALE: f64 = 0.25;

/// The largest display scale the protocol will carry. See [`MIN_SCALE`].
pub const MAX_SCALE: f64 = 8.0;

/// How a display is turned.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Orientation {
    /// The usual way up.
    #[default]
    Landscape,
    /// Rotated 90°.
    Portrait,
    /// Rotated 180°.
    LandscapeFlipped,
    /// Rotated 270°.
    PortraitFlipped,
}

/// One display.
///
/// `x` and `y` are its origin in the **virtual desktop** — the single
/// coordinate space Windows lays every monitor out in, where a screen to the
/// left of the primary has a negative x. Getting this wrong is how a click
/// meant for the second monitor lands on the first.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct Monitor {
    /// Stable within one agent run. Referenced by `SelectMonitor`.
    pub id: u32,
    /// What the person at the machine calls it.
    pub name: String,
    /// Whether Windows considers this the primary display.
    pub primary: bool,
    /// Origin x in the virtual desktop, in physical pixels.
    pub x: i32,
    /// Origin y in the virtual desktop, in physical pixels.
    pub y: i32,
    /// Width in physical pixels.
    pub width: u32,
    /// Height in physical pixels.
    pub height: u32,
    /// DPI scale — 1.0 at 96 DPI, 1.5 at 150%, 2.0 at 192.
    ///
    /// Reported so the controller can size its own rendering sensibly. It is
    /// deliberately *not* applied to coordinates: `width` and `height` are
    /// already physical pixels, and multiplying by the scale as well is the
    /// classic way a click on a 150% display lands half a screen away.
    pub scale: f64,
    /// Refresh rate in Hz, where the platform reports one.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub refresh_hz: Option<u32>,
    /// How the display is turned.
    #[serde(default)]
    pub orientation: Orientation,
}

impl Monitor {
    /// Turn a normalised point on *this* monitor into a virtual-desktop pixel.
    ///
    /// Returns `None` for a position that is not valid, so a caller cannot get
    /// a coordinate out of a `NaN` by accident.
    #[must_use]
    pub fn denormalise(&self, position: PointerPosition) -> Option<(i32, i32)> {
        position.validate().ok()?;

        // `width - 1` because a position of exactly 1.0 means the last pixel,
        // not one past it: `x + width` is the first pixel of whatever is to
        // the right, which on a multi-monitor desktop is another screen.
        let x = self.x + ((self.width.saturating_sub(1)) as f64 * position.x).round() as i32;
        let y = self.y + ((self.height.saturating_sub(1)) as f64 * position.y).round() as i32;

        Some((x, y))
    }

    /// Turn a virtual-desktop pixel into a normalised point on this monitor.
    ///
    /// The inverse of [`Self::denormalise`], used when the agent reports where
    /// its own pointer is. Clamped, because a pointer genuinely can be on
    /// another monitor.
    #[must_use]
    pub fn normalise(&self, x: i32, y: i32) -> PointerPosition {
        let width = self.width.saturating_sub(1).max(1) as f64;
        let height = self.height.saturating_sub(1).max(1) as f64;

        PointerPosition {
            x: (f64::from(x - self.x) / width),
            y: (f64::from(y - self.y) / height),
        }
        .clamped()
    }

    /// A monitor with no pixels is not a monitor.
    pub fn validate(&self) -> Result<(), ProtocolError> {
        if self.width == 0 || self.height == 0 {
            return Err(ProtocolError::OutOfBounds);
        }

        // 16K is comfortably beyond any real display and well short of
        // anything that would overflow the arithmetic above.
        if self.width > 16_384 || self.height > 16_384 {
            return Err(ProtocolError::OutOfBounds);
        }

        if !self.scale.is_finite() || !(MIN_SCALE..=MAX_SCALE).contains(&self.scale) {
            return Err(ProtocolError::OutOfBounds);
        }

        if self.name.len() > 120 {
            return Err(ProtocolError::OutOfBounds);
        }

        Ok(())
    }
}

/// How close two aspect ratios have to be for a shared surface to be taken for
/// a monitor: 1%, relative to the shared surface's own ratio.
///
/// Wide enough for the rounding a browser applies when it reports a capture's
/// size, narrow enough that 16:10 (1.60) and 16:9 (1.78), or 3:2 (1.50) and
/// 16:10, are never confused.
pub const ASPECT_TOLERANCE: f64 = 0.01;

/// Which monitor a browser's shared surface is, as far as the agent can tell.
///
/// A control companion injects into the monitor its owner is sharing from the
/// browser, and the browser only says how big the shared surface is. The rules
/// are the contract's (`docs/desktop/CONTROL_COMPANION.md`):
///
/// 1. one monitor — it is that one ([`SurfaceMatch::Single`], `AUTO_SINGLE`);
/// 2. exactly one monitor whose aspect ratio is within [`ASPECT_TOLERANCE`] of
///    the surface's — that one ([`SurfaceMatch::Aspect`], `AUTO_ASPECT`);
/// 3. anything else — the person at the machine picks
///    ([`SurfaceMatch::Ambiguous`], `USER`).
///
/// Only the **ratio** is compared, never the size. macOS lays monitors out in
/// points and a browser reports the capture in pixels (twice the points on a
/// Retina display), and a browser may also downscale a capture — so a size
/// that happens to equal another monitor's is not evidence of anything, and
/// guessing from it is how input lands on a screen nobody is looking at.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum SurfaceMatch {
    /// The machine has one monitor.
    Single(u32),
    /// Exactly one monitor has the surface's aspect ratio.
    Aspect(u32),
    /// The person at the machine has to choose. `candidates` are the monitors
    /// with the right aspect ratio when two or more have it, and every monitor
    /// when none does — the list the picker offers, in layout order.
    Ambiguous {
        /// Which monitors to offer.
        candidates: Vec<u32>,
    },
}

impl SurfaceMatch {
    /// The monitor chosen without asking anybody, if there is one.
    #[must_use]
    pub fn chosen(&self) -> Option<u32> {
        match self {
            Self::Single(id) | Self::Aspect(id) => Some(*id),
            Self::Ambiguous { .. } => None,
        }
    }

    /// How the choice was made, in the API's spelling (`matchedBy`).
    #[must_use]
    pub fn matched_by(&self) -> Option<&'static str> {
        match self {
            Self::Single(_) => Some("AUTO_SINGLE"),
            Self::Aspect(_) => Some("AUTO_ASPECT"),
            Self::Ambiguous { .. } => None,
        }
    }
}

/// Every display the agent can see, and which one it is sharing.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct MonitorLayout {
    /// The displays, in the order the platform reported them.
    pub monitors: Vec<Monitor>,
    /// The `id` of the one currently being captured.
    pub active_monitor_id: u32,
}

/// More displays than any real machine has, and enough to stop a hostile peer
/// from making the controller allocate.
const MAX_MONITORS: usize = 16;

impl MonitorLayout {
    /// One monitor, the common case.
    #[must_use]
    pub fn single(monitor: Monitor) -> Self {
        let active_monitor_id = monitor.id;

        Self {
            monitors: vec![monitor],
            active_monitor_id,
        }
    }

    /// The monitor currently being shared, if the layout is coherent.
    #[must_use]
    pub fn active(&self) -> Option<&Monitor> {
        self.monitors
            .iter()
            .find(|m| m.id == self.active_monitor_id)
    }

    /// Look one up by id.
    #[must_use]
    pub fn find(&self, id: u32) -> Option<&Monitor> {
        self.monitors.iter().find(|m| m.id == id)
    }

    /// Which of these monitors a shared surface of `width` × `height` is.
    ///
    /// See [`SurfaceMatch`] for the rules. A surface with no size (`0` for
    /// either side — the browser did not report one) can only be matched on a
    /// machine with a single monitor; with more, the person picks from all of
    /// them.
    #[must_use]
    pub fn match_surface(&self, width: u32, height: u32) -> SurfaceMatch {
        if let [only] = self.monitors.as_slice() {
            return SurfaceMatch::Single(only.id);
        }

        let everyone = || SurfaceMatch::Ambiguous {
            candidates: self.monitors.iter().map(|m| m.id).collect(),
        };

        if width == 0 || height == 0 {
            return everyone();
        }

        let surface = f64::from(width) / f64::from(height);

        let matching: Vec<u32> = self
            .monitors
            .iter()
            .filter(|monitor| monitor.width > 0 && monitor.height > 0)
            .filter(|monitor| {
                let ratio = f64::from(monitor.width) / f64::from(monitor.height);

                ((ratio - surface) / surface).abs() <= ASPECT_TOLERANCE
            })
            .map(|monitor| monitor.id)
            .collect();

        match matching.as_slice() {
            [one] => SurfaceMatch::Aspect(*one),
            [] => everyone(),
            _ => SurfaceMatch::Ambiguous {
                candidates: matching,
            },
        }
    }

    /// The same layout, sharing a different monitor. `None` when there is no
    /// such monitor, so a stale choice cannot produce an incoherent layout.
    #[must_use]
    pub fn with_active(&self, monitor_id: u32) -> Option<Self> {
        self.find(monitor_id)?;

        Some(Self {
            monitors: self.monitors.clone(),
            active_monitor_id: monitor_id,
        })
    }

    /// Coherent: at least one monitor, no duplicate ids, and the active one
    /// exists.
    ///
    /// The last check is what stops a layout arriving in which the controller
    /// would map every click against a monitor that is not there — which
    /// happens for real when a screen is unplugged mid-session and only half
    /// the update is applied.
    pub fn validate(&self) -> Result<(), ProtocolError> {
        if self.monitors.is_empty() || self.monitors.len() > MAX_MONITORS {
            return Err(ProtocolError::OutOfBounds);
        }

        for (index, monitor) in self.monitors.iter().enumerate() {
            monitor.validate()?;

            if self.monitors[..index]
                .iter()
                .any(|other| other.id == monitor.id)
            {
                return Err(ProtocolError::OutOfBounds);
            }
        }

        if self.active().is_none() {
            return Err(ProtocolError::OutOfBounds);
        }

        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn monitor(id: u32, x: i32, y: i32, width: u32, height: u32) -> Monitor {
        Monitor {
            id,
            name: format!("Display {id}"),
            primary: id == 1,
            x,
            y,
            width,
            height,
            scale: 1.0,
            refresh_hz: Some(60),
            orientation: Orientation::Landscape,
        }
    }

    #[test]
    fn normalising_and_back_lands_on_the_same_pixel() {
        let display = monitor(1, 0, 0, 1920, 1080);

        for (x, y) in [(0, 0), (1919, 1079), (960, 540), (1, 1079)] {
            let normalised = display.normalise(x, y);
            let (back_x, back_y) = display.denormalise(normalised).expect("valid");

            assert_eq!((back_x, back_y), (x, y), "round trip for ({x}, {y})");
        }
    }

    /// A screen to the left of the primary has a negative origin. Ignoring it
    /// puts every click on the wrong monitor.
    #[test]
    fn a_secondary_monitor_maps_into_its_own_place_on_the_virtual_desktop() {
        let left = monitor(2, -1920, 0, 1920, 1080);

        assert_eq!(
            left.denormalise(PointerPosition { x: 0.0, y: 0.0 }),
            Some((-1920, 0))
        );
        assert_eq!(
            left.denormalise(PointerPosition { x: 1.0, y: 1.0 }),
            Some((-1, 1079))
        );
    }

    /// 1.0 is the last pixel of this screen, not the first of the next one.
    #[test]
    fn the_far_edge_is_the_last_pixel_not_the_next_monitors_first() {
        let primary = monitor(1, 0, 0, 1920, 1080);
        let right = monitor(2, 1920, 0, 1920, 1080);

        let (x, _) = primary
            .denormalise(PointerPosition { x: 1.0, y: 0.5 })
            .unwrap();

        assert_eq!(x, 1919);
        assert_ne!(x, right.x);
    }

    /// A 150% display reports physical pixels *and* its scale. Multiplying by
    /// the scale as well is how a click lands half a screen away.
    #[test]
    fn dpi_scaling_does_not_move_a_coordinate() {
        let mut scaled = monitor(1, 0, 0, 2880, 1620);
        scaled.scale = 1.5;

        assert_eq!(
            scaled.denormalise(PointerPosition { x: 0.5, y: 0.5 }),
            Some((1440, 810))
        );
    }

    #[test]
    fn a_rotated_display_is_described_by_its_actual_pixels() {
        let mut portrait = monitor(1, 0, 0, 1080, 1920);
        portrait.orientation = Orientation::Portrait;

        assert!(portrait.validate().is_ok());
        assert_eq!(
            portrait.denormalise(PointerPosition { x: 1.0, y: 1.0 }),
            Some((1079, 1919))
        );
    }

    #[test]
    fn an_invalid_position_yields_no_coordinate_at_all() {
        let display = monitor(1, 0, 0, 1920, 1080);

        assert_eq!(
            display.denormalise(PointerPosition {
                x: f64::NAN,
                y: 0.5
            }),
            None
        );
        assert_eq!(
            display.denormalise(PointerPosition { x: 1.2, y: 0.5 }),
            None
        );
    }

    #[test]
    fn a_monitor_with_no_pixels_is_refused() {
        assert!(monitor(1, 0, 0, 0, 1080).validate().is_err());
        assert!(monitor(1, 0, 0, 1920, 0).validate().is_err());
        assert!(monitor(1, 0, 0, 99_999, 1080).validate().is_err());
    }

    #[test]
    fn a_layout_must_contain_the_monitor_it_says_is_active() {
        let layout = MonitorLayout {
            monitors: vec![monitor(1, 0, 0, 1920, 1080)],
            active_monitor_id: 7,
        };

        assert_eq!(layout.validate(), Err(ProtocolError::OutOfBounds));
        assert!(layout.active().is_none());
    }

    #[test]
    fn duplicate_monitor_ids_are_refused() {
        let layout = MonitorLayout {
            monitors: vec![
                monitor(1, 0, 0, 1920, 1080),
                monitor(1, 1920, 0, 1920, 1080),
            ],
            active_monitor_id: 1,
        };

        assert_eq!(layout.validate(), Err(ProtocolError::OutOfBounds));
    }

    #[test]
    fn an_empty_layout_is_refused() {
        let layout = MonitorLayout {
            monitors: vec![],
            active_monitor_id: 1,
        };

        assert_eq!(layout.validate(), Err(ProtocolError::OutOfBounds));
    }

    fn layout(monitors: Vec<Monitor>) -> MonitorLayout {
        let active_monitor_id = monitors[0].id;

        MonitorLayout {
            monitors,
            active_monitor_id,
        }
    }

    fn retina(id: u32, x: i32, width_points: u32, height_points: u32) -> Monitor {
        Monitor {
            scale: 2.0,
            ..monitor(id, x, 0, width_points, height_points)
        }
    }

    /// One monitor is the one being shared, whatever the browser says — even
    /// when it said nothing.
    #[test]
    fn a_single_monitor_is_matched_without_looking_at_the_surface() {
        let single = layout(vec![monitor(7, 0, 0, 1920, 1080)]);

        assert_eq!(single.match_surface(1920, 1080), SurfaceMatch::Single(7));
        assert_eq!(single.match_surface(0, 0), SurfaceMatch::Single(7));
        assert_eq!(single.match_surface(1024, 768), SurfaceMatch::Single(7));
        assert_eq!(single.match_surface(1, 1).matched_by(), Some("AUTO_SINGLE"));
        assert_eq!(single.match_surface(1, 1).chosen(), Some(7));
    }

    /// A 16:10 laptop beside a 16:9 external screen: the ratio tells them apart.
    #[test]
    fn exactly_one_matching_aspect_ratio_is_chosen() {
        let desk = layout(vec![
            monitor(1, 0, 0, 2560, 1600),
            monitor(2, 2560, 0, 3840, 2160),
        ]);

        assert_eq!(desk.match_surface(3840, 2160), SurfaceMatch::Aspect(2));
        // A browser that downscaled the 4K capture still matches it by ratio.
        assert_eq!(desk.match_surface(1920, 1080), SurfaceMatch::Aspect(2));
        assert_eq!(desk.match_surface(1280, 800), SurfaceMatch::Aspect(1));
        assert_eq!(
            desk.match_surface(1280, 800).matched_by(),
            Some("AUTO_ASPECT")
        );
    }

    /// Within 1% is the same ratio — a browser rounds odd sizes — and 2% is not.
    #[test]
    fn the_aspect_tolerance_is_one_percent() {
        let desk = layout(vec![
            monitor(1, 0, 0, 1920, 1080),
            monitor(2, 1920, 0, 1920, 1200),
        ]);

        // 1918 / 1080 is 0.1% from 16:9.
        assert_eq!(desk.match_surface(1918, 1080), SurfaceMatch::Aspect(1));
        // 1920 / 1060 is 1.9% from 16:9 and nowhere near 16:10.
        assert_eq!(
            desk.match_surface(1920, 1060),
            SurfaceMatch::Ambiguous {
                candidates: vec![1, 2]
            }
        );
    }

    /// Two identical screens cannot be told apart by anything the browser
    /// reports, so the person at the machine is asked — never a guess.
    #[test]
    fn identical_monitors_are_ambiguous_and_the_person_picks() {
        let twins = layout(vec![
            monitor(1, 0, 0, 2560, 1440),
            monitor(2, 2560, 0, 2560, 1440),
            monitor(3, -1200, 0, 1200, 1920),
        ]);

        let matched = twins.match_surface(2560, 1440);

        assert_eq!(
            matched,
            SurfaceMatch::Ambiguous {
                candidates: vec![1, 2]
            }
        );
        assert_eq!(matched.chosen(), None);
        assert_eq!(matched.matched_by(), None);
    }

    /// Nothing with the right shape: the picker offers every monitor rather
    /// than none.
    #[test]
    fn no_matching_ratio_offers_every_monitor() {
        let desk = layout(vec![
            monitor(1, 0, 0, 1920, 1080),
            monitor(2, 1920, 0, 2560, 1440),
        ]);

        assert_eq!(
            desk.match_surface(1000, 1000),
            SurfaceMatch::Ambiguous {
                candidates: vec![1, 2]
            }
        );
        assert_eq!(
            desk.match_surface(0, 1080),
            SurfaceMatch::Ambiguous {
                candidates: vec![1, 2]
            }
        );
    }

    /// macOS lays monitors out in points; a browser reports the capture of a
    /// Retina display in pixels — twice the size. The ratio is the same, and
    /// that is all that is compared.
    #[test]
    fn a_retina_display_in_points_matches_a_capture_in_pixels() {
        let mac = layout(vec![
            // MacBook Pro 14": 1512 × 982 points, 3024 × 1964 pixels.
            retina(1, 0, 1512, 982),
            // A 4K external at "looks like 1920 × 1080": 3840 × 2160 pixels.
            retina(2, 1512, 1920, 1080),
        ]);

        assert_eq!(mac.match_surface(3024, 1964), SurfaceMatch::Aspect(1));
        assert_eq!(mac.match_surface(1512, 982), SurfaceMatch::Aspect(1));
        assert_eq!(mac.match_surface(3840, 2160), SurfaceMatch::Aspect(2));

        // And a size that equals another monitor's points is not mistaken for
        // it: 1920 × 1080 is the external's points, and also its ratio.
        assert_eq!(mac.match_surface(1920, 1080), SurfaceMatch::Aspect(2));
    }

    /// Two Retina displays of the same model are as ambiguous as two of
    /// anything else.
    #[test]
    fn identical_retina_displays_are_ambiguous_whatever_the_scale() {
        let mac = layout(vec![retina(1, 0, 1728, 1117), retina(2, 1728, 1728, 1117)]);

        assert!(matches!(
            mac.match_surface(3456, 2234),
            SurfaceMatch::Ambiguous { .. }
        ));
    }

    #[test]
    fn choosing_a_different_active_monitor_keeps_the_layout_coherent() {
        let desk = layout(vec![
            monitor(1, 0, 0, 1920, 1080),
            monitor(2, 1920, 0, 2560, 1440),
        ]);

        let chosen = desk.with_active(2).expect("exists");
        assert_eq!(chosen.active_monitor_id, 2);
        assert!(chosen.validate().is_ok());

        assert!(desk.with_active(9).is_none());
    }

    #[test]
    fn a_multi_monitor_layout_round_trips_over_the_wire() {
        let layout = MonitorLayout {
            monitors: vec![
                monitor(1, 0, 0, 1920, 1080),
                monitor(2, 1920, -200, 2560, 1440),
                monitor(3, -1080, 0, 1080, 1920),
            ],
            active_monitor_id: 2,
        };

        assert!(layout.validate().is_ok());

        let json = serde_json::to_string(&layout).unwrap();
        let back: MonitorLayout = serde_json::from_str(&json).unwrap();

        assert_eq!(back, layout);
        assert_eq!(back.active().unwrap().width, 2560);
        assert_eq!(back.find(3).unwrap().height, 1920);
    }
}
