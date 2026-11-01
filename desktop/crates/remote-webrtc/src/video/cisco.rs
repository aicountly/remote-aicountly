//! Cisco's OpenH264 binary: which one this computer needs, fetched from
//! Cisco, checked, kept, and loaded — and switched off again when the person
//! at the computer says so.
//!
//! # Why Cisco's binary
//!
//! H.264 is covered by patents licensed through the AVC/H.264 patent pool
//! (Via Licensing Alliance, formerly MPEG LA). Cisco pays the pool's royalties
//! for **its own prebuilt OpenH264 binary** — and only for that binary, and
//! only when the software that uses it meets four conditions, which are part
//! of the licence text Cisco publishes beside it
//! (`desktop/legal/OPENH264_BINARY_LICENSE.txt`). An OpenH264 compiled from
//! source and shipped inside our installer is not covered, so the application
//! ships none: `openh264` is built with `libloading` only, and this module
//! obtains the codec on the person's own computer. docs/legal/OPENH264.md has
//! the whole arrangement; here is where each condition is met in this file.
//!
//! 1. **Separately downloaded to the end user's device.** [`CodecManager`]
//!    downloads the binary from Cisco's own server, [`CISCO_BASE_URLS`], onto
//!    this computer, for this user ([`CodecOptions::directory`]). Nothing in
//!    the installer contains it, and AICOUNTLY never hosts or forwards it.
//! 2. **The end user can enable, disable and re-enable it.**
//!    [`CodecManager::set_enabled`]: off stops every encoder using it at its
//!    next frame and deletes the file; on downloads it again. The switch is in
//!    App settings, saved as `openh264Enabled` in the configuration.
//! 3. **"OpenH264 Video Codec provided by Cisco Systems, Inc." where it is
//!    controlled** — [`ATTRIBUTION`], which the switch is labelled with.
//! 4. **The licence text where licensing information is shown** — the About
//!    panel's "Third-party licences", which shows the file above in full.
//!
//! # What makes a file safe to load
//!
//! A library loaded into this process can do anything this process can, and
//! this process injects input into somebody's desktop. So a file is loaded
//! only when, at the moment it is loaded:
//!
//! * its SHA-256 is the one pinned below for this platform's OpenH264 2.6.0
//!   ([`Blob::sha256`]) — checked after every download *and* before every
//!   load, including the load each encoder makes, because a file on disk can
//!   change between the two;
//! * the `openh264` crate's own list of Cisco builds knows it —
//!   `OpenH264API::from_blob_path` checks that before it opens the file, and
//!   nothing here ever calls `from_blob_path_unchecked`;
//! * it lives in a folder only this person can write to
//!   (`remote_core::codec_directory`, and on Unix the folder is made `0700`
//!   before anything is written), written there atomically — a temporary
//!   file, flushed, then renamed — so a half-written library can never sit
//!   under the real name.
//!
//! Cisco serves the binaries over plain HTTP (see [`CISCO_BASE_URLS`] for why
//! that is acceptable).
//!
//! # The states, as the window shows them
//!
//! ```text
//!   Disabled ──switch on──▶ NotInstalled ──▶ Downloading ──▶ Ready
//!      ▲                        ▲  ▲              │
//!      └──────switch off────────┼──┼── (from any) │ failed
//!                               │  └─ remove ─────┤
//!                               └──── Retry ── Failed ◀┘
//! ```
//!
//! `Unsupported` is a computer Cisco publishes no binary for: nothing ships
//! for it, and there is nothing to switch.

use std::future::Future;
use std::io::{Read, Write};
use std::path::{Path, PathBuf};
use std::pin::Pin;
use std::sync::{Arc, Mutex, Weak};
use std::time::{Duration, Instant};

use openh264::OpenH264API;
use serde::Serialize;
use sha2::{Digest, Sha256};

use super::codec::{Codec, CodecObserver, CodecSource};

/// The sentence Cisco's licence requires wherever the person can switch the
/// codec on and off (condition 3). Exactly this, with its full stop.
pub const ATTRIBUTION: &str = "OpenH264 Video Codec provided by Cisco Systems, Inc.";

/// The OpenH264 release this build loads. Its binaries' hashes are pinned
/// below, and are among the ones `openh264-sys2` 0.9.9 knows; moving to a
/// later release means new hashes here and an `openh264` that knows them.
pub const OPENH264_VERSION: &str = "2.6.0";

/// Where Cisco publishes its binaries: HTTPS first, then plain HTTP.
///
/// Cisco serves `ciscobinary.openh264.org` over HTTP — it is what Firefox has
/// always fetched OpenH264 from — and its HTTPS has not always presented a
/// certificate for that name. Falling back to HTTP is safe because the
/// transport is not what makes the file trustworthy: the SHA-256 of what
/// arrives is compared with the hash pinned in this binary, and then with the
/// `openh264` crate's own list, before it is written under its real name or
/// loaded. Somebody on the path can stop the download, or slow it, but cannot
/// change what is loaded. What they can learn is that this computer fetched a
/// public codec, which the HTTPS attempt would not hide from them either.
pub const CISCO_BASE_URLS: [&str; 2] = [
    "https://ciscobinary.openh264.org",
    "http://ciscobinary.openh264.org",
];

/// The largest download accepted. Cisco's `.bz2` files are well under a
/// megabyte; anything this size is not one of them, and is not read further.
pub const MAX_DOWNLOAD_BYTES: u64 = 4 * 1024 * 1024;

/// The largest binary accepted once decompressed. The libraries are one to
/// two megabytes; this stops a small file that decompresses into a very large
/// one long before it fills a disk.
pub const MAX_BINARY_BYTES: u64 = 10 * 1024 * 1024;

/// How long to wait for Cisco's server to accept a connection.
const CONNECT_TIMEOUT: Duration = Duration::from_secs(15);

/// How long a transfer may sit with nothing arriving.
const READ_TIMEOUT: Duration = Duration::from_secs(30);

/// The longest one request may take end to end — a slow link, not a stalled
/// one, which [`READ_TIMEOUT`] catches first.
const REQUEST_TIMEOUT: Duration = Duration::from_secs(3 * 60);

/// How many times every address is tried before the download is reported as
/// failed, and how long to wait before the second round (doubling after).
const ATTEMPTS: u32 = 3;
const FIRST_RETRY: Duration = Duration::from_secs(5);

/// How soon after a failed or missing download a session that needs the
/// codec may start another one by itself.
const RETRY_WHEN_WANTED: Duration = Duration::from_secs(60);

/// The window is told about progress every this many bytes.
const PROGRESS_STEP: u64 = 64 * 1024;

/// Deleting the file is tried this many times, this far apart. On Windows a
/// DLL cannot be deleted while it is loaded, and an encoder lets go of it at
/// its next frame — a still screen sends its next frame when something moves.
const DELETE_ATTEMPTS: u32 = 10;
const DELETE_PAUSE: Duration = Duration::from_millis(300);

/// One of Cisco's binaries.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Blob {
    /// Its file name on Cisco's server (with `.bz2`) and on this computer.
    pub file: &'static str,
    /// Its SHA-256, lower-case hex, once decompressed.
    pub sha256: &'static str,
    /// The OpenH264 release it is.
    pub version: &'static str,
    /// Whether a build of this application ships for this platform. The
    /// Linux binary is for a developer's experiments only.
    pub shipped: bool,
}

/// Windows on x64 — the Windows build of this application.
const WINDOWS_X64: Blob = Blob {
    file: "openh264-2.6.0-win64.dll",
    sha256: "2076cb5675ec6c1a4c70e7a2a322552f547b6eeed649d6dfcd9e02a543b24691",
    version: OPENH264_VERSION,
    shipped: true,
};

/// A Mac with Apple silicon.
const MACOS_ARM64: Blob = Blob {
    file: "libopenh264-2.6.0-mac-arm64.dylib",
    sha256: "052e98bfcf7a9167d22f3bbb3f5988ef79065591f36af8b52924b22b13624551",
    version: OPENH264_VERSION,
    shipped: true,
};

/// An Intel Mac.
const MACOS_X64: Blob = Blob {
    file: "libopenh264-2.6.0-mac-x64.dylib",
    sha256: "e3dc8bc01fe69363f61fd3c02fd27798537a585eadd38cd808f303d1ee505a19",
    version: OPENH264_VERSION,
    shipped: true,
};

/// A developer's Linux workstation. Nothing ships for Linux.
///
/// The `.8.so` build, which is what Cisco publishes for 2.6.0. The crate's
/// list also names `.7.so` files for 2.6.0, but every one of them — Linux and
/// Android, 32- and 64-bit, x86 and ARM — under the same hash, which cannot be
/// the hash of five different libraries; whatever those entries are, they are
/// not a binary worth fetching.
const LINUX_X64: Blob = Blob {
    file: "libopenh264-2.6.0-linux64.8.so",
    sha256: "2f0cde7c6a6abcf5cae76942894ea42897fa677bce4ed6c91a24dd1b041d5f04",
    version: OPENH264_VERSION,
    shipped: false,
};

/// Every binary this module knows, for tidying up after an earlier version.
const KNOWN: [Blob; 4] = [WINDOWS_X64, MACOS_ARM64, MACOS_X64, LINUX_X64];

