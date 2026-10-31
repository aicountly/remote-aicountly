//! When a frame is, whether it is encoded at all, and whether it is a
//! keyframe.
//!
//! Plain arithmetic on timestamps, kept apart from the encoder so that every
//! rule here is tested with numbers rather than with an encoder and a clock.

use std::time::{Duration, Instant};

/// How often an IDR is sent even when nobody asked for one.
///
/// A viewer that joins late, or loses a packet the NACK path cannot recover,
/// can start decoding from the next IDR. Two seconds bounds how long a picture
/// can stay broken; much shorter and the IDRs — several times the size of a
/// P-frame on a busy screen — become most of the bitrate.
pub const KEYFRAME_INTERVAL: Duration = Duration::from_secs(2);

/// The smallest step media time takes between two frames: one tick of the
/// 90 kHz RTP clock, rounded up. Two frames with the same RTP timestamp are,
/// to a receiver, two halves of one frame.
const MIN_STEP_US: u64 = 12;

/// How far the capture clock may run ahead of the wall clock between two
/// frames before it is disbelieved.
///
/// The Windows capture stamps frames from the system clock, which a time sync
/// can move by minutes. A capture gap longer than the real gap by more than
/// this is a clock that jumped, not a screen that was still.
const CLOCK_JUMP_TOLERANCE_US: u64 = 1_000_000;

/// How early, as a percentage of the frame interval, a frame may arrive for
/// its slot and still be encoded in it.
///
/// A 30 Hz source at a 30 fps profile delivers a frame per slot, give or take
/// scheduling jitter; without slack, every frame that came a millisecond early
/// would be dropped and the session would run at half rate. Fifteen per cent
/// (5 ms at 30 fps) passes that jitter, and is still less than a frame of any
/// refresh rate a display has, so a 144 Hz source cannot sneak two frames
/// into one slot.
const EARLY_SLACK_PERCENT: u64 = 15;

/// Turns capture timestamps into media time: microseconds since the first
/// frame, strictly increasing.
///
/// The capture's own timestamps are used when they behave, because they say
/// when the screen changed, which is what the receiver's jitter buffer should
/// be told. `Windows.Graphics.Capture` delivers only on change, so a still
/// desktop sends nothing for seconds and the next frame must say "seconds
/// later", not "one frame later" — a receiver told otherwise reads the gap as
/// network delay and grows its playout buffer, which is latency the person
/// controlling the machine feels on every click. When the capture's timestamp
/// does not advance, goes backwards, or leaps ahead of the wall clock, the
/// wall-clock gap between the frames' arrivals is used instead.
#[derive(Debug, Default)]
pub struct MediaClock {
    previous: Option<(u64, Instant)>,
    media_us: u64,
}

impl MediaClock {
    /// A clock that has seen no frame.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// The media time of a frame captured at `capture_us` (any epoch, the
    /// capture's own) and handed over at `now`.
    pub fn stamp(&mut self, capture_us: u64, now: Instant) -> u64 {
        if let Some((previous_capture, previous_arrival)) = self.previous {
            let wall_us = duration_us(now.saturating_duration_since(previous_arrival));

            let step = match capture_us.checked_sub(previous_capture) {
                Some(capture_step)
                    if capture_step > 0
                        && capture_step <= wall_us.saturating_add(CLOCK_JUMP_TOLERANCE_US) =>
                {
                    capture_step
                }
                _ => wall_us,
            };

            self.media_us = self.media_us.saturating_add(step.max(MIN_STEP_US));
        }

        self.previous = Some((capture_us, now));

        self.media_us
    }
}

/// Drops frames that arrive faster than the profile's frame rate.
///
/// Before conversion, so a dropped frame costs nothing. This is half of "drop
/// rather than queue": a 144 Hz display dragging a window would otherwise
/// hand the encoder 144 frames a second for a 30 fps budget, and either the
/// CPU or the link would fall behind. The other half is the encoder's own
/// rate control, which skips a frame when the bits are not there.
///
/// Time is divided into slots one frame interval apart and each slot takes
/// the first frame that reaches it. Measuring from the last frame admitted
/// instead would turn every early arrival into a later next slot: a 75 Hz
/// display at a 30 fps profile would run at 25 fps. Slots keep the average at
/// the profile's rate for any source faster than it. After a still screen the
/// slots restart from the frame that ended it, so a burst of change does not
/// arrive at the encoder as a backlog of "missed" slots.
#[derive(Debug, Default)]
pub struct FrameGate {
    next_slot_us: Option<u64>,
}

