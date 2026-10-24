//! Keyboard and mouse injection on macOS, through `CGEvent`.
//!
//! # A private event source
//!
//! Every event is created from a `kCGEventSourceStatePrivate` source rather
//! than the combined session state. Two reasons:
//!
//! * the modifier flags on an injected event are exactly the ones set here,
//!   not whatever the person at the machine happens to be holding — a remote
//!   "c" must not become ⌘C because somebody's hand is on the Command key;
//! * the HID system state, which the local-input override reads
//!   (`CGEventSourceSecondsSinceLastEventType`), is the hardware's, so the
//!   person at the machine moving their own mouse can be told apart from the
//!   events this file posts.
//!
//! # Accessibility
//!
//! Posting events needs the Accessibility permission. Without it macOS drops
//! them **silently** — `CGEventPost` returns nothing to check — which is why
//! the permission is checked (`permissions.rs`) before the companion reports
//! itself ready, rather than discovered when a click does nothing.
//!
//! # What is decided here, and tested on every host
//!
//! The key table, the US-layout character table used for shortcuts, the
//! modifier flags, which event type a move is while a button is held, and the
//! scroll arithmetic are all outside `mod imp`, so Linux CI tests them.

use std::sync::Mutex;

#[cfg(not(target_os = "macos"))]
use remote_device::PlatformError;
use remote_device::{InputProvider, PlatformResult};
use remote_protocol::{
    Key, KeyEvent, Modifiers, Monitor, MouseButton, MouseEvent, PointerPosition, ScrollEvent,
};

/// `kCGEventFlagMaskShift`.
pub const FLAG_SHIFT: u64 = 0x0002_0000;
/// `kCGEventFlagMaskControl`.
pub const FLAG_CONTROL: u64 = 0x0004_0000;
/// `kCGEventFlagMaskAlternate` — Option.
pub const FLAG_OPTION: u64 = 0x0008_0000;
/// `kCGEventFlagMaskCommand`.
pub const FLAG_COMMAND: u64 = 0x0010_0000;

/// The `CGEventFlags` a protocol modifier state means.
///
/// The protocol's `meta` is the Windows key on a PC controller and Command on
/// a Mac one; both become Command here, which is what a person reaching for
/// "copy" on either means.
#[must_use]
pub fn event_flags(modifiers: Modifiers) -> u64 {
    let mut flags = 0;

    if modifiers.shift {
        flags |= FLAG_SHIFT;
    }
    if modifiers.ctrl {
        flags |= FLAG_CONTROL;
    }
    if modifiers.alt {
        flags |= FLAG_OPTION;
    }
    if modifiers.meta {
        flags |= FLAG_COMMAND;
    }

    flags
}

/// The macOS virtual key code (`kVK_*`, `Events.h`) for a named key.
///
/// `None` for a printable character — see [`key_plan`] — and for anything a
/// Mac keyboard does not have (F21–F24, Print Screen). Refusing rather than
/// approximating, as on Windows.
#[must_use]
pub fn mac_keycode(key: Key) -> Option<u16> {
    Some(match key {
        Key::Enter => 0x24,
        Key::Tab => 0x30,
        Key::Space => 0x31,
        Key::Backspace => 0x33,
        Key::Escape => 0x35,
        Key::Meta => 0x37,
        Key::Shift => 0x38,
        Key::CapsLock => 0x39,
        Key::Alt => 0x3A,
        Key::Ctrl => 0x3B,
        // kVK_Help sits where Insert is on a PC keyboard.
        Key::Insert => 0x72,
        Key::Home => 0x73,
        Key::PageUp => 0x74,
        Key::Delete => 0x75,
        Key::End => 0x77,
        Key::PageDown => 0x79,
        Key::ArrowLeft => 0x7B,
        Key::ArrowRight => 0x7C,
        Key::ArrowDown => 0x7D,
        Key::ArrowUp => 0x7E,
        Key::Function(n) => return function_keycode(n),
        Key::PrintScreen | Key::Character(_) => return None,
    })
}

fn function_keycode(n: u8) -> Option<u16> {
    // Not contiguous: Apple's F-key codes follow the original ADB layout.
    const CODES: [u16; 20] = [
        0x7A, 0x78, 0x63, 0x76, 0x60, 0x61, 0x62, 0x64, 0x65, 0x6D, // F1–F10
        0x67, 0x6F, 0x69, 0x6B, 0x71, 0x6A, 0x40, 0x4F, 0x50, 0x5A, // F11–F20
    ];

    CODES.get(usize::from(n).checked_sub(1)?).copied()
}

