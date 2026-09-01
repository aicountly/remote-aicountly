<?php

declare(strict_types=1);

namespace App\Domain\Signalling;

use App\Domain\Support\ApiException;
use Config\Remote as RemoteConfig;

/**
 * Short-lived tokens that let a browser open a signalling room (§19).
 *
 * The division of labour is the point: **authorisation happens here, in the
 * CI4 API, and the signalling service only verifies.** The signalling server
 * holds no database, evaluates no policy and trusts no room id a client sends —
 * the room is inside the signed token, so a client cannot ask to join a room it
 * was not admitted to.
 *
 * A token is minted only for a participant whose status is APPROVED or JOINED,
 * which is what makes host approval meaningful: an unapproved viewer cannot get
 * a token, cannot enter the room, and therefore never receives an SDP offer.
 *
 * Format is the same compact HS256 JWS used elsewhere, so the Node service can
 * verify it with any JWT library or with a dozen lines of `crypto`.
 */
class SignallingTokenService
{
    public function __construct(private readonly RemoteConfig $config)
    {
    }

    public function isConfigured(): bool
    {
        return $this->config->signallingSecret !== '';
    }

    /**
     * @param  array<string, bool> $capabilities
     * @return array{token: string, expiresAt: int, url: string, room: string}
     */
    public function issue(
        string $sessionUuid,
        string $participantUuid,
        string $participantRole,
        string $displayName,
        array $capabilities,
    ): array {
        if (! $this->isConfigured()) {
            // Failing loudly beats issuing an unsigned token: without a secret
            // the signalling service could not distinguish a real participant
            // from anyone who guessed a room name.
            throw ApiException::unavailable(
                'SIGNALLING_UNCONFIGURED',
                'Live sessions are not available on this deployment yet.',
            );
        }

        $expiresAt = time() + $this->config->signallingTokenTtlSeconds;

        $header = $this->encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $body   = $this->encode([
            'iss'  => 'aicountly-remote-api',
            'aud'  => 'aicountly-remote-signalling',
            'room' => $sessionUuid,
            'sub'  => $participantUuid,
            'role' => $participantRole,
            'name' => mb_substr($displayName, 0, 120),
            'cap'  => $capabilities,
            'iat'  => time(),
            'exp'  => $expiresAt,
        ]);

        $signature = hash_hmac('sha256', $header . '.' . $body, $this->config->signallingSecret, true);

        return [
            'token'     => $header . '.' . $body . '.' . $this->base64UrlEncode($signature),
            'expiresAt' => $expiresAt,
            'url'       => $this->config->signalUrl,
            'room'      => $sessionUuid,
        ];
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return $this->base64UrlEncode((string) json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
