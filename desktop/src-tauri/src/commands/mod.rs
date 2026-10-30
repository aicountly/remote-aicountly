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
    /// This build's version.
    pub version: String,
    /// Which build of that version (`yyyyMMddHHmm`, the commit's time), or
    /// `None` for a local build. The same number is in the installer's name,
    /// so somebody can tell which one they are running.
    pub build: Option<String>,
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
    /// AICOUNTLY's privacy policy, which the About panel links to.
    pub privacy_policy_url: String,
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

/// What this computer is called on the network, before anybody signs in.
///
/// The device key's fingerprint, created on first run and kept. It is shown
/// on the sign-in screen so somebody can read it out — the way AnyDesk shows
/// an ID — while every control the machine has stays shut until the sign-in
/// finishes. An identifier on its own reaches nothing: a connection needs a
/// credential, and a credential needs a person to sign in for it.
#[tauri::command]
pub fn device_identity(agent: tauri::State<'_, Arc<Agent>>) -> Result<String, String> {
    agent.device_identity().map_err(|error| error.to_string())
}

/// Start a device-code sign-in, and hand back the code to show.
///
/// This call lives on this side of the bridge because the web view is a
/// browser, and a browser making a JSON request to another origin sends a
/// preflight first. The API answers a preflight from an origin nobody
/// configured with no CORS headers — which is right, since the deployed app
/// shares an origin with it — so the web view's own `fetch` was blocked and
/// the window reported a network problem on a machine with a working network.
/// `reqwest` has no origin and asks nobody's permission.
#[tauri::command]
pub async fn start_desktop_sign_in(
    agent: tauri::State<'_, Arc<Agent>>,
    material: EnrolmentMaterial,
) -> Result<remote_core::DesktopSignInStart, String> {
    let client = remote_core::ApiClient::new(agent.config()).map_err(|error| error.to_string())?;

    let request = remote_core::DesktopSignInRequest {
        device_name: material.host_name.clone(),
        public_key: material.public_key,
        device_type: "DESKTOP".to_owned(),
        operating_system: material.operating_system,
        os_version: material.os_version,
        architecture: material.architecture,
        hostname: material.host_name,
        agent_version: material.agent_version,
        capabilities: material.capabilities,
    };

    client
        .start_desktop_sign_in(&request)
        .await
        .map_err(|error| error.to_string())
}

/// Ask what became of a device-code sign-in.
#[tauri::command]
pub async fn poll_desktop_sign_in(
    agent: tauri::State<'_, Arc<Agent>>,
    device_code: String,
) -> Result<remote_core::DesktopSignInOutcome, String> {
    let client = remote_core::ApiClient::new(agent.config()).map_err(|error| error.to_string())?;

    let mut outcome = client
        .poll_desktop_sign_in(&device_code)
        .await
        .map_err(|error| error.to_string())?;

    // The portal's sign-in hand-off stays here: the window never holds it,
    // and the next time the portal is shown it redeems it (`portal::show`).
    if let Some(code) = outcome.portal_handoff.take() {
        crate::portal::stash_handoff(code);
    }

    Ok(outcome)
}

/// Everything the enrolment call needs, and nothing else.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
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
    /// Which organisation, if any. `None` is a personal device — the
    /// person's own computer, which needs no organisation.
    #[serde(default)]
    pub company_id: Option<i64>,
    /// Its name, for the "Organisation" row. The API's device resource
    /// carries only the id, so the window supplies the name the sign-in
    /// poll reported alongside it.
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

/// Switch this machine's own unattended access off, on the server.
///
/// Not a recording, unlike [`enable_unattended`] above: this one *is* the
/// decision, and it is made with the agent's own device credential against
/// `POST /devices/me/unattended/disable` — the route the API provides for
/// exactly this, and the asymmetry it was built with. A machine may always
/// stop being reachable unattended; switching it back on needs a person in
/// the console, with the confirmation that endpoint demands.
///
/// It awaits the server and returns the failure rather than swallowing it,
/// because unattended access is the server's state and not this window's.
/// Recording it locally without telling the server produced a window that
/// said unattended access was off while the machine was still reachable —
/// until the next poll of `GET /devices/me` quietly put the switch back,
/// which is how somebody finds out that the button they pressed did nothing.
#[tauri::command]
pub async fn disable_unattended(
    agent: tauri::State<'_, Arc<Agent>>,
) -> Result<remote_core::AgentState, String> {
    let client = agent
        .device_client()
        .ok_or("This computer is not registered, so there is nothing to switch off.")?;

    let device = client
        .disable_unattended()
        .await
        .map_err(|error| error.to_string())?;

    // The server's answer, not an assumption about it. `allowed_by_policy` is
    // the one field it does not carry: it belongs to the organisation's policy
    // rather than to the device row, and the poll owns it.
    Ok(agent.set_unattended(remote_core::UnattendedState {
        enabled: device.unattended_access_enabled,
        enabled_at: device.unattended_enabled_at,
        last_used_at: device.unattended_last_used_at,
        allowed_by_policy: agent.state().unattended.allowed_by_policy,
    }))
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
    //
    // The download page and this product's directory on the downloads host
    // are fixed, not configured: they are where an update is offered, and
    // the update banner opens them for anyone who would rather install by
    // hand.
    let download_page = remote_core::update::DOWNLOAD_PAGE_URL
        .split('#')
        .next()
        .map(str::to_owned);

    let permitted = [
        Some(config.portal_url.clone()),
        Some(config.api_base_url.clone()),
        app_origin,
        download_page,
        Some(remote_core::update::DOWNLOADS_BASE_URL.to_owned()),
        Some(crate::PRIVACY_POLICY_URL.to_owned()),
    ];

    permitted.iter().flatten().any(|origin| {
        let origin = origin.trim_end_matches('/');

        url == origin
            || url.starts_with(&format!("{origin}/"))
            || url.starts_with(&format!("{origin}?"))
            || url.starts_with(&format!("{origin}#"))
    })
}