/// The binary for an operating system and architecture, in Rust's own words
/// (`std::env::consts::OS` and `ARCH`). `None` where Cisco publishes nothing
/// this application can use.
#[must_use]
pub fn blob_for(os: &str, arch: &str) -> Option<Blob> {
    match (os, arch) {
        ("windows", "x86_64") => Some(WINDOWS_X64),
        ("macos", "aarch64") => Some(MACOS_ARM64),
        ("macos", "x86_64") => Some(MACOS_X64),
        ("linux", "x86_64") => Some(LINUX_X64),
        _ => None,
    }
}

/// The binary for the computer this is running on.
#[must_use]
pub fn this_platform() -> Option<Blob> {
    blob_for(std::env::consts::OS, std::env::consts::ARCH)
}

impl Blob {
    /// Where to download it from, in the order to try: `<base>/<file>.bz2`
    /// for each base.
    #[must_use]
    pub fn urls(&self, bases: &[String]) -> Vec<String> {
        bases
            .iter()
            .map(|base| format!("{}/{}.bz2", base.trim_end_matches('/'), self.file))
            .collect()
    }
}

/// What the window shows about the codec.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(
    tag = "state",
    rename_all = "snake_case",
    rename_all_fields = "camelCase"
)]
pub enum CodecStatus {
    /// The person switched it off. Nothing is downloaded or loaded, and no
    /// file is kept.
    Disabled,
    /// Switched on, and not on this computer — yet, or since it was removed.
    NotInstalled,
    /// Being fetched from Cisco.
    Downloading {
        /// Bytes received so far, of the compressed file.
        received_bytes: u64,
        /// Its size, when the server said.
        total_bytes: Option<u64>,
    },
    /// Verified, loaded, and what every encoder uses.
    Ready {
        /// The OpenH264 release.
        version: String,
        /// Where it is on this computer.
        path: String,
        /// Its size.
        size_bytes: u64,
    },
    /// The last attempt did not end with a codec. A sentence for the window.
    Failed {
        /// Why, in words a person can act on.
        reason: String,
    },
    /// Cisco publishes no binary for this computer.
    Unsupported {
        /// The operating system and architecture, as Rust names them.
        platform: String,
    },
}

impl CodecStatus {
    /// Whether an encoder can be built.
    #[must_use]
    pub fn is_ready(&self) -> bool {
        matches!(self, Self::Ready { .. })
    }
}

/// Why the codec could not be obtained. Each says what happened in words the
/// window can show as they are.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum CodecError {
    /// Nothing is published for this computer.
    #[error("Cisco does not publish OpenH264 for this computer ({0}).")]
    Unsupported(String),
    /// No per-user folder to keep it in.
    #[error("This computer has no folder for AICOUNTLY Remote to keep the codec in.")]
    NoDirectory,
    /// The server could not be reached, or the transfer broke off.
    #[error("Cisco's server could not be reached: {0}.")]
    Transport(String),
    /// The server answered with something other than the file.
    #[error("Cisco's server answered {0}.")]
    Status(u16),
    /// More arrived than an OpenH264 download can be.
    #[error("The download was larger than OpenH264 is, so it was not kept.")]
    TooLarge,
    /// Not a bzip2 file.
    #[error("The download was not a codec Cisco publishes ({0}), so it was not kept.")]
    Corrupt(String),
    /// Not the binary pinned for this computer.
    #[error("The download did not match the OpenH264 {OPENH264_VERSION} Cisco publishes for this computer, so it was not kept.")]
    Mismatch,
    /// Writing or reading the file failed.
    #[error("The codec could not be saved: {0}.")]
    Io(String),
    /// The folder is not this account's alone.
    #[error("The codec folder {0} could not be made private to this account, so nothing was saved in it.")]
    NotPrivate(String),
    /// The operating system would not load it.
    #[error("The codec could not be loaded: {0}.")]
    NotLoadable(String),
    /// Switched off or removed while it was being fetched.
    #[error("The download was stopped.")]
    Cancelled,
}

/// Opens a verified file as an OpenH264 API. A function pointer so the tests
/// can stand OpenH264 compiled from source in for Cisco's library, which they
/// cannot download; the application only ever uses [`load_cisco`].
pub(crate) type LoadFn = fn(&Path) -> Result<OpenH264API, String>;

/// Cisco's library, through the `openh264` crate's checked loader.
///
/// `from_blob_path` reads the file, compares its SHA-256 with the crate's list
/// of Cisco builds, and only then opens it. Never `from_blob_path_unchecked`:
/// that opens whatever is at the path, and a downloaded file is exactly the
/// kind of thing that must not be opened unchecked.
fn load_cisco(path: &Path) -> Result<OpenH264API, String> {
    OpenH264API::from_blob_path(path).map_err(|error| error.to_string())
}

/// Cisco's binary, verified and loaded: what [`CodecSource`] hands encoders.
///
/// Holds one OpenH264 API open for as long as it exists, so the library stays
/// mapped and each encoder's own load of it is the operating system counting
/// one more user of a library it already has, not a second copy.
pub(crate) struct LoadedCodec {
    blob: Blob,
    path: PathBuf,
    size: u64,
    load: LoadFn,
    _resident: OpenH264API,
}

impl LoadedCodec {
    /// Verify `path` and load it.
    fn open(blob: Blob, path: &Path, load: LoadFn) -> Result<Self, CodecError> {
        let size = verify_file(path, &blob)?;
        let resident = load(path).map_err(CodecError::NotLoadable)?;

        Ok(Self {
            blob,
            path: path.to_owned(),
            size,
            load,
            _resident: resident,
        })
    }

    /// An API for one more encoder — after checking the file again.
    pub(crate) fn api(&self) -> Result<OpenH264API, String> {
        verify_file(&self.path, &self.blob).map_err(|error| error.to_string())?;

        (self.load)(&self.path)
    }

    fn status(&self) -> CodecStatus {
        CodecStatus::Ready {
            version: self.blob.version.to_owned(),
            path: self.path.display().to_string(),
            size_bytes: self.size,
        }
    }
}

/// The SHA-256 of `bytes`, lower-case hex.
#[must_use]
pub fn sha256_hex(bytes: &[u8]) -> String {
    hex::encode(Sha256::digest(bytes))
}

/// Whether `bytes` are `blob`.
pub(crate) fn verify_bytes(bytes: &[u8], blob: &Blob) -> Result<(), CodecError> {
    if sha256_hex(bytes).eq_ignore_ascii_case(blob.sha256) {
        Ok(())
    } else {
        Err(CodecError::Mismatch)
    }
}

/// Whether the file at `path` is `blob`, and its size if so.
pub(crate) fn verify_file(path: &Path, blob: &Blob) -> Result<u64, CodecError> {
    let size = std::fs::metadata(path)
        .map_err(|error| CodecError::Io(error.to_string()))?
        .len();

    // Not even read if it cannot be the binary.
    if size > MAX_BINARY_BYTES {
        return Err(CodecError::Mismatch);
    }

    let bytes = std::fs::read(path).map_err(|error| CodecError::Io(error.to_string()))?;
    verify_bytes(&bytes, blob)?;

    Ok(bytes.len() as u64)
}

/// Decompress a `.bz2` download, refusing anything that grows past `limit`.
pub(crate) fn decompress(compressed: &[u8], limit: u64) -> Result<Vec<u8>, CodecError> {
    let mut binary = Vec::new();

    bzip2::read::BzDecoder::new(compressed)
        .take(limit.saturating_add(1))
        .read_to_end(&mut binary)
        .map_err(|error| CodecError::Corrupt(error.to_string()))?;

    if binary.len() as u64 > limit {
        return Err(CodecError::TooLarge);
    }

    if binary.is_empty() {
        return Err(CodecError::Corrupt("it was empty".into()));
    }

    Ok(binary)
}

/// Create the codec folder, and make it this account's alone where the
/// operating system lets a program say so.
///
/// On Unix that is mode `0700`, which only the folder's owner can set — so a
/// folder somebody else created where ours should be is refused rather than
/// written into. On Windows the folder is under `%LOCALAPPDATA%`, whose ACL is
/// already the person's own (and SYSTEM's and the administrators'), and a new
/// folder inherits it.
pub(crate) fn prepare_directory(directory: &Path) -> Result<(), CodecError> {
    std::fs::create_dir_all(directory).map_err(|error| CodecError::Io(error.to_string()))?;

    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;

        std::fs::set_permissions(directory, std::fs::Permissions::from_mode(0o700))
            .map_err(|_| CodecError::NotPrivate(directory.display().to_string()))?;
    }

    Ok(())
}

/// Write `bytes` to `directory/file` so that the name only ever holds a whole
/// file: written to a temporary name beside it, flushed to disk, and renamed
/// over it. A power cut or a full disk leaves the old file or none, never half
/// a library.
pub(crate) fn write_atomically(
    directory: &Path,
    file: &str,
    bytes: &[u8],
) -> Result<PathBuf, CodecError> {
    prepare_directory(directory)?;

    let target = directory.join(file);
    let temporary = directory.join(format!("{file}.{}.part", unique_suffix()));

    let written = (|| -> std::io::Result<()> {
        let mut out = open_new(&temporary)?;
        out.write_all(bytes)?;
        out.sync_all()?;
        drop(out);

        std::fs::rename(&temporary, &target)
    })();

    if let Err(error) = written {
        let _ = std::fs::remove_file(&temporary);

        return Err(CodecError::Io(error.to_string()));
    }

    Ok(target)
}

