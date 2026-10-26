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
use remote_security::EnrolmentRecord;
use serde::{Deserialize, Serialize};

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
    /// Whether this build has a real platform implementation — either role.
    pub supported: bool,
    /// Whether it can host a session: share this screen and be controlled.
    pub supports_hosting: bool,
    /// Whether it can be its owner's control companion for a browser share.
    pub supports_companion: bool,
    /// The system-wide Stop control shortcut, as this platform writes it.
    pub stop_shortcut: String,
    /// Where the device key is kept — "Windows DPAPI (machine scope)".
    ///
    /// The store's *name*, never anything in it. A support engineer needs to
    /// know where the key lives; nobody needs the key.
    pub key_storage: String,
    /// Where this process is writing its log, if it has one.
    ///
    /// The *path*, so a support engineer can be asked for the file. There is
    /// no command that returns its contents: a log is read by the person who
    /// owns the machine, not shipped anywhere by the agent.
    pub log_file: Option<String>,
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

/// What the window reports back once the server has accepted this machine's
/// enrolment.
#[derive(Debug, Clone, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct EnrolmentConfirmation {
    /// The uuid the API issued.
    pub device_uuid: String,
    /// Which organisation.
    pub company_id: i64,
    /// Its name, for the "Organisation" row. The API's device resource
    /// carries only the id, so the window supplies the name it already
    /// showed in the registration form.
    pub company_name: Option<String>,
    /// What the person named this machine.
    pub device_name: String,
}

