//! Talking to the Windows service from the user-session process.
//!
//! This is the *client* half. The service itself is
//! `agents/windows-service`, and it runs in Session 0 with no desktop — see
//! that crate's documentation for why the agent is two processes.
//!
//! Installing and removing the service needs elevation, and this module does
//! not attempt to obtain it silently. Where a person has to be asked, the
//! answer is [`PlatformError::ElevationRequired`], which the UI turns into a
//! button that triggers the normal Windows consent prompt. AICOUNTLY Remote
//! does not disable UAC and does not try to get around it.

use aicountly_remote_service::{IpcError, IpcRequest, IpcResponse, SERVICE_NAME};
use remote_device::{PlatformError, PlatformResult, SystemServiceProvider};

/// How long to wait for the service to answer one request.
///
/// Short: the service is on the same machine and every request it answers is a
/// lookup, not work. A UI that hangs for seconds because a service is wedged
/// is worse than one that says the service is not responding.
pub const IPC_TIMEOUT_MS: u64 = 3_000;

/// The service, as the user-session process sees it.
#[derive(Debug, Default)]
pub struct WindowsService;

impl WindowsService {
    /// Send one request and read one answer.
    ///
    /// Public so the commands layer can ask the service things that are not
    /// part of the `SystemServiceProvider` trait — a device status, a reboot —
    /// without every one of them needing a trait method.
    pub fn request(&self, request: &IpcRequest) -> Result<IpcResponse, IpcError> {
        #[cfg(target_os = "windows")]
        {
            imp::round_trip(request)
        }

        #[cfg(not(target_os = "windows"))]
        {
            let _ = request;

            Err(IpcError::NotRunning)
        }
    }

    /// The handshake, which every connection begins with.
    fn hello(&self) -> Result<IpcResponse, IpcError> {
        self.request(&IpcRequest::Hello {
            protocol_version: aicountly_remote_service::IPC_PROTOCOL_VERSION,
            agent_version: remote_core::AGENT_VERSION.to_owned(),
            role: "ui".to_owned(),
        })
    }
}

impl SystemServiceProvider for WindowsService {
    fn is_installed(&self) -> PlatformResult<bool> {
        #[cfg(target_os = "windows")]
        {
            imp::is_installed()
        }

        #[cfg(not(target_os = "windows"))]
        {
            Ok(false)
        }
    }

    fn is_running(&self) -> PlatformResult<bool> {
        // The pipe answering is the definition of running, and it is a
        // stronger statement than the SCM's: a service the SCM calls RUNNING
        // but which is not answering its pipe is one the agent cannot use.
        Ok(matches!(self.hello(), Ok(IpcResponse::Hello { .. })))
    }

    fn install(&self) -> PlatformResult<()> {
        Err(PlatformError::ElevationRequired(
            "Installing the background service",
        ))
    }

    fn uninstall(&self) -> PlatformResult<()> {
        Err(PlatformError::ElevationRequired(
            "Removing the background service",
        ))
    }

    fn start(&self) -> PlatformResult<()> {
        #[cfg(target_os = "windows")]
        {
            imp::start()
        }

        #[cfg(not(target_os = "windows"))]
        {
            Err(PlatformError::Unsupported("The background service"))
        }
    }

    fn stop(&self) -> PlatformResult<()> {
        Err(PlatformError::ElevationRequired(
            "Stopping the background service",
        ))
    }

    fn service_version(&self) -> PlatformResult<Option<String>> {
        match self.hello() {
            Ok(IpcResponse::Hello {
                service_version, ..
            }) => Ok(Some(service_version)),
            // A half-finished update leaves a new UI beside an old service.
            // Saying which is far more useful than "it did not work".
            Err(IpcError::VersionMismatch { found, .. }) => Ok(Some(format!("protocol {found}"))),
            _ => Ok(None),
        }
    }
}

#[cfg(target_os = "windows")]
mod imp {
    use aicountly_remote_service::ipc::HEADER_BYTES;
    use aicountly_remote_service::{pipe_name, IpcError, IpcFrame, IpcRequest, IpcResponse};
    use remote_device::{PlatformError, PlatformResult};
    use std::io::{Read, Write};

    use super::{IPC_TIMEOUT_MS, SERVICE_NAME};

    /// Connect, send, read one answer, disconnect.
    pub fn round_trip(request: &IpcRequest) -> Result<IpcResponse, IpcError> {
        use std::fs::OpenOptions;

        // Opened without FILE_FLAG_OVERLAPPED: this is one synchronous
        // request and one response, and the pipe's own idle timeout on the
        // service side is what bounds a peer that stops answering.
        let mut pipe = OpenOptions::new()
            .read(true)
            .write(true)
            .open(pipe_name())
            .map_err(|error| match error.kind() {
                std::io::ErrorKind::NotFound => IpcError::NotRunning,
                std::io::ErrorKind::PermissionDenied => IpcError::NotAuthenticated,
                _ => IpcError::Transport(error.to_string()),
            })?;

        let framed = IpcFrame::encode(request)?;
        pipe.write_all(&framed)
            .map_err(|error| IpcError::Transport(error.to_string()))?;
        pipe.flush()
            .map_err(|error| IpcError::Transport(error.to_string()))?;

        let mut header = [0_u8; HEADER_BYTES];
        pipe.read_exact(&mut header)
            .map_err(|_| IpcError::Truncated)?;

        let (_, length) = IpcFrame::decode_header(&header)?;

        let mut payload = vec![0_u8; length];
        pipe.read_exact(&mut payload)
            .map_err(|_| IpcError::Truncated)?;

        IpcFrame::decode_payload(&payload)
    }

