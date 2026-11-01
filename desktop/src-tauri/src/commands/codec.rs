//! The video codec: Cisco's OpenH264 binary, which this application downloads
//! onto this computer, and which the person at it can switch off and on.
//!
//! The application ships no H.264 encoder. It fetches Cisco's own binary from
//! Cisco, checks it, and loads it (`remote_webrtc::video::cisco`), because
//! Cisco's patent licence covers that binary and nothing else — on four
//! conditions, set out in docs/legal/OPENH264.md. Two of them are met here and
//! in the window: the person can enable, disable and re-enable it
//! ([`set_codec_enabled`], App settings → Video codec), and the switch says
//! "OpenH264 Video Codec provided by Cisco Systems, Inc." ([`ATTRIBUTION`],
//! carried in every [`CodecView`] so the window cannot show a different one).
//!
//! # What happens when
//!
//! * **Start.** A few seconds after the window opens ([`start`]), off the
//!   window's thread and off the connection loop: the copy already here is
//!   checked and loaded, or — switched on and not here yet — downloaded in
//!   the background. Switched off, anything an earlier run left is deleted.
//! * **Switched off.** Saved, every encoder lets go of it at its next frame,
//!   and the file is deleted.
//! * **Switched on.** Saved, and downloaded again in the background; the
//!   window follows the download through [`CODEC_EVENT`].
//! * **No codec in a session.** The screen track refuses each frame with
//!   `remote_webrtc::video::CODEC_UNAVAILABLE`; the session logs it once
//!   (`session.rs`), the window shows a notice naming the setting, and —
//!   when the codec is switched on — a fresh download starts by itself, at
//!   most once a minute.

use std::sync::Arc;
use std::time::Duration;

use remote_core::AgentConfig;
use remote_webrtc::video::{
    CodecManager, CodecOptions, CodecStatus, ATTRIBUTION, OPENH264_VERSION,
};
use serde::Serialize;

use crate::Agent;

/// The event the window hears every change of the codec's status on.
pub const CODEC_EVENT: &str = "aicountly-remote://codec";

/// How long after start the codec is looked at: long enough for the window to
/// have drawn, short enough that a session starting soon after finds it. A
/// session that starts sooner asks for it itself.
const START_DELAY: Duration = Duration::from_secs(3);

/// The codec, as the window shows it.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct CodecView {
    /// Whether the person has it switched on.
    pub enabled: bool,
    /// Where things stand.
    pub status: CodecStatus,
    /// The sentence Cisco's licence asks for beside the switch.
    pub attribution: String,
    /// The OpenH264 release this build loads.
    pub version: String,
    /// Cisco's file for this computer, when it publishes one.
    pub file: Option<String>,
}

impl CodecView {
    fn of(status: CodecStatus, enabled: bool, file: Option<String>) -> Self {
        Self {
            enabled,
            status,
            attribution: ATTRIBUTION.to_owned(),
            version: OPENH264_VERSION.to_owned(),
            file,
        }
    }

    /// The codec now.
    #[must_use]
    pub fn now(codec: &CodecManager) -> Self {
        Self::of(
            codec.status(),
            codec.is_enabled(),
            codec.blob().map(|blob| blob.file.to_owned()),
        )
    }
}

/// What switching the codec on or off returns: the saved configuration, and
/// the codec.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct CodecChoice {
    /// The configuration as saved.
    pub config: AgentConfig,
    /// The codec after the change.
    pub codec: CodecView,
}

/// The application's codec manager, switched on or off as `config` says.
///
/// Kept in this person's own folder (`remote_core::codec_directory`), fetched
/// with the same `User-Agent` as every other request the application makes,
/// and with Tauri's runtime to run background downloads on.
#[must_use]
pub fn manager(config: &AgentConfig) -> CodecManager {
    let options = CodecOptions::cisco(
        remote_core::codec_directory(),
        format!(
            "AicountlyRemote/{} ({})",
            remote_core::AGENT_VERSION,
            std::env::consts::OS
        ),
    )
    .with_spawner(Arc::new(|future| {
        tauri::async_runtime::spawn(future);
    }));

    CodecManager::new(options, config.openh264_enabled)
}

