//! The clipboard. Text only, bounded, and never logged.
//!
//! # Why the retry loop
//!
//! The Windows clipboard is a single global resource guarded by a lock any
//! process can hold. `OpenClipboard` fails outright while somebody else has
//! it — and "somebody else" is routinely a clipboard manager, a password
//! manager, or Office reformatting what was just copied. A single attempt
//! fails often enough to be reported as a bug; a short bounded retry does not.
//!
//! It is bounded rather than patient: a clipboard nobody will release is a
//! clipboard the session does without, and blocking a control session on it
//! would be far worse than a synchronisation that did not happen.

use remote_device::{ClipboardProvider, PlatformError, PlatformResult};

/// How many times to try opening the clipboard.
pub const OPEN_ATTEMPTS: u32 = 10;

/// How long to wait between attempts.
pub const OPEN_RETRY_MS: u64 = 20;

/// The Windows clipboard.
#[derive(Debug, Default)]
pub struct WindowsClipboard;

impl ClipboardProvider for WindowsClipboard {
    fn read_text(&self) -> PlatformResult<Option<String>> {
        #[cfg(target_os = "windows")]
        {
            imp::read_text()
        }

        #[cfg(not(target_os = "windows"))]
        {
            Err(PlatformError::Unsupported("Clipboard sharing"))
        }
    }

    fn write_text(&self, text: &str) -> PlatformResult<()> {
        #[cfg(target_os = "windows")]
        {
            imp::write_text(text)
        }

        #[cfg(not(target_os = "windows"))]
        {
            let _ = text;

            Err(PlatformError::Unsupported("Clipboard sharing"))
        }
    }
}

/// Prepare text for the Windows clipboard.
///
/// Two conversions, both of which matter:
///
/// * **Line endings.** The clipboard is CRLF; text arriving from a browser or
///   from macOS is LF. Pasting LF-only text into Notepad on older Windows
///   builds produces one long line.
/// * **NUL.** The clipboard is a C API. Text containing a NUL would be
///   silently truncated at it, so it is refused rather than half-pasted — the
///   protocol refuses it too, and this is the second of the two checks.
pub fn prepare_for_clipboard(text: &str) -> Result<Vec<u16>, PlatformError> {
    if text.contains('\0') {
        return Err(PlatformError::Os {
            operation: "writing the clipboard",
            detail: "the text contained a NUL byte".into(),
        });
    }

    let normalised = text.replace("\r\n", "\n").replace('\n', "\r\n");

    let mut units: Vec<u16> = normalised.encode_utf16().collect();
    units.push(0);

    Ok(units)
}

/// Turn what came off the clipboard into text the protocol will carry.
///
/// The reverse conversion, and the same NUL rule: the string stops at the
/// first NUL, because that is where the C API says it ends.
#[must_use]
pub fn text_from_clipboard(units: &[u16]) -> String {
    let end = units.iter().position(|unit| *unit == 0).unwrap_or(units.len());

    String::from_utf16_lossy(&units[..end]).replace("\r\n", "\n")
}

#[cfg(target_os = "windows")]
mod imp {
    use super::{prepare_for_clipboard, text_from_clipboard, OPEN_ATTEMPTS, OPEN_RETRY_MS};
    use remote_device::{PlatformError, PlatformResult};
    use windows::Win32::Foundation::{HANDLE, HGLOBAL};
    use windows::Win32::System::DataExchange::{
        CloseClipboard, EmptyClipboard, GetClipboardData, IsClipboardFormatAvailable,
        OpenClipboard, SetClipboardData,
    };
    use windows::Win32::System::Memory::{GlobalAlloc, GlobalLock, GlobalUnlock, GMEM_MOVEABLE};
    use windows::Win32::System::Ole::CF_UNICODETEXT;

    /// Open the clipboard, retrying briefly while another process holds it.
    struct ClipboardGuard;

    impl ClipboardGuard {
        fn open() -> PlatformResult<Self> {
            for attempt in 0..OPEN_ATTEMPTS {
                // SAFETY: OpenClipboard takes an optional window handle; None
                // associates the clipboard with the current task, which is
                // what a process with no window of its own wants.
                if unsafe { OpenClipboard(None) }.is_ok() {
                    return Ok(Self);
                }

                if attempt + 1 < OPEN_ATTEMPTS {
                    std::thread::sleep(std::time::Duration::from_millis(OPEN_RETRY_MS));
                }
            }

            Err(PlatformError::Os {
                operation: "opening the clipboard",
                detail: "another application is holding the clipboard".into(),
            })
        }
    }

    impl Drop for ClipboardGuard {
        fn drop(&mut self) {
            // Closed on every path, including a panic: leaving the clipboard
            // open would lock it for every other application on the machine.
            // SAFETY: this guard exists only when OpenClipboard succeeded.
            unsafe {
                let _ = CloseClipboard();
            }
        }
    }

