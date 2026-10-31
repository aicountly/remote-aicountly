//! Capturing a Mac's screen, through `CGDisplayCreateImage`.
//!
//! This is what lets a Mac **host** a session — an unattended connection an
//! administrator enabled, or one its owner started — rather than only take
//! input as a control companion. A companion never starts it: its picture
//! comes from its owner's browser share, and asking for Screen Recording to
//! capture a picture nobody will look at would be asking for something for
//! nothing.
//!
//! # Screen Recording, before anything is captured
//!
//! From macOS 10.15 an application needs the **Screen Recording** permission
//! to see other applications' windows. Without it `CGDisplayCreateImage` does
//! not fail — it returns a perfectly good image of the desktop wallpaper and
//! the menu bar, with every window missing. A session that went ahead would
//! show whoever is at the other end an empty desktop, and they would
//! reasonably conclude the computer had nothing open. So [`MacCapture::start`]
//! asks `CGPreflightScreenCaptureAccess` first — which only reads the answer
//! and never prompts — and refuses with [`PlatformError::PermissionDenied`],
//! worded for the person who has to fix it, when the answer is no. It asks
//! again before every frame, so a permission switched off mid-session ends
//! the capture with the same refusal instead of quietly turning into
//! wallpaper.
//!
//! Nothing here ever *prompts*. An unattended session has nobody at the
//! machine to answer a dialog, and a system prompt appearing on somebody's
//! screen because a person far away connected is not something to do to
//! them. Asking is the person's own act, from the window
//! (`permissions.rs`, `request_permission("screen_capture")`).
//!
//! # A clock, not a change feed
//!
//! `CGDisplayCreateImage` is a snapshot: it costs a full read-back of the
//! display every time it is called, whether or not anything moved. So
//! [`MacCapture::next_frame`] returns **at most one frame per profile
//! interval** ([`FramePacer`]) and `None` in between, and the profile's frame
//! rate — which congestion feedback lowers — is what bounds the cost on a
//! still desktop. That is the opposite of `Windows.Graphics.Capture`, which
//! delivers only on change; the ScreenCaptureKit follow-up below delivers on
//! change too.
//!
//! # Pixels in the picture, points for the pointer
//!
//! The picture is the display's backing store, in **pixels**: a built-in
//! Retina display that macOS lays out as 1512 × 982 points is captured as
//! 3024 × 1964. The pointer is placed in **points** (`input.rs`), the global
//! coordinate space `CGEventPost` takes. The two units never have to be
//! converted into each other, because neither crosses the wire:
//!
//! 1. the viewer sends a click as a **fraction** of the picture — 0 at the
//!    left edge, 1 at the right (`PointerPosition`; the web app's
//!    `normalisePointer` measures it against the picture as drawn, so it is
//!    the same fraction whatever size the encoder scaled the picture to);
//! 2. the picture is the whole display and nothing else;
//! 3. `input::to_global_point` turns that same fraction into points across
//!    the same display.
//!
//! A fraction of the picture is therefore the same fraction of the display at
//! any scale, and the scale is reported beside the size and never multiplied
//! in — multiplying by it as well is how a click on a Retina display lands
//! half a screen away, exactly as on Windows. The one thing that has to hold
//! is that input lands on the display being captured: while a capture runs,
//! [`MacCapture::monitors`] reports that display as `active_monitor_id`, and
//! that is the id the agent hands the input provider for every pointer event
//! of a hosted session. The tests below walk a Retina and a non-Retina
//! display through the whole chain.
//!
//! # Deprecated on macOS 14 and 15, and what replaces it
//!
//! `CGDisplayCreateImage` is deprecated from macOS 14 in favour of
//! **ScreenCaptureKit** (`SCStream`, or `SCScreenshotManager` for a still),
//! and the macOS 15 SDK goes further and makes it unavailable to Swift and
//! Objective-C compiled against that SDK. Rust declares and links the symbol
//! directly rather than through those headers, and the symbol is still
//! exported at run time — applications built against older SDKs depend on it
//! — so this is expected to work on every macOS this product supports (13,
//! 14 and 15). That is exactly what the macOS CI job and a real Sequoia
//! machine have to confirm, and Apple may remove it in a later release.
//!
//! macOS 15 Sequoia also re-confirms capture through these older APIs: an
//! application using them is asked about again from time to time ("… is
//! requesting to bypass the system private window picker and directly access
//! your screen"), on an interval Apple has changed between releases, and
//! after updates. On an unattended Mac there is nobody to answer that, and
//! what capture does while the question is open has to be checked on a real
//! Sequoia machine; the window's Screen Recording notice tells the person to
//! choose Allow when it appears.
//!
//! The follow-up is an `SCStream` capture: it delivers on change, so a still
//! desktop costs nothing; it draws the pointer (`showsCursor` — this file
//! cannot: `CGDisplayCreateImage` never includes the cursor, whatever
//! [`CaptureProfile::include_cursor`] says); it can capture a single window;
//! and it is the API Sequoia does not keep asking about. It is a larger
//! change — Objective-C blocks, a dispatch queue, `CMSampleBuffer`s — and is
//! deliberately not attempted here.
//!
//! # Frames are never stored
//!
//! A [`Frame`] is produced, handed to the encoder and dropped. Nothing here
//! keeps one — not even the previous frame for comparison, which is why a
//! still desktop is paced rather than deduplicated.
//!
//! # What is decided here, and tested on every host
//!
//! Which display is captured, which one input lands on, when a frame is due,
//! how a bitmap's byte order is read and converted to BGRA with its real row
//! length, and what happens when a display disappears — all outside
//! `mod imp`, behind [`DisplaySource`], so Linux CI runs them against a fake.
//! `mod imp` is the image call and nothing else.

use std::time::{Duration, Instant};

use remote_device::{
    CaptureProfile, Frame, PixelFormat, PlatformError, PlatformResult, ScreenCaptureProvider,
};
use remote_protocol::MonitorLayout;

use super::permissions;

/// What the capture needs from macOS: the display list, whether Screen
/// Recording is granted, and one image of one display.
///
/// A trait so the rules around the three calls — the parts that decide what
/// a viewer sees and where their clicks land — run on any host against a
/// fake, the same reason every native call in this module lives in an inner
/// `mod imp`.
pub trait DisplaySource: Send + Sync {
    /// Every display, now (`display::current_layout`).
    fn layout(&self) -> PlatformResult<MonitorLayout>;

    /// Whether this process may record the screen. Must never prompt.
    fn may_record(&self) -> bool;

    /// One frame of `display`, already BGRA. `Ok(None)` when macOS had no
    /// picture to give — the display is asleep, mid mode-change, or gone.
    fn grab(&self, display: u32, timestamp_us: u64) -> PlatformResult<Option<Frame>>;
}

/// The real displays, through Core Graphics. Off macOS every call refuses.
#[derive(Debug, Default, Clone, Copy)]
pub struct CoreGraphics;

impl DisplaySource for CoreGraphics {
    fn layout(&self) -> PlatformResult<MonitorLayout> {
        super::display::current_layout()
    }

    fn may_record(&self) -> bool {
        permissions::screen_recording_granted()
    }

