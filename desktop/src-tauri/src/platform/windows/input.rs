//! Keyboard and mouse injection, through `SendInput`.
//!
//! # Why `SendInput` and not `mouse_event` / `keybd_event`
//!
//! The older calls are documented as superseded, cannot express a batch
//! atomically, and interleave with real hardware input in ways that produce
//! chords nobody typed. `SendInput` puts a whole sequence into the input
//! stream in one go.
//!
//! # Coordinates
//!
//! `SendInput` with `MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_VIRTUALDESK` takes a
//! position normalised to **0..65535 across the whole virtual desktop**, not
//! pixels on one screen. Getting that conversion wrong is the single most
//! common way remote control lands clicks on the wrong monitor, so it lives in
//! [`to_virtual_desktop`], which is pure arithmetic and tested on every host.
//!
//! # What this cannot reach
//!
//! Input is not delivered to the Secure Desktop — the UAC prompt,
//! Ctrl+Alt+Del, the sign-in screen — because Windows isolates it from every
//! desktop application. Nothing here attempts to work around that. See
//! `capture::SecureDesktopState`, which is how the viewer is told.

use std::sync::Mutex;

use remote_device::{InputProvider, PlatformResult};
use remote_protocol::{Key, KeyEvent, MouseEvent, PointerPosition, ScrollEvent};

/// One notch of a wheel, as Windows counts it.
pub const WHEEL_DELTA: i32 = 120;

/// The absolute coordinate space `SendInput` uses.
pub const ABSOLUTE_RANGE: f64 = 65_535.0;

/// Injection, through `SendInput`.
pub struct WindowsInput {
    /// Keys currently held down by *this* agent.
    ///
    /// Tracked so [`InputProvider::release_all`] can put the keyboard back
    /// exactly as it found it. Without it, a controller whose tab closed
    /// mid-chord leaves Ctrl held on somebody else's machine — which reads, to
    /// the person sitting there, as a broken keyboard.
    held: Mutex<Vec<Key>>,
}

impl Default for WindowsInput {
    fn default() -> Self {
        Self::new()
    }
}

impl WindowsInput {
    /// A provider with nothing held.
    #[must_use]
    pub fn new() -> Self {
        Self { held: Mutex::new(Vec::new()) }
    }

    /// Remember or forget a held key.
    fn track(&self, key: Key, pressed: bool) {
        let Ok(mut held) = self.held.lock() else {
            return;
        };

        if pressed {
            if !held.contains(&key) {
                held.push(key);
            }
        } else {
            held.retain(|candidate| candidate != &key);
        }
    }

    /// What is currently held. For the diagnostics panel and the tests.
    #[must_use]
    pub fn held_keys(&self) -> Vec<Key> {
        self.held.lock().map(|held| held.clone()).unwrap_or_default()
    }
}

/// The virtual-desktop rectangle, in physical pixels.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct VirtualDesktop {
    /// Leftmost x, which is negative when a monitor sits left of the primary.
    pub left: i32,
    /// Topmost y.
    pub top: i32,
    /// Total width across every monitor.
    pub width: u32,
    /// Total height.
    pub height: u32,
}

/// Turn a virtual-desktop pixel into the 0..65535 space `SendInput` wants.
///
/// The arithmetic that decides where a click lands. Pure, and tested on every
/// host, because getting it wrong is silent: Windows accepts any value and
/// puts the pointer somewhere.
#[must_use]
pub fn to_virtual_desktop(desktop: VirtualDesktop, x: i32, y: i32) -> (i32, i32) {
    // A single-pixel desktop is not a thing, but dividing by its width - 1
    // would be, so the denominators are floored at one.
    let width = f64::from(desktop.width.max(2) - 1);
    let height = f64::from(desktop.height.max(2) - 1);

    let normalised_x = f64::from(x - desktop.left) / width;
    let normalised_y = f64::from(y - desktop.top) / height;

    (
        (normalised_x.clamp(0.0, 1.0) * ABSOLUTE_RANGE).round() as i32,
        (normalised_y.clamp(0.0, 1.0) * ABSOLUTE_RANGE).round() as i32,
    )
}

