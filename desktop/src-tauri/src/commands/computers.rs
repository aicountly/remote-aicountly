//! Your computers, their history, and help sessions started at this machine.
//!
//! Every command here is made with this computer's own device credential
//! against a `devices/me` route, so none of them can name another person's
//! computer: the server answers for the owner of the device that asked, and
//! for nobody else. The two that take ids — a session, a participant — check
//! the shape first, because the id becomes part of a URL path.
//!
//! # Help sessions ("give your code")
//!
//! The person at this machine presses **Get help**. [`start_help_session`]
//! creates an attended session in their name that names this computer, and
//! hands it to the connection loop, which joins it as the host. They read the
//! code out; the helper types it on the web; the helper waits until the person
//! here admits them ([`answer_help_participant`]); and control is still asked
//! for and granted in the window, as in any attended session. Nothing in this
//! flow lets a helper in, or lets one control anything, without the person at
//! the machine saying so twice.

use std::sync::Arc;

use remote_core::{
    ApiError, DeviceHistory, HelpSession, HostedParticipant, HostedParticipants, OwnComputers,
};

use crate::{ipc, platform, Agent};

/// The most history entries one call reads.
const MAX_HISTORY: u32 = 50;

/// The sentence for a failed call: the API's own when it refused, which is
/// written for a person, and the error's otherwise.
fn say(error: ApiError) -> String {
    match error {
        ApiError::Refused { message, .. } if !message.is_empty() => message,
        error => error.to_string(),
    }
}

/// Whether `id` is shaped like the ids the API hands out. It becomes a path
/// segment, so anything else — a slash, a dot, a query — is refused here.
fn is_id(id: &str) -> bool {
    !id.is_empty()
        && id.len() <= 64
        && id
            .chars()
            .all(|character| character.is_ascii_alphanumeric() || character == '-')
}

fn checked(id: &str) -> Result<&str, String> {
    if is_id(id) {
        Ok(id)
    } else {
        Err("That is not a session this computer knows.".into())
    }
}

fn client(agent: &Agent) -> Result<remote_core::ApiClient, String> {
    agent
        .device_client()
        .ok_or_else(|| "This computer is not signed in to AICOUNTLY Remote yet.".into())
}

/// The owner's computers, this one first, and how many there are.
#[tauri::command]
pub async fn list_computers(agent: tauri::State<'_, Arc<Agent>>) -> Result<OwnComputers, String> {
    client(&agent)?.own_computers().await.map_err(say)
}

/// This computer's sessions and changes, newest first.
#[tauri::command]
pub async fn computer_history(
    agent: tauri::State<'_, Arc<Agent>>,
    limit: Option<u32>,
) -> Result<DeviceHistory, String> {
    let limit = limit.unwrap_or(20).clamp(1, MAX_HISTORY);

    client(&agent)?.own_history(limit).await.map_err(say)
}

/// Why a help session cannot be started now, if it cannot.
fn help_refusal(state: &remote_core::AgentState, hosting: bool) -> Option<&'static str> {
    if !hosting {
        return Some("This computer cannot show its screen in this version of AICOUNTLY Remote.");
    }

    if !state.is_enrolled() {
        return Some("Sign this computer in to AICOUNTLY Remote first.");
    }

    if state.is_session_active() {
        return Some("A session is already running on this computer. End it first.");
    }

    if state.active_companion().is_some() {
        return Some(
            "This computer is linked to a session as a control companion. Leave it first.",
        );
    }

    None
}

