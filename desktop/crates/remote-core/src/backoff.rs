//! Reconnection: exponential, bounded, and jittered.
//!
//! The agent reconnects for a living. A laptop sleeps and wakes, a network
//! changes from Wi-Fi to a phone's hotspot, a service restarts during an
//! update, the API has a bad five minutes, the signalling process is
//! redeployed. Every one of those looks the same from here: the connection
//! went away, and something has to decide when to try again.
//!
//! # Why the jitter is not optional
//!
//! Without it, every agent that lost its connection to a signalling service
//! that just restarted comes back in the same millisecond — and knocks it over
//! again, and they all back off together, and come back together. A fleet of a
//! thousand machines becomes a thousand-machine thundering herd with a
//! synchronised heartbeat. The randomised factor is what breaks the lockstep,
//! and it is the same reason `RemoteSignallingClient` in the browser has one.
//!
//! # Why it never gives up
//!
//! The browser's signalling client stops after eight attempts, because a
//! person is watching and can be told. Nobody is watching an agent on a
//! machine in a back office: giving up would mean a device that is silently
//! unreachable until somebody walks over to it. So the delay is capped and the
//! attempts are not.

use std::time::Duration;

/// Exponential backoff with jitter and a ceiling.
#[derive(Debug, Clone)]
pub struct Backoff {
    base: Duration,
    ceiling: Duration,
    attempt: u32,
    /// Jitter as a fraction: 0.3 means ±30%.
    jitter: f64,
    /// Deterministic sequence, so a test can assert a delay exactly.
    seed: u64,
}

impl Default for Backoff {
    fn default() -> Self {
        Self::new(Duration::from_secs(1), Duration::from_secs(60))
    }
}

impl Backoff {
    /// A backoff from `base`, doubling, capped at `ceiling`.
    #[must_use]
    pub fn new(base: Duration, ceiling: Duration) -> Self {
        Self {
            base,
            ceiling,
            attempt: 0,
            jitter: 0.3,
            // Not cryptographic and does not need to be: this only has to
            // stop a fleet reconnecting in lockstep.
            seed: 0x2545_F491_4F6C_DD1D,
        }
    }

    /// The presence connection: quick first retry, capped at a minute.
    #[must_use]
    pub fn presence() -> Self {
        Self::new(Duration::from_secs(1), Duration::from_secs(60))
    }

    /// Re-authenticating the device: slower, because a failure here is
    /// usually a revoked device or an API outage rather than a blip.
    #[must_use]
    pub fn authentication() -> Self {
        Self::new(Duration::from_secs(5), Duration::from_secs(300))
    }

    /// Fix the jitter fraction. 0.0 makes the sequence deterministic.
    #[must_use]
    pub fn with_jitter(mut self, jitter: f64) -> Self {
        self.jitter = jitter.clamp(0.0, 1.0);

        self
    }

    /// How many failures have been counted.
    #[must_use]
    pub fn attempt(&self) -> u32 {
        self.attempt
    }

    /// The connection came back. The next failure starts from the base again.
    pub fn reset(&mut self) {
        self.attempt = 0;
    }

    /// How long to wait before the next attempt.
    #[must_use]
    pub fn next_delay(&mut self) -> Duration {
        self.attempt = self.attempt.saturating_add(1);

        // Saturating and capped at 32 shifts: attempt 40 would otherwise
        // overflow the shift and produce a delay of zero — a backoff that
        // turns into a hot loop after a long outage.
        let exponent = (self.attempt - 1).min(32);
        let multiplier = 1_u64.checked_shl(exponent).unwrap_or(u64::MAX);

        let raw = self
            .base
            .as_millis()
            .saturating_mul(u128::from(multiplier))
            .min(self.ceiling.as_millis());

        Duration::from_millis(self.jittered(raw as u64))
    }

    /// Apply ±`jitter` to a delay, deterministically per attempt.
    fn jittered(&mut self, millis: u64) -> u64 {
        if self.jitter <= f64::EPSILON {
            return millis;
        }

        // xorshift64*: a handful of instructions, no dependency, and plenty
        // good enough to break a fleet out of lockstep.
        self.seed ^= self.seed >> 12;
        self.seed ^= self.seed << 25;
        self.seed ^= self.seed >> 27;
        let random = ((self.seed.wrapping_mul(0x2545_F491_4F6C_DD1D) >> 33) as f64) / f64::from(u32::MAX);

        let factor = 1.0 - self.jitter + (random * self.jitter * 2.0);

        ((millis as f64) * factor).round().max(1.0) as u64
    }
}

/// Why the agent's connection went away.
///
/// Recorded so the diagnostics panel can say something true, and so the
/// reconnection logic can treat a revoked device differently from a network
/// blip — retrying forever against a revocation is pointless, and telling the
/// person their machine was revoked is useful.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum DisconnectReason {
    /// The network went away — sleep, a changed adapter, a lost route.
    Network,
    /// The presence token expired and has to be re-minted.
    TokenExpired,
    /// The API is not answering.
    ApiUnavailable,
    /// The signalling service is not answering.
    SignallingUnavailable,
    /// The device was revoked. Retrying will not help.
    DeviceRevoked,
    /// The machine is going to sleep or shutting down.
    Suspending,
}