impl FrameGate {
    /// A gate that admits its first frame.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// Whether a frame at `media_us` should be encoded at `max_fps`; if it
    /// should, the slot it took is used up.
    pub fn admit(&mut self, media_us: u64, max_fps: u32) -> bool {
        let interval_us = 1_000_000 / u64::from(max_fps.max(1));
        let slack_us = interval_us * EARLY_SLACK_PERCENT / 100;

        let Some(slot) = self.next_slot_us else {
            self.next_slot_us = Some(media_us.saturating_add(interval_us));

            return true;
        };

        if media_us.saturating_add(slack_us) < slot {
            return false;
        }

        let following = slot.saturating_add(interval_us);
        self.next_slot_us = Some(if media_us >= following {
            // The screen was still for longer than a slot: start again from
            // here rather than owing the encoder the slots that went by.
            media_us.saturating_add(interval_us)
        } else {
            following
        });

        true
    }
}

/// Why the next frame must be an IDR.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum KeyframeReason {
    /// Nothing has been encoded at this size yet: the first frame, or the
    /// first after the size changed and the encoder was rebuilt.
    Start,
    /// Somebody asked: the session on connecting, or the receiver with a PLI
    /// or FIR.
    Requested,
    /// The interval ran out.
    Periodic,
}

/// Decides which frames are IDRs.
///
/// A keyframe stays due until one is actually produced: the encoder's rate
/// control can skip the very frame an IDR was forced on, and a request that
/// was consumed by a skipped frame is a viewer left staring at a broken
/// picture until the next periodic one.
#[derive(Debug)]
pub struct KeyframeSchedule {
    interval_us: u64,
    last_idr_us: Option<u64>,
    requested: bool,
}

impl Default for KeyframeSchedule {
    fn default() -> Self {
        Self::new(KEYFRAME_INTERVAL)
    }
}

impl KeyframeSchedule {
    /// A schedule with an IDR at least every `interval`.
    #[must_use]
    pub fn new(interval: Duration) -> Self {
        Self {
            interval_us: duration_us(interval).max(1),
            last_idr_us: None,
            requested: false,
        }
    }

    /// Ask for the next frame to be an IDR.
    pub fn request(&mut self) {
        self.requested = true;
    }

    /// Forget the last IDR: the encoder was rebuilt and its next frame starts
    /// a new stream. A pending request is kept — it is satisfied by the same
    /// frame.
    pub fn restart(&mut self) {
        self.last_idr_us = None;
    }

    /// Whether the frame at `media_us` must be an IDR, and why.
    #[must_use]
    pub fn due(&self, media_us: u64) -> Option<KeyframeReason> {
        match self.last_idr_us {
            None => Some(KeyframeReason::Start),
            Some(_) if self.requested => Some(KeyframeReason::Requested),
            Some(last) if media_us.saturating_sub(last) >= self.interval_us => {
                Some(KeyframeReason::Periodic)
            }
            Some(_) => None,
        }
    }

    /// An IDR went out at `media_us` — asked for or not, since the encoder's
    /// scene-change detection makes some of its own. Either way it restarts
    /// the interval and answers any request.
    pub fn produced_idr(&mut self, media_us: u64) {
        self.last_idr_us = Some(media_us);
        self.requested = false;
    }
}

fn duration_us(duration: Duration) -> u64 {
    u64::try_from(duration.as_micros()).unwrap_or(u64::MAX)
}

#[cfg(test)]
mod tests {
    use super::*;

    const FRAME_US: u64 = 33_333;

    /// The capture's timestamps are believed while they behave: a still
    /// screen's five-second gap is five seconds of media time, not one frame.
    #[test]
    fn media_time_follows_the_capture_clock_including_its_gaps() {
        let start = Instant::now();
        let mut clock = MediaClock::new();

        assert_eq!(clock.stamp(1_700_000_000_000_000, start), 0);
        assert_eq!(
            clock.stamp(1_700_000_000_033_333, start + Duration::from_millis(40)),
            33_333,
            "the capture's own spacing, not the arrival jitter"
        );
        assert_eq!(
            clock.stamp(1_700_000_005_033_333, start + Duration::from_millis(5_040)),
            5_033_333,
            "a still screen's gap is kept"
        );
    }

    /// A capture that leaves the timestamp at zero, or one restarted with a
    /// new epoch, falls back to the wall clock and never runs backwards.
    #[test]
    fn a_timestamp_that_does_not_advance_falls_back_to_the_wall_clock() {
        let start = Instant::now();
        let mut clock = MediaClock::new();

        assert_eq!(clock.stamp(0, start), 0);
        assert_eq!(clock.stamp(0, start + Duration::from_millis(50)), 50_000);

        // A capture restarted with a new epoch — first forward by far more
        // than the wall clock moved, then backwards — is timed by the wall
        // clock both ways.
        assert_eq!(
            clock.stamp(9_000_000, start + Duration::from_millis(100)),
            100_000
        );
        let after_restart = clock.stamp(1_000, start + Duration::from_millis(130));
        assert_eq!(after_restart, 130_000);

        // Two frames in the same microsecond are still two RTP timestamps.
        let same = clock.stamp(1_000, start + Duration::from_millis(130));
        assert!(same > after_restart);
    }

