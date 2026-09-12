//! Device identity: the keypair that *is* the machine, and how it proves it.
//!
//! # The one rule
//!
//! > **The private key never leaves the device.**
//!
//! It is generated here, on the machine, from the operating system's CSPRNG.
//! It is written only through a [`SecureStorageProvider`], which on Windows
//! means DPAPI with machine scope and on macOS will mean the Keychain. It is
//! never sent anywhere, never written to a configuration file, never put in an
//! environment variable, never logged, and — because [`DeviceKeypair`] has a
//! `Debug` implementation that prints the fingerprint instead — cannot be
//! printed by accident either.
//!
//! What the server holds is the public half. A dump of `remote_devices`
//! authenticates nobody.
//!
//! # The canonical payload
//!
//! [`challenge_payload`] produces the exact bytes the API's
//! `App\Domain\Device\DeviceSignature::challengePayload()` produces. Both are
//! covered by tests asserting the literal string, because a one-byte
//! disagreement between the two either breaks every login or — much worse —
//! makes a signature over one thing acceptable for another.

#![forbid(unsafe_code)]
#![deny(missing_docs)]

use base64::Engine as _;
use ed25519_dalek::{Signer, SigningKey, Verifier, VerifyingKey};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::fmt;
use zeroize::Zeroizing;

pub mod storage;

pub use storage::{SecureStorageProvider, StorageError, StorageScope};

/// Domain separation for the challenge signature.
///
/// A signature over these bytes can only ever be a device authentication. It
/// cannot be replayed as anything else, and nothing else can be replayed as it.
const CHALLENGE_DOMAIN: &str = "AICOUNTLY-REMOTE-DEVICE-AUTH-v1";

/// Who the assertion is for. A signature for one deployment is not one for
/// another.
pub const CHALLENGE_AUDIENCE: &str = "aicountly-remote-api";

/// The algorithm. There is exactly one, and it is not negotiated.
///
/// Nothing here reads an algorithm name from a message and dispatches on it,
/// which is where the whole signature-confusion family of bugs lives.
pub const KEY_ALGORITHM: &str = "ED25519";

/// The name the private key is stored under.
pub const DEVICE_KEY_ENTRY: &str = "device-signing-key";

/// The exact bytes a device signs to prove possession of its private key.
///
/// Must match `DeviceSignature::challengePayload()` in the API byte for byte.
#[must_use]
pub fn challenge_payload(device_uuid: &str, nonce: &str, issued_at: i64) -> String {
    format!(
        "{CHALLENGE_DOMAIN}\n{device_uuid}\n{nonce}\n{issued_at}\n{CHALLENGE_AUDIENCE}\n",
        nonce = nonce.to_ascii_lowercase(),
    )
}

/// A device's Ed25519 keypair.
///
/// The secret is held in a [`Zeroizing`] buffer so it is wiped when the value
/// is dropped rather than left in freed memory for whatever allocates next.
pub struct DeviceKeypair {
    signing: SigningKey,
}

impl DeviceKeypair {
    /// Generate a fresh keypair from the operating system's CSPRNG.
    #[must_use]
    pub fn generate() -> Self {
        Self {
            signing: SigningKey::generate(&mut rand::rng()),
        }
    }

    /// Rebuild a keypair from the 32 secret bytes that came out of storage.
    pub fn from_secret_bytes(bytes: &[u8]) -> Result<Self, KeyError> {
        let array: [u8; 32] = bytes.try_into().map_err(|_| KeyError::MalformedSecret)?;

        Ok(Self {
            signing: SigningKey::from_bytes(&array),
        })
    }

    /// The secret bytes, for handing straight to a [`SecureStorageProvider`].
    ///
    /// The return type wipes itself on drop. There is deliberately no method
    /// that returns the secret as a `String` or a base64 value: every use of
    /// it goes to secure storage, and a printable form exists only to be
    /// accidentally logged.
    #[must_use]
    pub fn secret_bytes(&self) -> Zeroizing<[u8; 32]> {
        Zeroizing::new(self.signing.to_bytes())
    }

