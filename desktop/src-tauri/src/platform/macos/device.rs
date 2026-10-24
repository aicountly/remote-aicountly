//! What this Mac is: its name, its macOS version, its architecture.
//!
//! The name is the **computer name** a person set in System Settings →
//! General → Sharing ("Rahul's MacBook Pro"), read with
//! `SCDynamicStoreCopyComputerName`, because that is what they recognise in a
//! device list — not the `.local` host name. The version comes from
//! `sysctl kern.osproductversion`. Neither needs a permission, and nothing
//! here starts a process.

#[cfg(not(target_os = "macos"))]
use remote_device::PlatformError;
use remote_device::{DeviceInfoProvider, PlatformResult};

/// The oldest macOS the companion supports (the owner's decision): 13 Ventura.
pub const MINIMUM_MACOS_MAJOR: u32 = 13;

/// Whether a `kern.osproductversion` string (`14.5`, `13.0.1`) is a macOS this
/// build supports.
#[must_use]
pub fn is_supported_version(version: &str) -> bool {
    version
        .trim()
        .split('.')
        .next()
        .and_then(|major| major.parse::<u32>().ok())
        .is_some_and(|major| major >= MINIMUM_MACOS_MAJOR)
}

/// A computer name made safe to show and to send: no control characters,
/// trimmed, bounded. It is a string a person typed, and it ends up in an
/// administrator's device list.
#[must_use]
pub fn clean_name(name: &str) -> Option<String> {
    let cleaned: String = name
        .chars()
        .filter(|c| !c.is_control())
        .take(120)
        .collect::<String>()
        .trim()
        .to_owned();

    (!cleaned.is_empty()).then_some(cleaned)
}

/// What the machine is.
#[derive(Debug, Default, Clone, Copy)]
pub struct MacDeviceInfo;

impl DeviceInfoProvider for MacDeviceInfo {
    fn host_name(&self) -> PlatformResult<String> {
        #[cfg(target_os = "macos")]
        {
            imp::computer_name()
        }

        #[cfg(not(target_os = "macos"))]
        {
            Err(PlatformError::Unsupported("Reading the machine name"))
        }
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
        #[cfg(target_os = "macos")]
        {
            imp::product_version()
        }

        #[cfg(not(target_os = "macos"))]
        {
            Err(PlatformError::Unsupported(
                "Reading the operating system version",
            ))
        }
    }

    fn architecture(&self) -> &'static str {
        std::env::consts::ARCH
    }

    fn is_supported_platform(&self) -> PlatformResult<bool> {
        #[cfg(target_os = "macos")]
        {
            Ok(is_supported_version(&imp::product_version()?))
        }

        #[cfg(not(target_os = "macos"))]
        {
            Ok(false)
        }
    }
}

#[cfg(target_os = "macos")]
mod imp {
    //! `SCDynamicStoreCopyComputerName` and `sysctlbyname`. macOS only.

    use std::ffi::{c_void, CStr};

    use core_foundation::base::TCFType;
    use core_foundation::string::{CFString, CFStringRef};
    use remote_device::{PlatformError, PlatformResult};

    use super::clean_name;

    #[link(name = "SystemConfiguration", kind = "framework")]
    extern "C" {
        fn SCDynamicStoreCopyComputerName(store: *const c_void, encoding: *mut u32) -> CFStringRef;
    }

    pub fn computer_name() -> PlatformResult<String> {
        // SAFETY: a null store means "the default one"; a null encoding
        // pointer is permitted. The result follows the Create rule.
        let name =
            unsafe { SCDynamicStoreCopyComputerName(std::ptr::null(), std::ptr::null_mut()) };

        if !name.is_null() {
            // SAFETY: non-null and owned by us, per the Create rule.
            let name = unsafe { CFString::wrap_under_create_rule(name) }.to_string();

            if let Some(name) = clean_name(&name) {
                return Ok(name);
            }
        }

        host_name()
    }

    fn host_name() -> PlatformResult<String> {
        let mut buffer = [0_u8; 256];

        // SAFETY: the buffer is writable for its whole length, and
        // gethostname NUL-terminates within it on success.
        let status = unsafe { libc::gethostname(buffer.as_mut_ptr().cast(), buffer.len()) };

        if status != 0 {
            return Err(PlatformError::Os {
                operation: "reading the machine name",
                detail: "gethostname failed".into(),
            });
        }

        let name = CStr::from_bytes_until_nul(&buffer)
            .ok()
            .and_then(|name| name.to_str().ok())
            .and_then(clean_name)
            .ok_or(PlatformError::NotFound("The machine name"))?;

        Ok(name.trim_end_matches(".local").to_owned())
    }

    pub fn product_version() -> PlatformResult<String> {
        let name = c"kern.osproductversion";
        let mut buffer = [0_u8; 64];
        let mut length = buffer.len();

        // SAFETY: name is NUL-terminated; buffer and length describe a
        // writable region, and sysctlbyname writes no more than `length`.
        let status = unsafe {
            libc::sysctlbyname(
                name.as_ptr(),
                buffer.as_mut_ptr().cast(),
                &mut length,
                std::ptr::null_mut(),
                0,
            )
        };

        if status != 0 {
            return Err(PlatformError::Os {
                operation: "reading the macOS version",
                detail: "sysctl kern.osproductversion failed".into(),
            });
        }

        CStr::from_bytes_until_nul(&buffer[..length.min(buffer.len())])
            .ok()
            .and_then(|version| version.to_str().ok())
            .map(|version| version.trim().to_owned())
            .filter(|version| !version.is_empty())
            .ok_or(PlatformError::NotFound("The macOS version"))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The owner's decision: macOS 13 and later.
    #[test]
    fn macos_13_and_later_are_supported() {
        assert!(is_supported_version("13.0"));
        assert!(is_supported_version("13.6.1"));
        assert!(is_supported_version("14.5"));
        assert!(is_supported_version("26.0"));
        assert!(!is_supported_version("12.7.4"));
        assert!(!is_supported_version("10.15"));
        assert!(!is_supported_version(""));
        assert!(!is_supported_version("unknown"));
    }

    #[test]
    fn a_computer_name_is_cleaned_before_it_is_shown_or_sent() {
        assert_eq!(
            clean_name("  Rahul's MacBook Pro\n"),
            Some("Rahul's MacBook Pro".into())
        );
        assert_eq!(clean_name("\u{7}\u{1b}"), None);
        assert_eq!(clean_name(&"x".repeat(500)).unwrap().len(), 120);
    }

    #[test]
    fn the_architecture_is_the_builds_own() {
        assert_eq!(MacDeviceInfo.architecture(), std::env::consts::ARCH);
    }
}