/// The Windows virtual-key code for a protocol key.
///
/// `None` for anything this build will not inject. Returning `None` rather
/// than guessing is the point: approximating a keystroke on somebody else's
/// machine is how a remote-control product presses the wrong thing.
#[must_use]
pub fn virtual_key(key: Key) -> Option<u16> {
    // The constants, written out rather than pulled from the `windows` crate,
    // so the mapping is reviewable and testable on any host. Each is from
    // Microsoft's Virtual-Key Codes list.
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
        // A printable character is injected as a **Unicode** event rather than
        // as a virtual key, so the character the controller intends is the one
        // that arrives whatever keyboard layout the host is using. A French
        // controller typing 'a' on a US host produces 'a'.
        Key::Character(_) => return None,
    })
}

/// Whether a key needs `KEYEVENTF_EXTENDEDKEY`.
///
/// The navigation cluster and the right-hand modifiers share virtual-key codes
/// with the numeric keypad. Without the extended flag, Home sends Numpad-7 —
/// which works until somebody has Num Lock on.
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

/// Wheel notches, as Windows counts them.
#[must_use]
pub fn wheel_delta(notches: f64) -> i32 {
    (notches * f64::from(WHEEL_DELTA)).round() as i32
}

impl InputProvider for WindowsInput {
    fn move_pointer(&self, monitor_id: u32, position: PointerPosition) -> PlatformResult<()> {
        #[cfg(target_os = "windows")]
        {
            imp::move_pointer(monitor_id, position)
        }

        #[cfg(not(target_os = "windows"))]
        {
            let _ = (monitor_id, position);

            Err(PlatformError::Unsupported("Remote control"))
        }
    }

    fn move_pointer_relative(&self, dx: f64, dy: f64) -> PlatformResult<()> {
        #[cfg(target_os = "windows")]
        {
            imp::move_pointer_relative(dx, dy)
        }

        #[cfg(not(target_os = "windows"))]
        {
            let _ = (dx, dy);

            Err(PlatformError::Unsupported("Remote control"))
        }
    }

    fn mouse_button(&self, monitor_id: u32, event: MouseEvent) -> PlatformResult<()> {
        #[cfg(target_os = "windows")]
        {
            imp::mouse_button(monitor_id, event)
        }

        #[cfg(not(target_os = "windows"))]
        {
            let _ = (monitor_id, event);

            Err(PlatformError::Unsupported("Remote control"))
        }
    }

    fn scroll(&self, monitor_id: u32, event: ScrollEvent) -> PlatformResult<()> {
        #[cfg(target_os = "windows")]
        {
            imp::scroll(monitor_id, event)
        }

        #[cfg(not(target_os = "windows"))]
        {
            let _ = (monitor_id, event);

            Err(PlatformError::Unsupported("Remote control"))
        }
    }

    fn key(&self, event: KeyEvent) -> PlatformResult<()> {
        #[cfg(target_os = "windows")]
        {
            imp::key(event)?;
            self.track(event.key, event.pressed);

            Ok(())
        }

        #[cfg(not(target_os = "windows"))]
        {
            let _ = event;

            Err(PlatformError::Unsupported("Remote control"))
        }
    }

    fn release_all(&self) -> PlatformResult<()> {
        let held = self.held_keys();

        if let Ok(mut tracked) = self.held.lock() {
            tracked.clear();
        }

        #[cfg(target_os = "windows")]
        {
            for key in held {
                // Best effort, and deliberately so: one key that will not
                // release must not stop the rest from being released.
                let _ = imp::key(KeyEvent {
                    key,
                    pressed: false,
                    modifiers: remote_protocol::Modifiers::none(),
                });
            }
        }

        #[cfg(not(target_os = "windows"))]
        {
            let _ = held;
        }

        Ok(())
    }
}

#[cfg(target_os = "windows")]
mod imp {
    //! The `SendInput` half. Compiled only on Windows.

