//! The agent's client for the Remote API.
//!
//! Every request the agent makes, in one place, so that the two credentials
//! the agent handles cannot be confused for one another:
//!
//! * a **portal `ses_key`**, held only while a person is signing in to
//!   register the machine, and used for exactly one call — enrolment;
//! * a **device credential**, obtained by proving possession of the private
//!   key, used for everything afterwards, and never written to disk.
//!
//! Neither is ever logged. [`DeviceCredential`] has a `Debug` implementation
//! that prints its expiry, and the `ses_key` is taken by value at the single
//! call site that needs it rather than being stored on the client at all.

use std::time::Duration;

use remote_device::AgentCapabilities;
use remote_protocol::Monitor;
use remote_security::DeviceKeypair;
use serde::{Deserialize, Serialize};

use crate::config::AgentConfig;

/// How long any single request may take.
///
/// Short, because the agent is not waiting on a person: an API that has not
/// answered in fifteen seconds has not answered, and the reconnection loop is
/// a better answer than a socket held open for a minute.
const REQUEST_TIMEOUT: Duration = Duration::from_secs(15);

/// A device access credential and when it stops working.
///
/// Held in memory only. Writing it to disk would turn a short-lived,
/// re-obtainable credential into a durable one that somebody could steal off
/// a machine — which is the exact thing proof-of-possession exists to avoid.
#[derive(Clone, PartialEq, Eq)]
pub struct DeviceCredential {
    token: String,
    /// ISO-8601 UTC, as the API returned it.
    pub expires_at: String,
    /// What this credential is allowed to be used for.
    pub scopes: Vec<String>,
}

impl DeviceCredential {
    /// The `Authorization` header value.
    #[must_use]
    pub fn header_value(&self) -> String {
        format!("Bearer {}", self.token)
    }

    /// Whether the credential covers a scope.
    #[must_use]
    pub fn has_scope(&self, scope: &str) -> bool {
        self.scopes.iter().any(|held| held == scope)
    }

    /// Seconds from now until it expires, or `None` if the expiry is not an
    /// instant this build can read.
    #[must_use]
    pub fn seconds_remaining(&self) -> Option<i64> {
        let expires = time::OffsetDateTime::parse(
            &self.expires_at,
            &time::format_description::well_known::Rfc3339,
        )
        .ok()?
        .unix_timestamp();

        Some(expires - time::OffsetDateTime::now_utc().unix_timestamp())
    }

    /// Whether it is close enough to expiry to be renewed now.
    ///
    /// An expiry that cannot be parsed counts as expiring: a spare round trip
    /// costs nothing, and a session refused because the credential had
    /// quietly run out costs a support call. Both halves of the agent — the
    /// window's connection loop and the service's presence loop — renew
    /// through this one answer so they cannot disagree about what "expiring"
    /// means.
    #[must_use]
    pub fn is_expiring(&self, margin: Duration) -> bool {
        match self.seconds_remaining() {
            Some(remaining) => remaining <= margin.as_secs() as i64,
            None => true,
        }
    }
}

/// Prints the expiry and the scopes. Never the token.
impl std::fmt::Debug for DeviceCredential {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("DeviceCredential")
            .field("expires_at", &self.expires_at)
            .field("scopes", &self.scopes)
            .finish_non_exhaustive()
    }
}

/// A device authentication challenge.
#[derive(Debug, Clone, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Challenge {
    /// 64 hex characters.
    pub nonce: String,
    /// The instant the agent signs into the canonical payload.
    pub issued_at: i64,
    /// When it stops being redeemable.
    pub expires_at: String,
    /// Who the assertion is for.
    pub audience: String,
}

/// What the agent sends to `POST /devices/enrol`.
#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct EnrolmentRequest {
    /// Which organisation.
    pub company_id: i64,
    /// What to call this machine.
    pub device_name: String,
    /// The **public** half. The private one never leaves the machine.
    pub public_key: String,
    /// `DESKTOP`, `LAPTOP`, `SERVER`.
    pub device_type: String,
    /// `Windows`.
    pub operating_system: String,
    /// `11 24H2`.
    pub os_version: String,
    /// `x86_64`.
    pub architecture: String,
    /// The machine's name.
    pub hostname: String,
    /// This build.
    pub agent_version: String,
    /// What the software can do. An upper bound the server intersects.
    pub capabilities: AgentCapabilities,
}

/// What the agent sends to `POST /desktop-signin/start`.
///
/// The field names are the API's, and `deviceType` is always `DESKTOP`: this
/// is the only kind of thing that enrols this way.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct DesktopSignInRequest {
    /// What the device will be called.
    pub device_name: String,
    /// The **public** half of the device keypair.
    pub public_key: String,
    /// Always `DESKTOP`.
    pub device_type: String,
    /// `Windows`.
    pub operating_system: String,
    /// `11 24H2`.
    pub os_version: String,
    /// `x86_64`.
    pub architecture: String,
    /// The machine's name, as the console shows it.
    pub hostname: String,
    /// This build.
    pub agent_version: String,
    /// What the software can do, already constrained by what the machine
    /// permits.
    pub capabilities: AgentCapabilities,
}

/// What `POST /desktop-signin/start` answers.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct DesktopSignInStart {
    /// `ABCD-1234` — the short code to compare with the browser.
    pub user_code: String,
    /// The long one, which is the proof. Never shown to anybody.
    pub device_code: String,
    /// Where to go to confirm.
    pub verification_uri: String,
    /// The same, with the code already in it — what the agent opens.
    pub verification_uri_complete: String,
    /// When the code lapses, ISO-8601 UTC.
    pub expires_at: String,
    /// How often to poll, as the server asks.
    pub interval_seconds: u64,
}

/// Where a device-code sign-in has got to.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum DesktopSignInStatus {
    /// Nobody has answered yet.
    Pending,
    /// Somebody confirmed it, and the device is enrolled.
    Confirmed,
    /// Somebody refused it.
    Denied,
    /// Nobody answered in time.
    Expired,
}

/// What one poll reports.
///
/// One struct rather than an enum, so a status this build does not know about
/// is still a well-formed answer the window can render rather than a
/// deserialisation failure in the middle of a sign-in.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct DesktopSignInOutcome {
    /// Pending, confirmed, denied or expired.
    pub status: DesktopSignInStatus,
    /// The enrolled device — only once confirmed.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub device: Option<DeviceResource>,
    /// Which organisation it was enrolled into.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub company_name: Option<String>,
}

/// The device resource, as the API renders it.
///
/// `Serialize` as well as `Deserialize` because a confirmed device-code
/// sign-in carries one back to the window.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct DeviceResource {
    /// The device's public identifier.
    pub uuid: String,
    /// What it is called.
    pub device_name: String,
    /// `PENDING`, `ACTIVE`, `SUSPENDED`, `REVOKED`.
    pub status: String,
    /// Which organisation.
    pub company_id: Option<i64>,
    /// The public key fingerprint, grouped for reading.
    pub key_fingerprint: Option<String>,
    /// Whether unattended access is on.
    pub unattended_access_enabled: bool,
    /// When it was switched on.
    pub unattended_enabled_at: Option<String>,
    /// When somebody last connected that way.
    pub unattended_last_used_at: Option<String>,
    /// What the software declared.
    pub capabilities: AgentCapabilities,
}

impl DeviceResource {
    /// Whether the API considers this device usable.
    #[must_use]
    pub fn is_active(&self) -> bool {
        self.status == "ACTIVE"
    }

    /// Whether an administrator has revoked it.
    #[must_use]
    pub fn is_revoked(&self) -> bool {
        self.status == "REVOKED"
    }
}

