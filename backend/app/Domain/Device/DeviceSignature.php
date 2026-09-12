<?php

declare(strict_types=1);

namespace App\Domain\Device;

use SodiumException;

/**
 * The canonical thing a device signs, and the verification of that signature.
 *
 * **The canonical representation is the security property.** If the server and
 * the agent disagree by one byte about what was signed, either every signature
 * fails (an outage) or — far worse — a signature over one payload is accepted
 * for a different one. So the bytes are defined here, once, in a form that has
 * no room for a formatting decision:
 *
 *     AICOUNTLY-REMOTE-DEVICE-AUTH-v1\n
 *     <device uuid>\n
 *     <nonce, 64 lowercase hex characters>\n
 *     <issued-at, seconds since the epoch, decimal>\n
 *     <audience>\n
 *
 * No JSON, no key ordering, no whitespace choices, no locale. The same string
 * is produced by `remote-security`'s `challenge_payload()` in the agent, and
 * `DeviceSignatureTest` asserts the exact bytes so a change on either side
 * fails a test rather than a production login.
 *
 * Ed25519 is the only algorithm. It has no parameters to get wrong, no curve to
 * confuse, and libsodium ships with PHP — so there is nothing here that
 * negotiates an algorithm from a client-supplied field, which is where the
 * signature-verification family of bugs lives.
 */
final class DeviceSignature
{
    public const ALGORITHM = 'ED25519';

    /** Domain separation: a signature over this can only ever be a device auth. */
    private const DOMAIN = 'AICOUNTLY-REMOTE-DEVICE-AUTH-v1';

    /** Who the assertion is for. A signature for one deployment is not one for another. */
    public const AUDIENCE = 'aicountly-remote-api';

    /** Raw Ed25519 public keys are exactly this many bytes. */
    public const PUBLIC_KEY_BYTES = 32;

    public const SIGNATURE_BYTES = 64;

    /**
     * The exact bytes a device signs to prove possession of its private key.
     */
    public static function challengePayload(
        string $deviceUuid,
        string $nonce,
        int $issuedAt,
        string $audience = self::AUDIENCE,
    ): string {
        return self::DOMAIN . "\n"
            . $deviceUuid . "\n"
            . strtolower($nonce) . "\n"
            . $issuedAt . "\n"
            . $audience . "\n";
    }

    /**
     * Verify a detached Ed25519 signature.
     *
     * Returns false rather than throwing for every malformed input, because
     * every one of them means the same thing to the caller — this did not
     * check out — and distinguishing them in an error message would tell an
     * attacker which half they got wrong.
     */
    public static function verify(string $message, string $signatureBase64, string $publicKeyBase64): bool
    {
        $signature = self::decodeExact($signatureBase64, self::SIGNATURE_BYTES);
        $publicKey = self::decodeExact($publicKeyBase64, self::PUBLIC_KEY_BYTES);

        if ($signature === null || $publicKey === null) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (SodiumException) {
            return false;
        }
    }

    /**
     * A public key in the one form Remote stores: standard base64 of the raw
     * 32 bytes. Returns null for anything that is not one.
     *
     * Normalising on the way in is what makes the fingerprint index meaningful:
     * two spellings of the same key must not become two devices.
     */
    public static function normalisePublicKey(string $candidate): ?string
    {
        $raw = self::decodeExact(trim($candidate), self::PUBLIC_KEY_BYTES);

        if ($raw === null) {
            return null;
        }

        // An all-zero key is not a key. libsodium will happily verify nothing
        // against some degenerate points, and a device that enrolled one could
        // not be authenticated by anybody — including itself.
        if (trim($raw, "\0") === '') {
            return null;
        }

        return base64_encode($raw);
    }

    /** SHA-256 of the *raw* key bytes, hex. The unique index is over this. */
    public static function fingerprint(string $normalisedPublicKey): string
    {
        return hash('sha256', (string) base64_decode($normalisedPublicKey, true));
    }

    /** Grouped in fours, for a person comparing it with what the agent shows. */
    public static function displayFingerprint(string $fingerprint): string
    {
        return implode(' ', str_split(strtoupper(substr($fingerprint, 0, 32)), 4));
    }

    /**
     * Base64 (standard or URL-safe) that decodes to exactly `$bytes` bytes.
     */
    private static function decodeExact(string $value, int $bytes): ?string
    {
        if ($value === '' || strlen($value) > 512) {
            return null;
        }

        $padded  = strtr($value, '-_', '+/');
        $decoded = base64_decode($padded . str_repeat('=', (4 - strlen($padded) % 4) % 4), true);

        if ($decoded === false || strlen($decoded) !== $bytes) {
            return null;
        }

        return $decoded;
    }
}