    fn grab(&self, display: u32, timestamp_us: u64) -> PlatformResult<Option<Frame>> {
        #[cfg(target_os = "macos")]
        {
            imp::grab(display, timestamp_us)
        }

        #[cfg(not(target_os = "macos"))]
        {
            let _ = (display, timestamp_us);

            Err(PlatformError::Unsupported("Screen capture"))
        }
    }
}

/// When the next frame is due: at most one per interval.
///
/// The interval is measured from the last frame *taken*, not from a fixed
/// schedule, so a caller that was late never gets two frames back to back to
/// "catch up" — a burst is a frame the encoder has to drop, and the point of
/// pacing a snapshot API is that the cost per second is bounded.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct FramePacer {
    interval: Duration,
    last: Option<Instant>,
}

impl FramePacer {
    /// Paced to a profile's frame rate. Nothing taken yet, so the first frame
    /// is due at once.
    #[must_use]
    pub fn for_profile(profile: &CaptureProfile) -> Self {
        Self {
            interval: interval_of(profile),
            last: None,
        }
    }

    /// Follow a new profile. The last frame stays where it was, so lowering
    /// the frame rate under congestion cannot let one extra frame through.
    pub fn retime(&mut self, profile: &CaptureProfile) {
        self.interval = interval_of(profile);
    }

    /// The gap between frames.
    #[must_use]
    pub fn interval(&self) -> Duration {
        self.interval
    }

    /// Whether a frame is due at `now`, and if it is, take it.
    pub fn take(&mut self, now: Instant) -> bool {
        if let Some(last) = self.last {
            if now.saturating_duration_since(last) < self.interval {
                return false;
            }
        }

        self.last = Some(now);

        true
    }
}

fn interval_of(profile: &CaptureProfile) -> Duration {
    // `frame_interval_ms` never divides by zero: a profile at 0 fps is paced
    // at one frame a second.
    Duration::from_millis(profile.frame_interval_ms())
}

/// The display a capture asked for `requested` should show: that one when it
/// is connected, and otherwise the main display.
///
/// "Otherwise the main display" because the id a session asks for is not
/// always one this Mac has ever had: `CGDirectDisplayID`s are arbitrary
/// numbers (the built-in display is often 1; an external one can be
/// 69733248), and a viewer's "show me the screen" is better answered with the
/// main screen than with no picture at all.
#[must_use]
pub fn choose_display(layout: &MonitorLayout, requested: u32) -> Option<u32> {
    layout
        .find(requested)
        .or_else(|| layout.active())
        .or_else(|| layout.monitors.first())
        .map(|monitor| monitor.id)
}

/// The layout as the agent should see it: with the display being captured as
/// the active one, which is the display every pointer event of a hosted
/// session lands on.
///
/// A capture of a display that is no longer connected changes nothing — the
/// layout's own choice (the main display) stands, and the capture follows it
/// at the next frame ([`after_missing_image`]).
#[must_use]
pub fn with_active(mut layout: MonitorLayout, capturing: Option<u32>) -> MonitorLayout {
    if let Some(display) = capturing {
        if layout.find(display).is_some() {
            layout.active_monitor_id = display;
        }
    }

    layout
}

/// What to do when macOS returns no image of the display being captured.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum MissingImage {
    /// The display is still connected — asleep, or changing mode. There is
    /// no picture this interval; try again at the next.
    Wait,
    /// The display was unplugged. Capture this one instead (the main
    /// display), as a capture that started on an unknown id would.
    Retarget(u32),
}

/// Decide what a missing image means, given the displays connected now.
#[must_use]
pub fn after_missing_image(layout: &MonitorLayout, display: u32) -> MissingImage {
    if layout.find(display).is_some() {
        return MissingImage::Wait;
    }

    match choose_display(layout, display) {
        Some(next) => MissingImage::Retarget(next),
        None => MissingImage::Wait,
    }
}

/// `kCGBitmapAlphaInfoMask`.
pub const ALPHA_INFO_MASK: u32 = 0x1F;
/// `kCGBitmapFloatInfoMask`: any bit set means floating-point components.
pub const FLOAT_INFO_MASK: u32 = 0xF00;
/// `kCGBitmapByteOrderMask`.
pub const BYTE_ORDER_MASK: u32 = 0x7000;
/// `kCGBitmapByteOrderDefault` — big-endian, for a 32-bit pixel.
pub const BYTE_ORDER_DEFAULT: u32 = 0;
/// `kCGBitmapByteOrder32Little` — `kCGBitmapByteOrder32Host` on every Mac.
pub const BYTE_ORDER_32_LITTLE: u32 = 2 << 12;
/// `kCGBitmapByteOrder32Big`.
pub const BYTE_ORDER_32_BIG: u32 = 4 << 12;
/// `kCGImagePixelFormatMask`: anything but 0 (`Packed`) is a 10-bit or
/// 16-bit-per-pixel format, not four 8-bit channels.
pub const PIXEL_FORMAT_MASK: u32 = 0xF_0000;

/// `kCGImageAlphaPremultipliedLast`.
pub const ALPHA_PREMULTIPLIED_LAST: u32 = 1;
/// `kCGImageAlphaPremultipliedFirst`.
pub const ALPHA_PREMULTIPLIED_FIRST: u32 = 2;
/// `kCGImageAlphaLast`.
pub const ALPHA_LAST: u32 = 3;
/// `kCGImageAlphaFirst`.
pub const ALPHA_FIRST: u32 = 4;
/// `kCGImageAlphaNoneSkipLast`.
pub const ALPHA_NONE_SKIP_LAST: u32 = 5;
/// `kCGImageAlphaNoneSkipFirst`.
pub const ALPHA_NONE_SKIP_FIRST: u32 = 6;

/// How Core Graphics describes an image's memory, read off the image
/// (`CGImageGetWidth`, `…GetBitsPerComponent`, `…GetBitmapInfo` and so on).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct ImageLayout {
    /// Pixels.
    pub width: usize,
    /// Pixels.
    pub height: usize,
    /// 8 for the layouts this reads.
    pub bits_per_component: usize,
    /// 32 for the layouts this reads.
    pub bits_per_pixel: usize,
    /// The real row length, padding included — Core Graphics pads rows, so
    /// this is routinely more than `width × 4`, and it is what the frame's
    /// stride must be.
    pub bytes_per_row: usize,
    /// `CGBitmapInfo`: alpha placement, byte order, pixel format.
    pub bitmap_info: u32,
}

/// The order a pixel's four bytes are in, in memory.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PixelOrder {
    /// B, G, R, A — what the encoder takes, and what a Mac's display gives
    /// (32-bit little-endian, alpha first). Copied as it is.
    Bgra,
    /// A, R, G, B — big-endian, alpha first.
    Argb,
    /// R, G, B, A — big-endian, alpha last.
    Rgba,
    /// A, B, G, R — little-endian, alpha last.
    Abgr,
}