/// The ANSI (US) key code for a printable character, and whether Shift makes
/// it. Used only for shortcuts — a character typed with Command, Control or
/// Option held — because a shortcut is a key, not text: ⌘C has to arrive as
/// the C key with Command down for an application to see "copy".
#[must_use]
pub fn ansi_keycode(character: char) -> Option<(u16, bool)> {
    let lower = character.to_ascii_lowercase();
    let shifted_letter = character.is_ascii_uppercase();

    let unshifted = |c: char| -> Option<u16> {
        Some(match c {
            'a' => 0x00,
            's' => 0x01,
            'd' => 0x02,
            'f' => 0x03,
            'h' => 0x04,
            'g' => 0x05,
            'z' => 0x06,
            'x' => 0x07,
            'c' => 0x08,
            'v' => 0x09,
            'b' => 0x0B,
            'q' => 0x0C,
            'w' => 0x0D,
            'e' => 0x0E,
            'r' => 0x0F,
            'y' => 0x10,
            't' => 0x11,
            '1' => 0x12,
            '2' => 0x13,
            '3' => 0x14,
            '4' => 0x15,
            '6' => 0x16,
            '5' => 0x17,
            '=' => 0x18,
            '9' => 0x19,
            '7' => 0x1A,
            '-' => 0x1B,
            '8' => 0x1C,
            '0' => 0x1D,
            ']' => 0x1E,
            'o' => 0x1F,
            'u' => 0x20,
            '[' => 0x21,
            'i' => 0x22,
            'p' => 0x23,
            'l' => 0x25,
            'j' => 0x26,
            '\'' => 0x27,
            'k' => 0x28,
            ';' => 0x29,
            '\\' => 0x2A,
            ',' => 0x2B,
            '/' => 0x2C,
            'n' => 0x2D,
            'm' => 0x2E,
            '.' => 0x2F,
            '`' => 0x32,
            _ => return None,
        })
    };

    if let Some(code) = unshifted(lower) {
        return Some((code, shifted_letter));
    }

    let base = match character {
        '!' => '1',
        '@' => '2',
        '#' => '3',
        '$' => '4',
        '%' => '5',
        '^' => '6',
        '&' => '7',
        '*' => '8',
        '(' => '9',
        ')' => '0',
        '_' => '-',
        '+' => '=',
        '{' => '[',
        '}' => ']',
        '|' => '\\',
        ':' => ';',
        '"' => '\'',
        '<' => ',',
        '>' => '.',
        '?' => '/',
        '~' => '`',
        _ => return None,
    };

    unshifted(base).map(|code| (code, true))
}

/// How one key event is posted.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum KeyPlan {
    /// A key event with this code and these flags.
    Code {
        /// `kVK_*`.
        code: u16,
        /// `CGEventFlags`.
        flags: u64,
    },
    /// Text: a key event carrying this character as its Unicode string, so
    /// the character the controller intends arrives whatever the Mac's
    /// keyboard layout — the same rule Windows follows with `KEYEVENTF_UNICODE`.
    Text(char),
    /// Nothing this build sends.
    Refused,
}

/// Decide how to post a key event.
#[must_use]
pub fn key_plan(event: &KeyEvent) -> KeyPlan {
    let flags = event_flags(event.modifiers);

    match event.key {
        Key::Character(character) => {
            let shortcut = event.modifiers.ctrl || event.modifiers.alt || event.modifiers.meta;

            match (shortcut, ansi_keycode(character)) {
                (true, Some((code, shift))) => KeyPlan::Code {
                    code,
                    flags: flags | if shift { FLAG_SHIFT } else { 0 },
                },
                // Plain typing, or a modified character with no key on a US
                // layout (Option-composed text): the character itself.
                _ => KeyPlan::Text(character),
            }
        }
        named => match mac_keycode(named) {
            Some(code) => KeyPlan::Code { code, flags },
            None => KeyPlan::Refused,
        },
    }
}

