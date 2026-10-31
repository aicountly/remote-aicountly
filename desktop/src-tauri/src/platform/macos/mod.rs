//! macOS: a **host**, and a **control companion**.
//!
//! A Mac takes part in a session in either of two ways, and both go through
//! the same `ControlGate` as on Windows.
//!
//! * **Hosting** — it shares its own screen, captured with Core Graphics
//!   (`capture.rs`), and is controlled through it: in a session its owner
//!   started, or in an unattended one an administrator enabled. That takes two
//!   permissions a person grants in System Settings — **Screen Recording** for
//!   the picture and **Accessibility** for the mouse and keyboard — and the
//!   capture refuses, with the steps, rather than send a picture of the
//!   wallpaper without the first.
//! * **Companion** — its owner shares a screen from Chrome or Edge as usual,
//!   links this Mac to that share, and somebody they grant control to in the
//!   browser moves this Mac's mouse and types on its keyboard. The picture
//!   comes from the browser, so a companion needs Accessibility alone and
//!   never starts a capture. See `docs/desktop/CONTROL_COMPANION.md`.
//!
//! What a Mac does not do, in either role: share its clipboard (off in this
//! version), restart, or run a launch daemon — so "unattended" on a Mac means
//! a Mac left signed in with AICOUNTLY Remote running. One at the login
//! window, or restarted and not signed back in to, cannot be reached, and says
//! so by not being online.
//!
//! | Trait | macOS | Notes |
//! |---|---|---|
//! | `ScreenCaptureProvider` | [`capture::MacCapture`] | `CGDisplayCreateImage` of one display, paced to the profile, handed over as BGRA with its real stride. Needs Screen Recording and refuses without it. The display list (`CGGetActiveDisplayList`, `CGDisplayBounds`, in [`display`]) needs no permission. Deprecated from macOS 14 in favour of ScreenCaptureKit — see `capture.rs`. |
//! | `InputProvider` | [`input::MacInput`] | `CGEvent` from a private event source, posted at the HID tap. Needs Accessibility. |
//! | `PermissionsProvider` | [`permissions::MacPermissions`] | `AXIsProcessTrustedWithOptions` and `CGPreflightScreenCaptureAccess`, both read on every call. |
//! | `DeviceInfoProvider` | [`device::MacDeviceInfo`] | Computer name, `kern.osproductversion`; macOS 13 and later. |
//! | `SecureStorageProvider` | [`storage::KeychainStorage`] | A generic-password item, after first unlock, this device only. |
//! | `ClipboardProvider` | `Unsupported` | Off on a Mac in this version. |
//! | `SystemServiceProvider` | `Unsupported` | No launch daemon: unattended only while somebody is signed in and the app is running. |
//! | `PowerProvider` | `Unsupported` | A Mac cannot be restarted from a session. |
//!
//! # Compiled and tested on every host
//!
//! Like `platform::windows`, this module is **not** gated on the target. Each
//! file keeps its native calls in an inner `#[cfg(target_os = "macos")] mod
//! imp`, and everything around them — the key table, the coordinate
//! arithmetic, the display description, which display is captured and when a
//! frame is due, the byte-order conversion, the permission summary, the
//! keychain fallback rule — is plain Rust tested on Linux CI. Off macOS the
//! providers refuse with [`remote_device::PlatformError::Unsupported`], which
//! is also what a Linux developer build of the agent uses.

use remote_device::{
    AgentCapabilities, ClipboardProvider, PlatformProviders, PlatformResult, PowerProvider,
    SystemServiceProvider,
};
use remote_protocol::ClipboardPayload;

use super::unsupported;

pub mod capture;
pub mod device;
pub mod display;
pub mod input;
pub mod permissions;
pub mod storage;

pub use device::MacDeviceInfo;

/// What a Mac declares at enrolment: it shares its own screen, is controlled
/// through it, and can be reached with nobody at it — while somebody is
/// signed in. Nothing else: no clipboard, no files, no restart, and no
/// viewing of other screens.
///
/// An upper bound, like every declaration: the server intersects it with the
/// organisation's policy, and [`PermissionSummary::constrain`] with what macOS
/// permits. A companion session takes none of it but `remote_control`.
///
/// It lives here rather than in
/// `remote_device::AgentCapabilities::for_current_platform`, which still
/// describes the Mac from before it could host (`companion_only`): the
/// platform module is the one that knows a Mac now captures.
///
/// [`PermissionSummary::constrain`]: remote_device::PermissionSummary::constrain
#[must_use]
pub fn declared_capabilities() -> AgentCapabilities {
    AgentCapabilities {
        screen_share: true,
        remote_control: true,
        unattended_access: true,
        ..AgentCapabilities::none()
    }
}

/// Assemble the macOS providers. Off macOS every native one refuses.
pub fn providers() -> PlatformResult<PlatformProviders> {
    Ok(PlatformProviders {
        capture: Box::new(capture::MacCapture::new()),
        input: Box::new(input::MacInput::new()),
        clipboard: Box::new(NoClipboard),
        service: Box::new(NoService),
        device_info: Box::new(MacDeviceInfo),
        power: Box::new(NoPower),
        permissions: Box::new(permissions::MacPermissions),
    })
}

/// The clipboard: off on a Mac in this version, hosting or companion.
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

/// No background daemon: the agent runs while somebody is signed in, which is
/// the only time a Mac can be reached at all — attended, unattended or as a
/// companion. Managing a service refuses; the questions the window asks
/// before offering to are answered "no".
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

/// Restarting: not something a Mac offers from a session, hosting or
/// companion.
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

    /// What a Mac does not do, in either role, refuses with a reason a
    /// person can read — and so does capture, off a Mac.
    #[test]
    fn what_a_mac_does_not_do_refuses_with_a_reason_a_person_can_read() {
        let mut providers = providers().expect("assembles");

        let started = providers
            .capture
            .start(1, remote_device::CaptureProfile::adaptive());

        if cfg!(target_os = "macos") {
            // On a Mac: capturing, or saying Screen Recording is needed.
            assert!(
                matches!(started, Ok(()) | Err(PlatformError::PermissionDenied(_))),
                "{started:?}"
            );
            assert!(providers.capture.stop().is_ok());
        } else {
            let error = started.unwrap_err();
            assert!(matches!(error, PlatformError::Unsupported(_)));
            assert!(error
                .to_string()
                .contains("not available on this platform yet"));
        }

        for error in [
            providers.clipboard.read_text().map(|_| ()).unwrap_err(),
            providers.clipboard.write_text("x").unwrap_err(),
            providers.power.reboot("test").unwrap_err(),
            providers.service.install().unwrap_err(),
            providers.service.start().unwrap_err(),
        ] {
            assert!(matches!(error, PlatformError::Unsupported(_)), "{error:?}");
            assert!(error
                .to_string()
                .contains("not available on this platform yet"));
        }
    }

    /// A Mac hosts and is controlled, and can be reached unattended while
    /// somebody is signed in — and declares nothing it does not do.
    #[test]
    fn a_mac_declares_hosting_and_nothing_it_does_not_do() {
        let declared = declared_capabilities();

        assert!(declared.screen_share);
        assert!(declared.remote_control);
        assert!(declared.unattended_access);

        assert!(!declared.clipboard_sync, "off on a Mac in this version");
        assert!(!declared.reboot);
        assert!(!declared.file_transfer);
        assert!(!declared.screen_view);

        // Everything a companion declared is still declared.
        let companion = AgentCapabilities::companion_only();
        assert_eq!(declared.intersect(companion), companion);
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
