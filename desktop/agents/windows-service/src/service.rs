//! The Windows plumbing: the SCM, the named pipe, and the restart.
//!
//! Everything that *decides* anything lives in [`crate::machine`], which
//! compiles and is tested on any host. This file is the part that only makes
//! sense on Windows, and it is kept as small as that split allows.
//!
//! # Lifecycle
//!
//! ```text
//!   --install     register with the SCM, LocalSystem, automatic (delayed)
//!   --uninstall   stop, then delete
//!   (no argument) started by the SCM: run until told to stop
//! ```
//!
//! The service accepts `Stop`, `Shutdown` and `SessionChange`. Session change
//! matters because presence is about a machine being *usable*: a machine at
//! the logon screen is reachable but has no desktop to share, and saying so is
//! more honest than reporting a machine as available and then failing to
//! capture anything.
//!
//! # What this service does not do
//!
//! * **It does not start the user interface.** Windows removed interactive
//!   services, and a service that launched a window into somebody's session
//!   would be doing the thing that was removed for good reasons. The installer
//!   registers the tray application to start at sign-in; the service and the
//!   application find each other over the pipe.
//! * **It runs nothing it was asked to run.** [`crate::IpcRequest`] has no
//!   variant naming a program, and the widest effect a peer can produce is
//!   [`crate::machine::Effect::Reboot`].
//! * **It does not weaken the machine.** No UAC setting is touched, no
//!   security policy is written, and no attempt is made to reach the Secure
//!   Desktop — see `docs/desktop/WINDOWS_AGENT.md` for what that costs and
//!   why it is the right trade.

use std::ffi::OsString;
use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use windows_service::service::{
    ServiceAccess, ServiceControl, ServiceControlAccept, ServiceErrorControl, ServiceExitCode,
    ServiceInfo, ServiceStartType, ServiceState, ServiceStatus, ServiceType,
};
use windows_service::service_control_handler::{self, ServiceControlHandlerResult};
use windows_service::service_dispatcher;
use windows_service::service_manager::{ServiceManager, ServiceManagerAccess};

use crate::ipc::{IpcError, IpcFrame, IpcRequest, IpcResponse, HEADER_BYTES, MAX_FRAME_BYTES};
use crate::machine::{handle, Connection, Effect, MachineState};
use crate::pipe::{pipe_name, PipeSecurity, IDLE_TIMEOUT_SECONDS, MAX_PIPE_INSTANCES};
use crate::{SERVICE_DESCRIPTION, SERVICE_DISPLAY_NAME, SERVICE_NAME};

/// How often a quiet connection is checked for a new frame.
const POLL_INTERVAL: Duration = Duration::from_millis(120);

/// How long the SCM is told to wait for a start or a stop.
const WAIT_HINT: Duration = Duration::from_secs(10);

/// Why the service could not do something.
#[derive(Debug, thiserror::Error)]
pub enum ServiceError {
    /// An argument this binary does not take.
    #[error("unknown argument {0:?} — try --install, --uninstall or --version")]
    UnknownArgument(String),