/// A new file, never an existing one — so nothing that was already at the
/// temporary name, a link included, is written through. Owner-only on Unix.
fn open_new(path: &Path) -> std::io::Result<std::fs::File> {
    let mut options = std::fs::OpenOptions::new();
    options.write(true).create_new(true);

    #[cfg(unix)]
    {
        use std::os::unix::fs::OpenOptionsExt;

        options.mode(0o700);
    }

    options.open(path)
}

fn unique_suffix() -> String {
    let nanos = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|elapsed| elapsed.as_nanos())
        .unwrap_or_default();

    format!("{}-{nanos}", std::process::id())
}

/// Whether `name` is a file this module writes: one of Cisco's binaries, or a
/// temporary file on its way to being one. Nothing else in the folder is ever
/// touched.
fn is_ours(name: &str, blob: &Blob) -> bool {
    KNOWN.iter().chain(std::iter::once(blob)).any(|known| {
        name == known.file || (name.starts_with(known.file) && name.ends_with(".part"))
    })
}

/// Delete what an earlier run left behind: temporary files, and binaries
/// other than `keep` — an earlier OpenH264 release, or everything when the
/// codec is switched off.
pub(crate) fn tidy(directory: &Path, blob: &Blob, keep: Option<&str>) {
    let Ok(entries) = std::fs::read_dir(directory) else {
        return;
    };

    for entry in entries.flatten() {
        let name = entry.file_name();
        let Some(name) = name.to_str() else {
            continue;
        };

        if is_ours(name, blob) && Some(name) != keep {
            let _ = std::fs::remove_file(entry.path());
        }
    }
}

/// Delete a file, trying again for a few seconds if the operating system says
/// it is in use. True once it is gone.
async fn delete_patiently(path: &Path) -> bool {
    for _ in 0..DELETE_ATTEMPTS {
        match std::fs::remove_file(path) {
            Ok(()) => return true,
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => return true,
            Err(_) => tokio::time::sleep(DELETE_PAUSE).await,
        }
    }

    false
}

/// What happened to a request, without the URL or whatever a proxy put in
/// its error page: the reason is shown in the window as it is.
fn describe(error: &reqwest::Error) -> String {
    if error.is_timeout() {
        "it did not answer in time".into()
    } else if error.is_connect() {
        "it could not be connected to — check this computer's internet connection or proxy".into()
    } else {
        "the download broke off".into()
    }
}

/// Runs a future somewhere — the application's async runtime. The manager
/// starts a download by itself when a session finds the codec missing, and
/// when the person switches the codec on; it is given somewhere to run it
/// rather than assuming which runtime it is inside.
pub type Spawner = Arc<dyn Fn(Pin<Box<dyn Future<Output = ()> + Send + 'static>>) + Send + Sync>;

/// How a [`CodecManager`] is set up.
#[derive(Clone)]
pub struct CodecOptions {
    /// Where the binary is kept: this person's own folder
    /// (`remote_core::codec_directory`). `None` on a computer that has none,
    /// where every attempt fails with a reason.
    pub directory: Option<PathBuf>,
    /// Where it is downloaded from, in order — [`CISCO_BASE_URLS`].
    pub base_urls: Vec<String>,
    /// The `User-Agent` sent, as every other request the application makes.
    pub user_agent: String,
    /// Where background downloads run. Without one, only [`CodecManager::install`]
    /// and [`CodecManager::start`] download, when awaited.
    pub spawner: Option<Spawner>,
    attempts: u32,
    first_retry: Duration,
    max_download_bytes: u64,
    max_binary_bytes: u64,
    blob: Option<Blob>,
    load: LoadFn,
}

impl CodecOptions {
    /// Cisco's binary for this computer, from Cisco, kept in `directory`.
    #[must_use]
    pub fn cisco(directory: Option<PathBuf>, user_agent: impl Into<String>) -> Self {
        Self {
            directory,
            base_urls: CISCO_BASE_URLS
                .iter()
                .map(|base| (*base).to_owned())
                .collect(),
            user_agent: user_agent.into(),
            spawner: None,
            attempts: ATTEMPTS,
            first_retry: FIRST_RETRY,
            max_download_bytes: MAX_DOWNLOAD_BYTES,
            max_binary_bytes: MAX_BINARY_BYTES,
            blob: this_platform(),
            load: load_cisco,
        }
    }

    /// Run background downloads with `spawner`.
    #[must_use]
    pub fn with_spawner(mut self, spawner: Spawner) -> Self {
        self.spawner = Some(spawner);
        self
    }
}

impl std::fmt::Debug for CodecOptions {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("CodecOptions")
            .field("directory", &self.directory)
            .field("base_urls", &self.base_urls)
            .field("blob", &self.blob.map(|blob| blob.file))
            .field("attempts", &self.attempts)
            .finish_non_exhaustive()
    }
}

/// Downloads, verifies, keeps, loads and removes Cisco's OpenH264 binary, and
/// is the one place that knows whether it is there.
///
/// Cheap to clone; every clone is the same manager.
#[derive(Clone)]
pub struct CodecManager {
    inner: Arc<Inner>,
}

type Listener = Arc<dyn Fn(&CodecStatus) + Send + Sync>;

struct Inner {
    options: CodecOptions,
    /// Built once. A client that could not be built is a reason every
    /// download gives, rather than a panic at start.
    http: Result<reqwest::Client, String>,
    source: CodecSource,
    state: Mutex<State>,
    listeners: Mutex<Vec<Listener>>,
    /// This manager, for the downloads it starts by itself.
    me: Weak<Inner>,
}

struct State {
    enabled: bool,
    status: CodecStatus,
    /// Bumped whenever the codec is removed or switched off. A download that
    /// began under an older epoch stops at its next chunk and throws away
    /// what it had.
    epoch: u64,
    /// A download or a load is running. One at a time.
    busy: bool,
    last_attempt: Option<Instant>,
    last_progress: u64,
}

impl CodecManager {
    /// A manager, `enabled` or not as the configuration says. Nothing is read
    /// or downloaded until [`CodecManager::start`].
    #[must_use]
    pub fn new(options: CodecOptions, enabled: bool) -> Self {
        let http = reqwest::Client::builder()
            .connect_timeout(CONNECT_TIMEOUT)
            .read_timeout(READ_TIMEOUT)
            .timeout(REQUEST_TIMEOUT)
            .user_agent(options.user_agent.clone())
            // Followed, a few: the file is checked by its hash wherever it
            // came from, and a CDN in front of Cisco's server may redirect.
            .redirect(reqwest::redirect::Policy::limited(3))
            .build()
            .map_err(|error| error.to_string());

        let status = match options.blob {
            None => unsupported(),
            Some(_) if !enabled => CodecStatus::Disabled,
            Some(_) => CodecStatus::NotInstalled,
        };

        let inner = Arc::new_cyclic(|me| Inner {
            options,
            http,
            source: CodecSource::empty(),
            state: Mutex::new(State {
                enabled,
                status,
                epoch: 0,
                busy: false,
                last_attempt: None,
                last_progress: 0,
            }),
            listeners: Mutex::new(Vec::new()),
            me: me.clone(),
        });

        let observer: Weak<Inner> = Arc::downgrade(&inner);
        let observer: Weak<dyn CodecObserver> = observer;
        inner.source.observe(observer);

        Self { inner }
    }

    /// The slot every encoder takes the codec from.
    #[must_use]
    pub fn source(&self) -> CodecSource {
        self.inner.source.clone()
    }

    /// Where things stand.
    #[must_use]
    pub fn status(&self) -> CodecStatus {
        self.inner.status()
    }

    /// Whether the person has it switched on.
    #[must_use]
    pub fn is_enabled(&self) -> bool {
        self.inner
            .state
            .lock()
            .map(|state| state.enabled)
            .unwrap_or(false)
    }

    /// The binary this computer uses, if Cisco publishes one for it.
    #[must_use]
    pub fn blob(&self) -> Option<Blob> {
        self.inner.options.blob
    }

    /// Be told about every change of status — for the window.
    pub fn on_change(&self, listener: impl Fn(&CodecStatus) + Send + Sync + 'static) {
        if let Ok(mut listeners) = self.inner.listeners.lock() {
            listeners.push(Arc::new(listener));
        }
    }

    /// What the application does shortly after it starts.
    ///
    /// Switched off: delete anything an earlier run left behind, so a codec
    /// the person turned off is not kept either. Switched on: tidy up after an
    /// earlier run, then load the binary that is already here if it still
    /// passes its checks, or download it if not.
    pub async fn start(&self) -> CodecStatus {
        let Some(blob) = self.inner.options.blob else {
            return self.status();
        };

        let Some(directory) = self.inner.options.directory.clone() else {
            if self.is_enabled() {
                self.inner.update(|state| {
                    state.status = CodecStatus::Failed {
                        reason: CodecError::NoDirectory.to_string(),
                    };
                });
            }

            return self.status();
        };

        let keep = self.is_enabled().then_some(blob.file);
        let _ = tokio::task::spawn_blocking(move || tidy(&directory, &blob, keep)).await;

        if self.is_enabled() {
            self.install().await
        } else {
            self.status()
        }
    }

