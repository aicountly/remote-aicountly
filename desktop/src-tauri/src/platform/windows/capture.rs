//! Screen capture, through `Windows.Graphics.Capture`.
//!
//! # Why this API and not the older ones
//!
//! `BitBlt` from a screen DC copies the desktop through GDI: it costs a full
//! readback per frame, misses hardware-accelerated and protected surfaces, and
//! at 1080p30 it is enough CPU to be noticeable on the machine somebody is
//! trying to work on. `DXGI` Desktop Duplication is much better but is a
//! whole-output API with its own device-lost handling, and it cannot capture a
//! single window.
//!
//! `Windows.Graphics.Capture` is the API Windows itself uses for the
//! screenshot tool and for Teams. It is GPU-side, it delivers **on change**
//! rather than on a clock — so a still desktop costs nothing — it handles
//! resolution changes, DPI and hot-plug, and it can capture either a monitor
//! or a window. It needs Windows 10 1903 for the monitor path, comfortably
//! below the 22H2 floor this product supports.
//!
//! # Frames are never stored
//!
//! A [`Frame`] is produced, handed to the encoder and dropped. Nothing here
//! writes one to disk, sends one to the API, or keeps one — and there is no
//! method that would let a caller do so.
//!
//! # The Secure Desktop
//!
//! Windows isolates the Secure Desktop — the UAC prompt, Ctrl+Alt+Del, and the
//! sign-in screen — from every desktop application. `Windows.Graphics.Capture`
//! keeps delivering frames of the *user's* desktop underneath it, so a remote
//! viewer sees the last ordinary frame while the person at the machine sees a
//! prompt. That is confusing rather than dangerous, and it is confusing in a
//! way the agent can detect and say out loud: see [`SecureDesktopState`].

use std::sync::atomic::{AtomicBool, Ordering};

use remote_device::{CaptureProfile, Frame, PlatformResult, ScreenCaptureProvider};
// Only the refusals a host without an implementation returns need it.
#[cfg(not(target_os = "windows"))]
use remote_device::PlatformError;
use remote_protocol::MonitorLayout;

// The arithmetic itself is plain numbers, so it lives beside the platform
// modules rather than inside this one — see `platform::display`.
pub use crate::platform::display::{
    describe_monitor, orientation_from_windows, scale_from_dpi, SecureDesktopState,
};

/// Capture, through `Windows.Graphics.Capture`.
pub struct WindowsCapture {
    running: AtomicBool,
    profile: CaptureProfile,
    monitor_id: u32,
    #[cfg(target_os = "windows")]
    session: Option<imp::CaptureSession>,
}

impl Default for WindowsCapture {
    fn default() -> Self {
        Self::new()
    }
}

impl WindowsCapture {
    /// A capture that has not started.
    #[must_use]
    pub fn new() -> Self {
        Self {
            running: AtomicBool::new(false),
            profile: CaptureProfile::adaptive(),
            monitor_id: 0,
            #[cfg(target_os = "windows")]
            session: None,
        }
    }

    /// Whether the Secure Desktop is in front right now.
    #[must_use]
    pub fn secure_desktop_state(&self) -> SecureDesktopState {
        #[cfg(target_os = "windows")]
        {
            imp::secure_desktop_state()
        }

        #[cfg(not(target_os = "windows"))]
        {
            SecureDesktopState::UserDesktop
        }
    }

    /// The profile currently in force.
    #[must_use]
    pub fn profile(&self) -> CaptureProfile {
        self.profile
    }
}

impl ScreenCaptureProvider for WindowsCapture {
    fn monitors(&self) -> PlatformResult<MonitorLayout> {
        #[cfg(target_os = "windows")]
        {
            imp::enumerate_monitors()
        }

        #[cfg(not(target_os = "windows"))]
        {
            Err(PlatformError::Unsupported("Reading the display layout"))
        }
    }

    fn start(&mut self, monitor_id: u32, profile: CaptureProfile) -> PlatformResult<()> {
        // Starting an already-running capture on a different monitor is a
        // monitor switch, not an error: the controller asked for a different
        // screen and the honest response is to give them one.
        if self.running.load(Ordering::SeqCst) && self.monitor_id == monitor_id {
            return self.reconfigure(profile);
        }

        self.stop()?;

        #[cfg(target_os = "windows")]
        {
            self.session = Some(imp::CaptureSession::start(monitor_id, profile)?);
        }

        #[cfg(not(target_os = "windows"))]
        {
            let _ = (monitor_id, profile);

            Err(PlatformError::Unsupported("Screen capture"))
        }

        #[cfg(target_os = "windows")]
        {
            self.monitor_id = monitor_id;
            self.profile = profile;
            self.running.store(true, Ordering::SeqCst);

            Ok(())
        }
    }

