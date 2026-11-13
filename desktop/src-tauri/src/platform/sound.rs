//! This computer's sound, for a session that shows its screen.
//!
//! What the computer is playing — the chime that goes with the error on
//! screen, the other half of a call — travels beside the picture when the
//! person at the computer has switched that on (App settings → "Share this
//! computer's sound"), and not otherwise. It is their switch, off until they
//! turn it on, and it is read again continuously: switching it off stops the
//! sound within a moment, mid-session, with nothing to renegotiate.
//!
//! # Nothing is captured while it is off
//!
//! The capture is opened only while the switch is on and a session is
//! connected, and closed again when either stops being true. There is no
//! "captured but discarded" state.
//!
//! # Windows: WASAPI loopback
//!
//! `IAudioClient` in shared mode with `AUDCLNT_STREAMFLAGS_LOOPBACK` on the
//! default render endpoint delivers exactly what is being sent to the
//! speakers, after the mixer, in the mixer's own format (almost always
//! 32-bit float, 48 kHz or 44.1 kHz, stereo). It needs no driver, no
//! permission prompt and no virtual device. It delivers nothing while nothing
//! is playing, which is why the sound track's packetiser knows about gaps.
//!
//! # macOS: not built
//!
//! macOS has no loopback of what the speakers play: system sound can only be
//! captured through ScreenCaptureKit (macOS 13) or a Core Audio process tap
//! (14.2), both a larger piece of native work than this file. A Mac hosts
//! without sound, [`supported`] says so, and the setting is not offered there.
//!
//! # What is decided here, and tested on every host
//!
//! How a buffer in the mixer's format becomes float samples
//! ([`SampleFormat`], [`decode`]), outside `mod imp`.

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;

use remote_device::{PlatformError, PlatformResult};
use remote_webrtc::audio::AudioSink;

/// Whether this build can capture what the computer plays.
#[must_use]
pub fn supported() -> bool {
    cfg!(target_os = "windows")
}

/// How the mixer lays out one sample.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SampleFormat {
    /// 32-bit IEEE float, -1.0..=1.0. What Windows mixes in.
    Float32,
    /// 16-bit signed integer.
    Int16,
    /// 24-bit signed integer in three bytes.
    Int24,
    /// 32-bit signed integer.
    Int32,
}

impl SampleFormat {
    /// The format a `WAVEFORMATEX` describes.
    ///
    /// `tag` is `wFormatTag`, `bits` is `wBitsPerSample`, and `sub_format` is
    /// the first field of the `SubFormat` GUID when the tag is
    /// `WAVE_FORMAT_EXTENSIBLE` — which, like the tags, is 1 for integer PCM
    /// and 3 for IEEE float. `None` for a layout this does not read, so the
    /// capture refuses rather than playing noise.
    #[must_use]
    pub fn of(tag: u16, bits: u16, sub_format: Option<u32>) -> Option<Self> {
        const PCM: u32 = 1;
        const IEEE_FLOAT: u32 = 3;
        const EXTENSIBLE: u16 = 0xFFFE;

        let kind = if tag == EXTENSIBLE {
            sub_format?
        } else {
            u32::from(tag)
        };

        match (kind, bits) {
            (IEEE_FLOAT, 32) => Some(Self::Float32),
            (PCM, 16) => Some(Self::Int16),
            (PCM, 24) => Some(Self::Int24),
            (PCM, 32) => Some(Self::Int32),
            _ => None,
        }
    }

    /// Bytes in one sample.
    #[must_use]
    pub fn bytes(self) -> usize {
        match self {
            Self::Int16 => 2,
            Self::Int24 => 3,
            Self::Float32 | Self::Int32 => 4,
        }
    }
}

/// One buffer of little-endian samples in `format`, as floats.
///
/// A trailing partial sample is ignored: a buffer is whole frames, and one
/// that is not has been cut somewhere it should not be read past.
#[must_use]
pub fn decode(bytes: &[u8], format: SampleFormat) -> Vec<f32> {
    bytes
        .chunks_exact(format.bytes())
        .map(|sample| match format {
            SampleFormat::Float32 => {
                f32::from_le_bytes([sample[0], sample[1], sample[2], sample[3]])
            }
            SampleFormat::Int16 => f32::from(i16::from_le_bytes([sample[0], sample[1]])) / 32_768.0,
            SampleFormat::Int24 => {
                // Sign-extended through the top byte of an i32.
                let value = i32::from_le_bytes([0, sample[0], sample[1], sample[2]]) >> 8;

                value as f32 / 8_388_608.0
            }
            SampleFormat::Int32 => {
                i32::from_le_bytes([sample[0], sample[1], sample[2], sample[3]]) as f32
                    / 2_147_483_648.0
            }
        })
        .collect()
}