    /// Download it now — or load the copy already here — and return once
    /// that has succeeded or failed. "Retry" and "Download now".
    ///
    /// Does nothing when the codec is switched off, already loaded, or
    /// already being downloaded.
    pub async fn install(&self) -> CodecStatus {
        if let Some(epoch) = self.inner.claim() {
            Arc::clone(&self.inner).run(epoch).await;
        }

        self.status()
    }

    /// [`CodecManager::install`], on the spawner, returning at once — with
    /// `Downloading` already, when there is no copy here to load.
    pub fn install_in_background(&self) -> CodecStatus {
        self.inner.install_in_background();
        self.status()
    }

    /// Stop using the codec and delete it. The setting is left as it is: the
    /// next start, or Retry, downloads it again while it is switched on.
    ///
    /// Every encoder lets go of it at its next frame. A file the operating
    /// system will not delete yet — a DLL an encoder still holds on Windows —
    /// is tried again for a few seconds, and otherwise deleted at the next
    /// start if the codec is off by then, or checked and used again if not.
    pub async fn remove(&self) -> CodecStatus {
        self.inner.update(|state| {
            state.epoch = state.epoch.wrapping_add(1);
            state.status = match self.inner.options.blob {
                None => unsupported(),
                Some(_) if state.enabled => CodecStatus::NotInstalled,
                Some(_) => CodecStatus::Disabled,
            };
            self.inner.source.set(None);
        });

        if let Some(path) = self.inner.blob_path() {
            if !delete_patiently(&path).await {
                tracing::warn!(
                    path = %path.display(),
                    "the OpenH264 codec is still in use and could not be deleted yet; it is deleted at the next start"
                );
            }
        }

        self.status()
    }

    /// The person's switch (licence condition 2). Off stops every encoder
    /// using the codec at its next frame and deletes it; on downloads it again,
    /// in the background.
    pub async fn set_enabled(&self, enabled: bool) -> CodecStatus {
        self.inner.update(|state| {
            state.enabled = enabled;

            if enabled && state.status == CodecStatus::Disabled {
                state.status = CodecStatus::NotInstalled;
            }
        });

        if enabled {
            self.install_in_background()
        } else {
            self.remove().await
        }
    }
}

impl std::fmt::Debug for CodecManager {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("CodecManager")
            .field("status", &self.status())
            .field("options", &self.inner.options)
            .finish()
    }
}

fn unsupported() -> CodecStatus {
    CodecStatus::Unsupported {
        platform: format!("{} {}", std::env::consts::OS, std::env::consts::ARCH),
    }
}

/// Run `work` on the blocking pool: file reads, hashing, decompression and
/// loading a library are all blocking, and the runtime is shared with the
/// window's commands.
async fn blocking<T: Send + 'static>(
    work: impl FnOnce() -> Result<T, CodecError> + Send + 'static,
) -> Result<T, CodecError> {
    tokio::task::spawn_blocking(work)
        .await
        .map_err(|error| CodecError::Io(error.to_string()))?
}

impl Inner {
    fn status(&self) -> CodecStatus {
        self.state
            .lock()
            .map(|state| state.status.clone())
            .unwrap_or_else(|_| CodecStatus::Failed {
                reason: "The codec's state could not be read.".into(),
            })
    }

    fn blob_path(&self) -> Option<PathBuf> {
        Some(
            self.options
                .directory
                .as_ref()?
                .join(self.options.blob?.file),
        )
    }

    /// Change the state, and tell the listeners if the status changed — after
    /// the lock is released, so a listener may ask for the status itself.
    fn update(&self, change: impl FnOnce(&mut State)) -> CodecStatus {
        let (before, after) = {
            let Ok(mut state) = self.state.lock() else {
                return self.status();
            };

            let before = state.status.clone();
            change(&mut state);

            (before, state.status.clone())
        };

        if before != after {
            let listeners = self
                .listeners
                .lock()
                .map(|listeners| listeners.clone())
                .unwrap_or_default();

            for listener in listeners {
                listener(&after);
            }
        }

        after
    }

    fn cancelled(&self, epoch: u64) -> bool {
        self.state
            .lock()
            .map(|state| state.epoch != epoch)
            .unwrap_or(true)
    }

    /// Take the right to download, if a download is due: switched on, not
    /// loaded, nothing already running. The epoch it runs under.
    fn claim(&self) -> Option<u64> {
        self.options.blob?;

        let nothing_here = self.blob_path().is_none_or(|path| !path.exists());
        let mut claimed = None;

        self.update(|state| {
            if !state.enabled || state.busy || state.status.is_ready() {
                return;
            }

            state.busy = true;
            state.last_attempt = Some(Instant::now());
            claimed = Some(state.epoch);

            if nothing_here {
                state.last_progress = 0;
                state.status = CodecStatus::Downloading {
                    received_bytes: 0,
                    total_bytes: None,
                };
            }
        });

        claimed
    }

    fn install_in_background(&self) {
        let Some(me) = self.me.upgrade() else {
            return;
        };

        let Some(spawner) = self.options.spawner.clone() else {
            tracing::debug!("no spawner: the codec is downloaded only when asked for directly");
            return;
        };

        if let Some(epoch) = self.claim() {
            spawner(Box::pin(async move { me.run(epoch).await }));
        }
    }

    /// One claimed attempt, start to finish.
    async fn run(self: Arc<Self>, epoch: u64) {
        let outcome = self.obtain(epoch).await;

        self.finish(epoch, outcome).await;
    }

    /// The copy already here if it passes, otherwise a fresh one from Cisco.
    async fn obtain(&self, epoch: u64) -> Result<LoadedCodec, CodecError> {
        let blob = self
            .options
            .blob
            .ok_or_else(|| CodecError::Unsupported(platform_name()))?;
        let directory = self
            .options
            .directory
            .clone()
            .ok_or(CodecError::NoDirectory)?;
        let path = directory.join(blob.file);
        let load = self.options.load;

        if path.exists() {
            let existing = path.clone();

            match blocking(move || LoadedCodec::open(blob, &existing, load)).await {
                Ok(loaded) => return Ok(loaded),
                Err(error) => {
                    tracing::warn!(%error, "the OpenH264 codec on this computer failed its check; downloading it again");
                    let _ = std::fs::remove_file(&path);
                }
            }
        }

        self.update(|state| {
            if state.epoch == epoch {
                state.last_progress = 0;
                state.status = CodecStatus::Downloading {
                    received_bytes: 0,
                    total_bytes: None,
                };
            }
        });

        let binary = self.download(&blob, epoch).await?;
        let written = blocking(move || write_atomically(&directory, blob.file, &binary)).await?;

        if self.cancelled(epoch) {
            let _ = std::fs::remove_file(&written);

            return Err(CodecError::Cancelled);
        }

        blocking(move || LoadedCodec::open(blob, &written, load)).await
    }

    /// Every address, a few times over, until one gives the binary pinned for
    /// this computer.
    async fn download(&self, blob: &Blob, epoch: u64) -> Result<Vec<u8>, CodecError> {
        let http = self
            .http
            .as_ref()
            .map_err(|error| CodecError::Transport(error.clone()))?;
        let urls = blob.urls(&self.options.base_urls);
        let mut last = CodecError::Transport("there was nowhere to download it from".into());

        for attempt in 0..self.options.attempts.max(1) {
            if attempt > 0 {
                let pause = self
                    .options
                    .first_retry
                    .saturating_mul(1 << (attempt - 1).min(6));
                tokio::time::sleep(pause).await;
            }

            for url in &urls {
                if self.cancelled(epoch) {
                    return Err(CodecError::Cancelled);
                }

                let fetched = match self.fetch(http, url, epoch).await {
                    Ok(compressed) => {
                        let blob = *blob;
                        let limit = self.options.max_binary_bytes;

                        blocking(move || {
                            let binary = decompress(&compressed, limit)?;
                            verify_bytes(&binary, &blob)?;

                            Ok(binary)
                        })
                        .await
                    }
                    Err(error) => Err(error),
                };

                match fetched {
                    Ok(binary) => {
                        tracing::info!(%url, "downloaded the OpenH264 codec from Cisco");

                        return Ok(binary);
                    }
                    Err(CodecError::Cancelled) => return Err(CodecError::Cancelled),
                    Err(error) => {
                        tracing::info!(%url, %error, attempt = attempt + 1, "the OpenH264 codec could not be downloaded from here");
                        last = error;
                    }
                }
            }
        }

        Err(last)
    }

