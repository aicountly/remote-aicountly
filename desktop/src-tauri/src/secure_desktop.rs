//! The app's end of the Secure Desktop helper.
//!
//! When a session is being controlled and the Secure Desktop may be answered
//! — the person at the machine ticked the box, or an unattended connection is
//! permitted by the device setting — the app asks the service to start a
//! helper ([`crate::ipc::start_secure_desktop`]) and connects to the dedicated
//! pipe the service names. This module owns that connection.
//!
//! # What it is, and is not
//!
//! It is a plain client of a named pipe: a reader thread that takes the
//! helper's **state** and **frames**, and a writer that sends **input**,
//! **ping** and **stop**. It holds no privilege — the helper is the SYSTEM
//! process on the Secure Desktop, and this is the medium-integrity app talking
//! to it over a pipe whose DACL already decided it may. The wire protocol and
//! its size caps are [`aicountly_remote_service::secure_desktop::protocol`],
//! shared with the helper so the two cannot drift.
//!
//! # The gate is still the only path to input
//!
//! Nothing here injects anything. `Agent::dispatch` turns a **gate-admitted**
//! control message into a [`HelperInput`] and hands it over; a message the gate
//! refused never reaches this module, exactly as it never reaches `SendInput`.
//! Routing to the helper instead of the local injector is a change of
//! destination, not a second path.

use std::io::{Read, Write};
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use aicountly_remote_service::secure_desktop::protocol::{HelperProtocolError, HEADER_BYTES};
use aicountly_remote_service::{HelperFrame, HelperInput, HelperMessage};
use remote_device::{Frame, PixelFormat};
use remote_protocol::ControlMessage;

/// How long to keep trying to open the pipe before giving up.
///
/// The service answers the app's request with the pipe name, then launches the
/// helper, which then creates the pipe — so the pipe does not exist the
/// instant the app is told its name. A few seconds of retry covers that
/// hand-off; longer would be a helper that never started, which the app treats
/// as the Secure Desktop simply being unreachable.
const CONNECT_TIMEOUT: Duration = Duration::from_secs(5);

/// The gap between connection attempts.
const CONNECT_RETRY: Duration = Duration::from_millis(100);

/// Whether Windows on this computer lets a service raise Ctrl+Alt+Del.
///
/// `SendSAS` — the only way software raises the Secure Attention Sequence —
/// works for a service only where the "Disable or enable software Secure
/// Attention Sequence" policy says services may: `SoftwareSASGeneration`
/// under `HKLM\…\Policies\System`, `1` (services) or `3` (services and Ease
/// of Access applications). Unset, which is how Windows ships, means it does
/// nothing at all. This application reads the policy and does not write it:
/// changing what may raise the sequence on somebody's computer is a decision
/// for whoever runs that computer, and is recorded as such in the readiness
/// audit rather than made quietly by an installer.
///
/// Read so the controller can be told why nothing happened, instead of the
/// helper being asked for something Windows will silently drop.
#[must_use]
pub fn software_secure_attention_permitted() -> bool {
    #[cfg(target_os = "windows")]
    {
        use windows::core::w;
        use windows::Win32::System::Registry::{
            RegGetValueW, HKEY_LOCAL_MACHINE, RRF_RT_REG_DWORD,
        };

        let mut value = 0_u32;
        let mut size = std::mem::size_of::<u32>() as u32;

        // SAFETY: `value` and `size` are matched locals; `RegGetValueW` writes
        // at most `size` bytes of a DWORD, which is what the flag restricts
        // the read to.
        let result = unsafe {
            RegGetValueW(
                HKEY_LOCAL_MACHINE,
                w!(r"SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System"),
                w!("SoftwareSASGeneration"),
                RRF_RT_REG_DWORD,
                None,
                Some(std::ptr::addr_of_mut!(value).cast()),
                Some(&mut size),
            )
        };

        result.is_ok() && services_may_raise_secure_attention(value)
    }

    #[cfg(not(target_os = "windows"))]
    {
        false
    }
}

/// Which values of `SoftwareSASGeneration` let a service raise the sequence:
/// `1` is services, `3` is services and Ease of Access applications. `0` is
/// nobody and `2` is Ease of Access applications only.
#[cfg_attr(not(any(target_os = "windows", test)), allow(dead_code))]
fn services_may_raise_secure_attention(policy: u32) -> bool {
    matches!(policy, 1 | 3)
}

/// A live connection to a Secure Desktop helper.
pub struct SecureDesktopHelper {
    /// The helper's most recent `State { active }`.
    active: Arc<AtomicBool>,
    /// Cleared when the reader thread sees the pipe close.
    connected: Arc<AtomicBool>,
    /// The latest frame, and a counter bumped on each new one so the capture
    /// pump can tell a fresh frame from the one it already sent.
    latest: Arc<Mutex<Option<HelperFrame>>>,
    epoch: Arc<AtomicU64>,
    /// The write half of the pipe, for input/ping/stop. A clone of the same
    /// handle the reader thread reads from; a duplex pipe serves both.
    writer: Mutex<Box<dyn Write + Send>>,
    reader: Option<std::thread::JoinHandle<()>>,
}

