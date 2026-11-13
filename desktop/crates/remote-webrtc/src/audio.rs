//! The computer's sound, as a session sends it.
//!
//! A hosted session can carry what the computer is playing — the notification
//! that explains the error on screen, the other half of a call — beside its
//! picture. This module is the portable half: a chunk of captured sound comes
//! in, at whatever rate and channel count the sound card mixes at, and
//! 20-millisecond packets a browser can play come out.
//!
//! # G.711 µ-law, and why
//!
//! The track is `audio/PCMU`: G.711 µ-law, 8 kHz, one channel. It is the one
//! audio codec every WebRTC browser is *required* to decode (RFC 7874), it is
//! a table lookup rather than a library — nothing to download, license or
//! link — and it is exactly correct or audibly wrong, which makes it
//! testable here. It sounds like a telephone line: speech and system sounds
//! are clear, music is not what it is for. Opus would carry full-band stereo
//! and needs an encoder the application does not ship; that is a later
//! decision about a dependency, not a change to anything here but the
//! encoder behind [`PcmuEncoder`].
//!
//! # Nothing is kept
//!
//! As with the picture: a chunk is converted, packetised and dropped. There
//! is no buffer of what the computer played beyond the fraction of a packet
//! still being filled.

/// The sample rate of the encoded sound. G.711's.
pub const AUDIO_CLOCK_RATE: u32 = 8_000;

/// Samples in one packet: 20 ms, which is what every browser expects of
/// G.711 unless told otherwise.
pub const PACKET_SAMPLES: usize = 160;

/// The MIME type the sound track is negotiated as.
pub const PCMU_MIME_TYPE: &str = "audio/PCMU";

/// One piece of captured sound, as the capture hands it over.
///
/// Interleaved 32-bit float samples in -1.0..=1.0, `channels` to a frame, at
/// `sample_rate` — the sound card's own mix format, untouched.
#[derive(Debug, Clone, PartialEq)]
pub struct AudioChunk {
    /// Frames a second.
    pub sample_rate: u32,
    /// Samples in a frame: 2 for stereo.
    pub channels: u16,
    /// The samples, interleaved.
    pub samples: Vec<f32>,
}

/// Where captured sound goes: the session's sound track.
///
/// A handle that can be given to the capture's own thread. `push` never
/// waits — a chunk the session cannot take right now is dropped, because
/// sound that arrives late is worse than a gap.
#[derive(Clone)]
pub struct AudioSink {
    push: std::sync::Arc<dyn Fn(AudioChunk) -> bool + Send + Sync>,
}

impl AudioSink {
    /// A sink that hands each chunk to `push`, which says whether it took it.
    #[must_use]
    pub fn new(push: impl Fn(AudioChunk) -> bool + Send + Sync + 'static) -> Self {
        Self {
            push: std::sync::Arc::new(push),
        }
    }

    /// Hand over one chunk. `false` when it was dropped.
    pub fn push(&self, chunk: AudioChunk) -> bool {
        (self.push)(chunk)
    }
}

impl std::fmt::Debug for AudioSink {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("AudioSink").finish_non_exhaustive()
    }
}

/// G.711 µ-law: one 16-bit linear sample as one byte.
///
/// The algorithm of ITU-T G.711, as every implementation writes it: bias,
/// clip, find the segment, keep four bits of mantissa, and invert.
#[must_use]
pub fn mulaw(sample: i16) -> u8 {
    const BIAS: i32 = 0x84;
    const CLIP: i32 = 32_635;

    let mut magnitude = i32::from(sample);
    let sign = if magnitude < 0 {
        magnitude = -magnitude;
        0x80
    } else {
        0
    };

    magnitude = magnitude.min(CLIP) + BIAS;

    // The segment: the position of the highest set bit, from bit 14 down.
    let mut exponent = 7;
    let mut mask = 0x4000;
    while magnitude & mask == 0 && exponent > 0 {
        exponent -= 1;
        mask >>= 1;
    }

    let mantissa = (magnitude >> (exponent + 3)) & 0x0F;

    !((sign | (exponent << 4) | mantissa) as u8)
}

/// Turns captured sound into G.711 packets.
///
/// Mixes every channel down to one, brings the rate down to 8 kHz by
/// averaging — each output sample is the mean of the input samples that fall
/// in its 125 microseconds, which is both the low-pass filter the lower rate
/// needs and the decimation — and cuts the result into 160-sample packets.
#[derive(Debug, Clone, Default)]
pub struct PcmuEncoder {
    /// The sum of the input samples in the output sample being filled.
    sum: f64,
    /// How many there are.
    count: u32,
    /// How far through the output sample's period the input has got, in
    /// input samples.
    position: f64,
    /// Encoded samples not yet a whole packet.
    pending: Vec<u8>,
}