/// Where a normalised position on a monitor lands in global display
/// coordinates (points, top-left origin).
///
/// Fractional, because a point on a Retina display is two pixels and rounding
/// to whole points would halve the precision a controller has. `1.0` is the
/// last point of the monitor, not the first of the next one.
#[must_use]
pub fn to_global_point(monitor: &Monitor, position: PointerPosition) -> Option<(f64, f64)> {
    position.validate().ok()?;

    let span_x = (f64::from(monitor.width) - 1.0).max(0.0);
    let span_y = (f64::from(monitor.height) - 1.0).max(0.0);

    Some((
        f64::from(monitor.x) + span_x * position.x,
        f64::from(monitor.y) + span_y * position.y,
    ))
}

/// Which kind of event a pointer move is, given what is held.
///
/// macOS sends drags, not moves, while a button is down; an application that
/// is selecting text or dragging a window listens for the drag and ignores a
/// plain move, so posting `MouseMoved` with the button down drops the drag.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum MoveKind {
    /// `kCGEventMouseMoved`.
    Moved,
    /// `kCGEventLeftMouseDragged`.
    LeftDragged,
    /// `kCGEventRightMouseDragged`.
    RightDragged,
    /// `kCGEventOtherMouseDragged`.
    OtherDragged,
}

/// Buttons currently held by this agent.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct HeldButtons {
    /// Primary.
    pub left: bool,
    /// Secondary.
    pub right: bool,
    /// Wheel.
    pub middle: bool,
}

impl HeldButtons {
    /// The event a move is while these are held.
    #[must_use]
    pub fn move_kind(self) -> MoveKind {
        if self.left {
            MoveKind::LeftDragged
        } else if self.right {
            MoveKind::RightDragged
        } else if self.middle {
            MoveKind::OtherDragged
        } else {
            MoveKind::Moved
        }
    }

    fn set(&mut self, button: MouseButton, pressed: bool) {
        match button {
            MouseButton::Left => self.left = pressed,
            MouseButton::Right => self.right = pressed,
            MouseButton::Middle => self.middle = pressed,
        }
    }
}

/// Wheel notches as `CGEventCreateScrollWheelEvent2` line units.
///
/// macOS counts a positive vertical wheel as scrolling **up** and a positive
/// horizontal one as **left**; the protocol counts down and right as positive.
/// Hence the negation. A fractional notch is at least one line in its
/// direction rather than rounding to nothing.
#[must_use]
pub fn scroll_lines(notches: f64) -> i32 {
    if !notches.is_finite() || notches == 0.0 {
        return 0;
    }

    let lines = notches.abs().round().clamp(1.0, 64.0) as i32;

    if notches > 0.0 {
        -lines
    } else {
        lines
    }
}

/// Injection, through `CGEventPost`.
pub struct MacInput {
    /// Key codes held down by this agent, for [`InputProvider::release_all`].
    held_keys: Mutex<Vec<u16>>,
    /// Mouse buttons held down by this agent.
    held_buttons: Mutex<HeldButtons>,
    /// Where this agent last put the pointer, in global points.
    last_point: Mutex<Option<(f64, f64)>>,
}

impl Default for MacInput {
    fn default() -> Self {
        Self::new()
    }
}

impl MacInput {
    /// A provider with nothing held.
    #[must_use]
    pub fn new() -> Self {
        Self {
            held_keys: Mutex::new(Vec::new()),
            held_buttons: Mutex::new(HeldButtons::default()),
            last_point: Mutex::new(None),
        }
    }

    // Called from the injection path and from the tests, both of which a
    // non-macOS build has none of outside the tests.
    #[cfg_attr(not(target_os = "macos"), allow(dead_code))]
    fn track_key(&self, code: u16, pressed: bool) {
        if let Ok(mut held) = self.held_keys.lock() {
            if pressed {
                if !held.contains(&code) {
                    held.push(code);
                }
            } else {
                held.retain(|candidate| *candidate != code);
            }
        }
    }

    /// Key codes currently held. For the tests and diagnostics.
    #[must_use]
    pub fn held_keys(&self) -> Vec<u16> {
        self.held_keys
            .lock()
            .map(|held| held.clone())
            .unwrap_or_default()
    }

    #[cfg_attr(not(target_os = "macos"), allow(dead_code))]
    fn buttons(&self) -> HeldButtons {
        self.held_buttons
            .lock()
            .map(|held| *held)
            .unwrap_or_default()
    }

    #[cfg_attr(not(target_os = "macos"), allow(dead_code))]
    fn track_button(&self, button: MouseButton, pressed: bool) {
        if let Ok(mut held) = self.held_buttons.lock() {
            held.set(button, pressed);
        }
    }

