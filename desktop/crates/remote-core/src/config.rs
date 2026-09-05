//! The agent's configuration, and what it refuses to accept.
//!
//! Two rules shape everything here:
//!
//! * **No secret is a configuration value.** The device private key is in the
//!   operating system's key store; the device credential lives in memory for
//!   its few minutes; the TURN credentials come from the API per session. This
//!   file holds a URL and some numbers, and that is all it *can* hold — there
//!   is no field for a token.
//! * **HTTPS, unless a developer says otherwise at compile time.** A
//!   remote-control agent talking to a plaintext endpoint is a remote-control
//!   agent whose traffic is on the wire. `http://` is refused in a release
//!   build, and only accepted for `localhost` in a debug one.

use serde::{Deserialize, Serialize};

/// Where the agent talks to, and how eagerly.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct AgentConfig {
    /// The Remote API, including its path prefix.
    ///
    /// `https://remote.aicountly.com/api`, not the bare host: the deployment
    /// serves the SPA at the root and the API underneath it.
    pub api_base_url: String,

    /// The portal the desktop sign-in flow opens.
    pub portal_url: String,

    /// How often to say the machine is still there, in seconds.
    ///
    /// The server sends its own figure and the agent adopts it, so the two
    /// cannot disagree about what "stale" means. This applies until the first
    /// `GET /devices/me` answers.
    pub presence_interval_seconds: u64,

    /// Whether to keep the agent running when the window is closed.
    ///
    /// On by default, because that is what a tray application is for — but a
    /// *visible* choice, not a silent one, and closing the window is spelled
    /// differently from quitting in the UI.
    pub run_in_background: bool,

    /// Start the agent when the user signs in.
    pub start_at_login: bool,

    /// Which capture profile to prefer.
    pub capture_quality: String,
}

impl Default for AgentConfig {
    fn default() -> Self {
        Self {
            api_base_url: "https://remote.aicountly.com/api".into(),
            portal_url: "https://my.aicountly.com".into(),
            presence_interval_seconds: 60,
            run_in_background: true,
            start_at_login: true,
            capture_quality: "adaptive".into(),
        }
    }
}

impl AgentConfig {
    /// Parse and validate a configuration document.
    pub fn from_json(json: &str) -> Result<Self, ConfigError> {
        let config: Self = serde_json::from_str(json).map_err(|error| ConfigError::Malformed(error.to_string()))?;
        config.validate()?;

        Ok(config)
    }

    /// Serialise for writing back.
    ///
    /// There is nothing secret in here, which is exactly why it can be written
    /// to an ordinary file — and why nothing else may be added to this struct.
    #[must_use]
    pub fn to_json(&self) -> String {
        serde_json::to_string_pretty(self).unwrap_or_else(|_| "{}".into())
    }

    /// Everything that has to be true before the agent will use this.
    pub fn validate(&self) -> Result<(), ConfigError> {
        validate_endpoint(&self.api_base_url, "API")?;
        validate_endpoint(&self.portal_url, "portal")?;

        if !(15..=600).contains(&self.presence_interval_seconds) {
            return Err(ConfigError::OutOfRange {
                field: "presenceIntervalSeconds",
                detail: "must be between 15 and 600 seconds",
            });
        }

        if !["adaptive", "low_bandwidth", "high_quality"].contains(&self.capture_quality.as_str()) {
            return Err(ConfigError::OutOfRange {
                field: "captureQuality",
                detail: "must be adaptive, low_bandwidth or high_quality",
            });
        }

        Ok(())
    }

    /// Build a URL under the API base.
    ///
    /// Refuses a path that is not rooted, so no caller can accidentally
    /// concatenate its way onto a different host by passing something starting
    /// `//` or a full URL of its own.
    pub fn endpoint(&self, path: &str) -> Result<String, ConfigError> {
        if !path.starts_with('/') || path.starts_with("//") {
            return Err(ConfigError::OutOfRange {
                field: "path",
                detail: "an API path must start with a single '/'",
            });
        }

        Ok(format!("{}{path}", self.api_base_url.trim_end_matches('/')))
    }

    /// The signalling and API origin, for the diagnostics panel.
    #[must_use]
    pub fn api_origin(&self) -> &str {
        &self.api_base_url
    }
}

/// HTTPS, or `http://localhost` in a debug build and nowhere else.
fn validate_endpoint(url: &str, what: &'static str) -> Result<(), ConfigError> {
    if url.starts_with("https://") {
        return Ok(());
    }

    // A developer running the API on their own machine needs this; a customer
    // must never get it, so the exception is compiled out of a release build
    // rather than being a runtime flag somebody could set.
    #[cfg(debug_assertions)]
    if url.starts_with("http://localhost") || url.starts_with("http://127.0.0.1") {
        return Ok(());
    }

    Err(ConfigError::InsecureEndpoint(what))
}

