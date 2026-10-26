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
    describe_monitor, friendly_display_name, orientation_from_windows, scale_from_dpi,
    SecureDesktopState,
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

    use super::{
        describe_monitor, friendly_display_name, orientation_from_windows, SecureDesktopState,
    };
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
    use windows::Win32::Graphics::Direct3D::{
        D3D_DRIVER_TYPE, D3D_DRIVER_TYPE_HARDWARE, D3D_DRIVER_TYPE_WARP,
    };
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

    /// Make sure this process has an apartment WinRT activation can use.
    ///
    /// `RoGetActivationFactory` — which is what `windows::core::factory` and
    /// every `Direct3D11CaptureFramePool` call below go through — fails with
    /// `CO_E_NOTINITIALIZED` on a thread that has never initialised COM. The
    /// capture runs on the agent's own connection thread, which is a plain
    /// `std::thread` and had initialised nothing, so capture would have failed
    /// at the first activation with an error that reads like a Windows
    /// version problem rather than a missing apartment.
    ///
    /// `CoIncrementMTAUsage` and not `CoInitializeEx` on purpose: it creates
    /// the process's implicit multithreaded apartment and leaves every
    /// thread's own apartment alone, so it cannot fail with
    /// `RPC_E_CHANGED_MODE` against the single-threaded apartment the webview
    /// puts the main thread in, and it needs no matching uninitialise on a
    /// thread that may outlive it. The cookie is deliberately never released:
    /// the MTA is wanted for the life of the process.
    fn ensure_apartment() -> PlatformResult<()> {
        use windows::Win32::System::Com::CoIncrementMTAUsage;

        static APARTMENT: std::sync::OnceLock<Result<(), String>> = std::sync::OnceLock::new();

        // SAFETY: takes nothing, and the cookie it returns is intentionally
        // leaked — the apartment is wanted until the process exits.
        APARTMENT
            .get_or_init(|| unsafe {
                CoIncrementMTAUsage()
                    .map(|cookie| {
                        let _ = cookie;
                    })
                    .map_err(|error| error.message())
            })
            .clone()
            .map_err(|detail| PlatformError::Os {
                operation: "preparing screen capture",
                detail,
            })
    }

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

            let id = index as u32 + 1;

            // `szDevice` is a GDI device path, `\\.\DISPLAY1`. The monitor
            // picker asks a person which of their screens they are sharing,
            // so what it shows them is "Display 1".
            let device = String::from_utf16_lossy(&info.szDevice);
            let name = friendly_display_name(&device, id);

            monitors.push(describe_monitor(
                id,
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
        /// The size the pool's buffers currently are.
        pool_size: SizeInt32,
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

            // Nothing below can be activated without an apartment.
            ensure_apartment()?;

            // SAFETY: every call below is a documented COM/WinRT entry point
            // with correctly typed arguments; each result is checked.
            unsafe {
                let (device, context) = graphics_device()?;

                let winrt_device = capture_device(&device)?;

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

                // The pool's buffers are the size of the content, taken from
                // the capture item itself rather than from the monitor
                // rectangle, because the item is what Windows will draw into
                // them.
                //
                // NOT `profile.fit(...)`: a frame pool smaller than the
                // content does not scale the picture into it, it **crops** —
                // so sizing the pool to a downscaled profile would have sent
                // the viewer the top-left corner of the screen and called it
                // the screen. Scaling belongs to the encoder, which is handed
                // the whole frame and the profile together.
                let pool_size = item.Size().unwrap_or(SizeInt32 {
                    Width: monitor.width as i32,
                    Height: monitor.height as i32,
                });

                let frame_pool = Direct3D11CaptureFramePool::CreateFreeThreaded(
                    &winrt_device,
                    DirectXPixelFormat::B8G8R8A8UIntNormalized,
                    FRAME_POOL_DEPTH,
                    pool_size,
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
                    pool_size,
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

            // A resolution change, a rotation, a DPI change or a remote
            // desktop reconnect changes the content size under a running
            // capture. The pool's buffers do not follow on their own, and a
            // pool that no longer matches the content crops it — so the pool
            // is rebuilt and this frame, which was drawn into the old one, is
            // dropped. The next one arrives at the new size.
            if let Ok(content) = frame.ContentSize() {
                if content.Width != self.pool_size.Width || content.Height != self.pool_size.Height
                {
                    close_frame(&frame);
                    self.resize(content)?;

                    return Ok(None);
                }
            }

            let surface = frame.Surface().map_err(|error| PlatformError::Os {
                operation: "reading a captured frame",
                detail: error.message(),
            })?;

            let texture = copy_to_staging(&self.device, &self.context, &surface)?;

            let bytes = read_staging(&self.context, &texture, profile)?;

            // Explicitly, and before the next `TryGetNextFrame`: the buffer
            // this frame holds goes back to the pool when the frame is
            // closed, and a pool of two whose buffers are never returned
            // stops delivering after two frames.
            drop(surface);
            close_frame(&frame);

            self.frames.fetch_add(1, Ordering::Relaxed);

            Ok(bytes)
        }

        /// Rebuild the pool at a new content size.
        fn resize(&mut self, size: SizeInt32) -> PlatformResult<()> {
            // The WinRT device is made again from the D3D11 one rather than
            // held in this struct: `IDirect3DDevice` is not an agile
            // interface, so it is not `Send`, and this session is owned by
            // whichever thread is pumping the capture.
            let device = capture_device(&self.device)?;

            self.frame_pool
                .Recreate(
                    &device,
                    DirectXPixelFormat::B8G8R8A8UIntNormalized,
                    FRAME_POOL_DEPTH,
                    size,
                )
                .map_err(|error| PlatformError::Os {
                    operation: "following a display change",
                    detail: error.message(),
                })?;

            self.pool_size = size;

            Ok(())
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

    /// The WinRT view of a D3D11 device, which is what the frame pool takes.
    fn capture_device(
        device: &ID3D11Device,
    ) -> PlatformResult<windows::Graphics::DirectX::Direct3D11::IDirect3DDevice> {
        // SAFETY: `device` is a live D3D11 device, and the interop entry point
        // is the documented way to reach its WinRT counterpart.
        unsafe {
            let dxgi: IDXGIDevice = device.cast().map_err(|error| PlatformError::Os {
                operation: "creating the graphics device",
                detail: error.message(),
            })?;

            CreateDirect3D11DeviceFromDXGIDevice(&dxgi)
                .map_err(|error| PlatformError::Os {
                    operation: "creating the capture device",
                    detail: error.message(),
                })?
                .cast()
                .map_err(|error| PlatformError::Os {
                    operation: "creating the capture device",
                    detail: error.message(),
                })
        }
    }

    /// Give a frame's buffer back to the pool.
    ///
    /// `Direct3D11CaptureFrame` is an `IClosable`, and closing it is what
    /// returns the buffer. Dropping the last reference does it too, but the
    /// explicit call is what makes the ordering against the next
    /// `TryGetNextFrame` deliberate rather than a consequence of where the
    /// borrow happens to end.
    fn close_frame(frame: &Direct3D11CaptureFrame) {
        use windows::Foundation::IClosable;

        if let Ok(closable) = frame.cast::<IClosable>() {
            let _ = closable.Close();
        }
    }

    /// A D3D11 device, preferring the GPU and falling back to the software
    /// rasteriser.
    ///
    /// The fallback is not theoretical: a virtual machine without a
    /// paravirtual GPU, a Windows Server install with no display driver, and
    /// a machine whose driver has just been replaced mid-session all fail
    /// `D3D_DRIVER_TYPE_HARDWARE`. WARP is slower and it is a picture; no
    /// device at all is a support session that cannot start.
    ///
    /// # Safety
    ///
    /// Calls `D3D11CreateDevice`, whose out-parameters are locals here.
    unsafe fn graphics_device() -> PlatformResult<(ID3D11Device, ID3D11DeviceContext)> {
        let mut last: Option<windows::core::Error> = None;

        for driver in [D3D_DRIVER_TYPE_HARDWARE, D3D_DRIVER_TYPE_WARP] {
            match unsafe { create_device(driver) } {
                Ok(pair) => return Ok(pair),
                Err(error) => last = Some(error),
            }
        }

        Err(PlatformError::Os {
            operation: "creating the graphics device",
            detail: last
                .map(|error| error.message())
                .unwrap_or_else(|| "no Direct3D device could be created".into()),
        })
    }

    /// One `D3D11CreateDevice` attempt.
    ///
    /// # Safety
    ///
    /// As [`graphics_device`].
    unsafe fn create_device(
        driver: D3D_DRIVER_TYPE,
    ) -> windows::core::Result<(ID3D11Device, ID3D11DeviceContext)> {
        let mut device: Option<ID3D11Device> = None;
        let mut context: Option<ID3D11DeviceContext> = None;

        unsafe {
            D3D11CreateDevice(
                None,
                driver,
                // No software rasteriser module: the driver type above is
                // what is being asked for, and a null module is what that
                // combination expects.
                windows::Win32::Foundation::HMODULE::default(),
                D3D11_CREATE_DEVICE_BGRA_SUPPORT,
                None,
                D3D11_SDK_VERSION,
                Some(&mut device),
                None,
                Some(&mut context),
            )?;
        }

        match (device, context) {
            (Some(device), Some(context)) => Ok((device, context)),
            _ => Err(windows::core::Error::new(
                windows::Win32::Foundation::E_FAIL,
                "Direct3D returned no device",
            )),
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
