//! What macOS currently lets this Mac do.
//!
//! Two permissions matter, and a person grants each in System Settings →
//! Privacy & Security; an application can grant itself neither, and
//! [`MacPermissions::request`] only asks macOS to show its own prompt and
//! settings.
//!
//! * **Accessibility** — without it every posted `CGEvent` is dropped
//!   silently. Needed for remote control, whether this Mac hosts the session
//!   or is a control companion. Read with `AXIsProcessTrusted`.
//! * **Screen Recording** — without it a capture is not refused but quietly
//!   becomes the wallpaper and the menu bar, every window missing. Needed only
//!   to **host**: a companion's picture comes from its owner's browser share,
//!   so a companion is ready without it, and nothing about being one asks for
//!   it. Read with `CGPreflightScreenCaptureAccess`, which never prompts.
//!
//! Both are read every time the summary is — never cached — so the window,
//! the declaration and the companion's readiness only ever reflect what macOS
//! would actually do now.

use remote_device::{
    PermissionState, PermissionSummary, PermissionsProvider, PlatformError, PlatformResult,
};

/// The permission name the window asks for Accessibility by — the
/// [`PermissionSummary`] field it reports in.
pub const ACCESSIBILITY: &str = "input_injection";

/// The permission name the window asks for Screen Recording by — the
/// [`PermissionSummary`] field it reports in.
pub const SCREEN_RECORDING: &str = "screen_capture";

/// System Settings → Privacy & Security → Screen Recording ("Screen & System
/// Audio Recording" on macOS 15), opened by the URL scheme System Settings
/// registers for its own panes.
pub const SCREEN_RECORDING_SETTINGS: &str =
    "x-apple.systempreferences:com.apple.preference.security?Privacy_ScreenCapture";

/// What a person is told when a session cannot show this Mac's screen
/// because Screen Recording is not granted.
///
/// Worded as the steps, because the person reading it is the one who has to
/// take them, and "permission denied" alone sends them looking in the wrong
/// place. The quit-and-reopen is not optional: macOS applies a new Screen
/// Recording grant to an application the next time it starts.
pub const SCREEN_RECORDING_REFUSAL: &str =
    "macOS has not allowed AICOUNTLY Remote to record this Mac's screen, so the session cannot \
     show it. Turn on AICOUNTLY Remote in System Settings → Privacy & Security → Screen Recording \
     (Screen & System Audio Recording on macOS 15), then quit and reopen AICOUNTLY Remote.";

/// The summary, given whether this process is trusted for Accessibility and
/// whether it may record the screen.
///
/// Pure, so what the Permissions panel shows on a Mac is tested on any host.
#[must_use]
pub fn summary_for(
    accessibility_trusted: bool,
    screen_recording_granted: bool,
) -> PermissionSummary {
    PermissionSummary {
        screen_capture: if screen_recording_granted {
            PermissionState::Ready
        } else {
            PermissionState::NeedsAttention
        },
        input_injection: if accessibility_trusted {
            PermissionState::Ready
        } else {
            PermissionState::NeedsAttention
        },
        // Off on a Mac in this version (the owner's decision), and not
        // implemented here.
        clipboard: PermissionState::Unsupported,
        // No launch daemon: the agent runs while somebody is signed in, which
        // is the only time a Mac can be reached — attended, as a companion,
        // or unattended with nobody at it.
        background_service: PermissionState::NotApplicable,
        power: PermissionState::Unsupported,
    }
}

/// macOS permissions.
#[derive(Debug, Default, Clone, Copy)]
pub struct MacPermissions;

/// Whether this process is trusted for Accessibility right now. `false` off
/// macOS.
#[must_use]
pub fn accessibility_trusted() -> bool {
    #[cfg(target_os = "macos")]
    {
        imp::is_trusted(false)
    }

    #[cfg(not(target_os = "macos"))]
    {
        false
    }
}

/// Whether this process may record the screen right now. Never prompts.
/// `false` off macOS.
#[must_use]
pub fn screen_recording_granted() -> bool {
    #[cfg(target_os = "macos")]
    {
        imp::may_record_screen()
    }

    #[cfg(not(target_os = "macos"))]
    {
        false
    }
}

impl PermissionsProvider for MacPermissions {
    fn summary(&self) -> PlatformResult<PermissionSummary> {
        #[cfg(target_os = "macos")]
        {
            Ok(summary_for(
                accessibility_trusted(),
                screen_recording_granted(),
            ))
        }

        #[cfg(not(target_os = "macos"))]
        {
            Ok(PermissionSummary::all_unsupported())
        }
    }

