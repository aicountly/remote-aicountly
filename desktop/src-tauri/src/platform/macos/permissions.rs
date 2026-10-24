//! What macOS currently lets the companion do.
//!
//! One permission matters: **Accessibility**, without which every posted
//! `CGEvent` is dropped silently. It is checked with `AXIsProcessTrusted`
//! every time the summary is read — never cached — so the companion only
//! reports itself ready (and only declares `remote_control`) while macOS
//! would actually deliver its input. A person grants it in System Settings →
//! Privacy & Security → Accessibility; an application cannot grant itself it,
//! and [`MacPermissions::request`] only opens macOS's own prompt.
//!
//! **Screen Recording is not needed and never asked for**: a companion's
//! picture comes from its owner's browser share. It is reported as
//! [`PermissionState::NotApplicable`] for that reason, not as "ready".

use remote_device::{
    PermissionState, PermissionSummary, PermissionsProvider, PlatformError, PlatformResult,
};

/// The permission name the window asks for.
pub const ACCESSIBILITY: &str = "input_injection";

/// The summary, given whether this process is trusted for Accessibility.
///
/// Pure, so what the Permissions panel shows on a Mac is tested on any host.
#[must_use]
pub fn summary_for(accessibility_trusted: bool) -> PermissionSummary {
    PermissionSummary {
        screen_capture: PermissionState::NotApplicable,
        input_injection: if accessibility_trusted {
            PermissionState::Ready
        } else {
            PermissionState::NeedsAttention
        },
        // Off for companions in this version (the owner's decision), and not
        // implemented here.
        clipboard: PermissionState::Unsupported,
        // No launch daemon: the companion runs while its owner is signed in,
        // which is the only time they can be sharing a screen anyway.
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

impl PermissionsProvider for MacPermissions {
    fn summary(&self) -> PlatformResult<PermissionSummary> {
        #[cfg(target_os = "macos")]
        {
            Ok(summary_for(accessibility_trusted()))
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
            _ => Err(PlatformError::Unsupported("Requesting that permission")),
        }
    }
}

#[cfg(target_os = "macos")]
mod imp {
    //! `AXIsProcessTrustedWithOptions`. Compiled only on macOS.

    use core_foundation::base::TCFType;
    use core_foundation::boolean::CFBoolean;
    use core_foundation::dictionary::{CFDictionary, CFDictionaryRef};
    use core_foundation::string::{CFString, CFStringRef};

    #[link(name = "ApplicationServices", kind = "framework")]
    extern "C" {
        fn AXIsProcessTrustedWithOptions(options: CFDictionaryRef) -> u8;
        static kAXTrustedCheckOptionPrompt: CFStringRef;
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
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Ready only with Accessibility; Screen Recording is never a step.
    #[test]
    fn a_mac_is_ready_to_be_a_companion_only_with_accessibility() {
        let trusted = summary_for(true);
        assert!(trusted.can_be_companion());
        assert_eq!(trusted.screen_capture, PermissionState::NotApplicable);

        let untrusted = summary_for(false);
        assert!(!untrusted.can_be_companion());
        assert_eq!(untrusted.input_injection, PermissionState::NeedsAttention);

        // The declaration follows: no Accessibility, no remote_control.
        let companion = remote_device::AgentCapabilities::companion_only();
        assert!(trusted.constrain(companion).remote_control);
        assert!(!untrusted.constrain(companion).remote_control);
    }

    #[test]
    fn only_accessibility_can_be_requested() {
        assert!(MacPermissions.request("screen_capture").is_err());
        assert!(MacPermissions.request("power").is_err());
    }
}