    fn stop(&mut self) -> PlatformResult<()> {
        // Idempotent, and it must be: this runs on every teardown path,
        // including the ones that got there because something else failed.
        self.running.store(false, Ordering::SeqCst);

        #[cfg(target_os = "windows")]
        {
            if let Some(session) = self.session.take() {
                session.close();
            }
        }

        Ok(())
    }

    fn next_frame(&mut self) -> PlatformResult<Option<Frame>> {
        if !self.running.load(Ordering::SeqCst) {
            return Ok(None);
        }

        #[cfg(target_os = "windows")]
        {
            match self.session.as_mut() {
                Some(session) => session.next_frame(self.profile),
                None => Ok(None),
            }
        }

        #[cfg(not(target_os = "windows"))]
        {
            Err(PlatformError::Unsupported("Screen capture"))
        }
    }

    fn reconfigure(&mut self, profile: CaptureProfile) -> PlatformResult<()> {
        self.profile = profile;

        #[cfg(target_os = "windows")]
        {
            if let Some(session) = self.session.as_mut() {
                session.reconfigure(profile)?;
            }
        }

        Ok(())
    }

    fn is_running(&self) -> bool {
        self.running.load(Ordering::SeqCst)
    }
}

#[cfg(target_os = "windows")]
mod imp {
    //! The Win32 and WinRT half. Compiled only on Windows.

    use super::{describe_monitor, orientation_from_windows, SecureDesktopState};
    use remote_device::{CaptureProfile, Frame, PixelFormat, PlatformError, PlatformResult};
    use remote_protocol::MonitorLayout;
    use std::sync::atomic::{AtomicU64, Ordering};
    use windows::core::Interface;
    use windows::core::BOOL;
    use windows::Graphics::Capture::{
        Direct3D11CaptureFrame, Direct3D11CaptureFramePool, GraphicsCaptureItem,
        GraphicsCaptureSession,
    };
    use windows::Graphics::DirectX::DirectXPixelFormat;
    use windows::Graphics::SizeInt32;
    use windows::Win32::Foundation::{HANDLE, HWND, LPARAM, RECT};
    use windows::Win32::Graphics::Direct3D::D3D_DRIVER_TYPE_HARDWARE;
    use windows::Win32::Graphics::Direct3D11::{
        D3D11CreateDevice, ID3D11Device, ID3D11DeviceContext, ID3D11Texture2D,
        D3D11_CPU_ACCESS_READ, D3D11_CREATE_DEVICE_BGRA_SUPPORT, D3D11_MAPPED_SUBRESOURCE,
        D3D11_MAP_READ, D3D11_SDK_VERSION, D3D11_TEXTURE2D_DESC, D3D11_USAGE_STAGING,
    };
    use windows::Win32::Graphics::Dxgi::IDXGIDevice;
    use windows::Win32::Graphics::Gdi::{
        EnumDisplayMonitors, GetMonitorInfoW, HDC, HMONITOR, MONITORINFOEXW,
    };
    use windows::Win32::System::StationsAndDesktops::{
        CloseDesktop, OpenInputDesktop, DESKTOP_READOBJECTS,
    };
    use windows::Win32::System::WinRT::Direct3D11::CreateDirect3D11DeviceFromDXGIDevice;
    use windows::Win32::System::WinRT::Graphics::Capture::IGraphicsCaptureItemInterop;
    use windows::Win32::UI::HiDpi::{GetDpiForMonitor, MDT_EFFECTIVE_DPI};
    use windows::Win32::UI::WindowsAndMessaging::{GetForegroundWindow, MONITORINFOF_PRIMARY};

    /// The frame pool depth.
    ///
    /// Two is enough to decouple the compositor from the encoder without
    /// building a queue: a third buffered frame is a frame the viewer will see
    /// late, and latency matters more here than smoothness.
    const FRAME_POOL_DEPTH: i32 = 2;

    /// Whether a UAC prompt or the sign-in screen is in front.
    ///
    /// `OpenInputDesktop` succeeds only for the desktop this process's window
    /// station owns. When the Secure Desktop has the input, it fails — which
    /// is the supported way to *notice* the situation without attempting to
    /// interfere with it.
    pub fn secure_desktop_state() -> SecureDesktopState {
        // SAFETY: OpenInputDesktop takes no pointers and returns a handle we
        // close immediately. The call is safe for any argument values.
        unsafe {
            match OpenInputDesktop(Default::default(), false, DESKTOP_READOBJECTS) {
                Ok(desktop) => {
                    let _ = CloseDesktop(desktop);

                    SecureDesktopState::UserDesktop
                }
                Err(_) => SecureDesktopState::Active,
            }
        }
    }