    #[cfg_attr(not(target_os = "macos"), allow(dead_code))]
    fn remember_point(&self, point: (f64, f64)) {
        if let Ok(mut last) = self.last_point.lock() {
            *last = Some(point);
        }
    }

    #[cfg_attr(not(target_os = "macos"), allow(dead_code))]
    fn remembered_point(&self) -> Option<(f64, f64)> {
        self.last_point.lock().ok().and_then(|last| *last)
    }
}

#[cfg(not(target_os = "macos"))]
fn unsupported<T>() -> PlatformResult<T> {
    Err(PlatformError::Unsupported("Remote control"))
}

impl InputProvider for MacInput {
    fn move_pointer(&self, monitor_id: u32, position: PointerPosition) -> PlatformResult<()> {
        #[cfg(target_os = "macos")]
        {
            imp::move_pointer(self, monitor_id, position)
        }

        #[cfg(not(target_os = "macos"))]
        {
            let _ = (monitor_id, position);
            unsupported()
        }
    }

    fn move_pointer_relative(&self, dx: f64, dy: f64) -> PlatformResult<()> {
        #[cfg(target_os = "macos")]
        {
            imp::move_pointer_relative(self, dx, dy)
        }

        #[cfg(not(target_os = "macos"))]
        {
            let _ = (dx, dy);
            unsupported()
        }
    }

    fn mouse_button(&self, monitor_id: u32, event: MouseEvent) -> PlatformResult<()> {
        #[cfg(target_os = "macos")]
        {
            imp::mouse_button(self, monitor_id, event)
        }

        #[cfg(not(target_os = "macos"))]
        {
            let _ = (monitor_id, event);
            unsupported()
        }
    }

    fn scroll(&self, monitor_id: u32, event: ScrollEvent) -> PlatformResult<()> {
        #[cfg(target_os = "macos")]
        {
            imp::scroll(self, monitor_id, event)
        }

        #[cfg(not(target_os = "macos"))]
        {
            let _ = (monitor_id, event);
            unsupported()
        }
    }

    fn key(&self, event: KeyEvent) -> PlatformResult<()> {
        #[cfg(target_os = "macos")]
        {
            imp::key(self, event)
        }

        #[cfg(not(target_os = "macos"))]
        {
            let _ = event;
            unsupported()
        }
    }

    fn release_all(&self) -> PlatformResult<()> {
        let keys = self.held_keys();
        let buttons = self
            .held_buttons
            .lock()
            .map(|mut held| std::mem::take(&mut *held))
            .unwrap_or_default();

        if let Ok(mut held) = self.held_keys.lock() {
            held.clear();
        }

        #[cfg(target_os = "macos")]
        {
            // Best effort, each one: a key that will not release must not stop
            // the rest from being released.
            imp::release(self, &keys, buttons);
        }

        #[cfg(not(target_os = "macos"))]
        {
            let _ = (keys, buttons);
        }

        Ok(())
    }

    fn seconds_since_input(&self) -> PlatformResult<f64> {
        #[cfg(target_os = "macos")]
        {
            Ok(imp::seconds_since_hid_input())
        }

        #[cfg(not(target_os = "macos"))]
        {
            Err(PlatformError::Unsupported("Reading local input activity"))
        }
    }
}

#[cfg(target_os = "macos")]
mod imp {
    //! The `CGEvent` half. Compiled only on macOS.

    use core_graphics::event::{
        CGEvent, CGEventFlags, CGEventTapLocation, CGEventType, CGMouseButton, EventField,
        ScrollEventUnit,
    };
    use core_graphics::event_source::{CGEventSource, CGEventSourceStateID};
    use core_graphics::geometry::CGPoint;
    use remote_device::{PlatformError, PlatformResult};
    use remote_protocol::{KeyEvent, MouseButton, MouseEvent, PointerPosition, ScrollEvent};

    use super::{
        key_plan, scroll_lines, to_global_point, HeldButtons, KeyPlan, MacInput, MoveKind,
    };

    #[link(name = "CoreGraphics", kind = "framework")]
    extern "C" {
        fn CGEventSourceSecondsSinceLastEventType(source: i32, event_type: u32) -> f64;
    }

    /// `kCGEventSourceStateHIDSystemState`: the hardware's own state.
    const HID_SYSTEM_STATE: i32 = 1;
    /// `kCGAnyInputEventType`.
    const ANY_INPUT_EVENT: u32 = u32::MAX;