/// Which order an image's bytes are in, or why this build cannot read them.
///
/// A 32-bit pixel in Core Graphics is a 32-bit **word** whose alpha (or
/// padding) byte is either its most significant ("first") or its least
/// ("last"), stored in the byte order the bitmap info names. Little-endian
/// puts the least significant byte first, so little-endian alpha-first —
/// `kCGBitmapByteOrder32Little | kCGImageAlphaNoneSkipFirst`, which is what a
/// Mac's display hands back — is B, G, R, A in memory: exactly the encoder's
/// format, with nothing to convert.
///
/// Anything that is not four 8-bit integer channels — 16 bits a channel,
/// floating point, the 10-bit packed formats a wide-gamut display could
/// produce — is refused rather than guessed at: a guessed byte order is a
/// picture in the wrong colours, and a guessed pixel size is a picture
/// sheared into diagonal stripes.
pub fn pixel_order(layout: &ImageLayout) -> Result<PixelOrder, &'static str> {
    if layout.bits_per_component != 8 || layout.bits_per_pixel != 32 {
        return Err("a pixel that is not four 8-bit channels");
    }

    if layout.bitmap_info & FLOAT_INFO_MASK != 0 {
        return Err("floating-point pixels");
    }

    if layout.bitmap_info & PIXEL_FORMAT_MASK != 0 {
        return Err("a packed 10-bit pixel format");
    }

    let alpha_first = match layout.bitmap_info & ALPHA_INFO_MASK {
        ALPHA_PREMULTIPLIED_FIRST | ALPHA_FIRST | ALPHA_NONE_SKIP_FIRST => true,
        ALPHA_PREMULTIPLIED_LAST | ALPHA_LAST | ALPHA_NONE_SKIP_LAST => false,
        // `None` at 32 bits a pixel, or alpha only: no three colour channels
        // to read.
        _ => return Err("a pixel without three colour channels"),
    };

    match layout.bitmap_info & BYTE_ORDER_MASK {
        BYTE_ORDER_32_LITTLE if alpha_first => Ok(PixelOrder::Bgra),
        BYTE_ORDER_32_LITTLE => Ok(PixelOrder::Abgr),
        BYTE_ORDER_DEFAULT | BYTE_ORDER_32_BIG if alpha_first => Ok(PixelOrder::Argb),
        BYTE_ORDER_DEFAULT | BYTE_ORDER_32_BIG => Ok(PixelOrder::Rgba),
        _ => Err("a 16-bit byte order for a 32-bit pixel"),
    }
}

/// Copy an image's bytes into a BGRA [`Frame`], converting the byte order if
/// it is not BGRA already, and keeping the image's real row length as the
/// stride.
///
/// The stride is kept rather than the padding stripped because every consumer
/// of a [`Frame`] reads it by stride already — the Windows capture hands over
/// its texture's `RowPitch` the same way — and stripping it would be a second
/// copy of every row for nothing. The padding bytes are whatever Core
/// Graphics left there; nothing reads them.
///
/// The alpha byte is passed on as it came. A screen is opaque, so a
/// premultiplied alpha is the same as a straight one, and a "skip" byte is
/// padding the encoder ignores, as it ignores the fourth byte of the Windows
/// path's `B8G8R8A8`.
pub fn bgra_frame(layout: &ImageLayout, bytes: &[u8], timestamp_us: u64) -> PlatformResult<Frame> {
    let unreadable = |reason: &str| PlatformError::Os {
        operation: "reading a captured frame",
        detail: format!("macOS returned {reason}, which this build cannot read"),
    };

    let order = pixel_order(layout).map_err(unreadable)?;

    let width = u32::try_from(layout.width)
        .ok()
        .filter(|width| *width > 0)
        .ok_or_else(|| unreadable("an image with no width"))?;
    let height = u32::try_from(layout.height)
        .ok()
        .filter(|height| *height > 0)
        .ok_or_else(|| unreadable("an image with no height"))?;

    let stride = layout.bytes_per_row;
    let row_bytes = layout
        .width
        .checked_mul(4)
        .ok_or_else(|| unreadable("an image too wide to address"))?;

    if stride < row_bytes {
        return Err(unreadable("rows shorter than their pixels"));
    }

    // Every row whole, except that the last may stop at its last pixel: a
    // provider is entitled to leave off the final row's padding.
    let needed = stride
        .checked_mul(layout.height - 1)
        .and_then(|rows| rows.checked_add(row_bytes))
        .ok_or_else(|| unreadable("an image too large to address"))?;
    let full = stride
        .checked_mul(layout.height)
        .ok_or_else(|| unreadable("an image too large to address"))?;

    if bytes.len() < needed {
        return Err(unreadable("fewer bytes than its size needs"));
    }

    let mut data = Vec::with_capacity(full);
    data.extend_from_slice(&bytes[..bytes.len().min(full)]);
    // Only ever the missing padding of the last row, which nothing reads, but
    // `Frame` promises `stride × height` bytes and the encoder will rely on it.
    data.resize(full, 0);

    if order != PixelOrder::Bgra {
        for row in data.chunks_exact_mut(stride) {
            for pixel in row[..row_bytes].chunks_exact_mut(4) {
                match order {
                    // A R G B → B G R A
                    PixelOrder::Argb => pixel.reverse(),
                    // R G B A → B G R A
                    PixelOrder::Rgba => pixel.swap(0, 2),
                    // A B G R → B G R A
                    PixelOrder::Abgr => pixel.rotate_left(1),
                    PixelOrder::Bgra => {}
                }
            }
        }
    }

    Frame::new(
        width,
        height,
        stride,
        PixelFormat::Bgra8,
        timestamp_us,
        data,
    )
    .ok_or_else(|| unreadable("an image whose size and bytes disagree"))
}

/// What a person is told when Screen Recording is not granted.
#[must_use]
pub fn screen_recording_refused() -> PlatformError {
    PlatformError::PermissionDenied(permissions::SCREEN_RECORDING_REFUSAL.to_owned())
}

/// Screen capture, through Core Graphics.
pub struct MacCapture<S: DisplaySource = CoreGraphics> {
    source: S,
    /// The display being captured, while a capture runs.
    display: Option<u32>,
    profile: CaptureProfile,
    pacer: FramePacer,
    /// When the capture started, for the frames' timestamps.
    started: Option<Instant>,
}

impl Default for MacCapture {
    fn default() -> Self {
        Self::new()
    }
}

impl MacCapture {
    /// A capture of the real displays that has not started.
    #[must_use]
    pub fn new() -> Self {
        Self::with_source(CoreGraphics)
    }
}

impl<S: DisplaySource> MacCapture<S> {
    /// A capture of whatever `source` describes. The tests' fake, or
    /// [`CoreGraphics`].
    pub fn with_source(source: S) -> Self {
        let profile = CaptureProfile::adaptive();

        Self {
            source,
            display: None,
            profile,
            pacer: FramePacer::for_profile(&profile),
            started: None,
        }
    }

    /// The profile currently in force.
    #[must_use]
    pub fn profile(&self) -> CaptureProfile {
        self.profile
    }

    fn timestamp_us(&self) -> u64 {
        self.started
            .map(|started| u64::try_from(started.elapsed().as_micros()).unwrap_or(u64::MAX))
            .unwrap_or(0)
    }
}

impl<S: DisplaySource> ScreenCaptureProvider for MacCapture<S> {
    fn monitors(&self) -> PlatformResult<MonitorLayout> {
        self.source
            .layout()
            .map(|layout| with_active(layout, self.display))
    }

