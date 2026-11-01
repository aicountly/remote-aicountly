//! The Secure Desktop helper process itself.
//!
//! Reached by `AicountlyRemoteService.exe --secure-desktop-helper <pipe>`,
//! which the service launches inside the console session on the
//! `winsta0\winlogon` desktop with a session-adjusted SYSTEM token (see
//! [`super::launch`]). It does one job and opens nothing else:
//!
//! 1. create the dedicated pipe — first instance only, remote clients
//!    rejected, a DACL granting only SYSTEM and this session's user — and wait
//!    for the app to connect;
//! 2. on its worker thread, **follow the input desktop**
//!    (`OpenInputDesktop` + `SetThreadDesktop`, re-checked every tick) and
//!    report `active` — true when the input desktop is not the user's
//!    `Default` desktop, i.e. the Secure Desktop is in front;
//! 3. while active, capture the input desktop with GDI into a DIB section,
//!    downscale to at most 1280 px at at most 10 fps, and send BGRA frames;
//! 4. inject the mouse and keyboard events the app sends, with `SendInput`
//!    from that same desktop-attached thread;
//! 5. exit when the app disconnects, when told to stop, or after a hard
//!    maximum lifetime — whichever comes first.
//!
//! Everything it *decides* — the downscale, the pacing, the coordinate and
//! key-code mapping, the wire protocol — is the pure code in the sibling
//! modules, tested on every host. This file is the Win32 that drives them.

use std::time::{Duration, Instant};

use windows::core::{Error, PCWSTR, PWSTR};
use windows::Win32::Foundation::{CloseHandle, LocalFree, E_FAIL, HANDLE, HLOCAL};
use windows::Win32::Graphics::Gdi::{
    BitBlt, CreateCompatibleDC, CreateDIBSection, DeleteDC, DeleteObject, GetDC, ReleaseDC,
    SelectObject, BITMAPINFO, BITMAPINFOHEADER, BI_RGB, CAPTUREBLT, DIB_RGB_COLORS, HBITMAP, HDC,
    HGDIOBJ, ROP_CODE, SRCCOPY,
};
use windows::Win32::Security::Authorization::{
    ConvertSidToStringSidW, ConvertStringSecurityDescriptorToSecurityDescriptorW, SDDL_REVISION_1,
};
use windows::Win32::Security::{
    GetTokenInformation, TokenUser, PSECURITY_DESCRIPTOR, SECURITY_ATTRIBUTES, TOKEN_USER,
};
use windows::Win32::Storage::FileSystem::{
    ReadFile, WriteFile, FILE_FLAGS_AND_ATTRIBUTES, FILE_FLAG_FIRST_PIPE_INSTANCE,
    PIPE_ACCESS_DUPLEX,
};
use windows::Win32::System::Pipes::{
    ConnectNamedPipe, CreateNamedPipeW, DisconnectNamedPipe, PeekNamedPipe, PIPE_READMODE_BYTE,
    PIPE_REJECT_REMOTE_CLIENTS, PIPE_TYPE_BYTE, PIPE_WAIT,
};
use windows::Win32::System::RemoteDesktop::{WTSGetActiveConsoleSessionId, WTSQueryUserToken};
use windows::Win32::System::StationsAndDesktops::{
    CloseDesktop, GetUserObjectInformationW, OpenInputDesktop, SetThreadDesktop, HDESK, UOI_NAME,
};
use windows::Win32::UI::Input::KeyboardAndMouse::{
    SendInput, INPUT, INPUT_0, INPUT_KEYBOARD, INPUT_MOUSE, KEYBDINPUT, KEYBD_EVENT_FLAGS,
    KEYEVENTF_EXTENDEDKEY, KEYEVENTF_KEYUP, KEYEVENTF_UNICODE, MOUSEEVENTF_ABSOLUTE,
    MOUSEEVENTF_HWHEEL, MOUSEEVENTF_LEFTDOWN, MOUSEEVENTF_LEFTUP, MOUSEEVENTF_MIDDLEDOWN,
    MOUSEEVENTF_MIDDLEUP, MOUSEEVENTF_MOVE, MOUSEEVENTF_RIGHTDOWN, MOUSEEVENTF_RIGHTUP,
    MOUSEEVENTF_VIRTUALDESK, MOUSEEVENTF_WHEEL, MOUSEINPUT, MOUSE_EVENT_FLAGS, VIRTUAL_KEY,
};
use windows::Win32::UI::WindowsAndMessaging::{
    GetSystemMetrics, SM_CXVIRTUALSCREEN, SM_CYVIRTUALSCREEN, SM_XVIRTUALSCREEN, SM_YVIRTUALSCREEN,
};

