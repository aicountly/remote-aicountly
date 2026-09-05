//! The one place a native API is called.
//!
//! Everything above this module is portable and tested on any host. Everything
//! below it is Windows or macOS, and each is behind the traits in
//! `remote-device` so nothing above ever learns which.
//!
//! # macOS is Unsupported, and says so
//!
//! [`macos`] returns [`PlatformError::Unsupported`] from every method. It does
//! not simulate a capture, it does not pretend to inject input, and it does
//! not declare a capability the machine cannot deliver — an agent that
//! enrolled with `remote_control: true` on macOS would be a device an
//! administrator believes they can control and cannot.
//!
//! # Linux
//!
//! Not a supported product. The build exists so the workspace compiles and the
//! portable crates are tested on Linux CI, and it uses the same `Unsupported`
//! providers macOS does.

use remote_device::{
    AgentCapabilities, PermissionSummary, PlatformError, PlatformProviders, PlatformResult,
};
use remote_security::SecureStorageProvider;

pub mod macos;

#[cfg(target_os = "windows")]
pub mod windows;

/// Everything the platform layer provides, assembled once at startup.
pub fn providers() -> PlatformResult<PlatformProviders> {
    #[cfg(target_os = "windows")]
    {
        windows::providers()
    }

    #[cfg(not(target_os = "windows"))]
    {
        macos::providers()
    }
}

/// The secure store the device key lives in.
pub fn secure_storage() -> Box<dyn SecureStorageProvider> {
    #[cfg(target_os = "windows")]
    {
        Box::new(windows::storage::DpapiStorage::new())
    }

    #[cfg(not(target_os = "windows"))]
    {
        Box::new(remote_security::storage::UnsupportedStorage)
    }
}

/// What this build declares it can do.
///
/// Not what the *machine* currently permits — that is
/// [`PermissionSummary::constrain`], applied on top. This is the ceiling for
/// the platform the binary was compiled for.
#[must_use]
pub fn declared_capabilities() -> AgentCapabilities {
    AgentCapabilities::for_current_platform()
}

/// The name of the platform this build targets, for the About panel.
#[must_use]
pub fn platform_name() -> &'static str {
    #[cfg(target_os = "windows")]
    {
        "Windows"
    }

    #[cfg(target_os = "macos")]
    {
        "macOS"
    }

    #[cfg(not(any(target_os = "windows", target_os = "macos")))]
    {
        "unsupported"
    }
}

/// Whether this build has a real platform implementation at all.
///
/// The window renders a plain "AICOUNTLY Remote is not available on this
/// operating system yet" when it does not, rather than a home screen full of
/// controls that would every one of them fail.
#[must_use]
pub fn is_supported() -> bool {
    cfg!(target_os = "windows")
}

/// The permission summary an unimplemented platform reports.
pub(crate) fn unsupported_permissions() -> PermissionSummary {
    PermissionSummary::all_unsupported()
}

/// The error an unimplemented platform returns.
pub(crate) fn unsupported<T>(what: &'static str) -> PlatformResult<T> {
    Err(PlatformError::Unsupported(what))
}

#[cfg(test)]
mod tests {
    use super::*;

    /// A capability the UI believes in and the machine cannot deliver is worse
    /// than a clear "not supported yet".
    #[test]
    fn an_unsupported_platform_declares_nothing_and_says_so() {
        if is_supported() {
            assert_eq!(declared_capabilities(), AgentCapabilities::windows());

            return;
        }

        assert_eq!(declared_capabilities(), AgentCapabilities::none());
        assert!(!unsupported_permissions().can_host_session());
        assert!(!unsupported_permissions().can_be_controlled());
    }

    #[test]
    fn the_unsupported_error_names_what_was_attempted() {
        let error = unsupported::<()>("Screen capture").unwrap_err();

        assert!(error.to_string().contains("Screen capture"));
        assert!(error.to_string().contains("not available on this platform yet"));
    }

    /// On a platform with no key store, storage refuses rather than falling
    /// back to a file — which is what the whole abstraction exists to prevent.
    #[test]
    fn secure_storage_on_an_unsupported_platform_refuses_rather_than_using_a_file() {
        if is_supported() {
            return;
        }

        let store = secure_storage();

        assert!(store
            .store(
                "device-signing-key",
                b"secret",
                remote_security::StorageScope::LocalMachine
            )
            .is_err());
    }
}
