//! The device private key, protected by DPAPI.
//!
//! # Machine scope, and the extra entropy
//!
//! The key is stored with `CRYPTPROTECT_LOCAL_MACHINE`, for a reason that is
//! not obvious: the Windows service runs as `LocalSystem`, and a *user*-scoped
//! DPAPI blob is invisible to it. A device that could only be authenticated
//! while its enrolling user happened to be signed in would not be an
//! unattended device at all.
//!
//! Machine scope on its own means **any** process on the machine can decrypt
//! the blob by calling `CryptUnprotectData` with the defaults. That is what
//! [`ENTROPY`] is for: DPAPI mixes it into the derivation, so decrypting the
//! blob requires knowing it. It is not a secret — it is compiled into a binary
//! anybody can download — and it is not pretending to be one. What it does is
//! raise the bar from "any process that finds the file" to "a process written
//! against this specific product", and it costs nothing.
//!
//! The real boundary is the file's ACL and the fact that reading it requires
//! code execution on the machine already. A machine an attacker is running
//! code on is a machine whose screen they can already see; the device key is
//! not what is protecting it at that point.
//!
//! # Where the blob lives
//!
//! `%ProgramData%\AICOUNTLY\Remote\device.key` — machine-wide, so the service
//! and the user-session process see the same file, and inside a directory the
//! installer creates with an ACL that excludes ordinary users.

use remote_security::{SecureStorageProvider, StorageError, StorageScope};

/// Additional entropy mixed into the DPAPI derivation.
///
/// Not a secret. See the module documentation for what it is and is not for.
const ENTROPY: &[u8] = b"AICOUNTLY-REMOTE-DEVICE-KEY-v1";

/// Where machine-scoped secrets live, under `%ProgramData%`.
const DIRECTORY: &str = r"AICOUNTLY\Remote";

/// DPAPI-backed storage.
#[derive(Debug, Default)]
pub struct DpapiStorage;

impl DpapiStorage {
    /// A store rooted at `%ProgramData%\AICOUNTLY\Remote`.
    #[must_use]
    pub fn new() -> Self {
        Self
    }

    /// The path a named entry is stored at.
    ///
    /// The entry name is constrained rather than trusted: it becomes a path,
    /// and every caller is inside this crate today, which is exactly when a
    /// path-traversal bug gets written and stays unnoticed.
    pub fn path_for(entry: &str, scope: StorageScope) -> Result<std::path::PathBuf, StorageError> {
        if entry.is_empty()
            || entry.len() > 64
            || !entry.chars().all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '_')
        {
            return Err(StorageError::Platform(
                "that is not a valid secure-storage entry name".into(),
            ));
        }

        let root = match scope {
            StorageScope::LocalMachine => std::env::var_os("ProgramData")
                .ok_or_else(|| StorageError::Platform("ProgramData is not set".into()))?,
            StorageScope::CurrentUser => std::env::var_os("LOCALAPPDATA")
                .ok_or_else(|| StorageError::Platform("LOCALAPPDATA is not set".into()))?,
        };

        Ok(std::path::PathBuf::from(root)
            .join(DIRECTORY)
            .join(format!("{entry}.key")))
    }
}

impl SecureStorageProvider for DpapiStorage {
    fn store(&self, entry: &str, secret: &[u8], scope: StorageScope) -> Result<(), StorageError> {
        let path = Self::path_for(entry, scope)?;

        #[cfg(target_os = "windows")]
        {
            let protected = imp::protect(secret, scope)?;

            if let Some(parent) = path.parent() {
                std::fs::create_dir_all(parent)
                    .map_err(|error| StorageError::Platform(error.to_string()))?;
            }

            // Written to a temporary file and renamed, so a crash mid-write
            // cannot leave a half-written key that decrypts to nothing and
            // makes the machine look revoked.
            let temporary = path.with_extension("key.new");
            std::fs::write(&temporary, &protected)
                .map_err(|error| StorageError::Platform(error.to_string()))?;
            std::fs::rename(&temporary, &path)
                .map_err(|error| StorageError::Platform(error.to_string()))?;

            Ok(())
        }

        #[cfg(not(target_os = "windows"))]
        {
            let _ = (path, secret);

            Err(StorageError::Unsupported)
        }
    }

    fn load(&self, entry: &str, scope: StorageScope) -> Result<Option<Vec<u8>>, StorageError> {
        let path = Self::path_for(entry, scope)?;

        #[cfg(target_os = "windows")]
        {
            let protected = match std::fs::read(&path) {
                Ok(bytes) => bytes,
                // A machine that has never enrolled is a normal state.
                Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(None),
                Err(error) => return Err(StorageError::Platform(error.to_string())),
            };

            imp::unprotect(&protected, scope).map(Some)
        }

        #[cfg(not(target_os = "windows"))]
        {
            let _ = path;

            Err(StorageError::Unsupported)
        }
    }

    fn delete(&self, entry: &str, scope: StorageScope) -> Result<(), StorageError> {
        let path = Self::path_for(entry, scope)?;

        match std::fs::remove_file(&path) {
            Ok(()) => Ok(()),
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(()),
            Err(error) => Err(StorageError::Platform(error.to_string())),
        }
    }

    fn describe(&self) -> &'static str {
        "Windows DPAPI (machine scope)"
    }
}

#[cfg(target_os = "windows")]
mod imp {
    //! `CryptProtectData` and `CryptUnprotectData`.