/// Record that the server accepted this machine's enrolment.
///
/// `enrol_device` already created the keypair, and the window's own call to
/// `POST /devices/enrol` already succeeded — a device row exists on the
/// server whether or not this call is ever made. What this brings into line
/// is the **running agent**: without it, `AgentState.deviceUuid` stays `None`
/// forever, the window keeps showing "Not registered", and the connection
/// loop's `let Some(device_uuid) = state.device_uuid.clone() else { continue }`
/// never lets it attempt to authenticate — even though the key this call
/// fingerprints is already sitting in secure storage.
///
/// The fingerprint shown is computed fresh from the key this machine actually
/// stored, never taken from anything the caller claims, so what a person
/// compares against the console can never drift from what is real.
#[tauri::command]
pub fn confirm_enrolment(
    agent: tauri::State<'_, Arc<Agent>>,
    confirmation: EnrolmentConfirmation,
) -> Result<remote_core::AgentState, String> {
    let key_fingerprint = agent
        .device_key()
        .map_err(|error| error.to_string())?
        .display_fingerprint();

    let record = EnrolmentRecord {
        device_uuid: confirmation.device_uuid,
        company_id: confirmation.company_id,
        company_name: confirmation.company_name,
        device_name: confirmation.device_name,
        key_fingerprint,
        api_base_url: agent.config().api_base_url,
        enrolled_at: now_iso(),
    };

    let state = agent.record_enrolment(&record);

    // The service holds its own credential and reports presence with it, so
    // that a registered machine is reachable before anybody signs in. It
    // reads the record this call just wrote — telling it to look now is what
    // turns "registered" into "reachable" without waiting for its next
    // interval. Fail-soft: the service not running is a normal state, and the
    // record on disk is what matters.
    if let Err(error) = ipc::authenticate() {
        tracing::debug!(%error, "the background service was not told to authenticate");
    }

    Ok(state)
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

/// Yes: let people in this session control this computer while its owner
/// shares it. Picked up by the connection loop, which joins.
#[tauri::command]
pub fn allow_companion(
    agent: tauri::State<'_, Arc<Agent>>,
    companion_uuid: String,
) -> remote_core::AgentState {
    agent.decide_companion(&companion_uuid, true);

    agent.state()
}

/// No. The connection loop tells the API; the question leaves the screen now.
#[tauri::command]
pub fn decline_companion(
    agent: tauri::State<'_, Arc<Agent>>,
    companion_uuid: String,
) -> remote_core::AgentState {
    agent.decide_companion(&companion_uuid, false);

    agent.companion_dismissed(&companion_uuid)
}

/// The person picked which monitor they are sharing.
#[tauri::command]
pub fn choose_companion_monitor(
    agent: tauri::State<'_, Arc<Agent>>,
    companion_uuid: String,
    monitor_id: u32,
) -> remote_core::AgentState {
    agent.choose_companion_monitor(&companion_uuid, monitor_id);

    agent.state()
}

/// Stop being this session's control companion. Local, and needing nobody.
#[tauri::command]
pub fn leave_companion(agent: tauri::State<'_, Arc<Agent>>) -> remote_core::AgentState {
    // Control stops at once; leaving the link follows in the loop.
    if agent.state().is_being_controlled() {
        agent.stop_control();
    }

    agent.leave_companion();

    agent.state()
}

/// Ask macOS to prompt for a permission (Accessibility). Returns what the
/// machine permits afterwards — usually unchanged until the person has acted
/// in System Settings.
#[tauri::command]
pub fn request_permission(
    agent: tauri::State<'_, Arc<Agent>>,
    permission: String,
) -> Result<PermissionSummary, String> {
    agent
        .request_permission(&permission)
        .map_err(|error| error.to_string())?;

    Ok(agent.permissions())
}

/// Put each monitor's name on the monitor itself for a few seconds, so the
/// person choosing which one they share can see which is which.
///
/// Async because creating a window from a synchronous command deadlocks on
/// Windows.
#[tauri::command]
pub async fn identify_monitors(
    app: tauri::AppHandle,
    agent: tauri::State<'_, Arc<Agent>>,
) -> Result<(), String> {
    let layout = agent.monitor_layout().map_err(|error| error.to_string())?;

    for monitor in &layout.monitors {
        show_identify(&app, monitor);
    }

    Ok(())
}

/// How long an Identify label stays up.
const IDENTIFY_SECONDS: u64 = 4;

/// The Identify label's size, in logical units.
const IDENTIFY_SIZE: (f64, f64) = (420.0, 220.0);

fn show_identify(app: &tauri::AppHandle, monitor: &remote_protocol::Monitor) {
    use tauri::Manager;

    let label = format!("identify-{}", monitor.id);

    if let Some(existing) = app.get_webview_window(&label) {
        let _ = existing.close();
    }

    let url = tauri::WebviewUrl::App(
        format!("index.html#identify={}", fragment_encode(&monitor.name)).into(),
    );

    let Ok(window) = tauri::WebviewWindowBuilder::new(app, &label, url)
        .title("AICOUNTLY Remote")
        .decorations(false)
        .always_on_top(true)
        .resizable(false)
        .skip_taskbar(true)
        .focused(false)
        .visible(false)
        .inner_size(IDENTIFY_SIZE.0, IDENTIFY_SIZE.1)
        .build()
    else {
        return;
    };

    let (x, y) = identify_position(monitor, IDENTIFY_SIZE);

    // The monitor is described in the platform's injection space: points on
    // macOS, which Tauri calls logical; physical pixels on Windows.
    #[cfg(target_os = "macos")]
    let _ = window.set_position(tauri::LogicalPosition::new(x, y));
    #[cfg(not(target_os = "macos"))]
    let _ = window.set_position(tauri::PhysicalPosition::new(x, y));

    let _ = window.show();

    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(std::time::Duration::from_secs(IDENTIFY_SECONDS)).await;
        let _ = window.close();
    });
}

/// Where to put an Identify label: centred on the monitor. On Windows the
/// monitor is in physical pixels and the label's size is logical, so it is
/// scaled by the monitor's scale to centre properly.
#[must_use]
pub fn identify_position(monitor: &remote_protocol::Monitor, size: (f64, f64)) -> (f64, f64) {
    let scale = if cfg!(target_os = "macos") {
        1.0
    } else {
        monitor.scale
    };

    (
        f64::from(monitor.x) + (f64::from(monitor.width) - size.0 * scale).max(0.0) / 2.0,
        f64::from(monitor.y) + (f64::from(monitor.height) - size.1 * scale).max(0.0) / 2.0,
    )
}

