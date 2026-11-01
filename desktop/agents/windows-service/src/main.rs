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
    let arguments: Vec<String> = std::env::args().collect();
    let argument = arguments.get(1).cloned().unwrap_or_default();

    // Before anything else that might want to say why it failed. A service
    // has no console — the SCM starts it in Session 0 — so without this every
    // `tracing::error!` in the service went nowhere at all, including the one
    // that says why it stopped.
    if argument != "--version" {
        remote_core::logging::init("service");
    }

    // The hidden Secure Desktop helper mode: this same binary, launched by the
    // service into the console session on `winsta0\winlogon`, serving the pipe
    // named as the next argument. It runs nothing else and returns when the
    // app disconnects, it is told to stop, or its lifetime cap is reached.
    #[cfg(windows)]
    if argument == aicountly_remote_service::secure_desktop::HELPER_ARGUMENT {
        let pipe = arguments.get(2).map(String::as_str).unwrap_or_default();

        if let Err(error) = aicountly_remote_service::secure_desktop::helper::run(pipe) {
            eprintln!("[aicountly-remote-service] secure desktop helper: {error}");
            std::process::exit(1);
        }

        return;
    }

    // Before any TLS connection — see `remote_core::tls`.
    remote_core::tls::install_default_crypto_provider();

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
