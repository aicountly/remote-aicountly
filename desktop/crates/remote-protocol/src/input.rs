//! Mouse and keyboard events, in a form no operating system owns.
//!
//! The types here are deliberately not Windows virtual-key codes, not X11
//! keysyms and not browser `KeyboardEvent.code` strings. They are the small
//! vocabulary all three can be translated into and out of, so the browser does
//! not have to know what Windows calls the Home key and the platform layer
//! does not have to parse a DOM string.
//!
//! Anything a client sends that this vocabulary cannot express is dropped
//! rather than approximated. Approximating a keystroke on somebody else's
//! machine is how a remote-control product presses the wrong thing.

use serde::{Deserialize, Serialize};

use crate::ProtocolError;

/// A point on the shared surface, as a fraction of it.
///
/// Never pixels. See the crate documentation for why.
#[derive(Debug, Clone, Copy, PartialEq, Serialize, Deserialize)]
pub struct PointerPosition {
    /// 0.0 at the left edge, 1.0 at the right.
    pub x: f64,
    /// 0.0 at the top edge, 1.0 at the bottom.
    pub y: f64,
}

impl PointerPosition {
    /// Inside the surface, and a real number.
    ///
    /// `is_finite()` is not pedantry: `NaN` compares false against every bound,
    /// so a naive range check lets it through — and every platform pointer API
    /// will accept the cast result without complaint.
    pub fn validate(&self) -> Result<(), ProtocolError> {
        if !self.x.is_finite() || !self.y.is_finite() {
            return Err(ProtocolError::OutOfBounds);
        }

        if !(0.0..=1.0).contains(&self.x) || !(0.0..=1.0).contains(&self.y) {
            return Err(ProtocolError::OutOfBounds);
        }

        Ok(())
    }

    /// Clamp into range. Used after arithmetic, never instead of validating
    /// something that arrived from a peer.
    #[must_use]
    pub fn clamped(self) -> Self {
        Self {
            x: if self.x.is_finite() {
                self.x.clamp(0.0, 1.0)
            } else {
                0.0
            },
            y: if self.y.is_finite() {
                self.y.clamp(0.0, 1.0)
            } else {
                0.0
            },
        }
    }
}

/// The buttons a mouse is allowed to have here.
///
/// Three. Browsers report `button` 3 and 4 for back and forward, and this
/// deliberately does not carry them: they are application navigation, they are
/// not needed to help somebody, and every additional injectable input is
/// additional surface.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum MouseButton {
    /// Primary.
    Left,
    /// Secondary — the context menu.
    Right,
    /// The wheel, pressed.
    Middle,
}

/// A button going down or up.
#[derive(Debug, Clone, Copy, PartialEq, Serialize, Deserialize)]
pub struct MouseEvent {
    /// Which button.
    pub button: MouseButton,
    /// Down (`true`) or up (`false`).
    pub pressed: bool,
    /// A double click, sent as a single event.
    ///
    /// Sent as one event rather than as two press/release pairs because the
    /// double-click interval is the *host's* setting, not the controller's —
    /// two clicks 300 ms apart are a double click on one machine and two
    /// clicks on another.
    #[serde(default)]
    pub double: bool,
    /// Where the pointer was, if the controller wants to move and click as one.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub position: Option<PointerPosition>,
}

impl MouseEvent {
    /// Validate the embedded position, if there is one.
    pub fn validate(&self) -> Result<(), ProtocolError> {
        // A release cannot be a double click; the pair would be meaningless
        // and the platform layer would have to invent what to do with it.
        if self.double && !self.pressed {
            return Err(ProtocolError::OutOfBounds);
        }

        match &self.position {
            Some(position) => position.validate(),
            None => Ok(()),
        }
    }
}

/// A wheel turn.
///
/// Deltas are in **notches**, not pixels or lines: a notch is the unit every
/// platform can express, and converting pixels to notches needs the host's own
/// scroll settings, which the controller does not have.
#[derive(Debug, Clone, Copy, PartialEq, Serialize, Deserialize)]
pub struct ScrollEvent {
    /// Horizontal notches. Positive is right.
    #[serde(default)]
    pub delta_x: f64,
    /// Vertical notches. Positive is down, matching every platform's own sign.
    #[serde(default)]
    pub delta_y: f64,
    /// Where to scroll, if the controller wants to move and scroll as one.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub position: Option<PointerPosition>,
}