use remote_protocol::{Key, MouseButton};

use super::frame::{self, FramePacer, MAX_HELPER_FPS, MAX_HELPER_WIDTH};
use super::inject;
use super::protocol::{HelperFrame, HelperInput, HelperMessage, HelperProtocolError, HEADER_BYTES};

/// The longest a helper runs before exiting on its own.
///
/// A Secure Desktop helper that somehow outlived its session — the app gone
/// without a clean disconnect, the service unable to reach it — is exactly
/// what must not linger, so it stops itself after this even if nothing told it
/// to. A real prompt is answered in seconds to minutes; half an hour is far
/// past any legitimate need.
const MAX_LIFETIME: Duration = Duration::from_secs(30 * 60);

/// How often the worker wakes to read input and re-check the desktop.
const TICK: Duration = Duration::from_millis(10);

/// The user's ordinary desktop. Anything else being the input desktop means a
/// Secure Desktop (UAC, Winlogon, the lock screen) is in front.
const DEFAULT_DESKTOP: &str = "Default";

/// A NUL-terminated wide string.
fn wide(value: &str) -> Vec<u16> {
    value.encode_utf16().chain(std::iter::once(0)).collect()
}

/// Run the helper to completion. Returns when the app disconnects, a stop is
/// received, or the lifetime cap is reached.
pub fn run(pipe_name: &str) -> Result<(), Error> {
    let session = unsafe { WTSGetActiveConsoleSessionId() };
    let sddl = pipe_sddl(session)?;

    let security = SecurityDescriptor::build(&sddl)?;
    let pipe = create_pipe(pipe_name, &security)?;

    // Wait for the app. `ERROR_PIPE_CONNECTED` means it beat us here, which is
    // a connection, not a failure.
    // SAFETY: `pipe` is the server handle just created and owned by `pipe`.
    if let Err(error) = unsafe { ConnectNamedPipe(pipe.0, None) } {
        use windows::Win32::Foundation::ERROR_PIPE_CONNECTED;
        if error.code() != windows::core::HRESULT::from_win32(ERROR_PIPE_CONNECTED.0) {
            return Err(error);
        }
    }

    serve(&pipe)
}

/// The SDDL for the dedicated pipe: SYSTEM full control, this session's user
/// read and write, and nobody else.
fn pipe_sddl(session: u32) -> Result<String, Error> {
    let sid = console_user_sid(session)?;

    // `(A;;GA;;;SY)` LocalSystem (the helper). `(A;;GRGW;;;<sid>)` this
    // session's user (the app). No entry for anyone else, and the pipe is
    // created with `PIPE_REJECT_REMOTE_CLIENTS` so it is not reachable over
    // SMB either.
    Ok(format!("D:(A;;GA;;;SY)(A;;GRGW;;;{sid})"))
}

