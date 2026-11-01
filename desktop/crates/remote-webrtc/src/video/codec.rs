//! Where an encoder gets OpenH264 from.
//!
//! The application ships no H.264 encoder of its own. It loads Cisco's
//! prebuilt OpenH264 binary, which [`super::cisco::CodecManager`] downloads
//! from Cisco, checks and loads once; every encoder then takes the codec from
//! one shared [`CodecSource`]. The source is a slot, not a value, because the
//! codec comes and goes while the application runs:
//!
//! * it arrives a few seconds after start, or minutes later on a slow link,
//!   or only when somebody presses Retry — and a session that started
//!   without it should start sending the moment it is there;
//! * the person at the computer can switch it off at any time (Cisco's
//!   licence requires that they can), and a session that is using it must
//!   then stop using it rather than carry on with a library that is about to
//!   be deleted.
//!
//! Hence the generation counter. Every change to the slot bumps it, and an
//! encoder compares the generation it was built under with the current one on
//! every frame — one atomic load — so a removed codec is let go of at the next
//! frame, and a fresh one is picked up at the next frame too.
//!
//! The tests use the same slot with OpenH264 compiled from source in it
//! (`CodecSource::bundled`, behind the test-only `bundled-openh264`
//! feature), which is what lets them run where Cisco cannot be reached.

use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex, RwLock, Weak};
use std::time::{Duration, Instant};

use openh264::OpenH264API;

use super::cisco::LoadedCodec;
use super::h264::VideoError;

/// How often an encoder that has no codec may tell the manager so. The
/// encoder asks on every frame it cannot encode — thirty times a second —
/// and the manager only needs to hear it once in a while.
const WANTED_INTERVAL: Duration = Duration::from_secs(5);

/// The codec every encoder builds from. Cheap to clone; every clone sees the
/// same codec.
#[derive(Clone, Default)]
pub struct CodecSource {
    shared: Arc<Shared>,
}

#[derive(Default)]
struct Shared {
    codec: RwLock<Option<Codec>>,
    /// Bumped, under the write lock, by every change to `codec`.
    generation: AtomicU64,
    /// Told when an encoder needed a codec and found none, or found one that
    /// no longer passes its check. Weak, because the manager owns the source.
    observer: Mutex<Option<Weak<dyn CodecObserver>>>,
    last_wanted: Mutex<Option<Instant>>,
}

/// What is in the slot.
#[derive(Clone)]
pub(crate) enum Codec {
    /// Cisco's binary, verified and loaded by the manager.
    Cisco(Arc<LoadedCodec>),
    /// OpenH264 compiled from source. Tests only.
    #[cfg(feature = "bundled-openh264")]
    Bundled,
}

/// What the manager hears from the encoders.
pub(crate) trait CodecObserver: Send + Sync {
    /// An encoder had a frame to encode and no codec to encode it with.
    fn wanted(&self);

    /// The codec failed its check when an encoder loaded it — the file
    /// changed on disk after it was installed — and has been taken out of
    /// the slot.
    fn broken(&self, reason: String);
}

impl CodecSource {
    /// A slot with nothing in it: every encoder built from it reports the
    /// codec as not installed until something is put there.
    #[must_use]
    pub fn empty() -> Self {
        Self::default()
    }

    /// A slot holding OpenH264 compiled from source.
    ///
    /// For the tests and the benchmark only — the feature behind it is never
    /// enabled for a build that ships, because Cisco's patent licence covers
    /// Cisco's binary and not a build of ours (docs/legal/OPENH264.md).
    #[cfg(feature = "bundled-openh264")]
    #[must_use]
    pub fn bundled() -> Self {
        let source = Self::default();
        source.set(Some(Codec::Bundled));
        source
    }

    /// Whether an encoder could be built now.
    #[must_use]
    pub fn is_ready(&self) -> bool {
        self.shared
            .codec
            .read()
            .map(|codec| codec.is_some())
            .unwrap_or(false)
    }

    /// Which codec the slot holds, as a number that changes whenever the
    /// codec does. An encoder built under another generation lets go of its
    /// OpenH264 instance and builds again.
    #[must_use]
    pub fn generation(&self) -> u64 {
        self.shared.generation.load(Ordering::Acquire)
    }

