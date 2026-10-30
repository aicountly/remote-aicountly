//! TLS for the agent's own connections.
//!
//! Two rustls crypto providers are compiled into the application: aws-lc-rs,
//! which reqwest uses, and ring, which the WebRTC stack brings. With more than
//! one present rustls cannot choose a process-wide default, and anything that
//! asks it to — `ClientConfig::builder()`, or tokio-tungstenite's
//! `connect_async` — panics: "Could not automatically determine the
//! process-level CryptoProvider". A release build aborts on a panic, so the
//! application vanished a few seconds after opening, the first time a signed-in
//! computer opened its presence socket.
//!
//! So nothing here relies on a default: [`client_config`] names its provider,
//! and every binary also installs one at start ([`install_default_crypto_provider`])
//! for whatever else might ask later.

use std::sync::Arc;

use rustls::crypto::CryptoProvider;
use rustls::{ClientConfig, RootCertStore};

/// aws-lc-rs — the provider reqwest already uses for every API call.
#[must_use]
pub fn crypto_provider() -> Arc<CryptoProvider> {
    Arc::new(rustls::crypto::aws_lc_rs::default_provider())
}

/// Make aws-lc-rs the process-wide default. Call once, first thing, in every
/// binary. Harmless if a default is already installed.
pub fn install_default_crypto_provider() {
    let _ = rustls::crypto::aws_lc_rs::default_provider().install_default();
}

/// A client configuration that names its provider and trusts the same web PKI
/// roots tokio-tungstenite's own `rustls-tls-webpki-roots` would.
pub fn client_config() -> Result<Arc<ClientConfig>, rustls::Error> {
    let roots = RootCertStore {
        roots: webpki_roots::TLS_SERVER_ROOTS.to_vec(),
    };

    let config = ClientConfig::builder_with_provider(crypto_provider())
        .with_safe_default_protocol_versions()?
        .with_root_certificates(roots)
        .with_no_client_auth();

    Ok(Arc::new(config))
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Built from a named provider, so it does not depend on — or panic for
    /// the lack of — a process-wide default.
    #[test]
    fn a_client_configuration_is_built_without_a_process_default() {
        assert!(client_config().is_ok());
        assert!(!webpki_roots::TLS_SERVER_ROOTS.is_empty());
    }
}