/// The string SID of the user logged on at the given session.
fn console_user_sid(session: u32) -> Result<String, Error> {
    // SAFETY: WTSQueryUserToken needs SeTcbPrivilege, which this SYSTEM helper
    // holds; the token handle is closed on every path.
    unsafe {
        let mut token = HANDLE::default();
        WTSQueryUserToken(session, &mut token)?;

        // Query the size, then the TOKEN_USER itself.
        let mut needed = 0_u32;
        let _ = GetTokenInformation(token, TokenUser, None, 0, &mut needed);

        let mut buffer = vec![0_u8; needed as usize];
        let got = GetTokenInformation(
            token,
            TokenUser,
            Some(buffer.as_mut_ptr() as *mut core::ffi::c_void),
            needed,
            &mut needed,
        );

        if let Err(error) = got {
            let _ = CloseHandle(token);

            return Err(error);
        }

        // The SID sits at the front of the returned TOKEN_USER.
        let token_user = &*(buffer.as_ptr() as *const TOKEN_USER);
        let sid = token_user.User.Sid;

        let mut string_sid = PWSTR::null();
        let converted = ConvertSidToStringSidW(sid, &mut string_sid);

        let _ = CloseHandle(token);
        converted?;

        let text = string_sid
            .to_string()
            .map_err(|_| Error::from_hresult(E_FAIL));
        let _ = LocalFree(Some(HLOCAL(string_sid.0 as *mut core::ffi::c_void)));

        text
    }
}

/// A security descriptor Windows allocated, freed on drop.
struct SecurityDescriptor {
    descriptor: PSECURITY_DESCRIPTOR,
}

impl SecurityDescriptor {
    fn build(sddl: &str) -> Result<Self, Error> {
        let text = wide(sddl);
        let mut descriptor = PSECURITY_DESCRIPTOR::default();

        // SAFETY: `text` is NUL-terminated and outlives the call; Windows
        // allocates the descriptor, which this type frees in `Drop`.
        unsafe {
            ConvertStringSecurityDescriptorToSecurityDescriptorW(
                PCWSTR(text.as_ptr()),
                SDDL_REVISION_1,
                &mut descriptor,
                None,
            )?;
        }

        Ok(Self { descriptor })
    }

    fn attributes(&self) -> SECURITY_ATTRIBUTES {
        SECURITY_ATTRIBUTES {
            nLength: std::mem::size_of::<SECURITY_ATTRIBUTES>() as u32,
            lpSecurityDescriptor: self.descriptor.0,
            bInheritHandle: false.into(),
        }
    }
}

impl Drop for SecurityDescriptor {
    fn drop(&mut self) {
        if !self.descriptor.is_invalid() {
            // SAFETY: allocated by ConvertStringSecurityDescriptor…, whose
            // documented release is LocalFree.
            unsafe {
                let _ = LocalFree(Some(HLOCAL(self.descriptor.0)));
            }
        }
    }
}

/// An owned pipe-server handle that disconnects and closes on drop.
struct Pipe(HANDLE);

impl Drop for Pipe {
    fn drop(&mut self) {
        // SAFETY: created by CreateNamedPipeW and owned here.
        unsafe {
            let _ = DisconnectNamedPipe(self.0);
            let _ = CloseHandle(self.0);
        }
    }
}

/// Create the dedicated pipe as the first (and only) instance.
fn create_pipe(pipe_name: &str, security: &SecurityDescriptor) -> Result<Pipe, Error> {
    let name = wide(pipe_name);
    let attributes = security.attributes();

    // SAFETY: `name` is NUL-terminated and `attributes` borrows a descriptor
    // that outlives the call. `FILE_FLAG_FIRST_PIPE_INSTANCE` makes creation
    // fail rather than succeed if the name is already taken, which is what
    // stops a same-session process from squatting it.
    let handle = unsafe {
        CreateNamedPipeW(
            PCWSTR(name.as_ptr()),
            FILE_FLAGS_AND_ATTRIBUTES(PIPE_ACCESS_DUPLEX.0) | FILE_FLAG_FIRST_PIPE_INSTANCE,
            PIPE_TYPE_BYTE | PIPE_READMODE_BYTE | PIPE_WAIT | PIPE_REJECT_REMOTE_CLIENTS,
            1,
            super::protocol::MAX_FRAME_BYTES as u32,
            super::protocol::MAX_CONTROL_BYTES as u32,
            0,
            Some(&attributes),
        )
    };

    if handle.is_invalid() {
        return Err(Error::from_thread());
    }

    Ok(Pipe(handle))
}