    fn start(&mut self, monitor_id: u32, profile: CaptureProfile) -> PlatformResult<()> {
        // The layout first: off macOS it is what refuses, as "not available
        // on this platform" rather than as a permission nobody can grant.
        let layout = self.source.layout()?;

        if !self.source.may_record() {
            // Whatever was running would be wallpaper from here on.
            self.stop()?;

            return Err(screen_recording_refused());
        }

        let display =
            choose_display(&layout, monitor_id).ok_or(PlatformError::NotFound("A display"))?;

        // The display already being captured: a profile change, not a
        // restart — the pacing and the timestamps carry on, so asking twice
        // cannot squeeze an extra frame out.
        if self.display == Some(display) {
            return self.reconfigure(profile);
        }

        self.stop()?;

        self.display = Some(display);
        self.profile = profile;
        self.pacer = FramePacer::for_profile(&profile);
        self.started = Some(Instant::now());

        Ok(())
    }

    fn stop(&mut self) -> PlatformResult<()> {
        // Idempotent, and it must be: this runs on every teardown path,
        // including the ones that got there because something else failed.
        // Nothing native is held between frames, so there is nothing to
        // release.
        self.display = None;
        self.started = None;

        Ok(())
    }

    fn next_frame(&mut self) -> PlatformResult<Option<Frame>> {
        let Some(display) = self.display else {
            return Ok(None);
        };

        if !self.pacer.take(Instant::now()) {
            return Ok(None);
        }

        // Asked again before every frame, because a person can switch Screen
        // Recording off in System Settings mid-session, and what the next
        // image would show then is the wallpaper. It is a read of this
        // process's own answer and costs nothing beside the read-back of a
        // whole display that follows it.
        if !self.source.may_record() {
            self.stop()?;

            return Err(screen_recording_refused());
        }

        let timestamp_us = self.timestamp_us();

        match self.source.grab(display, timestamp_us)? {
            Some(frame) => Ok(Some(frame)),
            None => {
                // A display unplugged mid-session: follow the main display,
                // and `monitors()` follows with it, so input lands on what
                // the viewer is now looking at. Anything else — a display
                // asleep or changing mode — is simply no picture this time.
                if let Ok(layout) = self.source.layout() {
                    if let MissingImage::Retarget(next) = after_missing_image(&layout, display) {
                        self.display = Some(next);
                    }
                }

                Ok(None)
            }
        }
    }

    fn reconfigure(&mut self, profile: CaptureProfile) -> PlatformResult<()> {
        // Resolution is the encoder's to change: the picture is always the
        // whole display at its own pixels, as on Windows, where sizing the
        // capture to the profile cropped it. What changes here is how often a
        // frame is taken.
        self.profile = profile;
        self.pacer.retime(&profile);

        Ok(())
    }

    fn is_running(&self) -> bool {
        self.display.is_some()
    }
}

#[cfg(target_os = "macos")]
mod imp {
    //! `CGDisplayCreateImage`. Compiled only on macOS.
    //!
    //! Declared here rather than reached through
    //! `core_graphics::display::CGDisplay::image`, because the one thing this
    //! file must read that core-graphics 0.25 does not expose —
    //! `CGImageGetBitmapInfo` — needs the raw image pointer, and the crate
    //! only hands that out through a trait of a crate this one does not
    //! depend on. Ten declarations, each checked against the Core Graphics
    //! headers, are a smaller thing to get right than a new dependency.

    use core_foundation::base::TCFType;
    use core_foundation::data::{CFData, CFDataRef};
    use core_graphics::sys::{CGDataProviderRef, CGImageRef};
    use remote_device::{Frame, PlatformError, PlatformResult};

    use super::{bgra_frame, ImageLayout};

    // `CGDirectDisplayID` is `uint32_t`; `size_t` is `usize`; `CGBitmapInfo`
    // is `CF_OPTIONS(uint32_t, …)`. Every function here may be called from
    // any thread.
    #[link(name = "CoreGraphics", kind = "framework")]
    extern "C" {
        fn CGDisplayCreateImage(display: u32) -> CGImageRef;
        fn CGImageRelease(image: CGImageRef);
        fn CGImageGetWidth(image: CGImageRef) -> usize;
        fn CGImageGetHeight(image: CGImageRef) -> usize;
        fn CGImageGetBitsPerComponent(image: CGImageRef) -> usize;
        fn CGImageGetBitsPerPixel(image: CGImageRef) -> usize;
        fn CGImageGetBytesPerRow(image: CGImageRef) -> usize;
        fn CGImageGetBitmapInfo(image: CGImageRef) -> u32;
        fn CGImageGetDataProvider(image: CGImageRef) -> CGDataProviderRef;
        fn CGDataProviderCopyData(provider: CGDataProviderRef) -> CFDataRef;
    }

    /// An image this file owns: never null, holding the one reference
    /// `CGDisplayCreateImage` returned under the Create rule, released
    /// exactly once when it drops.
    struct OwnedImage(CGImageRef);

    impl OwnedImage {
        fn of_display(display: u32) -> Option<Self> {
            // SAFETY: takes a plain display id by value; any value is
            // permitted, and an unknown or offline display returns NULL,
            // which is checked before the pointer is kept. A non-null result
            // follows the Create rule, so the reference is ours to release.
            let image = unsafe { CGDisplayCreateImage(display) };

            (!image.is_null()).then_some(Self(image))
        }

        fn layout(&self) -> ImageLayout {
            // SAFETY: `self.0` is a live, non-null CGImage for as long as
            // `self` exists. Each getter reads the image's immutable
            // description by value and retains nothing.
            unsafe {
                ImageLayout {
                    width: CGImageGetWidth(self.0),
                    height: CGImageGetHeight(self.0),
                    bits_per_component: CGImageGetBitsPerComponent(self.0),
                    bits_per_pixel: CGImageGetBitsPerPixel(self.0),
                    bytes_per_row: CGImageGetBytesPerRow(self.0),
                    bitmap_info: CGImageGetBitmapInfo(self.0),
                }
            }
        }

        /// The pixels, as an owned copy.
        fn copy_bytes(&self) -> Option<CFData> {
            // SAFETY: `self.0` is live, as above. The provider follows the
            // Get rule — owned by the image, which outlives this call — so it
            // is neither retained nor released here, and NULL is checked
            // before it is used. `CGDataProviderCopyData` returns a new
            // CFData under the Create rule, or NULL, which is checked before
            // `wrap_under_create_rule` takes over that one reference (it
            // releases it when the CFData drops, and asserts on NULL, which
            // cannot reach it).
            unsafe {
                let provider = CGImageGetDataProvider(self.0);
                if provider.is_null() {
                    return None;
                }

                let data = CGDataProviderCopyData(provider);
                if data.is_null() {
                    return None;
                }

                Some(CFData::wrap_under_create_rule(data))
            }
        }
    }

    impl Drop for OwnedImage {
        fn drop(&mut self) {
            // SAFETY: `self.0` is non-null and is the reference this value
            // was created holding; `drop` runs once, so it is released once,
            // and nothing borrowed from the image (the provider) outlives it.
            unsafe { CGImageRelease(self.0) }
        }
    }