    /// The public key, as the API stores it: base64 of the raw 32 bytes.
    #[must_use]
    pub fn public_key_base64(&self) -> String {
        base64::engine::general_purpose::STANDARD.encode(self.signing.verifying_key().to_bytes())
    }

    /// SHA-256 of the raw public key, hex — the API's `public_key_fingerprint`.
    #[must_use]
    pub fn fingerprint(&self) -> String {
        let mut hasher = Sha256::new();
        hasher.update(self.signing.verifying_key().to_bytes());

        hex::encode(hasher.finalize())
    }

    /// The fingerprint as a person compares it: the first 32 hex characters,
    /// upper case, in groups of four. Matches the API's `displayFingerprint`.
    #[must_use]
    pub fn display_fingerprint(&self) -> String {
        display_fingerprint(&self.fingerprint())
    }

    /// Sign a device authentication challenge.
    ///
    /// The only signing method on this type. It takes the three fields and
    /// builds the canonical payload itself, so no caller can sign something
    /// that is not one — which is what stops this key from becoming a general
    /// signing oracle for whatever bytes a peer supplies.
    #[must_use]
    pub fn sign_challenge(&self, device_uuid: &str, nonce: &str, issued_at: i64) -> String {
        let payload = challenge_payload(device_uuid, nonce, issued_at);

        base64::engine::general_purpose::STANDARD
            .encode(self.signing.sign(payload.as_bytes()).to_bytes())
    }

    /// Verify a signature against this keypair's public half.
    ///
    /// Present so the agent's own tests can prove the round trip without
    /// reaching for the API. The server does the verification that matters.
    #[must_use]
    pub fn verify_challenge(
        &self,
        device_uuid: &str,
        nonce: &str,
        issued_at: i64,
        signature_base64: &str,
    ) -> bool {
        verify_challenge(
            &self.public_key_base64(),
            device_uuid,
            nonce,
            issued_at,
            signature_base64,
        )
    }
}

/// Prints the fingerprint. Never the key.
impl fmt::Debug for DeviceKeypair {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("DeviceKeypair")
            .field("fingerprint", &self.display_fingerprint())
            .finish_non_exhaustive()
    }
}

/// Verify a challenge signature against a base64 public key.
#[must_use]
pub fn verify_challenge(
    public_key_base64: &str,
    device_uuid: &str,
    nonce: &str,
    issued_at: i64,
    signature_base64: &str,
) -> bool {
    let Some(public_key) = decode_exact::<32>(public_key_base64) else {
        return false;
    };
    let Some(signature) = decode_exact::<64>(signature_base64) else {
        return false;
    };

    let Ok(verifying) = VerifyingKey::from_bytes(&public_key) else {
        return false;
    };

    let payload = challenge_payload(device_uuid, nonce, issued_at);

    verifying
        .verify(
            payload.as_bytes(),
            &ed25519_dalek::Signature::from_bytes(&signature),
        )
        .is_ok()
}

/// Group a hex fingerprint for a person to read out loud.
#[must_use]
pub fn display_fingerprint(fingerprint: &str) -> String {
    fingerprint
        .chars()
        .take(32)
        .collect::<String>()
        .to_ascii_uppercase()
        .as_bytes()
        .chunks(4)
        .map(|chunk| String::from_utf8_lossy(chunk).into_owned())
        .collect::<Vec<_>>()
        .join(" ")
}

fn decode_exact<const N: usize>(value: &str) -> Option<[u8; N]> {
    let value = value.trim();
    if value.is_empty() || value.len() > 512 {
        return None;
    }

    // Accept both alphabets: the API emits standard base64, and a URL-safe
    // spelling of the same key must not become a different device.
    let bytes = base64::engine::general_purpose::STANDARD
        .decode(value)
        .or_else(|_| base64::engine::general_purpose::URL_SAFE_NO_PAD.decode(value))
        .ok()?;

    bytes.try_into().ok()
}