/// The worker loop: follow the desktop, capture, inject, until done.
fn serve(pipe: &Pipe) -> Result<(), Error> {
    let started = Instant::now();
    let mut pacer = FramePacer::new(MAX_HELPER_FPS);
    let mut capturer = Capturer::default();
    let mut desktop = InputDesktop::default();
    let mut reported_active: Option<bool> = None;
    let mut read_buffer: Vec<u8> = Vec::new();

    loop {
        if started.elapsed() >= MAX_LIFETIME {
            return Ok(());
        }

        // Follow the input desktop onto this thread, so both the capture
        // (GetDC) and the injection (SendInput) act on whatever is in front —
        // the Secure Desktop when one is up.
        let active = desktop.follow();

        if reported_active != Some(active) {
            reported_active = Some(active);

            if write_message(pipe.0, &HelperMessage::State { active }).is_err() {
                return Ok(()); // the app went away
            }

            // Leaving the Secure Desktop means the capture surface is gone.
            if !active {
                capturer.release();
            }
        }

        // Drain whatever the app has sent, acting on each.
        match drain_input(pipe.0, &mut read_buffer) {
            Ok(messages) => {
                for message in messages {
                    match message {
                        HelperMessage::Input(input) => inject(input),
                        HelperMessage::Ping => {
                            if write_message(pipe.0, &HelperMessage::Pong).is_err() {
                                return Ok(());
                            }
                        }
                        HelperMessage::Stop => return Ok(()),
                        // Helper→app kinds never arrive here; ignore rather
                        // than trust a peer that sent one.
                        _ => {}
                    }
                }
            }
            // A read error is the app gone or a broken frame: either way this
            // helper's reason to exist is over.
            Err(()) => return Ok(()),
        }

        let now_ms = started.elapsed().as_millis() as u64;

        if active && pacer.due(now_ms) {
            if let Some(frame) = capturer.capture() {
                if write_message(pipe.0, &HelperMessage::Frame(frame)).is_err() {
                    return Ok(());
                }
            }
        }

        std::thread::sleep(TICK);
    }
}

/// Follows the input desktop onto the calling thread.
#[derive(Default)]
struct InputDesktop {
    current: Option<HDESK>,
}

impl InputDesktop {
    /// Attach this thread to the input desktop and report whether it is a
    /// Secure Desktop (anything but `Default`).
    fn follow(&mut self) -> bool {
        use windows::Win32::System::StationsAndDesktops::{
            DESKTOP_ACCESS_FLAGS, DESKTOP_CREATEWINDOW, DESKTOP_ENUMERATE, DESKTOP_HOOKCONTROL,
            DESKTOP_JOURNALPLAYBACK, DESKTOP_JOURNALRECORD, DESKTOP_READOBJECTS,
            DESKTOP_SWITCHDESKTOP, DESKTOP_WRITEOBJECTS,
        };

        // SAFETY: OpenInputDesktop takes no pointers and returns a handle the
        // caller owns; a failure (the Secure Desktop can momentarily refuse
        // the open during a switch) leaves the thread where it was.
        let next = unsafe {
            OpenInputDesktop(
                Default::default(),
                false,
                // `DESKTOP_ACCESS_FLAGS` has no `BitOr` in windows 0.62, so
                // the rights are combined on the raw values.
                DESKTOP_ACCESS_FLAGS(
                    DESKTOP_READOBJECTS.0
                        | DESKTOP_WRITEOBJECTS.0
                        | DESKTOP_CREATEWINDOW.0
                        | DESKTOP_ENUMERATE.0
                        | DESKTOP_HOOKCONTROL.0
                        | DESKTOP_JOURNALPLAYBACK.0
                        | DESKTOP_JOURNALRECORD.0
                        | DESKTOP_SWITCHDESKTOP.0,
                ),
            )
        };

        let Ok(next) = next else {
            // Could not open the input desktop. Report the last known state
            // rather than guessing; the next tick tries again.
            return self.current.is_some() && self.active_name_is_secure();
        };

        // SAFETY: `next` is a live desktop handle this thread is about to take.
        if unsafe { SetThreadDesktop(next) }.is_err() {
            // SAFETY: `next` is the handle just opened and not retained.
            unsafe {
                let _ = CloseDesktop(next);
            }

            return self.current.is_some() && self.active_name_is_secure();
        }

        // The thread is now on `next`; the previous handle can be released.
        if let Some(previous) = self.current.replace(next) {
            // SAFETY: the thread left `previous` for `next`, so it is no longer
            // in use by this thread and may be closed.
            unsafe {
                let _ = CloseDesktop(previous);
            }
        }

        self.active_name_is_secure()
    }

