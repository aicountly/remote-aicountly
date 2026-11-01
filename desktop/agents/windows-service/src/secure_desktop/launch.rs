//! Starting a Secure Desktop helper, and learning who asked.
//!
//! This is the only part of the service that creates a process, and it creates
//! exactly one: a second copy of this binary with the fixed
//! [`super::HELPER_ARGUMENT`], run inside the requesting app's session with a
//! desktop of `winsta0\winlogon`. Nothing a peer sent becomes part of the
//! command line — the executable is this service's own path and the only
//! variable is a pipe name the service itself generated — which is what keeps
//! "the service runs nothing it was asked to run" true.
//!
//! # Why a session-adjusted SYSTEM token and not the user's
//!
//! The helper must run as `LocalSystem` to be allowed onto the Secure Desktop;
//! the user's own token could not reach it any more than the app can. But a
//! Session 0 process has no desktop, so the SYSTEM token is **duplicated and
//! its `TokenSessionId` set to the interactive session** before
//! `CreateProcessAsUserW` places the helper there. That is the standard way a
//! service puts a SYSTEM process onto a user's Secure Desktop — the same thing
//! AnyDesk's and TeamViewer's services do.

use std::path::Path;

use windows::core::{Error, PCWSTR, PWSTR};
use windows::Win32::Foundation::{CloseHandle, HANDLE};
use windows::Win32::Security::{
    DuplicateTokenEx, SecurityImpersonation, SetTokenInformation, TokenPrimary, TokenSessionId,
    TOKEN_ADJUST_DEFAULT, TOKEN_ADJUST_SESSIONID, TOKEN_ASSIGN_PRIMARY, TOKEN_DUPLICATE,
    TOKEN_QUERY,
};
use windows::Win32::System::Pipes::{GetNamedPipeClientProcessId, GetNamedPipeClientSessionId};
use windows::Win32::System::RemoteDesktop::WTSGetActiveConsoleSessionId;
use windows::Win32::System::Threading::{
    CreateProcessAsUserW, GetCurrentProcess, OpenProcess, OpenProcessToken,
    QueryFullProcessImageNameW, TerminateProcess, CREATE_NO_WINDOW, PROCESS_INFORMATION,
    PROCESS_NAME_WIN32, PROCESS_QUERY_LIMITED_INFORMATION, STARTUPINFOW,
};

use super::ClientFacts;

/// "No console session" — the value `WTSGetActiveConsoleSessionId` returns when
/// nobody is logged on at the physical console.
const NO_SESSION: u32 = 0xFFFF_FFFF;

/// A NUL-terminated wide string.
fn wide(value: &str) -> Vec<u16> {
    value.encode_utf16().chain(std::iter::once(0)).collect()
}

/// The machine's active console session, or `None` when there is not one.
#[must_use]
pub fn console_session_id() -> Option<u32> {
    // SAFETY: the call takes no arguments and cannot fail; it returns a session
    // id or the sentinel for "none".
    let id = unsafe { WTSGetActiveConsoleSessionId() };

    (id != NO_SESSION).then_some(id)
}

/// Learn, from the pipe a request arrived on, whether the client is our
/// installed app in the console session.
///
/// Both facts are read here and handed to [`super::authorise`] as plain bools,
/// so the decision stays pure. Any failure reads as `false` — the safe reading
/// — rather than being treated as a pass.
#[must_use]
pub fn client_facts(pipe: HANDLE, install_dir: &Path) -> ClientFacts {
    let Some(console) = console_session_id() else {
        return ClientFacts::default();
    };

    // SAFETY: `pipe` is the connected server handle owned by the caller, and
    // both out-parameters are initialised locals.
    let mut client_session = 0_u32;
    let in_console_session = unsafe { GetNamedPipeClientSessionId(pipe, &mut client_session) }
        .is_ok()
        && client_session == console;

    let is_installed_image = client_image(pipe)
        .map(|image| {
            super::image_in_install_dir(&image.to_string_lossy(), &install_dir.to_string_lossy())
        })
        .unwrap_or(false);

    ClientFacts {
        is_installed_image,
        in_console_session,
    }
}

/// The full image path of the process on the other end of the pipe.
fn client_image(pipe: HANDLE) -> Option<std::path::PathBuf> {
    // SAFETY: `pipe` is a connected server handle and `pid` is a local.
    let mut pid = 0_u32;
    unsafe { GetNamedPipeClientProcessId(pipe, &mut pid) }.ok()?;

    // SAFETY: a documented open with a read-only query right; the handle is
    // closed on every path below.
    let process = unsafe { OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, false, pid) }.ok()?;

    let mut buffer = vec![0_u16; 512];
    let mut length = buffer.len() as u32;

    // SAFETY: `process` is a live handle, `buffer` backs the PWSTR for `length`
    // units, and `length` is updated to the count actually written.
    let queried = unsafe {
        QueryFullProcessImageNameW(
            process,
            PROCESS_NAME_WIN32,
            PWSTR(buffer.as_mut_ptr()),
            &mut length,
        )
    };

    // SAFETY: the handle came from OpenProcess above and is not used again.
    unsafe {
        let _ = CloseHandle(process);
    }

    queried.ok()?;

    let path = String::from_utf16_lossy(&buffer[..length as usize]);

    Some(std::path::PathBuf::from(path))
}