/// What `GET /devices/me` says the organisation currently permits.
#[derive(Debug, Clone, PartialEq, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct DevicePolicyView {
    /// Remote control.
    pub allow_remote_control: bool,
    /// Unattended access.
    pub allow_unattended_access: bool,
    /// Clipboard synchronisation.
    pub allow_clipboard_sync: bool,
    /// Restarting the machine.
    pub allow_device_reboot: bool,
    /// File transfer.
    pub allow_file_transfer: bool,
    /// Machine reasons a capability is off, for the interface to explain.
    #[serde(default)]
    pub restrictions: Vec<String>,
    /// Which sharing surfaces the organisation permits, as the API spells
    /// them: `SAFE_SHARE`, `BROWSER_TAB`, `APPLICATION_WINDOW`,
    /// `ENTIRE_MONITOR`.
    ///
    /// `None` and `Some([])` are deliberately different things, which is why
    /// this is an `Option` rather than a defaulted `Vec`. `None` is a server
    /// that does not send the field at all, and it constrains nothing —
    /// enforcing an absent policy would stop every agent sharing the moment it
    /// met an older API. `Some([])` is an organisation that permits no
    /// sharing anywhere, and it is honoured.
    #[serde(default)]
    pub allowed_share_modes: Option<Vec<String>>,
}

/// The whole of one screen — the only surface a desktop agent can offer.
///
/// A browser picker can hand back a tab or a single window; this agent
/// captures a monitor and has no way to capture anything smaller. So this is
/// the one mode its hosting has to be measured against, and an organisation
/// that allows only `APPLICATION_WINDOW` allows this machine nothing.
pub const ENTIRE_MONITOR: &str = "ENTIRE_MONITOR";

impl DevicePolicyView {
    /// Whether this organisation permits sharing a whole screen.
    ///
    /// True when the server did not say (see `allowed_share_modes`), because
    /// an agent that refused on silence would be an agent that stops working
    /// against an API that predates this field.
    #[must_use]
    pub fn allows_entire_monitor(&self) -> bool {
        match self.allowed_share_modes.as_deref() {
            None => true,
            Some(modes) => modes.iter().any(|mode| mode == ENTIRE_MONITOR),
        }
    }

    /// The policy as a capability set, for intersecting with the declaration.
    #[must_use]
    pub fn as_capabilities(&self) -> AgentCapabilities {
        AgentCapabilities {
            // Not an unconditional yes: this agent shares a whole screen or
            // nothing, so a policy without `ENTIRE_MONITOR` is a policy under
            // which it cannot share at all — whatever other surfaces a browser
            // may still be offered.
            screen_share: self.allows_entire_monitor(),
            // Viewing somebody else's screen is not a surface this machine
            // offers, so the share modes have nothing to say about it.
            screen_view: true,
            remote_control: self.allow_remote_control,
            unattended_access: self.allow_unattended_access,
            file_transfer: self.allow_file_transfer,
            clipboard_sync: self.allow_clipboard_sync,
            reboot: self.allow_device_reboot,
        }
    }
}

/// The whole of `GET /devices/me`.
#[derive(Debug, Clone, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct DeviceSelf {
    /// The device row.
    pub device: DeviceResource,
    /// The declaration ∧ the organisation's ceiling, as the server computed it.
    pub capabilities: AgentCapabilities,
    /// What the organisation permits.
    pub policy: DevicePolicyView,
    /// ICE configuration and how often to report presence.
    pub realtime: RealtimeConfig,
    /// Version and update information.
    pub agent: AgentAdvisory,
    /// Unattended sessions waiting for this machine to join.
    #[serde(default)]
    pub pending_sessions: Vec<PendingSession>,
    /// Sessions this machine's owner is sharing from a browser and has asked
    /// this machine to join as their control companion. Each waits for the
    /// person at the machine to confirm it, here, before anything is joined.
    #[serde(default)]
    pub pending_companions: Vec<PendingCompanion>,
}

/// A control-companion link waiting for the person at the machine to confirm.
///
/// The owner shares a screen from the browser and links this machine to that
/// share, so somebody they grant control to can use its mouse and keyboard.
/// Nothing is joined until the person here says yes, once, for this link.
#[derive(Debug, Clone, PartialEq, Eq, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct PendingCompanion {
    /// The link's own identifier — what join, decline and leave are called on.
    pub companion_uuid: String,
    /// The session the owner is sharing in.
    pub session_uuid: String,
    /// `123-456` — the label to read out loud, as the browser shows it.
    #[serde(default)]
    pub display_id: String,
    /// Which organisation the session belongs to.
    #[serde(default)]
    pub company_name: Option<String>,
    /// Who asked — the owner, from their browser.
    #[serde(default)]
    pub requested_by_name: Option<String>,
    /// When they asked.
    #[serde(default)]
    pub requested_at: Option<String>,
    /// When the request lapses unanswered (five minutes).
    #[serde(default)]
    pub expires_at: Option<String>,
}

/// ICE and presence configuration from the API.
#[derive(Debug, Clone, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct RealtimeConfig {
    /// The ICE servers, per session, never inlined in this binary.
    #[serde(default)]
    pub ice_servers: Vec<serde_json::Value>,
    /// Whether a TURN relay is configured at all.
    pub relay_available: bool,
    /// How often to say the machine is still there.
    pub presence_interval_seconds: u64,
}

/// What the deployment expects of the agent.
#[derive(Debug, Clone, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct AgentAdvisory {
    /// The build this deployment expects.
    pub minimum_version: String,
    /// Where the signed update manifest lives, when updates are configured.
    pub update_feed_url: Option<String>,
    /// The server's clipboard ceiling. The agent uses this, not its own.
    pub clipboard_max_bytes: usize,
}

/// A session the agent has been asked to host.
#[derive(Debug, Clone, PartialEq, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct PendingSession {
    /// The session's public identifier.
    pub uuid: String,
    /// `AR-10282`.
    pub display_id: String,
    /// Which organisation.
    pub company_name: Option<String>,
    /// Who started it.
    pub owner_name: Option<String>,
    /// When it expires.
    pub expires_at: Option<String>,
}

/// Control, as the API currently has it for one session.
#[derive(Debug, Clone, Default, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct SessionControlView {
    /// The participant whose machine can be controlled — this one, normally.
    #[serde(default)]
    pub controllable_host_uuid: Option<String>,
    /// Who currently holds control.
    #[serde(default)]
    pub controller_uuid: Option<String>,
    /// Their name, for the indicator.
    #[serde(default)]
    pub controller_name: Option<String>,
    /// Whether the clipboard was granted alongside it.
    #[serde(default)]
    pub clipboard_enabled: bool,
    /// Everyone waiting for an answer.
    #[serde(default)]
    pub pending_requests: Vec<PendingControlRequest>,
    /// The organisation's switch, so the dialog cannot offer what would be
    /// refused.
    #[serde(default)]
    pub allow_remote_control: bool,
    /// Whether the clipboard may be offered at all.
    #[serde(default)]
    pub allow_clipboard_sync: bool,
    /// Whether a restart may be asked for at all.
    #[serde(default)]
    pub allow_device_reboot: bool,
    /// The opaque identifier of the grant `controller_uuid` holds (the
    /// controller and the instant it was granted). What re-arms the gate after
    /// a local Stop: only a grant with a different identifier does.
    #[serde(default)]
    pub grant_id: Option<String>,
    /// The control-companion link in this session, when there is one.
    #[serde(default)]
    pub companion: Option<CompanionView>,
    /// What the companion's owner is sharing from the browser.
    #[serde(default)]
    pub shared_surface: Option<SharedSurface>,
    /// Why control cannot be offered right now: `NO_COMPANION`,
    /// `COMPANION_NOT_READY`, `NOT_SHARING`, `SURFACE_NOT_MONITOR`.
    #[serde(default)]
    pub control_blocked_reason: Option<String>,
}

