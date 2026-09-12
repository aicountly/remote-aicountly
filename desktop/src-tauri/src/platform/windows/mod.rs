//! Windows. The only platform with a real implementation.
//!
//! Supported: **Windows 10 22H2 and later, and Windows 11.** x86_64 today;
//! nothing here is architecture-specific, so ARM64 is a build target rather
//! than a port.
//!
//! # Why this compiles on a Linux runner too
//!
//! The module is **not** gated on the target. Every file inside puts its
//! native calls in an inner `#[cfg(target_os = "windows")] mod imp` and
//! returns [`remote_device::PlatformError::Unsupported`] from the outer half
//! everywhere else, so the parts that are only arithmetic and tables — where a
//! click lands, which virtual-key code a key is, whether a key needs the
//! extended flag, how a wheel notch is counted, what the clipboard will carry
//! — compile and are **tested on every push**, not only when a Windows runner
//! is available.
//!
//! Gating the module instead was a real mistake, corrected here: it hid a
//! rounding bug and a DPI conversion that would have dropped a working display
//! out of the monitor layout, and it left a test written specifically for
//! non-Windows hosts running on no host at all. Anything added here should keep
//! the same shape — decide in the outer half, call in `imp`.
//!
//! # What runs where
//!
//! Everything in this module runs in the **user's own interactive session**,
//! in `AicountlyRemote.exe`. The service (`AicountlyRemoteService.exe`) runs in
//! Session 0 and has no desktop — it cannot capture what a person sees and
//! cannot inject into their session, so it does neither. See
//! `agents/windows-service` for that half and why the split exists.
//!
//! # What this deliberately does not do
//!
//! * **It does not disable UAC**, weaken any Windows security setting, or
//!   attempt to defeat the Secure Desktop.
//! * **It cannot capture or control the Secure Desktop.** Windows isolates it
//!   from every desktop application by design, and there is no supported way
//!   for a user-session process to reach it. That means the UAC prompt, the
//!   Ctrl+Alt+Del screen, and the sign-in screen appear to a remote viewer as a
//!   frozen or black frame, and input sent during one does nothing. The
//!   limitation is real, it is documented in
//!   `docs/desktop/WINDOWS_AGENT.md`, and the agent tells the person watching
//!   rather than leaving them to wonder — see [`capture::SecureDesktopState`].

use remote_device::{PlatformProviders, PlatformResult};

pub mod capture;
pub mod clipboard;
pub mod device;
pub mod input;
pub mod permissions;
pub mod power;
pub mod service;
pub mod storage;

/// Assemble the Windows providers.
pub fn providers() -> PlatformResult<PlatformProviders> {
    Ok(PlatformProviders {
        capture: Box::new(capture::WindowsCapture::new()),
        input: Box::new(input::WindowsInput::new()),
        clipboard: Box::new(clipboard::WindowsClipboard),
        service: Box::new(service::WindowsService),
        device_info: Box::new(device::WindowsDeviceInfo),
        power: Box::new(power::WindowsPower),
        permissions: Box::new(permissions::WindowsPermissions),
    })
}