    /// One GET, read to the end, never past the size limit.
    async fn fetch(
        &self,
        http: &reqwest::Client,
        url: &str,
        epoch: u64,
    ) -> Result<Vec<u8>, CodecError> {
        let limit = self.options.max_download_bytes;

        let mut response = http.get(url).send().await.map_err(|error| {
            tracing::debug!(%error, "the request for the codec failed");
            CodecError::Transport(describe(&error))
        })?;

        if !response.status().is_success() {
            return Err(CodecError::Status(response.status().as_u16()));
        }

        let total = response.content_length();
        if total.is_some_and(|total| total > limit) {
            return Err(CodecError::TooLarge);
        }

        self.progress(epoch, 0, total);

        let capacity = usize::try_from(total.unwrap_or(0).min(limit)).unwrap_or(0);
        let mut body = Vec::with_capacity(capacity);

        while let Some(chunk) = response.chunk().await.map_err(|error| {
            tracing::debug!(%error, "the codec's download broke off");
            CodecError::Transport(describe(&error))
        })? {
            if self.cancelled(epoch) {
                return Err(CodecError::Cancelled);
            }

            if (body.len() + chunk.len()) as u64 > limit {
                return Err(CodecError::TooLarge);
            }

            body.extend_from_slice(&chunk);
            self.progress(epoch, body.len() as u64, total);
        }

        Ok(body)
    }

    fn progress(&self, epoch: u64, received: u64, total: Option<u64>) {
        self.update(|state| {
            if state.epoch != epoch || !state.busy {
                return;
            }

            let due = received < state.last_progress
                || received - state.last_progress >= PROGRESS_STEP
                || received == 0
                || total == Some(received);

            if due {
                state.last_progress = received;
                state.status = CodecStatus::Downloading {
                    received_bytes: received,
                    total_bytes: total,
                };
            }
        });
    }

    /// Record how an attempt ended — unless the codec was removed or switched
    /// off while it ran, in which case what it produced is thrown away and,
    /// if the codec has been switched back on since, a fresh attempt starts.
    async fn finish(&self, epoch: u64, outcome: Result<LoadedCodec, CodecError>) {
        let mut stale = None;
        let mut outcome = Some(outcome);

        self.update(|state| {
            state.busy = false;

            if state.epoch != epoch {
                stale = outcome.take();
                return;
            }

            match outcome.take() {
                Some(Ok(loaded)) => {
                    tracing::info!(path = %loaded.path.display(), version = loaded.blob.version, "the OpenH264 codec is ready");
                    state.status = loaded.status();
                    self.source.set(Some(Codec::Cisco(Arc::new(loaded))));
                }
                Some(Err(CodecError::Cancelled)) | None => {}
                Some(Err(error)) => {
                    tracing::warn!(%error, "the OpenH264 codec is not available");
                    state.status = CodecStatus::Failed {
                        reason: error.to_string(),
                    };
                }
            }
        });

        match stale {
            Some(Ok(loaded)) => {
                // Unloaded before it is deleted: Windows will not delete a
                // DLL that is still loaded.
                let path = loaded.path.clone();
                drop(loaded);

                if !delete_patiently(&path).await {
                    tracing::warn!(path = %path.display(), "a codec downloaded after it was switched off could not be deleted yet");
                }

                self.install_in_background();
            }
            Some(Err(_)) => self.install_in_background(),
            None => {}
        }
    }
}

impl CodecObserver for Inner {
    /// A session needs the codec and it is not here. Switched on, nothing
    /// running, and the last attempt a while ago: try again now — which is
    /// what gets an unattended computer that was offline when it started its
    /// picture back without anybody pressing Retry.
    fn wanted(&self) {
        let due = self.state.lock().is_ok_and(|state| {
            state.enabled
                && !state.busy
                && matches!(
                    state.status,
                    CodecStatus::NotInstalled | CodecStatus::Failed { .. }
                )
                && state
                    .last_attempt
                    .is_none_or(|at| at.elapsed() >= RETRY_WHEN_WANTED)
        });

        if due {
            tracing::info!("a session needs the OpenH264 codec; downloading it");
            self.install_in_background();
        }
    }

    fn broken(&self, reason: String) {
        self.update(|state| {
            if state.status.is_ready() {
                state.status = CodecStatus::Failed {
                    reason: format!(
                        "The codec on this computer changed after it was installed, so it is no longer used ({reason}). Download it again."
                    ),
                };
            }
        });
    }
}

fn platform_name() -> String {
    format!("{} {}", std::env::consts::OS, std::env::consts::ARCH)
}

#[cfg(all(test, feature = "bundled-openh264"))]
mod tests {
    use std::sync::atomic::{AtomicUsize, Ordering};

    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    use tokio::net::TcpListener;

    use super::*;

    /// What the tests download: not a library, so the tests stand OpenH264
    /// compiled from source in for loading it (`pretend_load`). Everything
    /// before the load — the download, decompression, the hash, the write —
    /// is the real code.
    const PAYLOAD: &[u8] = b"pretend this is Cisco's OpenH264 binary, a little over a hundred bytes of it, so the progress has something to count";

    /// `printf 'OpenH264 Video Codec provided by Cisco Systems, Inc.\n' |
    /// bzip2 -9`: written by the reference bzip2, not by the crate decoding
    /// it, so the decoder is checked against a real `.bz2`.
    const FIXTURE_BZ2: [u8; 92] = [
        0x42, 0x5a, 0x68, 0x39, 0x31, 0x41, 0x59, 0x26, 0x53, 0x59, 0x95, 0x7e, 0x30, 0x74, 0x00,
        0x00, 0x08, 0xdf, 0x80, 0x00, 0x10, 0x40, 0x05, 0x15, 0x00, 0x08, 0x60, 0x89, 0x00, 0x1e,
        0x23, 0xdd, 0x20, 0x20, 0x00, 0x54, 0x42, 0x00, 0x00, 0x00, 0x68, 0x45, 0x3d, 0x35, 0x06,
        0x8d, 0x94, 0x6d, 0x46, 0xd3, 0x46, 0xa1, 0x43, 0x6c, 0x3c, 0x2a, 0xe0, 0x50, 0xcc, 0xc2,
        0xe2, 0x83, 0xa5, 0x8a, 0x72, 0xa3, 0x05, 0x03, 0x38, 0x94, 0x3d, 0xa6, 0x0c, 0x2a, 0x50,
        0xfe, 0x75, 0x08, 0xf5, 0xa9, 0x17, 0xc5, 0xdc, 0x91, 0x4e, 0x14, 0x24, 0x25, 0x5f, 0x8c,
        0x1d, 0x00,
    ];

    fn pretend_load(path: &Path) -> Result<OpenH264API, String> {
        assert!(path.exists(), "only a file that is there is loaded");

        Ok(OpenH264API::from_source())
    }

    fn blob_of(bytes: &[u8]) -> Blob {
        Blob {
            file: "libopenh264-test.so",
            sha256: Box::leak(sha256_hex(bytes).into_boxed_str()),
            version: OPENH264_VERSION,
            shipped: false,
        }
    }

    fn compress(bytes: &[u8]) -> Vec<u8> {
        let mut encoder = bzip2::write::BzEncoder::new(Vec::new(), bzip2::Compression::best());
        encoder.write_all(bytes).unwrap();
        encoder.finish().unwrap()
    }

    fn temp_dir(name: &str) -> PathBuf {
        let dir = std::env::temp_dir().join(format!(
            "aicountly-remote-codec-test-{name}-{}",
            unique_suffix()
        ));
        let _ = std::fs::remove_dir_all(&dir);
        dir
    }

    /// One answer from the test server: status, body, and how long to wait
    /// before sending it.
    type Answer = (u16, Vec<u8>, Duration);

    /// A tiny HTTP/1.1 server, as `remote-core`'s update tests use: the path
    /// and how many requests came before it, in; an answer, out.
    async fn serve(
        answer: impl Fn(&str, usize) -> Answer + Send + Sync + 'static,
    ) -> (String, Arc<AtomicUsize>) {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = listener.local_addr().unwrap();
        let answer = Arc::new(answer);
        let requests = Arc::new(AtomicUsize::new(0));
        let counted = Arc::clone(&requests);

        tokio::spawn(async move {
            loop {
                let Ok((mut socket, _)) = listener.accept().await else {
                    return;
                };
                let answer = Arc::clone(&answer);
                let counted = Arc::clone(&counted);

                tokio::spawn(async move {
                    let mut buffer = vec![0u8; 8192];
                    let mut read = 0;
                    loop {
                        let Ok(n) = socket.read(&mut buffer[read..]).await else {
                            return;
                        };
                        if n == 0 {
                            return;
                        }
                        read += n;
                        if buffer[..read].windows(4).any(|w| w == b"\r\n\r\n") {
                            break;
                        }
                    }

                    let request = String::from_utf8_lossy(&buffer[..read]);
                    let path = request.split_whitespace().nth(1).unwrap_or("/").to_owned();
                    let before = counted.fetch_add(1, Ordering::SeqCst);
                    let (status, body, delay) = answer(&path, before);

                    tokio::time::sleep(delay).await;

                    let head = format!(
                        "HTTP/1.1 {status} X\r\nContent-Length: {}\r\nContent-Type: application/octet-stream\r\nConnection: close\r\n\r\n",
                        body.len()
                    );
                    let _ = socket.write_all(head.as_bytes()).await;
                    let _ = socket.write_all(&body).await;
                    let _ = socket.shutdown().await;
                });
            }
        });

        (format!("http://{address}"), requests)
    }

    /// An address nothing listens on: what an unreachable HTTPS server looks
    /// like from here.
    async fn nowhere() -> String {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = listener.local_addr().unwrap();
        drop(listener);

        format!("http://{address}")
    }

