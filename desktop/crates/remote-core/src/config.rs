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
        let config: Self = serde_json::from_str(json)
            .map_err(|error| ConfigError::Malformed(error.to_string()))?;
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

    /// Read the machine's configuration, or the default when there is none.
    ///
    /// A missing file is not an error: a fresh installation has none, and the
    /// default points at production. A *corrupt* file is not an error either —
    /// an agent that refuses to start because somebody edited a JSON file is
    /// an agent somebody has to visit the machine to fix, which is the one
    /// thing a remote-support tool must not need.
    #[must_use]
    pub fn load() -> Self {
        let Some(path) = config_path() else {
            return Self::default();
        };

        let Ok(text) = std::fs::read_to_string(path) else {
            return Self::default();
        };

        Self::from_json(&text).unwrap_or_default()
    }

    /// Write the configuration back, creating the directory if needed.
    ///
    /// Written atomically — to a temporary file in the same directory, then
    /// renamed — so a machine that loses power mid-write comes back with the
    /// previous configuration rather than half of the new one.
    pub fn save(&self) -> Result<(), ConfigError> {
        self.validate()?;

        let path = config_path().ok_or(ConfigError::OutOfRange {
            field: "configPath",
            detail: "could not be determined on this machine",
        })?;

        let directory = path.parent().ok_or(ConfigError::OutOfRange {
            field: "configPath",
            detail: "has no parent directory",
        })?;

        std::fs::create_dir_all(directory).map_err(not_saved)?;

        let temporary = directory.join("config.json.new");

        std::fs::write(&temporary, self.to_json()).map_err(|error| {
            let _ = std::fs::remove_file(&temporary);

            not_saved(error)
        })?;

        std::fs::rename(&temporary, &path).map_err(|error| {
            // Leaving the temporary file behind would make the next save look
            // as though it had succeeded before.
            let _ = std::fs::remove_file(&temporary);

            not_saved(error)
        })
    }
}

/// A failed write, said as what it is.
///
/// The data directory is writable by SYSTEM, administrators **and the Users
/// group** (see `installers/windows/hooks.nsh`), because the tray application
/// runs as whoever is signed in and Save is theirs to press. So "access
/// denied" here is not the ordinary outcome of Save by a non-administrator —
/// it means the directory's own permissions are wrong, which is what the
/// message says and what reinstalling repairs. Either way it is not a
/// configuration that "could not be read".
fn not_saved(error: std::io::Error) -> ConfigError {
    if error.kind() == std::io::ErrorKind::PermissionDenied {
        ConfigError::NotPermitted
    } else {
        ConfigError::NotSaved(error.to_string())
    }
}

/// Where the configuration lives.
///
/// Machine-wide rather than per-user, and deliberately: the service runs as
/// `LocalSystem` and the tray application runs as whoever is signed in, and
/// the two must read the same endpoint. A per-user file would give a machine
/// as many configurations as it has accounts.
///
/// The file holds a URL and some numbers. There is no field in
/// [`AgentConfig`] for a secret, which is what makes an ordinary file the
/// right place for it — the device key is in the operating system's key store
/// and never comes near this path.
#[must_use]
pub fn config_path() -> Option<std::path::PathBuf> {
    #[cfg(windows)]
    {
        std::env::var_os("ProgramData").map(|root| {
            std::path::PathBuf::from(root)
                .join("AICOUNTLY")
                .join("Remote")
                .join("config.json")
        })
    }

    #[cfg(target_os = "macos")]
    {
        // Per user, in Application Support: there is no background service on
        // macOS yet, so the only reader is the application itself, running as
        // the person signed in. The macOS test build's INSTALL.txt copies its
        // config.json to exactly this path.
        std::env::var_os("HOME").map(|home| macos_support_directory(&home).join("config.json"))
    }

    #[cfg(not(any(windows, target_os = "macos")))]
    {
        // Only used by developers running the agent's portable half on a
        // workstation; the shipped product is Windows and macOS.
        std::env::var_os("XDG_CONFIG_HOME")
            .map(std::path::PathBuf::from)
            .or_else(|| {
                std::env::var_os("HOME").map(|home| std::path::PathBuf::from(home).join(".config"))
            })
            .map(|root| root.join("aicountly-remote").join("config.json"))
    }
}

