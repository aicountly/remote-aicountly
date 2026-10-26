//! Where the agent's log actually goes.
//!
//! # Why a file, and not standard output
//!
//! Neither half of the Windows agent has somewhere to print to:
//!
//! * `AicountlyRemote.exe` is built with `windows_subsystem = "windows"` in a
//!   release build, so Windows gives it no console. Anything written to
//!   standard output or standard error is discarded — including, before this
//!   module existed, every line the agent logged. Running it from a terminal
//!   does not help: the process is detached from that console, which is why
//!   the documented `AICOUNTLY_REMOTE_LOG=debug` recipe produced silence.
//! * `AicountlyRemoteService.exe` runs in Session 0 under the SCM, which has
//!   no console at all and never did.
//!
//! So the log goes to a file, next to the configuration, where a support
//! engineer can be asked for it:
//!
//! ```text
//!   %ProgramData%\AICOUNTLY\Remote\logs\agent.log      the person's session
//!   %ProgramData%\AICOUNTLY\Remote\logs\service.log    the machine
//! ```
//!
//! Standard error is written to as well, so a debug build in a terminal and
//! `cargo run` behave as they always did.
//!
//! # What is never in it
//!
//! The log is a file somebody will be asked to email, so it is held to the
//! same rule as every other rendering of agent state: no device key, no
//! credential, no clipboard contents, no frame. That property is owned by the
//! types being logged — `DeviceCredential`, `DeviceKeypair` and the clipboard
//! payload all have `Debug` implementations that print a fingerprint or a
//! length and nothing else — and this module adds no way around them.
//!
//! # Bounded
//!
//! A remote-support agent runs for months. The file is capped at
//! [`MAX_LOG_BYTES`] and rolled once, to `<name>.log.1`, so the worst case is
//! two files of a known size rather than a disk somebody has to go and find.

use std::io::Write;
use std::path::PathBuf;
use std::sync::{Arc, Mutex};

/// How large one log file is allowed to get before it is rolled.
pub const MAX_LOG_BYTES: u64 = 2 * 1024 * 1024;

/// The environment variable that sets the level, as the documentation says.
pub const LEVEL_VARIABLE: &str = "AICOUNTLY_REMOTE_LOG";

/// The directory the log files live in.
///
/// A `logs` directory beside `config.json`, so one path is all a support
/// engineer has to be told — and, on Windows, so it inherits the data
/// directory's ACL rather than needing one of its own.
#[must_use]
pub fn log_directory() -> Option<PathBuf> {
    crate::config::config_path()
        .and_then(|config| config.parent().map(|directory| directory.join("logs")))
}

/// The file a given component logs to.
///
/// `component` is one of this product's own two names — `agent` or `service`.
/// It is constrained rather than trusted because it becomes a path.
#[must_use]
pub fn log_path(component: &str) -> Option<PathBuf> {
    if component.is_empty()
        || component.len() > 32
        || !component
            .chars()
            .all(|c| c.is_ascii_lowercase() || c == '-')
    {
        return None;
    }

    log_directory().map(|directory| directory.join(format!("{component}.log")))
}

/// Install the agent's log subscriber: the file above, and standard error.
///
/// Called once, at the top of each binary's `main`. Safe to call when the file
/// cannot be opened — a machine whose data directory is missing or read-only
/// still gets a working agent, it just gets one whose log is only on standard
/// error. Logging is diagnostics; it is never a reason to refuse to run.
///
/// Returns where the log is being written, for the About panel and for the
/// first line of the log itself.
pub fn init(component: &str) -> Option<PathBuf> {
    use tracing_subscriber::fmt::writer::MakeWriterExt;

    let filter = tracing_subscriber::EnvFilter::try_from_env(LEVEL_VARIABLE)
        .unwrap_or_else(|_| tracing_subscriber::EnvFilter::new("info"));

    let file = log_path(component).and_then(|path| RollingLog::open(path).ok());
    let written_to = file.as_ref().map(RollingLog::path);

    let builder = tracing_subscriber::fmt()
        .with_env_filter(filter)
        // No ANSI: this goes to a file somebody reads in Notepad, where escape
        // codes are noise rather than colour.
        .with_ansi(false);

    match file {
        Some(file) => builder.with_writer(file.and(std::io::stderr)).init(),
        None => builder.with_writer(std::io::stderr).init(),
    }

    if let Some(path) = written_to.as_ref() {
        tracing::info!(log = %path.display(), version = crate::AGENT_VERSION, "AICOUNTLY Remote starting");
    }

    written_to
}

/// A log file that rolls itself once at [`MAX_LOG_BYTES`].
#[derive(Clone)]
pub struct RollingLog {
    path: PathBuf,
    inner: Arc<Mutex<Open>>,
}

/// The open file, and how much has gone into it.
struct Open {
    file: std::fs::File,
    written: u64,
}