    /// Every display, as the protocol describes them.
    pub fn enumerate_monitors() -> PlatformResult<MonitorLayout> {
        let mut handles: Vec<HMONITOR> = Vec::new();

        // SAFETY: the callback below only pushes the handle it is given onto
        // the vector `lparam` points at, which lives for the whole call.
        unsafe {
            let _ = EnumDisplayMonitors(
                None,
                None,
                Some(enum_monitor),
                LPARAM(&mut handles as *mut Vec<HMONITOR> as isize),
            );
        }

        let mut monitors = Vec::new();

        for (index, handle) in handles.into_iter().enumerate() {
            let mut info = MONITORINFOEXW::default();
            info.monitorInfo.cbSize = std::mem::size_of::<MONITORINFOEXW>() as u32;

            // SAFETY: `info` is correctly sized and `handle` came from
            // EnumDisplayMonitors.
            let ok = unsafe { GetMonitorInfoW(handle, &mut info.monitorInfo as *mut _) };
            if !ok.as_bool() {
                continue;
            }

            let RECT {
                left,
                top,
                right,
                bottom,
            } = info.monitorInfo.rcMonitor;

            let mut dpi_x = 96_u32;
            let mut dpi_y = 96_u32;
            // SAFETY: both out-parameters are initialised locals.
            unsafe {
                let _ = GetDpiForMonitor(handle, MDT_EFFECTIVE_DPI, &mut dpi_x, &mut dpi_y);
            }

            let name = String::from_utf16_lossy(&info.szDevice)
                .trim_end_matches('\0')
                .to_owned();

            monitors.push(describe_monitor(
                index as u32 + 1,
                &name,
                info.monitorInfo.dwFlags & MONITORINFOF_PRIMARY != 0,
                left,
                top,
                (right - left).max(0) as u32,
                (bottom - top).max(0) as u32,
                dpi_x,
                // Windows reports orientation per display device; the effective
                // rectangle already reflects it, so the value here is
                // descriptive rather than something coordinates depend on.
                orientation_from_windows(if bottom - top > right - left { 1 } else { 0 }),
            ));
        }

        if monitors.is_empty() {
            return Err(PlatformError::NotFound("Any display"));
        }

        let active_monitor_id = monitors
            .iter()
            .find(|monitor| monitor.primary)
            .map_or(1, |monitor| monitor.id);

        Ok(MonitorLayout {
            monitors,
            active_monitor_id,
        })
    }

    unsafe extern "system" fn enum_monitor(
        monitor: HMONITOR,
        _dc: HDC,
        _rect: *mut RECT,
        lparam: LPARAM,
    ) -> BOOL {
        // SAFETY: `lparam` is the vector pointer passed in by the caller above
        // and is valid for the duration of EnumDisplayMonitors.
        let handles = unsafe { &mut *(lparam.0 as *mut Vec<HMONITOR>) };
        handles.push(monitor);

        BOOL(1)
    }

    /// One running capture.
    pub struct CaptureSession {
        device: ID3D11Device,
        context: ID3D11DeviceContext,
        frame_pool: Direct3D11CaptureFramePool,
        session: GraphicsCaptureSession,
        item: GraphicsCaptureItem,
        started: std::time::Instant,
        frames: AtomicU64,
        profile: CaptureProfile,
    }

