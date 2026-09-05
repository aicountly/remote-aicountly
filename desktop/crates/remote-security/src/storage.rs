//! Where the private key lives, and the shape every platform implements.
//!
//! The list of places the device key is **never** written is not decoration —
//! it is the requirement:
//!
//! * not a `.env`, not `localStorage`, not a plaintext JSON file;
//! * not the registry in the clear;
//! * not a log line, not a panic message, not a crash dump we control;
//! * not Git, not PostgreSQL, not any browser storage.
//!
//! What is left is the operating system's own protected store. On Windows that
//! is DPAPI (`CryptProtectData` with `CRYPTPROTECT_LOCAL_MACHINE`, plus an
//! entropy value of our own so another application on the machine cannot
//! decrypt it by calling DPAPI with the defaults). On macOS it will be the
//! Keychain. Both are behind this one trait, so the crate that owns the key
//! never learns which platform it is on.
//!
//! [`InMemoryStorage`] exists for tests and for nothing else — it is not
//! reachable from a release build of the agent, and its documentation says so.

#[cfg(any(test, feature = "test-support"))]
use std::{collections::HashMap, sync::Mutex};

/// Who is allowed to read a stored secret back.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum StorageScope {
    /// Any process on this machine running as this user.
    ///
    /// Appropriate for a per-user preference. **Not** for the device key: the
    /// key has to be readable by the Windows service, which runs as
    /// `LocalSystem` and would never see a user-scoped secret.
    CurrentUser,
    /// The machine.
    ///
    /// What the device key uses, protected additionally by an entropy value
    /// the agent supplies — otherwise any process on the machine could decrypt
    /// it by calling DPAPI with the defaults.
    LocalMachine,
}

/// Secure storage, as every platform implements it.
///
/// Deliberately byte-oriented: nothing here knows what a key is, so nothing
/// here can be tempted to render one as a string.
pub trait SecureStorageProvider: Send + Sync {
    /// Store bytes under a name, replacing whatever was there.
    fn store(&self, entry: &str, secret: &[u8], scope: StorageScope) -> Result<(), StorageError>;

    /// Read bytes back. `Ok(None)` means there is nothing stored, which is a
    /// normal state — a machine that has never enrolled — and not an error.
    fn load(&self, entry: &str, scope: StorageScope) -> Result<Option<Vec<u8>>, StorageError>;

    /// Remove an entry. Succeeds when there was nothing to remove.
    fn delete(&self, entry: &str, scope: StorageScope) -> Result<(), StorageError>;

    /// A name for the store, for the agent's diagnostics panel.
    ///
    /// "Windows DPAPI (machine)" tells a support engineer where the key is.
    /// The key itself never appears anywhere.
    fn describe(&self) -> &'static str;
}

/// Why secure storage refused.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum StorageError {
    /// The platform's key store said no.
    #[error("the operating system's secure storage refused: {0}")]
    Platform(String),
    /// There is no secure storage on this platform yet.
    ///
    /// Returned by the macOS provider until the Keychain implementation
    /// exists. A clean refusal, not a fallback to a file — a device key in a
    /// file is the thing this whole trait exists to avoid.
    #[error("secure storage is not implemented on this platform yet")]
    Unsupported,
    /// The stored value was there but could not be decrypted.
    ///
    /// Normal after a Windows reinstall or a machine rename: the DPAPI
    /// machine key is gone, so the device has to enrol again. The agent says
    /// exactly that rather than "an error occurred".
    #[error("the stored key could not be decrypted on this machine")]
    Undecryptable,
}

/// In-memory storage. **Tests only.**
///
/// Kept behind `#[cfg(any(test, feature = "test-support"))]` so a release
/// build of the agent cannot construct one by accident — a device key in
/// process memory with no protection is exactly what the trait exists to
/// prevent, and "it was only meant for tests" is how it ships.
#[cfg(any(test, feature = "test-support"))]
#[derive(Debug, Default)]
pub struct InMemoryStorage {
    entries: Mutex<HashMap<(String, bool), Vec<u8>>>,
}

#[cfg(any(test, feature = "test-support"))]
impl InMemoryStorage {
    /// An empty store.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    fn key(entry: &str, scope: StorageScope) -> (String, bool) {
        (entry.to_owned(), scope == StorageScope::LocalMachine)
    }
}

#[cfg(any(test, feature = "test-support"))]
impl SecureStorageProvider for InMemoryStorage {
    fn store(&self, entry: &str, secret: &[u8], scope: StorageScope) -> Result<(), StorageError> {
        self.entries
            .lock()
            .map_err(|_| StorageError::Platform("lock poisoned".into()))?
            .insert(Self::key(entry, scope), secret.to_vec());

        Ok(())
    }

