//! The device private key, protected by DPAPI.
//!
//! The implementation moved to `aicountly_remote_service::storage`, because
//! both processes need the same blob: this one writes it when a person
//! registers the computer, and the service reads it as `LocalSystem` to
//! authenticate the machine while nobody is signed in. It is re-exported here
//! so the platform layer still reads as the one place a Windows facility is
//! reached from, and so `platform::secure_storage()` is unchanged.
//!
//! See that module for machine scope, the additional entropy, and where the
//! file lives.

pub use aicountly_remote_service::storage::DpapiStorage;
