//! The Secure Desktop helper: letting an authorised controller answer a
//! Windows administrator prompt.
//!
//! # The problem this solves
//!
//! A UAC consent or credential prompt, the Ctrl+Alt+Del screen and the lock
//! screen do not live on the signed-in person's desktop. They live on the
//! **Secure Desktop** (`winsta0\Winlogon`), which Windows isolates from every
//! medium-integrity application by design: the app that hosts a session runs
//! in the user's desktop and cannot capture or inject into the Secure Desktop,
//! so a remote session goes blank at a UAC prompt and the agent can only
//! *detect* it (`platform::display::SecureDesktopState`).
//!
//! AnyDesk and TeamViewer answer those prompts because their per-machine
//! service — which runs as LocalSystem in Session 0 — starts a helper process
//! that *is* allowed onto the Secure Desktop. This module is the same idea,
//! built to the same boundary.
//!
//! # The two processes, and the two pipes
//!
//! ```text
//!   AicountlyRemote.exe (user session, medium)        the app
//!     │  existing app↔service pipe (ACL'd)
//!     │  StartSecureDesktop { session, mode }          asks the service
//!     ▼
//!   AicountlyRemoteService.exe (Session 0, SYSTEM)     the service
//!     │  authorise()  ── session known? console? our image? consent/setting?
//!     │  DuplicateTokenEx(SYSTEM) + SetTokenInformation(TokenSessionId)
//!     │  CreateProcessAsUserW(lpDesktop = "winsta0\\winlogon")
//!     ▼
//!   AicountlyRemoteService.exe --secure-desktop-helper <pipe>   the helper
//!     │  follows the input desktop (OpenInputDesktop/SetThreadDesktop)
//!     │  GDI capture of the input desktop, ≤1280px, ≤10fps, BGRA
//!     │  SendInput from the thread attached to that desktop
//!     │  dedicated pipe (DACL: SYSTEM + this session's user), binary frames
//!     ▲
//!     │  frames / state ▲   input / ping / stop ▼
//!   AicountlyRemote.exe                              connects as a client
//! ```
//!
//! # Why the helper is this binary and not a new one
//!
//! Nothing new ships. The service launches a second copy of itself with a
//! hidden `--secure-desktop-helper <pipe name>` argument. There is no variant
//! of [`crate::IpcRequest`] that names a program, a path or an argument, so a
//! peer that got through the app↔service pipe's ACL still cannot ask the
//! service to run anything — the helper's command line is built entirely
//! inside the service from a fixed string and a pipe name the service itself
//! chose.
//!
//! # What this module is, split for testing
//!
//! Everything that *decides* is pure Rust compiled and tested on every host:
//! the [`authorise`] gate, the [`protocol`] encode/decode with its size caps,
//! the [`frame`] downscale and pacing, and the [`inject`] coordinate and
//! key-code mapping. The parts that actually touch Windows — launching the
//! helper, following the input desktop, capturing with GDI, injecting with
//! `SendInput`, serving the dedicated pipe — live in [`helper`] and [`launch`]
//! behind `#[cfg(windows)]`, and are the only part a Windows runner is needed
//! for.

pub mod frame;
pub mod inject;
pub mod protocol;

pub use protocol::{HelperFrame, HelperInput, HelperMessage, HelperProtocolError, HELPER_VERSION};

#[cfg(windows)]
pub mod helper;
#[cfg(windows)]
pub mod launch;

use crate::ipc::SecureDesktopMode;

/// The hidden argument that turns this binary into a Secure Desktop helper.
///
/// Followed by the dedicated pipe name the service chose. It is never built
/// from anything a peer sent — the service assembles it from this constant and
/// a name it generated — which is what keeps "the service runs nothing it was
/// asked to run" true even with a helper that is a second copy of the service.
pub const HELPER_ARGUMENT: &str = "--secure-desktop-helper";

/// The facts about the connecting app that only the Windows layer can learn.
///
/// Gathered once from the pipe the request arrived on — the client's process
/// image and the session it runs in — and passed to [`authorise`] so the
/// decision itself is pure and exhaustively testable. A field that is `false`
/// is a check the Windows layer could not satisfy, which [`authorise`] treats
/// as a refusal rather than guessing.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub struct ClientFacts {
    /// The pipe client's image is the installed app in the service's own
    /// install directory — `QueryFullProcessImageNameW` of the client process
    /// id, compared with the directory the service runs from.
    pub is_installed_image: bool,
    /// The pipe client runs in the machine's active **console** session — the
    /// one at the physical screen — rather than a remote-desktop or detached
    /// session. `GetNamedPipeClientSessionId` compared with
    /// `WTSGetActiveConsoleSessionId`.
    pub in_console_session: bool,
}

