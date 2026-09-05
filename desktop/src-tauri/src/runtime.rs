//! The agent's connection to AICOUNTLY.
//!
//! One background task, started once at launch, that keeps four things true
//! for as long as the application is running:
//!
//! ```text
//!   1. the machine holds a valid device credential      authenticate, renew
//!   2. AICOUNTLY knows the machine is reachable         presence
//!   3. the agent knows what it is currently permitted   GET /devices/me
//!   4. an unattended connection request is noticed      pending sessions
//! ```
//!
//! # Why a loop and not an event stream
//!
//! Presence is the agent telling the server it is there, and the server's
//! `GET /devices/me` is the agent finding out what changed — a revocation, a
//! policy switch turned off, somebody asking to connect. Both are polls, on an
//! interval the *server* chooses and the agent adopts, so the two cannot
//! disagree about what "stale" means.
//!
//! A pushed presence room exists as well and is faster; it is not what makes
//! this reliable. A socket that dropped during the night is a machine nobody
//! can help in the morning, and a poll survives sleep, a network change, a
//! service restart and a backend outage without anybody noticing.
//!
//! # What it does when things go wrong
//!
//! * **A network failure** — bounded exponential backoff with jitter, and it
//!   never gives up. The status line says offline and why.
//! * **A revoked device** — it stops. Terminal, and the only state the agent
//!   deliberately does not retry from: a machine an administrator removed
//!   should stop knocking, and should say plainly that it was removed.
//! * **A credential about to expire** — renewed a minute early rather than
//!   after the first refusal, so an unattended connection does not arrive to
//!   find the agent re-authenticating.
//!
//! # What it does not do
//!
//! It does not open a media session. The session runtime — signalling, the
//! peer connection, capture — is separate, and what is and is not built of it
//! is set out in `docs/desktop/ARCHITECTURE.md` rather than implied here.

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::time::Duration;

use remote_core::{
    AgentConfig, AgentEvent, AgentState, ApiClient, ApiError, Backoff, UnattendedState,
};

use crate::Agent;

/// How long before expiry a credential is renewed.
///
/// Not zero, and not "when it fails": an unattended connection arriving to find
/// the agent mid-re-authentication is a connection that times out for no
/// reason a person could understand.
const RENEW_MARGIN: Duration = Duration::from_secs(60);

/// How often the loop looks again while the machine is not enrolled.
///
/// Nothing can happen until somebody registers it, so this is only how quickly
/// the agent notices that they have.
const IDLE_POLL: Duration = Duration::from_secs(3);

/// The longest the loop sleeps in one go.
///
/// Sleeping in slices is what lets the runtime stop within a few seconds of
/// being asked, rather than at the end of whatever interval it happened to be
/// in the middle of.
const SLEEP_SLICE: Duration = Duration::from_millis(500);

/// A running connection loop.
///
/// Dropping the handle asks the loop to stop; it does so at its next slice.
#[derive(Debug)]
pub struct RuntimeHandle {
    stopping: Arc<AtomicBool>,
}

impl RuntimeHandle {
    /// Ask the loop to stop.
    pub fn stop(&self) {
        self.stopping.store(true, Ordering::SeqCst);
    }
}

impl Drop for RuntimeHandle {
    fn drop(&mut self) {
        self.stop();
    }
}

/// What the runtime tells the interface.
///
/// A callback rather than a Tauri `AppHandle`, so the loop is a plain function
/// that can be exercised without a window — which is what
/// `docs/desktop/TESTING.md` asks of it.
pub type StateSink = Arc<dyn Fn(AgentState) + Send + Sync>;

/// Start the connection loop on the Tauri async runtime.
pub fn start(agent: Arc<Agent>, sink: StateSink) -> RuntimeHandle {
    let stopping = Arc::new(AtomicBool::new(false));
    let handle = RuntimeHandle {
        stopping: Arc::clone(&stopping),
    };

    tauri::async_runtime::spawn(async move {
        run(agent, sink, stopping).await;
    });

    handle
}

/// The loop itself.
///
/// Public so an integration test can drive it against a stub, and separate
/// from [`start`] so it carries no Tauri types.
pub async fn run(agent: Arc<Agent>, sink: StateSink, stopping: Arc<AtomicBool>) {
    let mut backoff = Backoff::authentication();

    while !stopping.load(Ordering::SeqCst) {
        let state = agent.state();

        // Terminal. An administrator removed this machine; retrying for ever
        // would look like a bug to them and like a broken product to whoever
        // is sitting at it.
        if matches!(state.status, remote_core::AgentStatus::Revoked) {
            return;
        }

        let Some(device_uuid) = state.device_uuid.clone() else {
            sleep_interruptibly(&stopping, IDLE_POLL).await;

            continue;
        };

        let config = agent.config();

        let Ok(mut client) = ApiClient::new(config.clone()) else {
            // A configuration the client cannot be built from is a
            // configuration a person has to fix; retrying faster does not help.
            offline(
                &agent,
                &sink,
                "This computer's AICOUNTLY address is not usable.",
                false,
            );
            sleep_interruptibly(&stopping, IDLE_POLL).await;

            continue;
        };

        match authenticate(&agent, &mut client, &device_uuid).await {
            Outcome::Continue => {
                backoff.reset();
                emit(&sink, agent.apply(AgentEvent::Authenticated));
            }
            Outcome::Revoked => {
                emit(&sink, agent.apply(AgentEvent::Revoked));

                return;
            }
            Outcome::Retry(reason) => {
                emit(
                    &sink,
                    agent.apply(AgentEvent::AuthenticationFailed {
                        attempt: backoff.attempt() + 1,
                    }),
                );
                tracing::warn!(%reason, "the device could not be authenticated");

                sleep_interruptibly(&stopping, backoff.next_delay()).await;

                continue;
            }
        }

        // Authenticated. Stay in the presence loop until the credential is
        // near expiry, the device is rejected, or the runtime is stopped.
        connected(&agent, &sink, &client, &config, &stopping).await;
    }
}