    /// A system clock that jumps an hour forward between frames is a time
    /// sync, not an hour of still screen.
    #[test]
    fn a_capture_clock_that_leaps_ahead_is_disbelieved() {
        let start = Instant::now();
        let mut clock = MediaClock::new();

        clock.stamp(10_000_000, start);
        let next = clock.stamp(
            10_000_000 + 3_600_000_000,
            start + Duration::from_millis(33),
        );

        assert_eq!(next, 33_000);
    }

    /// How many of `count` frames `spacing_us` apart a gate at `fps` admits.
    fn admitted(spacing_us: u64, count: u64, fps: u32) -> usize {
        let mut gate = FrameGate::new();

        (0..count)
            .filter(|frame| gate.admit(frame * spacing_us, fps))
            .count()
    }

    /// Whatever the display's refresh rate, a 30 fps profile encodes 30
    /// frames a second — not 25 for a 75 Hz display, not 36 for a 144 Hz one.
    #[test]
    fn the_gate_holds_any_faster_source_to_the_profiles_rate() {
        // Two seconds of each source: 60 frames at 30 fps, plus the first.
        assert_eq!(admitted(16_667, 120, 30), 60, "60 Hz");
        assert!((60..=61).contains(&admitted(13_333, 150, 30)), "75 Hz");
        assert!((60..=61).contains(&admitted(8_333, 240, 30)), "120 Hz");
        assert!((60..=61).contains(&admitted(6_944, 288, 30)), "144 Hz");

        // A low-bandwidth profile's 12 fps is 12 fps.
        assert!((24..=25).contains(&admitted(8_333, 240, 12)), "12 fps");

        // A source slower than the profile loses nothing.
        assert_eq!(admitted(66_667, 30, 30), 30, "15 Hz");
    }

    /// A 30 Hz source a few milliseconds early or late keeps every frame:
    /// slots are measured from the schedule, not from the last arrival.
    #[test]
    fn scheduling_jitter_does_not_cost_frames() {
        let mut gate = FrameGate::new();
        let jitter = [0_i64, 4_000, -3_000, 2_000, -4_000, 0];

        let admitted = (0..60_u64)
            .filter(|&frame| {
                let at = frame as i64 * FRAME_US as i64 + jitter[frame as usize % jitter.len()];
                gate.admit(at.max(0) as u64, 30)
            })
            .count();

        assert_eq!(admitted, 60);
    }

    /// After a still screen the first change goes straight through, and the
    /// burst after it is paced from there rather than let through to "catch
    /// up" on the slots the still screen did not use.
    #[test]
    fn a_still_screen_does_not_bank_slots() {
        let mut gate = FrameGate::new();

        assert!(gate.admit(0, 30));
        assert!(
            gate.admit(5_000_000, 30),
            "the change that ends a still screen"
        );
        assert!(!gate.admit(5_010_000, 30), "10 ms later is the same slot");
        assert!(!gate.admit(5_020_000, 30));
        assert!(gate.admit(5_033_333, 30));
    }

    #[test]
    fn the_first_frame_and_the_first_after_a_rebuild_are_keyframes() {
        let mut schedule = KeyframeSchedule::default();

        assert_eq!(schedule.due(0), Some(KeyframeReason::Start));
        schedule.produced_idr(0);
        assert_eq!(schedule.due(FRAME_US), None);

        schedule.restart();
        assert_eq!(schedule.due(2 * FRAME_US), Some(KeyframeReason::Start));
    }

    #[test]
    fn a_keyframe_is_due_every_two_seconds_of_media_time() {
        let mut schedule = KeyframeSchedule::default();
        schedule.produced_idr(0);

        assert_eq!(schedule.due(1_999_999), None);
        assert_eq!(schedule.due(2_000_000), Some(KeyframeReason::Periodic));

        // Measured from the last IDR, whoever caused it.
        schedule.produced_idr(1_500_000);
        assert_eq!(schedule.due(3_000_000), None);
        assert_eq!(schedule.due(3_500_000), Some(KeyframeReason::Periodic));
    }

    /// A request stays due until an IDR actually goes out: a frame the rate
    /// control skipped does not consume it.
    #[test]
    fn a_request_stays_due_until_an_idr_is_produced() {
        let mut schedule = KeyframeSchedule::default();
        schedule.produced_idr(0);

        schedule.request();
        assert_eq!(schedule.due(FRAME_US), Some(KeyframeReason::Requested));

        // The frame was skipped; nothing was produced.
        assert_eq!(schedule.due(2 * FRAME_US), Some(KeyframeReason::Requested));

        schedule.produced_idr(3 * FRAME_US);
        assert_eq!(schedule.due(4 * FRAME_US), None);
    }
}
