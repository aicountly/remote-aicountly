//! The portable heart of the desktop agent.
//!
//! Everything that is not a native call and not a pixel: the configuration,
//! the API client, the state machine that decides what the agent is doing, and
//! the backoff that decides when it tries again.
//!
//! Kept here rather than in `src-tauri` so that it compiles and is tested on
//! any host — which is what makes the Windows-only part of the agent small
//! enough to reason about.

#![forbid(unsafe_code)]
#![deny(missing_docs)]

pub mod api;
pub mod backoff;
pub mod config;
pub mod state;

pub use api::{ApiClient, ApiError, DeviceCredential};
pub use backoff::Backoff;
pub use config::{config_path, AgentConfig, ConfigError};
pub use state::{
    AgentEvent, AgentState, AgentStatus, ControlStateView, ControlSummary, SessionSummary,
    UnattendedState,
};

/// The single authoritative agent version.
///
/// Read from the crate's own `version`, which comes from `workspace.package`
/// in `desktop/Cargo.toml`. Every other place the version appears — the Tauri
/// bundle, the installer, the update manifest, `remote_devices.agent_version`,
/// the About panel — derives from that one field, so a release is one edit.
pub const AGENT_VERSION: &str = env!("CARGO_PKG_VERSION");

/// The product name, as a person sees it.
pub const PRODUCT_NAME: &str = "AICOUNTLY Remote";