impl SecureDesktopHelper {
    /// Connect to the helper's pipe, retrying until it exists or time runs out.
    pub fn connect(pipe_name: &str) -> std::io::Result<Self> {
        let deadline = Instant::now() + CONNECT_TIMEOUT;

        let read_handle = loop {
            match open_pipe(pipe_name) {
                Ok(handle) => break handle,
                Err(error) if Instant::now() >= deadline => return Err(error),
                Err(_) => std::thread::sleep(CONNECT_RETRY),
            }
        };

        // A second handle to the same pipe for writing, so the reader thread
        // can block on reads without holding the writer.
        let write_handle = read_handle.try_clone()?;

        let active = Arc::new(AtomicBool::new(false));
        let connected = Arc::new(AtomicBool::new(true));
        let latest = Arc::new(Mutex::new(None));
        let epoch = Arc::new(AtomicU64::new(0));

        let reader = {
            let active = Arc::clone(&active);
            let connected = Arc::clone(&connected);
            let latest = Arc::clone(&latest);
            let epoch = Arc::clone(&epoch);

            std::thread::Builder::new()
                .name("aicountly-secure-desktop".into())
                .spawn(move || read_loop(read_handle, &active, &connected, &latest, &epoch))
                .ok()
        };

        Ok(Self {
            active,
            connected,
            latest,
            epoch,
            writer: Mutex::new(Box::new(write_handle)),
            reader,
        })
    }

    /// Whether the Secure Desktop is in front, as the helper last reported.
    #[must_use]
    pub fn is_active(&self) -> bool {
        self.connected.load(Ordering::SeqCst) && self.active.load(Ordering::SeqCst)
    }

    /// Whether the pipe is still up.
    #[must_use]
    pub fn is_connected(&self) -> bool {
        self.connected.load(Ordering::SeqCst)
    }

    /// The current frame epoch — a value that changes whenever a new frame has
    /// arrived, so a caller can send each frame once.
    #[must_use]
    pub fn frame_epoch(&self) -> u64 {
        self.epoch.load(Ordering::SeqCst)
    }

    /// The latest frame as the encoder's [`Frame`], if there is one.
    #[must_use]
    pub fn latest_frame(&self) -> Option<Frame> {
        let held = self.latest.lock().ok()?;
        held.as_ref().and_then(frame_from)
    }

    /// Send one gate-admitted input to the helper. `false` if the pipe is gone.
    pub fn send_input(&self, input: HelperInput) -> bool {
        self.write(&HelperMessage::Input(input))
    }

    /// Tell the helper to stop and exit.
    pub fn stop(&self) {
        let _ = self.write(&HelperMessage::Stop);
    }

    fn write(&self, message: &HelperMessage) -> bool {
        let Ok(bytes) = message.encode() else {
            return false;
        };

        let Ok(mut writer) = self.writer.lock() else {
            return false;
        };

        if writer.write_all(&bytes).is_err() || writer.flush().is_err() {
            self.connected.store(false, Ordering::SeqCst);

            return false;
        }

        true
    }
}

impl Drop for SecureDesktopHelper {
    fn drop(&mut self) {
        // Ask the helper to exit, then let the reader thread finish as the pipe
        // closes. Best effort: a helper already gone makes the write a no-op.
        self.stop();
        self.connected.store(false, Ordering::SeqCst);

        if let Some(reader) = self.reader.take() {
            let _ = reader.join();
        }
    }
}

/// Open the pipe for reading and writing.
///
/// On Windows this opens the service's dedicated pipe; on any other host there
/// is no such pipe and the open fails, which is how the whole feature is
/// absent off Windows without a separate code path.
fn open_pipe(pipe_name: &str) -> std::io::Result<std::fs::File> {
    std::fs::OpenOptions::new()
        .read(true)
        .write(true)
        .open(pipe_name)
}

/// Read frames and state until the pipe closes.
fn read_loop(
    mut pipe: std::fs::File,
    active: &Arc<AtomicBool>,
    connected: &Arc<AtomicBool>,
    latest: &Arc<Mutex<Option<HelperFrame>>>,
    epoch: &Arc<AtomicU64>,
) {
    let mut header = [0_u8; HEADER_BYTES];

    loop {
        if pipe.read_exact(&mut header).is_err() {
            break;
        }

        let (kind, length) = match HelperMessage::decode_header(&header) {
            Ok(header) => header,
            // A malformed or mismatched frame is a broken peer: stop rather
            // than try to resynchronise a byte stream.
            Err(HelperProtocolError::Truncated) => continue,
            Err(_) => break,
        };

        let mut payload = vec![0_u8; length];
        if pipe.read_exact(&mut payload).is_err() {
            break;
        }

        match HelperMessage::decode_payload(kind, &payload) {
            Ok(HelperMessage::State { active: is_active }) => {
                active.store(is_active, Ordering::SeqCst);
            }
            Ok(HelperMessage::Frame(frame)) => {
                if let Ok(mut held) = latest.lock() {
                    *held = Some(frame);
                    epoch.fetch_add(1, Ordering::SeqCst);
                }
            }
            // Pong and anything app→helper arriving here is ignored.
            Ok(_) => {}
            Err(_) => break,
        }
    }

    connected.store(false, Ordering::SeqCst);
}