/// A single wheel event cannot exceed this many notches.
///
/// A hostile peer sending a delta of 10^9 would produce a scroll the person at
/// the machine cannot recover from by scrolling back.
const MAX_SCROLL_NOTCHES: f64 = 64.0;

impl ScrollEvent {
    /// Bounded, finite, and any embedded position valid.
    pub fn validate(&self) -> Result<(), ProtocolError> {
        if !self.delta_x.is_finite() || !self.delta_y.is_finite() {
            return Err(ProtocolError::OutOfBounds);
        }

        if self.delta_x.abs() > MAX_SCROLL_NOTCHES || self.delta_y.abs() > MAX_SCROLL_NOTCHES {
            return Err(ProtocolError::OutOfBounds);
        }

        match &self.position {
            Some(position) => position.validate(),
            None => Ok(()),
        }
    }
}

/// Which modifiers were held at the instant of a key event.
///
/// Carried with the event rather than tracked as state on the agent. A
/// controller whose tab loses focus mid-chord never sends the key-up, and an
/// agent tracking state would be left believing Ctrl is still down — which is
/// how a remote session ends with a machine that behaves as though a key is
/// stuck.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct Modifiers {
    /// Ctrl (or Control on macOS).
    #[serde(default)]
    pub ctrl: bool,
    /// Alt (Option on macOS).
    #[serde(default)]
    pub alt: bool,
    /// Shift.
    #[serde(default)]
    pub shift: bool,
    /// The Windows key, or Command on macOS.
    #[serde(default)]
    pub meta: bool,
}

impl Modifiers {
    /// Nothing held.
    #[must_use]
    pub fn none() -> Self {
        Self::default()
    }

    /// Whether any modifier at all is held.
    #[must_use]
    pub fn any(&self) -> bool {
        self.ctrl || self.alt || self.shift || self.meta
    }
}

/// A key, in a vocabulary no operating system owns.
///
/// `Character` carries the *printable character the controller intends*, not a
/// scan code, so a French keyboard controlling a US one produces the character
/// that was typed rather than the one in that physical position. Everything
/// else is named, because a name is unambiguous where a code is a lookup table
/// per platform.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(tag = "k", content = "c", rename_all = "snake_case")]
pub enum Key {
    /// A printable character, as the controller intends it.
    Character(char),

    /// Enter / Return.
    Enter,
    /// Tab.
    Tab,
    /// Space.
    Space,
    /// Backspace.
    Backspace,
    /// Delete (forward delete).
    Delete,
    /// Escape.
    Escape,
    /// Insert.
    Insert,

    /// Arrow up.
    ArrowUp,
    /// Arrow down.
    ArrowDown,
    /// Arrow left.
    ArrowLeft,
    /// Arrow right.
    ArrowRight,

    /// Home.
    Home,
    /// End.
    End,
    /// Page up.
    PageUp,
    /// Page down.
    PageDown,

    /// A function key. F1 through F24.
    Function(u8),

    /// Left Ctrl.
    Ctrl,
    /// Left Alt.
    Alt,
    /// Left Shift.
    Shift,
    /// The Windows / Command key.
    Meta,

    /// Caps lock.
    CapsLock,
    /// Print screen.
    PrintScreen,
}

impl Key {
    /// A function key is F1..=F24; a character must be one Windows can inject.
    pub fn validate(&self) -> Result<(), ProtocolError> {
        match self {
            Self::Function(n) => {
                if (1..=24).contains(n) {
                    Ok(())
                } else {
                    Err(ProtocolError::OutOfBounds)
                }
            }
            Self::Character(c) => {
                // Control characters have named variants above. Accepting them
                // here as well would give two spellings for one keystroke, and
                // the platform layer would have to handle both.
                if c.is_control() {
                    Err(ProtocolError::OutOfBounds)
                } else {
                    Ok(())
                }
            }
            _ => Ok(()),
        }
    }
}