    pub fn read_text() -> PlatformResult<Option<String>> {
        let _guard = ClipboardGuard::open()?;

        // SAFETY: the guard holds the clipboard open for this whole block.
        unsafe {
            if IsClipboardFormatAvailable(CF_UNICODETEXT.0.into()).is_err() {
                // Something is on the clipboard, but not text. `None` rather
                // than an error: this version carries text and nothing else,
                // and an image on the clipboard is not a failure.
                return Ok(None);
            }

            let handle: HANDLE = GetClipboardData(CF_UNICODETEXT.0.into()).map_err(|error| {
                PlatformError::Os {
                    operation: "reading the clipboard",
                    detail: error.message(),
                }
            })?;

            let global = HGLOBAL(handle.0);
            let pointer = GlobalLock(global) as *const u16;

            if pointer.is_null() {
                return Ok(None);
            }

            let mut length = 0_usize;
            // Bounded: the clipboard is a C string and a corrupt one without a
            // terminator would otherwise be read until the process faulted.
            while length < 8 * 1024 * 1024 && *pointer.add(length) != 0 {
                length += 1;
            }

            let units = std::slice::from_raw_parts(pointer, length).to_vec();
            let _ = GlobalUnlock(global);

            Ok(Some(text_from_clipboard(&units)))
        }
    }

    pub fn write_text(text: &str) -> PlatformResult<()> {
        let units = prepare_for_clipboard(text)?;
        let bytes = std::mem::size_of_val(units.as_slice());

        let _guard = ClipboardGuard::open()?;

        // SAFETY: the allocation is sized from `units`, filled from it, and
        // handed to the clipboard, which takes ownership on success.
        unsafe {
            EmptyClipboard().map_err(|error| PlatformError::Os {
                operation: "writing the clipboard",
                detail: error.message(),
            })?;

            let global = GlobalAlloc(GMEM_MOVEABLE, bytes).map_err(|error| PlatformError::Os {
                operation: "writing the clipboard",
                detail: error.message(),
            })?;

            let pointer = GlobalLock(global) as *mut u16;
            if pointer.is_null() {
                return Err(PlatformError::Os {
                    operation: "writing the clipboard",
                    detail: "the clipboard buffer could not be locked".into(),
                });
            }

            std::ptr::copy_nonoverlapping(units.as_ptr(), pointer, units.len());
            let _ = GlobalUnlock(global);

            SetClipboardData(CF_UNICODETEXT.0.into(), Some(HANDLE(global.0))).map_err(|error| {
                PlatformError::Os {
                    operation: "writing the clipboard",
                    detail: error.message(),
                }
            })?;

            Ok(())
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// LF-only text pasted into Notepad on older Windows builds is one long
    /// line.
    #[test]
    fn line_endings_are_converted_to_the_clipboards_own() {
        let units = prepare_for_clipboard("one\ntwo\nthree").expect("prepared");
        let text = text_from_clipboard(&units);

        assert_eq!(text, "one\ntwo\nthree");

        let raw = String::from_utf16_lossy(&units[..units.len() - 1]);
        assert_eq!(raw, "one\r\ntwo\r\nthree");
    }

    #[test]
    fn text_that_already_has_crlf_is_not_doubled() {
        let units = prepare_for_clipboard("one\r\ntwo").expect("prepared");
        let raw = String::from_utf16_lossy(&units[..units.len() - 1]);

        assert_eq!(raw, "one\r\ntwo");
        assert!(!raw.contains("\r\r"));
    }

    /// The clipboard is a C API: text containing a NUL would be silently
    /// truncated at it.
    #[test]
    fn a_nul_byte_is_refused_rather_than_half_pasted() {
        assert!(prepare_for_clipboard("before\0after").is_err());
    }

    #[test]
    fn the_prepared_buffer_is_nul_terminated() {
        let units = prepare_for_clipboard("text").expect("prepared");

        assert_eq!(units.last(), Some(&0));
    }

    #[test]
    fn reading_stops_at_the_terminator() {
        let units: Vec<u16> = "hello\0ignored".encode_utf16().collect();

        assert_eq!(text_from_clipboard(&units), "hello");
    }

    #[test]
    fn unicode_survives_the_round_trip() {
        for text in ["café", "日本語", "emoji 🎉", "Ünïcödé"] {
            let units = prepare_for_clipboard(text).expect("prepared");

            assert_eq!(text_from_clipboard(&units), text);
        }
    }

    #[test]
    fn an_empty_clipboard_write_is_still_valid() {
        let units = prepare_for_clipboard("").expect("prepared");

        assert_eq!(units, vec![0]);
        assert_eq!(text_from_clipboard(&units), "");
    }

    /// A clipboard nobody will release is a clipboard the session does
    /// without; blocking a control session on it would be far worse.
    #[test]
    fn the_retry_is_bounded() {
        assert!(OPEN_ATTEMPTS > 1, "one attempt fails often enough to be a bug report");
        assert!(
            u64::from(OPEN_ATTEMPTS) * OPEN_RETRY_MS < 1_000,
            "the whole retry must stay well under a second"
        );
    }
}