/// Why a Secure Desktop helper was refused.
///
/// Specific rather than uniform: the app↔service pipe's ACL has already
/// excluded everything below medium integrity and everything but the
/// interactive user, so by the time a request reaches [`authorise`] the caller
/// is not a probe to be starved of information — it is the app, and a clear
/// reason is what lets its diagnostics say which of the two human decisions is
/// missing.
#[derive(Debug, Clone, Copy, PartialEq, Eq, thiserror::Error)]
pub enum SecureDesktopRefusal {
    /// The request did not come from the installed app image.
    #[error("the request did not come from AICOUNTLY Remote")]
    WrongImage,
    /// The request did not come from the machine's console session.
    #[error("the Secure Desktop can only be reached for the session at this computer")]
    NotConsoleSession,
    /// The service was never told about this session.
    #[error("that session is not running on this computer")]
    UnknownSession,
    /// Unattended, but the device setting that permits it is off.
    #[error("this computer is not set to answer administrator prompts unattended")]
    UnattendedNotEnabled,
}

/// **The gate on starting a Secure Desktop helper.**
///
/// Pure, and every refusal is one a test can produce. The checks run in order
/// from the most fundamental — is this even our app, from the right session —
/// to the setting that distinguishes unattended from attended, because an
/// unattended refusal on a request that was not from our app at all would be
/// the wrong thing to say.
///
/// Attended consent is not re-checked here: the app raises the consent box and
/// only sends the request once the person at the machine has ticked it, and
/// the service cannot see a dialog in another session to second-guess it. What
/// the service *can* check independently, it does — the image, the session,
/// and, for unattended, the device setting — and it is those that stop a
/// hostile same-user process from standing in for the app.
pub fn authorise(
    session_known: bool,
    mode: SecureDesktopMode,
    unattended_setting_on: bool,
    facts: ClientFacts,
) -> Result<(), SecureDesktopRefusal> {
    if !facts.is_installed_image {
        return Err(SecureDesktopRefusal::WrongImage);
    }

    if !facts.in_console_session {
        return Err(SecureDesktopRefusal::NotConsoleSession);
    }

    if !session_known {
        return Err(SecureDesktopRefusal::UnknownSession);
    }

    if matches!(mode, SecureDesktopMode::Unattended) && !unattended_setting_on {
        return Err(SecureDesktopRefusal::UnattendedNotEnabled);
    }

    Ok(())
}

/// Whether a client's image sits in the service's own install directory.
///
/// The pure half of the "our image" check: the Windows layer reads the pipe
/// client's full image path, and this decides whether it belongs to this
/// install. Both executables — the app and the service — ship in the one
/// install folder, so "the same directory the service runs from" is the test.
///
/// Taken as strings, and split on both `\` and `/` rather than through
/// `std::path`, so the Windows path logic is exercised by the Linux tests
/// rather than quietly behaving differently there (a `std::path::Path` does
/// not treat a backslash as a separator off Windows). Compared
/// case-insensitively, because Windows paths are. A client elsewhere on disk,
/// even one named identically, does not match.
#[must_use]
pub fn image_in_install_dir(image: &str, install_dir: &str) -> bool {
    let install = install_dir
        .trim_end_matches(['\\', '/'])
        .to_ascii_lowercase();

    if install.is_empty() {
        return false;
    }

    let image = image.to_ascii_lowercase();

    match image.rfind(['\\', '/']) {
        Some(separator) => image[..separator] == install,
        None => false,
    }
}

/// The dedicated pipe's name for a session, under a per-run salt.
///
/// The salt is random and chosen once when the service starts, so the full
/// name is unpredictable across runs: a process in the same session that
/// guessed the `AicountlyRemote.SecureDesktop.…` shape still cannot know the
/// salt, so it cannot create (and thereby squat) the pipe before the helper
/// does. `PIPE_REJECT_REMOTE_CLIENTS` and the first-instance flag on the
/// helper's side, and the DACL granting only SYSTEM and this session's user,
/// are what actually guard it; the salt only removes the race.
#[must_use]
pub fn dedicated_pipe_name(salt: &str, session_uuid: &str) -> String {
    format!(r"\\.\pipe\AicountlyRemote.SecureDesktop.{salt}.{session_uuid}")
}

