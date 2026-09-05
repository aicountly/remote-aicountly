//! macOS. **Not implemented, and it says so.**
//!
//! Every provider here returns [`PlatformError::Unsupported`]. There is no
//! simulated capture, no pretend input injection, and no capability declared
//! that the machine cannot deliver.
//!
//! That is a deliberate choice rather than an omission. A macOS agent that
//! enrolled with `remote_control: true` would appear in an administrator's
//! device list as a machine they can connect to and control — and the first
//! time somebody tried, in the middle of helping a colleague, it would fail.
//! An honest "not available on macOS yet" costs nothing and misleads nobody.
//!
//! # What a macOS implementation will need
//!
//! The work is enumerated rather than hidden, so that adding it is filling in
//! this file and touching nothing above it:
//!
//! | Trait | macOS API | The part that needs care |
//! |---|---|---|
//! | `ScreenCaptureProvider` | `ScreenCaptureKit` | Screen Recording is a TCC permission the user grants in System Settings; it cannot be requested silently, and the app must be relaunched after it is granted. |
//! | `InputProvider` | `CGEvent` | Accessibility is a separate TCC permission. Posting an event without it fails silently, which is the worst possible failure mode — so `summary()` has to report it before control is offered. |
//! | `ClipboardProvider` | `NSPasteboard` | Straightforward; the change count is what tells you it moved. |
//! | `SecureStorageProvider` | Keychain | A `kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly` item, so the helper can read it after a reboot without anybody signing in. |
//! | `SystemServiceProvider` | `launchd` | A `LaunchDaemon` plist, not a `LaunchAgent` — the machine has to be reachable with nobody signed in. |
//! | `DeviceInfoProvider` | `sysctl`, `SCDynamicStore` | The computer name is the one a person recognises, not the hostname. |
//! | `PowerProvider` | `AEDeterminePermissionToAutomateTarget` | Restarting needs Apple Events consent, which is a third TCC permission. |
//!
//! Everything else — the protocol, the gate, the state machine, the API
//! client, the WebRTC session, the whole interface — is already shared.

use remote_device::{
    ClipboardProvider, DeviceInfoProvider, InputProvider, PermissionSummary, PermissionsProvider,
    PlatformProviders, PlatformResult, PowerProvider, ScreenCaptureProvider, SystemServiceProvider,
};
use remote_protocol::{
    ClipboardPayload, KeyEvent, MonitorLayout, MouseEvent, PointerPosition, ScrollEvent,
};

use super::{unsupported, unsupported_permissions};

/// Assemble the providers for a platform that has none.
pub fn providers() -> PlatformResult<PlatformProviders> {
    Ok(PlatformProviders {
        capture: Box::new(NoCapture),
        input: Box::new(NoInput),
        clipboard: Box::new(NoClipboard),
        service: Box::new(NoService),
        device_info: Box::new(MacDeviceInfo),
        power: Box::new(NoPower),
        permissions: Box::new(NoPermissions),
    })
}

/// Screen capture, once `ScreenCaptureKit` is wired up.
pub struct NoCapture;

impl ScreenCaptureProvider for NoCapture {
    fn monitors(&self) -> PlatformResult<MonitorLayout> {
        unsupported("Reading the display layout")
    }

    fn start(&mut self, _monitor_id: u32, _profile: remote_device::CaptureProfile) -> PlatformResult<()> {
        unsupported("Screen capture")
    }

    fn stop(&mut self) -> PlatformResult<()> {
        // Stopping something that was never started succeeds — a teardown path
        // must not fail on a platform where nothing was running.
        Ok(())
    }

    fn next_frame(&mut self) -> PlatformResult<Option<remote_device::Frame>> {
        unsupported("Screen capture")
    }

    fn reconfigure(&mut self, _profile: remote_device::CaptureProfile) -> PlatformResult<()> {
        unsupported("Screen capture")
    }

    fn is_running(&self) -> bool {
        false
    }
}

/// Input injection, once `CGEvent` is wired up.
pub struct NoInput;