/// Percent-encode a monitor name for the Identify window's URL fragment.
#[must_use]
pub fn fragment_encode(value: &str) -> String {
    let mut encoded = String::with_capacity(value.len());

    for byte in value.bytes() {
        match byte {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => {
                encoded.push(byte as char);
            }
            _ => encoded.push_str(&format!("%{byte:02X}")),
        }
    }

    encoded
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

    // `api_base_url` is the API under `/api`; the device-code sign-in page
    // (docs/desktop/DEVICE_ENROLMENT.md) that the agent opens to be confirmed
    // is the *application* on the same host, one level up. Deriving it rather
    // than adding a fourth config field keeps there being exactly one place —
    // `api_base_url` — that says which deployment this agent talks to.
    let app_origin = config
        .api_base_url
        .trim_end_matches('/')
        .strip_suffix("/api")
        .map(str::to_owned);

    // Compared as origins with an explicit boundary, so
    // `https://my.aicountly.com.attacker.example` does not match
    // `https://my.aicountly.com`.
    let permitted = [
        Some(config.portal_url.clone()),
        Some(config.api_base_url.clone()),
        app_origin,
    ];

    permitted.iter().flatten().any(|origin| {
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
        supports_hosting: platform::supports_hosting(),
        supports_companion: platform::supports_companion(),
        stop_shortcut: crate::stop_shortcut_label().to_owned(),
        key_storage: platform::secure_storage().describe().to_owned(),
        log_file: remote_core::log_path("agent").map(|path| path.display().to_string()),
        service: ipc::service_status(),
    }
}

fn now_iso() -> String {
    time::OffsetDateTime::now_utc()
        .format(&time::format_description::well_known::Rfc3339)
        .unwrap_or_default()
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

    /// The page a device-code sign-in opens to be confirmed
    /// (docs/desktop/DEVICE_ENROLMENT.md) is the application itself, one
    /// level up from `api_base_url`'s `/api` — not under it.
    #[test]
    fn the_device_signin_page_on_the_same_host_is_opened() {
        assert!(is_permitted_url(
            "https://remote.aicountly.com/desktop-signin?code=ABCD-1234",
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

    fn monitor(x: i32, y: i32, width: u32, height: u32, scale: f64) -> remote_protocol::Monitor {
        remote_protocol::Monitor {
            id: 1,
            name: "Display 1".into(),
            primary: true,
            x,
            y,
            width,
            height,
            scale,
            refresh_hz: None,
            orientation: remote_protocol::Orientation::Landscape,
        }
    }

    /// The label lands in the middle of the monitor it names, wherever that
    /// monitor sits in the layout.
    #[test]
    fn an_identify_label_is_centred_on_its_monitor() {
        let (x, y) = identify_position(&monitor(-1920, 0, 1920, 1080, 1.0), (420.0, 220.0));

        assert_eq!((x, y), (-1920.0 + 750.0, 430.0));

        // Smaller than the label: pinned to the corner rather than off-screen.
        let (x, y) = identify_position(&monitor(100, 50, 200, 100, 1.0), (420.0, 220.0));
        assert_eq!((x, y), (100.0, 50.0));
    }

    #[test]
    fn a_monitor_name_is_escaped_for_the_identify_url() {
        assert_eq!(fragment_encode("Display 2"), "Display%202");
        assert_eq!(fragment_encode("a#b&c"), "a%23b%26c");
        assert_eq!(
            fragment_encode("Built-in_display.1~"),
            "Built-in_display.1~"
        );
    }

    /// The store's name, never anything in it.
    #[test]
    fn the_about_panel_names_the_key_store_and_nothing_in_it() {
        let name = platform::secure_storage().describe();

        assert!(!name.is_empty());
        assert!(!name.to_lowercase().contains("key ="));
    }
}