    /// One BGRA frame of `display`, or `None` when macOS has no image of it.
    pub fn grab(display: u32, timestamp_us: u64) -> PlatformResult<Option<Frame>> {
        let Some(image) = OwnedImage::of_display(display) else {
            return Ok(None);
        };

        let layout = image.layout();

        // An image whose pixels cannot be copied is not a display that is
        // asleep — it is something wrong — so it is said rather than
        // swallowed as "no picture this time".
        let bytes = image.copy_bytes().ok_or(PlatformError::Os {
            operation: "reading a captured frame",
            detail: "macOS returned an image without its pixels".into(),
        })?;

        // The only copy into the frame, converting as it goes if the byte
        // order is not BGRA. The CFData, then the image, are released when
        // this returns.
        bgra_frame(&layout, bytes.bytes(), timestamp_us).map(Some)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::{Arc, Mutex};

    use remote_protocol::PointerPosition;

    use crate::platform::macos::display::{layout_from, scale_from_pixels, DisplayReading};
    use crate::platform::macos::input::to_global_point;

    // ------------------------------------------------------------ helpers

    fn reading(
        id: u32,
        main: bool,
        x: f64,
        width: f64,
        height: f64,
        pixels: u64,
    ) -> DisplayReading {
        DisplayReading {
            id,
            main,
            builtin: main,
            x,
            y: 0.0,
            width,
            height,
            pixel_width: pixels,
            rotation: 0.0,
        }
    }

    /// A MacBook's Retina display (main) and a non-Retina 1080p external to
    /// its right. Ids as Core Graphics hands them out: not 1, 2.
    fn desk() -> MonitorLayout {
        layout_from(&[
            reading(69_733_382, true, 0.0, 1512.0, 982.0, 3024),
            reading(722_472_005, false, 1512.0, 1920.0, 1080.0, 1920),
        ])
        .expect("a layout")
    }

    const BUILTIN: u32 = 69_733_382;
    const EXTERNAL: u32 = 722_472_005;

    fn image(width: usize, height: usize, bytes_per_row: usize, bitmap_info: u32) -> ImageLayout {
        ImageLayout {
            width,
            height,
            bits_per_component: 8,
            bits_per_pixel: 32,
            bytes_per_row,
            bitmap_info,
        }
    }

    /// What a Mac's display hands back.
    const NATIVE: u32 = BYTE_ORDER_32_LITTLE | ALPHA_NONE_SKIP_FIRST;

    /// macOS, as far as the capture can tell.
    #[derive(Debug)]
    struct World {
        layout: MonitorLayout,
        may_record: bool,
        /// Whether a display has a picture to give.
        images: bool,
        /// Every grab: which display, at what timestamp.
        grabs: Vec<(u32, u64)>,
    }

    #[derive(Clone)]
    struct Fake(Arc<Mutex<World>>);

    impl Fake {
        fn new(may_record: bool) -> Self {
            Self(Arc::new(Mutex::new(World {
                layout: desk(),
                may_record,
                images: true,
                grabs: Vec::new(),
            })))
        }

        fn world(&self) -> std::sync::MutexGuard<'_, World> {
            self.0.lock().unwrap()
        }
    }

    impl DisplaySource for Fake {
        fn layout(&self) -> PlatformResult<MonitorLayout> {
            Ok(self.world().layout.clone())
        }

        fn may_record(&self) -> bool {
            self.world().may_record
        }

        fn grab(&self, display: u32, timestamp_us: u64) -> PlatformResult<Option<Frame>> {
            let mut world = self.world();
            world.grabs.push((display, timestamp_us));

            if !world.images {
                return Ok(None);
            }

            // The display's own pixels: its points times its scale.
            let monitor = world
                .layout
                .find(display)
                .expect("grabbing a connected display");
            let width = (f64::from(monitor.width) * monitor.scale).round() as usize;
            let height = (f64::from(monitor.height) * monitor.scale).round() as usize;
            let stride = width * 4 + 64;

            bgra_frame(
                &image(width, height, stride, NATIVE),
                &vec![0; stride * height],
                timestamp_us,
            )
            .map(Some)
        }
    }

    /// Fast enough that a test never waits long for a frame to be due.
    fn quick() -> CaptureProfile {
        CaptureProfile {
            max_fps: 1000,
            ..CaptureProfile::adaptive()
        }
    }

    /// Slow enough that a second frame is never due within a test.
    fn slow() -> CaptureProfile {
        CaptureProfile {
            max_fps: 1,
            ..CaptureProfile::adaptive()
        }
    }

    fn wait_for_frame(capture: &mut MacCapture<Fake>) -> Frame {
        for _ in 0..1000 {
            if let Some(frame) = capture.next_frame().expect("no error") {
                return frame;
            }
            std::thread::sleep(Duration::from_millis(1));
        }

        panic!("no frame arrived");
    }

    // ------------------------------------------------- display selection

    #[test]
    fn a_connected_display_is_captured_and_an_unknown_one_means_the_main_display() {
        let layout = desk();

        assert_eq!(choose_display(&layout, EXTERNAL), Some(EXTERNAL));
        assert_eq!(choose_display(&layout, BUILTIN), Some(BUILTIN));
        // A Windows-style index, or a display unplugged since the session was
        // asked for: the main display, not nothing.
        assert_eq!(choose_display(&layout, 1), Some(BUILTIN));
        assert_eq!(choose_display(&layout, 0), Some(BUILTIN));
    }

    /// The main display is the fallback even when it is not listed first.
    #[test]
    fn the_fallback_is_the_main_display_wherever_it_is_listed() {
        let layout = layout_from(&[
            reading(7, false, -1920.0, 1920.0, 1080.0, 1920),
            reading(3, true, 0.0, 1512.0, 982.0, 3024),
        ])
        .unwrap();

        assert_eq!(choose_display(&layout, 99), Some(3));
    }

    #[test]
    fn while_capturing_the_captured_display_is_the_active_one() {
        assert_eq!(with_active(desk(), None).active_monitor_id, BUILTIN);
        assert_eq!(
            with_active(desk(), Some(EXTERNAL)).active_monitor_id,
            EXTERNAL
        );

        // A display that has gone leaves the layout's own choice standing.
        assert_eq!(with_active(desk(), Some(12345)).active_monitor_id, BUILTIN);
    }

    #[test]
    fn a_missing_image_waits_unless_the_display_has_gone() {
        assert_eq!(after_missing_image(&desk(), EXTERNAL), MissingImage::Wait);
        assert_eq!(
            after_missing_image(&desk(), 12345),
            MissingImage::Retarget(BUILTIN)
        );
    }

    // ------------------------------------------------------------ pacing

    #[test]
    fn the_first_frame_is_due_at_once_and_the_next_one_interval_later() {
        let mut pacer = FramePacer::for_profile(&CaptureProfile::adaptive());
        let t0 = Instant::now();

        assert_eq!(pacer.interval(), Duration::from_millis(33));
        assert!(pacer.take(t0));
        assert!(!pacer.take(t0));
        assert!(!pacer.take(t0 + Duration::from_millis(32)));
        assert!(pacer.take(t0 + Duration::from_millis(33)));
        assert!(!pacer.take(t0 + Duration::from_millis(40)));
    }