impl InputProvider for NoInput {
    fn move_pointer(&self, _monitor_id: u32, _position: PointerPosition) -> PlatformResult<()> {
        unsupported("Remote control")
    }

    fn move_pointer_relative(&self, _dx: f64, _dy: f64) -> PlatformResult<()> {
        unsupported("Remote control")
    }

    fn mouse_button(&self, _monitor_id: u32, _event: MouseEvent) -> PlatformResult<()> {
        unsupported("Remote control")
    }

    fn scroll(&self, _monitor_id: u32, _event: ScrollEvent) -> PlatformResult<()> {
        unsupported("Remote control")
    }

    fn key(&self, _event: KeyEvent) -> PlatformResult<()> {
        unsupported("Remote control")
    }

    fn release_all(&self) -> PlatformResult<()> {
        // Nothing was ever pressed, so there is nothing to release. Succeeding
        // matters: this is called on every teardown, including a failed one.
        Ok(())
    }
}

/// The clipboard, once `NSPasteboard` is wired up.
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

/// The background daemon, once the `launchd` plist exists.
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

/// Restarting, once Apple Events consent is handled.
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

/// Native permissions, once TCC is handled.
pub struct NoPermissions;

impl PermissionsProvider for NoPermissions {
    fn summary(&self) -> PlatformResult<PermissionSummary> {
        Ok(unsupported_permissions())
    }

    fn request(&self, _permission: &str) -> PlatformResult<()> {
        unsupported("Requesting a permission")
    }
}

/// What the machine is.
///
/// The one provider that answers rather than refusing, because the About panel
/// and the "not supported" screen both need to say which operating system this
/// is — and refusing to name it would make that screen say nothing useful.
pub struct MacDeviceInfo;

impl DeviceInfoProvider for MacDeviceInfo {
    fn host_name(&self) -> PlatformResult<String> {
        unsupported("Reading the machine name")
    }

    fn operating_system(&self) -> &'static str {
        #[cfg(target_os = "macos")]
        {
            "macOS"
        }

        #[cfg(not(target_os = "macos"))]
        {
            "unsupported"
        }
    }

    fn os_version(&self) -> PlatformResult<String> {
        unsupported("Reading the operating system version")
    }

    fn architecture(&self) -> &'static str {
        std::env::consts::ARCH
    }

    fn is_supported_platform(&self) -> PlatformResult<bool> {
        Ok(false)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use remote_device::PlatformError;

    #[test]
    fn every_capability_refuses_with_a_reason_a_person_can_read() {
        let providers = providers().expect("assembles");

        let error = providers.capture.monitors().unwrap_err();
        assert!(matches!(error, PlatformError::Unsupported(_)));
        assert!(error.to_string().contains("not available on this platform yet"));

        assert!(providers
            .input
            .move_pointer(1, PointerPosition { x: 0.5, y: 0.5 })
            .is_err());
        assert!(providers.clipboard.read_text().is_err());
        assert!(providers.power.reboot("test").is_err());
    }

    /// A teardown path must not fail on a platform where nothing was running.
    #[test]
    fn tearing_down_succeeds_even_though_nothing_ever_started() {
        let mut providers = providers().expect("assembles");

        assert!(providers.capture.stop().is_ok());
        assert!(providers.input.release_all().is_ok());
        assert!(!providers.capture.is_running());
    }

    /// The UI asks these to decide whether to *offer* something, so they
    /// answer honestly rather than refusing.
    #[test]
    fn the_questions_the_ui_asks_before_offering_a_button_are_answered() {
        let providers = providers().expect("assembles");

        assert_eq!(providers.power.can_reboot(), Ok(false));
        assert_eq!(providers.service.is_installed(), Ok(false));
        assert_eq!(providers.service.is_running(), Ok(false));
        assert_eq!(providers.device_info.is_supported_platform(), Ok(false));
    }

    #[test]
    fn the_permission_summary_reports_unsupported_rather_than_denied() {
        let summary = providers().unwrap().permissions.summary().unwrap();

        assert_eq!(summary, PermissionSummary::all_unsupported());
        assert!(!summary.can_host_session());
    }
}