    /// An OpenH264 API to build one encoder with, and the generation it
    /// belongs to.
    ///
    /// For Cisco's binary this checks the file again — its pinned SHA-256,
    /// then the `openh264` crate's own list of known builds — before it is
    /// loaded for this encoder: a library is checked every time it is loaded,
    /// not only when it was downloaded. A file that no longer passes is taken
    /// out of the slot and reported, and the encoder is told the codec is not
    /// installed, which is now the truth.
    pub(crate) fn api(&self) -> Result<(OpenH264API, u64), VideoError> {
        let (codec, generation) = {
            let Ok(slot) = self.shared.codec.read() else {
                return Err(VideoError::CodecUnavailable);
            };

            (slot.clone(), self.generation())
        };

        match codec {
            None => {
                self.want();

                Err(VideoError::CodecUnavailable)
            }
            Some(Codec::Cisco(loaded)) => match loaded.api() {
                Ok(api) => Ok((api, generation)),
                Err(reason) => {
                    tracing::warn!(%reason, "the OpenH264 codec failed its check and is no longer used");
                    self.take_out(generation);

                    if let Some(observer) = self.observer() {
                        observer.broken(reason);
                    }

                    Err(VideoError::CodecUnavailable)
                }
            },
            #[cfg(feature = "bundled-openh264")]
            Some(Codec::Bundled) => Ok((OpenH264API::from_source(), generation)),
        }
    }

    /// Put a codec in the slot, or take it out.
    pub(crate) fn set(&self, codec: Option<Codec>) {
        if let Ok(mut slot) = self.shared.codec.write() {
            *slot = codec;
            self.shared.generation.fetch_add(1, Ordering::AcqRel);
        }
    }

    /// Take the codec out, but only if it is still the one of `generation` —
    /// a codec installed again in the meantime is left where it is.
    fn take_out(&self, generation: u64) {
        if let Ok(mut slot) = self.shared.codec.write() {
            if self.generation() == generation {
                *slot = None;
                self.shared.generation.fetch_add(1, Ordering::AcqRel);
            }
        }
    }

    pub(crate) fn observe(&self, observer: Weak<dyn CodecObserver>) {
        if let Ok(mut held) = self.shared.observer.lock() {
            *held = Some(observer);
        }
    }

    fn observer(&self) -> Option<Arc<dyn CodecObserver>> {
        self.shared
            .observer
            .lock()
            .ok()
            .and_then(|held| held.as_ref().and_then(Weak::upgrade))
    }

    /// An encoder had nothing to encode with. Passed on at most once every
    /// [`WANTED_INTERVAL`].
    pub(crate) fn want(&self) {
        let due = self.shared.last_wanted.lock().is_ok_and(|mut last| {
            let due = last.is_none_or(|at| at.elapsed() >= WANTED_INTERVAL);
            if due {
                *last = Some(Instant::now());
            }
            due
        });

        if due {
            if let Some(observer) = self.observer() {
                observer.wanted();
            }
        }
    }
}

/// Whether there is a codec, and which generation — never the library.
impl std::fmt::Debug for CodecSource {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("CodecSource")
            .field("ready", &self.is_ready())
            .field("generation", &self.generation())
            .finish()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[derive(Default)]
    struct Counting {
        wanted: Mutex<u32>,
    }

    impl CodecObserver for Counting {
        fn wanted(&self) {
            *self.wanted.lock().unwrap() += 1;
        }

        fn broken(&self, _reason: String) {}
    }

    #[test]
    fn an_empty_slot_says_the_codec_is_not_installed() {
        let source = CodecSource::empty();

        assert!(!source.is_ready());
        assert!(matches!(source.api(), Err(VideoError::CodecUnavailable)));
    }

    /// Every change is a new generation, so an encoder built before it
    /// notices at its next frame.
    #[cfg(feature = "bundled-openh264")]
    #[test]
    fn every_change_to_the_slot_is_a_new_generation() {
        let source = CodecSource::empty();
        let clone = source.clone();
        let first = source.generation();

        source.set(Some(Codec::Bundled));
        assert!(clone.is_ready(), "a clone sees the same slot");
        let (_, generation) = clone.api().expect("an API");
        assert_eq!(generation, source.generation());
        assert_ne!(generation, first);

        source.set(None);
        assert!(!clone.is_ready());
        assert_ne!(clone.generation(), generation);
    }

    /// The manager hears about a missing codec, but not thirty times a
    /// second.
    #[test]
    fn an_encoder_without_a_codec_is_passed_on_now_and_then() {
        let source = CodecSource::empty();
        let observer = Arc::new(Counting::default());
        let weak: Weak<dyn CodecObserver> =
            Arc::downgrade(&(observer.clone() as Arc<dyn CodecObserver>));
        source.observe(weak);

        for _ in 0..30 {
            let _ = source.api();
        }

        assert_eq!(*observer.wanted.lock().unwrap(), 1);
    }
}