/// Show the web portal in the window's content area, at `path` if given.
///
/// The portal is this deployment's web application (`portal::portal_origin`);
/// the window chooses only the page and where it is drawn.
///
/// `async`, like every command here that creates or drives a webview: a
/// synchronous command runs on the main thread, and creating a webview there
/// deadlocks on Windows (see `portal::show`).
#[tauri::command]
pub async fn portal_show(
    app: tauri::AppHandle,
    agent: tauri::State<'_, Arc<Agent>>,
    path: Option<String>,
    bounds: crate::portal::PortalBounds,
) -> Result<(), String> {
    let origin = crate::portal::portal_origin(&agent.config().api_base_url)
        .ok_or("AICOUNTLY Remote's address is not set up for this computer.")?;

    crate::portal::show(&app, &origin, path.as_deref(), bounds)
}

/// The window's layout changed: move the portal with it.
#[tauri::command]
pub async fn portal_set_bounds(
    app: tauri::AppHandle,
    bounds: crate::portal::PortalBounds,
) -> Result<(), String> {
    crate::portal::set_bounds(&app, bounds)
}

/// Take the portal off the screen, keeping its page and sign-in.
#[tauri::command]
pub async fn portal_hide(app: tauri::AppHandle) -> Result<(), String> {
    crate::portal::hide(&app)
}

/// Back, reload, or open the current portal page in the browser.
#[tauri::command]
pub async fn portal_action(app: tauri::AppHandle, action: String) -> Result<(), String> {
    crate::portal::act(&app, &action)
}

/// Whether a newer version is published for this computer.
///
/// Never an error: a check that cannot reach the downloads host, or reads a
/// manifest it does not understand, is "no update" and the application goes
/// on exactly as before. Only logged, for a support engineer.
#[tauri::command]
pub async fn check_for_update() -> Option<remote_core::UpdateInfo> {
    let (platform, arch) = remote_core::update::current_platform()?;

    let checker = match remote_core::UpdateChecker::new() {
        Ok(checker) => checker,
        Err(error) => {
            tracing::info!(%error, "the update check could not start; carrying on");
            return None;
        }
    };

    match checker
        .check(crate::VERSION, remote_core::AGENT_BUILD, platform, arch)
        .await
    {
        Ok(update) => {
            if let Some(update) = &update {
                tracing::info!(
                    latest = %update.latest_version,
                    build = update.latest_build.as_deref().unwrap_or("-"),
                    "a newer build is published"
                );
            }
            update
        }
        Err(error) => {
            tracing::info!(%error, "the update check failed; carrying on");
            None
        }
    }
}

/// What the window says once an installer has been opened.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct UpdateStarted {
    /// The version being installed.
    pub version: String,
    /// `windows` or `macos`: what the person is about to see differs.
    pub platform: String,
    /// How long until this application closes, so the installer can replace it.
    pub closes_in_seconds: u64,
}

/// How long the window stays up after the installer opens: long enough to
/// read what happens next, short enough to be out of the installer's way.
const UPDATE_CLOSE_DELAY_SECONDS: u64 = 4;