    /// Seconds since the hardware last reported keyboard or mouse input.
    pub fn seconds_since_hid_input() -> f64 {
        // SAFETY: a plain query with two integer arguments.
        let seconds =
            unsafe { CGEventSourceSecondsSinceLastEventType(HID_SYSTEM_STATE, ANY_INPUT_EVENT) };

        if seconds.is_finite() && seconds >= 0.0 {
            seconds
        } else {
            f64::MAX
        }
    }

    fn source() -> PlatformResult<CGEventSource> {
        CGEventSource::new(CGEventSourceStateID::Private).map_err(|()| PlatformError::Os {
            operation: "creating an input source",
            detail: "macOS refused to create an event source".into(),
        })
    }

    fn failed(operation: &'static str) -> PlatformError {
        PlatformError::Os {
            operation,
            detail: "macOS could not create the event".into(),
        }
    }

    fn post(input: &MacInput, event: &CGEvent) {
        let _ = input;
        event.post(CGEventTapLocation::HID);
    }

    fn resolve(monitor_id: u32, position: PointerPosition) -> PlatformResult<(f64, f64)> {
        let layout = super::super::display::current_layout()?;
        let monitor = layout
            .find(monitor_id)
            .or_else(|| layout.active())
            .ok_or(PlatformError::NotFound("That display"))?;

        to_global_point(monitor, position).ok_or(PlatformError::Os {
            operation: "moving the pointer",
            detail: "the position was not usable".into(),
        })
    }

    /// Where the pointer is: where this agent last put it, or where macOS says.
    fn current_point(input: &MacInput) -> PlatformResult<(f64, f64)> {
        if let Some(point) = input.remembered_point() {
            return Ok(point);
        }

        let location = CGEvent::new(source()?)
            .map_err(|()| failed("reading the pointer position"))?
            .location();

        Ok((location.x, location.y))
    }

    fn move_to(input: &MacInput, point: (f64, f64)) -> PlatformResult<()> {
        let (event_type, button) = match input.buttons().move_kind() {
            MoveKind::Moved => (CGEventType::MouseMoved, CGMouseButton::Left),
            MoveKind::LeftDragged => (CGEventType::LeftMouseDragged, CGMouseButton::Left),
            MoveKind::RightDragged => (CGEventType::RightMouseDragged, CGMouseButton::Right),
            MoveKind::OtherDragged => (CGEventType::OtherMouseDragged, CGMouseButton::Center),
        };

        let event = CGEvent::new_mouse_event(
            source()?,
            event_type,
            CGPoint::new(point.0, point.1),
            button,
        )
        .map_err(|()| failed("moving the pointer"))?;

        post(input, &event);
        input.remember_point(point);

        Ok(())
    }

    pub fn move_pointer(
        input: &MacInput,
        monitor_id: u32,
        position: PointerPosition,
    ) -> PlatformResult<()> {
        let point = resolve(monitor_id, position)?;

        move_to(input, point)
    }

    pub fn move_pointer_relative(input: &MacInput, dx: f64, dy: f64) -> PlatformResult<()> {
        // A relative move is a fraction of the shared surface; the main
        // display stands in for it, since the trait carries no monitor.
        let layout = super::super::display::current_layout()?;
        let main = layout
            .active()
            .ok_or(PlatformError::NotFound("A display"))?;
        let (x, y) = current_point(input)?;

        move_to(
            input,
            (
                x + dx * f64::from(main.width),
                y + dy * f64::from(main.height),
            ),
        )
    }