    use super::{is_extended, to_virtual_desktop, virtual_key, wheel_delta, VirtualDesktop};
    use remote_device::{PlatformError, PlatformResult};
    use remote_protocol::{Key, KeyEvent, MouseButton, MouseEvent, PointerPosition, ScrollEvent};
    use windows::Win32::UI::Input::KeyboardAndMouse::{
        SendInput, INPUT, INPUT_0, INPUT_KEYBOARD, INPUT_MOUSE, KEYBDINPUT, KEYBD_EVENT_FLAGS,
        KEYEVENTF_EXTENDEDKEY, KEYEVENTF_KEYUP, KEYEVENTF_UNICODE, MOUSEEVENTF_ABSOLUTE,
        MOUSEEVENTF_HWHEEL, MOUSEEVENTF_LEFTDOWN, MOUSEEVENTF_LEFTUP, MOUSEEVENTF_MIDDLEDOWN,
        MOUSEEVENTF_MIDDLEUP, MOUSEEVENTF_MOVE, MOUSEEVENTF_RIGHTDOWN, MOUSEEVENTF_RIGHTUP,
        MOUSEEVENTF_VIRTUALDESK, MOUSEEVENTF_WHEEL, MOUSEINPUT, MOUSE_EVENT_FLAGS,
    };
    use windows::Win32::UI::WindowsAndMessaging::{
        GetSystemMetrics, SM_CXVIRTUALSCREEN, SM_CYVIRTUALSCREEN, SM_XVIRTUALSCREEN,
        SM_YVIRTUALSCREEN,
    };

    /// The virtual desktop as Windows currently reports it.
    ///
    /// Read on every injection rather than cached: a monitor unplugged
    /// mid-session changes it, and a cached rectangle would put every
    /// subsequent click in the wrong place.
    fn virtual_desktop() -> VirtualDesktop {
        // SAFETY: GetSystemMetrics takes no pointers and cannot fail.
        unsafe {
            VirtualDesktop {
                left: GetSystemMetrics(SM_XVIRTUALSCREEN),
                top: GetSystemMetrics(SM_YVIRTUALSCREEN),
                width: GetSystemMetrics(SM_CXVIRTUALSCREEN).max(1) as u32,
                height: GetSystemMetrics(SM_CYVIRTUALSCREEN).max(1) as u32,
            }
        }
    }

    /// Where a normalised position on one monitor lands on the virtual desktop.
    fn resolve(monitor_id: u32, position: PointerPosition) -> PlatformResult<(i32, i32)> {
        // The trait has to be in scope for `monitors()`; it is the same
        // provider the capture side uses, so the coordinates a click is
        // resolved against are the ones the viewer was looking at.
        use remote_device::ScreenCaptureProvider;

        let layout = super::super::capture::WindowsCapture::new().monitors()?;

        let monitor = layout
            .find(monitor_id)
            .or_else(|| layout.active())
            .ok_or(PlatformError::NotFound("That display"))?;

        monitor
            .denormalise(position)
            .ok_or(PlatformError::Os {
                operation: "moving the pointer",
                detail: "the position was not usable".into(),
            })
    }

    fn send(inputs: &[INPUT]) -> PlatformResult<()> {
        // SAFETY: `inputs` is a live slice of correctly initialised INPUT
        // structures and the size argument matches the type exactly.
        let sent = unsafe { SendInput(inputs, std::mem::size_of::<INPUT>() as i32) };

        if sent as usize != inputs.len() {
            return Err(PlatformError::Os {
                operation: "sending input",
                // Almost always UIPI: a process at a lower integrity level
                // cannot inject into a higher-integrity window. Saying so is
                // more useful than the raw code.
                detail: "Windows refused the input, usually because the window in front runs with higher privileges".into(),
            });
        }

        Ok(())
    }

