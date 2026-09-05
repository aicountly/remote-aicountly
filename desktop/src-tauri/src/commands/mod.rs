//! The Tauri commands — the only surface the window can reach.
//!
//! Deliberately small, and deliberately not a passthrough. There is no
//! `invoke("run", ...)`, no command that takes a path or a program name, and
//! no command that returns a secret. Every one of these does one named thing.
//!
//! The `capabilities/` directory beside this one is the other half: Tauri's
//! own allowlist, which decides what the web layer may call at all. Both have
//! to permit a command for it to be reachable.

use std::sync::Arc;

use remote_core::AgentConfig;
use remote_device::PermissionSummary;
use serde::Serialize;

use crate::{ipc, platform, Agent};

/// What the About panel shows.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct About {
    /// The product name.
    pub product: String,
    /// This build.
    pub version: String,
    /// `Windows`, `macOS`, or `unsupported`.
    pub platform: String,
    /// Whether this build has a real platform implementation.
    pub supported: bool,
    /// Where the device key is kept — "Windows DPAPI (machine scope)".
    ///
    /// The store's *name*, never anything in it. A support engineer needs to
    /// know where the key lives; nobody needs the key.
    pub key_storage: String,
    /// The background service, as this process sees it.
    pub service: ipc::ServiceStatus,
}

/// The state the window renders from.
#[tauri::command]
pub fn get_state(agent: tauri::State<'_, Arc<Agent>>) -> remote_core::AgentState {
    agent.state()
}

/// What the machine currently permits.
#[tauri::command]
pub fn get_permissions(agent: tauri::State<'_, Arc<Agent>>) -> PermissionSummary {
    agent.permissions()
}

/// The settings the person can change.
#[tauri::command]
pub fn get_configuration(agent: tauri::State<'_, Arc<Agent>>) -> AgentConfig {
    agent.config()
}

/// Save the settings, after validating them.
#[tauri::command]
pub fn save_configuration(
    agent: tauri::State<'_, Arc<Agent>>,
    config: AgentConfig,
) -> Result<AgentConfig, String> {
    agent
        .set_config(config)
        .map_err(|error| error.to_string())?;

    Ok(agent.config())
}

/// Register this machine.
///
/// The keypair is generated here and the private half goes straight into the
/// operating system's key store. What crosses back into the web layer is the
/// **public** key, which is what the enrolment call sends — there is no
/// command that returns a private key, and adding one would be the only way to
/// get it out of this process.
#[tauri::command]
pub fn enrol_device(agent: tauri::State<'_, Arc<Agent>>) -> Result<EnrolmentMaterial, String> {
    let public_key = agent
        .create_device_key()
        .map_err(|error| error.to_string())?;
    let description = agent.describe().map_err(|error| error.to_string())?;

    Ok(EnrolmentMaterial {
        public_key,
        host_name: description.host_name,
        operating_system: description.operating_system,
        os_version: description.os_version,
        architecture: description.architecture,
        agent_version: description.agent_version,
        capabilities: agent.capabilities(),
    })
}

/// Everything the enrolment call needs, and nothing else.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct EnrolmentMaterial {
    /// The **public** half of the device keypair.
    pub public_key: String,
    /// The machine's name.
    pub host_name: String,
    /// `Windows`.
    pub operating_system: String,
    /// `11 24H2`.
    pub os_version: String,
    /// `x86_64`.
    pub architecture: String,
    /// This build.
    pub agent_version: String,
    /// What the software can do, already constrained by what the machine
    /// permits. The server intersects it with policy on top.
    pub capabilities: remote_device::AgentCapabilities,
}

/// Remove this machine's device key.
///
/// The local half of unregistering. The web layer calls the API's revoke
/// endpoint as well, and both happen — a key left on a machine whose device
/// row was revoked authenticates nothing but is still there.
#[tauri::command]
pub fn unregister_device(
    agent: tauri::State<'_, Arc<Agent>>,
) -> Result<remote_core::AgentState, String> {
    agent
        .forget_device_key()
        .map_err(|error| error.to_string())?;

    Ok(agent.apply(remote_core::AgentEvent::EnrolmentRemoved))
}

/// Record that unattended access was switched on.
///
/// The *decision* is the API's — its own endpoint, its own permission, its own
/// confirmation, its own audit event. This only brings the window's state into
/// line with what the server said.
#[tauri::command]
pub fn enable_unattended(
    agent: tauri::State<'_, Arc<Agent>>,
    enabled_at: Option<String>,
) -> remote_core::AgentState {
    agent.set_unattended(remote_core::UnattendedState {
        enabled: true,
        enabled_at,
        last_used_at: None,
        allowed_by_policy: true,
    })
}

/// Record that unattended access was switched off.
#[tauri::command]
pub fn disable_unattended(agent: tauri::State<'_, Arc<Agent>>) -> remote_core::AgentState {
    agent.set_unattended(remote_core::UnattendedState {
        enabled: false,
        ..remote_core::UnattendedState::default()
    })
}

/// The person at the machine says yes to control.
#[tauri::command]
pub fn grant_control(
    agent: tauri::State<'_, Arc<Agent>>,
    participant_uuid: String,
    clipboard: bool,
) -> remote_core::AgentState {
    agent.grant_control(&participant_uuid, clipboard)
}