impl PcmuEncoder {
    /// A new encoder, with nothing pending.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// Take a chunk; return the packets it completed, oldest first.
    ///
    /// A chunk with no channels or no rate describes no sound and produces
    /// nothing. A rate below 8 kHz is passed through sample for sample rather
    /// than refused: no sound card mixes that low, and a session should not
    /// fall silent over one that claims to.
    pub fn push(&mut self, chunk: &AudioChunk) -> Vec<Vec<u8>> {
        let channels = usize::from(chunk.channels);

        if channels == 0 || chunk.sample_rate == 0 {
            return Vec::new();
        }

        let ratio = (f64::from(chunk.sample_rate) / f64::from(AUDIO_CLOCK_RATE)).max(1.0);
        let mut packets = Vec::new();

        for frame in chunk.samples.chunks_exact(channels) {
            let mono = frame.iter().map(|sample| f64::from(*sample)).sum::<f64>() / channels as f64;

            // Not-a-number from a broken driver must not poison the average.
            self.sum += if mono.is_finite() { mono } else { 0.0 };
            self.count += 1;
            self.position += 1.0;

            if self.position < ratio {
                continue;
            }

            self.position -= ratio;

            let average = self.sum / f64::from(self.count.max(1));
            self.sum = 0.0;
            self.count = 0;

            let linear = (average.clamp(-1.0, 1.0) * f64::from(i16::MAX)).round() as i16;
            self.pending.push(mulaw(linear));

            if self.pending.len() == PACKET_SAMPLES {
                packets.push(std::mem::replace(
                    &mut self.pending,
                    Vec::with_capacity(PACKET_SAMPLES),
                ));
            }
        }

        packets
    }