    fn load(&self, entry: &str, scope: StorageScope) -> Result<Option<Vec<u8>>, StorageError> {
        Ok(self
            .entries
            .lock()
            .map_err(|_| StorageError::Platform("lock poisoned".into()))?
            .get(&Self::key(entry, scope))
            .cloned())
    }

    fn delete(&self, entry: &str, scope: StorageScope) -> Result<(), StorageError> {
        self.entries
            .lock()
            .map_err(|_| StorageError::Platform("lock poisoned".into()))?
            .remove(&Self::key(entry, scope));

        Ok(())
    }

    fn describe(&self) -> &'static str {
        "in-memory (tests only)"
    }
}

/// A provider that refuses everything, with a reason.
///
/// Used on platforms whose native implementation is not written yet. It is a
/// clean, explainable failure — the agent tells the user their platform is not
/// supported — rather than a silent fallback to something insecure.
#[derive(Debug, Default)]
pub struct UnsupportedStorage;

impl SecureStorageProvider for UnsupportedStorage {
    fn store(&self, _entry: &str, _secret: &[u8], _scope: StorageScope) -> Result<(), StorageError> {
        Err(StorageError::Unsupported)
    }

    fn load(&self, _entry: &str, _scope: StorageScope) -> Result<Option<Vec<u8>>, StorageError> {
        Err(StorageError::Unsupported)
    }

    fn delete(&self, _entry: &str, _scope: StorageScope) -> Result<(), StorageError> {
        Err(StorageError::Unsupported)
    }

    fn describe(&self) -> &'static str {
        "unsupported on this platform"
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::{DeviceKeypair, DEVICE_KEY_ENTRY};

    #[test]
    fn a_secret_round_trips_through_storage() {
        let store = InMemoryStorage::new();
        let keys = DeviceKeypair::generate();

        store
            .store(DEVICE_KEY_ENTRY, keys.secret_bytes().as_ref(), StorageScope::LocalMachine)
            .expect("stores");

        let loaded = store
            .load(DEVICE_KEY_ENTRY, StorageScope::LocalMachine)
            .expect("loads")
            .expect("present");

        let restored = DeviceKeypair::from_secret_bytes(&loaded).expect("valid");

        assert_eq!(restored.public_key_base64(), keys.public_key_base64());
    }

    /// A machine that has never enrolled is a normal state, not an error.
    #[test]
    fn a_missing_entry_is_none_rather_than_an_error() {
        let store = InMemoryStorage::new();

        assert_eq!(
            store.load("never-written", StorageScope::LocalMachine),
            Ok(None)
        );
    }

    /// The device key is machine-scoped so the service, running as
    /// `LocalSystem`, can read what the user-session process wrote. A
    /// user-scoped entry would be invisible to it.
    #[test]
    fn scopes_are_separate_stores() {
        let store = InMemoryStorage::new();

        store.store("k", b"machine", StorageScope::LocalMachine).unwrap();
        store.store("k", b"user", StorageScope::CurrentUser).unwrap();

        assert_eq!(
            store.load("k", StorageScope::LocalMachine).unwrap().as_deref(),
            Some(&b"machine"[..])
        );
        assert_eq!(
            store.load("k", StorageScope::CurrentUser).unwrap().as_deref(),
            Some(&b"user"[..])
        );
    }

    #[test]
    fn deleting_is_idempotent() {
        let store = InMemoryStorage::new();

        store.store("k", b"value", StorageScope::LocalMachine).unwrap();
        store.delete("k", StorageScope::LocalMachine).unwrap();
        store.delete("k", StorageScope::LocalMachine).unwrap();

        assert_eq!(store.load("k", StorageScope::LocalMachine), Ok(None));
    }

    #[test]
    fn storing_again_replaces_rather_than_appends() {
        let store = InMemoryStorage::new();

        store.store("k", b"first", StorageScope::LocalMachine).unwrap();
        store.store("k", b"second", StorageScope::LocalMachine).unwrap();

        assert_eq!(
            store.load("k", StorageScope::LocalMachine).unwrap().as_deref(),
            Some(&b"second"[..])
        );
    }

    /// An unimplemented platform refuses cleanly. It does not fall back to a
    /// file, which is the thing this trait exists to prevent.
    #[test]
    fn an_unsupported_platform_refuses_rather_than_falling_back() {
        let store = UnsupportedStorage;

        assert_eq!(
            store.store("k", b"secret", StorageScope::LocalMachine),
            Err(StorageError::Unsupported)
        );
        assert_eq!(
            store.load("k", StorageScope::LocalMachine),
            Err(StorageError::Unsupported)
        );
        assert!(store.describe().contains("unsupported"));
    }
}