    /// Whether the current desktop's name is something other than `Default`.
    fn active_name_is_secure(&self) -> bool {
        let Some(desktop) = self.current else {
            return false;
        };

        let mut buffer = [0_u16; 256];
        let mut needed = 0_u32;

        // SAFETY: `desktop` is a live handle; `buffer` backs the name and
        // `needed` receives the byte count written.
        let read = unsafe {
            GetUserObjectInformationW(
                HANDLE(desktop.0),
                UOI_NAME,
                Some(buffer.as_mut_ptr() as *mut core::ffi::c_void),
                (buffer.len() * 2) as u32,
                Some(&mut needed),
            )
        };

        if read.is_err() {
            return false;
        }

        let name = String::from_utf16_lossy(&buffer)
            .trim_end_matches('\0')
            .to_owned();

        !name.eq_ignore_ascii_case(DEFAULT_DESKTOP)
    }
}

impl Drop for InputDesktop {
    fn drop(&mut self) {
        if let Some(desktop) = self.current.take() {
            // SAFETY: owned here; the thread is ending so it is not in use.
            unsafe {
                let _ = CloseDesktop(desktop);
            }
        }
    }
}

/// A GDI capturer of the whole virtual desktop into a DIB section.
#[derive(Default)]
struct Capturer {
    surface: Option<Surface>,
}

/// One sized DIB and the DCs to blit into it.
struct Surface {
    width: i32,
    height: i32,
    left: i32,
    top: i32,
    screen: HDC,
    memory: HDC,
    bitmap: HBITMAP,
    previous: HGDIOBJ,
    bits: *mut core::ffi::c_void,
}

impl Capturer {
    /// Capture one frame, (re)building the surface if the virtual desktop
    /// changed size.
    fn capture(&mut self) -> Option<HelperFrame> {
        let (left, top, width, height) = virtual_desktop();
        if width <= 0 || height <= 0 {
            return None;
        }

        let matches = self.surface.as_ref().is_some_and(|s| {
            s.width == width && s.height == height && s.left == left && s.top == top
        });

        if !matches {
            self.release();
            self.surface = Surface::build(left, top, width, height);
        }

        let surface = self.surface.as_ref()?;

        // SAFETY: the DCs and bitmap are live for `surface`'s lifetime, and the
        // blit copies the whole virtual desktop into the DIB whose bits
        // `surface.bits` points at.
        unsafe {
            BitBlt(
                surface.memory,
                0,
                0,
                width,
                height,
                Some(surface.screen),
                left,
                top,
                // `SRCCOPY | CAPTUREBLT`: CAPTUREBLT pulls in layered windows,
                // which the logon and UAC UI are drawn with.
                ROP_CODE(SRCCOPY.0 | CAPTUREBLT.0),
            )
            .ok()?;
        }

        let stride = (width as usize) * 4;
        let byte_len = stride * height as usize;

        // SAFETY: CreateDIBSection allocated `byte_len` bytes at `surface.bits`
        // for a top-down 32-bpp bitmap; this reads exactly that many.
        let pixels = unsafe { std::slice::from_raw_parts(surface.bits as *const u8, byte_len) };

        frame::downscale_bgra(
            pixels,
            width as u32,
            height as u32,
            stride,
            MAX_HELPER_WIDTH,
        )
    }