    /// The service control manager refused.
    #[error("the service control manager refused: {0}")]
    ControlManager(#[from] windows_service::Error),

    /// A Windows call failed.
    #[error("{context}: {source}")]
    Windows {
        /// What was being attempted.
        context: &'static str,
        /// What Windows said.
        source: windows::core::Error,
    },

    /// The service's own executable could not be located.
    #[error("this executable's own path could not be determined: {0}")]
    ExecutablePath(String),

    /// The pipe's security descriptor did not check out.
    #[error("the IPC channel's permissions are wrong and it was not opened")]
    InsecurePipe,
}

impl ServiceError {
    fn windows(context: &'static str) -> impl Fn(windows::core::Error) -> Self {
        move |source| Self::Windows { context, source }
    }
}

/// Entry point from `main`.
pub fn run(argument: &str) -> Result<(), ServiceError> {
    match argument {
        "--install" => install(),
        "--uninstall" => uninstall(),
        "" | "--service" | "--run" => {
            service_dispatcher::start(SERVICE_NAME, ffi_service_main)?;

            Ok(())
        }
        other => Err(ServiceError::UnknownArgument(other.to_owned())),
    }
}

// --------------------------------------------------------------- registration

/// Register the service with the SCM.
///
/// `AutoStart` with a delayed start: the machine being reachable matters, but
/// not more than the machine finishing its boot, and a delayed start keeps the
/// agent out of the sign-in critical path.
fn install() -> Result<(), ServiceError> {
    let manager = ServiceManager::local_computer(
        None::<&str>,
        ServiceManagerAccess::CONNECT | ServiceManagerAccess::CREATE_SERVICE,
    )?;

    let executable =
        std::env::current_exe().map_err(|error| ServiceError::ExecutablePath(error.to_string()))?;

    let info = ServiceInfo {
        name: OsString::from(SERVICE_NAME),
        display_name: OsString::from(SERVICE_DISPLAY_NAME),
        service_type: ServiceType::OWN_PROCESS,
        start_type: ServiceStartType::AutoStart,
        // `Normal` rather than `Critical`: a machine must never fail to boot
        // because a remote-support agent did not start.
        error_control: ServiceErrorControl::Normal,
        executable_path: executable,
        launch_arguments: vec![],
        dependencies: vec![],
        // LocalSystem. It needs `SE_SHUTDOWN_NAME` for the restart and machine
        // scope for the DPAPI blob; it holds no user's credential and reads no
        // user's profile.
        account_name: None,
        account_password: None,
    };

    let service = manager.create_service(
        &info,
        ServiceAccess::CHANGE_CONFIG | ServiceAccess::START | ServiceAccess::QUERY_STATUS,
    )?;

    service.set_description(SERVICE_DESCRIPTION)?;
    service.set_delayed_auto_start(true)?;

    // Start it now so the machine is reachable without a reboot.
    let _ = service.start(&[] as &[&std::ffi::OsStr]);

    Ok(())
}

/// Stop the service and remove it.
///
/// Both halves, because a service that is deleted while running stays
/// registered until the next reboot — and a leftover privileged service after
/// an uninstall is exactly what an administrator will not forgive.
fn uninstall() -> Result<(), ServiceError> {
    let manager = ServiceManager::local_computer(None::<&str>, ServiceManagerAccess::CONNECT)?;

    let service = manager.open_service(
        SERVICE_NAME,
        ServiceAccess::STOP | ServiceAccess::DELETE | ServiceAccess::QUERY_STATUS,
    )?;

    // Already stopped is not a failure.
    let _ = service.stop();

    // Give it a moment to actually stop, so `delete` takes effect now rather
    // than being deferred to the next reboot.
    for _ in 0..30 {
        match service.query_status() {
            Ok(status) if status.current_state == ServiceState::Stopped => break,
            Ok(_) => std::thread::sleep(Duration::from_millis(200)),
            Err(_) => break,
        }
    }

    service.delete()?;

    Ok(())
}

// ------------------------------------------------------------------ the service

windows_service::define_windows_service!(ffi_service_main, service_main);

fn service_main(_arguments: Vec<OsString>) {
    if let Err(error) = serve() {
        // There is no console. The event log is where an administrator looks.
        tracing::error!(%error, "the AICOUNTLY Remote service stopped");
    }
}

fn status(state: ServiceState, accepted: ServiceControlAccept) -> ServiceStatus {
    ServiceStatus {
        service_type: ServiceType::OWN_PROCESS,
        current_state: state,
        controls_accepted: accepted,
        exit_code: ServiceExitCode::Win32(0),
        checkpoint: 0,
        wait_hint: WAIT_HINT,
        process_id: None,
    }
}

fn serve() -> Result<(), ServiceError> {
    let stopping = Arc::new(AtomicBool::new(false));
    let state = Arc::new(Mutex::new(MachineState::default()));

    let handler = {
        let stopping = Arc::clone(&stopping);
        let state = Arc::clone(&state);

        move |control| -> ServiceControlHandlerResult {
            match control {
                ServiceControl::Interrogate => ServiceControlHandlerResult::NoError,

                ServiceControl::Stop | ServiceControl::Shutdown => {
                    stopping.store(true, Ordering::SeqCst);
                    // The accept loop is blocked in `ConnectNamedPipe`. Opening
                    // our own pipe as a client completes it, so the loop wakes,
                    // sees the flag and returns — rather than being killed
                    // mid-write by the SCM's timeout.
                    wake_accept_loop();

                    ServiceControlHandlerResult::NoError
                }

                // Presence is about a machine being *usable*. One at the logon
                // screen is reachable and has no desktop to share, and saying
                // so beats reporting it as available and then capturing
                // nothing.
                ServiceControl::SessionChange(_) => {
                    if let Ok(mut held) = state.lock() {
                        held.active_sessions.clear();
                    }

                    ServiceControlHandlerResult::NoError
                }

                _ => ServiceControlHandlerResult::NotImplemented,
            }
        }
    };

    let handle = service_control_handler::register(SERVICE_NAME, handler)?;

    handle.set_service_status(status(
        ServiceState::Running,
        ServiceControlAccept::STOP
            | ServiceControlAccept::SHUTDOWN
            | ServiceControlAccept::SESSION_CHANGE,
    ))?;

    let presence = {
        let stopping = Arc::clone(&stopping);
        let state = Arc::clone(&state);

        std::thread::spawn(move || presence_loop(&stopping, &state))
    };

    let result = accept_loop(&stopping, &state);

    stopping.store(true, Ordering::SeqCst);
    let _ = presence.join();

    handle.set_service_status(status(ServiceState::Stopped, ServiceControlAccept::empty()))?;

    result
}

// ---------------------------------------------------------------- the pipe

mod win {
    pub use windows::core::PCWSTR;
    pub use windows::Win32::Foundation::{
        CloseHandle, LocalFree, ERROR_PIPE_CONNECTED, HANDLE, HLOCAL, LUID,
    };
    pub use windows::Win32::Security::Authorization::{
        ConvertStringSecurityDescriptorToSecurityDescriptorW, SDDL_REVISION_1,
    };
    pub use windows::Win32::Security::{
        AdjustTokenPrivileges, LookupPrivilegeValueW, LUID_AND_ATTRIBUTES, PSECURITY_DESCRIPTOR,
        SECURITY_ATTRIBUTES, SE_PRIVILEGE_ENABLED, TOKEN_ADJUST_PRIVILEGES, TOKEN_PRIVILEGES,
        TOKEN_QUERY,
    };
    pub use windows::Win32::Storage::FileSystem::{
        CreateFileW, ReadFile, WriteFile, FILE_FLAGS_AND_ATTRIBUTES, FILE_GENERIC_READ,
        FILE_GENERIC_WRITE, FILE_SHARE_NONE, OPEN_EXISTING, PIPE_ACCESS_DUPLEX,
    };
    pub use windows::Win32::System::Pipes::{
        ConnectNamedPipe, CreateNamedPipeW, DisconnectNamedPipe, PeekNamedPipe, PIPE_READMODE_BYTE,
        PIPE_REJECT_REMOTE_CLIENTS, PIPE_TYPE_BYTE, PIPE_WAIT,
    };
    pub use windows::Win32::System::Shutdown::{
        InitiateSystemShutdownExW, SHTDN_REASON_FLAG_PLANNED, SHTDN_REASON_MAJOR_APPLICATION,
        SHTDN_REASON_MINOR_MAINTENANCE,
    };
    pub use windows::Win32::System::Threading::{GetCurrentProcess, OpenProcessToken};
}

/// A NUL-terminated wide string, for a Win32 call that wants one.
fn wide(value: &str) -> Vec<u16> {
    value.encode_utf16().chain(std::iter::once(0)).collect()
}

/// A `SECURITY_ATTRIBUTES` carrying the pipe's DACL.
///
/// The descriptor is allocated by Windows and freed when this is dropped, so
/// the pipe cannot end up pointing at memory that has been reused.
struct PipeSecurityDescriptor {
    descriptor: win::PSECURITY_DESCRIPTOR,
}

impl PipeSecurityDescriptor {
    fn build() -> Result<Self, ServiceError> {
        let sddl = PipeSecurity::sddl();

        // Belt and braces. A descriptor that somehow ended up permissive fails
        // open, and nothing about the running service would look wrong.
        if PipeSecurity::grants_world_access(sddl) {
            return Err(ServiceError::InsecurePipe);
        }

        let mut descriptor = win::PSECURITY_DESCRIPTOR::default();
        let text = wide(sddl);

        // SAFETY: `text` is NUL-terminated and outlives the call; `descriptor`
        // is a valid out-pointer. Windows allocates the descriptor and this
        // type frees it in `Drop`.
        unsafe {
            win::ConvertStringSecurityDescriptorToSecurityDescriptorW(
                win::PCWSTR(text.as_ptr()),
                win::SDDL_REVISION_1,
                &mut descriptor,
                None,
            )
        }
        .map_err(ServiceError::windows("building the IPC channel's permissions"))?;

        Ok(Self { descriptor })
    }