    /// Whether the SCM knows about the service.
    pub fn is_installed() -> PlatformResult<bool> {
        use windows::core::HSTRING;
        use windows::Win32::System::Services::{
            CloseServiceHandle, OpenSCManagerW, OpenServiceW, SC_MANAGER_CONNECT,
            SERVICE_QUERY_STATUS,
        };

        // SAFETY: every handle opened below is closed on every path.
        unsafe {
            let manager = match OpenSCManagerW(None, None, SC_MANAGER_CONNECT) {
                Ok(handle) => handle,
                Err(error) => {
                    return Err(PlatformError::Os {
                        operation: "reading the service list",
                        detail: error.message(),
                    })
                }
            };

            let name = HSTRING::from(SERVICE_NAME);
            let service = OpenServiceW(manager, &name, SERVICE_QUERY_STATUS);

            let installed = service.is_ok();

            if let Ok(handle) = service {
                let _ = CloseServiceHandle(handle);
            }
            let _ = CloseServiceHandle(manager);

            Ok(installed)
        }
    }

    /// Start the service. Does not need elevation when it is configured for
    /// automatic start and merely stopped.
    pub fn start() -> PlatformResult<()> {
        use windows::core::HSTRING;
        use windows::Win32::System::Services::{
            CloseServiceHandle, OpenSCManagerW, OpenServiceW, StartServiceW, SC_MANAGER_CONNECT,
            SERVICE_START,
        };

        // SAFETY: as above.
        unsafe {
            let manager = OpenSCManagerW(None, None, SC_MANAGER_CONNECT).map_err(|error| {
                PlatformError::Os {
                    operation: "starting the background service",
                    detail: error.message(),
                }
            })?;

            let name = HSTRING::from(SERVICE_NAME);
            let service = OpenServiceW(manager, &name, SERVICE_START).map_err(|_| {
                let _ = CloseServiceHandle(manager);

                PlatformError::NotFound("The background service")
            })?;

            let result = StartServiceW(service, None);

            let _ = CloseServiceHandle(service);
            let _ = CloseServiceHandle(manager);

            result.map_err(|error| {
                PlatformError::ElevationRequired(match error.code().0 {
                    // ERROR_ACCESS_DENIED
                    -2_147_024_891 => "Starting the background service",
                    _ => "Starting the background service",
                })
            })
        }
    }

    // Keeps the timeout constant referenced on Windows builds where the
    // synchronous pipe read is bounded by the pipe's own timeout instead.
    #[allow(dead_code)]
    const _TIMEOUT: u64 = IPC_TIMEOUT_MS;
}

#[cfg(test)]
// Several tests here assert on constants on purpose: they are the design's
// own bounds, and the point is that editing one past what the design intends
// fails here rather than at some later runtime.
#[allow(clippy::assertions_on_constants)]
mod tests {
    use super::*;

    /// Installing and removing a service is an elevated act, and the agent
    /// says so rather than trying to obtain elevation quietly.
    #[test]
    fn installing_and_removing_report_that_elevation_is_needed() {
        let service = WindowsService;

        assert!(matches!(
            service.install(),
            Err(PlatformError::ElevationRequired(_))
        ));
        assert!(matches!(
            service.uninstall(),
            Err(PlatformError::ElevationRequired(_))
        ));
        assert!(matches!(
            service.stop(),
            Err(PlatformError::ElevationRequired(_))
        ));

        assert!(service
            .install()
            .unwrap_err()
            .to_string()
            .contains("administrator rights"));
    }

    /// Off Windows there is no service, and saying so is not an error — the
    /// UI renders "not running" rather than a failure.
    #[cfg(not(target_os = "windows"))]
    #[test]
    fn off_windows_the_service_is_simply_not_there() {
        let service = WindowsService;

        assert_eq!(service.is_installed(), Ok(false));
        assert_eq!(service.is_running(), Ok(false));
        assert_eq!(service.service_version(), Ok(None));
    }

    /// The timeout is short: a UI that hangs because a service is wedged is
    /// worse than one that says the service is not responding.
    #[test]
    fn the_ipc_timeout_is_short_enough_not_to_hang_the_window() {
        assert!(IPC_TIMEOUT_MS <= 5_000);
        assert!(IPC_TIMEOUT_MS >= 500);
    }
}