/// A control-companion link, as the control view describes it.
#[derive(Debug, Clone, Default, PartialEq, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct CompanionView {
    /// The link's identifier.
    #[serde(default)]
    pub uuid: String,
    /// `PENDING`, `JOINED`, `ACTIVE`, `DECLINED`, `ENDED`, `EXPIRED`.
    #[serde(default)]
    pub status: String,
    /// The machine's name.
    #[serde(default)]
    pub device_name: Option<String>,
    /// The machine's device uuid.
    #[serde(default)]
    pub device_uuid: Option<String>,
    /// The browser participant whose share this machine is the companion of —
    /// the sharer. If they leave, the link is over.
    #[serde(default)]
    pub linked_participant_uuid: Option<String>,
    /// This machine's own participant in the session, once joined.
    #[serde(default)]
    pub participant_uuid: Option<String>,
    /// The monitor this machine reported as the one being shared.
    #[serde(default)]
    pub monitor: Option<serde_json::Value>,
    /// Whether the server considers the companion controllable.
    #[serde(default)]
    pub ready: bool,
}

impl CompanionView {
    /// Whether the link is finished, one way or another. A finished link is
    /// the one thing — with the sharer leaving and the session ending — that
    /// ends a companion.
    #[must_use]
    pub fn is_over(&self) -> bool {
        matches!(self.status.as_str(), "ENDED" | "EXPIRED" | "DECLINED")
    }
}

/// The surface the companion's owner is sharing, as their browser reported it.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct SharedSurface {
    /// `monitor`, `window`, `browser`, or `unknown` (Firefox, Safari).
    #[serde(default, deserialize_with = "surface_kind")]
    pub display_surface: SurfaceKind,
    /// The captured track's width, from `track.getSettings()`.
    #[serde(default)]
    pub width: Option<u32>,
    /// The captured track's height.
    #[serde(default)]
    pub height: Option<u32>,
    /// Whether the browser reported the surface itself, rather than the server
    /// assuming it.
    #[serde(default)]
    pub verified: bool,
}

/// What kind of surface a browser is sharing.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub enum SurfaceKind {
    /// An entire screen — the only kind a companion can be controlled through.
    Monitor,
    /// One window, a browser tab, or anything else.
    Other,
    /// The browser did not say.
    #[default]
    Unknown,
}

fn surface_kind<'de, D: serde::Deserializer<'de>>(
    deserializer: D,
) -> Result<SurfaceKind, D::Error> {
    let value: Option<String> = Option::deserialize(deserializer)?;

    Ok(match value.as_deref() {
        Some("monitor") => SurfaceKind::Monitor,
        None | Some("unknown") | Some("") => SurfaceKind::Unknown,
        Some(_) => SurfaceKind::Other,
    })
}

impl SharedSurface {
    /// The size to match a monitor against, when the browser reported one.
    #[must_use]
    pub fn size(&self) -> Option<(u32, u32)> {
        match (self.width, self.height) {
            (Some(width), Some(height)) if width > 0 && height > 0 => Some((width, height)),
            _ => None,
        }
    }
}

/// How the companion decided which monitor is being shared (`matchedBy`).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "SCREAMING_SNAKE_CASE")]
pub enum MonitorMatch {
    /// The machine has one monitor.
    AutoSingle,
    /// Exactly one monitor has the shared surface's aspect ratio.
    AutoAspect,
    /// The person at the machine picked it.
    User,
}

impl MonitorMatch {
    /// The API's spelling.
    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            Self::AutoSingle => "AUTO_SINGLE",
            Self::AutoAspect => "AUTO_ASPECT",
            Self::User => "USER",
        }
    }

    /// From [`remote_protocol::SurfaceMatch::matched_by`]'s spelling.
    #[must_use]
    pub fn from_api(value: &str) -> Option<Self> {
        match value {
            "AUTO_SINGLE" => Some(Self::AutoSingle),
            "AUTO_ASPECT" => Some(Self::AutoAspect),
            "USER" => Some(Self::User),
            _ => None,
        }
    }
}

/// The body of `POST /devices/me/companions/{uuid}/monitor`.
///
/// The monitor in the companion's injection space — points on macOS, physical
/// pixels on Windows — which is the space its normalised coordinates are
/// mapped into.
#[must_use]
pub fn companion_monitor_body(monitor: &Monitor, matched_by: MonitorMatch) -> serde_json::Value {
    serde_json::json!({
        "monitor": {
            "id": monitor.id,
            "name": monitor.name,
            "x": monitor.x,
            "y": monitor.y,
            "width": monitor.width,
            "height": monitor.height,
            "scale": monitor.scale,
        },
        "matchedBy": matched_by.as_str(),
    })
}

/// Somebody waiting for control of this machine.
#[derive(Debug, Clone, PartialEq, Eq, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct PendingControlRequest {
    /// Their participant uuid.
    pub participant_uuid: String,
    /// Their name, as the dialog shows it.
    #[serde(default)]
    pub display_name: String,
    /// When they asked.
    #[serde(default)]
    pub requested_at: Option<String>,
}

/// What the person at the machine decided.
///
/// Three answers and no fourth. There is no "always allow": that is unattended
/// access, which is a separate entitlement, a separate policy switch, a
/// separate permission and a separate deliberate act — and never something a
/// consent dialog can quietly become.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ControlDecision {
    /// Allow this person to control the machine.
    Grant,
    /// Not now.
    Deny,
    /// Stop control that was already granted.
    Revoke,
}

impl ControlDecision {
    /// The wire spelling, matching `ControlService::decideAsDevice`.
    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Grant => "GRANT",
            Self::Deny => "DENY",
            Self::Revoke => "REVOKE",
        }
    }
}

/// A uuid that is about to become part of a request path.
///
/// Checked rather than trusted: it reaches the agent over a data channel from
/// another participant, and anything but a plain identifier is refused here
/// rather than producing a request to a path nobody intended.
fn require_identifier(value: &str) -> Result<(), ApiError> {
    if value.is_empty()
        || value.len() > 64
        || !value.chars().all(|c| c.is_ascii_alphanumeric() || c == '-')
    {
        return Err(ApiError::Refused {
            status: 400,
            code: "IDENTIFIER_INVALID".into(),
            message: "That is not an identifier.".into(),
        });
    }

    Ok(())
}

/// The agent's HTTP client.
pub struct ApiClient {
    config: AgentConfig,
    http: reqwest::Client,
    credential: Option<DeviceCredential>,
}

impl ApiClient {
    /// A client for one deployment.
    pub fn new(config: AgentConfig) -> Result<Self, ApiError> {
        let http = reqwest::Client::builder()
            .timeout(REQUEST_TIMEOUT)
            .user_agent(format!(
                "AicountlyRemote/{} ({})",
                crate::AGENT_VERSION,
                std::env::consts::OS
            ))
            // The agent talks to one host it was configured with. A redirect
            // to somewhere else is not something to follow with a credential
            // attached.
            .redirect(reqwest::redirect::Policy::none())
            .build()
            .map_err(|error| ApiError::Transport(error.to_string()))?;

        Ok(Self {
            config,
            http,
            credential: None,
        })
    }

    /// The credential currently held, if any.
    #[must_use]
    pub fn credential(&self) -> Option<&DeviceCredential> {
        self.credential.as_ref()
    }

    /// Forget the credential — on revocation, or when it stops being accepted.
    pub fn clear_credential(&mut self) {
        self.credential = None;
    }

    /// Attach a credential this client did not obtain itself.
    ///
    /// For a second client built to make one call on a credential the
    /// connection loop already holds — the alternative is sharing one client
    /// across tasks behind a lock, which would make a slow request block an
    /// unrelated one.
    #[must_use]
    pub fn with_credential(mut self, credential: DeviceCredential) -> Self {
        self.credential = Some(credential);

        self
    }