/// A running capture of what the computer plays. Stops when dropped.
pub struct SoundCapture {
    stop: Arc<AtomicBool>,
    thread: Option<std::thread::JoinHandle<()>>,
}

impl SoundCapture {
    /// Start handing this computer's sound to `sink`, for as long as
    /// `enabled` says the person at the computer has it switched on.
    ///
    /// `enabled` is asked many times a second, on the capture's own thread;
    /// while it says no, nothing is captured at all.
    ///
    /// # Errors
    ///
    /// [`PlatformError::Unsupported`] where there is no capture — everywhere
    /// but Windows.
    pub fn start(
        sink: AudioSink,
        enabled: Arc<dyn Fn() -> bool + Send + Sync>,
    ) -> PlatformResult<Self> {
        if !supported() {
            let _ = (sink, enabled);

            return Err(PlatformError::Unsupported("Sharing this computer's sound"));
        }

        let stop = Arc::new(AtomicBool::new(false));

        #[cfg(target_os = "windows")]
        let thread = {
            let stop = Arc::clone(&stop);

            std::thread::Builder::new()
                .name("aicountly-remote-sound".into())
                .spawn(move || imp::run(&sink, enabled.as_ref(), &stop))
                .map_err(|error| PlatformError::Os {
                    operation: "starting the sound capture",
                    detail: error.to_string(),
                })?
        };

        #[cfg(not(target_os = "windows"))]
        let thread = std::thread::spawn(|| {});

        Ok(Self {
            stop,
            thread: Some(thread),
        })
    }
}

impl Drop for SoundCapture {
    fn drop(&mut self) {
        self.stop.store(true, Ordering::SeqCst);

        // The thread looks at the flag every few milliseconds; waiting for it
        // means the capture is closed by the time the session says it ended.
        if let Some(thread) = self.thread.take() {
            let _ = thread.join();
        }
    }
}

#[cfg(target_os = "windows")]
mod imp {
    //! The WASAPI half. Compiled only on Windows.

    use std::sync::atomic::{AtomicBool, Ordering};
    use std::time::Duration;

    use remote_webrtc::audio::{AudioChunk, AudioSink};
    use windows::Win32::Media::Audio::{
        eConsole, eRender, IAudioCaptureClient, IAudioClient, IMMDeviceEnumerator,
        MMDeviceEnumerator, AUDCLNT_BUFFERFLAGS_SILENT, AUDCLNT_SHAREMODE_SHARED,
        AUDCLNT_STREAMFLAGS_LOOPBACK, WAVEFORMATEX, WAVEFORMATEXTENSIBLE,
    };
    use windows::Win32::System::Com::{
        CoCreateInstance, CoInitializeEx, CoTaskMemFree, CoUninitialize, CLSCTX_ALL,
        COINIT_MULTITHREADED,
    };

    use super::{decode, SampleFormat};

    /// How often the capture is drained. Shorter than a packet, so sound
    /// reaches the encoder in pieces smaller than the ones it sends.
    const DRAIN_INTERVAL: Duration = Duration::from_millis(10);

    /// How often the switch is looked at while it is off.
    const IDLE_INTERVAL: Duration = Duration::from_millis(250);

    /// How long to wait before opening the capture again after it failed —
    /// the default device changed, the sound service restarted.
    const RETRY_INTERVAL: Duration = Duration::from_secs(2);

    /// The capture buffer Windows keeps for us, in 100-nanosecond units:
    /// 200 ms, twenty times what is drained at a time.
    const BUFFER_DURATION: i64 = 2_000_000;

    /// The thread: capture while the switch is on, wait while it is off, and
    /// open the device again if it goes away.
    pub fn run(sink: &AudioSink, enabled: &(dyn Fn() -> bool + Send + Sync), stop: &AtomicBool) {
        // SAFETY: initialises COM for this thread, which owns every COM
        // object below and uninitialises before it exits.
        let initialised = unsafe { CoInitializeEx(None, COINIT_MULTITHREADED) }.is_ok();

        let mut failure_logged = false;

        while !stop.load(Ordering::SeqCst) {
            if !enabled() {
                std::thread::sleep(IDLE_INTERVAL);

                continue;
            }

            match capture(sink, enabled, stop) {
                Ok(()) => failure_logged = false,
                Err(detail) => {
                    // Once, not every two seconds: a computer with no sound
                    // device at all fails the same way for the whole session.
                    if !failure_logged {
                        failure_logged = true;
                        tracing::warn!(%detail, "this computer's sound could not be captured");
                    }

                    sleep_unless_stopped(RETRY_INTERVAL, stop);
                }
            }
        }

        if initialised {
            // SAFETY: pairs with the successful CoInitializeEx above, after
            // every COM object made on this thread has been dropped.
            unsafe { CoUninitialize() };
        }
    }