    /// Drop the surface (on a size change, or when the Secure Desktop closes).
    fn release(&mut self) {
        self.surface = None;
    }
}

impl Surface {
    fn build(left: i32, top: i32, width: i32, height: i32) -> Option<Surface> {
        // SAFETY: GetDC(NULL) returns the screen DC; CreateCompatibleDC and
        // CreateDIBSection build a 32-bpp top-down DIB the blit targets. Every
        // object is released in `Drop`. A negative height makes the DIB
        // top-down, so row 0 is the top of the screen — which is what the
        // frame protocol and the encoder expect.
        unsafe {
            let screen = GetDC(None);
            if screen.is_invalid() {
                return None;
            }

            let memory = CreateCompatibleDC(Some(screen));
            if memory.is_invalid() {
                let _ = ReleaseDC(None, screen);
                return None;
            }

            let info = BITMAPINFO {
                bmiHeader: BITMAPINFOHEADER {
                    biSize: std::mem::size_of::<BITMAPINFOHEADER>() as u32,
                    biWidth: width,
                    biHeight: -height, // top-down
                    biPlanes: 1,
                    biBitCount: 32,
                    biCompression: BI_RGB.0,
                    ..Default::default()
                },
                ..Default::default()
            };

            let mut bits: *mut core::ffi::c_void = std::ptr::null_mut();
            let bitmap =
                match CreateDIBSection(Some(memory), &info, DIB_RGB_COLORS, &mut bits, None, 0) {
                    Ok(bitmap) if !bitmap.is_invalid() && !bits.is_null() => bitmap,
                    _ => {
                        let _ = DeleteDC(memory);
                        let _ = ReleaseDC(None, screen);
                        return None;
                    }
                };

            let previous = SelectObject(memory, HGDIOBJ(bitmap.0));

            Some(Surface {
                width,
                height,
                left,
                top,
                screen,
                memory,
                bitmap,
                previous,
                bits,
            })
        }
    }
}

impl Drop for Surface {
    fn drop(&mut self) {
        // SAFETY: each object was created above and is released once here, in
        // the reverse order it was made.
        unsafe {
            let _ = SelectObject(self.memory, self.previous);
            let _ = DeleteObject(HGDIOBJ(self.bitmap.0));
            let _ = DeleteDC(self.memory);
            let _ = ReleaseDC(None, self.screen);
        }
    }
}

/// The virtual desktop rectangle: origin and size across every monitor.
fn virtual_desktop() -> (i32, i32, i32, i32) {
    // SAFETY: GetSystemMetrics takes no pointers and cannot fail.
    unsafe {
        (
            GetSystemMetrics(SM_XVIRTUALSCREEN),
            GetSystemMetrics(SM_YVIRTUALSCREEN),
            GetSystemMetrics(SM_CXVIRTUALSCREEN),
            GetSystemMetrics(SM_CYVIRTUALSCREEN),
        )
    }
}

// ----------------------------------------------------------------- injection