    impl CaptureSession {
        pub fn start(monitor_id: u32, profile: CaptureProfile) -> PlatformResult<Self> {
            let layout = enumerate_monitors()?;
            let monitor = layout
                .find(monitor_id)
                .ok_or(PlatformError::NotFound("That display"))?;

            let handle = monitor_handle(monitor_id)?;

            // SAFETY: every call below is a documented COM/WinRT entry point
            // with correctly typed arguments; each result is checked.
            unsafe {
                let mut device: Option<ID3D11Device> = None;
                let mut context: Option<ID3D11DeviceContext> = None;

                D3D11CreateDevice(
                    None,
                    D3D_DRIVER_TYPE_HARDWARE,
                    // No software rasteriser module: the hardware driver type
                    // above is the one being asked for, and a null module is
                    // what that combination expects.
                    windows::Win32::Foundation::HMODULE::default(),
                    D3D11_CREATE_DEVICE_BGRA_SUPPORT,
                    None,
                    D3D11_SDK_VERSION,
                    Some(&mut device),
                    None,
                    Some(&mut context),
                )
                .map_err(|error| PlatformError::Os {
                    operation: "creating the graphics device",
                    detail: error.message(),
                })?;

                let device = device.ok_or(PlatformError::Os {
                    operation: "creating the graphics device",
                    detail: "no device was returned".into(),
                })?;
                let context = context.ok_or(PlatformError::Os {
                    operation: "creating the graphics device",
                    detail: "no context was returned".into(),
                })?;

                let dxgi: IDXGIDevice = device.cast().map_err(|error| PlatformError::Os {
                    operation: "creating the graphics device",
                    detail: error.message(),
                })?;

                let winrt_device =
                    CreateDirect3D11DeviceFromDXGIDevice(&dxgi).map_err(|error| {
                        PlatformError::Os {
                            operation: "creating the capture device",
                            detail: error.message(),
                        }
                    })?;
                let winrt_device: windows::Graphics::DirectX::Direct3D11::IDirect3DDevice =
                    winrt_device.cast().map_err(|error| PlatformError::Os {
                        operation: "creating the capture device",
                        detail: error.message(),
                    })?;

                let interop: IGraphicsCaptureItemInterop =
                    windows::core::factory::<GraphicsCaptureItem, IGraphicsCaptureItemInterop>()
                        .map_err(|error| PlatformError::Os {
                            operation: "starting screen capture",
                            detail: error.message(),
                        })?;

                let item: GraphicsCaptureItem =
                    interop.CreateForMonitor(handle).map_err(|error| {
                        PlatformError::PermissionDenied(format!(
                            "Windows would not let AICOUNTLY Remote capture this display: {}",
                            error.message()
                        ))
                    })?;

                let (width, height) = profile.fit(monitor.width, monitor.height);

                let frame_pool = Direct3D11CaptureFramePool::CreateFreeThreaded(
                    &winrt_device,
                    DirectXPixelFormat::B8G8R8A8UIntNormalized,
                    FRAME_POOL_DEPTH,
                    SizeInt32 {
                        Width: width as i32,
                        Height: height as i32,
                    },
                )
                .map_err(|error| PlatformError::Os {
                    operation: "creating the capture frame pool",
                    detail: error.message(),
                })?;

                let session =
                    frame_pool
                        .CreateCaptureSession(&item)
                        .map_err(|error| PlatformError::Os {
                            operation: "starting screen capture",
                            detail: error.message(),
                        })?;

                // The cursor is drawn in because a viewer helping somebody
                // needs to see where they are pointing.
                let _ = session.SetIsCursorCaptureEnabled(profile.include_cursor);
                // No yellow capture border: this is an assistance session the
                // person already consented to and can see in the tray, and a
                // system border on top of their own screen is noise. Available
                // from Windows 11; ignored on 10, which is why the result is
                // discarded rather than checked.
                let _ = session.SetIsBorderRequired(false);

                session.StartCapture().map_err(|error| PlatformError::Os {
                    operation: "starting screen capture",
                    detail: error.message(),
                })?;

                Ok(Self {
                    device,
                    context,
                    frame_pool,
                    session,
                    item,
                    started: std::time::Instant::now(),
                    frames: AtomicU64::new(0),
                    profile,
                })
            }
        }

        /// The next frame, or `None` when nothing has changed.
        pub fn next_frame(&mut self, profile: CaptureProfile) -> PlatformResult<Option<Frame>> {
            // SAFETY: `TryGetNextFrame` returns null when the pool is empty,
            // which the `Ok(None)` arm handles; everything else is a checked
            // COM call on a live object.
            let frame: Direct3D11CaptureFrame = match self.frame_pool.TryGetNextFrame() {
                Ok(frame) => frame,
                // An empty pool means the desktop has not changed. That is the
                // whole point of this API: a still screen costs nothing.
                Err(_) => return Ok(None),
            };

            let surface = frame.Surface().map_err(|error| PlatformError::Os {
                operation: "reading a captured frame",
                detail: error.message(),
            })?;

            let texture = copy_to_staging(&self.device, &self.context, &surface)?;

            let bytes = read_staging(&self.context, &texture, profile)?;

            self.frames.fetch_add(1, Ordering::Relaxed);

            Ok(bytes)
        }

        pub fn reconfigure(&mut self, profile: CaptureProfile) -> PlatformResult<()> {
            self.profile = profile;
            let _ = self
                .session
                .SetIsCursorCaptureEnabled(profile.include_cursor);

            Ok(())
        }

        pub fn close(self) {
            // Order matters: the session stops producing before the pool that
            // holds its buffers goes away.
            let _ = self.session.Close();
            let _ = self.frame_pool.Close();
            let _ = self.item;
            let _ = self.started;
        }
    }