#[cfg(test)]
mod tests {
    use super::*;

    fn ok_facts() -> ClientFacts {
        ClientFacts {
            is_installed_image: true,
            in_console_session: true,
        }
    }

    #[test]
    fn an_attended_request_from_our_app_in_the_console_session_for_a_known_session_is_allowed() {
        assert_eq!(
            authorise(true, SecureDesktopMode::Attended, false, ok_facts()),
            Ok(())
        );
    }

    /// Unattended needs the device setting on; attended does not.
    #[test]
    fn unattended_needs_the_device_setting_and_attended_does_not() {
        assert_eq!(
            authorise(true, SecureDesktopMode::Unattended, false, ok_facts()),
            Err(SecureDesktopRefusal::UnattendedNotEnabled)
        );
        assert_eq!(
            authorise(true, SecureDesktopMode::Unattended, true, ok_facts()),
            Ok(())
        );
        // Attended ignores the device setting entirely.
        assert_eq!(
            authorise(true, SecureDesktopMode::Attended, false, ok_facts()),
            Ok(())
        );
    }

    /// A request that did not come from our app, or not from the console
    /// session, is refused before anything about the session is considered —
    /// this is what stops a same-user process standing in for the app.
    #[test]
    fn a_request_not_from_the_app_image_in_the_console_session_is_refused_first() {
        assert_eq!(
            authorise(
                true,
                SecureDesktopMode::Attended,
                true,
                ClientFacts {
                    is_installed_image: false,
                    ..ok_facts()
                },
            ),
            Err(SecureDesktopRefusal::WrongImage)
        );

        assert_eq!(
            authorise(
                true,
                SecureDesktopMode::Attended,
                true,
                ClientFacts {
                    in_console_session: false,
                    ..ok_facts()
                },
            ),
            Err(SecureDesktopRefusal::NotConsoleSession)
        );
    }

    /// A session the service was never told about is not one it starts a
    /// helper for — the same rule the restart follows.
    #[test]
    fn an_unknown_session_is_refused() {
        assert_eq!(
            authorise(false, SecureDesktopMode::Attended, true, ok_facts()),
            Err(SecureDesktopRefusal::UnknownSession)
        );
    }

    /// Our-image matching is by directory and case-insensitive, so an
    /// identically named binary run from anywhere else does not pass.
    #[test]
    fn an_image_matches_only_in_the_install_directory() {
        let install = r"C:\Program Files\AICOUNTLY Remote";

        assert!(image_in_install_dir(
            r"C:\Program Files\AICOUNTLY Remote\AicountlyRemote.exe",
            install,
        ));
        // Case differences on a case-insensitive filesystem still match.
        assert!(image_in_install_dir(
            r"c:\program files\aicountly remote\AicountlyRemote.exe",
            install,
        ));
        // A trailing separator on the install directory does not spoil it.
        assert!(image_in_install_dir(
            r"C:\Program Files\AICOUNTLY Remote\AicountlyRemoteService.exe",
            r"C:\Program Files\AICOUNTLY Remote\",
        ));
        // A look-alike in another directory does not match.
        assert!(!image_in_install_dir(
            r"C:\Users\mallory\AppData\Local\Temp\AicountlyRemote.exe",
            install,
        ));
        // Nor a path with no directory part, nor an empty install directory.
        assert!(!image_in_install_dir("AicountlyRemote.exe", install));
        assert!(!image_in_install_dir(
            r"C:\Program Files\AICOUNTLY Remote\AicountlyRemote.exe",
            "",
        ));
    }

    /// The name is unpredictable across runs because the salt is, and it
    /// carries the session so two sessions never collide on one pipe.
    #[test]
    fn the_dedicated_pipe_name_is_salted_and_per_session() {
        let one = dedicated_pipe_name("f00dcafe", "session-1");
        let two = dedicated_pipe_name("f00dcafe", "session-2");
        let other_run = dedicated_pipe_name("deadbeef", "session-1");

        assert!(one.starts_with(r"\\.\pipe\AicountlyRemote.SecureDesktop."));
        assert_ne!(one, two, "two sessions get two pipes");
        assert_ne!(one, other_run, "a different run gets a different name");
        assert!(one.contains("session-1"));
    }
}