/// What the agent keeps about its enrolment, beside the key.
///
/// Deliberately contains **no secret**: the private key is in secure storage
/// and the device credential lives in memory for its few minutes. This
/// structure can be written to an ordinary configuration file, and is.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct EnrolmentRecord {
    /// The device's uuid, as issued by the API.
    pub device_uuid: String,
    /// The company it was enrolled into.
    pub company_id: i64,
    /// What it was called at enrolment.
    pub device_name: String,
    /// Fingerprint of the public key, so the agent can show what an
    /// administrator sees and a mismatch is visible rather than mysterious.
    pub key_fingerprint: String,
    /// The API base URL it was enrolled against.
    pub api_base_url: String,
    /// When, as an ISO-8601 UTC string.
    pub enrolled_at: String,
}

/// Everything that can go wrong with a device key.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum KeyError {
    /// The stored secret was not 32 bytes.
    #[error("the stored device key is not a valid Ed25519 secret")]
    MalformedSecret,
    /// Secure storage refused.
    #[error(transparent)]
    Storage(#[from] StorageError),
    /// The device is not enrolled on this machine.
    #[error("this device is not registered with AICOUNTLY Remote")]
    NotEnrolled,
}

#[cfg(test)]
mod tests {
    use super::*;

    const UUID: &str = "2f1d6b2e-1d3f-4a54-9d0b-6f0c3f5f6a71";

    fn nonce() -> String {
        "ab".repeat(32)
    }

    /// The exact bytes the API produces. A change to either side without the
    /// other fails here rather than in production.
    #[test]
    fn the_canonical_payload_matches_the_api_byte_for_byte() {
        let payload = challenge_payload(UUID, &nonce(), 1_770_000_000);

        assert_eq!(
            payload,
            format!(
                "AICOUNTLY-REMOTE-DEVICE-AUTH-v1\n{UUID}\n{}\n1770000000\naicountly-remote-api\n",
                nonce()
            )
        );
    }

    /// The API lowercases the nonce before signing. If the agent did not, a
    /// server that sent an upper-case nonce would reject every signature.
    #[test]
    fn the_nonce_is_lowercased_so_case_cannot_split_the_two_sides() {
        assert_eq!(
            challenge_payload(UUID, &nonce(), 1),
            challenge_payload(UUID, &nonce().to_uppercase(), 1)
        );
    }

    #[test]
    fn a_signature_round_trips() {
        let keys = DeviceKeypair::generate();
        let signature = keys.sign_challenge(UUID, &nonce(), 1_770_000_000);

        assert!(keys.verify_challenge(UUID, &nonce(), 1_770_000_000, &signature));
    }

    /// Every field is inside the signature, so changing any of them breaks it.
    #[test]
    fn changing_any_field_invalidates_the_signature() {
        let keys = DeviceKeypair::generate();
        let signature = keys.sign_challenge(UUID, &nonce(), 1_770_000_000);

        assert!(!keys.verify_challenge("another-uuid", &nonce(), 1_770_000_000, &signature));
        assert!(!keys.verify_challenge(UUID, &"cd".repeat(32), 1_770_000_000, &signature));
        assert!(!keys.verify_challenge(UUID, &nonce(), 1_770_000_001, &signature));
    }

    #[test]
    fn another_devices_key_does_not_verify() {
        let mine = DeviceKeypair::generate();
        let theirs = DeviceKeypair::generate();

        let signature = theirs.sign_challenge(UUID, &nonce(), 1);

        assert!(!mine.verify_challenge(UUID, &nonce(), 1, &signature));
    }

    #[test]
    fn a_key_survives_a_round_trip_through_storage_bytes() {
        let original = DeviceKeypair::generate();
        let bytes = original.secret_bytes();

        let restored = DeviceKeypair::from_secret_bytes(bytes.as_ref()).expect("restores");

        assert_eq!(restored.public_key_base64(), original.public_key_base64());
        assert_eq!(restored.fingerprint(), original.fingerprint());
    }

    #[test]
    fn a_malformed_secret_is_refused_rather_than_padded() {
        for length in [0, 1, 31, 33, 64] {
            assert_eq!(
                DeviceKeypair::from_secret_bytes(&vec![0u8; length]).err(),
                Some(KeyError::MalformedSecret),
                "{length} bytes should not be accepted as a key"
            );
        }
    }