    fn mouse_input(flags: MOUSE_EVENT_FLAGS, x: i32, y: i32, data: i32) -> INPUT {
        INPUT {
            r#type: INPUT_MOUSE,
            Anonymous: INPUT_0 {
                mi: MOUSEINPUT {
                    dx: x,
                    dy: y,
                    // `mouseData` is unsigned; a negative scroll notch is the
                    // same bit pattern, which is what Windows reads it as.
                    mouseData: data as u32,
                    dwFlags: flags,
                    time: 0,
                    dwExtraInfo: 0,
                },
            },
        }
    }

    pub fn move_pointer(monitor_id: u32, position: PointerPosition) -> PlatformResult<()> {
        let (x, y) = resolve(monitor_id, position)?;
        let (absolute_x, absolute_y) = to_virtual_desktop(virtual_desktop(), x, y);

        send(&[mouse_input(
            MOUSEEVENTF_MOVE | MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_VIRTUALDESK,
            absolute_x,
            absolute_y,
            0,
        )])
    }

    pub fn move_pointer_relative(dx: f64, dy: f64) -> PlatformResult<()> {
        let desktop = virtual_desktop();

        send(&[mouse_input(
            MOUSEEVENTF_MOVE,
            (dx * f64::from(desktop.width)).round() as i32,
            (dy * f64::from(desktop.height)).round() as i32,
            0,
        )])
    }

    pub fn mouse_button(monitor_id: u32, event: MouseEvent) -> PlatformResult<()> {
        let mut inputs = Vec::with_capacity(5);

        if let Some(position) = event.position {
            let (x, y) = resolve(monitor_id, position)?;
            let (absolute_x, absolute_y) = to_virtual_desktop(virtual_desktop(), x, y);

            inputs.push(mouse_input(
                MOUSEEVENTF_MOVE | MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_VIRTUALDESK,
                absolute_x,
                absolute_y,
                0,
            ));
        }

        let (down, up) = match event.button {
            MouseButton::Left => (MOUSEEVENTF_LEFTDOWN, MOUSEEVENTF_LEFTUP),
            MouseButton::Right => (MOUSEEVENTF_RIGHTDOWN, MOUSEEVENTF_RIGHTUP),
            MouseButton::Middle => (MOUSEEVENTF_MIDDLEDOWN, MOUSEEVENTF_MIDDLEUP),
        };

        if event.double {
            // Two complete pairs in one batch. Sent together so the interval
            // between them is the input stack's, not the network's — a double
            // click split across two data-channel messages arrives as two
            // single clicks over any real link.
            inputs.push(mouse_input(down, 0, 0, 0));
            inputs.push(mouse_input(up, 0, 0, 0));
            inputs.push(mouse_input(down, 0, 0, 0));
            inputs.push(mouse_input(up, 0, 0, 0));
        } else {
            inputs.push(mouse_input(if event.pressed { down } else { up }, 0, 0, 0));
        }

        send(&inputs)
    }

    pub fn scroll(monitor_id: u32, event: ScrollEvent) -> PlatformResult<()> {
        let mut inputs = Vec::with_capacity(3);

        if let Some(position) = event.position {
            let (x, y) = resolve(monitor_id, position)?;
            let (absolute_x, absolute_y) = to_virtual_desktop(virtual_desktop(), x, y);

            inputs.push(mouse_input(
                MOUSEEVENTF_MOVE | MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_VIRTUALDESK,
                absolute_x,
                absolute_y,
                0,
            ));
        }

        if event.delta_y != 0.0 {
            // Windows counts a wheel turn away from the user as positive; the
            // protocol counts down as positive, matching every platform's own
            // scroll direction. Hence the negation.
            inputs.push(mouse_input(MOUSEEVENTF_WHEEL, 0, 0, -wheel_delta(event.delta_y)));
        }

        if event.delta_x != 0.0 {
            inputs.push(mouse_input(MOUSEEVENTF_HWHEEL, 0, 0, wheel_delta(event.delta_x)));
        }

        if inputs.is_empty() {
            return Ok(());
        }

        send(&inputs)
    }