/// Turn a helper frame into the encoder's [`Frame`].
///
/// The helper's pixels are tight BGRA, top row first, so the stride is
/// `width * 4`. `Frame::new` re-checks that the bytes match the geometry and
/// returns `None` if they do not, so a malformed frame never reaches the
/// encoder.
#[must_use]
pub fn frame_from(frame: &HelperFrame) -> Option<Frame> {
    let stride = (frame.width as usize).checked_mul(4)?;
    let timestamp_us = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|elapsed| elapsed.as_micros() as u64)
        .unwrap_or_default();

    Frame::new(
        frame.width,
        frame.height,
        stride,
        PixelFormat::Bgra8,
        timestamp_us,
        frame.bgra.clone(),
    )
}

/// Turn a gate-admitted control message into a helper input, or `None` for a
/// message that is not input to forward.
///
/// The one place the app's control vocabulary becomes the helper's. Only the
/// five input messages map; a clipboard payload, a monitor selection, a reboot
/// or a ping is not something the Secure Desktop takes, and returns `None` so
/// the caller falls through to its ordinary handling.
#[must_use]
pub fn helper_input_from(message: &ControlMessage) -> Option<HelperInput> {
    match message {
        ControlMessage::MouseMove { position } => Some(HelperInput::MouseMove {
            position: *position,
        }),
        ControlMessage::MouseButton(event) => Some(HelperInput::MouseButton(*event)),
        ControlMessage::Scroll(event) => Some(HelperInput::Scroll(*event)),
        ControlMessage::Key(event) => Some(HelperInput::Key(*event)),
        // A relative move has no meaning on the Secure Desktop, which is a
        // single fixed surface the controller sees in full; drop it rather
        // than integrate deltas against a surface it cannot pointer-lock.
        _ => None,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Only the two settings that name services let the helper raise
    /// Ctrl+Alt+Del; unset reads as nothing at all and is refused before
    /// this is asked.
    #[test]
    fn only_a_policy_that_names_services_lets_ctrl_alt_del_be_raised() {
        assert!(!services_may_raise_secure_attention(0), "nobody");
        assert!(services_may_raise_secure_attention(1), "services");
        assert!(
            !services_may_raise_secure_attention(2),
            "Ease of Access only"
        );
        assert!(services_may_raise_secure_attention(3), "both");
        assert!(!services_may_raise_secure_attention(4), "not a setting");
    }
    use remote_protocol::{
        ClipboardDirection, ClipboardPayload, Key, KeyEvent, Modifiers, MouseButton, MouseEvent,
        PointerPosition, ScrollEvent,
    };

    #[test]
    fn a_well_formed_helper_frame_becomes_an_encoder_frame() {
        let frame = HelperFrame {
            width: 4,
            height: 2,
            bgra: vec![0x20; 4 * 2 * 4],
        };

        let encoded = frame_from(&frame).expect("a 4x2 BGRA frame is well formed");
        assert_eq!(encoded.width, 4);
        assert_eq!(encoded.height, 2);
        assert_eq!(encoded.stride, 16);
    }

    #[test]
    fn the_five_input_messages_map_and_nothing_else_does() {
        assert!(matches!(
            helper_input_from(&ControlMessage::MouseMove {
                position: PointerPosition { x: 0.5, y: 0.5 }
            }),
            Some(HelperInput::MouseMove { .. })
        ));
        assert!(matches!(
            helper_input_from(&ControlMessage::MouseButton(MouseEvent {
                button: MouseButton::Left,
                pressed: true,
                double: false,
                position: None,
            })),
            Some(HelperInput::MouseButton(_))
        ));
        assert!(matches!(
            helper_input_from(&ControlMessage::Scroll(ScrollEvent {
                delta_x: 0.0,
                delta_y: 1.0,
                position: None,
            })),
            Some(HelperInput::Scroll(_))
        ));
        assert!(matches!(
            helper_input_from(&ControlMessage::Key(KeyEvent {
                key: Key::Enter,
                pressed: true,
                modifiers: Modifiers::none(),
            })),
            Some(HelperInput::Key(_))
        ));

        // Not input to forward.
        assert!(
            helper_input_from(&ControlMessage::MouseMoveRelative { dx: 0.1, dy: 0.1 }).is_none()
        );
        assert!(helper_input_from(&ControlMessage::Ping { nonce: 1 }).is_none());
        assert!(
            helper_input_from(&ControlMessage::Clipboard(ClipboardPayload::text(
                "x",
                ClipboardDirection::ToHost
            )))
            .is_none()
        );
        assert!(helper_input_from(&ControlMessage::SecureDesktop {
            active: true,
            reachable: true
        })
        .is_none());
    }
}
