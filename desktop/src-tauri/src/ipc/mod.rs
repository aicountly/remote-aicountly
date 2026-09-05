//! Talking to the background service from this process.
//!
//! A thin wrapper over `agents/windows-service`'s protocol, so the commands
//! layer asks questions in its own vocabulary ("is the service there?", "what
//! device is this?") rather than assembling frames.
//!
//! Every method here fails softly. The service not running is a *normal*
//! state — during an update, before the installer has finished, on a machine
//! where somebody stopped it — and the window has to render something useful
//! in it rather than an error dialog.

use aicountly_remote_service::{IpcError, IpcRequest, IpcResponse};

#[cfg(target_os = "windows")]
use crate::platform;

/// What the service knows about this machine.
#[derive(Debug, Clone, PartialEq, Eq, serde::Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ServiceStatus {
    /// Whether the service answered at all.
    pub running: bool,
    /// Its version, when it did.
    pub version: Option<String>,
    /// Whether it holds a device key for this machine.
    pub enrolled: bool,
    /// The device's uuid, when it does.
    pub device_uuid: Option<String>,
    /// The public key fingerprint, so the window shows what the console shows.
    pub key_fingerprint: Option<String>,
    /// Whether unattended access is on.
    pub unattended_enabled: bool,
    /// What went wrong, when something did — for the diagnostics panel.
    pub detail: Option<String>,
}

impl ServiceStatus {
    /// The answer when the service is not there.
    #[must_use]
    pub fn not_running(detail: Option<String>) -> Self {
        Self {
            running: false,
            version: None,
            enrolled: false,
            device_uuid: None,
            key_fingerprint: None,
            unattended_enabled: false,
            detail,
        }
    }
}

/// Ask the service what it knows.
#[must_use]
pub fn service_status() -> ServiceStatus {
    let service = platform_service();

    let version = match service.request(&IpcRequest::Hello {
        protocol_version: aicountly_remote_service::IPC_PROTOCOL_VERSION,
        agent_version: crate::VERSION.to_owned(),
        role: "ui".to_owned(),
    }) {
        Ok(IpcResponse::Hello { service_version, .. }) => service_version,
        // A half-finished update leaves a new UI beside an old service.
        // Naming that is far more useful than "the service is not running".
        Err(IpcError::VersionMismatch { expected, found }) => {
            return ServiceStatus::not_running(Some(format!(
                "The background service is still on protocol {found}; this version speaks {expected}. \
                 Restarting the computer finishes the update."
            )))
        }
        Err(error) => return ServiceStatus::not_running(Some(error.to_string())),
        Ok(_) => return ServiceStatus::not_running(Some("unexpected answer".into())),
    };

    match service.request(&IpcRequest::DeviceStatus) {
        Ok(IpcResponse::DeviceStatus {
            enrolled,
            device_uuid,
            key_fingerprint,
            unattended_enabled,
        }) => ServiceStatus {
            running: true,
            version: Some(version),
            enrolled,
            device_uuid,
            key_fingerprint,
            unattended_enabled,
            detail: None,
        },
        Ok(IpcResponse::Error { message, .. }) => ServiceStatus {
            running: true,
            version: Some(version),
            detail: Some(message),
            ..ServiceStatus::not_running(None)
        },
        _ => ServiceStatus {
            running: true,
            version: Some(version),
            ..ServiceStatus::not_running(None)
        },
    }
}

/// Tell the service a session started, so its presence stays in step.
pub fn session_started(session_uuid: &str) -> Result<(), IpcError> {
    expect_acknowledged(platform_service().request(&IpcRequest::SessionStarted {
        session_uuid: session_uuid.to_owned(),
    }))
}

/// Tell the service a session ended.
pub fn session_ended(session_uuid: &str) -> Result<(), IpcError> {
    expect_acknowledged(platform_service().request(&IpcRequest::SessionEnded {
        session_uuid: session_uuid.to_owned(),
    }))
}

/// Ask the service to restart the machine.
///
/// The session that authorised it travels with the request, and the service
/// checks it again before doing anything — this process is not trusted to have
/// checked, because it is the one exposed to the network.
pub fn reboot(session_uuid: &str, reason: &str) -> Result<(), IpcError> {
    expect_acknowledged(platform_service().request(&IpcRequest::Reboot {
        session_uuid: session_uuid.to_owned(),
        reason: reason.to_owned(),
    }))
}

fn expect_acknowledged(result: Result<IpcResponse, IpcError>) -> Result<(), IpcError> {
    match result? {
        IpcResponse::Acknowledged => Ok(()),
        IpcResponse::Error { message, .. } => Err(IpcError::Transport(message)),
        _ => Err(IpcError::Malformed),
    }
}

#[cfg(target_os = "windows")]
fn platform_service() -> platform::windows::service::WindowsService {
    platform::windows::service::WindowsService
}

#[cfg(not(target_os = "windows"))]
fn platform_service() -> NoService {
    NoService
}

/// The client on a platform with no service.
#[cfg(not(target_os = "windows"))]
pub struct NoService;

#[cfg(not(target_os = "windows"))]
impl NoService {
    fn request(&self, _request: &IpcRequest) -> Result<IpcResponse, IpcError> {
        Err(IpcError::NotRunning)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The service not running is a normal state, and the window has to render
    /// something useful in it.
    #[test]
    fn a_missing_service_produces_a_status_rather_than_an_error() {
        let status = ServiceStatus::not_running(Some("the service is not running".into()));

        assert!(!status.running);
        assert!(!status.enrolled);
        assert!(!status.unattended_enabled);
        assert!(status.detail.is_some());
    }

    /// A half-finished update leaves a new UI beside an old service. Saying
    /// which is far more useful than "the service is not running".
    #[test]
    fn a_protocol_mismatch_says_what_to_do_about_it() {
        // Off Windows there is no pipe, so this exercises the shape of the
        // message rather than the round trip.
        let status = ServiceStatus::not_running(Some(
            "The background service is still on protocol 1; this version speaks 2. \
             Restarting the computer finishes the update."
                .into(),
        ));

        let detail = status.detail.expect("carries a reason");
        assert!(detail.contains("Restarting the computer"));
    }

    #[cfg(not(target_os = "windows"))]
    #[test]
    fn off_windows_every_call_fails_softly() {
        assert!(!service_status().running);
        assert_eq!(session_started("s"), Err(IpcError::NotRunning));
        assert_eq!(session_ended("s"), Err(IpcError::NotRunning));
        assert_eq!(reboot("s", "why"), Err(IpcError::NotRunning));
    }

    #[test]
    fn the_status_crosses_the_tauri_boundary_as_camel_case() {
        let json = serde_json::to_string(&ServiceStatus::not_running(None)).unwrap();

        assert!(json.contains("deviceUuid"));
        assert!(json.contains("keyFingerprint"));
        assert!(json.contains("unattendedEnabled"));
    }
}