    /// Start a device-code sign-in.
    ///
    /// Unauthenticated, and deliberately: this runs before the machine has any
    /// credential at all. The long `deviceCode` the server returns is the
    /// proof, exactly as `devices/auth/challenge` works.
    ///
    /// # Why this is here rather than in the window
    ///
    /// It used to be a `fetch` in `services/api.ts`, and it was the only API
    /// call the agent made from its web view. A web view is a browser: the
    /// JSON content type makes it send a preflight, and the API answers a
    /// cross-origin preflight from an origin nobody configured with no CORS
    /// headers at all — correctly, since `corsAllowedOrigins` exists for
    /// `npm run dev` and the deployed app shares an origin with the API. The
    /// browser then blocked the response, `fetch` rejected, and the window
    /// reported "AICOUNTLY Remote could not be reached. Check this computer's
    /// network connection" on a machine whose network was fine.
    ///
    /// Here there is no origin, no preflight and no content security policy —
    /// this is `reqwest`, the same client every other call already used.
    pub async fn start_desktop_sign_in(
        &self,
        request: &DesktopSignInRequest,
    ) -> Result<DesktopSignInStart, ApiError> {
        let url = self.config.endpoint("/v1/remote/desktop-signin/start")?;

        let response = self
            .http
            .post(url)
            .json(request)
            .send()
            .await
            .map_err(|error| ApiError::Transport(error.to_string()))?;

        Self::read(response).await
    }

    /// Ask what became of a device-code sign-in.
    ///
    /// Unauthenticated for the same reason as `start_desktop_sign_in`: the
    /// device code is the proof, and nobody who only saw the short code the
    /// screen displays was ever given it.
    pub async fn poll_desktop_sign_in(
        &self,
        device_code: &str,
    ) -> Result<DesktopSignInOutcome, ApiError> {
        let url = self.config.endpoint("/v1/remote/desktop-signin/poll")?;

        let response = self
            .http
            .post(url)
            .json(&serde_json::json!({ "deviceCode": device_code }))
            .send()
            .await
            .map_err(|error| ApiError::Transport(error.to_string()))?;

        Self::read(response).await
    }

    /// Register this machine.
    ///
    /// The **only** call that takes a `ses_key`, and it takes it by value so
    /// it is not left on the client afterwards. Everything else the agent does
    /// uses a device credential, which is what makes a stolen agent
    /// installation useless without the machine's own key store.
    pub async fn enrol(
        &self,
        ses_key: &str,
        request: &EnrolmentRequest,
    ) -> Result<DeviceResource, ApiError> {
        #[derive(Deserialize)]
        struct Body {
            device: DeviceResource,
        }

        let url = self.config.endpoint("/v1/remote/devices/enrol")?;

        let response = self
            .http
            .post(url)
            .bearer_auth(ses_key)
            .json(request)
            .send()
            .await
            .map_err(|error| ApiError::Transport(error.to_string()))?;

        let body: Body = Self::read(response).await?;

        Ok(body.device)
    }

    /// Ask for a challenge and answer it, obtaining a device credential.
    ///
    /// One method rather than two, because the two halves are meaningless
    /// apart: a challenge nobody signs is a row that expires, and a signature
    /// with no challenge cannot be made.
    pub async fn authenticate(
        &mut self,
        device_uuid: &str,
        keypair: &DeviceKeypair,
    ) -> Result<DeviceCredential, ApiError> {
        #[derive(Deserialize)]
        struct VerifyBody {
            token: String,
            #[serde(rename = "expiresAt")]
            expires_at: String,
            scopes: Vec<String>,
        }

        let challenge: Challenge = {
            let url = self.config.endpoint("/v1/remote/devices/auth/challenge")?;
            let response = self
                .http
                .post(url)
                .json(&serde_json::json!({ "deviceUuid": device_uuid }))
                .send()
                .await
                .map_err(|error| ApiError::Transport(error.to_string()))?;

            Self::read(response).await?
        };

        let signature = keypair.sign_challenge(device_uuid, &challenge.nonce, challenge.issued_at);

        let url = self.config.endpoint("/v1/remote/devices/auth/verify")?;
        let response = self
            .http
            .post(url)
            .json(&serde_json::json!({
                "deviceUuid": device_uuid,
                "nonce": challenge.nonce,
                "issuedAt": challenge.issued_at,
                "signature": signature,
            }))
            .send()
            .await
            .map_err(|error| ApiError::Transport(error.to_string()))?;

        let body: VerifyBody = Self::read(response).await?;

        let credential = DeviceCredential {
            token: body.token,
            expires_at: body.expires_at,
            scopes: body.scopes,
        };

        self.credential = Some(credential.clone());

        Ok(credential)
    }

    /// What this device currently is, and what it may currently do.
    pub async fn describe_self(&self) -> Result<DeviceSelf, ApiError> {
        self.get("/v1/remote/devices/me").await
    }

    /// Say the machine is still there.
    pub async fn report_presence(&self, online: bool) -> Result<(), ApiError> {
        let _: serde_json::Value = self
            .post(
                "/v1/remote/devices/me/presence",
                &serde_json::json!({
                    "state": if online { "ONLINE" } else { "OFFLINE" },
                    "agentVersion": crate::AGENT_VERSION,
                }),
            )
            .await?;

        Ok(())
    }

    /// A token for this device's own presence room.
    pub async fn presence_token(&self) -> Result<serde_json::Value, ApiError> {
        self.post(
            "/v1/remote/devices/me/presence-token",
            &serde_json::json!({}),
        )
        .await
    }

    /// Join a session as its screen-sharing host.
    pub async fn join_session(&self, session_uuid: &str) -> Result<serde_json::Value, ApiError> {
        require_identifier(session_uuid)?;

        self.post(
            &format!("/v1/remote/devices/me/sessions/{session_uuid}/join"),
            &serde_json::json!({}),
        )
        .await
    }

    /// Who is waiting for control, who has it, and what is permitted.
    ///
    /// Polled while a session is running, and it is the **only** thing that
    /// puts a consent dialog in front of the person at the machine. A peer
    /// cannot conjure one by sending a data-channel message, because nothing
    /// reads one for this purpose.
    pub async fn session_control(
        &self,
        session_uuid: &str,
    ) -> Result<SessionControlView, ApiError> {
        require_identifier(session_uuid)?;

        self.get(&format!(
            "/v1/remote/devices/me/sessions/{session_uuid}/control"
        ))
        .await
    }

    /// Tell the API what the person at this machine decided about control.
    ///
    /// The agent's own gate has **already** applied the decision by the time
    /// this is called — it is local and needs no network, which is what makes
    /// Stop control trustworthy. This is how the server and the controlling
    /// browser find out, so it is reported after the fact and a failure here
    /// does not leave the machine being controlled.
    pub async fn report_control_decision(
        &self,
        session_uuid: &str,
        participant_uuid: &str,
        decision: ControlDecision,
        allow_clipboard: bool,
    ) -> Result<serde_json::Value, ApiError> {
        require_identifier(session_uuid)?;
        require_identifier(participant_uuid)?;

        self.post(
            &format!("/v1/remote/devices/me/sessions/{session_uuid}/control"),
            &serde_json::json!({
                "participantUuid": participant_uuid,
                "decision": decision.as_str(),
                // Never inferred from the grant. Control and the clipboard are
                // different exposures and the person ticked one of them.
                "allowClipboard": allow_clipboard && decision == ControlDecision::Grant,
            }),
        )
        .await
    }

    /// Join a session as its owner's control companion, after the person at
    /// the machine confirmed the link.
    ///
    /// Idempotent: called again, it returns a fresh signalling token for the
    /// same participant — which is how the companion renews its connection.
    /// The response has the shape of [`Self::join_session`]'s, plus the link.
    pub async fn join_companion(
        &self,
        companion_uuid: &str,
    ) -> Result<serde_json::Value, ApiError> {
        require_identifier(companion_uuid)?;

        self.post(
            &format!("/v1/remote/devices/me/companions/{companion_uuid}/join"),
            &serde_json::json!({}),
        )
        .await
    }

    /// The person at the machine said no to the link.
    pub async fn decline_companion(&self, companion_uuid: &str) -> Result<(), ApiError> {
        require_identifier(companion_uuid)?;

        let _: serde_json::Value = self
            .post(
                &format!("/v1/remote/devices/me/companions/{companion_uuid}/decline"),
                &serde_json::json!({}),
            )
            .await?;

        Ok(())
    }