/// What an API attempt means for the loop.
enum Outcome {
    /// Carry on.
    Continue,
    /// Stop. Terminal.
    Revoked,
    /// Try again after a backoff.
    Retry(String),
}

impl Outcome {
    fn from(error: ApiError) -> Self {
        if error.is_device_rejected() {
            Self::Revoked
        } else {
            Self::Retry(error.to_string())
        }
    }
}

/// Prove possession of this machine's key and obtain a credential.
async fn authenticate(agent: &Arc<Agent>, client: &mut ApiClient, device_uuid: &str) -> Outcome {
    let keypair = match agent.device_key() {
        Ok(keypair) => keypair,
        // Enrolled according to the stored state, but the key is gone — an
        // uninstall that left the state behind, or a key store a person
        // cleared. Neither is recoverable by retrying.
        Err(error) => return Outcome::Retry(error.to_string()),
    };

    match client.authenticate(device_uuid, &keypair).await {
        Ok(_) => Outcome::Continue,
        Err(error) => Outcome::from(error),
    }
}

/// The presence loop, for as long as the credential lasts.
async fn connected(
    agent: &Arc<Agent>,
    sink: &StateSink,
    client: &ApiClient,
    config: &AgentConfig,
    stopping: &Arc<AtomicBool>,
) {
    // The server's figure, adopted rather than argued with. The configured
    // value applies only until the first answer, so the two halves of the
    // system cannot disagree about what "stale" means.
    let mut interval = Duration::from_secs(config.presence_interval_seconds);
    let _ = interval;
    let mut backoff = Backoff::presence();

    while !stopping.load(Ordering::SeqCst) {
        // Renewed a minute early. Re-authenticating is the outer loop's job,
        // so returning is how this asks for it.
        if credential_expiring(client) {
            return;
        }

        match client.describe_self().await {
            Ok(description) => {
                interval =
                    Duration::from_secs(description.realtime.presence_interval_seconds.max(15));

                if description.device.is_revoked() {
                    emit(sink, agent.apply(AgentEvent::Revoked));

                    return;
                }

                // Unattended access as the *server* currently has it, including
                // the organisation's switch — so the agent's own screen cannot
                // show a capability the server would refuse, and an
                // administrator turning it off elsewhere is reflected here
                // without anybody restarting anything.
                emit(
                    sink,
                    agent.apply(AgentEvent::UnattendedChanged(UnattendedState {
                        enabled: description.device.unattended_access_enabled,
                        enabled_at: description.device.unattended_enabled_at.clone(),
                        last_used_at: description.device.unattended_last_used_at.clone(),
                        allowed_by_policy: description.policy.allow_unattended_access,
                    })),
                );

                if !description.pending_sessions.is_empty() {
                    // Recorded, not joined: joining is the session runtime's
                    // job and this loop does not pretend to do it.
                    for pending in &description.pending_sessions {
                        tracing::info!(
                            session = %pending.uuid,
                            display_id = %pending.display_id,
                            "an unattended session is waiting for this computer"
                        );
                    }
                }
            }
            Err(error) if error.is_device_rejected() => {
                emit(sink, agent.apply(AgentEvent::Revoked));

                return;
            }
            Err(error) => {
                offline(agent, sink, &describe(&error), error.is_retryable());

                if !error.is_retryable() {
                    return;
                }

                sleep_interruptibly(stopping, backoff.next_delay()).await;

                continue;
            }
        }

        match client.report_presence(true).await {
            Ok(()) => {
                backoff.reset();
                emit(sink, agent.apply(AgentEvent::Connected));
            }
            Err(error) if error.is_device_rejected() => {
                emit(sink, agent.apply(AgentEvent::Revoked));

                return;
            }
            Err(error) => {
                offline(agent, sink, &describe(&error), error.is_retryable());

                sleep_interruptibly(stopping, backoff.next_delay()).await;

                continue;
            }
        }

        sleep_interruptibly(stopping, interval).await;
    }
}

/// Whether the credential is close enough to expiry to renew.
fn credential_expiring(client: &ApiClient) -> bool {
    let Some(credential) = client.credential() else {
        return true;
    };

    match seconds_until(&credential.expires_at) {
        Some(remaining) => remaining <= RENEW_MARGIN.as_secs() as i64,
        // An expiry that cannot be parsed is one the agent cannot reason
        // about. Renewing is the safe reading: a spare round trip costs
        // nothing, and a session refused because the credential had quietly
        // expired costs a support call.
        None => true,
    }
}

