//! `AicountlyRemoteService.exe` — the Windows service entry point.
//!
//! Three ways to run, and Windows decides which:
//!
//! ```text
//!   AicountlyRemoteService.exe              started by the SCM: run as a service
//!   AicountlyRemoteService.exe --install    register with the SCM (needs elevation)
//!   AicountlyRemoteService.exe --uninstall  deregister and remove (needs elevation)
//!   AicountlyRemoteService.exe --version    print the version and exit
//! ```
//!
//! On anything but Windows this binary exists so the workspace builds and the
//! IPC library is tested on any host; it refuses to run rather than pretending
//! to be a service.

fn main() {
    let argument = std::env::args().nth(1).unwrap_or_default();

    if argument == "--version" {
        println!(
            "{} {}",
            aicountly_remote_service::SERVICE_DISPLAY_NAME,
            env!("CARGO_PKG_VERSION")
        );

        return;
    }

    #[cfg(windows)]
    {
        if let Err(error) = aicountly_remote_service::service::run(&argument) {
            eprintln!("[aicountly-remote-service] {error}");
            std::process::exit(1);
        }
    }

    #[cfg(not(windows))]
    {
        eprintln!(
            "{} runs on Windows. This build exists so the workspace compiles and \
             the IPC protocol is tested on every host.",
            aicountly_remote_service::SERVICE_DISPLAY_NAME
        );
        std::process::exit(64);
    }
}