    pub fn key(event: KeyEvent) -> PlatformResult<()> {
        let mut flags = KEYBD_EVENT_FLAGS(0);

        if !event.pressed {
            flags |= KEYEVENTF_KEYUP;
        }

        let (virtual_code, scan_code) = match event.key {
            Key::Character(character) => {
                // Unicode injection: the character the controller intends is
                // the one that arrives, whatever layout the host is using.
                let mut units = [0_u16; 2];
                let encoded = character.encode_utf16(&mut units);

                let mut inputs = Vec::with_capacity(encoded.len());
                for unit in encoded.iter() {
                    inputs.push(INPUT {
                        r#type: INPUT_KEYBOARD,
                        Anonymous: INPUT_0 {
                            ki: KEYBDINPUT {
                                wVk: windows::Win32::UI::Input::KeyboardAndMouse::VIRTUAL_KEY(0),
                                wScan: *unit,
                                dwFlags: flags | KEYEVENTF_UNICODE,
                                time: 0,
                                dwExtraInfo: 0,
                            },
                        },
                    });
                }

                return send(&inputs);
            }
            other => (
                virtual_key(other).ok_or(PlatformError::Os {
                    operation: "sending a key",
                    detail: "that key is not one AICOUNTLY Remote sends".into(),
                })?,
                0_u16,
            ),
        };

        if is_extended(event.key) {
            flags |= KEYEVENTF_EXTENDEDKEY;
        }

        send(&[INPUT {
            r#type: INPUT_KEYBOARD,
            Anonymous: INPUT_0 {
                ki: KEYBDINPUT {
                    wVk: windows::Win32::UI::Input::KeyboardAndMouse::VIRTUAL_KEY(virtual_code),
                    wScan: scan_code,
                    dwFlags: flags,
                    time: 0,
                    dwExtraInfo: 0,
                },
            },
        }])
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    // Used only by the no-implementation test below, which is the one that
    // does not compile on Windows.
    #[cfg(not(target_os = "windows"))]
    use remote_protocol::Modifiers;

    fn desktop() -> VirtualDesktop {
        VirtualDesktop { left: 0, top: 0, width: 1920, height: 1080 }
    }

    /// The arithmetic that decides where a click lands. Windows accepts any
    /// value, so getting this wrong is silent.
    #[test]
    fn a_pixel_maps_into_the_absolute_coordinate_space() {
        assert_eq!(to_virtual_desktop(desktop(), 0, 0), (0, 0));
        assert_eq!(to_virtual_desktop(desktop(), 1919, 1079), (65_535, 65_535));

        let (x, y) = to_virtual_desktop(desktop(), 960, 540);
        assert!((32_000..34_000).contains(&x), "centre x was {x}");
        assert!((32_000..34_000).contains(&y), "centre y was {y}");
    }

    /// A monitor left of the primary has a negative origin. Ignoring it is the
    /// most common way remote control clicks the wrong screen.
    #[test]
    fn a_negative_virtual_desktop_origin_is_handled() {
        let two_screens = VirtualDesktop { left: -1920, top: 0, width: 3840, height: 1080 };

        // The far left of the left-hand monitor.
        assert_eq!(to_virtual_desktop(two_screens, -1920, 0), (0, 0));
        // The join between them, halfway across.
        let (x, _) = to_virtual_desktop(two_screens, 0, 0);
        assert!((32_000..33_600).contains(&x), "the join mapped to {x}");
        // The far right of the right-hand monitor.
        assert_eq!(to_virtual_desktop(two_screens, 1919, 1079), (65_535, 65_535));
    }

    #[test]
    fn a_coordinate_outside_the_desktop_is_clamped_rather_than_wrapping() {
        assert_eq!(to_virtual_desktop(desktop(), -5000, -5000), (0, 0));
        assert_eq!(to_virtual_desktop(desktop(), 99_999, 99_999), (65_535, 65_535));
    }

    #[test]
    fn a_degenerate_desktop_does_not_divide_by_zero() {
        let tiny = VirtualDesktop { left: 0, top: 0, width: 1, height: 1 };

        let (x, y) = to_virtual_desktop(tiny, 0, 0);
        assert_eq!((x, y), (0, 0));
    }

    #[test]
    fn every_named_key_has_a_virtual_key_code() {
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
            Key::PrintScreen,
        ] {
            assert!(virtual_key(key).is_some(), "{key:?} has no code");
        }

        for n in 1..=24 {
            assert!(virtual_key(Key::Function(n)).is_some(), "F{n} has no code");
        }
    }

