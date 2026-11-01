<?php

declare(strict_types=1);

namespace App\Domain\TwoStep;

use Config\Remote as RemoteConfig;

/**
 * Seals and opens the authenticator secrets Remote stores.
 *
 * **The key.** Production's `api/.env` is written by hand, so the key is
 * derived rather than added: HKDF-SHA256, info `remote-two-step-v1`, from
 * `remote.twoStepKey` when an operator has set one and otherwise from
 * `remote.signallingSecret`. The signalling secret is the one secret every
 * working deployment is guaranteed to hold — `/api/health` reports
 * `"signalling":"unconfigured"` and no live session connects without it — and
 * the only other holder is the signalling service, which runs on the same host
 * as the same account and reads it out of this same `.env`
 * (scripts/ops/remote-signalling-setup.sh). So deriving from it gives nobody a
 * key they could not already read, and HKDF's label means the derived key and
 * the signalling secret say nothing about each other. The secrets that were
 * *not* chosen, and why, are in docs/SECURITY.md: `remote.contextSecret` and
 * `LIFECYCLE_WEBHOOK_SECRET` are shared with other products on other hosts,
 * `encryption.key` is not set in production, and a database password is not
 * something to hang the database's own encryption on.
 *
 * **Rotation.** Each sealed value is stored beside the id of the key that
 * sealed it ({@see keyId()}, a fingerprint that reveals nothing of the key).
 * `remote.twoStepPreviousKeys` lists earlier secrets, so a value sealed under
 * one of them can still be opened — and the service re-seals it under the
 * current key the next time it is used. Recovery codes are hashed, not sealed,
 * so a key that is lost outright still leaves everybody a way back in.
 *
 * **The cipher.** libsodium's XChaCha20-Poly1305 (already a requirement:
 * device signatures are Ed25519 through the same extension), with the person's
 * id as associated data. A sealed secret therefore opens only on its own row: a
 * database writer who copies their own ciphertext onto somebody else's row —
 * which would hand them that person's codes — gets a value that refuses to open.
 */
final class SecretCipher
{
    /** HKDF's info: names what the key is for, and its version. */
    public const INFO = 'remote-two-step-v1';

    private const SALT = 'aicountly-remote';

    /** Below this the input is not a secret worth deriving a key from. */
    public const MINIMUM_SECRET_LENGTH = 32;

    /** @var array<string, string> key id => key, the current key first */
    private array $keys = [];

    private ?string $currentId = null;

    public function __construct(RemoteConfig $config)
    {
        $source = $config->twoStepKey !== '' ? $config->twoStepKey : $config->signallingSecret;

        if (strlen($source) >= self::MINIMUM_SECRET_LENGTH) {
            $key             = self::derive($source);
            $this->currentId = self::keyId($key);
            $this->keys[$this->currentId] = $key;
        }

        // The signalling secret stays readable after an operator sets a dedicated
        // key, so secrets sealed before that day keep opening without anybody
        // having to remember to list it.
        $fallbacks = array_merge(
            $config->twoStepKey !== '' ? [$config->signallingSecret] : [],
            $config->twoStepPreviousKeys,
        );
        foreach ($fallbacks as $previous) {
            if (strlen($previous) < self::MINIMUM_SECRET_LENGTH) {
                continue;
            }
            $key = self::derive($previous);
            $this->keys[self::keyId($key)] ??= $key;
        }
    }

    public static function derive(string $secret): string
    {
        return hash_hkdf('sha256', $secret, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, self::INFO, self::SALT);
    }

    /** A public fingerprint of a key: which key sealed a value, never the key. */
    public static function keyId(string $key): string
    {
        return substr(hash_hmac('sha256', 'remote-two-step-key-id', $key), 0, 16);
    }

    /** Whether a new secret can be sealed at all. */
    public function available(): bool
    {
        return $this->currentId !== null;
    }

    public function currentKeyId(): ?string
    {
        return $this->currentId;
    }

    public function canOpen(string $keyId): bool
    {
        return isset($this->keys[$keyId]);
    }

    /**
     * @return array{ciphertext: string, keyId: string}
     * @throws \LogicException when no key is configured — the caller checks {@see available()} first
     */
    public function seal(string $plaintext, int $userId): array
    {
        if ($this->currentId === null) {
            throw new \LogicException('No two-step key is configured.');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $box   = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            self::associatedData($userId),
            $nonce,
            $this->keys[$this->currentId],
        );

        return ['ciphertext' => base64_encode($nonce . $box), 'keyId' => $this->currentId];
    }

    /** The plaintext, or null: an unknown key, a damaged value, or a value from another row. */
    public function open(string $ciphertext, string $keyId, int $userId): ?string
    {
        $key = $this->keys[$keyId] ?? null;
        $raw = base64_decode($ciphertext, true);

        if ($key === null || $raw === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            return null;
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),
            self::associatedData($userId),
            substr($raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),
            $key,
        );

        return is_string($plaintext) ? $plaintext : null;
    }

    private static function associatedData(int $userId): string
    {
        return 'remote-two-step:user:' . $userId;
    }
}