    pub fn mouse_button(
        input: &MacInput,
        monitor_id: u32,
        event: MouseEvent,
    ) -> PlatformResult<()> {
        let point = match event.position {
            Some(position) => {
                let point = resolve(monitor_id, position)?;
                move_to(input, point)?;
                point
            }
            None => current_point(input)?,
        };

        let (down, up, button) = match event.button {
            MouseButton::Left => (
                CGEventType::LeftMouseDown,
                CGEventType::LeftMouseUp,
                CGMouseButton::Left,
            ),
            MouseButton::Right => (
                CGEventType::RightMouseDown,
                CGEventType::RightMouseUp,
                CGMouseButton::Right,
            ),
            MouseButton::Middle => (
                CGEventType::OtherMouseDown,
                CGEventType::OtherMouseUp,
                CGMouseButton::Center,
            ),
        };

        let at = CGPoint::new(point.0, point.1);
        let click = |event_type: CGEventType, clicks: i64| -> PlatformResult<()> {
            let event = CGEvent::new_mouse_event(source()?, event_type, at, button)
                .map_err(|()| failed("clicking"))?;
            // The click count is what makes the second click of a pair a
            // double click; macOS does not infer it from timing for posted
            // events.
            event.set_integer_value_field(EventField::MOUSE_EVENT_CLICK_STATE, clicks);
            post(input, &event);

            Ok(())
        };

        if event.double {
            click(down, 1)?;
            click(up, 1)?;
            click(down, 2)?;
            click(up, 2)?;
            input.track_button(event.button, false);
        } else {
            click(if event.pressed { down } else { up }, 1)?;
            input.track_button(event.button, event.pressed);
        }

        Ok(())
    }

    pub fn scroll(input: &MacInput, monitor_id: u32, event: ScrollEvent) -> PlatformResult<()> {
        if let Some(position) = event.position {
            let point = resolve(monitor_id, position)?;
            move_to(input, point)?;
        }

        let vertical = scroll_lines(event.delta_y);
        let horizontal = scroll_lines(event.delta_x);

        if vertical == 0 && horizontal == 0 {
            return Ok(());
        }

        let wheel =
            CGEvent::new_scroll_event(source()?, ScrollEventUnit::LINE, 2, vertical, horizontal, 0)
                .map_err(|()| failed("scrolling"))?;
        post(input, &wheel);

        Ok(())
    }

    pub fn key(input: &MacInput, event: KeyEvent) -> PlatformResult<()> {
        match key_plan(&event) {
            KeyPlan::Code { code, flags } => {
                let key = CGEvent::new_keyboard_event(source()?, code, event.pressed)
                    .map_err(|()| failed("sending a key"))?;
                key.set_flags(CGEventFlags::from_bits_truncate(flags));
                post(input, &key);
                input.track_key(code, event.pressed);

                Ok(())
            }
            KeyPlan::Text(character) => {
                let key = CGEvent::new_keyboard_event(source()?, 0, event.pressed)
                    .map_err(|()| failed("typing"))?;
                let mut buffer = [0_u8; 4];
                key.set_string(character.encode_utf8(&mut buffer));
                post(input, &key);

                Ok(())
            }
            KeyPlan::Refused => Err(PlatformError::Os {
                operation: "sending a key",
                detail: "that key is not on a Mac keyboard".into(),
            }),
        }
    }