    /// Copy a GPU surface into a CPU-readable staging texture.
    fn copy_to_staging(
        device: &ID3D11Device,
        context: &ID3D11DeviceContext,
        surface: &windows::Graphics::DirectX::Direct3D11::IDirect3DSurface,
    ) -> PlatformResult<ID3D11Texture2D> {
        use windows::Win32::System::WinRT::Direct3D11::IDirect3DDxgiInterfaceAccess;

        // SAFETY: the surface is a live WinRT object; the interop interface is
        // the documented way to reach the underlying D3D11 texture.
        unsafe {
            let access: IDirect3DDxgiInterfaceAccess =
                surface.cast().map_err(|error| PlatformError::Os {
                    operation: "reading a captured frame",
                    detail: error.message(),
                })?;

            let source: ID3D11Texture2D =
                access.GetInterface().map_err(|error| PlatformError::Os {
                    operation: "reading a captured frame",
                    detail: error.message(),
                })?;

            let mut description = D3D11_TEXTURE2D_DESC::default();
            source.GetDesc(&mut description);

            let staging = D3D11_TEXTURE2D_DESC {
                Usage: D3D11_USAGE_STAGING,
                BindFlags: 0,
                CPUAccessFlags: D3D11_CPU_ACCESS_READ.0 as u32,
                MiscFlags: 0,
                ..description
            };

            let mut texture: Option<ID3D11Texture2D> = None;
            device
                .CreateTexture2D(&staging, None, Some(&mut texture))
                .map_err(|error| PlatformError::Os {
                    operation: "reading a captured frame",
                    detail: error.message(),
                })?;

            let texture = texture.ok_or(PlatformError::Os {
                operation: "reading a captured frame",
                detail: "no staging texture was created".into(),
            })?;

            context.CopyResource(&texture, &source);

            Ok(texture)
        }
    }

    /// Map a staging texture and copy its rows out.
    fn read_staging(
        context: &ID3D11DeviceContext,
        texture: &ID3D11Texture2D,
        profile: CaptureProfile,
    ) -> PlatformResult<Option<Frame>> {
        // SAFETY: the texture was created with CPU read access above, and the
        // mapping is released before this function returns on every path.
        unsafe {
            let mut description = D3D11_TEXTURE2D_DESC::default();
            texture.GetDesc(&mut description);

            let mut mapped = D3D11_MAPPED_SUBRESOURCE::default();
            context
                .Map(texture, 0, D3D11_MAP_READ, 0, Some(&mut mapped))
                .map_err(|error| PlatformError::Os {
                    operation: "reading a captured frame",
                    detail: error.message(),
                })?;

            let stride = mapped.RowPitch as usize;
            let height = description.Height as usize;

            let mut data = vec![0_u8; stride * height];
            std::ptr::copy_nonoverlapping(mapped.pData as *const u8, data.as_mut_ptr(), data.len());

            context.Unmap(texture, 0);

            let timestamp_us = std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .map(|d| d.as_micros() as u64)
                .unwrap_or_default();

            let _ = profile;

            Ok(Frame::new(
                description.Width,
                description.Height,
                stride,
                PixelFormat::Bgra8,
                timestamp_us,
                data,
            ))
        }
    }

    fn monitor_handle(monitor_id: u32) -> PlatformResult<HMONITOR> {
        let mut handles: Vec<HMONITOR> = Vec::new();

        // SAFETY: as in `enumerate_monitors`.
        unsafe {
            let _ = EnumDisplayMonitors(
                None,
                None,
                Some(enum_monitor),
                LPARAM(&mut handles as *mut Vec<HMONITOR> as isize),
            );
        }

        handles
            .get((monitor_id.max(1) - 1) as usize)
            .copied()
            .ok_or(PlatformError::NotFound("That display"))
    }

    // Referenced so the imports above are not flagged when a future edit stops
    // using one; keeping the list honest is easier than chasing warnings.
    #[allow(dead_code)]
    fn _unused(_: HANDLE, _: HWND) {
        let _ = GetForegroundWindow;
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_capture_starts_stopped_and_stopping_is_idempotent() {
        let mut capture = WindowsCapture::new();

        assert!(!capture.is_running());
        assert!(capture.stop().is_ok());
        assert!(capture.stop().is_ok());
    }

    // The monitor arithmetic, the DPI fallback and the Secure Desktop wording
    // are tested in `platform::display`, which compiles on every host. They
    // used to be tested here — where a Windows runner was the only thing that
    // ran them, and a rounding mistake in one reached CI rather than a laptop.
}