    /// Polled every millisecond for a second at 30 fps: 31 frames (0, 33, …
    /// 990 ms), never two closer together than the interval — and no burst
    /// after a late poll.
    #[test]
    fn at_most_one_frame_per_interval_however_often_it_is_asked() {
        let mut pacer = FramePacer::for_profile(&CaptureProfile::adaptive());
        let t0 = Instant::now();

        let taken: Vec<Duration> = (0..=1000)
            .map(Duration::from_millis)
            .filter(|offset| pacer.take(t0 + *offset))
            .collect();

        assert_eq!(taken.len(), 31);
        assert!(taken
            .windows(2)
            .all(|pair| pair[1] - pair[0] >= Duration::from_millis(33)));

        // A caller that went quiet for half a second gets one frame, not
        // fifteen to catch up.
        let late = t0 + Duration::from_millis(1500);
        assert!(pacer.take(late));
        assert!(!pacer.take(late + Duration::from_millis(1)));
    }

    /// Lowering the frame rate under congestion does not let an extra frame
    /// through, and the new interval applies from the last frame taken.
    #[test]
    fn a_new_profile_retimes_the_pacing_from_the_last_frame() {
        let mut pacer = FramePacer::for_profile(&CaptureProfile::adaptive());
        let t0 = Instant::now();
        assert!(pacer.take(t0));

        pacer.retime(&CaptureProfile::low_bandwidth());
        assert_eq!(pacer.interval(), Duration::from_millis(83));
        assert!(!pacer.take(t0 + Duration::from_millis(50)));
        assert!(pacer.take(t0 + Duration::from_millis(83)));

        // A profile at 0 fps is a frame a second, not a division by zero.
        pacer.retime(&CaptureProfile {
            max_fps: 0,
            ..CaptureProfile::adaptive()
        });
        assert_eq!(pacer.interval(), Duration::from_secs(1));
    }

    // ------------------------------------------------------ bytes per row

    #[test]
    fn a_macs_own_layout_is_bgra_and_every_other_32_bit_layout_is_named() {
        let order = |info| pixel_order(&image(4, 4, 16, info));

        assert_eq!(order(NATIVE), Ok(PixelOrder::Bgra));
        assert_eq!(
            order(BYTE_ORDER_32_LITTLE | ALPHA_PREMULTIPLIED_FIRST),
            Ok(PixelOrder::Bgra)
        );
        assert_eq!(
            order(BYTE_ORDER_32_BIG | ALPHA_PREMULTIPLIED_LAST),
            Ok(PixelOrder::Rgba)
        );
        assert_eq!(
            order(BYTE_ORDER_DEFAULT | ALPHA_NONE_SKIP_LAST),
            Ok(PixelOrder::Rgba)
        );
        assert_eq!(order(BYTE_ORDER_32_BIG | ALPHA_FIRST), Ok(PixelOrder::Argb));
        assert_eq!(
            order(BYTE_ORDER_DEFAULT | ALPHA_NONE_SKIP_FIRST),
            Ok(PixelOrder::Argb)
        );
        assert_eq!(
            order(BYTE_ORDER_32_LITTLE | ALPHA_LAST),
            Ok(PixelOrder::Abgr)
        );
    }

    /// Guessing is how a picture comes out in the wrong colours or sheared
    /// into stripes. Anything that is not four 8-bit channels is refused.
    #[test]
    fn a_layout_that_is_not_four_8_bit_channels_is_refused_rather_than_guessed() {
        let wide = ImageLayout {
            bits_per_component: 16,
            bits_per_pixel: 64,
            ..image(4, 4, 32, NATIVE)
        };
        let rgb = ImageLayout {
            bits_per_pixel: 24,
            ..image(4, 4, 12, BYTE_ORDER_DEFAULT)
        };

        assert!(pixel_order(&wide).is_err());
        assert!(pixel_order(&rgb).is_err());
        assert!(
            pixel_order(&image(4, 4, 16, NATIVE | 0x100)).is_err(),
            "float"
        );
        assert!(
            pixel_order(&image(4, 4, 16, NATIVE | (3 << 16))).is_err(),
            "10-bit"
        );
        assert!(
            pixel_order(&image(4, 4, 16, BYTE_ORDER_32_LITTLE)).is_err(),
            "no alpha"
        );
        assert!(
            pixel_order(&image(4, 4, 16, BYTE_ORDER_32_LITTLE | 7)).is_err(),
            "alpha only"
        );
        assert!(
            pixel_order(&image(4, 4, 16, (1 << 12) | ALPHA_NONE_SKIP_FIRST)).is_err(),
            "16-bit byte order"
        );
    }

    /// Three pixels a row, padded to 16 bytes, two rows: the frame keeps the
    /// real row length as its stride and every byte where it was.
    #[test]
    fn a_padded_bgra_image_is_copied_with_its_real_row_length_as_the_stride() {
        let bytes: Vec<u8> = (0..32).collect();

        let frame = bgra_frame(&image(3, 2, 16, NATIVE), &bytes, 42).expect("a frame");

        assert_eq!((frame.width, frame.height), (3, 2));
        assert_eq!(frame.stride, 16);
        assert_eq!(frame.format, PixelFormat::Bgra8);
        assert_eq!(frame.timestamp_us, 42);
        assert_eq!(frame.data, bytes);
    }

    /// Each other order becomes B, G, R, A — and the padding is left alone.
    #[test]
    fn every_other_byte_order_is_converted_to_bgra_in_place() {
        // One pixel a row, padded to 8 bytes; the padding is 0xEE.
        let row = |pixel: [u8; 4]| {
            [
                pixel[0], pixel[1], pixel[2], pixel[3], 0xEE, 0xEE, 0xEE, 0xEE,
            ]
        };
        let convert = |info: u32, pixel: [u8; 4]| {
            let bytes: Vec<u8> = row(pixel)
                .iter()
                .chain(row(pixel).iter())
                .copied()
                .collect();

            bgra_frame(&image(1, 2, 8, info), &bytes, 0)
                .expect("a frame")
                .data
        };

        // Blue 1, green 2, red 3, alpha 4, in each layout's own order.
        let expected = [
            1, 2, 3, 4, 0xEE, 0xEE, 0xEE, 0xEE, 1, 2, 3, 4, 0xEE, 0xEE, 0xEE, 0xEE,
        ];

        assert_eq!(convert(NATIVE, [1, 2, 3, 4]), expected, "BGRA");
        assert_eq!(
            convert(BYTE_ORDER_32_BIG | ALPHA_FIRST, [4, 3, 2, 1]),
            expected,
            "ARGB"
        );
        assert_eq!(
            convert(BYTE_ORDER_32_BIG | ALPHA_LAST, [3, 2, 1, 4]),
            expected,
            "RGBA"
        );
        assert_eq!(
            convert(BYTE_ORDER_32_LITTLE | ALPHA_LAST, [4, 1, 2, 3]),
            expected,
            "ABGR"
        );
    }

