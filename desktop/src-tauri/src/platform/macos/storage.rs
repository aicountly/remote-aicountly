//! The device key, in the macOS Keychain.
//!
//! A generic-password item, service `com.aicountly.remote`, account = the
//! entry name (`device-signing-key`), accessible
//! **after first unlock, this device only**
//! (`kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly`): readable once the
//! Mac has been unlocked after a restart, never synchronised to iCloud and
//! never restored onto another machine from a backup — a device key that
//! could move to another Mac would be a device identity that could too.
//!
//! # The data-protection keychain, and an unsigned build
//!
//! That accessibility class exists only in the data-protection keychain
//! (`kSecUseDataProtectionKeychain`), which macOS opens only to an app signed
//! with a team identifier and a keychain access group. A development build
//! signed ad hoc gets `errSecMissingEntitlement` (-34018). Rather than refuse
//! to enrol at all, such a build falls back to the login keychain — still
//! encrypted at rest, still this user's, but without the "this device only"
//! guarantee — and [`KeychainStorage::describe`] stays honest about which one
//! the key lives in. A signed build never takes the fallback.
//!
//! [`StorageScope`] does not change the item: macOS has no machine-wide store
//! a user-session app can reach without an administrator, and the companion
//! needs none — there is no background service on macOS to share it with.

use remote_security::{SecureStorageProvider, StorageError, StorageScope};

/// The Keychain service every item is stored under.
pub const KEYCHAIN_SERVICE: &str = "com.aicountly.remote";

/// `errSecItemNotFound`.
pub const ERR_ITEM_NOT_FOUND: i32 = -25300;

/// `errSecMissingEntitlement`: the data-protection keychain needs a signed app.
pub const ERR_MISSING_ENTITLEMENT: i32 = -34018;

/// Which keychain a call should use, given what the data-protection one said.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum KeychainRoute {
    /// The data-protection keychain answered.
    Protected,
    /// It is not available to this build; use the login keychain.
    Login,
}

/// The route after an attempt on the data-protection keychain returned
/// `status` (0 is success).
#[must_use]
pub fn route_after(status: i32) -> KeychainRoute {
    if status == ERR_MISSING_ENTITLEMENT {
        KeychainRoute::Login
    } else {
        KeychainRoute::Protected
    }
}

/// The Keychain.
#[derive(Debug, Default, Clone, Copy)]
pub struct KeychainStorage;

impl SecureStorageProvider for KeychainStorage {
    fn store(&self, entry: &str, secret: &[u8], scope: StorageScope) -> Result<(), StorageError> {
        let _ = scope;

        #[cfg(target_os = "macos")]
        {
            imp::store(entry, secret)
        }

        #[cfg(not(target_os = "macos"))]
        {
            let _ = (entry, secret);
            Err(StorageError::Unsupported)
        }
    }

    fn load(&self, entry: &str, scope: StorageScope) -> Result<Option<Vec<u8>>, StorageError> {
        let _ = scope;

        #[cfg(target_os = "macos")]
        {
            imp::load(entry)
        }

        #[cfg(not(target_os = "macos"))]
        {
            let _ = entry;
            Err(StorageError::Unsupported)
        }
    }

    fn delete(&self, entry: &str, scope: StorageScope) -> Result<(), StorageError> {
        let _ = scope;

        #[cfg(target_os = "macos")]
        {
            imp::delete(entry)
        }

        #[cfg(not(target_os = "macos"))]
        {
            let _ = entry;
            Err(StorageError::Unsupported)
        }
    }

    fn describe(&self) -> &'static str {
        "macOS Keychain"
    }
}

#[cfg(target_os = "macos")]
mod imp {
    //! `SecItem*`, through `security-framework`. Compiled only on macOS.

    use remote_security::StorageError;
    use security_framework::access_control::{ProtectionMode, SecAccessControl};
    use security_framework::passwords::{
        delete_generic_password_options, generic_password, set_generic_password_options,
        PasswordOptions,
    };