/// A launched helper process. Terminating it is the backstop to the helper
/// exiting on its own when the app disconnects; dropping it closes the handle.
pub struct HelperProcess {
    handle: HANDLE,
}

// SAFETY: a process handle is a kernel object; the service moves it between its
// own threads only, and one instance is owned in one place.
unsafe impl Send for HelperProcess {}

impl HelperProcess {
    /// Ask the helper to exit, hard.
    ///
    /// The ordinary stop is the app disconnecting its pipe, which the helper
    /// notices and exits on. This is for the cases where that did not
    /// happen — the service is stopping, the session ended, the console
    /// switched — and a Secure Desktop helper that outlived its reason is
    /// exactly what must not be left running.
    pub fn terminate(&self) {
        // SAFETY: `handle` is the live process handle this type owns. A process
        // that already exited makes this a harmless no-op failure.
        unsafe {
            let _ = TerminateProcess(self.handle, 1);
        }
    }
}

impl Drop for HelperProcess {
    fn drop(&mut self) {
        // SAFETY: the handle came from `CreateProcessAsUserW` and is owned here.
        unsafe {
            let _ = CloseHandle(self.handle);
        }
    }
}

/// Launch the helper in `session_id`, on the Secure Desktop, serving `pipe`.
///
/// `exe` is this service's own path ([`std::env::current_exe`]). The command
/// line is built entirely from that and the fixed argument and the pipe name;
/// nothing a peer supplied reaches it.
pub fn launch(exe: &Path, session_id: u32, pipe: &str) -> Result<HelperProcess, Error> {
    // SAFETY: every handle opened below is closed on every path, and every
    // pointer is to a local that outlives its call. The duplicated token is a
    // primary token placed in the interactive session, which is what lets a
    // Session 0 service put a SYSTEM process onto that session's desktop.
    unsafe {
        let mut process_token = HANDLE::default();
        OpenProcessToken(
            GetCurrentProcess(),
            TOKEN_DUPLICATE
                | TOKEN_QUERY
                | TOKEN_ASSIGN_PRIMARY
                | TOKEN_ADJUST_DEFAULT
                | TOKEN_ADJUST_SESSIONID,
            &mut process_token,
        )?;

        let mut helper_token = HANDLE::default();
        let duplicated = DuplicateTokenEx(
            process_token,
            TOKEN_ASSIGN_PRIMARY
                | TOKEN_DUPLICATE
                | TOKEN_QUERY
                | TOKEN_ADJUST_DEFAULT
                | TOKEN_ADJUST_SESSIONID,
            None,
            SecurityImpersonation,
            TokenPrimary,
            &mut helper_token,
        );

        let _ = CloseHandle(process_token);
        duplicated?;

        // Place the duplicated SYSTEM token in the interactive session.
        let set = SetTokenInformation(
            helper_token,
            TokenSessionId,
            &session_id as *const u32 as *const core::ffi::c_void,
            std::mem::size_of::<u32>() as u32,
        );

        if let Err(error) = set {
            let _ = CloseHandle(helper_token);

            return Err(error);
        }

        // `"<exe>" --secure-desktop-helper <pipe>`. CreateProcessAsUserW wants a
        // mutable command-line buffer, so this is owned for the whole call.
        let command_line = format!("\"{}\" {} {pipe}", exe.display(), super::HELPER_ARGUMENT);
        let mut command_line = wide(&command_line);

        // The Secure Desktop. This is the whole point of the helper: a desktop
        // of `winsta0\winlogon` is where the UAC prompt and the lock screen
        // live, and a process started onto it can see and drive them.
        let mut desktop = wide(r"winsta0\winlogon");

        let startup = STARTUPINFOW {
            cb: std::mem::size_of::<STARTUPINFOW>() as u32,
            lpDesktop: PWSTR(desktop.as_mut_ptr()),
            ..Default::default()
        };

        let mut info = PROCESS_INFORMATION::default();

        let created = CreateProcessAsUserW(
            Some(helper_token),
            PCWSTR::null(),
            Some(PWSTR(command_line.as_mut_ptr())),
            None,
            None,
            false,
            CREATE_NO_WINDOW,
            None,
            PCWSTR::null(),
            &startup,
            &mut info,
        );

        let _ = CloseHandle(helper_token);
        created?;

        // The thread handle is not wanted; the process handle is kept so the
        // helper can be terminated as a backstop.
        if !info.hThread.is_invalid() {
            let _ = CloseHandle(info.hThread);
        }

        Ok(HelperProcess {
            handle: info.hProcess,
        })
    }
}