/// A key going down or up.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
pub struct KeyEvent {
    /// Which key.
    pub key: Key,
    /// Down (`true`) or up (`false`).
    pub pressed: bool,
    /// What was held at that instant.
    #[serde(default)]
    pub modifiers: Modifiers,
}

impl KeyEvent {
    /// Validate the key.
    pub fn validate(&self) -> Result<(), ProtocolError> {
        self.key.validate()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_pointer_position_must_be_inside_the_surface() {
        assert!(PointerPosition { x: 0.0, y: 0.0 }.validate().is_ok());
        assert!(PointerPosition { x: 1.0, y: 1.0 }.validate().is_ok());
        assert!(PointerPosition { x: -0.001, y: 0.5 }.validate().is_err());
        assert!(PointerPosition { x: 0.5, y: 1.001 }.validate().is_err());
    }

    /// `NaN` compares false against every bound, so a naive range check passes
    /// it straight through to the platform's pointer API.
    #[test]
    fn nan_is_refused_rather_than_slipping_past_a_range_check() {
        assert!(PointerPosition {
            x: f64::NAN,
            y: 0.5
        }
        .validate()
        .is_err());
        assert!(PointerPosition {
            x: 0.5,
            y: f64::NAN
        }
        .validate()
        .is_err());
        assert!(PointerPosition {
            x: f64::INFINITY,
            y: 0.0
        }
        .validate()
        .is_err());
    }

    #[test]
    fn clamping_makes_a_wild_value_safe_without_hiding_it() {
        assert_eq!(
            PointerPosition { x: 2.0, y: -1.0 }.clamped(),
            PointerPosition { x: 1.0, y: 0.0 }
        );
        assert_eq!(
            PointerPosition {
                x: f64::NAN,
                y: 0.5
            }
            .clamped(),
            PointerPosition { x: 0.0, y: 0.5 }
        );
    }

    #[test]
    fn a_release_cannot_be_a_double_click() {
        let event = MouseEvent {
            button: MouseButton::Left,
            pressed: false,
            double: true,
            position: None,
        };

        assert_eq!(event.validate(), Err(ProtocolError::OutOfBounds));
    }

    #[test]
    fn scrolling_is_bounded_so_it_can_be_scrolled_back() {
        assert!(ScrollEvent {
            delta_x: 0.0,
            delta_y: 3.0,
            position: None
        }
        .validate()
        .is_ok());
        assert!(ScrollEvent {
            delta_x: 0.0,
            delta_y: 1e9,
            position: None
        }
        .validate()
        .is_err());
        assert!(ScrollEvent {
            delta_x: f64::NAN,
            delta_y: 0.0,
            position: None
        }
        .validate()
        .is_err());
    }

    #[test]
    fn function_keys_stop_at_f24() {
        assert!(Key::Function(1).validate().is_ok());
        assert!(Key::Function(24).validate().is_ok());
        assert!(Key::Function(0).validate().is_err());
        assert!(Key::Function(25).validate().is_err());
    }

    /// Control characters have named variants; accepting them as characters
    /// too would give one keystroke two spellings on the wire.
    #[test]
    fn a_control_character_is_not_a_character_key() {
        assert!(Key::Character('a').validate().is_ok());
        assert!(Key::Character('€').validate().is_ok());
        assert!(Key::Character('\n').validate().is_err());
        assert!(Key::Character('\u{7}').validate().is_err());
    }

    #[test]
    fn modifiers_travel_with_the_event_rather_than_as_agent_state() {
        let event = KeyEvent {
            key: Key::Character('c'),
            pressed: true,
            modifiers: Modifiers {
                ctrl: true,
                ..Modifiers::none()
            },
        };

        let json = serde_json::to_string(&event).unwrap();
        let back: KeyEvent = serde_json::from_str(&json).unwrap();

        assert!(back.modifiers.ctrl);
        assert!(back.modifiers.any());
        assert!(!Modifiers::none().any());
    }

    #[test]
    fn a_key_event_with_no_modifiers_field_parses_as_none_held() {
        let event: KeyEvent =
            serde_json::from_str(r#"{"key":{"k":"enter"},"pressed":true}"#).unwrap();

        assert_eq!(event.key, Key::Enter);
        assert!(!event.modifiers.any());
    }
}
