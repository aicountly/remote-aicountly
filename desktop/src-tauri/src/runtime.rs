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
//!   5. a control-companion link is put to the person    pending companions
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
//! * **A revoked device** — it stops asking. The only state the agent
//!   deliberately does not retry from: a machine that was removed should stop
//!   knocking, and should say plainly that it was removed. The loop itself
//!   waits rather than ending, so signing the computer in again — a new
//!   enrolment — is picked up without restarting the application.
//! * **A credential about to expire** — renewed a minute early rather than
//!   after the first refusal, so an unattended connection does not arrive to
//!   find the agent re-authenticating.
//!
//! # What it does not do
//!
//! It does not open a media session. The session runtime — signalling, the
//! It does not run a session itself. When one is waiting it hands over to
//! [`crate::session`], which joins, negotiates and pumps the control channel,
//! and comes back here when the session ends. What is and is not built of that
//! — there is no encoder — is set out in `docs/desktop/ARCHITECTURE.md`.

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::time::Duration;

use remote_core::{
    AgentConfig, AgentEvent, AgentState, ApiClient, ApiError, Backoff, Signal, SignallingSocket,
    UnattendedState,
};
use remote_webrtc::PeerSessionFactory;

use crate::companion;
use crate::platform;
use crate::session;
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

/// How often the loop looks at the API while a companion link is waiting for
/// the person at the machine — so one withdrawn from the browser, or lapsed,
/// leaves the screen promptly rather than at the next presence interval.
const AWAITING_POLL: Duration = Duration::from_secs(5);

/// How often a waiting answer is checked for.
const DECISION_SLICE: Duration = Duration::from_millis(200);

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

/// Start the connection loop.
///
/// The factory is passed in rather than named here, so this file — and the
/// session runtime under it — never mentions which WebRTC implementation is
/// behind the seam.
pub fn start<F>(agent: Arc<Agent>, sink: StateSink, factory: F) -> RuntimeHandle
where
    F: PeerSessionFactory + Clone + Send + 'static,
{
    let stopping = Arc::new(AtomicBool::new(false));
    let handle = RuntimeHandle {
        stopping: Arc::clone(&stopping),
    };

    // Its own thread with its own current-thread runtime, rather than a task
    // on Tauri's.
    //
    // The `PeerSession` trait uses `async fn` in a trait, so its futures are
    // not `Send` — which is correct for what they are, since a peer connection
    // is owned by exactly one task and moving one between threads mid-session
    // is not something to make possible. Giving the loop a runtime of its own
    // is what lets it hold one.
    std::thread::Builder::new()
        .name("aicountly-remote-connection".into())
        .spawn(move || {
            let runtime = match tokio::runtime::Builder::new_current_thread()
                .enable_all()
                .build()
            {
                Ok(runtime) => runtime,
                Err(error) => {
                    tracing::error!(%error, "the connection loop could not start");

                    return;
                }
            };

            runtime.block_on(run(agent, sink, factory, stopping));
        })
        .map_err(|error| tracing::error!(%error, "the connection loop could not start"))
        .ok();

    handle
}

/// The loop itself.
///
/// Public so an integration test can drive it against a stub, and separate
/// from [`start`] so it carries no Tauri types.
pub async fn run<F>(agent: Arc<Agent>, sink: StateSink, factory: F, stopping: Arc<AtomicBool>)
where
    F: PeerSessionFactory + Clone,
{
    let mut backoff = Backoff::authentication();
    // Separate from `backoff`, which a successful authentication resets: the
    // refusals this counts come *after* authenticating succeeded.
    let mut refused = Backoff::authentication();

    while !stopping.load(Ordering::SeqCst) {
        let state = agent.state();

        // This machine was removed — from the Computers page, or by signing
        // it out. Retrying the same enrolment for ever would look like a bug
        // to its owner and like a broken product to whoever is sitting at it,
        // so nothing is asked of the server. But the loop waits rather than
        // ending: signing in again enrols the computer afresh (`Revoked` then
        // `Enrolled` is `Authenticating`), and a loop that had returned left
        // it there, offline, until somebody restarted the application.
        if matches!(state.status, remote_core::AgentStatus::Revoked) {
            sleep_interruptibly(&stopping, IDLE_POLL).await;

            continue;
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
                // Shared with the window, so a decision made there — Allow,
                // Not now, Stop control — can be reported without waiting for
                // this loop to come round again.
                agent.set_credential(client.credential().cloned());
                emit(&sink, agent.apply(AgentEvent::Authenticated));
            }
            Outcome::Revoked => {
                agent.set_credential(None);
                emit(&sink, agent.apply(AgentEvent::Revoked));

                // Waits at the top of the loop for a new enrolment.
                continue;
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
        match connected(&agent, &sink, &client, &config, &factory, &stopping).await {
            Ended::Renew => refused.reset(),
            // Authenticating succeeded and the next call was refused anyway.
            // Waiting longer each time is what keeps a server that keeps
            // refusing from being asked again in a tight loop.
            Ended::Retry => sleep_interruptibly(&stopping, refused.next_delay()).await,
        }
    }
}

/// How a connected stretch ended, for the outer loop.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Ended {
    /// Re-authenticate now: the credential is near expiry, a session ended,
    /// the runtime is stopping, or the device was revoked (which the loop
    /// sees for itself).
    Renew,
    /// Re-authenticate after a wait: something the API said needs a fresh
    /// credential, or cannot be fixed by asking again at once.
    Retry,
}

