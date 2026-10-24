//! The one place a native API is called.
//!
//! Everything above this module is portable and tested on any host. Everything
//! below it is Windows or macOS, and each is behind the traits in
//! `remote-device` so nothing above ever learns which.
//!
//! "Below it" is about what the code *does*, not about what will compile:
//! [`windows`] is deliberately not gated on the target, because each of its
//! files keeps its native calls in an inner `mod imp` and everything else —
//! coordinates, key tables, bounds — is arithmetic that should be tested
//! wherever CI happens to run. See that module for why.
//!
//! # Two things a platform can be: a host, and a companion
//!
//! * **Hosting** ([`supports_hosting`]) — the machine shares its own screen and
//!   is controlled through it. Windows.
//! * **Companion** ([`supports_companion`]) — the machine's owner shares a
//!   screen from the browser and the machine joins the same session
//!   input-only, so the person they grant control to drives its mouse and
//!   keyboard. Windows and macOS. See `docs/desktop/CONTROL_COMPANION.md`.
//!
//! [`macos`] implements the companion half and nothing else: it captures
//! nothing, and declares only `remote_control` — an agent that declared more
//! would be a device an administrator believes they can reach in ways it
//! cannot be.
//!
//! # Linux
//!
//! Not a supported product. The build exists so the workspace compiles and the
//! portable crates are tested on Linux CI; it assembles the macOS providers,
//! whose native halves refuse with [`PlatformError::Unsupported`] off macOS.

use remote_device::{AgentCapabilities, PlatformError, PlatformProviders, PlatformResult};
use remote_security::SecureStorageProvider;

/// Describing a display, and the Secure Desktop. Plain numbers, so it lives
/// above the platform gate and is tested on every host.
pub mod display;

pub mod macos;

/// Windows. Not gated on the target: every file inside guards its own native
/// half with `#[cfg(target_os = "windows")]`, so the coordinate arithmetic,
/// the virtual-key table and the held-key tracking compile — and are tested —
/// on a Linux runner too. Gating the module here is what once hid a rounding
/// bug and a scale conversion that dropped a working display, and left a test
/// written for non-Windows hosts running nowhere at all.
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

    #[cfg(target_os = "macos")]
    {
        Box::new(macos::storage::KeychainStorage)
    }

    #[cfg(not(any(target_os = "windows", target_os = "macos")))]
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

/// Whether this build can **host** a session: share its own screen and be
/// controlled through it. Windows only — macOS has no capture or encoder.
#[must_use]
pub fn supports_hosting() -> bool {
    cfg!(target_os = "windows")
}

/// Whether this build can be a **control companion**: join a session its
/// owner shares from the browser, input-only. Windows and macOS.
#[must_use]
pub fn supports_companion() -> bool {
    cfg!(any(target_os = "windows", target_os = "macos"))
}

/// Whether this build has a real platform implementation at all — either
/// role.
///
/// The window renders a plain "AICOUNTLY Remote is not available on this
/// operating system yet" when it does not, rather than a home screen full of
/// controls that would every one of them fail.
#[must_use]
pub fn is_supported() -> bool {
    supports_hosting() || supports_companion()
}

/// The permission summary an unimplemented platform reports.
#[cfg(test)]
pub(crate) fn unsupported_permissions() -> remote_device::PermissionSummary {
    remote_device::PermissionSummary::all_unsupported()
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
        if supports_hosting() {
            assert_eq!(declared_capabilities(), AgentCapabilities::windows());

            return;
        }

        if supports_companion() {
            assert_eq!(declared_capabilities(), AgentCapabilities::companion_only());

            return;
        }

        assert_eq!(declared_capabilities(), AgentCapabilities::none());
        assert!(!unsupported_permissions().can_host_session());
        assert!(!unsupported_permissions().can_be_controlled());
    }

    /// Hosting implies being able to be a companion; nothing is a host that
    /// cannot also take input alone.
    #[test]
    fn the_two_roles_are_reported_separately() {
        if supports_hosting() {
            assert!(supports_companion());
        }

        assert_eq!(is_supported(), supports_hosting() || supports_companion());
        assert_eq!(supports_hosting(), cfg!(target_os = "windows"));
        assert_eq!(
            supports_companion(),
            cfg!(any(target_os = "windows", target_os = "macos"))
        );
    }

    #[test]
    fn the_unsupported_error_names_what_was_attempted() {
        let error = unsupported::<()>("Screen capture").unwrap_err();

        assert!(error.to_string().contains("Screen capture"));
        assert!(error
            .to_string()
            .contains("not available on this platform yet"));
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