    /// A provider may stop the last row at its last pixel. The frame still
    /// carries `stride × height` bytes, because that is what it promises.
    #[test]
    fn a_last_row_without_its_padding_is_accepted_and_padded() {
        let bytes = vec![7_u8; 16 + 12];

        let frame = bgra_frame(&image(3, 2, 16, NATIVE), &bytes, 0).expect("a frame");

        assert_eq!(frame.data.len(), 32);
        assert_eq!(&frame.data[..28], &bytes[..]);
        assert_eq!(&frame.data[28..], &[0, 0, 0, 0]);
    }

    /// An encoder handed fewer bytes than the geometry says reads past the
    /// end of the buffer. Every such image is refused.
    #[test]
    fn an_image_whose_bytes_do_not_cover_its_size_is_refused() {
        // One byte short of the last pixel.
        assert!(bgra_frame(&image(3, 2, 16, NATIVE), &[0; 27], 0).is_err());
        // Rows shorter than three pixels.
        assert!(bgra_frame(&image(3, 2, 11, NATIVE), &[0; 32], 0).is_err());
        // No pixels at all.
        assert!(bgra_frame(&image(0, 2, 16, NATIVE), &[0; 32], 0).is_err());
        assert!(bgra_frame(&image(3, 0, 16, NATIVE), &[0; 32], 0).is_err());

        let error = bgra_frame(&image(3, 2, 16, NATIVE), &[0; 4], 0).unwrap_err();
        assert!(matches!(error, PlatformError::Os { .. }));
        assert!(error.to_string().contains("reading a captured frame"));
    }

    // ------------------------------------------- pixels and points (Retina)

    /// The whole chain a click takes on a hosted Mac, on a Retina display
    /// (scale 2) and a non-Retina one (scale 1): the frame is the display's
    /// pixels; the viewer sends a fraction of that picture; input turns the
    /// fraction into points on the display `monitors()` says is active.
    ///
    /// A click at a fraction of the picture lands at the same fraction of the
    /// display — within the point the clicked pixel is part of — and the
    /// scale never enters the arithmetic.
    #[test]
    fn a_click_at_a_fraction_of_the_picture_lands_at_that_fraction_of_the_display() {
        for display in [BUILTIN, EXTERNAL] {
            let fake = Fake::new(true);
            let mut capture = MacCapture::with_source(fake);
            capture.start(display, quick()).unwrap();

            let frame = wait_for_frame(&mut capture);
            let layout = capture.monitors().unwrap();
            let monitor = layout.active().expect("an active display").clone();

            assert_eq!(monitor.id, display, "input follows the captured display");

            // The picture is the display, at its own pixels: the scale the
            // layout reports is the picture's width over the display's.
            assert_eq!(
                scale_from_pixels(u64::from(frame.width), f64::from(monitor.width)),
                monitor.scale
            );
            assert!(
                ((f64::from(frame.width) / f64::from(frame.height))
                    - (f64::from(monitor.width) / f64::from(monitor.height)))
                .abs()
                    < 0.01,
                "the picture has the display's shape"
            );

            for (px, py) in [
                (0, 0),
                (frame.width / 4, frame.height / 3),
                (frame.width / 2, frame.height / 2),
                (frame.width - 1, frame.height - 1),
            ] {
                // The centre of the clicked pixel, as a fraction of the
                // picture — which is what the browser sends, whatever size it
                // drew the picture at.
                let position = PointerPosition {
                    x: (f64::from(px) + 0.5) / f64::from(frame.width),
                    y: (f64::from(py) + 0.5) / f64::from(frame.height),
                };

                let (x, y) = to_global_point(&monitor, position).expect("a point");

                // The same fraction of the display.
                let span_x = f64::from(monitor.width) - 1.0;
                let span_y = f64::from(monitor.height) - 1.0;
                assert!((((x - f64::from(monitor.x)) / span_x) - position.x).abs() < 1e-12);
                assert!((((y - f64::from(monitor.y)) / span_y) - position.y).abs() < 1e-12);

                // And the point that pixel is part of, give or take one: the
                // pixel is at `px / scale` points into the display.
                let expected_x = f64::from(monitor.x) + f64::from(px) / monitor.scale;
                let expected_y = f64::from(monitor.y) + f64::from(py) / monitor.scale;
                assert!(
                    (x - expected_x).abs() <= 1.0,
                    "{display}: pixel {px} became point {x}, not about {expected_x}"
                );
                assert!(
                    (y - expected_y).abs() <= 1.0,
                    "{display}: pixel {py} became point {y}, not about {expected_y}"
                );

                // Never off the display — which is where it would go if the
                // pixel were taken for a point, or the scale applied twice.
                assert!(x >= f64::from(monitor.x) && x <= f64::from(monitor.x) + span_x);
                assert!(y >= f64::from(monitor.y) && y <= f64::from(monitor.y) + span_y);
            }
        }
    }

    /// The mistake the rule above exists to prevent, written out: taking the
    /// Retina picture's pixel for a point puts the middle of the built-in
    /// display on the external one.
    #[test]
    fn a_retina_pixel_is_not_a_point() {
        let layout = desk();
        let builtin = layout.find(BUILTIN).unwrap();
        let frame_width = 3024.0;

        let (x, _) = to_global_point(builtin, PointerPosition { x: 0.5, y: 0.5 }).unwrap();
        assert!((x - 755.5).abs() < 1e-9, "half of 1512 points");

        let mistaken = frame_width * 0.5;
        assert!(
            mistaken >= f64::from(layout.find(EXTERNAL).unwrap().x),
            "which is the external display"
        );
    }

    // ---------------------------------------- the provider, against a fake

    #[test]
    fn without_screen_recording_capture_is_refused_with_a_reason_a_person_can_act_on() {
        let fake = Fake::new(false);
        let mut capture = MacCapture::with_source(fake.clone());

        let error = capture.start(BUILTIN, quick()).unwrap_err();

        assert!(matches!(error, PlatformError::PermissionDenied(_)));
        let message = error.to_string();
        assert!(message.contains("Screen Recording"));
        assert!(message.contains("System Settings"));
        assert!(!capture.is_running());
        assert!(capture.next_frame().unwrap().is_none());
        assert!(
            fake.world().grabs.is_empty(),
            "nothing was captured — not even wallpaper"
        );
    }

    #[test]
    fn starting_an_unknown_display_captures_the_main_one_and_input_follows_it() {
        let fake = Fake::new(true);
        let mut capture = MacCapture::with_source(fake.clone());

        capture.start(1, quick()).unwrap();

        assert!(capture.is_running());
        assert_eq!(capture.monitors().unwrap().active_monitor_id, BUILTIN);
        wait_for_frame(&mut capture);
        assert_eq!(fake.world().grabs.last().unwrap().0, BUILTIN);
    }

    #[test]
    fn starting_another_display_switches_to_it() {
        let fake = Fake::new(true);
        let mut capture = MacCapture::with_source(fake.clone());

        capture.start(BUILTIN, quick()).unwrap();
        capture.start(EXTERNAL, quick()).unwrap();

        assert_eq!(capture.monitors().unwrap().active_monitor_id, EXTERNAL);
        let frame = wait_for_frame(&mut capture);
        assert_eq!((frame.width, frame.height), (1920, 1080));
        assert_eq!(fake.world().grabs.last().unwrap().0, EXTERNAL);
    }