/// Seconds from now until an ISO-8601 UTC instant, or `None` if it is not one.
fn seconds_until(iso: &str) -> Option<i64> {
    let expires = time::OffsetDateTime::parse(iso, &time::format_description::well_known::Rfc3339)
        .ok()?
        .unix_timestamp();

    let now = time::OffsetDateTime::now_utc().unix_timestamp();

    Some(expires - now)
}

/// What the status line says about an API failure.
///
/// Never the raw error: it can carry a URL, a proxy's HTML, or a message from
/// a WAF, and none of those is something to render in a product's window.
fn describe(error: &ApiError) -> String {
    match error {
        ApiError::Transport(_) => "AICOUNTLY could not be reached from this computer.".into(),
        ApiError::NoCredential => "This computer is not signed in to AICOUNTLY.".into(),
        ApiError::Refused { status, .. } if *status >= 500 => {
            "AICOUNTLY is not answering at the moment.".into()
        }
        ApiError::Refused { .. } => "AICOUNTLY refused this computer's request.".into(),
        _ => "This computer could not reach AICOUNTLY.".into(),
    }
}

fn offline(agent: &Arc<Agent>, sink: &StateSink, reason: &str, retryable: bool) {
    emit(
        sink,
        agent.apply(AgentEvent::Disconnected {
            reason: reason.to_owned(),
            retryable,
        }),
    );
}

fn emit(sink: &StateSink, state: AgentState) {
    sink(state);
}

/// Sleep, but notice a stop request within a slice.
async fn sleep_interruptibly(stopping: &Arc<AtomicBool>, total: Duration) {
    let mut remaining = total;

    while remaining > Duration::ZERO {
        if stopping.load(Ordering::SeqCst) {
            return;
        }

        let slice = remaining.min(SLEEP_SLICE);
        tokio::time::sleep(slice).await;
        remaining -= slice;
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// A credential with no parseable expiry is one the agent cannot reason
    /// about, and renewing is the safe reading.
    #[test]
    fn an_unreadable_expiry_is_treated_as_expiring() {
        assert_eq!(seconds_until("not a timestamp"), None);
        assert_eq!(seconds_until(""), None);
    }

    #[test]
    fn an_expiry_in_the_future_is_measured_from_now() {
        let future = time::OffsetDateTime::now_utc() + time::Duration::seconds(600);
        let text = future
            .format(&time::format_description::well_known::Rfc3339)
            .expect("formats");

        let remaining = seconds_until(&text).expect("parses");

        assert!(remaining > 500 && remaining <= 600, "was {remaining}");
    }

    #[test]
    fn an_expiry_in_the_past_is_negative() {
        let past = time::OffsetDateTime::now_utc() - time::Duration::seconds(120);
        let text = past
            .format(&time::format_description::well_known::Rfc3339)
            .expect("formats");

        assert!(seconds_until(&text).expect("parses") < 0);
    }

    /// The status line is written for a person and never carries the raw
    /// error: it can hold a URL, a proxy's HTML, or a WAF's message.
    #[test]
    fn a_failure_is_described_without_leaking_the_error() {
        let described = describe(&ApiError::Transport(
            "https://internal.example.invalid/api failed: connection refused".into(),
        ));

        assert!(!described.contains("internal.example.invalid"));
        assert!(!described.contains("connection refused"));
        assert!(described.contains("AICOUNTLY"));
    }

    #[test]
    fn a_server_error_and_a_refusal_read_differently() {
        let server = describe(&ApiError::Refused {
            status: 503,
            code: "X".into(),
            message: "y".into(),
        });
        let refusal = describe(&ApiError::Refused {
            status: 403,
            code: "X".into(),
            message: "y".into(),
        });

        assert_ne!(server, refusal);
    }

    /// A revoked device is terminal and is never a retry.
    #[test]
    fn a_rejected_device_stops_rather_than_backing_off() {
        let revoked = Outcome::from(ApiError::Refused {
            status: 403,
            code: "DEVICE_REVOKED".into(),
            message: "removed".into(),
        });

        assert!(matches!(revoked, Outcome::Revoked));

        let transient = Outcome::from(ApiError::Transport("network".into()));

        assert!(matches!(transient, Outcome::Retry(_)));
    }

    #[tokio::test]
    async fn a_sleep_notices_a_stop_request_without_waiting_it_out() {
        let stopping = Arc::new(AtomicBool::new(true));
        let started = std::time::Instant::now();

        sleep_interruptibly(&stopping, Duration::from_secs(60)).await;

        assert!(started.elapsed() < Duration::from_secs(1));
    }

    #[test]
    fn dropping_the_handle_stops_the_loop() {
        let stopping = Arc::new(AtomicBool::new(false));

        {
            let _handle = RuntimeHandle {
                stopping: Arc::clone(&stopping),
            };
        }

        assert!(stopping.load(Ordering::SeqCst));
    }
}
