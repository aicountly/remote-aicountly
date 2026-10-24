//! macOS: the **control companion**.
//!
//! A Mac does not host a session of its own here — there is no screen capture
//! and no encoder. What it does is the thing a browser cannot: its owner
//! shares a screen from Chrome or Edge as usual, links this Mac to that share,
//! and somebody they grant control to in the browser moves this Mac's mouse
//! and types on its keyboard. The picture comes from the browser; the input
//! comes through here, through the same `ControlGate` as on Windows. See
//! `docs/desktop/CONTROL_COMPANION.md` for the whole flow.
//!
//! | Trait | macOS | Notes |
//! |---|---|---|
//! | `ScreenCaptureProvider` | [`display::MacDisplays`] | The display list only (`CGGetActiveDisplayList`, `CGDisplayBounds`) — no Screen Recording. Capture is refused. |
//! | `InputProvider` | [`input::MacInput`] | `CGEvent` from a private event source, posted at the HID tap. Needs Accessibility. |
//! | `PermissionsProvider` | [`permissions::MacPermissions`] | `AXIsProcessTrustedWithOptions`, checked on every read. |
//! | `DeviceInfoProvider` | [`device::MacDeviceInfo`] | Computer name, `kern.osproductversion`; macOS 13 and later. |
//! | `SecureStorageProvider` | [`storage::KeychainStorage`] | A generic-password item, after first unlock, this device only. |
//! | `ClipboardProvider` | `Unsupported` | Off for companions in this version. |
//! | `SystemServiceProvider` | `Unsupported` | No launch daemon: never unattended. |
//! | `PowerProvider` | `Unsupported` | A companion cannot restart the Mac. |
//!
//! # Compiled and tested on every host
//!
//! Like `platform::windows`, this module is **not** gated on the target. Each
//! file keeps its native calls in an inner `#[cfg(target_os = "macos")] mod
//! imp`, and everything around them — the key table, the coordinate
//! arithmetic, the display description, the permission summary, the keychain
//! fallback rule — is plain Rust tested on Linux CI. Off macOS the providers
//! refuse with [`remote_device::PlatformError::Unsupported`], which is also
//! what a Linux developer build of the agent uses.

use remote_device::{
    ClipboardProvider, PlatformProviders, PlatformResult, PowerProvider, SystemServiceProvider,
};
use remote_protocol::ClipboardPayload;

use super::unsupported;

pub mod device;
pub mod display;
pub mod input;
pub mod permissions;
pub mod storage;

pub use device::MacDeviceInfo;

/// Assemble the macOS providers. Off macOS every native one refuses.
pub fn providers() -> PlatformResult<PlatformProviders> {
    Ok(PlatformProviders {
        capture: Box::new(display::MacDisplays),
        input: Box::new(input::MacInput::new()),
        clipboard: Box::new(NoClipboard),
        service: Box::new(NoService),
        device_info: Box::new(MacDeviceInfo),
        power: Box::new(NoPower),
        permissions: Box::new(permissions::MacPermissions),
    })
}

/// The clipboard: off for companions in this version.
pub struct NoClipboard;

impl ClipboardProvider for NoClipboard {
    fn read_text(&self) -> PlatformResult<Option<String>> {
        unsupported("Clipboard sharing")
    }

    fn write_text(&self, _text: &str) -> PlatformResult<()> {
        unsupported("Clipboard sharing")
    }

    fn apply(&self, _payload: &ClipboardPayload) -> PlatformResult<()> {
        unsupported("Clipboard sharing")
    }
}

/// No background daemon: a companion runs while its owner is signed in.
pub struct NoService;

impl SystemServiceProvider for NoService {
    fn is_installed(&self) -> PlatformResult<bool> {
        Ok(false)
    }

    fn is_running(&self) -> PlatformResult<bool> {
        Ok(false)
    }

    fn install(&self) -> PlatformResult<()> {
        unsupported("The background service")
    }

    fn uninstall(&self) -> PlatformResult<()> {
        unsupported("The background service")
    }

    fn start(&self) -> PlatformResult<()> {
        unsupported("The background service")
    }

    fn stop(&self) -> PlatformResult<()> {
        unsupported("The background service")
    }

    fn service_version(&self) -> PlatformResult<Option<String>> {
        Ok(None)
    }
}

/// Restarting: not something a companion can do.
pub struct NoPower;

impl PowerProvider for NoPower {
    fn reboot(&self, _reason: &str) -> PlatformResult<()> {
        unsupported("Restarting this computer")
    }

    fn can_reboot(&self) -> PlatformResult<bool> {
        // Answered rather than refused: the UI asks this to decide whether to
        // *offer* a restart, and `false` is the honest answer.
        Ok(false)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use remote_device::PlatformError;
    use remote_protocol::PointerPosition;

    #[test]
    fn what_a_companion_does_not_do_refuses_with_a_reason_a_person_can_read() {
        let mut providers = providers().expect("assembles");

        let error = providers
            .capture
            .start(1, remote_device::CaptureProfile::adaptive())
            .unwrap_err();
        assert!(matches!(error, PlatformError::Unsupported(_)));
        assert!(error
            .to_string()
            .contains("not available on this platform yet"));

        assert!(providers.clipboard.read_text().is_err());
        assert!(providers.power.reboot("test").is_err());
    }

    /// Off macOS the native providers refuse too, rather than pretending.
    #[cfg(not(target_os = "macos"))]
    #[test]
    fn off_macos_every_native_provider_refuses() {
        let providers = providers().expect("assembles");

        assert!(providers.capture.monitors().is_err());
        assert!(providers
            .input
            .move_pointer(1, PointerPosition { x: 0.5, y: 0.5 })
            .is_err());
        assert_eq!(
            providers.permissions.summary().unwrap(),
            remote_device::PermissionSummary::all_unsupported()
        );
        assert_eq!(providers.device_info.is_supported_platform(), Ok(false));
    }

    /// A teardown path must not fail on a platform where nothing was running.
    #[test]
    fn tearing_down_succeeds_even_though_nothing_ever_started() {
        let mut providers = providers().expect("assembles");

        assert!(providers.capture.stop().is_ok());
        assert!(providers.input.release_all().is_ok());
        assert!(!providers.capture.is_running());
        let _ = PointerPosition { x: 0.0, y: 0.0 };
    }

    /// The UI asks these to decide whether to *offer* something, so they
    /// answer honestly rather than refusing.
    #[test]
    fn the_questions_the_ui_asks_before_offering_a_button_are_answered() {
        let providers = providers().expect("assembles");

        assert_eq!(providers.power.can_reboot(), Ok(false));
        assert_eq!(providers.service.is_installed(), Ok(false));
        assert_eq!(providers.service.is_running(), Ok(false));
    }
}