impl RollingLog {
    /// Open — creating the directory — and roll first if the file is already
    /// at the limit, so a restart does not keep appending to a full one.
    pub fn open(path: PathBuf) -> std::io::Result<Self> {
        if let Some(directory) = path.parent() {
            std::fs::create_dir_all(directory)?;
        }

        let written = std::fs::metadata(&path).map(|data| data.len()).unwrap_or(0);

        if written >= MAX_LOG_BYTES {
            roll(&path);
        }

        let file = std::fs::OpenOptions::new()
            .create(true)
            .append(true)
            .open(&path)?;

        let written = file.metadata().map(|data| data.len()).unwrap_or(0);

        Ok(Self {
            path,
            inner: Arc::new(Mutex::new(Open { file, written })),
        })
    }

    /// Where it is writing.
    #[must_use]
    pub fn path(&self) -> PathBuf {
        self.path.clone()
    }
}

/// Move the current file aside, replacing the one previous generation.
fn roll(path: &std::path::Path) {
    let previous = path.with_extension("log.1");

    let _ = std::fs::remove_file(&previous);
    let _ = std::fs::rename(path, &previous);
}

/// One event's worth of writing, holding the lock for exactly that long.
pub struct Line {
    path: PathBuf,
    inner: Arc<Mutex<Open>>,
}

impl Write for Line {
    fn write(&mut self, buffer: &[u8]) -> std::io::Result<usize> {
        let Ok(mut open) = self.inner.lock() else {
            // A poisoned lock means another thread panicked mid-write. Losing
            // the line is the right outcome; failing the caller's own work
            // because its logging could not be written is not.
            return Ok(buffer.len());
        };

        if open.written >= MAX_LOG_BYTES {
            roll(&self.path);

            // A failure here — the directory went away, or something else now
            // owns the path — leaves the handle we already have in place
            // rather than dropping logging on the floor.
            if let Ok(file) = std::fs::OpenOptions::new()
                .create(true)
                .append(true)
                .open(&self.path)
            {
                open.file = file;
                open.written = 0;
            }
        }

        let written = open.file.write(buffer)?;
        open.written += written as u64;

        Ok(written)
    }

    fn flush(&mut self) -> std::io::Result<()> {
        match self.inner.lock() {
            Ok(mut open) => open.file.flush(),
            Err(_) => Ok(()),
        }
    }
}

impl<'a> tracing_subscriber::fmt::MakeWriter<'a> for RollingLog {
    type Writer = Line;

    fn make_writer(&'a self) -> Self::Writer {
        Line {
            path: self.path.clone(),
            inner: Arc::clone(&self.inner),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The component name becomes a path, and every caller is inside this
    /// workspace today — which is exactly when a traversal bug gets written
    /// and stays unnoticed.
    #[test]
    fn a_component_name_that_could_escape_the_directory_is_refused() {
        for candidate in [
            "",
            "../../windows/system32/config/sam",
            "a/b",
            r"a\b",
            "Agent",
            "agent.log",
            "agent 2",
            &"x".repeat(33),
        ] {
            assert!(
                log_path(candidate).is_none(),
                "{candidate:?} should have been refused"
            );
        }
    }

    #[test]
    fn the_two_components_log_to_their_own_files_beside_the_configuration() {
        let (Some(agent), Some(service), Some(directory)) =
            (log_path("agent"), log_path("service"), log_directory())
        else {
            // A host with neither %ProgramData% nor $HOME. Nothing to assert.
            return;
        };

        assert!(agent.ends_with("agent.log"));
        assert!(service.ends_with("service.log"));
        assert_eq!(agent.parent(), Some(directory.as_path()));
        assert!(directory.ends_with("logs"));
    }

    /// A remote-support agent runs for months. An uncapped log file is a disk
    /// somebody has to go and find.
    #[test]
    fn the_log_rolls_once_it_passes_the_cap_and_keeps_one_generation() {
        let directory =
            std::env::temp_dir().join(format!("aicountly-remote-log-test-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&directory);

        let path = directory.join("agent.log");
        let log = RollingLog::open(path.clone()).expect("opens");

        {
            use tracing_subscriber::fmt::MakeWriter;

            let mut line = log.make_writer();
            // Past the cap in one write, so the next one has to roll.
            let bulk = vec![b'.'; (MAX_LOG_BYTES + 1) as usize];
            line.write_all(&bulk).expect("writes");
            line.flush().expect("flushes");

            let mut next = log.make_writer();
            next.write_all(b"after the roll\n").expect("writes");
            next.flush().expect("flushes");
        }

        let rolled = path.with_extension("log.1");

        assert!(rolled.exists(), "the previous generation is kept");
        assert!(
            std::fs::metadata(&path).expect("current").len() < MAX_LOG_BYTES,
            "the current file started again"
        );
        assert_eq!(
            std::fs::read_to_string(&path).expect("readable"),
            "after the roll\n"
        );

        let _ = std::fs::remove_dir_all(&directory);
    }
}