/// What an API attempt means for the loop.
enum Outcome {
    /// Carry on.
    Continue,
    /// Stop asking: the device was removed. The loop waits for a new
    /// enrolment.
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
#[allow(clippy::too_many_arguments)]
async fn connected<F>(
    agent: &Arc<Agent>,
    sink: &StateSink,
    client: &ApiClient,
    config: &AgentConfig,
    factory: &F,
    stopping: &Arc<AtomicBool>,
) -> Ended
where
    F: PeerSessionFactory + Clone,
{
    // The server's figure, adopted rather than argued with. The configured
    // value applies only until the first answer, so the two halves of the
    // system cannot disagree about what "stale" means.
    let mut interval = Duration::from_secs(config.presence_interval_seconds);
    let _ = interval;
    let mut backoff = Backoff::presence();
    // The device's own presence room, listened to for `device-invite` so a
    // companion link (or an unattended request) is noticed at once rather
    // than at the next poll. The poll stays what makes it reliable.
    let mut presence: Option<PresenceRoom> = None;

    while !stopping.load(Ordering::SeqCst) {
        // Renewed a minute early. Re-authenticating is the outer loop's job,
        // so returning is how this asks for it.
        if credential_expiring(client) {
            return Ended::Renew;
        }

        // The companion link on screen, waiting for an answer, if there is one.
        let awaiting: Option<remote_core::PendingCompanion>;

        match client.describe_self().await {
            Ok(description) => {
                interval =
                    Duration::from_secs(description.realtime.presence_interval_seconds.max(15));

                if description.device.is_revoked() {
                    emit(sink, agent.apply(AgentEvent::Revoked));

                    return Ended::Renew;
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

                // Which sharing surfaces the organisation permits. This agent
                // captures a whole screen and has no way to capture anything
                // smaller — no tab, no single window — so `ENTIRE_MONITOR` is
                // the only surface it could ever offer, and a policy without
                // it is a policy under which this machine cannot share at all.
                //
                // The server's own `capabilities` do not carry that
                // distinction: it computes `screen_share` as "any surface is
                // allowed", which is true of an organisation that permits only
                // a browser window — something the browser can do and this
                // machine cannot.
                let may_share_a_screen = description.policy.allows_entire_monitor();

                // What this machine may currently do — the declaration
                // intersected with the organisation's ceiling, as the server
                // computed it, and then with the surface question above, so
                // the Permissions panel cannot say screen sharing is allowed
                // while the loop below refuses to host. The panel's "your
                // organisation" column has no other source, and this keeps it
                // current without anybody restarting anything.
                let mut capabilities = description.capabilities;
                if !may_share_a_screen {
                    capabilities.screen_share = false;
                }

                emit(
                    sink,
                    agent.apply(AgentEvent::CapabilitiesChanged(capabilities)),
                );

                // Somebody is waiting. Host one at a time — two sessions on
                // one desktop is not a feature, and deciding whose input wins
                // is not a decision anybody should have to make afterwards.
                let hosting = platform::supports_hosting();

                if let Some(pending) = description.pending_sessions.first().filter(|_| hosting) {
                    if may_share_a_screen {
                        tracing::info!(
                            session = %pending.uuid,
                            display_id = %pending.display_id,
                            "an unattended session is waiting for this computer"
                        );

                        let ended = session::host(
                            Arc::clone(agent),
                            Arc::clone(sink),
                            factory.clone(),
                            pending.uuid.clone(),
                            true,
                            Arc::clone(stopping),
                        )
                        .await;

                        tracing::info!(?ended, session = %pending.uuid, "the session ended");

                        // The credential may well have expired while it ran, so
                        // the outer loop re-authenticates rather than this one
                        // carrying on with a stale one.
                        return Ended::Renew;
                    }

                    // Refused here, at the machine, rather than discovered by
                    // whoever is waiting at the other end once the screen is
                    // already on its way. The session is left pending: it is
                    // the organisation's policy that refuses it, so there is
                    // nothing for this machine to retry, and an administrator
                    // who changes the policy gets a join on the next poll.
                    tracing::warn!(
                        session = %pending.uuid,
                        allowed = ?description.policy.allowed_share_modes,
                        "a session is waiting, but this organisation does not permit sharing a \
                         whole screen and that is the only surface this computer can share"
                    );
                }

                // A control-companion link waiting for the person here. The
                // API is the only thing that raises this question — never a
                // message from a peer — and nothing is joined until they say
                // yes.
                awaiting = description
                    .pending_companions
                    .first()
                    .filter(|_| platform::supports_companion())
                    .cloned();

                match &awaiting {
                    Some(pending) => emit(sink, agent.companion_requested(pending)),
                    None => {
                        // Withdrawn from the browser, lapsed, or answered
                        // elsewhere: take it off the screen.
                        let state = agent.state();
                        if let Some(asked) =
                            state.active_companion().filter(|asked| !asked.is_joined())
                        {
                            emit(sink, agent.companion_dismissed(&asked.companion_uuid));
                        }
                    }
                }
            }
            // The short-lived credential was not accepted. That is not the
            // device being removed — authenticating again answers that, and
            // only that answer is final. Treating this as revocation signed a
            // person's computer out whenever a credential was refused for any
            // other reason: every personal device, every launch, before the
            // server accepted credentials naming no organisation.
            Err(error) if error.is_credential_refused() => {
                offline(agent, sink, &describe(&error), true);

                return Ended::Retry;
            }
            Err(error) if error.is_device_rejected() => {
                emit(sink, agent.apply(AgentEvent::Revoked));

                return Ended::Renew;
            }
            Err(error) => {
                offline(agent, sink, &describe(&error), error.is_retryable());

                if !error.is_retryable() {
                    // Authenticating again might change the answer; doing it
                    // at once, every time, would not. The outer loop waits.
                    return Ended::Retry;
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
            // The short-lived credential was not accepted. That is not the
            // device being removed — authenticating again answers that, and
            // only that answer is final. Treating this as revocation signed a
            // person's computer out whenever a credential was refused for any
            // other reason: every personal device, every launch, before the
            // server accepted credentials naming no organisation.
            Err(error) if error.is_credential_refused() => {
                offline(agent, sink, &describe(&error), true);

                return Ended::Retry;
            }
            Err(error) if error.is_device_rejected() => {
                emit(sink, agent.apply(AgentEvent::Revoked));

                return Ended::Renew;
            }
            Err(error) => {
                offline(agent, sink, &describe(&error), error.is_retryable());

                sleep_interruptibly(stopping, backoff.next_delay()).await;

                continue;
            }
        }

        let Some(pending) = awaiting else {
            if wait_for_nudge(client, &mut presence, interval, stopping).await {
                tracing::debug!("nudged in the presence room; reading the API now");
            }

            continue;
        };

        match wait_for_decision(
            agent,
            &pending.companion_uuid,
            interval.min(AWAITING_POLL),
            stopping,
        )
        .await
        {
            Some(true) => {
                let Some(device_client) = agent.device_client() else {
                    continue;
                };

                let ended = companion::run(
                    Arc::clone(agent),
                    Arc::clone(sink),
                    companion::LiveCompanionApi::new(device_client, Arc::clone(agent), &pending),
                    factory.clone(),
                    pending.clone(),
                    Arc::clone(stopping),
                )
                .await;

                tracing::info!(?ended, companion = %pending.companion_uuid, "the control companion ended");

                // A join that was refused never left the question; clear it.
                emit(sink, agent.companion_dismissed(&pending.companion_uuid));

                // As after a hosted session: re-authenticate rather than carry
                // on with a credential that may have expired meanwhile.
                return Ended::Renew;
            }
            Some(false) => {
                if let Err(error) = client.decline_companion(&pending.companion_uuid).await {
                    tracing::warn!(%error, "declining the companion link could not be reported");
                }

                emit(sink, agent.companion_dismissed(&pending.companion_uuid));
            }
            // No answer yet: look at the API again — the link may have been
            // withdrawn — and keep asking.
            None => {}
        }
    }

    Ended::Renew
}

/// The device's presence-room socket, and when to replace its token.
struct PresenceRoom {
    socket: SignallingSocket,
    renew_at: std::time::Instant,
}

/// Open (or renew) the presence-room socket. `None` when it cannot be had —
/// which only costs the nudge, never the poll.
async fn open_presence_room(client: &ApiClient) -> Option<PresenceRoom> {
    let body = client.presence_token().await.ok()?;
    let (url, token, expires_at) = read_presence_token(&body)?;
    let socket = SignallingSocket::connect(&url, &token).await.ok()?;

    Some(PresenceRoom {
        socket,
        renew_at: std::time::Instant::now() + session::renew_in(expires_at),
    })
}

/// `POST /devices/me/presence-token`'s `{token, url, expiresAt}`, read without
/// trusting its shape.
fn read_presence_token(
    body: &serde_json::Value,
) -> Option<(String, String, Option<time::OffsetDateTime>)> {
    let url = body["url"].as_str()?.to_owned();
    let token = body["token"].as_str()?.to_owned();
    let expires_at = body["expiresAt"].as_str().and_then(|at| {
        time::OffsetDateTime::parse(at, &time::format_description::well_known::Rfc3339).ok()
    });

    Some((url, token, expires_at))
}

/// Sleep for `total`, waking early — `true` — when the presence room carries
/// a `device-invite`. The invite is only a reason to read the API sooner; it
/// names nothing the agent acts on.
async fn wait_for_nudge(
    client: &ApiClient,
    presence: &mut Option<PresenceRoom>,
    total: Duration,
    stopping: &Arc<AtomicBool>,
) -> bool {
    if presence
        .as_ref()
        .is_none_or(|room| std::time::Instant::now() >= room.renew_at)
    {
        if let Some(old) = presence.take() {
            old.socket.close().await;
        }

        *presence = open_presence_room(client).await;
    }

    let until = std::time::Instant::now() + total;

    while std::time::Instant::now() < until {
        if stopping.load(Ordering::SeqCst) {
            return false;
        }

        let slice = until
            .saturating_duration_since(std::time::Instant::now())
            .min(SLEEP_SLICE);

        let Some(room) = presence.as_mut() else {
            tokio::time::sleep(slice).await;

            continue;
        };

        match tokio::time::timeout(slice, room.socket.next()).await {
            Ok(Ok(Some(Signal::DeviceInvite { .. }))) => return true,
            Ok(Ok(Some(_))) | Err(_) => {}
            // Closed or dropped: the poll carries on without the nudge, and
            // the next wait opens a fresh room.
            Ok(Ok(None)) | Ok(Err(_)) => *presence = None,
        }
    }

    false
}

/// Wait up to `total` for the person at the machine to answer a companion
/// link. `None` when they have not, or the runtime is stopping.
async fn wait_for_decision(
    agent: &Arc<Agent>,
    companion_uuid: &str,
    total: Duration,
    stopping: &Arc<AtomicBool>,
) -> Option<bool> {
    let mut remaining = total;

    loop {
        if stopping.load(Ordering::SeqCst) {
            return None;
        }

        if let Some(decision) = agent.take_companion_decision(companion_uuid) {
            return Some(decision);
        }

        if remaining.is_zero() {
            return None;
        }

        let slice = remaining.min(DECISION_SLICE);
        tokio::time::sleep(slice).await;
        remaining -= slice;
    }
}

/// Whether the credential is close enough to expiry to renew.
///
/// The answer itself is `DeviceCredential::is_expiring`, so the service's
/// presence loop renews on exactly the same reading.
fn credential_expiring(client: &ApiClient) -> bool {
    client
        .credential()
        .is_none_or(|credential| credential.is_expiring(RENEW_MARGIN))
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
        // Manage's company lifecycle: the organisation is read-only in Remote. The server's sentence says
        // until when and what to do; it is shown rather than a refusal that sounds like this machine's fault.
        ApiError::Refused { .. } if error.is_company_restricted() => error
            .company_restricted_message()
            .map_or_else(
                || "This organisation is read-only in AICOUNTLY Remote because its subscription ended. Renew it to start sessions again.".into(),
                str::to_owned,
            ),
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

    /// A removed computer that is signed in again must reconnect without a
    /// restart. The loop used to return the moment it saw `Revoked`, so the new
    /// enrolment sat in `Authenticating` with nothing left to authenticate it.
    /// It now waits — asking the server nothing — until it is stopped or the
    /// computer is enrolled again.
    #[tokio::test]
    async fn a_revoked_computer_waits_for_a_new_enrolment_rather_than_ending_the_loop() {
        let agent = Arc::new(Agent::new());
        agent.apply(AgentEvent::Enrolled {
            device_uuid: "00000000-0000-4000-8000-000000000001".into(),
            device_name: "Removed laptop".into(),
            company_name: None,
            key_fingerprint: "AAAA BBBB".into(),
        });
        agent.apply(AgentEvent::Revoked);
        assert!(matches!(
            agent.state().status,
            remote_core::AgentStatus::Revoked
        ));

        let stopping = Arc::new(AtomicBool::new(false));
        let sink: StateSink = Arc::new(|_| {});
        let task = tokio::spawn(run(
            agent.clone(),
            sink,
            remote_webrtc::webrtc_rs::WebRtcFactory,
            stopping.clone(),
        ));

        tokio::time::sleep(Duration::from_millis(300)).await;
        assert!(
            !task.is_finished(),
            "the loop must still be there for the next sign-in"
        );

        stopping.store(true, Ordering::SeqCst);
        tokio::time::timeout(Duration::from_secs(5), task)
            .await
            .expect("stops promptly when the application does")
            .expect("ends cleanly");
    }

    /// Renewal is one reading, shared with the service's presence loop:
    /// `DeviceCredential::is_expiring`, tested over the parsing cases in
    /// `remote_core::api`. What this asserts is the part that belongs to this
    /// loop — that holding no credential at all counts as expiring, so the
    /// outer loop authenticates rather than sitting in the presence loop with
    /// nothing to present.
    #[test]
    fn no_credential_at_all_counts_as_expiring() {
        let client = ApiClient::new(AgentConfig::default()).expect("builds");

        assert!(credential_expiring(&client));
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

    /// A read-only organisation shows the server's sentence, not the generic refusal.
    #[test]
    fn a_read_only_organisation_is_described_in_the_servers_words() {
        let restricted = describe(&ApiError::Refused {
            status: 423,
            code: "COMPANY_RESTRICTED".into(),
            message: "This company is read-only until 1 Dec 2026 because its subscription ended."
                .into(),
        });

        assert_eq!(
            restricted,
            "This company is read-only until 1 Dec 2026 because its subscription ended."
        );

        let unreadable = describe(&ApiError::Refused {
            status: 423,
            code: "COMPANY_RESTRICTED".into(),
            message: "<html>proxy</html>".into(),
        });

        assert!(unreadable.contains("read-only"));
        assert!(!unreadable.contains("<html>"));
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
    fn a_presence_token_is_read_without_trusting_its_shape() {
        let (url, token, expires) = read_presence_token(&serde_json::json!({
            "token": "t", "url": "wss://relay.test/signal", "room": "device-1",
            "expiresAt": "2026-10-25T09:02:00Z", "staleAfterSeconds": 180
        }))
        .expect("reads");

        assert_eq!(
            (url.as_str(), token.as_str()),
            ("wss://relay.test/signal", "t")
        );
        assert!(expires.is_some());

        assert!(read_presence_token(&serde_json::json!({ "url": "wss://x" })).is_none());
        assert!(read_presence_token(&serde_json::json!({})).is_none());
    }

    /// The person's answer is picked up within a slice, and only for the link
    /// it was given for.
    #[tokio::test(start_paused = true)]
    async fn a_companion_answer_is_picked_up_promptly_and_only_for_its_link() {
        let agent = Arc::new(Agent::new());
        let stopping = Arc::new(AtomicBool::new(false));

        assert_eq!(
            wait_for_decision(&agent, "companion-1", Duration::from_secs(1), &stopping).await,
            None
        );

        agent.decide_companion("companion-2", true);
        assert_eq!(
            wait_for_decision(&agent, "companion-1", Duration::from_secs(1), &stopping).await,
            None
        );

        agent.decide_companion("companion-1", false);
        assert_eq!(
            wait_for_decision(&agent, "companion-1", Duration::from_secs(5), &stopping).await,
            Some(false)
        );
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
