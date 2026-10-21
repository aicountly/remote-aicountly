<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * One TURN server, as configured.
 *
 * Two credential arrangements, never both:
 *
 *   * **REST** (coturn `use-auth-secret`) — the node holds a shared secret and every client gets a
 *     username/credential pair derived from it that expires. The secret stays on the API host.
 *   * **Static** — a fixed username/credential pair, for a managed provider that issues one. It never
 *     expires and reaches every client, so it exists for compatibility, not as a recommendation.
 *
 * The secret is held privately and is invisible to var_dump/print_r/json_encode: it must never be the
 * thing a debugging session prints.
 */
final class TurnNode
{
    public const TRANSPORTS = ['udp', 'tcp', 'tls'];

    /** @var list<string> */
    public readonly array $transports;

    /**
     * @param list<string>      $transports   any of udp, tcp, tls
     * @param list<string>|null $explicitUrls overrides the URLs built from host/ports (legacy single-pool config)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $host,
        public readonly NodeStatus $status,
        public readonly int $priority = 100,
        public readonly string $region = '',
        public readonly string $label = '',
        array $transports = self::TRANSPORTS,
        public readonly int $port = 3478,
        public readonly int $tlsPort = 5349,
        public readonly bool $offersStun = true,
        #[\SensitiveParameter] private readonly string $secret = '',
        private readonly string $staticUsername = '',
        #[\SensitiveParameter] private readonly string $staticCredential = '',
        private readonly ?array $explicitUrls = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id) !== 1) {
            throw new \InvalidArgumentException("TURN node id '{$id}' must be 1-32 characters of a-z, 0-9 and '-'.");
        }

        if ($explicitUrls === null && ! self::isValidHost($host)) {
            throw new \InvalidArgumentException("TURN node '{$id}' has no usable host.");
        }

        foreach ([$port, $tlsPort] as $p) {
            if ($p < 1 || $p > 65535) {
                throw new \InvalidArgumentException("TURN node '{$id}' has a port outside 1-65535.");
            }
        }

        $clean = array_values(array_unique(array_filter(
            array_map(static fn ($t) => strtolower(trim((string) $t)), $transports),
            static fn (string $t): bool => in_array($t, self::TRANSPORTS, true),
        )));

        if ($explicitUrls === null && $clean === []) {
            throw new \InvalidArgumentException("TURN node '{$id}' enables no transport (udp, tcp, tls).");
        }

        $this->transports = $clean;
    }

    public static function isValidHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $host) === 1;
    }

    /** `rest` when a secret is configured, `static` for a fixed pair, `none` when neither is. */
    public function credentialMode(): string
    {
        if ($this->secret !== '') {
            return 'rest';
        }

        return $this->staticUsername !== '' && $this->staticCredential !== '' ? 'static' : 'none';
    }

    /**
     * Whether two nodes would mint credentials with the very same secret. Compared in constant time
     * and never printed: the answer is all a configuration check needs.
     */
    public function sharesSecretWith(self $other): bool
    {
        return $this->secret !== '' && hash_equals($this->secret, $other->secret);
    }

    public function hasCredentials(): bool
    {
        return $this->credentialMode() !== 'none';
    }

    /** Whether the node may be handed to a client at all, health aside. */
    public function isServable(): bool
    {
        return $this->status !== NodeStatus::Disabled && $this->hasCredentials() && $this->turnUrls() !== [];
    }

    /**
     * The TURN URLs a client should try, cheapest first: UDP, then TCP, then TLS — which is also the
     * order a restrictive network strips them in.
     *
     * @return list<string>
     */
    public function turnUrls(): array
    {
        if ($this->explicitUrls !== null) {
            return array_values(array_filter(
                $this->explicitUrls,
                static fn (string $u): bool => preg_match('/^turns?:/i', $u) === 1,
            ));
        }

        $host = $this->urlHost();
        $urls = [];

        if (in_array('udp', $this->transports, true)) {
            $urls[] = "turn:{$host}:{$this->port}?transport=udp";
        }
        if (in_array('tcp', $this->transports, true)) {
            $urls[] = "turn:{$host}:{$this->port}?transport=tcp";
        }
        if (in_array('tls', $this->transports, true)) {
            $urls[] = "turns:{$host}:{$this->tlsPort}?transport=tcp";
        }

        return $urls;
    }

    /** The node's own STUN endpoint (coturn answers STUN on its TURN port), when offered. */
    public function stunUrl(): ?string
    {
        if (! $this->offersStun || $this->explicitUrls !== null || ! in_array('udp', $this->transports, true)) {
            return null;
        }

        return "stun:{$this->urlHost()}:{$this->port}";
    }

    /**
     * The username/credential pair to give a client, or null when the node has none.
     *
     * @return array{username: string, credential: string, expiresAt: int|null}|null
     */
    public function credentialsFor(string $subject, int $ttlSeconds, int $now): ?array
    {
        return match ($this->credentialMode()) {
            'rest'   => CredentialMinter::mint($this->secret, $subject, $ttlSeconds, $now),
            'static' => ['username' => $this->staticUsername, 'credential' => $this->staticCredential, 'expiresAt' => null],
            default  => null,
        };
    }

    /**
     * A credential this node must REFUSE, for `bin/probe.php --negative`: `wrong-secret` is signed
     * with a secret the relay does not hold, `expired` with the right one but a timestamp in the past.
     * A relay that allocates for either is misconfigured, whatever its other tests say.
     *
     * @return array{username: string, credential: string}|null null when the node has no REST secret
     */
    public function refusableCredential(string $kind, string $subject, int $now): ?array
    {
        if ($this->secret === '') {
            return null;
        }

        $minted = match ($kind) {
            'wrong-secret' => CredentialMinter::mint(hash('sha256', 'not-' . $this->secret), $subject, 300, $now),
            'expired'      => CredentialMinter::mintExpired($this->secret, $subject, 120, $now),
            default        => throw new \InvalidArgumentException("Unknown credential kind {$kind}."),
        };

        return ['username' => $minted['username'], 'credential' => $minted['credential']];
    }

    /**
     * What may be said about the node in a response or a log: no secret, no credential.
     *
     * @return array{id: string, label: string, region: string, priority: int, status: string, credential: string, urls: list<string>}
     */
    public function describe(): array
    {
        return [
            'id'         => $this->id,
            'label'      => $this->label !== '' ? $this->label : $this->id,
            'region'     => $this->region,
            'priority'   => $this->priority,
            'status'     => $this->status->value,
            'credential' => $this->credentialMode(),
            'urls'       => $this->turnUrls(),
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->describe() + ['host' => $this->host, 'secret' => $this->secret !== '' ? '[redacted]' : ''];
    }

    private function urlHost(): string
    {
        return filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$this->host}]" : $this->host;
    }
}