    fn sleep_unless_stopped(duration: Duration, stop: &AtomicBool) {
        let step = Duration::from_millis(50);
        let mut waited = Duration::ZERO;

        while waited < duration && !stop.load(Ordering::SeqCst) {
            std::thread::sleep(step);
            waited += step;
        }
    }

    /// Capture until the switch goes off or the session ends. `Err` when the
    /// device could not be opened or went away.
    fn capture(
        sink: &AudioSink,
        enabled: &(dyn Fn() -> bool + Send + Sync),
        stop: &AtomicBool,
    ) -> Result<(), String> {
        let message = |error: windows::core::Error| error.message();

        // SAFETY: documented COM calls with correctly typed arguments; every
        // result is checked, the mix format is freed exactly once, and each
        // buffer taken with GetBuffer is given back with ReleaseBuffer before
        // the next is asked for.
        unsafe {
            let enumerator: IMMDeviceEnumerator =
                CoCreateInstance(&MMDeviceEnumerator, None, CLSCTX_ALL).map_err(message)?;

            // What is being played to the default output, whatever it is.
            let device = enumerator
                .GetDefaultAudioEndpoint(eRender, eConsole)
                .map_err(message)?;

            let client: IAudioClient = device.Activate(CLSCTX_ALL, None).map_err(message)?;

            let mix = client.GetMixFormat().map_err(message)?;
            let described = read_format(mix);

            let initialised = client.Initialize(
                AUDCLNT_SHAREMODE_SHARED,
                AUDCLNT_STREAMFLAGS_LOOPBACK,
                BUFFER_DURATION,
                0,
                mix,
                None,
            );

            CoTaskMemFree(Some(mix as *const core::ffi::c_void));
            initialised.map_err(message)?;

            let Some((format, channels, sample_rate, frame_bytes)) = described else {
                return Err(
                    "the sound card mixes in a format AICOUNTLY Remote does not read".into(),
                );
            };

            let capture: IAudioCaptureClient = client.GetService().map_err(message)?;
            client.Start().map_err(message)?;

            tracing::info!(
                sample_rate,
                channels,
                "this computer's sound is being shared"
            );

            let outcome = (|| -> Result<(), String> {
                while !stop.load(Ordering::SeqCst) && enabled() {
                    std::thread::sleep(DRAIN_INTERVAL);

                    loop {
                        let waiting = capture.GetNextPacketSize().map_err(message)?;
                        if waiting == 0 {
                            break;
                        }

                        let mut data: *mut u8 = std::ptr::null_mut();
                        let mut frames = 0_u32;
                        let mut flags = 0_u32;

                        capture
                            .GetBuffer(&mut data, &mut frames, &mut flags, None, None)
                            .map_err(message)?;

                        let silent = flags & (AUDCLNT_BUFFERFLAGS_SILENT.0 as u32) != 0;

                        let samples = if frames == 0 {
                            Vec::new()
                        } else if silent || data.is_null() {
                            vec![0.0; frames as usize * usize::from(channels)]
                        } else {
                            let bytes =
                                std::slice::from_raw_parts(data, frames as usize * frame_bytes);

                            decode(bytes, format)
                        };

                        capture.ReleaseBuffer(frames).map_err(message)?;

                        if !samples.is_empty() {
                            // Dropped if the session cannot take it right
                            // now: late sound is worse than a gap.
                            let _ = sink.push(AudioChunk {
                                sample_rate,
                                channels,
                                samples,
                            });
                        }
                    }
                }

                Ok(())
            })();

            let _ = client.Stop();

            tracing::info!("this computer's sound is no longer being shared");

            outcome
        }
    }