/// Inject one event. Best effort: a refusal (a window at higher integrity, say)
/// is dropped rather than retried, exactly as the app's own path does.
fn inject(input: HelperInput) {
    match input {
        HelperInput::MouseMove { position } => {
            let (x, y) = inject::to_absolute(position);
            send(&[mouse(
                MOUSEEVENTF_MOVE | MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_VIRTUALDESK,
                x,
                y,
                0,
            )]);
        }
        HelperInput::MouseButton(event) => {
            let mut inputs = Vec::with_capacity(5);

            if let Some(position) = event.position {
                let (x, y) = inject::to_absolute(position);
                inputs.push(mouse(
                    MOUSEEVENTF_MOVE | MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_VIRTUALDESK,
                    x,
                    y,
                    0,
                ));
            }

            let (down, up) = match event.button {
                MouseButton::Left => (MOUSEEVENTF_LEFTDOWN, MOUSEEVENTF_LEFTUP),
                MouseButton::Right => (MOUSEEVENTF_RIGHTDOWN, MOUSEEVENTF_RIGHTUP),
                MouseButton::Middle => (MOUSEEVENTF_MIDDLEDOWN, MOUSEEVENTF_MIDDLEUP),
            };

            if event.double {
                inputs.push(mouse(down, 0, 0, 0));
                inputs.push(mouse(up, 0, 0, 0));
                inputs.push(mouse(down, 0, 0, 0));
                inputs.push(mouse(up, 0, 0, 0));
            } else {
                inputs.push(mouse(if event.pressed { down } else { up }, 0, 0, 0));
            }

            send(&inputs);
        }
        HelperInput::Scroll(event) => {
            let mut inputs = Vec::with_capacity(3);

            if let Some(position) = event.position {
                let (x, y) = inject::to_absolute(position);
                inputs.push(mouse(
                    MOUSEEVENTF_MOVE | MOUSEEVENTF_ABSOLUTE | MOUSEEVENTF_VIRTUALDESK,
                    x,
                    y,
                    0,
                ));
            }

            if event.delta_y != 0.0 {
                inputs.push(mouse(
                    MOUSEEVENTF_WHEEL,
                    0,
                    0,
                    -inject::wheel_delta(event.delta_y),
                ));
            }
            if event.delta_x != 0.0 {
                inputs.push(mouse(
                    MOUSEEVENTF_HWHEEL,
                    0,
                    0,
                    inject::wheel_delta(event.delta_x),
                ));
            }

            if !inputs.is_empty() {
                send(&inputs);
            }
        }
        HelperInput::Key(event) => inject_key(event.key, event.pressed),
        HelperInput::SecureAttention => send_sas(),
    }
}

fn inject_key(key: Key, pressed: bool) {
    let mut flags = KEYBD_EVENT_FLAGS(0);
    if !pressed {
        flags |= KEYEVENTF_KEYUP;
    }

    if let Key::Character(character) = key {
        // Unicode: the character the controller intends arrives whatever the
        // Secure Desktop's keyboard layout is.
        let mut units = [0_u16; 2];
        let encoded = character.encode_utf16(&mut units);
        let inputs: Vec<INPUT> = encoded
            .iter()
            .map(|unit| INPUT {
                r#type: INPUT_KEYBOARD,
                Anonymous: INPUT_0 {
                    ki: KEYBDINPUT {
                        wVk: VIRTUAL_KEY(0),
                        wScan: *unit,
                        dwFlags: flags | KEYEVENTF_UNICODE,
                        time: 0,
                        dwExtraInfo: 0,
                    },
                },
            })
            .collect();

        send(&inputs);
        return;
    }

    let Some(code) = inject::virtual_key(key) else {
        return;
    };

    if inject::is_extended(key) {
        flags |= KEYEVENTF_EXTENDEDKEY;
    }

    send(&[INPUT {
        r#type: INPUT_KEYBOARD,
        Anonymous: INPUT_0 {
            ki: KEYBDINPUT {
                wVk: VIRTUAL_KEY(code),
                wScan: 0,
                dwFlags: flags,
                time: 0,
                dwExtraInfo: 0,
            },
        },
    }]);
}

fn mouse(flags: MOUSE_EVENT_FLAGS, x: i32, y: i32, data: i32) -> INPUT {
    INPUT {
        r#type: INPUT_MOUSE,
        Anonymous: INPUT_0 {
            mi: MOUSEINPUT {
                dx: x,
                dy: y,
                mouseData: data as u32,
                dwFlags: flags,
                time: 0,
                dwExtraInfo: 0,
            },
        },
    }
}