    fn options(bases: Vec<String>, directory: &Path, blob: Blob) -> CodecOptions {
        CodecOptions {
            directory: Some(directory.to_owned()),
            base_urls: bases,
            user_agent: "AicountlyRemote/test".into(),
            spawner: Some(Arc::new(|future| {
                tokio::spawn(future);
            })),
            attempts: 2,
            first_retry: Duration::from_millis(1),
            max_download_bytes: MAX_DOWNLOAD_BYTES,
            max_binary_bytes: MAX_BINARY_BYTES,
            blob: Some(blob),
            load: pretend_load,
        }
    }

    /// Wait, briefly, for the manager to settle on a status that satisfies
    /// `done`.
    async fn settles(manager: &CodecManager, done: impl Fn(&CodecStatus) -> bool) -> CodecStatus {
        for _ in 0..500 {
            let status = manager.status();
            if done(&status) {
                return status;
            }
            tokio::time::sleep(Duration::from_millis(10)).await;
        }

        panic!("the codec did not settle: {:?}", manager.status());
    }

    /// The path Cisco's server would serve `payload` under, and the body.
    fn published(payload: &[u8]) -> (String, Vec<u8>) {
        (format!("/{}.bz2", blob_of(payload).file), compress(payload))
    }

    /// A server that publishes `payload` and nothing else.
    async fn publishing(payload: &'static [u8]) -> (String, Arc<AtomicUsize>) {
        let (route, body) = published(payload);

        serve(move |path, _| {
            if path == route {
                (200, body.clone(), Duration::ZERO)
            } else {
                (404, b"not found".to_vec(), Duration::ZERO)
            }
        })
        .await
    }

    // ----------------------------------------------------- which binary, where

    #[test]
    fn each_platform_gets_its_own_cisco_binary() {
        assert_eq!(
            blob_for("windows", "x86_64").map(|blob| blob.file),
            Some("openh264-2.6.0-win64.dll")
        );
        assert_eq!(
            blob_for("macos", "aarch64").map(|blob| blob.file),
            Some("libopenh264-2.6.0-mac-arm64.dylib")
        );
        assert_eq!(
            blob_for("macos", "x86_64").map(|blob| blob.file),
            Some("libopenh264-2.6.0-mac-x64.dylib")
        );

        // A developer's Linux machine can try it; nothing ships for Linux.
        let linux = blob_for("linux", "x86_64").expect("a Linux binary for experiments");
        assert!(!linux.shipped);
        assert!(blob_for("windows", "x86_64").is_some_and(|blob| blob.shipped));
        assert!(blob_for("macos", "aarch64").is_some_and(|blob| blob.shipped));

        for (os, arch) in [
            ("windows", "aarch64"),
            ("windows", "x86"),
            ("linux", "aarch64"),
            ("freebsd", "x86_64"),
            ("ios", "aarch64"),
        ] {
            assert_eq!(blob_for(os, arch), None, "{os} {arch}");
        }

        assert_eq!(
            this_platform(),
            blob_for(std::env::consts::OS, std::env::consts::ARCH)
        );
    }

    /// From Cisco's own server, HTTPS before HTTP, and nowhere else.
    #[test]
    fn the_binary_is_fetched_from_cisco_https_first() {
        let bases: Vec<String> = CISCO_BASE_URLS
            .iter()
            .map(|base| (*base).to_owned())
            .collect();

        assert_eq!(
            WINDOWS_X64.urls(&bases),
            vec![
                "https://ciscobinary.openh264.org/openh264-2.6.0-win64.dll.bz2",
                "http://ciscobinary.openh264.org/openh264-2.6.0-win64.dll.bz2",
            ]
        );
        assert_eq!(
            MACOS_ARM64.urls(&["https://example.test/".to_owned()]),
            vec!["https://example.test/libopenh264-2.6.0-mac-arm64.dylib.bz2"]
        );

        let options = CodecOptions::cisco(None, "AicountlyRemote/test");
        assert_eq!(options.base_urls, bases);
        assert!(options
            .base_urls
            .iter()
            .all(|base| base.ends_with("://ciscobinary.openh264.org")));
    }

    /// The pinned hashes are the ones `openh264-sys2` 0.9.9 lists for these
    /// files (`src/blobs/hashes.txt`), so a file that passes here also passes
    /// the crate's own check.
    #[test]
    fn the_pinned_hashes_are_cisco_2_6_0() {
        assert_eq!(
            WINDOWS_X64.sha256,
            "2076cb5675ec6c1a4c70e7a2a322552f547b6eeed649d6dfcd9e02a543b24691"
        );
        assert_eq!(
            MACOS_ARM64.sha256,
            "052e98bfcf7a9167d22f3bbb3f5988ef79065591f36af8b52924b22b13624551"
        );
        assert_eq!(
            MACOS_X64.sha256,
            "e3dc8bc01fe69363f61fd3c02fd27798537a585eadd38cd808f303d1ee505a19"
        );

        for blob in KNOWN {
            assert_eq!(blob.version, "2.6.0");
            assert!(blob.file.contains("2.6.0"), "{}", blob.file);
            assert_eq!(blob.sha256.len(), 64);
            assert!(blob.sha256.bytes().all(|byte| byte.is_ascii_hexdigit()));
        }
    }

    // ---------------------------------------------------------- verification

    #[test]
    fn the_binary_passes_and_a_tampered_one_does_not() {
        let blob = blob_of(PAYLOAD);
        assert_eq!(verify_bytes(PAYLOAD, &blob), Ok(()));

        let mut tampered = PAYLOAD.to_vec();
        tampered[7] ^= 0x01;
        assert_eq!(verify_bytes(&tampered, &blob), Err(CodecError::Mismatch));
        assert_eq!(
            verify_bytes(&PAYLOAD[1..], &blob),
            Err(CodecError::Mismatch)
        );

        let dir = temp_dir("verify");
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.join(blob.file);

        std::fs::write(&path, PAYLOAD).unwrap();
        assert_eq!(verify_file(&path, &blob), Ok(PAYLOAD.len() as u64));

        std::fs::write(&path, &tampered).unwrap();
        assert_eq!(verify_file(&path, &blob), Err(CodecError::Mismatch));

        let _ = std::fs::remove_dir_all(&dir);
    }

    /// The application's loader goes through the `openh264` crate's own
    /// check: a file it does not know is refused before it is opened, even
    /// one that matched a pin.
    #[test]
    fn a_file_the_openh264_crate_does_not_know_is_never_loaded() {
        let dir = temp_dir("unknown");
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.join("libopenh264-test.so");
        std::fs::write(&path, PAYLOAD).unwrap();

        let refused = load_cisco(&path).err().expect("refused");
        assert!(refused.contains("Invalid hash"), "{refused}");

        let opened = LoadedCodec::open(blob_of(PAYLOAD), &path, load_cisco);
        assert!(matches!(opened, Err(CodecError::NotLoadable(_))));

        let _ = std::fs::remove_dir_all(&dir);
    }

    // ----------------------------------------------------------------- bzip2

    #[test]
    fn a_bzip2_file_decodes_and_anything_else_is_refused() {
        assert_eq!(
            decompress(&FIXTURE_BZ2, MAX_BINARY_BYTES).unwrap(),
            b"OpenH264 Video Codec provided by Cisco Systems, Inc.\n"
        );

        assert_eq!(
            decompress(&compress(PAYLOAD), MAX_BINARY_BYTES).unwrap(),
            PAYLOAD
        );

        assert!(matches!(
            decompress(PAYLOAD, MAX_BINARY_BYTES),
            Err(CodecError::Corrupt(_))
        ));
        assert!(matches!(
            decompress(&FIXTURE_BZ2[..40], MAX_BINARY_BYTES),
            Err(CodecError::Corrupt(_))
        ));
    }

    /// A small download that would decompress into a large file stops at the
    /// limit rather than filling memory.
    #[test]
    fn a_download_that_decompresses_past_the_limit_is_refused() {
        let bomb = compress(&vec![0_u8; 64 * 1024]);
        assert!(bomb.len() < 1024);

        assert_eq!(decompress(&bomb, 4096), Err(CodecError::TooLarge));
        assert_eq!(
            decompress(&bomb, 64 * 1024).map(|binary| binary.len()),
            Ok(64 * 1024)
        );
    }

    // ----------------------------------------------------------------- store

    #[test]
    fn the_binary_is_written_whole_or_not_at_all_and_privately() {
        let dir = temp_dir("atomic");
        let path = write_atomically(&dir, "libopenh264-test.so", PAYLOAD).expect("written");

        assert_eq!(path, dir.join("libopenh264-test.so"));
        assert_eq!(std::fs::read(&path).unwrap(), PAYLOAD);

        // Over an earlier copy, which is replaced whole.
        write_atomically(&dir, "libopenh264-test.so", b"second").expect("rewritten");
        assert_eq!(std::fs::read(&path).unwrap(), b"second");

        let leftovers: Vec<_> = std::fs::read_dir(&dir)
            .unwrap()
            .flatten()
            .map(|entry| entry.file_name().to_string_lossy().into_owned())
            .filter(|name| name.ends_with(".part"))
            .collect();
        assert!(leftovers.is_empty(), "{leftovers:?}");

        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;

            let folder = std::fs::metadata(&dir).unwrap().permissions().mode() & 0o777;
            let file = std::fs::metadata(&path).unwrap().permissions().mode() & 0o777;
            assert_eq!(folder, 0o700, "nobody else may write in the folder");
            assert_eq!(file & 0o077, 0, "nor read or write the file");
        }