/// Why a configuration was refused.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum ConfigError {
    /// Not valid JSON, or not this shape.
    #[error("the configuration could not be read: {0}")]
    Malformed(String),

    /// A plaintext endpoint outside a debug build.
    #[error("the {0} address must be https")]
    InsecureEndpoint(&'static str),

    /// A value outside its permitted range.
    #[error("{field} {detail}")]
    OutOfRange {
        /// Which field.
        field: &'static str,
        /// What is wrong with it.
        detail: &'static str,
    },
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_default_configuration_is_valid_and_points_at_production() {
        let config = AgentConfig::default();

        assert!(config.validate().is_ok());
        assert!(config.api_base_url.starts_with("https://"));
        assert!(config.api_base_url.ends_with("/api"));
    }

    /// A remote-control agent talking over plaintext is a remote-control agent
    /// whose traffic is on the wire.
    #[test]
    fn a_plaintext_endpoint_is_refused() {
        let config = AgentConfig {
            api_base_url: "http://remote.aicountly.com/api".into(),
            ..AgentConfig::default()
        };

        assert_eq!(
            config.validate(),
            Err(ConfigError::InsecureEndpoint("API"))
        );
    }

    /// Localhost is allowed only in a debug build, and the exception is
    /// compiled out rather than being a flag somebody could set.
    #[test]
    fn localhost_is_a_development_only_exception() {
        let config = AgentConfig {
            api_base_url: "http://localhost:8080/api".into(),
            ..AgentConfig::default()
        };

        #[cfg(debug_assertions)]
        assert!(config.validate().is_ok());

        #[cfg(not(debug_assertions))]
        assert_eq!(config.validate(), Err(ConfigError::InsecureEndpoint("API")));
    }

    #[test]
    fn a_round_trip_through_json_preserves_everything() {
        let config = AgentConfig {
            presence_interval_seconds: 45,
            run_in_background: false,
            capture_quality: "low_bandwidth".into(),
            ..AgentConfig::default()
        };

        let parsed = AgentConfig::from_json(&config.to_json()).expect("parses");

        assert_eq!(parsed, config);
    }

    #[test]
    fn a_malformed_document_is_refused_with_a_reason() {
        assert!(matches!(
            AgentConfig::from_json("not json"),
            Err(ConfigError::Malformed(_))
        ));

        assert!(matches!(
            AgentConfig::from_json(r#"{"apiBaseUrl": 42}"#),
            Err(ConfigError::Malformed(_))
        ));
    }

    #[test]
    fn an_out_of_range_presence_interval_is_refused() {
        for seconds in [0, 14, 601, u64::MAX] {
            let config = AgentConfig {
                presence_interval_seconds: seconds,
                ..AgentConfig::default()
            };

            assert!(config.validate().is_err(), "{seconds} should be refused");
        }
    }

    #[test]
    fn an_unknown_capture_quality_is_refused() {
        let config = AgentConfig {
            capture_quality: "ultra".into(),
            ..AgentConfig::default()
        };

        assert!(config.validate().is_err());
    }

    /// The configuration is written to an ordinary file, so it must be
    /// incapable of holding a secret at all.
    #[test]
    fn the_configuration_has_no_field_a_secret_could_live_in() {
        let json = AgentConfig::default().to_json().to_lowercase();

        for forbidden in ["token", "secret", "password", "credential", "key"] {
            assert!(
                !json.contains(forbidden),
                "the configuration must have no {forbidden} field"
            );
        }
    }

    /// A path that is not rooted could concatenate its way onto another host.
    #[test]
    fn an_endpoint_path_must_be_rooted() {
        let config = AgentConfig::default();

        assert_eq!(
            config.endpoint("/v1/remote/devices/me").unwrap(),
            "https://remote.aicountly.com/api/v1/remote/devices/me"
        );

        assert!(config.endpoint("v1/remote/devices").is_err());
        assert!(config.endpoint("//evil.example.com/steal").is_err());
    }

    #[test]
    fn a_trailing_slash_on_the_base_does_not_double_up() {
        let config = AgentConfig {
            api_base_url: "https://remote.aicountly.com/api/".into(),
            ..AgentConfig::default()
        };

        assert_eq!(
            config.endpoint("/health").unwrap(),
            "https://remote.aicountly.com/api/health"
        );
    }
}
