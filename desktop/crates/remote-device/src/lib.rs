//! The seam between what AICOUNTLY Remote does and what an operating system
//! provides.
//!
//! Seven traits, each covering exactly one native concern. Everything above
//! them — the session logic, the control gate, the protocol, the UI — is
//! written once and compiled for every target; everything below is a thin
//! translation into `Windows.Graphics.Capture`, `SendInput`, DPAPI and the
//! Service Control Manager, or the macOS equivalents when those are written.
//!
//! # Why the split is drawn here
//!
//! Because the alternative is two products. If the Windows agent owned its own
//! session logic, macOS would grow a second copy that drifts — and the whole
//! point of `docs/DESKTOP_AGENT.md` is that a desktop agent is a *participant
//! with different capabilities*, not a second Remote.
//!
//! # macOS is Unsupported, honestly
//!
//! Every trait has a documented failure mode for a platform that has not
//! implemented it, and macOS returns it. There is no simulated capture, no
//! pretend input, and no code path that reports a capability the platform does
//! not have — because a capability the UI believes in and the machine cannot
//! deliver is worse than a clear "not supported yet".

#![forbid(unsafe_code)]
#![deny(missing_docs)]

use remote_protocol::{ClipboardPayload, KeyEvent, MonitorLayout, MouseEvent, PointerPosition, ScrollEvent};
use serde::{Deserialize, Serialize};

pub mod capability;
pub mod frame;

pub use capability::{AgentCapabilities, PermissionState, PermissionSummary};
pub use frame::{CaptureProfile, Frame, PixelFormat};