        // Somewhere it cannot be written: an error, and nothing left behind.
        let blocked = dir.join("not-a-folder");
        std::fs::write(&blocked, b"a file where the folder should be").unwrap();
        assert!(write_atomically(&blocked, "libopenh264-test.so", PAYLOAD).is_err());
        assert_eq!(
            std::fs::read(&blocked).unwrap(),
            b"a file where the folder should be"
        );

        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn tidying_removes_only_what_this_module_wrote() {
        let dir = temp_dir("tidy");
        std::fs::create_dir_all(&dir).unwrap();
        let blob = blob_of(PAYLOAD);

        for name in [
            "libopenh264-test.so",
            "libopenh264-test.so.123-456.part",
            "openh264-2.6.0-win64.dll",
            "openh264-2.6.0-win64.dll.9-9.part",
            "somebody-elses.dll",
            "notes.txt",
        ] {
            std::fs::write(dir.join(name), b"x").unwrap();
        }

        tidy(&dir, &blob, Some("libopenh264-test.so"));

        let mut left: Vec<_> = std::fs::read_dir(&dir)
            .unwrap()
            .flatten()
            .map(|entry| entry.file_name().to_string_lossy().into_owned())
            .collect();
        left.sort();
        assert_eq!(
            left,
            ["libopenh264-test.so", "notes.txt", "somebody-elses.dll"]
        );

        tidy(&dir, &blob, None);
        assert!(!dir.join("libopenh264-test.so").exists());
        assert!(dir.join("notes.txt").exists());

        let _ = std::fs::remove_dir_all(&dir);
    }

    // --------------------------------------------------------- state machine

    #[tokio::test]
    async fn a_computer_cisco_publishes_nothing_for_says_so() {
        let dir = temp_dir("unsupported");
        let mut options = options(vec![nowhere().await], &dir, blob_of(PAYLOAD));
        options.blob = None;

        let manager = CodecManager::new(options, true);
        assert!(matches!(manager.status(), CodecStatus::Unsupported { .. }));
        assert!(matches!(
            manager.start().await,
            CodecStatus::Unsupported { .. }
        ));
        assert!(matches!(
            manager.install().await,
            CodecStatus::Unsupported { .. }
        ));
        assert!(!manager.source().is_ready());
    }

    /// The first start: downloaded from Cisco — here, after the HTTPS address
    /// could not be reached — decompressed, checked, written, loaded, and in
    /// every encoder's slot. The window heard each step.
    #[tokio::test]
    async fn the_first_start_downloads_checks_and_loads_it() {
        let dir = temp_dir("first-start");
        let (base, requests) = publishing(PAYLOAD).await;

        let manager = CodecManager::new(
            options(vec![nowhere().await, base], &dir, blob_of(PAYLOAD)),
            true,
        );
        let heard = Arc::new(Mutex::new(Vec::new()));
        let log = Arc::clone(&heard);
        manager.on_change(move |status| log.lock().unwrap().push(status.clone()));

        assert_eq!(manager.status(), CodecStatus::NotInstalled);
        let status = manager.start().await;

        let CodecStatus::Ready {
            version,
            path,
            size_bytes,
        } = status
        else {
            panic!("not ready: {status:?}");
        };
        assert_eq!(version, "2.6.0");
        assert_eq!(PathBuf::from(path), dir.join("libopenh264-test.so"));
        assert_eq!(size_bytes, PAYLOAD.len() as u64);
        assert_eq!(
            std::fs::read(dir.join("libopenh264-test.so")).unwrap(),
            PAYLOAD
        );
        assert!(manager.source().is_ready());
        assert_eq!(requests.load(Ordering::SeqCst), 1);

        let heard = heard.lock().unwrap().clone();
        assert!(
            matches!(heard.first(), Some(CodecStatus::Downloading { .. })),
            "{heard:?}"
        );
        assert!(heard.iter().any(|status| matches!(
            status,
            CodecStatus::Downloading { received_bytes, total_bytes: Some(total) } if received_bytes == total
        )), "{heard:?}");
        assert!(matches!(heard.last(), Some(CodecStatus::Ready { .. })));

        let _ = std::fs::remove_dir_all(&dir);
    }

    /// A copy already here that still passes is loaded, not downloaded again;
    /// one that does not pass is replaced.
    #[tokio::test]
    async fn a_copy_already_here_is_used_only_if_it_still_passes() {
        let dir = temp_dir("on-disk");
        std::fs::create_dir_all(&dir).unwrap();
        std::fs::write(dir.join("libopenh264-test.so"), PAYLOAD).unwrap();

        let (base, requests) = publishing(PAYLOAD).await;

        let manager = CodecManager::new(options(vec![base.clone()], &dir, blob_of(PAYLOAD)), true);
        assert!(manager.start().await.is_ready());
        assert_eq!(requests.load(Ordering::SeqCst), 0, "nothing downloaded");

        // Changed on disk while the application was not running.
        std::fs::write(dir.join("libopenh264-test.so"), b"something else").unwrap();
        let manager = CodecManager::new(options(vec![base], &dir, blob_of(PAYLOAD)), true);
        assert!(manager.start().await.is_ready());
        assert_eq!(requests.load(Ordering::SeqCst), 1, "downloaded again");
        assert_eq!(
            std::fs::read(dir.join("libopenh264-test.so")).unwrap(),
            PAYLOAD
        );

        let _ = std::fs::remove_dir_all(&dir);
    }

    /// Switched off: nothing is fetched, and a copy an earlier run left is
    /// deleted.
    #[tokio::test]
    async fn a_codec_switched_off_is_neither_downloaded_nor_kept() {
        let dir = temp_dir("off");
        std::fs::create_dir_all(&dir).unwrap();
        std::fs::write(dir.join("libopenh264-test.so"), PAYLOAD).unwrap();
        let (base, requests) = serve(|_, _| (200, compress(PAYLOAD), Duration::ZERO)).await;

        let manager = CodecManager::new(options(vec![base], &dir, blob_of(PAYLOAD)), false);
        assert_eq!(manager.status(), CodecStatus::Disabled);
        assert_eq!(manager.start().await, CodecStatus::Disabled);
        assert_eq!(manager.install().await, CodecStatus::Disabled);

        assert!(!dir.join("libopenh264-test.so").exists());
        assert!(!manager.source().is_ready());
        assert_eq!(requests.load(Ordering::SeqCst), 0);

        let _ = std::fs::remove_dir_all(&dir);
    }

    /// Licence condition 2: off, on, and off again. Off stops it being used
    /// and deletes it; on downloads it again.
    #[tokio::test]
    async fn switching_it_off_deletes_it_and_switching_it_on_downloads_it_again() {
        let dir = temp_dir("toggle");
        let (base, requests) = publishing(PAYLOAD).await;

        let manager = CodecManager::new(options(vec![base], &dir, blob_of(PAYLOAD)), true);
        let source = manager.source();
        assert!(manager.start().await.is_ready());
        let loaded_generation = source.generation();

        assert_eq!(manager.set_enabled(false).await, CodecStatus::Disabled);
        assert!(!manager.is_enabled());
        assert!(!source.is_ready(), "no encoder may build from it");
        assert_ne!(
            source.generation(),
            loaded_generation,
            "every encoder lets go"
        );
        assert!(!dir.join("libopenh264-test.so").exists(), "deleted");

        let status = manager.set_enabled(true).await;
        assert!(
            matches!(
                status,
                CodecStatus::Downloading { .. } | CodecStatus::Ready { .. }
            ),
            "{status:?}"
        );
        settles(&manager, CodecStatus::is_ready).await;
        assert!(source.is_ready());
        assert!(dir.join("libopenh264-test.so").exists());
        assert_eq!(requests.load(Ordering::SeqCst), 2);

        assert_eq!(manager.set_enabled(false).await, CodecStatus::Disabled);
        assert!(!dir.join("libopenh264-test.so").exists());

        let _ = std::fs::remove_dir_all(&dir);
    }

    /// Remove deletes the file and stops its use, and leaves the setting on.
    #[tokio::test]
    async fn remove_deletes_it_and_leaves_the_setting_alone() {
        let dir = temp_dir("remove");
        let (base, _) = publishing(PAYLOAD).await;

        let manager = CodecManager::new(options(vec![base], &dir, blob_of(PAYLOAD)), true);
        assert!(manager.install().await.is_ready());

        assert_eq!(manager.remove().await, CodecStatus::NotInstalled);
        assert!(manager.is_enabled());
        assert!(!manager.source().is_ready());
        assert!(!dir.join("libopenh264-test.so").exists());

        assert!(manager.install().await.is_ready(), "Download now");

        let _ = std::fs::remove_dir_all(&dir);
    }