/// Download this computer's installer, verify it, open it, and close.
///
/// Nothing about what to fetch comes from the window: the manifest is read
/// again here, and only the installer it names for this platform and
/// architecture is fetched — from the downloads host, checked against the
/// manifest's size and SHA-256 (`remote_core::update`). The operating system
/// then does what it does with the file: on Windows the installer runs (and
/// asks for elevation itself); on macOS the disk image opens in Finder.
///
/// Refused while a session is running: closing this application ends it, and
/// somebody is on the other end.
#[tauri::command]
pub async fn install_update(
    app: tauri::AppHandle,
    agent: tauri::State<'_, Arc<Agent>>,
) -> Result<UpdateStarted, String> {
    if agent.state().active_session().is_some() {
        return Err(
            "Finish the current session first — installing the update closes AICOUNTLY Remote."
                .into(),
        );
    }

    let (platform, arch) = remote_core::update::current_platform()
        .ok_or("Updates are not published for this computer.")?;

    let checker = remote_core::UpdateChecker::new().map_err(|error| error.to_string())?;

    let update = checker
        .check(crate::VERSION, remote_core::AGENT_BUILD, platform, arch)
        .await
        .map_err(|error| error.to_string())?
        .ok_or("This is already the latest version of AICOUNTLY Remote.")?;

    let installer = update
        .installer
        .ok_or("There is no installer for this computer to download automatically. Use the download page instead.")?;

    let path = checker
        .download(
            &installer,
            &std::env::temp_dir().join("AicountlyRemoteUpdate"),
        )
        .await
        .map_err(|error| error.to_string())?;

    open::that(&path).map_err(|error| format!("The installer could not be opened: {error}"))?;

    tracing::info!(version = %update.latest_version, "opened the update installer; closing");

    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(std::time::Duration::from_secs(UPDATE_CLOSE_DELAY_SECONDS)).await;
        app.exit(0);
    });

    Ok(UpdateStarted {
        version: update.latest_version,
        platform: platform.to_owned(),
        closes_in_seconds: UPDATE_CLOSE_DELAY_SECONDS,
    })
}

/// What the About panel shows.
#[tauri::command]
pub fn about(agent: tauri::State<'_, Arc<Agent>>) -> About {
    let _ = agent;

    About {
        product: crate::PRODUCT_NAME.to_owned(),
        version: crate::VERSION.to_owned(),
        build: remote_core::AGENT_BUILD.map(str::to_owned),
        platform: platform::platform_name().to_owned(),
        supported: platform::is_supported(),
        supports_hosting: platform::supports_hosting(),
        supports_companion: platform::supports_companion(),
        stop_shortcut: crate::stop_shortcut_label().to_owned(),
        key_storage: platform::secure_storage().describe().to_owned(),
        log_file: remote_core::log_path("agent").map(|path| path.display().to_string()),
        service: ipc::service_status(),
        privacy_policy_url: crate::PRIVACY_POLICY_URL.to_owned(),
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

    /// The About panel's link, and nothing that merely starts like it.
    #[test]
    fn the_privacy_policy_is_opened_and_its_lookalikes_are_not() {
        assert!(is_permitted_url(crate::PRIVACY_POLICY_URL, &config()));
        assert!(!is_permitted_url(
            "https://aicountly.com/privacy-policy.attacker.example",
            &config()
        ));
        assert!(!is_permitted_url("https://aicountly.com/", &config()));
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

    /// On Windows a synchronous command runs on the main thread, and creating
    /// or driving a webview from there waits on that same thread forever —
    /// which is how 1.0.2's portal hung the whole application on its first
    /// use. Every command that creates or drives a webview must be `async`.
    /// Read from this file's own source because the deadlock cannot be
    /// reproduced off Windows, so no runtime test here would catch it.
    #[test]
    fn every_command_that_touches_a_webview_is_async() {
        let source = include_str!("mod.rs");

        for command in [
            "portal_show",
            "portal_set_bounds",
            "portal_hide",
            "portal_action",
            "identify_monitors",
            "install_update",
        ] {
            assert!(
                source.contains(&format!("pub async fn {command}(")),
                "`{command}` must be an async command — a synchronous one deadlocks on Windows"
            );
            assert!(
                !source.contains(&format!("pub fn {command}(")),
                "`{command}` must not be a synchronous command"
            );
        }
    }

    /// The update banner's two links: the download page, and an installer on
    /// the downloads host — and nothing else on either host.
    #[test]
    fn the_download_page_and_published_installers_are_opened() {
        for candidate in [
            "https://aicountly.com/remote",
            "https://aicountly.com/remote#download",
            "https://downloads.aicountly.com/remote/1.1.0/Aicountly-Remote-1.1.0-x64-Setup.exe",
        ] {
            assert!(
                is_permitted_url(candidate, &config()),
                "{candidate:?} should be opened"
            );
        }

        for candidate in [
            "https://aicountly.com/",
            "https://aicountly.com/remoteevil",
            "https://downloads.aicountly.com/connect/x.exe",
            "https://downloads.aicountly.com.attacker.example/remote/x.exe",
            "http://downloads.aicountly.com/remote/x.exe",
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
