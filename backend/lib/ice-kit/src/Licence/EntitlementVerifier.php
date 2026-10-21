<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

/**
 * Checks that an entitlement envelope was signed by the central licensing backend — and by nobody else.
 *
 * Ed25519 (libsodium, in PHP core since 7.2): the central backend holds the PRIVATE key, a product holds only the
 * PUBLIC key(s). A compromised product host, an application database role with write access, or a poisoned cache
 * cannot mint a paid entitlement, because none of them has anything that can sign one. That is why the cache
 * tables need not be trusted.
 *
 *   envelope = { "document": {…}, "signature": { "alg": "ed25519", "kid": "<key id>", "value": "<base64>" } }
 *   signed bytes = Canonical::json(document)
 *
 * Keys: WEBRTC_LICENCE_PUBLIC_KEYS = "kid1=<base64 32-byte key>,kid2=<…>" (two ids may be live during rotation).
 */
final class EntitlementVerifier
{
    /** @param array<string, string> $publicKeys key id => raw 32-byte public key */
    public function __construct(private readonly array $publicKeys)
    {
    }

    /** @param callable(string): (string|null) $read */
    public static function fromEnvironment(callable $read): self
    {
        $keys = [];
        foreach (explode(',', (string) ($read('WEBRTC_LICENCE_PUBLIC_KEYS') ?? '')) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || ! str_contains($pair, '=')) {
                continue;
            }
            [$kid, $b64] = explode('=', $pair, 2);
            $raw = base64_decode(trim($b64), true);
            if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/', trim($kid)) === 1 && $raw !== false && strlen($raw) === 32) {
                $keys[trim($kid)] = $raw;
            }
        }

        return new self($keys);
    }

    /** Whether a signature can be checked at all: libsodium present and at least one key configured. */
    public function available(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached') && $this->publicKeys !== [];
    }

    /** @return list<string> */
    public function keyIds(): array
    {
        return array_keys($this->publicKeys);
    }

    /** @param array<string, mixed> $envelope */
    public function verify(array $envelope): bool
    {
        if (! $this->available()) {
            return false;
        }
        $doc = $envelope['document'] ?? null;
        $sig = $envelope['signature'] ?? null;
        if (! is_array($doc) || ! is_array($sig) || ($sig['alg'] ?? null) !== 'ed25519' || ! is_string($sig['kid'] ?? null) || ! is_string($sig['value'] ?? null)) {
            return false;
        }
        $key = $this->publicKeys[$sig['kid']] ?? null;
        $raw = base64_decode($sig['value'], true);
        if ($key === null || $raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($raw, Canonical::json($doc), $key);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Sign a document — for the central backend's reference implementation and for tests/fixtures ONLY. A product
     * never holds a private key; this lives here so the contract's wire format has one executable definition.
     *
     * @param array<string, mixed> $document
     * @return array{document: array<string, mixed>, signature: array{alg: string, kid: string, value: string}}
     */
    public static function sign(array $document, string $kid, string $secretKey): array
    {
        return [
            'document'  => $document,
            'signature' => ['alg' => 'ed25519', 'kid' => $kid, 'value' => base64_encode(sodium_crypto_sign_detached(Canonical::json($document), $secretKey))],
        ];
    }
}