    use super::{route_after, KeychainRoute, ERR_ITEM_NOT_FOUND, KEYCHAIN_SERVICE};

    fn protected(entry: &str) -> PasswordOptions {
        let mut options = PasswordOptions::new_generic_password(KEYCHAIN_SERVICE, entry);
        options.use_protected_keychain();

        options
    }

    fn login(entry: &str) -> PasswordOptions {
        PasswordOptions::new_generic_password(KEYCHAIN_SERVICE, entry)
    }

    fn refused(error: security_framework::base::Error) -> StorageError {
        // The status code, never the item: there is nothing secret in it.
        StorageError::Platform(format!("Keychain status {}", error.code()))
    }

    pub fn store(entry: &str, secret: &[u8]) -> Result<(), StorageError> {
        // Replace rather than update: an update cannot change the
        // accessibility of an existing item, and an item from an earlier build
        // must not keep a weaker one.
        let _ = delete(entry);

        let access = SecAccessControl::create_with_protection(
            Some(ProtectionMode::AccessibleAfterFirstUnlockThisDeviceOnly),
            0,
        )
        .map_err(refused)?;

        let mut options = protected(entry);
        options.set_access_control(access);
        options.set_label("AICOUNTLY Remote device key");

        match set_generic_password_options(secret, options) {
            Ok(()) => Ok(()),
            Err(error) if route_after(error.code()) == KeychainRoute::Login => {
                tracing::warn!(
                    "the data-protection keychain needs a signed build; the device key is in the login keychain"
                );
                set_generic_password_options(secret, login(entry)).map_err(refused)
            }
            Err(error) => Err(refused(error)),
        }
    }

    pub fn load(entry: &str) -> Result<Option<Vec<u8>>, StorageError> {
        match generic_password(protected(entry)) {
            Ok(secret) => return Ok(Some(secret)),
            Err(error) if error.code() == ERR_ITEM_NOT_FOUND => {}
            Err(error) if route_after(error.code()) == KeychainRoute::Login => {}
            Err(error) => return Err(refused(error)),
        }

        match generic_password(login(entry)) {
            Ok(secret) => Ok(Some(secret)),
            Err(error) if error.code() == ERR_ITEM_NOT_FOUND => Ok(None),
            Err(error) => Err(refused(error)),
        }
    }

    pub fn delete(entry: &str) -> Result<(), StorageError> {
        for options in [protected(entry), login(entry)] {
            match delete_generic_password_options(options) {
                Ok(()) => {}
                Err(error)
                    if error.code() == ERR_ITEM_NOT_FOUND
                        || route_after(error.code()) == KeychainRoute::Login => {}
                Err(error) => return Err(refused(error)),
            }
        }

        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Only a missing entitlement sends a call to the login keychain; any
    /// other refusal is reported, never papered over.
    #[test]
    fn only_a_missing_entitlement_falls_back_to_the_login_keychain() {
        assert_eq!(route_after(ERR_MISSING_ENTITLEMENT), KeychainRoute::Login);
        assert_eq!(route_after(0), KeychainRoute::Protected);
        assert_eq!(route_after(ERR_ITEM_NOT_FOUND), KeychainRoute::Protected);
        assert_eq!(
            route_after(-25293),
            KeychainRoute::Protected,
            "errSecAuthFailed"
        );
    }

    #[test]
    fn the_store_names_itself_and_nothing_in_it() {
        let name = KeychainStorage.describe();

        assert!(name.contains("Keychain"));
        assert!(!name.to_lowercase().contains("key ="));
    }

    /// Off macOS it refuses rather than falling back to a file.
    #[cfg(not(target_os = "macos"))]
    #[test]
    fn off_macos_the_keychain_refuses() {
        assert_eq!(
            KeychainStorage.store("device-signing-key", b"secret", StorageScope::LocalMachine),
            Err(StorageError::Unsupported)
        );
        assert_eq!(
            KeychainStorage.load("device-signing-key", StorageScope::LocalMachine),
            Err(StorageError::Unsupported)
        );
    }
}
