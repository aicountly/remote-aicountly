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
    /**
     * The hard ceiling the Node service enforces on a token's lifetime.
     *
     * Duplicated here on purpose: minting something the relay will refuse is a
     * failure nobody can diagnose from either side, so the API declines to
     * issue it in the first place. `signalling/src/token.js` carries the same
     * number, and `SupportAndSignallingTest` asserts they agree.
     */
    public const MAX_TTL_SECONDS = 600;

    public function __construct(private readonly RemoteConfig $config)
    {
    }

    public function isConfigured(): bool
    {
        return $this->config->signallingSecret !== '';
    }

    /**
     * @param  array<string, bool> $capabilities
     * @param  int|null            $ttlSeconds  overrides the configured TTL — a
     *                                          device holds one connection for
     *                                          hours and re-mints, where a
     *                                          browser re-mints per session
     * @param  'session'|'device'  $kind        what the connection *is*. The
     *                                          signalling service uses it to
     *                                          decide which message types are
     *                                          relayable in the room, so a
     *                                          presence connection cannot be
     *                                          used to push SDP at somebody.
     * @return array{token: string, expiresAt: int, url: string, room: string, kind: string}
     */
    public function issue(
        string $sessionUuid,
        string $participantUuid,
        string $participantRole,
        string $displayName,
        array $capabilities,
        ?int $ttlSeconds = null,
        string $kind = 'session',
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

        // Clamped to what the signalling service will accept: it refuses a
        // token whose lifetime exceeds what this API is supposed to mint, so a
        // configuration mistake here becomes a refusal to issue rather than a
        // fleet of agents that cannot connect.
        $ttl       = max(30, min($ttlSeconds ?? $this->config->signallingTokenTtlSeconds, self::MAX_TTL_SECONDS));
        $expiresAt = time() + $ttl;

        $kind = $kind === 'device' ? 'device' : 'session';

        $header = $this->encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $body   = $this->encode([
            'iss'  => 'aicountly-remote-api',
            'aud'  => 'aicountly-remote-signalling',
            'room' => $sessionUuid,
            'sub'  => $participantUuid,
            'role' => $participantRole,
            'name' => mb_substr($displayName, 0, 120),
            'cap'  => $capabilities,
            'knd'  => $kind,
            'iat'  => time(),
            'exp'  => $expiresAt,
        ]);

        $signature = hash_hmac('sha256', $header . '.' . $body, $this->config->signallingSecret, true);

        return [
            'token'     => $header . '.' . $body . '.' . $this->base64UrlEncode($signature),
            'expiresAt' => $expiresAt,
            'url'       => $this->config->signalUrl,
            'room'      => $sessionUuid,
            'kind'      => $kind,
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