    #[test]
    fn the_function_key_codes_are_contiguous_from_vk_f1() {
        assert_eq!(virtual_key(Key::Function(1)), Some(0x70));
        assert_eq!(virtual_key(Key::Function(12)), Some(0x7B));
        assert_eq!(virtual_key(Key::Function(24)), Some(0x87));
        assert_eq!(virtual_key(Key::Function(0)), None);
        assert_eq!(virtual_key(Key::Function(25)), None);
    }

    /// A character is injected as Unicode, not as a virtual key, so the
    /// character the controller intends arrives whatever layout the host uses.
    #[test]
    fn a_printable_character_has_no_virtual_key_because_it_goes_as_unicode() {
        assert_eq!(virtual_key(Key::Character('a')), None);
        assert_eq!(virtual_key(Key::Character('€')), None);
    }

    /// Without the extended flag, Home sends Numpad-7 — which works until
    /// somebody has Num Lock on.
    #[test]
    fn the_navigation_cluster_is_marked_extended() {
        for key in [
            Key::Home,
            Key::End,
            Key::Insert,
            Key::Delete,
            Key::PageUp,
            Key::PageDown,
            Key::ArrowUp,
            Key::ArrowDown,
            Key::ArrowLeft,
            Key::ArrowRight,
        ] {
            assert!(is_extended(key), "{key:?} must be extended");
        }

        assert!(!is_extended(Key::Enter));
        assert!(!is_extended(Key::Character('a')));
        assert!(!is_extended(Key::Function(1)));
    }

    #[test]
    fn wheel_notches_become_windows_wheel_deltas() {
        assert_eq!(wheel_delta(1.0), 120);
        assert_eq!(wheel_delta(-3.0), -360);
        assert_eq!(wheel_delta(0.5), 60);
        assert_eq!(wheel_delta(0.0), 0);
    }

    /// A controller whose tab closed mid-chord leaves Ctrl held on somebody
    /// else's machine, which reads as a broken keyboard.
    #[test]
    fn held_keys_are_tracked_so_they_can_all_be_released() {
        let input = WindowsInput::new();

        input.track(Key::Ctrl, true);
        input.track(Key::Shift, true);
        input.track(Key::Character('a'), true);

        assert_eq!(input.held_keys().len(), 3);

        input.track(Key::Character('a'), false);
        assert_eq!(input.held_keys().len(), 2);

        // Pressing a key that is already down does not double-count it.
        input.track(Key::Ctrl, true);
        assert_eq!(input.held_keys().len(), 2);

        assert!(input.release_all().is_ok());
        assert!(input.held_keys().is_empty());
    }

    #[test]
    fn releasing_when_nothing_is_held_succeeds() {
        let input = WindowsInput::new();

        assert!(input.release_all().is_ok());
        assert!(input.release_all().is_ok());
    }

    /// On a host with no implementation, every method refuses rather than
    /// silently doing nothing — a silent no-op would look like control that
    /// works and does not.
    #[cfg(not(target_os = "windows"))]
    #[test]
    fn injection_refuses_on_a_platform_that_cannot_do_it() {
        let input = WindowsInput::new();

        assert!(input
            .move_pointer(1, PointerPosition { x: 0.5, y: 0.5 })
            .is_err());
        assert!(input
            .key(KeyEvent { key: Key::Enter, pressed: true, modifiers: Modifiers::none() })
            .is_err());
        assert!(input
            .mouse_button(
                1,
                MouseEvent {
                    button: MouseButton::Left,
                    pressed: true,
                    double: false,
                    position: None
                }
            )
            .is_err());
    }
}
