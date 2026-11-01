//! AICOUNTLY Remote Service — the machine half of the Windows agent.
//!
//! # Two processes, and why
//!
//! Windows services run in **Session 0**, which has no desktop. A service
//! cannot capture what a user sees, cannot inject input into their session,
//! and cannot show them a window. The `Interactive Services Detection` service
//! that used to paper over this was removed in Windows 10 1803, and the
//! `SERVICE_INTERACTIVE_PROCESS` flag it depended on has been ignored since.
//!
//! So the agent is two processes and neither pretends to be the other:
//!
//! ```text
//!   AicountlyRemoteService.exe        Session 0, LocalSystem, auto-start
//!     device identity and presence      the machine is reachable
//!     device authentication             holds no user's credential
//!     reboot, service lifecycle         privileged, and only where needed
//!           │
//!           │  named pipe, ACL'd, authenticated both ways, versioned
//!           ▼
//!   AicountlyRemote.exe               the user's own session
//!     tray, window, consent             a person can see and stop it
//!     screen capture                    of the desktop they are looking at
//!     keyboard and mouse injection      into the desktop they are using
//!     clipboard, WebRTC                 where the session actually happens
//! ```
//!
//! # What this service deliberately cannot do
//!
//! * **It is not interactive.** It creates no window, no dialog and no tray
//!   icon, and it does not launch the UI process either — Windows removed
//!   interactive services, and a service that pushed a window into somebody's
//!   session would be doing the thing that was removed. The installer
//!   registers the tray application to start at sign-in; the two find each
//!   other over the pipe.
//! * **It executes nothing arbitrary.** [`IpcRequest`] has no variant naming a
//!   program, a path, an argument or a command line, and there is no
//!   passthrough. The widest thing it will do is restart the machine.
//! * **It opens no TCP port.** A localhost admin API is reachable by every
//!   process on the machine and by anything that can make the browser issue a
//!   request; a named pipe with an explicit ACL is not.
//! * **It weakens nothing.** It does not disable UAC and does not turn off any
//!   Windows security setting. It *can*, when a session is being controlled and
//!   the person at the machine (or the device's unattended setting) has agreed,
//!   start a **Secure Desktop helper** so an authorised controller can answer a
//!   UAC prompt — which is reaching the Secure Desktop the supported way, as a
//!   SYSTEM process on `winsta0\winlogon`, not defeating it. The gate on that is
//!   [`secure_desktop::authorise`], and the whole design is in that module and
//!   in `docs/desktop/WINDOWS_AGENT.md`.

#![deny(missing_docs)]

pub mod ipc;
pub mod machine;
pub mod pipe;
pub mod secure_desktop;
pub mod storage;

#[cfg(windows)]
pub mod service;

pub use ipc::{
    IpcError, IpcFrame, IpcRequest, IpcResponse, SecureDesktopMode, IPC_PROTOCOL_VERSION,
};
pub use machine::{handle, Connection, Effect, MachineState};
pub use pipe::{pipe_name, PipeSecurity};
pub use secure_desktop::{
    authorise as authorise_secure_desktop, HelperFrame, HelperInput, HelperMessage,
    SecureDesktopRefusal,
};

/// The display name Windows shows in `services.msc`.
pub const SERVICE_DISPLAY_NAME: &str = "AICOUNTLY Remote Service";

/// The service's key name.
pub const SERVICE_NAME: &str = "AicountlyRemoteService";

/// What an administrator reads in the service's description column.
pub const SERVICE_DESCRIPTION: &str =
    "Keeps this computer reachable for AICOUNTLY Remote assistance sessions. \
     Screen sharing and remote control run in the signed-in user's own session, \
     not in this service.";