    /// Report which monitor is the one being shared. Makes the link `ACTIVE`.
    pub async fn report_companion_monitor(
        &self,
        companion_uuid: &str,
        monitor: &Monitor,
        matched_by: MonitorMatch,
    ) -> Result<(), ApiError> {
        require_identifier(companion_uuid)?;

        let _: serde_json::Value = self
            .post(
                &format!("/v1/remote/devices/me/companions/{companion_uuid}/monitor"),
                &companion_monitor_body(monitor, matched_by),
            )
            .await?;

        Ok(())
    }

    /// Leave: the person at the machine ended the link, or the app is quitting.
    pub async fn leave_companion(&self, companion_uuid: &str) -> Result<(), ApiError> {
        require_identifier(companion_uuid)?;

        let _: serde_json::Value = self
            .post(
                &format!("/v1/remote/devices/me/companions/{companion_uuid}/leave"),
                &serde_json::json!({}),
            )
            .await?;

        Ok(())
    }

    /// Switch this machine's own unattended access off.
    pub async fn disable_unattended(&self) -> Result<DeviceResource, ApiError> {
        #[derive(Deserialize)]
        struct Body {
            device: DeviceResource,
        }

        let body: Body = self
            .post(
                "/v1/remote/devices/me/unattended/disable",
                &serde_json::json!({}),
            )
            .await?;

        Ok(body.device)
    }

    async fn get<T: for<'de> Deserialize<'de>>(&self, path: &str) -> Result<T, ApiError> {
        let credential = self.credential.as_ref().ok_or(ApiError::NoCredential)?;
        let url = self.config.endpoint(path)?;

        let response = self
            .http
            .get(url)
            .header(reqwest::header::AUTHORIZATION, credential.header_value())
            .send()
            .await
            .map_err(|error| ApiError::Transport(error.to_string()))?;

        Self::read(response).await
    }

    async fn post<T: for<'de> Deserialize<'de>>(
        &self,
        path: &str,
        body: &serde_json::Value,
    ) -> Result<T, ApiError> {
        let credential = self.credential.as_ref().ok_or(ApiError::NoCredential)?;
        let url = self.config.endpoint(path)?;

        let response = self
            .http
            .post(url)
            .header(reqwest::header::AUTHORIZATION, credential.header_value())
            .json(body)
            .send()
            .await
            .map_err(|error| ApiError::Transport(error.to_string()))?;

        Self::read(response).await
    }

    /// Turn a response into a value or a typed error.
    ///
    /// The API's error shape is `{"error": {"code", "message"}}` throughout,
    /// and the machine `code` is the contract — the agent switches on it, and
    /// shows the `message` to the person. A body that is not that shape gets a
    /// generic code rather than the raw text, because raw text from a proxy or
    /// a WAF is not something to render in a product's window.
    async fn read<T: for<'de> Deserialize<'de>>(
        response: reqwest::Response,
    ) -> Result<T, ApiError> {
        let status = response.status();

        if status.is_success() {
            #[derive(Deserialize)]
            struct Envelope<T> {
                data: T,
            }

            let envelope: Envelope<T> = response
                .json()
                .await
                .map_err(|error| ApiError::Malformed(error.to_string()))?;

            return Ok(envelope.data);
        }

        #[derive(Deserialize)]
        struct ErrorEnvelope {
            error: ApiErrorBody,
        }

        #[derive(Deserialize)]
        struct ApiErrorBody {
            code: String,
            message: String,
        }

        let parsed: Option<ErrorEnvelope> = response.json().await.ok();

        Err(match parsed {
            Some(ErrorEnvelope { error }) => ApiError::Refused {
                status: status.as_u16(),
                code: error.code,
                message: error.message,
            },
            None => ApiError::Refused {
                status: status.as_u16(),
                code: "UNEXPECTED_RESPONSE".into(),
                message: "AICOUNTLY Remote returned something unexpected.".into(),
            },
        })
    }
}

/// Why an API call did not succeed.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum ApiError {
    /// The network, TLS, or a timeout.
    #[error("could not reach AICOUNTLY Remote: {0}")]
    Transport(String),

    /// The API answered, and said no.
    #[error("{message}")]
    Refused {
        /// The HTTP status.
        status: u16,
        /// The machine code the agent switches on.
        code: String,
        /// The sentence to show a person.
        message: String,
    },

    /// The answer was not the shape this build expects.
    #[error("AICOUNTLY Remote returned something this version does not understand: {0}")]
    Malformed(String),

    /// A device-authenticated call with no credential held.
    #[error("this device is not authenticated")]
    NoCredential,

    /// The configuration would not produce a usable URL.
    #[error(transparent)]
    Config(#[from] crate::config::ConfigError),
}

impl ApiError {
    /// Whether this device has been revoked or has stopped being accepted.
    ///
    /// The agent stops rather than retrying, and says so — a revoked device
    /// retrying forever is a machine that looks broken instead of removed.
    #[must_use]
    pub fn is_device_rejected(&self) -> bool {
        matches!(
            self,
            Self::Refused { code, .. }
                if code == "DEVICE_NOT_ACTIVE"
                    || code == "DEVICE_UNAUTHENTICATED"
                    || code == "DEVICE_AUTH_FAILED"
                    || code == "DEVICE_REVOKED"
        )
    }

    /// Whether the organisation is read-only in AICOUNTLY Remote: its subscription lapsed, so Manage's
    /// company lifecycle answers its writes `423 COMPANY_RESTRICTED`. Not this machine's fault and not
    /// final — renewing lifts it — so it is retried with back-off and its own sentence is shown.
    #[must_use]
    pub fn is_company_restricted(&self) -> bool {
        matches!(self, Self::Refused { status: 423, code, .. } if code == "COMPANY_RESTRICTED")
    }

    /// The server's own sentence for a read-only organisation (it names the date, and what to do), when it
    /// is one a window may show: a single short line of plain text. `None` for any other error.
    #[must_use]
    pub fn company_restricted_message(&self) -> Option<&str> {
        match self {
            Self::Refused { message, .. } if self.is_company_restricted() => {
                let message = message.trim();
                let plain = !message.is_empty()
                    && message.chars().count() <= 300
                    && !message
                        .chars()
                        .any(|c| c == '<' || c == '>' || c.is_control());

                plain.then_some(message)
            }
            _ => None,
        }
    }