fn send(inputs: &[INPUT]) {
    if inputs.is_empty() {
        return;
    }

    // SAFETY: `inputs` is a live slice of correctly initialised INPUT values
    // and the size argument matches the type. A short return means Windows
    // refused some — dropped, not retried.
    unsafe {
        let _ = SendInput(inputs, std::mem::size_of::<INPUT>() as i32);
    }
}

/// Raise the Secure Attention Sequence (Ctrl+Alt+Del) through `sas.dll`.
///
/// Loaded dynamically rather than linked: `SendSAS` works for a service only
/// when the `SoftwareSASGeneration` policy permits services to, and this does
/// not change that policy. If the export cannot be reached or the policy
/// forbids it, nothing happens — which is the honest outcome, not a silent
/// policy edit.
fn send_sas() {
    use windows::core::s;
    use windows::Win32::System::LibraryLoader::{GetProcAddress, LoadLibraryA};

    // SAFETY: a library load and symbol lookup; the function pointer is called
    // only if both succeed and has the documented `void SendSAS(BOOL)` shape.
    unsafe {
        let Ok(module) = LoadLibraryA(s!("sas.dll")) else {
            return;
        };

        if let Some(symbol) = GetProcAddress(module, s!("SendSAS")) {
            let send_sas: extern "system" fn(windows::core::BOOL) = std::mem::transmute(symbol);
            send_sas(false.into());
        }
    }
}

// ------------------------------------------------------------- pipe transport

/// Read every whole message the app has queued, without blocking on a partial.
fn drain_input(handle: HANDLE, buffer: &mut Vec<u8>) -> Result<Vec<HelperMessage>, ()> {
    let available = peek(handle)?;
    if available == 0 {
        return Ok(Vec::new());
    }

    // Read exactly what is available into the running buffer, then peel whole
    // frames off the front.
    let start = buffer.len();
    buffer.resize(start + available as usize, 0);

    let mut read = 0_u32;
    // SAFETY: `handle` is the connected pipe and the slice is owned here.
    let ok = unsafe { ReadFile(handle, Some(&mut buffer[start..]), Some(&mut read), None) }.is_ok();
    if !ok {
        return Err(());
    }
    buffer.truncate(start + read as usize);

    let mut messages = Vec::new();
    let mut offset = 0;

    loop {
        if buffer.len() - offset < HEADER_BYTES {
            break;
        }

        let (kind, length) = match HelperMessage::decode_header(&buffer[offset..]) {
            Ok(header) => header,
            // A malformed frame on this pipe is a broken or hostile peer; end
            // the helper rather than try to resynchronise a byte stream.
            Err(HelperProtocolError::Truncated) => break,
            Err(_) => return Err(()),
        };

        if buffer.len() - offset < HEADER_BYTES + length {
            break; // the rest has not arrived yet
        }

        let payload = &buffer[offset + HEADER_BYTES..offset + HEADER_BYTES + length];
        match HelperMessage::decode_payload(kind, payload) {
            Ok(message) => messages.push(message),
            Err(_) => return Err(()),
        }

        offset += HEADER_BYTES + length;
    }

    // Drop what was consumed; keep any partial tail for the next read.
    buffer.drain(..offset);

    Ok(messages)
}

/// How many bytes are queued, or `Err` when the client has gone.
fn peek(handle: HANDLE) -> Result<u32, ()> {
    let mut available = 0_u32;

    // SAFETY: `handle` is a connected pipe and `available` is a local.
    let result = unsafe { PeekNamedPipe(handle, None, 0, None, Some(&mut available), None) };

    result.map(|()| available).map_err(|_| ())
}

/// Write one framed message.
fn write_message(handle: HANDLE, message: &HelperMessage) -> Result<(), ()> {
    let bytes = message.encode().map_err(|_| ())?;
    let mut written = 0_u32;

    // SAFETY: `bytes` outlives the call and `handle` is the connected pipe.
    unsafe { WriteFile(handle, Some(&bytes), Some(&mut written), None) }.map_err(|_| ())?;

    Ok(())
}