    pub fn release(input: &MacInput, keys: &[u16], buttons: HeldButtons) {
        for code in keys {
            if let Ok(source) = source() {
                if let Ok(up) = CGEvent::new_keyboard_event(source, *code, false) {
                    post(input, &up);
                }
            }
        }

        let Ok((x, y)) = current_point(input) else {
            return;
        };
        let at = CGPoint::new(x, y);

        for (held, event_type, button) in [
            (buttons.left, CGEventType::LeftMouseUp, CGMouseButton::Left),
            (
                buttons.right,
                CGEventType::RightMouseUp,
                CGMouseButton::Right,
            ),
            (
                buttons.middle,
                CGEventType::OtherMouseUp,
                CGMouseButton::Center,
            ),
        ] {
            if !held {
                continue;
            }

            if let Ok(source) = source() {
                if let Ok(up) = CGEvent::new_mouse_event(source, event_type, at, button) {
                    post(input, &up);
                }
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use remote_protocol::Orientation;

    fn modifiers(ctrl: bool, alt: bool, shift: bool, meta: bool) -> Modifiers {
        Modifiers {
            ctrl,
            alt,
            shift,
            meta,
        }
    }

    fn press(key: Key, mods: Modifiers) -> KeyEvent {
        KeyEvent {
            key,
            pressed: true,
            modifiers: mods,
        }
    }

    #[test]
    fn every_named_key_on_a_mac_keyboard_has_a_code() {
        for key in [
            Key::Enter,
            Key::Tab,
            Key::Space,
            Key::Backspace,
            Key::Delete,
            Key::Escape,
            Key::Insert,
            Key::ArrowUp,
            Key::ArrowDown,
            Key::ArrowLeft,
            Key::ArrowRight,
            Key::Home,
            Key::End,
            Key::PageUp,
            Key::PageDown,
            Key::Ctrl,
            Key::Alt,
            Key::Shift,
            Key::Meta,
            Key::CapsLock,
        ] {
            assert!(mac_keycode(key).is_some(), "{key:?} has no code");
        }

        // Apple's own codes, from Events.h.
        assert_eq!(mac_keycode(Key::Enter), Some(0x24));
        assert_eq!(mac_keycode(Key::Backspace), Some(0x33), "kVK_Delete");
        assert_eq!(mac_keycode(Key::Delete), Some(0x75), "kVK_ForwardDelete");
        assert_eq!(mac_keycode(Key::Meta), Some(0x37), "kVK_Command");
        assert_eq!(mac_keycode(Key::ArrowUp), Some(0x7E));
    }

    #[test]
    fn function_keys_follow_apples_table_and_stop_at_f20() {
        assert_eq!(mac_keycode(Key::Function(1)), Some(0x7A));
        assert_eq!(mac_keycode(Key::Function(5)), Some(0x60));
        assert_eq!(mac_keycode(Key::Function(12)), Some(0x6F));
        assert_eq!(mac_keycode(Key::Function(20)), Some(0x5A));
        assert_eq!(mac_keycode(Key::Function(21)), None);
        assert_eq!(mac_keycode(Key::Function(0)), None);
        assert_eq!(mac_keycode(Key::PrintScreen), None, "no such key on a Mac");
    }

    /// Plain typing goes as text, so the intended character arrives whatever
    /// layout the Mac uses.
    #[test]
    fn plain_typing_is_sent_as_the_character() {
        assert_eq!(
            key_plan(&press(Key::Character('a'), Modifiers::none())),
            KeyPlan::Text('a')
        );
        assert_eq!(
            key_plan(&press(
                Key::Character('A'),
                modifiers(false, false, true, false)
            )),
            KeyPlan::Text('A')
        );
        assert_eq!(
            key_plan(&press(Key::Character('é'), Modifiers::none())),
            KeyPlan::Text('é')
        );
    }

    /// ⌘C is the C key with Command down, or an application never sees "copy".
    #[test]
    fn a_shortcut_is_sent_as_a_key_with_its_modifiers() {
        assert_eq!(
            key_plan(&press(
                Key::Character('c'),
                modifiers(false, false, false, true)
            )),
            KeyPlan::Code {
                code: 0x08,
                flags: FLAG_COMMAND
            }
        );

        // ⌘⇧Z from a controller that reports the shifted character.
        assert_eq!(
            key_plan(&press(
                Key::Character('Z'),
                modifiers(false, false, true, true)
            )),
            KeyPlan::Code {
                code: 0x06,
                flags: FLAG_COMMAND | FLAG_SHIFT
            }
        );

        // ⌘? needs Shift on the slash key.
        assert_eq!(
            key_plan(&press(
                Key::Character('?'),
                modifiers(false, false, false, true)
            )),
            KeyPlan::Code {
                code: 0x2C,
                flags: FLAG_COMMAND | FLAG_SHIFT
            }
        );

        // A PC controller's Ctrl+C is Control-C on a Mac — not Command-C.
        assert_eq!(
            key_plan(&press(
                Key::Character('c'),
                modifiers(true, false, false, false)
            )),
            KeyPlan::Code {
                code: 0x08,
                flags: FLAG_CONTROL
            }
        );
    }

    /// A modified character with no US key (Option-composed text) is sent as
    /// the character rather than guessed at.
    #[test]
    fn a_modified_character_with_no_key_falls_back_to_text() {
        assert_eq!(
            key_plan(&press(
                Key::Character('€'),
                modifiers(false, true, false, false)
            )),
            KeyPlan::Text('€')
        );
    }

    #[test]
    fn named_keys_carry_the_modifier_flags_and_unknown_ones_are_refused() {
        assert_eq!(
            key_plan(&press(Key::ArrowLeft, modifiers(false, true, true, false))),
            KeyPlan::Code {
                code: 0x7B,
                flags: FLAG_OPTION | FLAG_SHIFT
            }
        );
        assert_eq!(
            key_plan(&press(Key::PrintScreen, Modifiers::none())),
            KeyPlan::Refused
        );
    }

    #[test]
    fn the_us_character_table_covers_letters_digits_and_punctuation() {
        for c in ('a'..='z').chain('0'..='9') {
            assert!(ansi_keycode(c).is_some(), "{c}");
        }
        for c in "-=[]\\;',./`!@#$%^&*()_+{}|:\"<>?~".chars() {
            assert!(ansi_keycode(c).is_some(), "{c}");
        }
        assert_eq!(ansi_keycode('a'), Some((0x00, false)));
        assert_eq!(ansi_keycode('A'), Some((0x00, true)));
        assert_eq!(ansi_keycode('!'), Some((0x12, true)));
        assert_eq!(ansi_keycode('é'), None);
    }

    fn retina(x: i32, y: i32) -> Monitor {
        Monitor {
            id: 2,
            name: "Display 2".into(),
            primary: false,
            x,
            y,
            width: 1512,
            height: 982,
            scale: 2.0,
            refresh_hz: None,
            orientation: Orientation::Landscape,
        }
    }

    /// Points, fractional, offset by the monitor's place in the layout.
    #[test]
    fn a_position_lands_in_global_points_on_its_own_monitor() {
        let monitor = retina(1920, -100);

        assert_eq!(
            to_global_point(&monitor, PointerPosition { x: 0.0, y: 0.0 }),
            Some((1920.0, -100.0))
        );
        assert_eq!(
            to_global_point(&monitor, PointerPosition { x: 1.0, y: 1.0 }),
            Some((1920.0 + 1511.0, -100.0 + 981.0))
        );

        // Half a point survives: on a Retina display that is a real pixel.
        let (x, _) = to_global_point(&monitor, PointerPosition { x: 0.5, y: 0.5 }).unwrap();
        assert_eq!(x, 1920.0 + 755.5);

        assert_eq!(
            to_global_point(
                &monitor,
                PointerPosition {
                    x: f64::NAN,
                    y: 0.0
                }
            ),
            None
        );
    }

    #[test]
    fn a_move_with_a_button_held_is_a_drag() {
        assert_eq!(HeldButtons::default().move_kind(), MoveKind::Moved);
        assert_eq!(
            HeldButtons {
                left: true,
                ..HeldButtons::default()
            }
            .move_kind(),
            MoveKind::LeftDragged
        );
        assert_eq!(
            HeldButtons {
                right: true,
                ..HeldButtons::default()
            }
            .move_kind(),
            MoveKind::RightDragged
        );
        assert_eq!(
            HeldButtons {
                middle: true,
                ..HeldButtons::default()
            }
            .move_kind(),
            MoveKind::OtherDragged
        );
    }

    #[test]
    fn scrolling_is_negated_into_macos_directions_and_never_lost() {
        assert_eq!(scroll_lines(3.0), -3, "down is negative on macOS");
        assert_eq!(scroll_lines(-2.0), 2);
        assert_eq!(scroll_lines(0.3), -1, "a fraction is still a line");
        assert_eq!(scroll_lines(0.0), 0);
        assert_eq!(scroll_lines(f64::NAN), 0);
        assert_eq!(scroll_lines(1e9), -64);
    }

    #[test]
    fn the_modifier_flags_are_apples() {
        assert_eq!(event_flags(Modifiers::none()), 0);
        assert_eq!(
            event_flags(modifiers(true, true, true, true)),
            FLAG_SHIFT | FLAG_CONTROL | FLAG_OPTION | FLAG_COMMAND
        );
        assert_eq!(FLAG_COMMAND, 0x0010_0000);
    }

    #[test]
    fn held_keys_are_tracked_and_released() {
        let input = MacInput::new();

        input.track_key(0x37, true);
        input.track_key(0x08, true);
        input.track_key(0x37, true);
        assert_eq!(input.held_keys(), vec![0x37, 0x08]);

        input.track_key(0x08, false);
        assert_eq!(input.held_keys(), vec![0x37]);

        input.track_button(MouseButton::Left, true);
        assert!(input.buttons().left);

        assert!(input.release_all().is_ok());
        assert!(input.held_keys().is_empty());
        assert_eq!(input.buttons(), HeldButtons::default());
    }

    /// Off macOS every method refuses rather than silently doing nothing.
    #[cfg(not(target_os = "macos"))]
    #[test]
    fn injection_refuses_off_macos() {
        let input = MacInput::new();

        assert!(input
            .move_pointer(1, PointerPosition { x: 0.5, y: 0.5 })
            .is_err());
        assert!(input.key(press(Key::Enter, Modifiers::none())).is_err());
        assert!(input.seconds_since_input().is_err());
    }
}
