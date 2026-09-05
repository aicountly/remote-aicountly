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

/// The device resource, as the API renders it.
#[derive(Debug, Clone, PartialEq, Deserialize)]
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
}

impl DevicePolicyView {
    /// The policy as a capability set, for intersecting with the declaration.
    #[must_use]
    pub fn as_capabilities(&self) -> AgentCapabilities {
        AgentCapabilities {
            screen_share: true,
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

    /// Whether trying again could plausibly work.
    #[must_use]
    pub fn is_retryable(&self) -> bool {
        match self {
            Self::Transport(_) => true,
            Self::Refused { status, .. } => *status >= 500 || *status == 429,
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

    #[test]
    fn the_policy_view_becomes_a_capability_ceiling() {
        let policy = DevicePolicyView {
            allow_remote_control: true,
            allow_unattended_access: false,
            allow_clipboard_sync: false,
            allow_device_reboot: true,
            allow_file_transfer: true,
            restrictions: vec!["UNATTENDED_ACCESS_NOT_ENTITLED".into()],
        };

        let ceiling = policy.as_capabilities();

        assert!(ceiling.remote_control);
        assert!(!ceiling.unattended_access);
        assert!(!ceiling.clipboard_sync);

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