    /// Cisco's server down: every address a few times, then a reason the
    /// window can show — and Retry, once it is back, works.
    #[tokio::test]
    async fn a_server_that_is_down_is_a_reason_and_retry_works_once_it_is_back() {
        let dir = temp_dir("down");
        let up = Arc::new(std::sync::atomic::AtomicBool::new(false));
        let serving = Arc::clone(&up);
        let (base, requests) = serve(move |_, _| {
            if serving.load(Ordering::SeqCst) {
                (200, compress(PAYLOAD), Duration::ZERO)
            } else {
                (503, b"down for maintenance".to_vec(), Duration::ZERO)
            }
        })
        .await;

        let manager = CodecManager::new(options(vec![base], &dir, blob_of(PAYLOAD)), true);
        let status = manager.install().await;

        let CodecStatus::Failed { reason } = &status else {
            panic!("expected a failure: {status:?}");
        };
        assert!(reason.contains("503"), "{reason}");
        assert!(
            !reason.contains("maintenance"),
            "never the server's own page"
        );
        assert_eq!(requests.load(Ordering::SeqCst), 2, "each attempt");
        assert!(!manager.source().is_ready());
        assert!(!dir.join("libopenh264-test.so").exists());

        up.store(true, Ordering::SeqCst);
        assert!(manager.install().await.is_ready());

        let _ = std::fs::remove_dir_all(&dir);
    }

    /// Nothing listening at any address: an unreachable server, said as
    /// such, without a URL in it.
    #[tokio::test]
    async fn an_unreachable_server_is_a_reason_without_an_address_in_it() {
        let dir = temp_dir("unreachable");
        let manager = CodecManager::new(
            options(
                vec![nowhere().await, nowhere().await],
                &dir,
                blob_of(PAYLOAD),
            ),
            true,
        );

        let CodecStatus::Failed { reason } = manager.install().await else {
            panic!("expected a failure");
        };
        assert!(reason.contains("could not be reached"), "{reason}");
        assert!(!reason.contains("127.0.0.1"), "{reason}");

        let _ = std::fs::remove_dir_all(&dir);
    }

    /// Something other than Cisco's binary arrives: refused, and not kept
    /// under any name.
    #[tokio::test]
    async fn a_tampered_download_is_refused_and_not_kept() {
        let dir = temp_dir("tampered");
        let (base, _) = serve(|_, _| (200, compress(b"not the codec"), Duration::ZERO)).await;

        let manager = CodecManager::new(options(vec![base], &dir, blob_of(PAYLOAD)), true);
        let CodecStatus::Failed { reason } = manager.install().await else {
            panic!("expected a failure");
        };
        assert!(reason.contains("did not match"), "{reason}");
        assert!(!dir.join("libopenh264-test.so").exists());
        assert!(!manager.source().is_ready());

        let _ = std::fs::remove_dir_all(&dir);
    }

    /// More than an OpenH264 download can be is not read to the end.
    #[tokio::test]
    async fn a_download_over_the_size_limit_is_refused() {
        let dir = temp_dir("too-large");
        let (base, _) = serve(|_, _| (200, vec![0x42; 8192], Duration::ZERO)).await;

        let mut options = options(vec![base], &dir, blob_of(PAYLOAD));
        options.max_download_bytes = 4096;
        let manager = CodecManager::new(options, true);

        let CodecStatus::Failed { reason } = manager.install().await else {
            panic!("expected a failure");
        };
        assert_eq!(reason, CodecError::TooLarge.to_string());

        let _ = std::fs::remove_dir_all(&dir);
    }

    /// Switched off while it was downloading: what arrives is thrown away,
    /// and the switch wins.
    #[tokio::test]
    async fn switching_it_off_mid_download_throws_the_download_away() {
        let dir = temp_dir("mid-download");
        let (base, _) = serve(|_, _| (200, compress(PAYLOAD), Duration::from_millis(300))).await;

        let manager = CodecManager::new(options(vec![base], &dir, blob_of(PAYLOAD)), true);
        let downloading = manager.install_in_background();
        assert!(
            matches!(downloading, CodecStatus::Downloading { .. }),
            "{downloading:?}"
        );

        assert_eq!(manager.set_enabled(false).await, CodecStatus::Disabled);
        tokio::time::sleep(Duration::from_millis(600)).await;

        assert_eq!(manager.status(), CodecStatus::Disabled);
        assert!(!manager.source().is_ready());
        assert!(!dir.join("libopenh264-test.so").exists());

        let _ = std::fs::remove_dir_all(&dir);
    }

    /// Checked again every time it is loaded: a file changed after it was
    /// installed is not loaded by the next encoder, and the window is told.
    #[tokio::test]
    async fn a_codec_changed_after_it_was_installed_is_not_loaded_again() {
        let dir = temp_dir("changed");
        let (base, _) = publishing(PAYLOAD).await;

        let manager = CodecManager::new(options(vec![base], &dir, blob_of(PAYLOAD)), true);
        let source = manager.source();
        assert!(manager.install().await.is_ready());
        assert!(source.api().is_ok(), "an encoder can be built");

        std::fs::write(dir.join("libopenh264-test.so"), b"replaced behind our back").unwrap();

        assert!(matches!(
            source.api(),
            Err(super::super::h264::VideoError::CodecUnavailable)
        ));
        assert!(!source.is_ready());
        let CodecStatus::Failed { reason } = manager.status() else {
            panic!("expected a failure: {:?}", manager.status());
        };
        assert!(
            reason.contains("changed after it was installed"),
            "{reason}"
        );

        // Retry fetches a good copy and replaces the bad one.
        assert!(manager.install().await.is_ready());
        assert_eq!(
            std::fs::read(dir.join("libopenh264-test.so")).unwrap(),
            PAYLOAD
        );

        let _ = std::fs::remove_dir_all(&dir);
    }

    /// A session that finds no codec starts a download by itself — an
    /// unattended computer that was offline when it started gets its picture
    /// back without anybody pressing Retry.
    #[tokio::test]
    async fn a_session_that_needs_the_codec_starts_a_download() {
        let dir = temp_dir("wanted");
        let (base, requests) = publishing(PAYLOAD).await;

        let manager = CodecManager::new(options(vec![base], &dir, blob_of(PAYLOAD)), true);
        let source = manager.source();

        assert!(source.api().is_err(), "not there yet");
        settles(&manager, CodecStatus::is_ready).await;
        assert!(source.api().is_ok());
        assert_eq!(requests.load(Ordering::SeqCst), 1);

        let _ = std::fs::remove_dir_all(&dir);
    }

    /// The window renders a tagged union, in camelCase, and a status carries
    /// no URL.
    #[test]
    fn the_status_is_the_shape_the_window_reads() {
        let json = |status: CodecStatus| serde_json::to_value(status).unwrap();

        assert_eq!(
            json(CodecStatus::Disabled),
            serde_json::json!({ "state": "disabled" })
        );
        assert_eq!(
            json(CodecStatus::NotInstalled),
            serde_json::json!({ "state": "not_installed" })
        );
        assert_eq!(
            json(CodecStatus::Downloading {
                received_bytes: 10,
                total_bytes: Some(20)
            }),
            serde_json::json!({ "state": "downloading", "receivedBytes": 10, "totalBytes": 20 })
        );
        assert_eq!(
            json(CodecStatus::Ready {
                version: "2.6.0".into(),
                path: "/x".into(),
                size_bytes: 3
            }),
            serde_json::json!({ "state": "ready", "version": "2.6.0", "path": "/x", "sizeBytes": 3 })
        );
        assert_eq!(
            json(CodecStatus::Failed { reason: "r".into() }),
            serde_json::json!({ "state": "failed", "reason": "r" })
        );
        assert_eq!(
            json(CodecStatus::Unsupported {
                platform: "linux aarch64".into()
            }),
            serde_json::json!({ "state": "unsupported", "platform": "linux aarch64" })
        );
    }

    /// The sentence the switch is labelled with is the one in Cisco's licence
    /// text, and the text the About panel shows is the whole of it, including
    /// the condition that asks for it to be shown.
    #[test]
    fn the_attribution_and_the_licence_text_are_cisco_s() {
        let licence = include_str!("../../../../legal/OPENH264_BINARY_LICENSE.txt");

        assert_eq!(
            ATTRIBUTION,
            "OpenH264 Video Codec provided by Cisco Systems, Inc."
        );
        assert!(licence.contains(&format!("\"{ATTRIBUTION}\"")));
        assert!(licence.contains("About The Cisco-Provided Binary of OpenH264 Video Codec"));
        assert!(licence.contains("AVC/H.264 Patent Portfolio License Conditions"));
        assert!(licence.contains("separately downloaded to an end user"));
        assert!(licence.contains("to enable, disable, or re-enable"));
        assert!(licence
            .contains("must reproduce all of the above text, as well as this last condition"));

        // Byte for byte the `tests/reference/BINARY_LICENSE.txt` that
        // `openh264-sys2` 0.9.9 ships — Cisco's text, CRLFs and all — so an
        // edit to the copy, or a checkout that rewrote its line endings, fails
        // here rather than showing somebody a licence Cisco did not write.
        assert_eq!(
            sha256_hex(licence.as_bytes()),
            "63b75e7d1f5062a6b85e87be72a6158bc6a15807ad91570802fd2d034935e96b"
        );
    }
}