impl DisconnectReason {
    /// Whether reconnecting could possibly work.
    ///
    /// A revoked device is the one case where it cannot: the agent stops,
    /// says so in the window and in the tray, and waits for somebody to enrol
    /// it again.
    #[must_use]
    pub fn is_retryable(self) -> bool {
        !matches!(self, Self::DeviceRevoked)
    }

    /// What the interface says about it.
    #[must_use]
    pub fn describe(self) -> &'static str {
        match self {
            Self::Network => "The network connection was lost.",
            Self::TokenExpired => "Reconnecting to AICOUNTLY Remote.",
            Self::ApiUnavailable => "AICOUNTLY Remote is not responding.",
            Self::SignallingUnavailable => "The session service is not responding.",
            Self::DeviceRevoked => "This device was removed by an administrator.",
            Self::Suspending => "The computer is going to sleep.",
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_delay_doubles_until_it_reaches_the_ceiling() {
        let mut backoff = Backoff::new(Duration::from_secs(1), Duration::from_secs(60)).with_jitter(0.0);

        assert_eq!(backoff.next_delay(), Duration::from_secs(1));
        assert_eq!(backoff.next_delay(), Duration::from_secs(2));
        assert_eq!(backoff.next_delay(), Duration::from_secs(4));
        assert_eq!(backoff.next_delay(), Duration::from_secs(8));
        assert_eq!(backoff.next_delay(), Duration::from_secs(16));
        assert_eq!(backoff.next_delay(), Duration::from_secs(32));
        assert_eq!(backoff.next_delay(), Duration::from_secs(60));
        assert_eq!(backoff.next_delay(), Duration::from_secs(60));
    }

    #[test]
    fn a_successful_connection_resets_the_sequence() {
        let mut backoff = Backoff::new(Duration::from_secs(1), Duration::from_secs(60)).with_jitter(0.0);

        for _ in 0..3 {
            let _ = backoff.next_delay();
        }
        assert_eq!(backoff.attempt(), 3);

        backoff.reset();

        assert_eq!(backoff.attempt(), 0);
        assert_eq!(backoff.next_delay(), Duration::from_secs(1));
    }

    /// A backoff that overflows its shift produces a delay of zero — which
    /// turns a long outage into a hot loop hammering the API.
    #[test]
    fn a_very_long_outage_stays_at_the_ceiling_rather_than_overflowing() {
        let mut backoff = Backoff::new(Duration::from_secs(1), Duration::from_secs(60)).with_jitter(0.0);

        for _ in 0..200 {
            let delay = backoff.next_delay();

            assert!(delay >= Duration::from_secs(1), "delay collapsed to {delay:?}");
            assert!(delay <= Duration::from_secs(60));
        }
    }

    /// Without jitter, every agent reconnects in the same millisecond and
    /// flattens the service that just came back.
    #[test]
    fn jitter_spreads_a_fleet_out_rather_than_reconnecting_in_lockstep() {
        let mut delays = Vec::new();

        for machine in 0..12_u64 {
            let mut backoff = Backoff::presence();
            // Each agent's sequence starts somewhere different, as it would
            // with a different process and a different start time.
            backoff.seed ^= machine.wrapping_mul(0x9E37_79B9_7F4A_7C15);

            let _ = backoff.next_delay();
            let _ = backoff.next_delay();
            delays.push(backoff.next_delay());
        }

        let distinct: std::collections::HashSet<_> = delays.iter().collect();

        assert!(
            distinct.len() > 6,
            "twelve agents produced only {} distinct delays: {delays:?}",
            distinct.len()
        );
    }

    #[test]
    fn jitter_stays_inside_its_declared_band() {
        let mut backoff = Backoff::new(Duration::from_secs(10), Duration::from_secs(10)).with_jitter(0.3);

        for _ in 0..500 {
            let delay = backoff.next_delay();

            assert!(delay >= Duration::from_millis(7_000), "{delay:?} below the band");
            assert!(delay <= Duration::from_millis(13_000), "{delay:?} above the band");
        }
    }

    /// Nobody is watching a machine in a back office. Giving up would leave a
    /// device silently unreachable.
    #[test]
    fn the_agent_never_stops_trying() {
        let mut backoff = Backoff::presence();

        for _ in 0..10_000 {
            assert!(backoff.next_delay() > Duration::ZERO);
        }
    }

    #[test]
    fn authentication_backs_off_further_than_presence() {
        let mut presence = Backoff::presence().with_jitter(0.0);
        let mut auth = Backoff::authentication().with_jitter(0.0);

        for _ in 0..12 {
            let _ = presence.next_delay();
            let _ = auth.next_delay();
        }

        assert_eq!(presence.next_delay(), Duration::from_secs(60));
        assert_eq!(auth.next_delay(), Duration::from_secs(300));
    }

    /// Retrying against a revocation is pointless; saying so is useful.
    #[test]
    fn a_revoked_device_is_the_one_thing_worth_giving_up_on() {
        assert!(!DisconnectReason::DeviceRevoked.is_retryable());

        for reason in [
            DisconnectReason::Network,
            DisconnectReason::TokenExpired,
            DisconnectReason::ApiUnavailable,
            DisconnectReason::SignallingUnavailable,
            DisconnectReason::Suspending,
        ] {
            assert!(reason.is_retryable(), "{reason:?} should be retried");
            assert!(!reason.describe().is_empty());
        }
    }
}