/// Tell the window about every change, and look at the codec shortly after
/// start — in the background, so neither the window nor the connection loop
/// waits for a disk read or a download.
pub fn start(app: &tauri::AppHandle, codec: &CodecManager) {
    let handle = app.clone();
    let blob = codec.blob().map(|blob| blob.file.to_owned());

    codec.on_change(move |status| {
        // From the status alone, so the listener holds no reference back to
        // the manager that holds it: a supported codec is switched off
        // exactly when its status is `Disabled` — switching it off is what
        // puts it there, and nothing moves it on while it is off.
        let enabled = !matches!(status, CodecStatus::Disabled);
        let view = CodecView::of(status.clone(), enabled, blob.clone());

        let _ = tauri::Emitter::emit(&handle, CODEC_EVENT, view);
    });

    let codec = codec.clone();
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(START_DELAY).await;

        match codec.start().await {
            CodecStatus::Ready { version, .. } => {
                tracing::info!(%version, "the OpenH264 video codec is ready");
            }
            status => tracing::info!(?status, "the OpenH264 video codec is not ready"),
        }
    });
}

/// The codec, for App settings, the Get help screen and the session notice.
#[tauri::command]
pub fn codec_status(codec: tauri::State<'_, CodecManager>) -> CodecView {
    CodecView::now(&codec)
}

/// "Download now" and "Retry": fetch it again, in the background. The window
/// follows it through [`CODEC_EVENT`].
#[tauri::command]
pub async fn install_codec(codec: tauri::State<'_, CodecManager>) -> Result<CodecView, String> {
    if !codec.is_enabled() {
        return Err(format!(
            "Turn on \u{201c}{ATTRIBUTION}\u{201d} first: it is switched off."
        ));
    }

    codec.install_in_background();

    Ok(CodecView::now(&codec))
}

/// Stop using the codec and delete it, leaving the switch as it is.
#[tauri::command]
pub async fn remove_codec(codec: tauri::State<'_, CodecManager>) -> Result<CodecView, String> {
    codec.remove().await;

    Ok(CodecView::now(&codec))
}

/// The switch beside "OpenH264 Video Codec provided by Cisco Systems, Inc."
/// (licence condition 2): saved to the configuration, and acted on at once —
/// off stops its use and deletes it, on downloads it again.
///
/// The choice takes effect for this run even when the configuration cannot be
/// written: the person said to stop using the codec, and a settings file is
/// no reason to carry on using it. The error is still reported, because the
/// next start would read the old value.
#[tauri::command]
pub async fn set_codec_enabled(
    agent: tauri::State<'_, Arc<Agent>>,
    codec: tauri::State<'_, CodecManager>,
    enabled: bool,
) -> Result<CodecChoice, String> {
    let saved = agent.set_config(AgentConfig {
        openh264_enabled: enabled,
        ..agent.config()
    });

    codec.set_enabled(enabled).await;

    tracing::info!(enabled, "the OpenH264 video codec was switched");

    saved.map_err(|error| error.to_string())?;

    Ok(CodecChoice {
        config: agent.config(),
        codec: CodecView::now(&codec),
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The window renders the sentence it is given, so it is given Cisco's.
    #[test]
    fn the_view_carries_cisco_s_sentence_and_the_version() {
        let codec = manager(&AgentConfig::default());
        let json = serde_json::to_value(CodecView::now(&codec)).unwrap();

        assert_eq!(
            json["attribution"],
            "OpenH264 Video Codec provided by Cisco Systems, Inc."
        );
        assert_eq!(json["version"], "2.6.0");
        assert_eq!(json["enabled"], true);
        assert!(json["status"]["state"].is_string());
    }

    /// The configuration decides whether it starts switched on.
    #[test]
    fn the_configuration_decides_whether_the_codec_is_on() {
        let on = manager(&AgentConfig::default());
        assert!(on.is_enabled());

        let off = manager(&AgentConfig {
            openh264_enabled: false,
            ..AgentConfig::default()
        });
        assert!(!off.is_enabled());

        if off.blob().is_some() {
            assert_eq!(off.status(), CodecStatus::Disabled);
        }
    }

    /// Nothing the window is handed says where the codec came from beyond
    /// Cisco's own file name.
    #[test]
    fn the_view_names_cisco_s_file_for_this_computer() {
        let codec = manager(&AgentConfig::default());
        let view = CodecView::now(&codec);

        assert_eq!(
            view.file,
            remote_webrtc::video::cisco::this_platform().map(|blob| blob.file.to_owned())
        );
    }
}
