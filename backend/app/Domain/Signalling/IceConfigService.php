<?php

declare(strict_types=1);

namespace App\Domain\Signalling;

use Config\Remote as RemoteConfig;

/**
 * The ICE server list handed to the browser (§20).
 *
 * No credential is ever hardcoded. Two arrangements are supported:
 *
 *   * **Static credentials** — `REMOTE_TURN_USERNAME` / `REMOTE_TURN_CREDENTIAL`
 *     from the server `.env`. Simple, and appropriate for a private TURN that
 *     is not reachable from the open internet.
 *
 *   * **Ephemeral credentials** (preferred) — coturn's `use-auth-secret` mode.
 *     The username is an expiry timestamp and the password is its HMAC under a
 *     shared secret, so the credential the browser receives is valid for an hour
 *     and useless afterwards. The secret itself never leaves this server.
 *
 * Long-lived TURN credentials in a JavaScript bundle are how a relay ends up
 * carrying someone else's traffic; the ephemeral form exists to avoid exactly
 * that, and the static form is documented as the fallback it is.
 */
class IceConfigService
{
    public function __construct(private readonly RemoteConfig $config)
    {
    }

    /**
     * @return list<array{urls: list<string>, username?: string, credential?: string}>
     */
    public function iceServers(): array
    {
        $servers = [];

        if ($this->config->stunUrls !== []) {
            $servers[] = ['urls' => array_values($this->config->stunUrls)];
        }

        if ($this->config->turnUrls === []) {
            return $servers;
        }

        if ($this->config->turnStaticAuthSecret !== '') {
            $expiry   = time() + $this->config->turnCredentialTtlSeconds;
            $username = (string) $expiry;

            $servers[] = [
                'urls'       => array_values($this->config->turnUrls),
                'username'   => $username,
                'credential' => base64_encode(
                    hash_hmac('sha1', $username, $this->config->turnStaticAuthSecret, true),
                ),
            ];

            return $servers;
        }

        if ($this->config->turnUsername !== '' && $this->config->turnCredential !== '') {
            $servers[] = [
                'urls'       => array_values($this->config->turnUrls),
                'username'   => $this->config->turnUsername,
                'credential' => $this->config->turnCredential,
            ];
        }

        return $servers;
    }

    /**
     * Whether a relay is available at all.
     *
     * The UI uses this to explain a failure honestly: without TURN, two peers
     * behind symmetric NAT simply cannot connect, and telling the user
     * "reconnecting" forever would be a lie.
     */
    public function hasRelay(): bool
    {
        return $this->config->turnUrls !== []
            && ($this->config->turnStaticAuthSecret !== ''
                || ($this->config->turnUsername !== '' && $this->config->turnCredential !== ''));
    }
}
