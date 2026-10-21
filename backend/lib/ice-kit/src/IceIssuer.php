<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * Decides which relays a client gets and mints its credentials. The one entry point a product calls.
 *
 * Selection, in order:
 *
 *   1. Only servable nodes — not `disabled`, with credentials and URLs.
 *   2. Healthy `active` nodes; if there are none, healthy `standby` nodes (`failover`).
 *   3. If health says nothing servable is up, serve the servable nodes anyway and say `degraded`:
 *      the health file is a probe's opinion, and a relay that might work beats none.
 *   4. Within the chosen set: the client's region first (a hint, never a requirement), then
 *      `priority` ascending, then id — and at most `maxRelayNodes` of them, because every TURN URL is
 *      an allocation a browser makes and pays for in gathering time.
 *
 * Each node gets its own credential, minted with its own secret, so nodes need not share one.
 */
final class IceIssuer
{
    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly Topology $topology,
        private readonly ?HealthStore $health = null,
        ?\Closure $clock = null,
        private readonly string $subjectFallback = 'aicountly',
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function topology(): Topology
    {
        return $this->topology;
    }

    /** Whether any relay could be served at all, health aside. What "relay available" means in UI copy. */
    public function relayConfigured(): bool
    {
        return $this->topology->servableNodes() !== [];
    }

    /**
     * @param string $subject an opaque reference to the client (participant uuid, user uuid) bound into
     *                        the username so relay usage is attributable. Never an email address.
     * @param array{preferRegion?: string|null, forceRelay?: bool} $options
     */
    public function issue(string $subject, array $options = []): IceGrant
    {
        $now     = ($this->clock)();
        $subject = CredentialMinter::subject($subject, $this->subjectFallback);
        $chosen  = $this->select($options['preferRegion'] ?? null, $now);

        $iceServers = [];
        $stun       = [];
        $relayNodes = [];
        $expiresAt  = null;

        foreach ($chosen['nodes'] as $node) {
            $credentials = $node->credentialsFor($subject, $this->topology->ttlSeconds, $now);
            if ($credentials === null) {
                continue;
            }

            if (($url = $node->stunUrl()) !== null) {
                $stun[] = $url;
            }

            $iceServers[] = [
                'urls'       => $node->turnUrls(),
                'username'   => $credentials['username'],
                'credential' => $credentials['credential'],
            ];

            if ($credentials['expiresAt'] !== null) {
                $expiresAt = $expiresAt === null ? $credentials['expiresAt'] : min($expiresAt, $credentials['expiresAt']);
            }

            $relayNodes[] = $node->describe() + ['health' => $chosen['health'][$node->id] ?? 'unknown'];
        }

        $stun = array_values(array_unique([...$stun, ...$this->topology->stunUrls()]));
        if ($stun !== []) {
            array_unshift($iceServers, ['urls' => $stun]);
        }

        return new IceGrant(
            iceServers: $iceServers,
            relayNodes: $relayNodes,
            issuedAt: $now,
            expiresAt: $expiresAt,
            // Relay-only with no relay would be a configuration that can never connect; refuse to
            // produce one rather than hand out a guaranteed failure.
            forceRelay: ($options['forceRelay'] ?? $this->topology->forceRelay) && $relayNodes !== [],
            candidatePoolSize: $this->topology->candidatePoolSize,
            degraded: $chosen['degraded'],
            failover: $chosen['failover'],
        );
    }

    /**
     * @return array{nodes: list<TurnNode>, degraded: bool, failover: bool, health: array<string, string>}
     */
    public function select(?string $preferRegion, ?int $now = null): array
    {
        $now       = $now ?? ($this->clock)();
        $servable  = $this->topology->servableNodes();
        $health    = $this->health?->states($now) ?? [];
        $isHealthy = static fn (TurnNode $n): bool => ($health[$n->id] ?? 'unknown') !== 'down';

        $active  = array_filter($servable, static fn (TurnNode $n): bool => $n->status === NodeStatus::Active);
        $standby = array_filter($servable, static fn (TurnNode $n): bool => $n->status === NodeStatus::Standby);

        $degraded = false;
        $failover = false;
        $pool     = array_filter($active, $isHealthy);

        if ($pool === []) {
            $pool     = array_filter($standby, $isHealthy);
            $failover = $pool !== [];
        }

        if ($pool === [] && $servable !== []) {
            $pool     = $active !== [] ? $active : $standby;
            $degraded = true;
        }

        $region = strtolower(trim((string) $preferRegion));
        $pool   = array_values($pool);

        usort($pool, static function (TurnNode $a, TurnNode $b) use ($region): int {
            if ($region !== '') {
                $byRegion = ($b->region === $region) <=> ($a->region === $region);
                if ($byRegion !== 0) {
                    return $byRegion;
                }
            }

            return [$a->priority, $a->id] <=> [$b->priority, $b->id];
        });

        return [
            'nodes'    => array_slice($pool, 0, $this->topology->maxRelayNodes),
            'degraded' => $degraded,
            'failover' => $failover,
            'health'   => $health,
        ];
    }

    /**
     * Every configured node and what is known about it — for a health endpoint or an operator.
     * Carries no secret and no credential.
     *
     * @return array{source: string, relayConfigured: bool, nodes: list<array<string, mixed>>, problems: list<string>}
     */
    public function status(): array
    {
        $now    = ($this->clock)();
        $health = $this->health?->states($now) ?? [];

        return [
            'source'          => $this->topology->source,
            'relayConfigured' => $this->relayConfigured(),
            'nodes'           => array_map(
                static fn (TurnNode $n): array => $n->describe() + [
                    'servable' => $n->isServable(),
                    'health'   => $health[$n->id] ?? 'unknown',
                ],
                $this->topology->nodes(),
            ),
            'problems' => $this->topology->problems(),
        ];
    }
}