    use super::ENTROPY;
    use remote_security::{StorageError, StorageScope};
    use windows::Win32::Foundation::LocalFree;
    use windows::Win32::Security::Cryptography::{
        CryptProtectData, CryptUnprotectData, CRYPT_INTEGER_BLOB, CRYPTPROTECT_LOCAL_MACHINE,
        CRYPTPROTECT_UI_FORBIDDEN,
    };

    fn blob(bytes: &[u8]) -> CRYPT_INTEGER_BLOB {
        CRYPT_INTEGER_BLOB {
            cbData: bytes.len() as u32,
            pbData: bytes.as_ptr() as *mut u8,
        }
    }

    /// Copy a DPAPI output blob out and free it.
    ///
    /// # Safety
    ///
    /// `out` must be a blob DPAPI produced and has not yet been freed.
    unsafe fn take(out: CRYPT_INTEGER_BLOB) -> Vec<u8> {
        let bytes = if out.pbData.is_null() {
            Vec::new()
        } else {
            // SAFETY: DPAPI guarantees `pbData` points at `cbData` readable
            // bytes until LocalFree is called on it.
            unsafe { std::slice::from_raw_parts(out.pbData, out.cbData as usize).to_vec() }
        };

        if !out.pbData.is_null() {
            // SAFETY: the pointer came from DPAPI, which documents LocalFree
            // as the way to release it.
            unsafe {
                let _ = LocalFree(Some(windows::Win32::Foundation::HLOCAL(out.pbData as *mut _)));
            }
        }

        bytes
    }

    fn flags(scope: StorageScope) -> u32 {
        // UI_FORBIDDEN throughout: this runs in a service and in a tray
        // application, and a DPAPI prompt from either would be a dialog
        // nobody is there to answer.
        match scope {
            StorageScope::LocalMachine => CRYPTPROTECT_LOCAL_MACHINE | CRYPTPROTECT_UI_FORBIDDEN,
            StorageScope::CurrentUser => CRYPTPROTECT_UI_FORBIDDEN,
        }
    }

    pub fn protect(secret: &[u8], scope: StorageScope) -> Result<Vec<u8>, StorageError> {
        let input = blob(secret);
        let entropy = blob(ENTROPY);
        let mut output = CRYPT_INTEGER_BLOB::default();

        // SAFETY: both input blobs point at live slices for the duration of
        // the call, and the output blob is freed by `take`.
        unsafe {
            CryptProtectData(
                &input,
                windows::core::w!("AICOUNTLY Remote device key"),
                Some(&entropy),
                None,
                None,
                flags(scope),
                &mut output,
            )
            .map_err(|error| StorageError::Platform(error.message()))?;

            Ok(take(output))
        }
    }

    pub fn unprotect(protected: &[u8], scope: StorageScope) -> Result<Vec<u8>, StorageError> {
        let input = blob(protected);
        let entropy = blob(ENTROPY);
        let mut output = CRYPT_INTEGER_BLOB::default();

        // SAFETY: as above.
        unsafe {
            CryptUnprotectData(
                &input,
                None,
                Some(&entropy),
                None,
                None,
                flags(scope),
                &mut output,
            )
            // A blob that will not decrypt is the normal outcome of a Windows
            // reinstall or a machine rename: the DPAPI machine key is gone.
            // The agent says exactly that and offers to enrol again, rather
            // than reporting a cryptographic error nobody can act on.
            .map_err(|_| StorageError::Undecryptable)?;

            Ok(take(output))
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The entry name becomes a path. Every caller is inside this crate today,
    /// which is exactly when a traversal bug gets written and stays unnoticed.
    #[test]
    fn an_entry_name_that_could_escape_the_directory_is_refused() {
        for candidate in [
            "",
            "../../windows/system32/config/sam",
            "a/b",
            r"a\b",
            "a.key",
            "a b",
            &"x".repeat(65),
        ] {
            assert!(
                DpapiStorage::path_for(candidate, StorageScope::LocalMachine).is_err(),
                "{candidate:?} should have been refused"
            );
        }
    }

    #[test]
    fn a_plain_entry_name_is_accepted() {
        // Only meaningful where the environment variable exists; on a host
        // without it the refusal is about the variable, not the name.
        if std::env::var_os("ProgramData").is_none() {
            return;
        }

        let path = DpapiStorage::path_for("device-signing-key", StorageScope::LocalMachine)
            .expect("accepted");

        assert!(path.ends_with("device-signing-key.key"));
        assert!(path.to_string_lossy().contains("AICOUNTLY"));
    }

    /// The device key has to be readable by the service, which runs as
    /// LocalSystem and would never see a user-scoped blob.
    #[test]
    fn the_two_scopes_resolve_to_different_roots() {
        if std::env::var_os("ProgramData").is_none() || std::env::var_os("LOCALAPPDATA").is_none() {
            return;
        }

        let machine = DpapiStorage::path_for("k", StorageScope::LocalMachine).unwrap();
        let user = DpapiStorage::path_for("k", StorageScope::CurrentUser).unwrap();

        assert_ne!(machine, user);
    }

    #[test]
    fn the_store_names_itself_for_the_diagnostics_panel() {
        assert_eq!(DpapiStorage::new().describe(), "Windows DPAPI (machine scope)");
    }

    /// Deleting something that is not there succeeds: unenrolling a machine
    /// that was never enrolled must not fail.
    #[test]
    fn deleting_a_missing_entry_succeeds() {
        if std::env::var_os("ProgramData").is_none() {
            return;
        }

        assert!(DpapiStorage::new()
            .delete("a-key-that-was-never-written", StorageScope::LocalMachine)
            .is_ok());
    }
}