    fn request(&self, permission: &str) -> PlatformResult<()> {
        match permission {
            ACCESSIBILITY => {
                #[cfg(target_os = "macos")]
                {
                    // Shows macOS's own "would like to control this computer"
                    // dialog, with its button to System Settings, when the
                    // process is not trusted yet. It grants nothing itself.
                    let _ = imp::is_trusted(true);

                    Ok(())
                }

                #[cfg(not(target_os = "macos"))]
                {
                    Err(PlatformError::Unsupported("Requesting Accessibility"))
                }
            }
            SCREEN_RECORDING => {
                #[cfg(target_os = "macos")]
                {
                    if imp::may_record_screen() {
                        return Ok(());
                    }

                    // Two things, because neither is enough alone.
                    //
                    // `CGRequestScreenCaptureAccess` is what puts AICOUNTLY
                    // Remote in System Settings' Screen Recording list at
                    // all — an application that has never asked is not
                    // there, and the person would have to find it with "+" —
                    // and the first time, it shows macOS's own prompt. But
                    // only the first time: once somebody has answered it,
                    // the call returns `false` and shows nothing, so a
                    // button that did only this would do nothing at all for
                    // everybody who once clicked "Deny".
                    //
                    // So the pane is opened as well, which is where the
                    // switch is either way. It grants nothing; the person
                    // does.
                    let _ = imp::ask_to_record_screen();

                    open::that(SCREEN_RECORDING_SETTINGS).map_err(|error| PlatformError::Os {
                        operation: "opening System Settings",
                        detail: error.to_string(),
                    })
                }

                #[cfg(not(target_os = "macos"))]
                {
                    Err(PlatformError::Unsupported("Requesting Screen Recording"))
                }
            }
            _ => Err(PlatformError::Unsupported("Requesting that permission")),
        }
    }
}

#[cfg(target_os = "macos")]
mod imp {
    //! `AXIsProcessTrustedWithOptions`, `CGPreflightScreenCaptureAccess` and
    //! `CGRequestScreenCaptureAccess`. Compiled only on macOS.

    use core_foundation::base::TCFType;
    use core_foundation::boolean::CFBoolean;
    use core_foundation::dictionary::{CFDictionary, CFDictionaryRef};
    use core_foundation::string::{CFString, CFStringRef};

    #[link(name = "ApplicationServices", kind = "framework")]
    extern "C" {
        fn AXIsProcessTrustedWithOptions(options: CFDictionaryRef) -> u8;
        static kAXTrustedCheckOptionPrompt: CFStringRef;
    }

    // Both macOS 10.15 and later — below the macOS 13 this product supports —
    // and both declared in the CoreGraphics headers as returning C `bool`,
    // which Rust's `bool` matches in size, alignment and valid values.
    #[link(name = "CoreGraphics", kind = "framework")]
    extern "C" {
        fn CGPreflightScreenCaptureAccess() -> bool;
        fn CGRequestScreenCaptureAccess() -> bool;
    }

    /// Whether the process is trusted, optionally asking macOS to prompt.
    pub fn is_trusted(prompt: bool) -> bool {
        // SAFETY: the key is a constant CFString exported by the framework,
        // borrowed under the Get rule; the dictionary outlives the call.
        unsafe {
            let key = CFString::wrap_under_get_rule(kAXTrustedCheckOptionPrompt);
            let options = CFDictionary::from_CFType_pairs(&[(key, CFBoolean::from(prompt))]);

            AXIsProcessTrustedWithOptions(options.as_concrete_TypeRef()) != 0
        }
    }

    /// Whether this process may record the screen. Never prompts.
    pub fn may_record_screen() -> bool {
        // SAFETY: takes no arguments and returns a C `bool` by value; it only
        // reads this process's own Screen Recording answer.
        unsafe { CGPreflightScreenCaptureAccess() }
    }