    /// The trait's contract: non-blocking, and `None` between frames.
    #[test]
    fn frames_are_paced_to_the_profile() {
        let fake = Fake::new(true);
        let mut capture = MacCapture::with_source(fake.clone());
        capture.start(BUILTIN, slow()).unwrap();

        let frame = capture
            .next_frame()
            .unwrap()
            .expect("the first is due at once");
        assert_eq!((frame.width, frame.height), (3024, 1964));
        assert_eq!(frame.format, PixelFormat::Bgra8);

        assert!(capture.next_frame().unwrap().is_none());
        assert!(capture.next_frame().unwrap().is_none());
        assert_eq!(
            fake.world().grabs.len(),
            1,
            "no image is taken between frames"
        );

        // Asking for the same display again is a profile change, not a
        // restart that would make another frame due at once.
        capture.start(BUILTIN, slow()).unwrap();
        assert!(capture.next_frame().unwrap().is_none());

        // A faster profile applies from the last frame.
        capture.reconfigure(quick()).unwrap();
        assert_eq!(capture.profile().max_fps, 1000);
        wait_for_frame(&mut capture);
    }

    #[test]
    fn timestamps_count_from_the_start_of_the_capture_and_never_go_back() {
        let fake = Fake::new(true);
        let mut capture = MacCapture::with_source(fake.clone());
        capture.start(BUILTIN, quick()).unwrap();

        let stamps: Vec<u64> = (0..3)
            .map(|_| wait_for_frame(&mut capture).timestamp_us)
            .collect();

        assert!(stamps.windows(2).all(|pair| pair[1] >= pair[0]));
        assert!(
            stamps[0] < 10_000_000,
            "microseconds since the start, not since 1970"
        );
    }

    /// Switched off in System Settings mid-session: the capture ends with the
    /// same refusal, rather than carrying on as wallpaper.
    #[test]
    fn screen_recording_switched_off_mid_session_ends_the_capture() {
        let fake = Fake::new(true);
        let mut capture = MacCapture::with_source(fake.clone());
        capture.start(BUILTIN, quick()).unwrap();
        wait_for_frame(&mut capture);
        let grabbed = fake.world().grabs.len();

        fake.world().may_record = false;

        let error = loop {
            match capture.next_frame() {
                Ok(_) => std::thread::sleep(Duration::from_millis(1)),
                Err(error) => break error,
            }
        };

        assert!(matches!(error, PlatformError::PermissionDenied(_)));
        assert!(!capture.is_running());
        assert_eq!(
            fake.world().grabs.len(),
            grabbed,
            "no image after it was switched off"
        );
        assert!(capture.next_frame().unwrap().is_none());
    }

    /// The external display unplugged mid-session: the capture follows the
    /// main display, and so does input.
    #[test]
    fn a_display_unplugged_mid_session_hands_over_to_the_main_display() {
        let fake = Fake::new(true);
        let mut capture = MacCapture::with_source(fake.clone());
        capture.start(EXTERNAL, quick()).unwrap();
        wait_for_frame(&mut capture);

        let before = {
            let mut world = fake.world();
            world.images = false;
            world.layout =
                layout_from(&[reading(BUILTIN, true, 0.0, 1512.0, 982.0, 3024)]).unwrap();
            world.grabs.len()
        };

        // The next grab is of the display that has gone: no picture, and the
        // capture moves on.
        while fake.world().grabs.len() == before {
            assert!(capture.next_frame().unwrap().is_none());
            std::thread::sleep(Duration::from_millis(1));
        }
        assert_eq!(fake.world().grabs.last().unwrap().0, EXTERNAL);

        assert!(capture.is_running());
        assert_eq!(capture.monitors().unwrap().active_monitor_id, BUILTIN);

        fake.world().images = true;
        let frame = wait_for_frame(&mut capture);
        assert_eq!((frame.width, frame.height), (3024, 1964));
        assert_eq!(fake.world().grabs.last().unwrap().0, BUILTIN);
    }

    /// A display that is still connected but has no picture (asleep) is not
    /// a reason to switch displays or to fail.
    #[test]
    fn a_display_with_no_picture_this_time_is_waited_for() {
        let fake = Fake::new(true);
        let mut capture = MacCapture::with_source(fake.clone());
        capture.start(EXTERNAL, quick()).unwrap();
        fake.world().images = false;

        for _ in 0..5 {
            assert!(capture.next_frame().unwrap().is_none());
            std::thread::sleep(Duration::from_millis(2));
        }

        assert!(capture.is_running());
        assert_eq!(capture.monitors().unwrap().active_monitor_id, EXTERNAL);
        assert!(fake
            .world()
            .grabs
            .iter()
            .all(|(display, _)| *display == EXTERNAL));
    }

    #[test]
    fn stopping_is_idempotent_and_a_stopped_capture_gives_nothing() {
        let fake = Fake::new(true);
        let mut capture = MacCapture::with_source(fake.clone());

        assert!(capture.stop().is_ok());
        capture.start(BUILTIN, quick()).unwrap();
        assert!(capture.stop().is_ok());
        assert!(capture.stop().is_ok());

        assert!(!capture.is_running());
        assert!(capture.next_frame().unwrap().is_none());
        assert_eq!(capture.monitors().unwrap().active_monitor_id, BUILTIN);
        assert!(fake.world().grabs.is_empty());
    }

    // ------------------------------------------------------- the real one

    /// Off macOS the real displays refuse, as "not available on this
    /// platform" — not as a permission nobody could grant.
    #[cfg(not(target_os = "macos"))]
    #[test]
    fn off_macos_the_real_capture_is_unsupported() {
        let mut capture = MacCapture::new();

        assert!(matches!(
            capture.start(1, CaptureProfile::adaptive()),
            Err(PlatformError::Unsupported(_))
        ));
        assert!(capture.monitors().is_err());
        assert!(capture.next_frame().unwrap().is_none());
        assert!(capture.stop().is_ok());
        assert!(!capture.is_running());
    }

    /// On a Mac — the macOS CI runner — capture either starts and produces a
    /// BGRA frame with a real stride, or refuses because Screen Recording is
    /// not granted to the test process. Nothing else.
    #[cfg(target_os = "macos")]
    #[test]
    fn on_a_mac_capture_starts_or_says_screen_recording_is_needed() {
        let mut capture = MacCapture::new();

        match capture.start(0, CaptureProfile::adaptive()) {
            Ok(()) => {
                let layout = capture.monitors().expect("a layout");
                let monitor = layout.active().expect("an active display").clone();

                if let Some(frame) = capture.next_frame().expect("a frame or nothing") {
                    assert_eq!(frame.format, PixelFormat::Bgra8);
                    assert!(frame.stride >= frame.width as usize * 4);
                    assert!(frame.data.len() >= frame.stride * frame.height as usize);

                    let picture = f64::from(frame.width) / f64::from(frame.height);
                    let display = f64::from(monitor.width) / f64::from(monitor.height);
                    assert!(
                        (picture - display).abs() / display < 0.02,
                        "the picture is the display"
                    );
                }

                capture.stop().unwrap();
            }
            Err(PlatformError::PermissionDenied(message)) => {
                assert!(message.contains("Screen Recording"));
            }
            Err(other) => panic!("capture neither started nor asked for Screen Recording: {other}"),
        }
    }
}
