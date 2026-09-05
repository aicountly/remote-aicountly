//! What Windows currently lets the agent do.
//!
//! # Windows has fewer permissions than macOS, and the UI should say so
//!
//! There is no Screen Recording consent to grant and no Accessibility switch
//! to flip: a desktop application may capture the screen and call `SendInput`
//! without asking. The honest report for those is
//! [`PermissionState::NotApplicable`] — **not** `Ready`, and certainly not a
//! button offering to request something nobody can grant.
//!
//! What Windows does have is two real prerequisites, and both are things a
//! person can act on:
//!
//! * the **background service** has to be installed and running, or the
//!   machine is not reachable when nobody is signed in;
//! * this build has to be running on a **supported Windows**, or capture will
//!   fail at the first frame rather than at startup where it can be explained.

use remote_device::{
    DeviceInfoProvider, PermissionState, PermissionSummary, PermissionsProvider, PlatformError,
    PlatformResult, SystemServiceProvider,
};

/// Windows permissions.
#[derive(Debug, Default)]
pub struct WindowsPermissions;

impl PermissionsProvider for WindowsPermissions {
    fn summary(&self) -> PlatformResult<PermissionSummary> {
        let supported = super::device::WindowsDeviceInfo
            .is_supported_platform()
            .unwrap_or(false);

        let service_running = super::service::WindowsService.is_running().unwrap_or(false);
        let service_installed = super::service::WindowsService.is_installed().unwrap_or(false);

        Ok(PermissionSummary {
            // Capture and input need no consent on Windows — but they do need
            // a Windows this build supports, so an unsupported one is reported
            // as needing attention rather than as ready.
            screen_capture: if supported {
                PermissionState::NotApplicable
            } else {
                PermissionState::NeedsAttention
            },
            input_injection: if supported {
                PermissionState::NotApplicable
            } else {
                PermissionState::NeedsAttention
            },
            clipboard: PermissionState::NotApplicable,
            background_service: match (service_installed, service_running) {
                (_, true) => PermissionState::Ready,
                (true, false) => PermissionState::NeedsAttention,
                (false, false) => PermissionState::NeedsAttention,
            },
            // Restarting is the service's job, so it is available exactly when
            // the service is.
            power: if service_running {
                PermissionState::Ready
            } else {
                PermissionState::NeedsAttention
            },
        })
    }

    fn request(&self, permission: &str) -> PlatformResult<()> {
        match permission {
            // The one thing that can actually be asked for. It needs
            // elevation, which the UI turns into the ordinary Windows consent
            // prompt rather than trying to obtain quietly.
            "background_service" => super::service::WindowsService.start(),
            // There is nothing to prompt for, so no button is offered. Saying
            // `Unsupported` rather than succeeding is what stops the UI
            // inventing a step nobody can take.
            _ => Err(PlatformError::Unsupported("Requesting that permission")),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// `NotApplicable`, not `Ready`: there is nothing to grant, and a button
    /// offering to request it would be a step nobody can take.
    #[test]
    fn capture_and_input_need_no_consent_on_windows() {
        let summary = WindowsPermissions.summary().expect("summarises");

        if cfg!(target_os = "windows") {
            // On a supported Windows both are not-applicable; on an
            // unsupported one both need attention. Either way they agree.
            assert_eq!(summary.screen_capture, summary.input_injection);
        }

        assert_eq!(summary.clipboard, PermissionState::NotApplicable);
        assert!(PermissionState::NotApplicable.is_usable());
    }

    #[test]
    fn there_is_nothing_to_request_except_the_service() {
        let permissions = WindowsPermissions;

        assert!(matches!(
            permissions.request("screen_capture"),
            Err(PlatformError::Unsupported(_))
        ));
        assert!(matches!(
            permissions.request("input_injection"),
            Err(PlatformError::Unsupported(_))
        ));
        assert!(matches!(
            permissions.request("anything_else"),
            Err(PlatformError::Unsupported(_))
        ));
    }

    /// Without the service the machine is not reachable when nobody is signed
    /// in, so unattended access is constrained away.
    #[test]
    fn without_the_service_unattended_access_is_not_offered() {
        let summary = PermissionSummary {
            background_service: PermissionState::NeedsAttention,
            ..PermissionSummary::all_ready()
        };

        let capabilities =
            summary.constrain(remote_device::AgentCapabilities::windows());

        assert!(!capabilities.unattended_access);
        assert!(capabilities.remote_control, "attended control still works");
    }
}
