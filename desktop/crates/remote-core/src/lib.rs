//! The portable heart of the desktop agent.
//!
//! Everything that is not a native call and not a pixel: the configuration,
//! the API client, the state machine that decides what the agent is doing, and
//! the backoff that decides when it tries again.
//!
//! Kept here rather than in `src-tauri` so that it compiles and is tested on
//! any host — which is what makes the Windows-only part of the agent small
//! enough to reason about.

#![forbid(unsafe_code)]
#![deny(missing_docs)]

pub mod api;
pub mod backoff;
pub mod config;
pub mod logging;
pub mod signalling;
pub mod state;
pub mod tls;
pub mod update;

pub use api::{
    companion_monitor_body, ApiClient, ApiError, CompanionView, ControlDecision,
    DesktopSignInOutcome, DesktopSignInRequest, DesktopSignInStart, DesktopSignInStatus,
    DeviceCredential, DeviceHistory, DeviceResource, HelpSession, HistoryEvent, HistorySession,
    HostedParticipant, HostedParticipants, MonitorMatch, OwnComputer, OwnComputers,
    PendingCompanion, PendingControlRequest, SessionControlView, SharedSurface, SurfaceKind,
};
pub use backoff::Backoff;
pub use config::{config_path, macos_support_directory, AgentConfig, ConfigError};
pub use logging::{log_directory, log_path};
pub use signalling::{Outbound, Peer, Signal, SignallingError, SignallingSocket};
pub use state::{
    AgentEvent, AgentState, AgentStatus, CompanionPhase, CompanionSummary, ControlStateView,
    ControlSummary, MonitorOption, SessionSummary, UnattendedState,
};
pub use update::{UpdateChecker, UpdateError, UpdateInfo, UpdateInstaller};

/// The single authoritative agent version.
///
/// Read from the crate's own `version`, which comes from `workspace.package`
/// in `desktop/Cargo.toml`. Every other place the version appears — the Tauri
/// bundle, the installer, the update manifest, `remote_devices.agent_version`,
/// the About panel — derives from that one field, so a release is one edit.
pub const AGENT_VERSION: &str = env!("CARGO_PKG_VERSION");

/// Which build of [`AGENT_VERSION`] this is: the time of the commit it was
/// built from, UTC, `yyyyMMddHHmm` — `None` for a local build.
///
/// The version only moves when a release is decided on, so several builds go
/// out as the same version. Each needs a name of its own: the downloads host
/// serves an installer as immutable for a year, so a second build published
/// under the first one's name reached nobody — every browser that had fetched
/// the first was handed it again, and the person reinstalling got the build
/// they were trying to replace. The release workflows set
/// `AICOUNTLY_REMOTE_BUILD`; the installer's name, its GitHub release, the
/// update check and the About panel all carry it.
pub const AGENT_BUILD: Option<&str> = option_env!("AICOUNTLY_REMOTE_BUILD");

/// The version as a person reads it: `1.0.0 (build 202610301030)`, or just the
/// version for a local build.
#[must_use]
pub fn version_label(version: &str, build: Option<&str>) -> String {
    match build {
        Some(build) if !build.is_empty() => format!("{version} (build {build})"),
        _ => version.to_owned(),
    }
}

/// The product name, as a person sees it.
pub const PRODUCT_NAME: &str = "AICOUNTLY Remote";

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_numbered_build_says_so_and_a_local_one_does_not() {
        assert_eq!(
            version_label("1.0.0", Some("202610301030")),
            "1.0.0 (build 202610301030)"
        );
        assert_eq!(version_label("1.0.0", None), "1.0.0");
        assert_eq!(version_label("1.0.0", Some("")), "1.0.0");
    }

    /// What the release workflows stamp, so the update check can compare it.
    #[test]
    fn a_stamped_build_is_twelve_digits() {
        if let Some(build) = AGENT_BUILD {
            assert_eq!(build.len(), 12, "{build}");
            assert!(build.bytes().all(|byte| byte.is_ascii_digit()), "{build}");
        }
    }
}