/// Why a native operation failed.
///
/// [`PlatformError::Unsupported`] is a first-class outcome, not an oversight:
/// it is what an unimplemented platform returns, and the UI renders it as
/// "not available on macOS yet" rather than as a crash.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum PlatformError {
    /// This platform has no implementation of this yet.
    #[error("{0} is not available on this platform yet")]
    Unsupported(&'static str),

    /// The operating system refused, and the user has to grant something.
    ///
    /// Distinct from a plain failure because the answer is different: the UI
    /// shows the person how to grant it rather than telling them to retry.
    #[error("{0}")]
    PermissionDenied(String),

    /// The operating system said no for some other reason.
    #[error("{operation} failed: {detail}")]
    Os {
        /// What was being attempted.
        operation: &'static str,
        /// What the platform reported. Never a secret, never user content.
        detail: String,
    },

    /// The thing being asked about is not there — an unplugged monitor, a
    /// service that is not installed.
    #[error("{0} was not found")]
    NotFound(&'static str),

    /// The operation needs elevation the agent deliberately does not hold.
    ///
    /// AICOUNTLY Remote does not silently elevate, and does not ask Windows
    /// to stop asking. Where a task genuinely needs administrator rights, the
    /// person is told so.
    #[error("{0} needs administrator rights")]
    ElevationRequired(&'static str),
}

/// A convenient result alias.
pub type PlatformResult<T> = Result<T, PlatformError>;

/// Capturing the screen.
///
/// Implementations feed frames into the WebRTC pipeline. Nothing here writes a
/// frame to disk, hands one to the API, or keeps one after it has been
/// encoded — screen pixels are never stored, and the trait offers no method
/// that would let them be.
pub trait ScreenCaptureProvider: Send + Sync {
    /// Every display the platform can see.
    fn monitors(&self) -> PlatformResult<MonitorLayout>;

    /// Begin capturing one display at a given profile.
    fn start(&mut self, monitor_id: u32, profile: CaptureProfile) -> PlatformResult<()>;

    /// Stop capturing. Idempotent — stopping a stopped capture succeeds.
    fn stop(&mut self) -> PlatformResult<()>;

    /// The next frame, if one is ready.
    ///
    /// Non-blocking and returns `None` when nothing has changed, so a static
    /// desktop costs nothing: `Windows.Graphics.Capture` delivers on change,
    /// and polling a screen that is not moving should not burn a core.
    fn next_frame(&mut self) -> PlatformResult<Option<Frame>>;

    /// Change resolution or frame rate on a running capture.
    ///
    /// Called from congestion feedback: a link that cannot sustain 1080p30
    /// gets a lower profile rather than a growing queue of frames nobody can
    /// send.
    fn reconfigure(&mut self, profile: CaptureProfile) -> PlatformResult<()>;

    /// Whether a capture is currently running.
    fn is_running(&self) -> bool;
}

/// Injecting keyboard and mouse input.
///
/// Every method takes a validated protocol type. Nothing here accepts a raw
/// scan code, a key name as a string, or anything else that would have to be
/// parsed on this side of the gate.
pub trait InputProvider: Send + Sync {
    /// Move the pointer to a normalised position on a monitor.
    fn move_pointer(&self, monitor_id: u32, position: PointerPosition) -> PlatformResult<()>;

    /// Move the pointer by a delta, for a pointer-locked controller.
    fn move_pointer_relative(&self, dx: f64, dy: f64) -> PlatformResult<()>;

    /// Press or release a mouse button.
    fn mouse_button(&self, monitor_id: u32, event: MouseEvent) -> PlatformResult<()>;

    /// Turn a wheel.
    fn scroll(&self, monitor_id: u32, event: ScrollEvent) -> PlatformResult<()>;

    /// Press or release a key.
    fn key(&self, event: KeyEvent) -> PlatformResult<()>;

    /// Release everything currently held.
    ///
    /// Called whenever control ends, for any reason. Without it, a controller
    /// whose tab closed mid-chord leaves Ctrl down on somebody else's machine
    /// — which reads, to the person sitting there, as a broken keyboard.
    fn release_all(&self) -> PlatformResult<()>;
}

/// Reading and writing the clipboard.
///
/// Text only in this version; the trait's shape allows more without changing
/// its callers, and [`ClipboardPayload`] refuses anything else today.
pub trait ClipboardProvider: Send + Sync {
    /// Read the clipboard as text. `None` when it holds something else.
    fn read_text(&self) -> PlatformResult<Option<String>>;

    /// Replace the clipboard with text.
    fn write_text(&self, text: &str) -> PlatformResult<()>;

    /// Apply a validated clipboard payload from the wire.
    fn apply(&self, payload: &ClipboardPayload) -> PlatformResult<()> {
        self.write_text(&payload.text)
    }
}

/// Installing, starting and querying the background service.
///
/// The Windows service owns machine lifecycle: auto-start, presence, device
/// authentication and the privileged operations that genuinely need it. It is
/// **not** interactive, and there is deliberately no method here that would
/// let it become so — a user-facing window from Session 0 is a thing Windows
/// stopped supporting for good reasons.
pub trait SystemServiceProvider: Send + Sync {
    /// Whether the service is installed.
    fn is_installed(&self) -> PlatformResult<bool>;

    /// Whether it is running right now.
    fn is_running(&self) -> PlatformResult<bool>;

    /// Install and configure it for automatic start. Needs elevation.
    fn install(&self) -> PlatformResult<()>;

    /// Stop and remove it, leaving nothing privileged behind. Needs elevation.
    fn uninstall(&self) -> PlatformResult<()>;

    /// Start it.
    fn start(&self) -> PlatformResult<()>;

    /// Stop it.
    fn stop(&self) -> PlatformResult<()>;

    /// Its version, when it is running and answering on the IPC channel.
    ///
    /// Used to notice a half-finished update — a new user-session process
    /// beside an old service — and say so rather than behaving strangely.
    fn service_version(&self) -> PlatformResult<Option<String>>;
}

/// What this machine is.
///
/// Everything here is sent at enrolment and shown in the device list. None of
/// it is a secret and none of it identifies a person: a hostname and an OS
/// build let an administrator recognise a machine, which is the whole purpose.
pub trait DeviceInfoProvider: Send + Sync {
    /// The machine's name — what the person calls it.
    fn host_name(&self) -> PlatformResult<String>;

    /// The operating system family: `Windows`, `macOS`.
    fn operating_system(&self) -> &'static str;

    /// The version, as the platform reports it: `11 24H2`, `10 22H2`.
    fn os_version(&self) -> PlatformResult<String>;

    /// The CPU architecture: `x86_64`, `aarch64`.
    fn architecture(&self) -> &'static str;

    /// Whether this build supports the platform it is running on.
    ///
    /// Windows 10 before 22H2 has no `Windows.Graphics.Capture` this agent can
    /// use; saying so at startup is better than failing at the first capture.
    fn is_supported_platform(&self) -> PlatformResult<bool>;
}

/// Restarting and shutting down.
///
/// Separately authorised everywhere: the company policy, the permission and a
/// live session with control all have to agree before this trait is reached.
pub trait PowerProvider: Send + Sync {
    /// Restart the machine.
    ///
    /// `reason` is shown to whoever is at it, and recorded by the platform's
    /// own shutdown log — so a person walking up to a restarting machine can
    /// see it was AICOUNTLY Remote and not a mystery.
    fn reboot(&self, reason: &str) -> PlatformResult<()>;

    /// Whether this process can actually restart the machine.
    ///
    /// Checked before the button is offered, so nobody is told a restart is
    /// happening and then finds it did not.
    fn can_reboot(&self) -> PlatformResult<bool>;
}

/// Native permissions and prerequisites, as the agent reports them.
///
/// On Windows this is mostly "is the service running" and "can we capture".
/// On macOS it will be Screen Recording and Accessibility, both of which the
/// user grants in System Settings and neither of which an application can
/// grant itself.
pub trait PermissionsProvider: Send + Sync {
    /// The current state of every permission the agent needs.
    fn summary(&self) -> PlatformResult<PermissionSummary>;

    /// Ask the platform to prompt for one, where it can.
    ///
    /// Returns [`PlatformError::Unsupported`] where the platform offers no
    /// prompt — Windows has nothing to ask for here — and the UI then simply
    /// does not offer a button.
    fn request(&self, permission: &str) -> PlatformResult<()>;
}

/// Everything the platform layer provides, in one place.
///
/// Assembled once at startup by the platform module and passed down. Having a
/// single struct rather than seven arguments is what keeps `commands/` from
/// growing its own idea of which providers exist.
pub struct PlatformProviders {
    /// Screen capture.
    pub capture: Box<dyn ScreenCaptureProvider>,
    /// Keyboard and mouse injection.
    pub input: Box<dyn InputProvider>,
    /// The clipboard.
    pub clipboard: Box<dyn ClipboardProvider>,
    /// The background service.
    pub service: Box<dyn SystemServiceProvider>,
    /// What this machine is.
    pub device_info: Box<dyn DeviceInfoProvider>,
    /// Restart.
    pub power: Box<dyn PowerProvider>,
    /// Native permissions.
    pub permissions: Box<dyn PermissionsProvider>,
}

/// A snapshot of the machine, for enrolment and for the device list.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct DeviceDescription {
    /// The machine's name.
    pub host_name: String,
    /// `Windows` or `macOS`.
    pub operating_system: String,
    /// The version string.
    pub os_version: String,
    /// `x86_64` or `aarch64`.
    pub architecture: String,
    /// This agent's version.
    pub agent_version: String,
}

impl DeviceDescription {
    /// Read the machine's description from a [`DeviceInfoProvider`].
    pub fn read(provider: &dyn DeviceInfoProvider, agent_version: &str) -> PlatformResult<Self> {
        Ok(Self {
            host_name: provider.host_name()?,
            operating_system: provider.operating_system().to_owned(),
            os_version: provider.os_version()?,
            architecture: provider.architecture().to_owned(),
            agent_version: agent_version.to_owned(),
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    struct StubInfo;

    impl DeviceInfoProvider for StubInfo {
        fn host_name(&self) -> PlatformResult<String> {
            Ok("WS-TEST-01".into())
        }

        fn operating_system(&self) -> &'static str {
            "Windows"
        }

        fn os_version(&self) -> PlatformResult<String> {
            Ok("11 24H2".into())
        }

        fn architecture(&self) -> &'static str {
            "x86_64"
        }

        fn is_supported_platform(&self) -> PlatformResult<bool> {
            Ok(true)
        }
    }

    struct UnimplementedInfo;

    impl DeviceInfoProvider for UnimplementedInfo {
        fn host_name(&self) -> PlatformResult<String> {
            Err(PlatformError::Unsupported("Reading the machine name"))
        }

        fn operating_system(&self) -> &'static str {
            "macOS"
        }

        fn os_version(&self) -> PlatformResult<String> {
            Err(PlatformError::Unsupported("Reading the OS version"))
        }

        fn architecture(&self) -> &'static str {
            "aarch64"
        }

        fn is_supported_platform(&self) -> PlatformResult<bool> {
            Ok(false)
        }
    }

    #[test]
    fn a_device_description_is_read_from_the_platform() {
        let description = DeviceDescription::read(&StubInfo, "1.0.0").expect("reads");

        assert_eq!(description.host_name, "WS-TEST-01");
        assert_eq!(description.operating_system, "Windows");
        assert_eq!(description.architecture, "x86_64");
        assert_eq!(description.agent_version, "1.0.0");
    }

    /// An unimplemented platform says so. It does not invent a hostname.
    #[test]
    fn an_unimplemented_platform_refuses_rather_than_inventing_an_answer() {
        let error = DeviceDescription::read(&UnimplementedInfo, "1.0.0").unwrap_err();

        assert!(matches!(error, PlatformError::Unsupported(_)));
        assert!(error.to_string().contains("not available on this platform yet"));
    }

    /// A permission failure has a different remedy from an OS failure, so the
    /// UI has to be able to tell them apart.
    #[test]
    fn error_kinds_stay_distinguishable_for_the_interface() {
        let denied = PlatformError::PermissionDenied("Screen Recording is not granted".into());
        let os = PlatformError::Os { operation: "capture", detail: "device lost".into() };
        let elevation = PlatformError::ElevationRequired("Installing the service");

        assert!(matches!(denied, PlatformError::PermissionDenied(_)));
        assert!(matches!(os, PlatformError::Os { .. }));
        assert!(elevation.to_string().contains("administrator rights"));
        assert_ne!(denied, os);
    }

    #[test]
    fn a_description_round_trips_as_json_for_the_enrolment_call() {
        let description = DeviceDescription::read(&StubInfo, "1.2.3").unwrap();
        let json = serde_json::to_string(&description).unwrap();

        assert_eq!(
            serde_json::from_str::<DeviceDescription>(&json).unwrap(),
            description
        );
    }
}