/// `~/Library/Application Support/AICOUNTLY/Remote`, under a given home.
///
/// Where the macOS agent keeps its configuration and its enrolment record.
/// Written out here rather than only inside the macOS branch so the path is
/// tested on every host.
#[must_use]
pub fn macos_support_directory(home: &std::ffi::OsStr) -> std::path::PathBuf {
    std::path::PathBuf::from(home)
        .join("Library")
        .join("Application Support")
        .join("AICOUNTLY")
        .join("Remote")
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

    /// The configuration file could not be written.
    #[error("the settings could not be saved: {0}")]
    NotSaved(String),

    /// The configuration file is not writable by this account.
    ///
    /// Not an ordinary outcome: the installer grants the Users group modify
    /// on the data directory precisely so that the person at the machine can
    /// change a setting. Seeing this means the directory's permissions are
    /// not the ones the installer sets.
    #[error(
        "the settings could not be saved: this computer's AICOUNTLY Remote data folder is not \
         writable. Reinstalling AICOUNTLY Remote repairs its permissions."
    )]
    NotPermitted,

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

    /// The configuration is a URL and some numbers. If a secret is ever added
    /// to it, this fails — which is the point, because the file is an
    /// ordinary one and the whole reason that is safe is that nothing secret
    /// is in it.
    #[test]
    fn the_configuration_holds_nothing_secret() {
        let json = AgentConfig::default().to_json().to_lowercase();

        for forbidden in ["token", "secret", "password", "key", "credential", "bearer"] {
            assert!(
                !json.contains(forbidden),
                "the configuration file must never carry a {forbidden}"
            );
        }
    }

    /// A machine-wide path, so the service running as LocalSystem and the tray
    /// application running as a signed-in user read the same endpoint.
    #[test]
    fn the_configuration_path_is_machine_wide_and_named_predictably() {
        // The environment decides, and a machine with neither variable set
        // simply has no path — the agent then runs on the default rather than
        // refusing to start.
        if let Some(path) = config_path() {
            assert!(path.ends_with("config.json"));
        }
    }

    /// The macOS test build tells its tester to copy config.json to exactly
    /// this path, so it must not drift.
    #[test]
    fn the_macos_configuration_lives_in_application_support() {
        let directory = macos_support_directory(std::ffi::OsStr::new("/Users/rahul"));

        assert_eq!(
            directory.join("config.json"),
            std::path::PathBuf::from(
                "/Users/rahul/Library/Application Support/AICOUNTLY/Remote/config.json"
            )
        );
    }

    /// The file the macOS test build ships parses as a configuration.
    #[test]
    fn the_macos_test_builds_config_file_is_a_valid_configuration() {
        let shipped = r#"{
            "apiBaseUrl": "https://remote.gh.aicountly.com/api",
            "portalUrl": "https://my.aicountly.com",
            "presenceIntervalSeconds": 60,
            "runInBackground": true,
            "startAtLogin": false,
            "captureQuality": "adaptive"
        }"#;

        let config = AgentConfig::from_json(shipped).expect("valid");
        assert_eq!(config.api_base_url, "https://remote.gh.aicountly.com/api");
        assert!(!config.start_at_login);
    }

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

        assert_eq!(config.validate(), Err(ConfigError::InsecureEndpoint("API")));
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

    /// The installer grants the Users group modify on the data directory, so
    /// a refused write is a broken installation rather than a person without
    /// rights — and the message has to point at the thing that fixes it.
    #[test]
    fn a_refused_write_says_the_data_folder_is_not_writable() {
        let denied = std::io::Error::from(std::io::ErrorKind::PermissionDenied);

        assert_eq!(not_saved(denied), ConfigError::NotPermitted);

        let message = ConfigError::NotPermitted.to_string();

        assert!(message.contains("not"), "{message}");
        assert!(message.contains("writable"), "{message}");
        assert!(message.contains("Reinstalling"), "{message}");
        assert!(
            !message.contains("administrator"),
            "an ordinary person pressing Save is not the problem: {message}"
        );
    }

    #[test]
    fn any_other_failed_write_is_reported_as_not_saved_and_not_as_unreadable() {
        let other = std::io::Error::other("disk full");

        match not_saved(other) {
            ConfigError::NotSaved(reason) => assert!(reason.contains("disk full")),
            unexpected => panic!("expected NotSaved, got {unexpected:?}"),
        }

        let message = ConfigError::NotSaved("x".into()).to_string();

        assert!(message.contains("could not be saved"));
        assert!(!message.contains("could not be read"));
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
