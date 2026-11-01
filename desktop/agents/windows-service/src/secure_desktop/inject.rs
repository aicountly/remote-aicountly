//! Turning a control message into the numbers `SendInput` wants.
//!
//! # Why this is duplicated, and how little
//!
//! The app's `platform::windows::input` already has this logic — the
//! virtual-key table, the extended-key set, the wheel-delta conversion and the
//! absolute-coordinate mapping. It cannot be shared from there: that module is
//! in the `aicountly-remote-desktop` crate, which **depends on** this service
//! crate, so reaching back the other way would be a dependency cycle. Moving it
//! into a lower crate was the alternative, but these are Windows injection
//! specifics (virtual-key codes, the extended-key flag) and `remote-protocol`
//! is deliberately an operating-system-agnostic crate — "the types here are not
//! Windows virtual-key codes" is a property it states about itself.
//!
//! So the *minimum* is duplicated here: the few pure functions the helper
//! needs, each tested on every host exactly as the app's copy is. If one copy
//! changes, the other should, and the tests on both sides are what catch a
//! drift. The capture and `SendInput` plumbing is not duplicated — that is in
//! [`super::helper`] and is the helper's own.
//!
//! # Coordinates
//!
//! The helper captures the **whole virtual desktop** (every monitor), so a
//! pointer position that arrived as a fraction of the picture is a fraction of
//! the virtual desktop, and maps straight onto the `0..=65535` space that
//! `MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_VIRTUALDESK` uses — no per-monitor
//! arithmetic, because there is only the one surface. That is simpler than the
//! app's hosted path (which shares one monitor) and is why the mapping here is
//! its own small function rather than a reuse of `to_virtual_desktop`.

use remote_protocol::{Key, PointerPosition};

/// The absolute coordinate space `SendInput` uses across the virtual desktop.
pub const ABSOLUTE_RANGE: f64 = 65_535.0;

/// One notch of a wheel, as Windows counts it.
pub const WHEEL_DELTA: i32 = 120;

/// Where a fraction of the captured surface lands in the absolute space.
///
/// Clamped, because a position that arrived very slightly outside `0..=1`
/// (floating-point from the browser) should click the edge rather than wrap.
/// A non-finite coordinate — which `ControlEnvelope::validate` already refuses
/// upstream — is pinned to the origin rather than cast to something arbitrary.
#[must_use]
pub fn to_absolute(position: PointerPosition) -> (i32, i32) {
    let clamp = |value: f64| {
        if value.is_finite() {
            (value.clamp(0.0, 1.0) * ABSOLUTE_RANGE).round() as i32
        } else {
            0
        }
    };

    (clamp(position.x), clamp(position.y))
}

/// Wheel notches, as Windows counts them.
#[must_use]
pub fn wheel_delta(notches: f64) -> i32 {
    (notches * f64::from(WHEEL_DELTA)).round() as i32
}

/// The Windows virtual-key code for a protocol key, or `None` for one the
/// helper will not inject.
///
/// The same table as the app's `virtual_key`, kept in step with it. A
/// printable character returns `None` because it is injected as a Unicode
/// event instead, so the character the controller intends arrives whatever
/// keyboard layout the Secure Desktop is using.
#[must_use]
pub fn virtual_key(key: Key) -> Option<u16> {
    Some(match key {
        Key::Backspace => 0x08,
        Key::Tab => 0x09,
        Key::Enter => 0x0D,
        Key::Shift => 0x10,
        Key::Ctrl => 0x11,
        Key::Alt => 0x12,
        Key::CapsLock => 0x14,
        Key::Escape => 0x1B,
        Key::Space => 0x20,
        Key::PageUp => 0x21,
        Key::PageDown => 0x22,
        Key::End => 0x23,
        Key::Home => 0x24,
        Key::ArrowLeft => 0x25,
        Key::ArrowUp => 0x26,
        Key::ArrowRight => 0x27,
        Key::ArrowDown => 0x28,
        Key::PrintScreen => 0x2C,
        Key::Insert => 0x2D,
        Key::Delete => 0x2E,
        Key::Meta => 0x5B,
        Key::Function(n) if (1..=24).contains(&n) => 0x70 + u16::from(n) - 1,
        Key::Function(_) => return None,
        Key::Character(_) => return None,
    })
}

/// Whether a key needs `KEYEVENTF_EXTENDEDKEY`.
///
/// The same set as the app's `is_extended`: the navigation cluster and the
/// keys that share a virtual-key code with the numeric keypad, so Home is Home
/// and not Numpad-7 when Num Lock is on.
#[must_use]
pub fn is_extended(key: Key) -> bool {
    matches!(
        key,
        Key::Insert
            | Key::Delete
            | Key::Home
            | Key::End
            | Key::PageUp
            | Key::PageDown
            | Key::ArrowUp
            | Key::ArrowDown
            | Key::ArrowLeft
            | Key::ArrowRight
            | Key::PrintScreen
            | Key::Meta
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_corners_of_the_surface_map_to_the_corners_of_the_absolute_space() {
        assert_eq!(to_absolute(PointerPosition { x: 0.0, y: 0.0 }), (0, 0));
        assert_eq!(
            to_absolute(PointerPosition { x: 1.0, y: 1.0 }),
            (65_535, 65_535)
        );

        let (x, y) = to_absolute(PointerPosition { x: 0.5, y: 0.5 });
        assert!((32_000..34_000).contains(&x));
        assert!((32_000..34_000).contains(&y));
    }

    #[test]
    fn an_out_of_range_or_non_finite_coordinate_is_clamped_rather_than_wrapping() {
        assert_eq!(
            to_absolute(PointerPosition { x: 1.5, y: -1.0 }),
            (65_535, 0)
        );
        assert_eq!(
            to_absolute(PointerPosition {
                x: f64::NAN,
                y: f64::INFINITY,
            }),
            (0, 0)
        );
    }

    #[test]
    fn wheel_notches_become_windows_deltas() {
        assert_eq!(wheel_delta(1.0), 120);
        assert_eq!(wheel_delta(-3.0), -360);
    }

    /// The table matches the app's `virtual_key`: the same named keys, the
    /// same contiguous function-key block, and a character injected as Unicode.
    #[test]
    fn the_named_keys_have_codes_and_characters_go_as_unicode() {
        assert_eq!(virtual_key(Key::Enter), Some(0x0D));
        assert_eq!(virtual_key(Key::Home), Some(0x24));
        assert_eq!(virtual_key(Key::Function(1)), Some(0x70));
        assert_eq!(virtual_key(Key::Function(24)), Some(0x87));
        assert_eq!(virtual_key(Key::Function(0)), None);
        assert_eq!(virtual_key(Key::Character('a')), None);
    }

    #[test]
    fn the_navigation_cluster_is_extended() {
        for key in [Key::Home, Key::End, Key::ArrowUp, Key::Delete, Key::Meta] {
            assert!(is_extended(key), "{key:?}");
        }
        assert!(!is_extended(Key::Enter));
        assert!(!is_extended(Key::Character('a')));
    }
}
