<?php

declare(strict_types=1);

namespace App\Domain\Device;

use App\Domain\Support\ApiException;
use Config\Remote as RemoteConfig;

/**
 * An authenticated device, and the short-lived credential that represents one.
 *
 * A device is not a person, so it cannot hold a `ses_key`; and a machine
 * credential that never expires is a machine credential that leaks. So the
 * agent proves possession of its private key
 * ({@see DeviceAuthenticationService}) and receives this: an HMAC-signed token
 * naming one device, in one company, for a few minutes.
 *
 * What it deliberately is not:
 *
 *   * **not a bearer identity for the machine.** It expires; the private key is
 *     what the machine actually is, and that never leaves it.
 *   * **not a user.** It cannot read a session it was not made a participant
 *     of, cannot administer a company, and `RequestContext::identity()` throws
 *     for it rather than quietly treating it as somebody.
 *   * **not a signalling or guest token.** The key is derived from the
 *     signalling secret under its own label, so the three can never be
 *     presented for one another.
 *
 * Revocation is not left to expiry: every endpoint that accepts one re-reads
 * the device row and refuses a status that is not ACTIVE, so an administrator
 * revoking a device stops it on the next call rather than in five minutes.
 */
final class DevicePrincipal
{
    private const KEY_LABEL = 'aicountly-remote-device-v1';

    /**
     * @param list<string> $scopes what this credential is allowed to be used for
     */
    public function __construct(
        public readonly string $deviceUuid,
        public readonly int $companyId,
        public readonly array $scopes,
        public readonly int $expiresAt,
    ) {
    }

    public const SCOPE_PRESENCE  = 'device.presence';
    public const SCOPE_SESSION   = 'device.session';
    public const SCOPE_MANAGE    = 'device.self';

    /** Everything an enrolled agent needs, and nothing an administrator does. */
    public static function agentScopes(): array
    {
        return [self::SCOPE_PRESENCE, self::SCOPE_SESSION, self::SCOPE_MANAGE];
    }

    /** @param list<string> $scopes */
    public static function issue(
        RemoteConfig $config,
        string $deviceUuid,
        int $companyId,
        array $scopes,
        int $expiresAt,
    ): string {
        $payload = self::base64UrlEncode((string) json_encode([
            'd'   => $deviceUuid,
            'c'   => $companyId,
            'sc'  => array_values($scopes),
            'iat' => time(),
            'exp' => $expiresAt,
        ], JSON_UNESCAPED_SLASHES));

        return $payload . '.' . self::base64UrlEncode(
            hash_hmac('sha256', $payload, self::key($config), true),
        );
    }

    /**
     * Verify a device token. Null for anything that is not a valid, unexpired
     * one — the caller turns that into a 401 without saying which half failed.
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

        $provided = self::base64UrlDecode($signature);
        if ($provided === null
            || ! hash_equals(hash_hmac('sha256', $payload, self::key($config), true), $provided)) {
            return null;
        }

        $claims = json_decode((string) self::base64UrlDecode($payload), true);
        if (! is_array($claims)) {
            return null;
        }

        $expiresAt = (int) ($claims['exp'] ?? 0);
        if ($expiresAt <= time()) {
            return null;
        }

        $deviceUuid = (string) ($claims['d'] ?? '');
        $companyId  = (int) ($claims['c'] ?? 0);
        if ($deviceUuid === '' || $companyId <= 0) {
            return null;
        }

        $scopes = $claims['sc'] ?? [];
        if (! is_array($scopes)) {
            return null;
        }

        return new self(
            $deviceUuid,
            $companyId,
            array_values(array_filter(array_map('strval', $scopes))),
            $expiresAt,
        );
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /** @throws ApiException */
    public function assertScope(string $scope): void
    {
        if (! $this->hasScope($scope)) {
            throw ApiException::forbidden(
                'DEVICE_SCOPE_DENIED',
                'This device credential does not cover that operation.',
            );
        }
    }

    /** @throws ApiException */
    public function assertDevice(string $deviceUuid): void
    {
        if (! hash_equals($this->deviceUuid, $deviceUuid)) {
            throw ApiException::notFound('That device could not be found.');
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
