<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Support\ApiException;
use Config\Remote as RemoteConfig;

/**
 * An external guest, and the token that represents one (§23).
 *
 * A guest has no AICOUNTLY account, so there is no `ses_key` to authenticate
 * with. Redeeming a one-time invitation mints this instead: an HMAC-signed
 * token bound to **one participant in one session**, expiring with that
 * session.
 *
 * What it deliberately is not:
 *   * not reusable — it names a single participant row;
 *   * not an AICOUNTLY session — it opens no other product, and no other API;
 *   * not a company credential — every endpoint that takes it still checks
 *     that the participant belongs to the session being acted on.
 *
 * The key is derived from the signalling secret with a distinct label, so a
 * guest token can never be presented as a signalling token or the reverse.
 */
final class GuestPrincipal
{
    private const KEY_LABEL = 'aicountly-remote-guest-v1';

    public function __construct(
        public readonly string $participantUuid,
        public readonly string $sessionUuid,
        public readonly string $displayName,
    ) {
    }

    public static function issue(
        RemoteConfig $config,
        string $participantUuid,
        string $sessionUuid,
        string $displayName,
        int $expiresAt,
    ): string {
        $payload = self::base64UrlEncode((string) json_encode([
            'p'    => $participantUuid,
            's'    => $sessionUuid,
            'n'    => mb_substr($displayName, 0, 120),
            'exp'  => $expiresAt,
        ], JSON_UNESCAPED_SLASHES));

        $signature = hash_hmac('sha256', $payload, self::key($config), true);

        return $payload . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Verify a guest token. Returns null for anything that is not a valid,
     * unexpired token — the caller turns that into a 401.
     */
    public static function verify(RemoteConfig $config, string $token): ?self
    {
        if ($config->signallingSecret === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $signature] = $parts;

        $expected = hash_hmac('sha256', $payload, self::key($config), true);
        $provided = self::base64UrlDecode($signature);

        if ($provided === null || ! hash_equals($expected, $provided)) {
            return null;
        }

        $claims = json_decode((string) self::base64UrlDecode($payload), true);
        if (! is_array($claims)) {
            return null;
        }

        if ((int) ($claims['exp'] ?? 0) <= time()) {
            return null;
        }

        $participantUuid = (string) ($claims['p'] ?? '');
        $sessionUuid     = (string) ($claims['s'] ?? '');
        if ($participantUuid === '' || $sessionUuid === '') {
            return null;
        }

        return new self($participantUuid, $sessionUuid, (string) ($claims['n'] ?? 'Guest'));
    }

    /**
     * A guest may only ever act inside the one session their token names.
     *
     * @throws ApiException
     */
    public function assertSession(string $sessionUuid): void
    {
        if (! hash_equals($this->sessionUuid, $sessionUuid)) {
            throw ApiException::notFound('That Remote session could not be found.');
        }
    }

    private static function key(RemoteConfig $config): string
    {
        return hash_hmac('sha256', self::KEY_LABEL, $config->signallingSecret, true);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $padded  = strtr($value, '-_', '+/');
        $decoded = base64_decode($padded . str_repeat('=', (4 - strlen($padded) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