    /// Whether trying again could plausibly work.
    #[must_use]
    pub fn is_retryable(&self) -> bool {
        match self {
            Self::Transport(_) => true,
            Self::Refused { status, .. } => {
                *status >= 500 || *status == 429 || self.is_company_restricted()
            }
            _ => false,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn credential() -> DeviceCredential {
        DeviceCredential {
            token: "device.super-secret-token-value".into(),
            expires_at: "2026-02-10T09:15:00Z".into(),
            scopes: vec!["device.presence".into(), "device.session".into()],
        }
    }

    /// A credential in a log line is a credential that left the machine.
    #[test]
    fn debug_output_never_contains_the_token() {
        let rendered = format!("{:?}", credential());

        assert!(!rendered.contains("super-secret-token-value"));
        assert!(rendered.contains("expires_at"));
    }

    /// Both loops renew through this, so a credential that is about to expire
    /// has to read as expiring in one place rather than two.
    #[test]
    fn a_credential_knows_when_it_is_nearly_expired() {
        let soon = DeviceCredential {
            token: "t".into(),
            expires_at: (time::OffsetDateTime::now_utc() + Duration::from_secs(30))
                .format(&time::format_description::well_known::Rfc3339)
                .expect("formats"),
            scopes: vec![],
        };
        assert!(soon.is_expiring(Duration::from_secs(60)));
        assert!(!soon.is_expiring(Duration::from_secs(5)));

        let later = DeviceCredential {
            token: "t".into(),
            expires_at: (time::OffsetDateTime::now_utc() + Duration::from_secs(3_600))
                .format(&time::format_description::well_known::Rfc3339)
                .expect("formats"),
            scopes: vec![],
        };
        assert!(!later.is_expiring(Duration::from_secs(60)));
        assert!(later.seconds_remaining().unwrap_or_default() > 3_500);

        // An expiry this build cannot read is treated as expiring: renewing
        // costs a round trip, trusting it costs a refused session.
        let unreadable = DeviceCredential {
            token: "t".into(),
            expires_at: "whenever".into(),
            scopes: vec![],
        };
        assert_eq!(unreadable.seconds_remaining(), None);
        assert!(unreadable.is_expiring(Duration::from_secs(60)));
    }

    #[test]
    fn a_credential_knows_its_own_scopes() {
        let credential = credential();

        assert!(credential.has_scope("device.presence"));
        assert!(!credential.has_scope("device.admin"));
        assert!(credential.header_value().starts_with("Bearer "));
    }

    /// A revoked device stops rather than retrying forever and looking broken.
    #[test]
    fn a_revoked_device_is_recognised_and_not_retried() {
        for code in [
            "DEVICE_NOT_ACTIVE",
            "DEVICE_UNAUTHENTICATED",
            "DEVICE_AUTH_FAILED",
            "DEVICE_REVOKED",
        ] {
            let error = ApiError::Refused {
                status: 403,
                code: code.into(),
                message: "no".into(),
            };

            assert!(error.is_device_rejected(), "{code}");
            assert!(!error.is_retryable(), "{code} must not be retried");
        }
    }

    /// A read-only organisation (AICOUNTLY company lifecycle) is the server's to explain, and renewing
    /// lifts it: its sentence is shown and the agent keeps trying.
    #[test]
    fn a_read_only_organisation_keeps_the_servers_sentence_and_is_retried() {
        let restricted = ApiError::Refused {
            status: 423,
            code: "COMPANY_RESTRICTED".into(),
            message: "This company is read-only until 1 Dec 2026 because its subscription ended."
                .into(),
        };

        assert!(restricted.is_company_restricted());
        assert!(restricted.is_retryable());
        assert!(!restricted.is_device_rejected());
        assert_eq!(
            restricted.company_restricted_message(),
            Some("This company is read-only until 1 Dec 2026 because its subscription ended.")
        );

        let html = ApiError::Refused {
            status: 423,
            code: "COMPANY_RESTRICTED".into(),
            message: "<html>blocked</html>".into(),
        };
        assert_eq!(html.company_restricted_message(), None, "never markup");

        let other = ApiError::Refused {
            status: 423,
            code: "LOCKED".into(),
            message: "y".into(),
        };
        assert!(!other.is_company_restricted());
        assert!(!other.is_retryable());
        assert_eq!(other.company_restricted_message(), None);
    }

    #[test]
    fn transport_failures_and_server_errors_are_retried() {
        assert!(ApiError::Transport("connection reset".into()).is_retryable());
        assert!(ApiError::Refused {
            status: 503,
            code: "X".into(),
            message: "y".into()
        }
        .is_retryable());
        assert!(ApiError::Refused {
            status: 429,
            code: "RATE_LIMITED".into(),
            message: "y".into()
        }
        .is_retryable());

        // A 403 is an answer, not a blip.
        assert!(!ApiError::Refused {
            status: 403,
            code: "X".into(),
            message: "y".into()
        }
        .is_retryable());
    }

    /// The session id goes into a URL path; anything but a plain identifier
    /// would produce a request to a path nobody intended.
    #[tokio::test]
    async fn a_session_identifier_that_could_escape_the_path_is_refused() {
        let mut client = ApiClient::new(AgentConfig::default()).expect("builds");
        client.credential = Some(credential());

        for candidate in [
            "../../../admin",
            "abc/../../v1/remote/company/1/policy",
            "abc?x=1",
            "abc#frag",
            "",
            &"a".repeat(65),
        ] {
            let error = client.join_session(candidate).await.unwrap_err();

            assert!(
                matches!(&error, ApiError::Refused { code, .. } if code == "IDENTIFIER_INVALID"),
                "{candidate} should have been refused, got {error:?}"
            );

            // The same check guards the control report, which carries two
            // identifiers that both arrived over a data channel.
            let error = client
                .report_control_decision(candidate, "participant-1", ControlDecision::Grant, false)
                .await
                .unwrap_err();
            assert!(
                matches!(&error, ApiError::Refused { code, .. } if code == "IDENTIFIER_INVALID")
            );

            let error = client
                .report_control_decision("session-1", candidate, ControlDecision::Grant, false)
                .await
                .unwrap_err();
            assert!(
                matches!(&error, ApiError::Refused { code, .. } if code == "IDENTIFIER_INVALID")
            );
        }
    }

    /// The companion endpoints carry an identifier that arrived over the
    /// network, and refuse one that could escape the path.
    #[tokio::test]
    async fn a_companion_identifier_that_could_escape_the_path_is_refused() {
        let mut client = ApiClient::new(AgentConfig::default()).expect("builds");
        client.credential = Some(credential());

        let monitor = Monitor {
            id: 1,
            name: "Built-in display".into(),
            primary: true,
            x: 0,
            y: 0,
            width: 1512,
            height: 982,
            scale: 2.0,
            refresh_hz: None,
            orientation: remote_protocol::Orientation::Landscape,
        };

        for candidate in ["../sessions/1", "a/b", "", "x?y=1"] {
            let refused = |error: ApiError| matches!(&error, ApiError::Refused { code, .. } if code == "IDENTIFIER_INVALID");

            assert!(refused(client.join_companion(candidate).await.unwrap_err()));
            assert!(refused(
                client.decline_companion(candidate).await.unwrap_err()
            ));
            assert!(refused(
                client.leave_companion(candidate).await.unwrap_err()
            ));
            assert!(refused(
                client
                    .report_companion_monitor(candidate, &monitor, MonitorMatch::User)
                    .await
                    .unwrap_err()
            ));
        }
    }

    /// `GET /devices/me` with a link waiting, in the shape the API renders.
    #[test]
    fn pending_companions_are_read_from_the_device_description() {
        let body = serde_json::json!({
            "device": {
                "uuid": "device-1", "deviceName": "Rahul's MacBook", "status": "ACTIVE",
                "companyId": 481, "keyFingerprint": "AAAA", "unattendedAccessEnabled": false,
                "unattendedEnabledAt": null, "unattendedLastUsedAt": null,
                "capabilities": AgentCapabilities::companion_only(),
            },
            "capabilities": AgentCapabilities::companion_only(),
            "policy": {
                "allowRemoteControl": true, "allowUnattendedAccess": false,
                "allowClipboardSync": false, "allowDeviceReboot": false, "allowFileTransfer": false,
            },
            "realtime": { "iceServers": [], "relayAvailable": false, "presenceIntervalSeconds": 60 },
            "agent": { "minimumVersion": "1.0.0", "updateFeedUrl": null, "clipboardMaxBytes": 65536 },
            "pendingSessions": [],
            "pendingCompanions": [{
                "companionUuid": "companion-1",
                "sessionUuid": "session-1",
                "displayId": "123-456",
                "companyName": "Northwind",
                "requestedByName": "Rahul Gupta",
                "requestedAt": "2026-10-25T09:00:00Z",
                "expiresAt": "2026-10-25T09:05:00Z",
            }],
        });

        let described: DeviceSelf = serde_json::from_value(body.clone()).expect("parses");
        let pending = &described.pending_companions[0];

        assert_eq!(pending.companion_uuid, "companion-1");
        assert_eq!(pending.session_uuid, "session-1");
        assert_eq!(pending.display_id, "123-456");
        assert_eq!(pending.company_name.as_deref(), Some("Northwind"));
        assert_eq!(pending.requested_by_name.as_deref(), Some("Rahul Gupta"));
        assert_eq!(pending.expires_at.as_deref(), Some("2026-10-25T09:05:00Z"));

        // An API from before companions sends no such key: none waiting.
        let mut older = body;
        older.as_object_mut().unwrap().remove("pendingCompanions");
        let described: DeviceSelf = serde_json::from_value(older).expect("parses");
        assert!(described.pending_companions.is_empty());
    }

    /// The device control view with the companion's additions.
    #[test]
    fn the_control_view_carries_the_companion_the_surface_and_the_grant() {
        let view: SessionControlView = serde_json::from_value(serde_json::json!({
            "controllableHostUuid": "companion-participant",
            "controllerUuid": "participant-b",
            "controllerName": "Sam",
            "clipboardEnabled": false,
            "pendingRequests": [],
            "allowRemoteControl": true,
            "allowClipboardSync": false,
            "allowDeviceReboot": false,
            "grantId": "participant-b:2026-10-25T09:01:00Z",
            "companion": {
                "uuid": "companion-1",
                "status": "ACTIVE",
                "deviceName": "Rahul's MacBook",
                "deviceUuid": "device-1",
                "linkedParticipantUuid": "participant-a",
                "participantUuid": "companion-participant",
                "monitor": { "id": 1, "name": "Built-in display", "x": 0, "y": 0, "width": 1512, "height": 982, "scale": 2.0 },
                "ready": true,
            },
            "sharedSurface": { "displaySurface": "monitor", "width": 3024, "height": 1964, "verified": true },
            "controlBlockedReason": null,
        }))
        .expect("parses");

        assert_eq!(
            view.grant_id.as_deref(),
            Some("participant-b:2026-10-25T09:01:00Z")
        );

        let companion = view.companion.as_ref().expect("a companion");
        assert_eq!(
            companion.linked_participant_uuid.as_deref(),
            Some("participant-a")
        );
        assert!(companion.ready);
        assert!(!companion.is_over());

        let surface = view.shared_surface.expect("a surface");
        assert_eq!(surface.display_surface, SurfaceKind::Monitor);
        assert_eq!(surface.size(), Some((3024, 1964)));
        assert!(surface.verified);
        assert!(view.control_blocked_reason.is_none());

        for status in ["ENDED", "EXPIRED", "DECLINED"] {
            let over = CompanionView {
                status: status.into(),
                ..companion.clone()
            };
            assert!(over.is_over(), "{status}");
        }
    }

    /// Firefox and Safari report `unknown`; a window is not a monitor; a
    /// browser that reported no size cannot be matched by size.
    #[test]
    fn a_surface_that_is_not_a_monitor_reads_as_what_it_is() {
        let parse = |value: serde_json::Value| -> SharedSurface {
            serde_json::from_value(value).expect("parses")
        };

        assert_eq!(
            parse(serde_json::json!({"displaySurface": "window", "width": 800, "height": 600}))
                .display_surface,
            SurfaceKind::Other
        );
        assert_eq!(
            parse(serde_json::json!({"displaySurface": "unknown"})).display_surface,
            SurfaceKind::Unknown
        );
        assert_eq!(
            parse(serde_json::json!({})).display_surface,
            SurfaceKind::Unknown
        );
        assert_eq!(
            parse(serde_json::json!({"width": 0, "height": 900})).size(),
            None
        );

        // An older API's control view has none of the companion's keys.
        let view: SessionControlView = serde_json::from_value(serde_json::json!({})).unwrap();
        assert!(
            view.companion.is_none() && view.shared_surface.is_none() && view.grant_id.is_none()
        );
    }

    /// What `POST …/monitor` sends: the monitor in injection space and how it
    /// was chosen, in the API's spelling.
    #[test]
    fn the_monitor_report_is_shaped_the_way_the_api_reads_it() {
        let monitor = Monitor {
            id: 69_733_382,
            name: "Built-in display".into(),
            primary: true,
            x: 0,
            y: 0,
            width: 1512,
            height: 982,
            scale: 2.0,
            refresh_hz: Some(120),
            orientation: remote_protocol::Orientation::Landscape,
        };

        let body = companion_monitor_body(&monitor, MonitorMatch::AutoAspect);

        assert_eq!(
            body,
            serde_json::json!({
                "monitor": {
                    "id": 69_733_382, "name": "Built-in display", "x": 0, "y": 0,
                    "width": 1512, "height": 982, "scale": 2.0,
                },
                "matchedBy": "AUTO_ASPECT",
            })
        );

        for kind in [
            MonitorMatch::AutoSingle,
            MonitorMatch::AutoAspect,
            MonitorMatch::User,
        ] {
            assert_eq!(MonitorMatch::from_api(kind.as_str()), Some(kind));
            assert_eq!(
                serde_json::to_value(kind).unwrap(),
                serde_json::json!(kind.as_str())
            );
        }
        assert_eq!(MonitorMatch::from_api("GUESSED"), None);
    }

    /// The clipboard is a separate exposure and never rides along with a
    /// decision that is not a grant.
    #[test]
    fn the_three_decisions_are_spelled_the_way_the_api_reads_them() {
        assert_eq!(ControlDecision::Grant.as_str(), "GRANT");
        assert_eq!(ControlDecision::Deny.as_str(), "DENY");
        assert_eq!(ControlDecision::Revoke.as_str(), "REVOKE");
    }

    #[tokio::test]
    async fn a_device_call_with_no_credential_is_refused_before_the_network() {
        let client = ApiClient::new(AgentConfig::default()).expect("builds");

        assert_eq!(
            client.describe_self().await.unwrap_err(),
            ApiError::NoCredential
        );
    }

    /// An older API does not send `allowedShareModes` at all, and an agent
    /// that read silence as refusal would stop sharing the day it met one.
    #[test]
    fn a_policy_that_does_not_mention_share_modes_constrains_nothing() {
        let policy: DevicePolicyView = serde_json::from_value(serde_json::json!({
            "allowRemoteControl": true,
            "allowUnattendedAccess": true,
            "allowClipboardSync": true,
            "allowDeviceReboot": true,
            "allowFileTransfer": true
        }))
        .unwrap();

        assert_eq!(policy.allowed_share_modes, None);
        assert!(policy.allows_entire_monitor());
        assert!(policy.as_capabilities().screen_share);
    }

    /// And an organisation that permits no surface at all is a different
    /// thing from a server that did not say, which is the whole reason the
    /// field is an `Option`.
    #[test]
    fn a_policy_that_permits_no_surface_is_not_the_same_as_silence() {
        let nothing = policy_allowing(Some(vec![]));

        assert!(!nothing.allows_entire_monitor());
        assert!(!nothing.as_capabilities().screen_share);
    }

    /// The surfaces a browser can offer and this agent cannot. An
    /// organisation that allows a window but not a screen allows this machine
    /// nothing: there is no window picker here.
    #[test]
    fn the_surfaces_this_agent_cannot_offer_do_not_let_it_share() {
        for modes in [
            vec!["SAFE_SHARE".to_owned()],
            vec!["BROWSER_TAB".to_owned()],
            vec!["APPLICATION_WINDOW".to_owned()],
            vec!["SAFE_SHARE".to_owned(), "APPLICATION_WINDOW".to_owned()],
        ] {
            let policy = policy_allowing(Some(modes.clone()));

            assert!(
                !policy.allows_entire_monitor(),
                "a whole screen is not among {modes:?}"
            );
        }

        for modes in [
            vec![ENTIRE_MONITOR.to_owned()],
            vec!["APPLICATION_WINDOW".to_owned(), ENTIRE_MONITOR.to_owned()],
        ] {
            assert!(policy_allowing(Some(modes)).allows_entire_monitor());
        }
    }

    /// The field as the API actually sends it, parsed from the wire rather
    /// than built in Rust.
    #[test]
    fn share_modes_are_read_from_the_policy_block() {
        let policy: DevicePolicyView = serde_json::from_value(serde_json::json!({
            "allowRemoteControl": true,
            "allowUnattendedAccess": true,
            "allowClipboardSync": true,
            "allowDeviceReboot": true,
            "allowFileTransfer": true,
            "allowedShareModes": ["SAFE_SHARE", "APPLICATION_WINDOW"],
            "restrictions": []
        }))
        .unwrap();

        assert!(!policy.allows_entire_monitor());

        let effective = AgentCapabilities::windows().intersect(policy.as_capabilities());
        assert!(
            !effective.screen_share,
            "the declaration cannot add it back"
        );
    }

    fn policy_allowing(modes: Option<Vec<String>>) -> DevicePolicyView {
        DevicePolicyView {
            allow_remote_control: true,
            allow_unattended_access: true,
            allow_clipboard_sync: true,
            allow_device_reboot: true,
            allow_file_transfer: true,
            restrictions: Vec::new(),
            allowed_share_modes: modes,
        }
    }

    /// The body the API reads field by field, in the names it reads them by.
    #[test]
    fn a_sign_in_request_is_sent_in_the_names_the_api_reads() {
        let body = serde_json::to_value(DesktopSignInRequest {
            device_name: "WS-01".into(),
            public_key: "AAAA".into(),
            device_type: "DESKTOP".into(),
            operating_system: "Windows".into(),
            os_version: "11 24H2".into(),
            architecture: "x86_64".into(),
            hostname: "WS-01".into(),
            agent_version: "1.0.0".into(),
            capabilities: AgentCapabilities::windows(),
        })
        .unwrap();

        assert_eq!(body["deviceName"], "WS-01");
        assert_eq!(body["publicKey"], "AAAA");
        assert_eq!(body["deviceType"], "DESKTOP");
        assert_eq!(body["operatingSystem"], "Windows");
        assert_eq!(body["osVersion"], "11 24H2");
        assert_eq!(body["architecture"], "x86_64");
        assert_eq!(body["hostname"], "WS-01");
        assert_eq!(body["agentVersion"], "1.0.0");
        assert!(body["capabilities"].is_object());
    }

    /// `POST /desktop-signin/start`, as the API answers it.
    #[test]
    fn the_code_to_show_is_read_from_the_start_response() {
        let start: DesktopSignInStart = serde_json::from_value(serde_json::json!({
            "userCode": "ABCD-1234",
            "deviceCode": "a".repeat(64),
            "verificationUri": "https://remote.aicountly.com/desktop-signin",
            "verificationUriComplete": "https://remote.aicountly.com/desktop-signin?code=ABCD-1234",
            "expiresAt": "2026-09-15T12:10:00Z",
            "intervalSeconds": 2
        }))
        .unwrap();

        assert_eq!(start.user_code, "ABCD-1234");
        assert_eq!(start.interval_seconds, 2);
        assert!(start.verification_uri_complete.ends_with("?code=ABCD-1234"));
    }

    /// A poll that is still waiting carries a status and nothing else, and
    /// travels back to the window in the same shape it arrived in.
    #[test]
    fn a_pending_poll_carries_nothing_but_its_status() {
        let outcome: DesktopSignInOutcome =
            serde_json::from_value(serde_json::json!({ "status": "pending" })).unwrap();

        assert_eq!(outcome.status, DesktopSignInStatus::Pending);
        assert!(outcome.device.is_none());

        assert_eq!(
            serde_json::to_value(&outcome).unwrap(),
            serde_json::json!({ "status": "pending" }),
            "an absent device is absent, not null: the window reads a union on status"
        );
    }

    /// And a confirmed one carries the device the server enrolled.
    #[test]
    fn a_confirmed_poll_carries_the_device() {
        let outcome: DesktopSignInOutcome = serde_json::from_value(serde_json::json!({
            "status": "confirmed",
            "companyName": "Northwind",
            "device": {
                "uuid": "device-1",
                "deviceName": "WS-01",
                "status": "ACTIVE",
                "companyId": 481,
                "keyFingerprint": "AAAA BBBB",
                "unattendedAccessEnabled": false,
                "capabilities": {
                    "screen_share": true,
                    "screen_view": true,
                    "remote_control": true,
                    "unattended_access": false,
                    "file_transfer": false,
                    "clipboard_sync": true,
                    "reboot": false
                }
            }
        }))
        .unwrap();

        assert_eq!(outcome.status, DesktopSignInStatus::Confirmed);
        assert_eq!(outcome.company_name.as_deref(), Some("Northwind"));
        assert_eq!(outcome.device.expect("a device").device_name, "WS-01");
    }

    /// The property that made these two calls move out of the web view: they
    /// are the only ones that work before the machine has a credential.
    #[tokio::test]
    async fn signing_in_needs_no_credential() {
        let client = ApiClient::new(AgentConfig::default()).unwrap();

        // No `NoCredential`: whatever happens, it is not refused for want of
        // one, which is what every other call on this client would do.
        for error in [
            client
                .start_desktop_sign_in(&DesktopSignInRequest {
                    device_name: "WS-01".into(),
                    public_key: "AAAA".into(),
                    device_type: "DESKTOP".into(),
                    operating_system: "Windows".into(),
                    os_version: "11".into(),
                    architecture: "x86_64".into(),
                    hostname: "WS-01".into(),
                    agent_version: "1.0.0".into(),
                    capabilities: AgentCapabilities::windows(),
                })
                .await
                .err(),
            client.poll_desktop_sign_in("a").await.err(),
        ] {
            assert!(
                !matches!(error, Some(ApiError::NoCredential)),
                "a device-code call must not ask for a credential"
            );
        }
    }

    #[test]
    fn the_policy_view_becomes_a_capability_ceiling() {
        let policy = DevicePolicyView {
            allow_remote_control: true,
            allow_unattended_access: false,
            allow_clipboard_sync: false,
            allow_device_reboot: true,
            allow_file_transfer: true,
            restrictions: vec!["UNATTENDED_ACCESS_NOT_ENTITLED".into()],
            allowed_share_modes: Some(vec![ENTIRE_MONITOR.into()]),
        };

        let ceiling = policy.as_capabilities();

        assert!(ceiling.remote_control);
        assert!(!ceiling.unattended_access);
        assert!(!ceiling.clipboard_sync);
        assert!(ceiling.screen_share);

        // And the declaration intersected with it removes what policy refuses.
        let effective = AgentCapabilities::windows().intersect(ceiling);
        assert!(!effective.unattended_access);
        assert!(effective.reboot);
    }

    #[test]
    fn a_device_resource_knows_whether_it_is_usable() {
        let active = DeviceResource {
            uuid: "u".into(),
            device_name: "WS-01".into(),
            status: "ACTIVE".into(),
            company_id: Some(481),
            key_fingerprint: Some("AAAA BBBB".into()),
            unattended_access_enabled: false,
            unattended_enabled_at: None,
            unattended_last_used_at: None,
            capabilities: AgentCapabilities::windows(),
        };

        assert!(active.is_active());
        assert!(!active.is_revoked());

        let revoked = DeviceResource {
            status: "REVOKED".into(),
            ..active
        };
        assert!(!revoked.is_active());
        assert!(revoked.is_revoked());
    }

    /// The enrolment body carries the public key and nothing else that matters.
    #[test]
    fn the_enrolment_request_carries_only_the_public_half() {
        let keys = DeviceKeypair::generate();

        let request = EnrolmentRequest {
            company_id: 481,
            device_name: "WS-01".into(),
            public_key: keys.public_key_base64(),
            device_type: "DESKTOP".into(),
            operating_system: "Windows".into(),
            os_version: "11 24H2".into(),
            architecture: "x86_64".into(),
            hostname: "WS-01".into(),
            agent_version: crate::AGENT_VERSION.into(),
            capabilities: AgentCapabilities::windows(),
        };

        let json = serde_json::to_string(&request).unwrap();
        let secret =
            remote_security::DeviceKeypair::from_secret_bytes(keys.secret_bytes().as_ref())
                .unwrap()
                .secret_bytes();

        assert!(json.contains("publicKey"));
        assert!(
            !json.contains(&remote_security::display_fingerprint(&hex_of(
                secret.as_ref()
            )))
        );
        assert!(!json.to_lowercase().contains("privatekey"));
        assert!(!json.to_lowercase().contains("secret"));
    }

    fn hex_of(bytes: &[u8]) -> String {
        bytes.iter().map(|b| format!("{b:02x}")).collect()
    }
}
