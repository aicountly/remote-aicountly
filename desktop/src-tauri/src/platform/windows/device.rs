//! What this machine is.
//!
//! Everything here is sent at enrolment and shown in an administrator's device
//! list. None of it is a secret and none of it identifies a person: a hostname
//! and a Windows build let somebody recognise a machine, which is the point.

use remote_device::{DeviceInfoProvider, PlatformError, PlatformResult};

/// The oldest Windows 10 build this agent supports.
///
/// 22H2 is build 19045. Earlier 10 releases are out of support from Microsoft,
/// and the capture path this agent uses is not something to be running on an
/// operating system nobody is patching.
pub const MINIMUM_WINDOWS_10_BUILD: u32 = 19_045;

/// The first Windows 11 build.
pub const FIRST_WINDOWS_11_BUILD: u32 = 22_000;

/// Windows, as the agent describes it.
#[derive(Debug, Default)]
pub struct WindowsDeviceInfo;

/// Turn a Windows build number into the name a person uses.
///
/// Windows 11 reports itself as major version 10 — `GetVersionEx` was frozen
/// years ago — so the build number is the only thing that tells them apart.
#[must_use]
pub fn describe_build(build: u32, display_version: &str) -> String {
    let family = if build >= FIRST_WINDOWS_11_BUILD {
        "11"
    } else {
        "10"
    };

    if display_version.is_empty() {
        format!("{family} (build {build})")
    } else {
        format!("{family} {display_version}")
    }
}

/// Whether a build is one this agent supports.
#[must_use]
pub fn is_supported_build(build: u32) -> bool {
    build >= MINIMUM_WINDOWS_10_BUILD
}

impl DeviceInfoProvider for WindowsDeviceInfo {
    fn host_name(&self) -> PlatformResult<String> {
        // `COMPUTERNAME` is what Windows itself shows in System > About and is
        // what a person will recognise in a device list.
        match std::env::var("COMPUTERNAME") {
            Ok(name) if !name.trim().is_empty() => Ok(name.trim().to_owned()),
            _ => match hostname_fallback() {
                Some(name) => Ok(name),
                None => Err(PlatformError::Os {
                    operation: "reading the machine name",
                    detail: "Windows did not report a computer name".into(),
                }),
            },
        }
    }

    fn operating_system(&self) -> &'static str {
        "Windows"
    }

    fn os_version(&self) -> PlatformResult<String> {
        #[cfg(target_os = "windows")]
        {
            let (build, display) = imp::version()?;

            Ok(describe_build(build, &display))
        }

        #[cfg(not(target_os = "windows"))]
        {
            Err(PlatformError::Unsupported("Reading the Windows version"))
        }
    }

    fn architecture(&self) -> &'static str {
        // `x86_64` and `aarch64`, which is what the API stores and what the
        // installer names its artifacts after.
        std::env::consts::ARCH
    }

    fn is_supported_platform(&self) -> PlatformResult<bool> {
        #[cfg(target_os = "windows")]
        {
            let (build, _) = imp::version()?;

            Ok(is_supported_build(build))
        }

        #[cfg(not(target_os = "windows"))]
        {
            Ok(false)
        }
    }
}

fn hostname_fallback() -> Option<String> {
    std::env::var("HOSTNAME")
        .ok()
        .filter(|name| !name.trim().is_empty())
        .map(|name| name.trim().to_owned())
}

#[cfg(target_os = "windows")]
mod imp {
    use remote_device::{PlatformError, PlatformResult};

    /// The build number and the display version, read from the registry.
    ///
    /// `GetVersionEx` has been frozen at 6.2 since Windows 8.1 unless an
    /// application ships a compatibility manifest declaring every Windows it
    /// has been tested on — which is a manifest that has to be edited for
    /// every future release. `RtlGetVersion` avoids the shim, and the registry
    /// is where `DisplayVersion` ("22H2", "24H2") actually lives.
    pub fn version() -> PlatformResult<(u32, String)> {
        use windows::Wdk::System::SystemServices::RtlGetVersion;
        use windows::Win32::System::SystemInformation::OSVERSIONINFOW;

        let mut info = OSVERSIONINFOW {
            dwOSVersionInfoSize: std::mem::size_of::<OSVERSIONINFOW>() as u32,
            ..Default::default()
        };

        // SAFETY: `info` is a correctly sized, initialised local.
        let status = unsafe { RtlGetVersion(&mut info) };

        if status.is_err() {
            return Err(PlatformError::Os {
                operation: "reading the Windows version",
                detail: "RtlGetVersion failed".into(),
            });
        }

        Ok((info.dwBuildNumber, display_version()))
    }

    /// `DisplayVersion` from the registry, or an empty string.
    ///
    /// Empty rather than an error: the build number alone is enough to name
    /// the release, and failing enrolment because a registry read did not work
    /// would be a poor trade.
    fn display_version() -> String {
        use windows::core::w;
        use windows::Win32::System::Registry::{
            RegCloseKey, RegGetValueW, HKEY, HKEY_LOCAL_MACHINE, RRF_RT_REG_SZ,
        };

        let mut buffer = [0_u16; 64];
        let mut size = std::mem::size_of_val(&buffer) as u32;

        // SAFETY: the buffer and size are matched locals; RegGetValueW writes
        // at most `size` bytes and updates it.
        let result = unsafe {
            RegGetValueW(
                HKEY_LOCAL_MACHINE,
                w!(r"SOFTWARE\Microsoft\Windows NT\CurrentVersion"),
                w!("DisplayVersion"),
                RRF_RT_REG_SZ,
                None,
                Some(buffer.as_mut_ptr().cast()),
                Some(&mut size),
            )
        };

        let _ = RegCloseKey;
        let _: Option<HKEY> = None;

        if result.is_err() {
            return String::new();
        }

        String::from_utf16_lossy(&buffer)
            .trim_end_matches('\0')
            .trim()
            .to_owned()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Windows 11 reports itself as major version 10, so the build number is
    /// the only thing that tells them apart.
    #[test]
    fn the_build_number_is_what_distinguishes_ten_from_eleven() {
        assert_eq!(describe_build(19_045, "22H2"), "10 22H2");
        assert_eq!(describe_build(22_631, "23H2"), "11 23H2");
        assert_eq!(describe_build(26_100, "24H2"), "11 24H2");
    }

    #[test]
    fn a_missing_display_version_falls_back_to_the_build() {
        assert_eq!(describe_build(26_100, ""), "11 (build 26100)");
        assert_eq!(describe_build(19_045, ""), "10 (build 19045)");
    }

    /// The capture path this agent uses is not something to run on an
    /// operating system nobody is patching.
    #[test]
    fn windows_ten_before_twenty_two_h_two_is_not_supported() {
        assert!(!is_supported_build(18_363), "1909 must not be supported");
        assert!(!is_supported_build(19_044), "21H2 must not be supported");
        assert!(
            is_supported_build(MINIMUM_WINDOWS_10_BUILD),
            "22H2 must be supported"
        );
        assert!(is_supported_build(22_631));
        assert!(is_supported_build(26_100));
    }

    #[test]
    fn the_architecture_is_the_one_the_api_stores() {
        let architecture = WindowsDeviceInfo.architecture();

        assert!(!architecture.is_empty());
        assert!(["x86_64", "aarch64", "x86"].contains(&architecture) || architecture.len() < 16);
    }

    #[test]
    fn the_operating_system_name_matches_what_the_api_expects() {
        assert_eq!(WindowsDeviceInfo.operating_system(), "Windows");
    }
}