/// Start a help session on this computer and host it.
///
/// Returns the session with its code for the person to read out. The
/// connection loop joins it as the host within a second; the window then
/// shows the session as running, and who is waiting to be let in.
#[tauri::command]
pub async fn start_help_session(
    agent: tauri::State<'_, Arc<Agent>>,
    issue_summary: Option<String>,
) -> Result<HelpSession, String> {
    if let Some(reason) = help_refusal(&agent.state(), platform::supports_hosting()) {
        return Err(reason.into());
    }

    if agent.has_help_request() {
        return Err("A help session is already starting.".into());
    }

    let summary = issue_summary
        .as_deref()
        .map(str::trim)
        .filter(|summary| !summary.is_empty())
        .map(|summary| summary.chars().take(500).collect::<String>());

    let session = client(&agent)?
        .start_help_session(summary.as_deref())
        .await
        .map_err(say)?;

    if !is_id(&session.uuid) {
        return Err("AICOUNTLY Remote returned a session this version cannot host.".into());
    }

    agent.request_help_session(&session.uuid);

    Ok(session)
}

/// Who is waiting to be let in to a help session, and who is in.
#[tauri::command]
pub async fn help_participants(
    agent: tauri::State<'_, Arc<Agent>>,
    session_uuid: String,
) -> Result<HostedParticipants, String> {
    let session_uuid = checked(&session_uuid)?;

    client(&agent)?
        .hosted_participants(session_uuid)
        .await
        .map_err(say)
}

/// Let somebody in to a help session, or turn them away.
#[tauri::command]
pub async fn answer_help_participant(
    agent: tauri::State<'_, Arc<Agent>>,
    session_uuid: String,
    participant_uuid: String,
    admit: bool,
) -> Result<HostedParticipant, String> {
    let session_uuid = checked(&session_uuid)?;
    let participant_uuid = checked(&participant_uuid)?;

    client(&agent)?
        .answer_participant(session_uuid, participant_uuid, admit)
        .await
        .map_err(say)
}

/// End a help session this computer hosts.
///
/// Ended here first — capture stops and every held key is released on this
/// machine before the server is told — so the person at the machine is never
/// waiting on the network to be rid of a helper. The server is then told, so
/// the helper's page closes too.
#[tauri::command]
pub async fn end_help_session(
    agent: tauri::State<'_, Arc<Agent>>,
    session_uuid: String,
) -> Result<remote_core::AgentState, String> {
    let session_uuid = checked(&session_uuid)?.to_owned();

    // Not hosted yet: make sure it never is.
    agent.cancel_help_request(&session_uuid);

    let state = if agent.is_hosting(&session_uuid) {
        let _ = ipc::session_ended(&session_uuid);
        agent.end_session()
    } else {
        agent.state()
    };

    if let Ok(client) = client(&agent) {
        if let Err(error) = client.end_hosted_session(&session_uuid).await {
            tracing::warn!(%error, "the help session's end could not be reported");
        }
    }

    Ok(state)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_id_shaped_values_become_path_segments() {
        assert!(is_id("8f14e45f-ceea-467f-a0e6-3b1b2c3d4e5f"));
        assert!(is_id("AR-10282"));
        assert!(!is_id(""));
        assert!(!is_id("../revoke"));
        assert!(!is_id("a/b"));
        assert!(!is_id("a?b=c"));
        assert!(!is_id("a.b"));
        assert!(!is_id(&"a".repeat(65)));
    }

    #[test]
    fn the_apis_own_sentence_is_shown_when_it_refused() {
        let refused = ApiError::Refused {
            status: 403,
            code: "HELP_NOT_ALLOWED".into(),
            message: "Remote access is switched off for your account.".into(),
        };
        assert_eq!(
            say(refused),
            "Remote access is switched off for your account."
        );
        assert_eq!(
            say(ApiError::NoCredential),
            "this device is not authenticated"
        );
    }

    #[test]
    fn a_help_session_needs_a_hosting_build_a_sign_in_and_nothing_else_running() {
        let fresh = remote_core::AgentState::not_enrolled("1.0.0");
        assert!(help_refusal(&fresh, false)
            .unwrap()
            .contains("cannot show its screen"));
        assert!(help_refusal(&fresh, true)
            .unwrap()
            .contains("Sign this computer in"));
    }
}