    /// Ask macOS to list this application under Screen Recording and, the
    /// first time only, to show its own prompt. `true` if already granted.
    pub fn ask_to_record_screen() -> bool {
        // SAFETY: takes no arguments and returns a C `bool` by value. Any
        // prompt is macOS's own, shown without blocking this thread, and
        // grants nothing by itself.
        unsafe { CGRequestScreenCaptureAccess() }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use remote_device::AgentCapabilities;

    /// A companion is ready with Accessibility alone; Screen Recording is
    /// never one of its steps.
    #[test]
    fn a_mac_is_ready_to_be_a_companion_with_accessibility_alone() {
        let trusted = summary_for(true, false);
        assert!(trusted.can_be_companion());
        assert_eq!(trusted.screen_capture, PermissionState::NeedsAttention);

        let untrusted = summary_for(false, true);
        assert!(!untrusted.can_be_companion());
        assert_eq!(untrusted.input_injection, PermissionState::NeedsAttention);

        // The companion's declaration follows Accessibility and nothing else.
        let companion = AgentCapabilities::companion_only();
        assert!(trusted.constrain(companion).remote_control);
        assert!(!untrusted.constrain(companion).remote_control);
    }

    /// Hosting needs Screen Recording for the picture, and both for control.
    #[test]
    fn a_mac_hosts_with_screen_recording_and_is_controlled_with_both() {
        let declared = super::super::declared_capabilities();

        let both = summary_for(true, true);
        assert!(both.can_host_session());
        assert!(both.can_be_controlled());
        assert_eq!(both.screen_capture, PermissionState::Ready);
        let allowed = both.constrain(declared);
        assert!(allowed.screen_share);
        assert!(allowed.remote_control);
        assert!(allowed.unattended_access, "while somebody is signed in");
        assert!(!allowed.clipboard_sync, "off on a Mac in this version");
        assert!(!allowed.reboot);

        // The picture without the mouse and keyboard: a session to watch.
        let picture_only = summary_for(false, true);
        assert!(picture_only.can_host_session());
        assert!(!picture_only.can_be_controlled());
        assert!(picture_only.constrain(declared).screen_share);
        assert!(!picture_only.constrain(declared).remote_control);

        // No Screen Recording: nothing to share, whatever else is granted.
        let no_picture = summary_for(true, false);
        assert!(!no_picture.can_host_session());
        assert!(!no_picture.constrain(declared).screen_share);
        assert!(!no_picture.constrain(declared).unattended_access);

        let neither = summary_for(false, false);
        assert!(!neither.can_host_session());
        assert!(!neither.can_be_companion());
    }

    /// What a Mac can never do here reads the same whatever is granted.
    #[test]
    fn the_clipboard_restart_and_service_rows_do_not_depend_on_either_permission() {
        for (accessibility, recording) in
            [(false, false), (true, false), (false, true), (true, true)]
        {
            let summary = summary_for(accessibility, recording);

            assert_eq!(summary.clipboard, PermissionState::Unsupported);
            assert_eq!(summary.power, PermissionState::Unsupported);
            assert_eq!(summary.background_service, PermissionState::NotApplicable);
        }
    }

    /// The names are the summary's own field names, which is what the window
    /// sends.
    #[test]
    fn the_permission_names_are_the_summarys_field_names() {
        let json = serde_json::to_value(summary_for(true, true)).unwrap();

        assert!(json.get(ACCESSIBILITY).is_some());
        assert!(json.get(SCREEN_RECORDING).is_some());
    }

    #[test]
    fn the_refusal_says_where_to_go_and_that_a_restart_is_needed() {
        assert!(SCREEN_RECORDING_REFUSAL.contains("Privacy & Security → Screen Recording"));
        assert!(SCREEN_RECORDING_REFUSAL.contains("Screen & System Audio Recording"));
        assert!(SCREEN_RECORDING_REFUSAL.contains("quit and reopen"));

        assert!(SCREEN_RECORDING_SETTINGS.starts_with("x-apple.systempreferences:"));
        assert!(SCREEN_RECORDING_SETTINGS.ends_with("?Privacy_ScreenCapture"));
    }

    /// Off macOS neither can be requested. (On a Mac these would show real
    /// prompts and open System Settings, so the tests do not ask there.)
    #[cfg(not(target_os = "macos"))]
    #[test]
    fn off_macos_neither_permission_can_be_requested() {
        for permission in [ACCESSIBILITY, SCREEN_RECORDING] {
            assert!(matches!(
                MacPermissions.request(permission),
                Err(PlatformError::Unsupported(_))
            ));
        }

        assert!(!screen_recording_granted());
        assert!(!accessibility_trusted());
    }

    #[test]
    fn nothing_else_can_be_requested() {
        for permission in [
            "power",
            "clipboard",
            "background_service",
            "",
            "SCREEN_CAPTURE",
        ] {
            assert!(matches!(
                MacPermissions.request(permission),
                Err(PlatformError::Unsupported(_))
            ));
        }
    }
}