    /// Forget a partly filled packet: the sound stopped, and what follows the
    /// gap is not its continuation.
    pub fn reset(&mut self) {
        *self = Self::default();
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The inverse, for the tests: ITU-T G.711 µ-law expansion.
    fn expand(byte: u8) -> i16 {
        let byte = !byte;
        let sign = byte & 0x80;
        let exponent = i32::from((byte >> 4) & 0x07);
        let mantissa = i32::from(byte & 0x0F);
        let magnitude = (((mantissa << 3) + 0x84) << exponent) - 0x84;

        if sign != 0 {
            -magnitude as i16
        } else {
            magnitude as i16
        }
    }

    /// The values the standard itself is quoted by: silence is 0xFF, the
    /// loudest positive sample 0x80 and the loudest negative 0x00.
    #[test]
    fn mulaw_matches_g711_at_its_landmarks() {
        assert_eq!(mulaw(0), 0xFF);
        assert_eq!(mulaw(-1), 0x7F);
        assert_eq!(mulaw(i16::MAX), 0x80);
        assert_eq!(mulaw(i16::MIN), 0x00);
        // The first step of the second segment, either side of zero.
        assert_eq!(mulaw(132), 0xEF);
        assert_eq!(mulaw(-132), 0x6F);
    }

    /// Companding is lossy by design — fine steps near silence, coarse ones
    /// near full scale — but what comes back is never further off than the
    /// step it was quantised to.
    #[test]
    fn mulaw_round_trips_within_its_own_step() {
        for sample in (-32_000..=32_000).step_by(37) {
            let sample = sample as i16;
            let back = i32::from(expand(mulaw(sample)));
            let error = (back - i32::from(sample)).abs();
            // A sixteenth of the magnitude, plus the bias, bounds every segment.
            let allowed = i32::from(sample).abs() / 16 + 0x84;

            assert!(error <= allowed, "{sample} came back as {back}");
            assert_eq!(
                back.signum(),
                i32::from(sample).signum() * i32::from(back != 0)
            );
        }
    }

    fn tone(sample_rate: u32, channels: u16, hertz: f64, seconds: f64) -> AudioChunk {
        let frames = (f64::from(sample_rate) * seconds) as usize;
        let mut samples = Vec::with_capacity(frames * usize::from(channels));

        for frame in 0..frames {
            let value = (std::f64::consts::TAU * hertz * frame as f64 / f64::from(sample_rate))
                .sin() as f32
                * 0.5;
            for _ in 0..channels {
                samples.push(value);
            }
        }

        AudioChunk {
            sample_rate,
            channels,
            samples,
        }
    }

    /// One second of sound is fifty 20-millisecond packets of 160 bytes,
    /// whatever rate and channel count it was captured at.
    #[test]
    fn a_second_of_sound_is_fifty_packets_at_any_capture_format() {
        for (rate, channels) in [
            (48_000, 2),
            (44_100, 2),
            (96_000, 6),
            (16_000, 1),
            (8_000, 1),
        ] {
            let mut encoder = PcmuEncoder::new();
            let packets = encoder.push(&tone(rate, channels, 440.0, 1.0));

            assert!(
                (49..=50).contains(&packets.len()),
                "{rate} Hz × {channels}: {} packets",
                packets.len()
            );
            assert!(packets.iter().all(|packet| packet.len() == PACKET_SAMPLES));
        }
    }

    /// A tone goes in and the same tone comes out: as loud, and at the same
    /// pitch — counted here by its zero crossings.
    #[test]
    fn a_tone_survives_the_downmix_and_the_rate_change() {
        let mut encoder = PcmuEncoder::new();
        let packets = encoder.push(&tone(48_000, 2, 440.0, 1.0));
        let decoded: Vec<i16> = packets.iter().flatten().map(|byte| expand(*byte)).collect();

        let peak = decoded
            .iter()
            .map(|sample| sample.unsigned_abs())
            .max()
            .unwrap();
        assert!(
            (14_000..=17_500).contains(&peak),
            "half scale in, about half scale out: {peak}"
        );

        let crossings = decoded
            .windows(2)
            .filter(|pair| (pair[0] < 0) != (pair[1] < 0))
            .count();
        // 440 Hz crosses zero 880 times a second.
        assert!(
            (860..=900).contains(&crossings),
            "{crossings} zero crossings in a second of 440 Hz"
        );
    }

    /// Chunks arrive in whatever sizes the sound card hands them over in; a
    /// packet that straddles two of them is completed by the second.
    #[test]
    fn a_packet_is_completed_across_chunks() {
        let mut encoder = PcmuEncoder::new();
        let whole = tone(48_000, 2, 300.0, 0.03);
        let (first, second) = whole.samples.split_at(whole.samples.len() / 2);

        let chunk = |samples: &[f32]| AudioChunk {
            sample_rate: 48_000,
            channels: 2,
            samples: samples.to_vec(),
        };

        // 15 ms: not a packet yet.
        assert!(encoder.push(&chunk(first)).is_empty());
        // 30 ms in all: one packet, and half of the next waiting.
        assert_eq!(encoder.push(&chunk(second)).len(), 1);

        encoder.reset();
        assert!(
            encoder.push(&chunk(first)).is_empty(),
            "nothing carried over a reset"
        );
    }

    /// Silence is silence, and a chunk that describes no sound produces
    /// nothing rather than dividing by zero.
    #[test]
    fn silence_and_nonsense_are_handled() {
        let mut encoder = PcmuEncoder::new();

        let quiet = AudioChunk {
            sample_rate: 48_000,
            channels: 2,
            samples: vec![0.0; 48_000 * 2 / 10],
        };
        let packets = encoder.push(&quiet);
        assert_eq!(packets.len(), 5);
        assert!(packets.iter().flatten().all(|byte| *byte == 0xFF));

        for nonsense in [
            AudioChunk {
                sample_rate: 0,
                channels: 2,
                samples: vec![0.5; 100],
            },
            AudioChunk {
                sample_rate: 48_000,
                channels: 0,
                samples: vec![0.5; 100],
            },
        ] {
            assert!(PcmuEncoder::new().push(&nonsense).is_empty());
        }

        // Out of range and not-a-number are clamped, not wrapped.
        let wild = AudioChunk {
            sample_rate: 8_000,
            channels: 1,
            samples: [f32::NAN, 9.0, -9.0].repeat(54),
        };
        let packets = PcmuEncoder::new().push(&wild);
        assert_eq!(packets.len(), 1);
        assert_eq!(packets[0][0], 0xFF, "not-a-number is silence");
        assert_eq!(packets[0][1], 0x80, "too loud is full scale");
        assert_eq!(packets[0][2], 0x00);
    }

    #[test]
    fn a_sink_hands_chunks_on_and_reports_a_refusal() {
        let taken = std::sync::Arc::new(std::sync::atomic::AtomicUsize::new(0));
        let counter = std::sync::Arc::clone(&taken);

        let sink = AudioSink::new(move |chunk| {
            counter.fetch_add(chunk.samples.len(), std::sync::atomic::Ordering::SeqCst);

            chunk.sample_rate == 48_000
        });

        assert!(sink.push(tone(48_000, 2, 440.0, 0.01)));
        assert!(!sink.push(tone(44_100, 2, 440.0, 0.01)));
        assert!(taken.load(std::sync::atomic::Ordering::SeqCst) > 0);
    }
}