    /// The sample format, channel count, rate and bytes per frame a mix
    /// format describes, or `None` for one this does not read.
    ///
    /// # Safety
    ///
    /// `mix` must be the pointer `GetMixFormat` returned, not yet freed.
    unsafe fn read_format(mix: *const WAVEFORMATEX) -> Option<(SampleFormat, u16, u32, usize)> {
        if mix.is_null() {
            return None;
        }

        // SAFETY: `mix` points at a WAVEFORMATEX; the struct is packed, so
        // it is read unaligned.
        let format = unsafe { std::ptr::read_unaligned(mix) };

        let tag = format.wFormatTag;
        let bits = format.wBitsPerSample;
        let channels = format.nChannels;
        let sample_rate = format.nSamplesPerSec;
        let frame_bytes = usize::from(format.nBlockAlign);
        let extra = format.cbSize;

        // The extensible form carries the real sample type in a GUID after
        // the basic fields; its first field is 1 for PCM and 3 for float.
        let sub_format = if tag == 0xFFFE && extra >= 22 {
            // SAFETY: a WAVE_FORMAT_EXTENSIBLE with cbSize >= 22 is a whole
            // WAVEFORMATEXTENSIBLE.
            let extensible =
                unsafe { std::ptr::read_unaligned(mix.cast::<WAVEFORMATEXTENSIBLE>()) };
            let guid = extensible.SubFormat;

            Some(guid.data1)
        } else {
            None
        };

        let sample = SampleFormat::of(tag, bits, sub_format)?;

        if channels == 0
            || sample_rate == 0
            || frame_bytes != sample.bytes() * usize::from(channels)
        {
            return None;
        }

        Some((sample, channels, sample_rate, frame_bytes))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Windows mixes in 32-bit float, described the extensible way; the
    /// older tags and integer PCM are read as well, and anything else is
    /// refused rather than played as noise.
    #[test]
    fn the_mixers_format_is_recognised_or_refused() {
        // WAVE_FORMAT_EXTENSIBLE with the IEEE float sub-format: the usual.
        assert_eq!(
            SampleFormat::of(0xFFFE, 32, Some(3)),
            Some(SampleFormat::Float32)
        );
        assert_eq!(
            SampleFormat::of(0xFFFE, 16, Some(1)),
            Some(SampleFormat::Int16)
        );
        assert_eq!(
            SampleFormat::of(0xFFFE, 24, Some(1)),
            Some(SampleFormat::Int24)
        );
        // The plain tags.
        assert_eq!(SampleFormat::of(3, 32, None), Some(SampleFormat::Float32));
        assert_eq!(SampleFormat::of(1, 16, None), Some(SampleFormat::Int16));
        assert_eq!(SampleFormat::of(1, 32, None), Some(SampleFormat::Int32));

        // Extensible with no sub-format, 64-bit float, 8-bit PCM, A-law.
        assert_eq!(SampleFormat::of(0xFFFE, 32, None), None);
        assert_eq!(SampleFormat::of(3, 64, None), None);
        assert_eq!(SampleFormat::of(1, 8, None), None);
        assert_eq!(SampleFormat::of(6, 8, None), None);
    }

    #[test]
    fn every_format_decodes_to_the_same_loudness() {
        // Half scale, then minus half scale, in each layout.
        let float: Vec<u8> = [0.5_f32, -0.5]
            .iter()
            .flat_map(|sample| sample.to_le_bytes())
            .collect();
        assert_eq!(decode(&float, SampleFormat::Float32), vec![0.5, -0.5]);

        let int16: Vec<u8> = [16_384_i16, -16_384]
            .iter()
            .flat_map(|sample| sample.to_le_bytes())
            .collect();
        assert_eq!(decode(&int16, SampleFormat::Int16), vec![0.5, -0.5]);

        // 0x400000 and its negative, little-endian, three bytes each.
        let int24 = [0x00, 0x00, 0x40, 0x00, 0x00, 0xC0];
        assert_eq!(decode(&int24, SampleFormat::Int24), vec![0.5, -0.5]);

        let int32: Vec<u8> = [1_073_741_824_i32, -1_073_741_824]
            .iter()
            .flat_map(|sample| sample.to_le_bytes())
            .collect();
        assert_eq!(decode(&int32, SampleFormat::Int32), vec![0.5, -0.5]);
    }

    /// A buffer cut mid-sample is read up to the last whole one.
    #[test]
    fn a_partial_sample_at_the_end_is_ignored() {
        let bytes = [0x00, 0x40, 0x00, 0xC0, 0x7F];

        assert_eq!(decode(&bytes, SampleFormat::Int16), vec![0.5, -0.5]);
        assert!(decode(&[], SampleFormat::Float32).is_empty());
    }

    /// Where there is no capture, starting one says so rather than starting
    /// a thread that does nothing.
    #[cfg(not(target_os = "windows"))]
    #[test]
    fn off_windows_sound_is_unsupported_and_said_to_be() {
        assert!(!supported());

        let sink = AudioSink::new(|_| true);
        let outcome = SoundCapture::start(sink, Arc::new(|| true));

        assert!(matches!(outcome, Err(PlatformError::Unsupported(_))));
    }
}
