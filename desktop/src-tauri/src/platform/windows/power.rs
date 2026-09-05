//! Restarting the machine.
//!
//! Separately authorised at every level above this: the company policy switch
//! (`allow_device_reboot`), the permission (`remote.control.request`), and an
//! active session with control on this device. By the time anything here is
//! called, the API has already said yes and written the audit entry — see
//! `DevicePresenceService::requestReboot`.
//!
//! The restart itself is performed by the **service**, not by this process:
//! `SE_SHUTDOWN_NAME` is a privilege a user-session process may not hold, and
//! the tray application asking for it would be the tray application asking for
//! elevation. The service already has it, and the IPC message that triggers it
//! names the session it was authorised inside.

use remote_device::{PlatformError, PlatformResult, PowerProvider};

/// How long Windows shows its own restart notice before restarting.
///
/// Not zero, deliberately. Somebody may have walked up to the machine between
/// the request and the restart, and thirty seconds is enough for them to see
/// what is happening and save what they were doing.
pub const RESTART_GRACE_SECONDS: u32 = 30;

/// Restarting, through the service.
#[derive(Debug, Default)]
pub struct WindowsPower;

impl PowerProvider for WindowsPower {
    fn reboot(&self, reason: &str) -> PlatformResult<()> {
        // Never performed here. The message that reaches the service names the
        // session that authorised it, and the service checks it again.
        let _ = reason;

        Err(PlatformError::ElevationRequired(
            "Restarting this computer, which the background service performs",
        ))
    }

    fn can_reboot(&self) -> PlatformResult<bool> {
        // The question the UI asks before *offering* a restart. The honest
        // answer is "if the service is running", because that is what performs
        // it — offering a button that will fail is worse than not offering one.
        #[cfg(target_os = "windows")]
        {
            use remote_device::SystemServiceProvider;

            super::service::WindowsService.is_running()
        }

        #[cfg(not(target_os = "windows"))]
        {
            Ok(false)
        }
    }
}

/// The message Windows shows on the machine before restarting.
///
/// Bounded and stripped: the reason travelled over the network, and it is
/// rendered on somebody else's screen and written to their System event log.
#[must_use]
pub fn restart_message(reason: &str, requested_by: &str) -> String {
    let clean = |value: &str| -> String {
        value
            .chars()
            .filter(|c| !c.is_control())
            .take(200)
            .collect::<String>()
            .trim()
            .to_owned()
    };

    let reason = clean(reason);
    let requested_by = clean(requested_by);

    if reason.is_empty() {
        format!("AICOUNTLY Remote: {requested_by} is restarting this computer.")
    } else {
        format!("AICOUNTLY Remote: {requested_by} is restarting this computer. {reason}")
    }
}

#[cfg(test)]
// Several tests here assert on constants on purpose: they are the design's
// own bounds, and the point is that editing one past what the design intends
// fails here rather than at some later runtime.
#[allow(clippy::assertions_on_constants)]
mod tests {
    use super::*;

    /// The restart is the service's job: a user-session process asking for
    /// SE_SHUTDOWN_NAME would be the tray application asking for elevation.
    #[test]
    fn the_user_session_process_does_not_restart_the_machine_itself() {
        let error = WindowsPower.reboot("test").unwrap_err();

        assert!(matches!(error, PlatformError::ElevationRequired(_)));
        assert!(error.to_string().contains("background service"));
    }

    /// Somebody may have walked up to the machine between the request and the
    /// restart.
    #[test]
    fn there_is_a_grace_period_before_the_machine_goes_down() {
        assert!(RESTART_GRACE_SECONDS >= 15);
        assert!(RESTART_GRACE_SECONDS <= 120);
    }

    /// The reason travelled over the network and is rendered on somebody
    /// else's screen and written to their event log.
    #[test]
    fn the_restart_message_is_stripped_and_bounded() {
        let message = restart_message(
            &format!("Applying\u{0}\u{7}updates{}", "x".repeat(500)),
            "Sam in support",
        );

        assert!(!message.contains('\0'));
        assert!(!message.contains('\u{7}'));
        assert!(message.len() < 500);
        assert!(message.contains("Sam in support"));
        assert!(message.starts_with("AICOUNTLY Remote:"));
    }

    #[test]
    fn an_empty_reason_still_names_who_asked() {
        let message = restart_message("", "Priya");

        assert!(message.contains("Priya"));
        assert!(message.ends_with("restarting this computer."));
    }

    /// Offering a restart button that will fail is worse than not offering one.
    #[cfg(not(target_os = "windows"))]
    #[test]
    fn a_machine_with_no_service_says_it_cannot_restart() {
        assert_eq!(WindowsPower.can_reboot(), Ok(false));
    }
}