    fn attributes(&self) -> win::SECURITY_ATTRIBUTES {
        win::SECURITY_ATTRIBUTES {
            nLength: std::mem::size_of::<win::SECURITY_ATTRIBUTES>() as u32,
            lpSecurityDescriptor: self.descriptor.0,
            bInheritHandle: false.into(),
        }
    }
}

impl Drop for PipeSecurityDescriptor {
    fn drop(&mut self) {
        if !self.descriptor.is_invalid() {
            // SAFETY: allocated by `ConvertStringSecurityDescriptorToSecurityDescriptorW`,
            // which documents `LocalFree` as the way to release it.
            unsafe {
                let _ = win::LocalFree(Some(win::HLOCAL(self.descriptor.0)));
            }
        }
    }
}

/// An owned pipe handle that disconnects and closes itself.
struct PipeInstance(win::HANDLE);

impl Drop for PipeInstance {
    fn drop(&mut self) {
        // SAFETY: the handle was created by `CreateNamedPipeW` and is not
        // closed anywhere else — this type owns it.
        unsafe {
            let _ = win::DisconnectNamedPipe(self.0);
            let _ = win::CloseHandle(self.0);
        }
    }
}

// SAFETY: a pipe handle is a kernel object; moving it to the thread that
// serves the connection is exactly what it is for, and only one thread ever
// holds a given instance.
unsafe impl Send for PipeInstance {}

/// Accept connections until the service is told to stop.
fn accept_loop(stopping: &Arc<AtomicBool>, state: &Arc<Mutex<MachineState>>) -> Result<(), ServiceError> {
    let security = PipeSecurityDescriptor::build()?;
    let name = wide(&pipe_name());
    let live = Arc::new(AtomicUsize::new(0));

    while !stopping.load(Ordering::SeqCst) {
        // A new instance per accept: the previous one is being served on its
        // own thread and must not be reused underneath it.
        let attributes = security.attributes();

        // SAFETY: `name` is NUL-terminated and outlives the call, and
        // `attributes` borrows a descriptor that outlives this loop.
        let handle = unsafe {
            win::CreateNamedPipeW(
                win::PCWSTR(name.as_ptr()),
                win::FILE_FLAGS_AND_ATTRIBUTES(win::PIPE_ACCESS_DUPLEX.0),
                win::PIPE_TYPE_BYTE
                    | win::PIPE_READMODE_BYTE
                    | win::PIPE_WAIT
                    // A pipe is reachable over SMB unless this is set. The
                    // agent's IPC channel is between two processes on one
                    // machine and has no business being reachable from another.
                    | win::PIPE_REJECT_REMOTE_CLIENTS,
                MAX_PIPE_INSTANCES,
                MAX_FRAME_BYTES as u32,
                MAX_FRAME_BYTES as u32,
                0,
                Some(&attributes),
            )
        };

        if handle.is_invalid() {
            return Err(ServiceError::Windows {
                context: "opening the IPC channel",
                source: windows::core::Error::from_thread(),
            });
        }

        let instance = PipeInstance(handle);

        // SAFETY: `instance` owns a valid pipe handle.
        let connected = unsafe { win::ConnectNamedPipe(instance.0, None) };

        // `ERROR_PIPE_CONNECTED` means the client got there first. That is a
        // connection, not a failure.
        if let Err(error) = connected {
            if error.code() != windows::core::HRESULT::from_win32(win::ERROR_PIPE_CONNECTED.0) {
                if stopping.load(Ordering::SeqCst) {
                    break;
                }

                continue;
            }
        }

        if stopping.load(Ordering::SeqCst) {
            break;
        }

        if live.load(Ordering::SeqCst) >= MAX_PIPE_INSTANCES as usize {
            // Bounded on purpose: an unbounded server is a resource-exhaustion
            // primitive for anything that satisfies the ACL. The instance is
            // dropped, which disconnects the client.
            continue;
        }

        live.fetch_add(1, Ordering::SeqCst);

        let state = Arc::clone(state);
        let stopping = Arc::clone(stopping);
        let live_for_thread = Arc::clone(&live);

        std::thread::spawn(move || {
            serve_connection(instance, &stopping, &state);
            live_for_thread.fetch_sub(1, Ordering::SeqCst);
        });
    }

    Ok(())
}

/// Open and immediately close the pipe, so a blocked accept completes.
fn wake_accept_loop() {
    let name = wide(&pipe_name());

    // SAFETY: `name` is NUL-terminated. A failure here is fine — it means
    // nothing was listening, which is the state the caller wanted anyway.
    unsafe {
        if let Ok(handle) = win::CreateFileW(
            win::PCWSTR(name.as_ptr()),
            win::FILE_GENERIC_READ.0 | win::FILE_GENERIC_WRITE.0,
            win::FILE_SHARE_NONE,
            None,
            win::OPEN_EXISTING,
            win::FILE_FLAGS_AND_ATTRIBUTES(0),
            None,
        ) {
            let _ = win::CloseHandle(handle);
        }
    }
}

/// Serve one connected client until it goes away or falls silent.
fn serve_connection(
    instance: PipeInstance,
    stopping: &Arc<AtomicBool>,
    state: &Arc<Mutex<MachineState>>,
) {
    let mut connection = Connection::default();
    let mut last_heard = Instant::now();

    while !stopping.load(Ordering::SeqCst) {
        match peek(instance.0) {
            // The client went away.
            Err(()) => return,

            Ok(0) => {
                // A UI that crashed leaves its end open until Windows notices.
                // The heartbeat is what turns that into a closed instance
                // rather than one of eight slots held for ever.
                if last_heard.elapsed() >= Duration::from_secs(IDLE_TIMEOUT_SECONDS) {
                    return;
                }

                std::thread::sleep(POLL_INTERVAL);

                continue;
            }

            Ok(_) => {}
        }

        last_heard = Instant::now();

        let request = match read_frame(instance.0) {
            Ok(request) => request,
            Err(error) => {
                // A malformed frame is answered once and the connection is
                // dropped: a peer that cannot frame a message is not a peer to
                // keep reading from.
                let _ = write_message(
                    instance.0,
                    &IpcResponse::Error {
                        code: "BAD_FRAME".into(),
                        message: error.to_string(),
                    },
                );

                return;
            }
        };

        let (response, effect) = {
            let mut held = match state.lock() {
                Ok(held) => held,
                // A poisoned lock means another thread panicked while holding
                // it. Serving from state nobody can vouch for is worse than
                // dropping the connection.
                Err(_) => return,
            };

            handle(&mut held, &mut connection, request, monotonic_seconds())
        };

        if write_message(instance.0, &response).is_err() {
            return;
        }

        match effect {
            Some(Effect::Reboot { reason, .. }) => {
                if let Err(error) = restart_machine(&reason) {
                    tracing::error!(%error, "the restart was refused by Windows");
                }
            }
            Some(Effect::Authenticate) | None => {}
        }
    }
}

/// How many bytes are waiting, or `Err` when the client has gone.
fn peek(handle: win::HANDLE) -> Result<u32, ()> {
    let mut available = 0_u32;

    // SAFETY: `handle` is a connected pipe owned by the caller, and
    // `available` is a valid out-pointer.
    let result = unsafe {
        win::PeekNamedPipe(handle, None, 0, None, Some(&mut available), None)
    };

    match result {
        Ok(()) => Ok(available),
        Err(_) => Err(()),
    }
}

/// Read exactly `wanted` bytes, or fail.
fn read_exact(handle: win::HANDLE, wanted: usize) -> Result<Vec<u8>, IpcError> {
    let mut buffer = vec![0_u8; wanted];
    let mut filled = 0;

    while filled < wanted {
        let mut read = 0_u32;

        // SAFETY: `buffer` is owned here and `filled < wanted <= buffer.len()`.
        unsafe { win::ReadFile(handle, Some(&mut buffer[filled..]), Some(&mut read), None) }
            .map_err(|error| IpcError::Transport(error.message()))?;

        if read == 0 {
            return Err(IpcError::Truncated);
        }

        filled += read as usize;
    }

    Ok(buffer)
}

/// Read one framed request.
fn read_frame(handle: win::HANDLE) -> Result<IpcRequest, IpcError> {
    let header = read_exact(handle, HEADER_BYTES)?;
    // The length is checked against the ceiling before a byte is allocated.
    let (_, length) = IpcFrame::decode_header(&header)?;
    let payload = read_exact(handle, length)?;

    IpcFrame::decode_payload(&payload)
}

/// Write one framed response.
fn write_message(handle: win::HANDLE, response: &IpcResponse) -> Result<(), IpcError> {
    let bytes = IpcFrame::encode(response)?;
    let mut written = 0_u32;

    // SAFETY: `bytes` outlives the call and `handle` is a connected pipe.
    unsafe { win::WriteFile(handle, Some(&bytes), Some(&mut written), None) }
        .map_err(|error| IpcError::Transport(error.message()))?;

    Ok(())
}

/// Seconds since the process started, for the restart cooldown.
fn monotonic_seconds() -> u64 {
    static START: std::sync::OnceLock<Instant> = std::sync::OnceLock::new();

    START.get_or_init(Instant::now).elapsed().as_secs()
}

// ----------------------------------------------------------------- restart

/// Restart the machine.
///
/// Authorised long before this: the company policy switch, the permission and
/// an active control grant were all checked by the API, the audit entry is
/// already written, and [`crate::machine::handle`] refused it for a session
/// this service was never told about.
///
/// The grace period is not zero. Somebody may have walked up to the machine
/// between the request and the restart, and Windows' own notice is what tells
/// them what is about to happen.
fn restart_machine(reason: &str) -> Result<(), ServiceError> {
    enable_shutdown_privilege()?;

    let message = wide(reason);

    // SAFETY: both strings are NUL-terminated and outlive the call.
    unsafe {
        win::InitiateSystemShutdownExW(
            win::PCWSTR::null(),
            win::PCWSTR(message.as_ptr()),
            RESTART_GRACE_SECONDS,
            // Never force applications closed. Somebody's unsaved work is not
            // this service's to discard.
            false,
            true,
            win::SHTDN_REASON_MAJOR_APPLICATION
                | win::SHTDN_REASON_MINOR_MAINTENANCE
                | win::SHTDN_REASON_FLAG_PLANNED,
        )
    }
    .map_err(ServiceError::windows("restarting this computer"))
}

/// How long Windows shows its own notice before restarting.
///
/// Mirrors `src-tauri/src/platform/windows/power.rs`, which is where the UI
/// tells a person what will happen.
pub const RESTART_GRACE_SECONDS: u32 = 30;

/// Enable `SeShutdownPrivilege` on this process's token.
///
/// `LocalSystem` holds the privilege but Windows does not enable it by
/// default, and `InitiateSystemShutdownExW` fails with `ERROR_ACCESS_DENIED`
/// until it is. Enabling a privilege the account already holds is not an
/// escalation.
fn enable_shutdown_privilege() -> Result<(), ServiceError> {
    // SAFETY: every pointer below is to a local that outlives its call, and
    // the token handle is closed before returning.
    unsafe {
        let mut token = win::HANDLE::default();

        win::OpenProcessToken(
            win::GetCurrentProcess(),
            win::TOKEN_ADJUST_PRIVILEGES | win::TOKEN_QUERY,
            &mut token,
        )
        .map_err(ServiceError::windows("opening this process's token"))?;

        let name = wide("SeShutdownPrivilege");
        let mut luid = win::LUID::default();

        let looked_up = win::LookupPrivilegeValueW(
            win::PCWSTR::null(),
            win::PCWSTR(name.as_ptr()),
            &mut luid,
        );

        let result = looked_up.and_then(|()| {
            let privileges = win::TOKEN_PRIVILEGES {
                PrivilegeCount: 1,
                Privileges: [win::LUID_AND_ATTRIBUTES {
                    Luid: luid,
                    Attributes: win::SE_PRIVILEGE_ENABLED,
                }],
            };

            win::AdjustTokenPrivileges(token, false, Some(&privileges), 0, None, None)
        });

        let _ = win::CloseHandle(token);

        result.map_err(ServiceError::windows("enabling the restart privilege"))
    }
}

// ---------------------------------------------------------------- presence

/// Keep saying the machine is there.
///
/// Bounded exponential backoff with jitter, and it never gives up except on a
/// revoked device: a machine that stops reporting because a network blipped
/// during a night is a machine somebody cannot help in the morning.
fn presence_loop(stopping: &Arc<AtomicBool>, state: &Arc<Mutex<MachineState>>) {
    let config = remote_core::AgentConfig::load();

    let runtime = match tokio::runtime::Builder::new_current_thread().enable_all().build() {
        Ok(runtime) => runtime,
        Err(error) => {
            tracing::error!(%error, "the presence loop could not start");

            return;
        }
    };

    let mut backoff = remote_core::Backoff::default();
    let interval = Duration::from_secs(config.presence_interval_seconds);

    let Ok(mut client) = remote_core::ApiClient::new(config) else {
        tracing::error!("the API client could not be built; presence will not be reported");

        return;
    };

    while !stopping.load(Ordering::SeqCst) {
        let outcome = runtime.block_on(async {
            // Without a credential there is nothing to report with. The UI is
            // what enrols; until it has, the machine is simply not registered.
            if client.credential().is_none() {
                return Err(remote_core::ApiError::NoCredential);
            }

            client.report_presence(true).await
        });

        match outcome {
            Ok(()) => {
                backoff.reset();

                if let Ok(mut held) = state.lock() {
                    held.online = true;
                }

                sleep_interruptibly(stopping, interval);
            }

            Err(error) if error.is_device_rejected() => {
                // Revoked or no longer accepted. Stopping is the correct
                // behaviour: retrying for ever would look like a bug and would
                // keep a removed machine knocking on the API.
                tracing::warn!("this device is no longer accepted; presence stopped");

                if let Ok(mut held) = state.lock() {
                    held.online = false;
                    held.enrolled = false;
                }

                client.clear_credential();

                return;
            }

            Err(_) => {
                if let Ok(mut held) = state.lock() {
                    held.online = false;
                }

                sleep_interruptibly(stopping, backoff.next_delay());
            }
        }
    }
}

/// Sleep, but wake within a poll interval of being told to stop.
fn sleep_interruptibly(stopping: &Arc<AtomicBool>, total: Duration) {
    let deadline = Instant::now() + total;

    while Instant::now() < deadline {
        if stopping.load(Ordering::SeqCst) {
            return;
        }

        std::thread::sleep(POLL_INTERVAL);
    }
}