/// The person at the machine says no.
#[tauri::command]
pub fn deny_control(agent: tauri::State<'_, Arc<Agent>>) -> remote_core::AgentState {
    agent.deny_control()
}

/// **Stop control.** Local, immediate, and needing nothing from anybody.
///
/// The API is told afterwards by the web layer. If that call fails, the
/// machine is still not being controlled — which is the whole reason the gate
/// is local.
#[tauri::command]
pub fn stop_control(agent: tauri::State<'_, Arc<Agent>>) -> remote_core::AgentState {
    agent.stop_control()
}

/// End the session.
#[tauri::command]
pub fn end_session(agent: tauri::State<'_, Arc<Agent>>) -> remote_core::AgentState {
    if let Some(session) = agent.state().active_session() {
        let _ = ipc::session_ended(&session.session_uuid);
    }

    agent.end_session()
}

/// Open a URL in the person's own browser.
///
/// **An allowlist, not a passthrough.** The sign-in flow and the "manage this
/// device" link both need to open a browser, and a command that opened
/// anything would be a way to launch a local file or a `file://` path from the
/// web layer.
#[tauri::command]
pub fn open_url(agent: tauri::State<'_, Arc<Agent>>, url: String) -> Result<(), String> {
    let config = agent.config();

    if !is_permitted_url(&url, &config) {
        return Err("That address is not one AICOUNTLY Remote opens.".into());
    }

    open::that(&url).map_err(|error| error.to_string())
}

/// Whether a URL is one the agent will open.
///
/// `https` only, and only under an origin this deployment was configured with.
/// Pulled out so the rule is tested rather than trusted.
#[must_use]
pub fn is_permitted_url(url: &str, config: &AgentConfig) -> bool {
    if !url.starts_with("https://") {
        return false;
    }

    // Compared as origins with an explicit boundary, so
    // `https://my.aicountly.com.attacker.example` does not match
    // `https://my.aicountly.com`.
    let permitted = [config.portal_url.as_str(), config.api_base_url.as_str()];

    permitted.iter().any(|origin| {
        let origin = origin.trim_end_matches('/');

        url == origin
            || url.starts_with(&format!("{origin}/"))
            || url.starts_with(&format!("{origin}?"))
    })
}

/// What the About panel shows.
#[tauri::command]
pub fn about(agent: tauri::State<'_, Arc<Agent>>) -> About {
    let _ = agent;

    About {
        product: crate::PRODUCT_NAME.to_owned(),
        version: crate::VERSION.to_owned(),
        platform: platform::platform_name().to_owned(),
        supported: platform::is_supported(),
        key_storage: platform::secure_storage().describe().to_owned(),
        service: ipc::service_status(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn config() -> AgentConfig {
        AgentConfig::default()
    }

    /// A command that opened anything would be a way to launch a local file
    /// from the web layer.
    #[test]
    fn only_https_urls_under_a_configured_origin_are_opened() {
        assert!(is_permitted_url("https://my.aicountly.com", &config()));
        assert!(is_permitted_url(
            "https://my.aicountly.com/login",
            &config()
        ));
        assert!(is_permitted_url(
            "https://remote.aicountly.com/api/v1/remote/devices",
            &config()
        ));
    }

    #[test]
    fn a_local_file_or_a_program_is_never_opened() {
        for candidate in [
            "file:///C:/Windows/System32/cmd.exe",
            r"C:\Windows\System32\cmd.exe",
            "cmd.exe",
            "javascript:alert(1)",
            "ms-settings:",
            "http://my.aicountly.com",
            "",
        ] {
            assert!(
                !is_permitted_url(candidate, &config()),
                "{candidate:?} must not be opened"
            );
        }
    }

    /// `startsWith` on a bare origin is how
    /// `https://my.aicountly.com.attacker.example` gets let in.
    #[test]
    fn a_lookalike_origin_does_not_match() {
        for candidate in [
            "https://my.aicountly.com.attacker.example/login",
            "https://my.aicountly.com-attacker.example",
            "https://remote.aicountly.com.evil.test/api",
        ] {
            assert!(
                !is_permitted_url(candidate, &config()),
                "{candidate:?} must not be opened"
            );
        }
    }

    /// There is no command that returns a private key, and the enrolment
    /// material is the reason there does not need to be one.
    #[test]
    fn the_enrolment_material_carries_only_the_public_half() {
        let material = EnrolmentMaterial {
            public_key: "cHVibGlj".into(),
            host_name: "WS-01".into(),
            operating_system: "Windows".into(),
            os_version: "11 24H2".into(),
            architecture: "x86_64".into(),
            agent_version: "1.0.0".into(),
            capabilities: remote_device::AgentCapabilities::windows(),
        };

        let json = serde_json::to_string(&material).unwrap().to_lowercase();

        assert!(json.contains("publickey"));
        assert!(!json.contains("privatekey"));
        assert!(!json.contains("secret"));
        assert!(!json.contains("token"));
    }

    /// The store's name, never anything in it.
    #[test]
    fn the_about_panel_names_the_key_store_and_nothing_in_it() {
        let name = platform::secure_storage().describe();

        assert!(!name.is_empty());
        assert!(!name.to_lowercase().contains("key ="));
    }
}