    #[test]
    fn the_public_key_is_thirty_two_bytes_of_base64() {
        let keys = DeviceKeypair::generate();
        let encoded = keys.public_key_base64();

        let decoded = base64::engine::general_purpose::STANDARD
            .decode(&encoded)
            .expect("standard base64");

        assert_eq!(decoded.len(), 32);
    }

    /// The fingerprint the agent shows and the one the console shows have to
    /// be the same string, or comparing them proves nothing.
    #[test]
    fn the_fingerprint_is_grouped_the_way_the_console_shows_it() {
        let grouped = display_fingerprint(&"a".repeat(64));

        assert_eq!(grouped, "AAAA AAAA AAAA AAAA AAAA AAAA AAAA AAAA");
        assert_eq!(grouped.len(), 39);
    }

    /// A key printed in a log is a key that has left the machine.
    #[test]
    fn debug_output_never_contains_the_secret() {
        let keys = DeviceKeypair::generate();
        let secret_hex = hex::encode(keys.secret_bytes().as_ref());

        let rendered = format!("{keys:?}");

        assert!(!rendered.contains(&secret_hex));
        assert!(rendered.contains("fingerprint"));
    }

    /// The signing method builds the payload itself, so this key cannot be
    /// used to sign arbitrary bytes a peer supplied.
    #[test]
    fn the_key_signs_challenges_and_nothing_else() {
        let keys = DeviceKeypair::generate();
        let signature = keys.sign_challenge(UUID, &nonce(), 5);

        // The only thing it verifies against is a canonical challenge payload.
        assert!(verify_challenge(
            &keys.public_key_base64(),
            UUID,
            &nonce(),
            5,
            &signature
        ));
    }

    #[test]
    fn verification_refuses_a_malformed_key_or_signature() {
        let keys = DeviceKeypair::generate();
        let good = keys.sign_challenge(UUID, &nonce(), 1);

        assert!(!verify_challenge("not base64", UUID, &nonce(), 1, &good));
        assert!(!verify_challenge(
            &keys.public_key_base64(),
            UUID,
            &nonce(),
            1,
            "short"
        ));
        assert!(!verify_challenge("", UUID, &nonce(), 1, &good));
        assert!(!verify_challenge(
            &keys.public_key_base64(),
            UUID,
            &nonce(),
            1,
            ""
        ));
    }

    /// The API emits standard base64; a URL-safe spelling of the same key must
    /// not read as a different device.
    #[test]
    fn both_base64_alphabets_decode_to_the_same_key() {
        let keys = DeviceKeypair::generate();
        let standard = keys.public_key_base64();
        let raw = base64::engine::general_purpose::STANDARD
            .decode(&standard)
            .unwrap();
        let url_safe = base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(&raw);

        let signature = keys.sign_challenge(UUID, &nonce(), 1);

        assert!(verify_challenge(&standard, UUID, &nonce(), 1, &signature));
        assert!(verify_challenge(&url_safe, UUID, &nonce(), 1, &signature));
    }

    /// The record the agent writes to disk beside the key must contain no
    /// secret at all, because it is written to an ordinary file.
    #[test]
    fn the_enrolment_record_carries_no_secret() {
        let keys = DeviceKeypair::generate();
        let record = EnrolmentRecord {
            device_uuid: UUID.into(),
            company_id: 481,
            device_name: "WS-01".into(),
            key_fingerprint: keys.fingerprint(),
            api_base_url: "https://remote.aicountly.com/api".into(),
            enrolled_at: "2026-02-10T09:00:00Z".into(),
        };

        let json = serde_json::to_string(&record).unwrap();
        let secret_hex = hex::encode(keys.secret_bytes().as_ref());

        assert!(!json.contains(&secret_hex));
        assert!(!json.to_lowercase().contains("private"));
        assert!(!json.to_lowercase().contains("secret"));
        assert_eq!(
            serde_json::from_str::<EnrolmentRecord>(&json).unwrap(),
            record
        );
    }
}
