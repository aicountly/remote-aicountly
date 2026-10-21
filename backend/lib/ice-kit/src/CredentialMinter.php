<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * coturn's REST credential scheme (`use-auth-secret`, draft-uberti-behave-turn-rest-00).
 *
 *   username   = "<unix expiry>:<subject>"
 *   credential = base64(HMAC-SHA1(username, shared secret))
 *
 * coturn recomputes the HMAC from the username it is sent and refuses the request once the timestamp
 * has passed — on Allocate *and* on every Refresh. An allocation therefore cannot outlive its
 * credential, which is why clients refresh before `expiresAt` and restart ICE when they are relayed
 * (see the JS kit's CredentialRefresher).
 *
 * HMAC-SHA1 is what coturn implements for this scheme; it is a credential derivation, not a password
 * hash, and the secret it is keyed with never leaves the server.
 */
final class CredentialMinter
{
    public const MIN_TTL = 60;
    public const MAX_TTL = 86400;

    /**
     * @return array{username: string, credential: string, expiresAt: int}
     */
    public static function mint(#[\SensitiveParameter] string $secret, string $subject, int $ttlSeconds, int $now): array
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('A TURN credential cannot be minted without a shared secret.');
        }

        $expiresAt = $now + self::clampTtl($ttlSeconds);
        $username  = $expiresAt . ':' . self::subject($subject);

        return [
            'username'   => $username,
            'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true)),
            'expiresAt'  => $expiresAt,
        ];
    }

    public static function clampTtl(int $ttlSeconds): int
    {
        return max(self::MIN_TTL, min(self::MAX_TTL, $ttlSeconds));
    }

    /**
     * The subject half of the username.
     *
     * coturn splits the username on ':' (its `rest-api-separator`), so a subject containing one would
     * be parsed differently from how it was signed and every allocation would be refused. It is also
     * the only identifier the relay logs, so it must be an opaque reference — never an email address.
     */
    public static function subject(string $subject, string $fallback = 'aicountly'): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.-]/', '', $subject) ?? '';

        return $clean === '' ? $fallback : substr($clean, 0, 64);
    }
}
