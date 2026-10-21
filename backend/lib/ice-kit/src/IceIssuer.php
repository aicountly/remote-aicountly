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
     * @param array{preferRegion?: string|null, forceRelay?: bool, ttl?: int} $options `ttl` overrides the
     *        credential lifetime for a client that cannot renew; see Topology::sessionBoundTtl()
     */
    public function issue(string $subject, array $options = []): IceGrant
    {
        $now     = ($this->clock)();
        $subject = CredentialMinter::subject($subject, $this->subjectFallback);
        $chosen  = $this->select($options['preferRegion'] ?? null, $now);

        $ttl        = isset($options['ttl']) ? CredentialMinter::clampTtl((int) $options['ttl']) : $this->topology->ttlSeconds;
        $iceServers = [];
        $stun       = [];
        $relayNodes = [];
        $expiresAt  = null;

        foreach ($chosen['nodes'] as $node) {
            $credentials = $node->credentialsFor($subject, $ttl, $now);
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
            restartOnRenewal: $this->topology->restartOnRenewal,
        );
    }

    /**
     * Which nodes a client is given, and why.
     *
     *   1. **Active nodes that are not known down.** A node declared down by repeated probes is never
     *      handed out beside one that works.
     *   2. **Standby nodes join** whenever no active node is fully healthy — the primary is down, has
     *      lost a transport, or has just failed a probe. Offering both costs a credential and one more
     *      allocation attempt; waiting for a second failed probe costs up to ten minutes of dead relay
     *      for every new session. A fully healthy primary is served alone.
     *   3. **Last resort.** If everything servable is known down, all of it is offered, active first
     *      (`degraded`): any of them may have recovered since the last probe, and offering nothing
     *      cannot be better.
     *
     * Fully working nodes lead, then the region hint, then priority, and the cap on nodes per client
     * is applied after that — so it can never cut a working standby in favour of a degraded primary.
     * A `disabled` node is not in `servable()` at all, so none of this can ever reach it.
     *
     * @return array{nodes: list<TurnNode>, degraded: bool, failover: bool, health: array<string, string>}
     */
    public function select(?string $preferRegion, ?int $now = null): array
    {
        $now      = $now ?? ($this->clock)();
        $servable = $this->topology->servableNodes();
        $health   = $this->health?->states($now) ?? [];
        $state    = static fn (TurnNode $n): string => $health[$n->id] ?? 'unknown';

        $usable = static fn (TurnNode $n): bool => $state($n) !== 'down';
        // "No information" counts as working: a probe that has not run says nothing against a node.
        $working = static fn (TurnNode $n): bool => in_array($state($n), ['up', 'unknown', 'stale'], true);

        $active  = array_values(array_filter($servable, static fn (TurnNode $n): bool => $n->status === NodeStatus::Active));
        $standby = array_values(array_filter($servable, static fn (TurnNode $n): bool => $n->status === NodeStatus::Standby));

        $pool     = array_values(array_filter($active, $usable));
        $failover = false;
        $degraded = false;

        if (array_filter($pool, $working) === []) {
            $reserve = array_values(array_filter($standby, $usable));
            if ($reserve !== []) {
                $pool     = [...$pool, ...$reserve];
                $failover = true;
            }
        }

        if ($pool === [] && $servable !== []) {
            $pool     = [...$active, ...$standby];
            $degraded = true;
        }

        $region = strtolower(trim((string) $preferRegion));

        usort($pool, static function (TurnNode $a, TurnNode $b) use ($region, $working): int {
            $byHealth = ($working($b) <=> $working($a));
            if ($byHealth !== 0) {
                return $byHealth;
            }

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
     * @return array{source: string, relayConfigured: bool, nodes: list<array<string, mixed>>, problems: list<string>, warnings: list<string>}
     */
    public function status(): array
    {
        $now     = ($this->clock)();
        $health  = $this->health?->states($now) ?? [];
        $serving = array_map(static fn (TurnNode $n): string => $n->id, $this->select(null, $now)['nodes']);

        return [
            'source'          => $this->topology->source,
            'relayConfigured' => $this->relayConfigured(),
            'nodes'           => array_map(
                static function (TurnNode $n) use ($health, $serving): array {
                    $token     = $health[$n->id] ?? 'unknown';
                    $condition = HealthStore::condition($token);

                    return $n->describe() + [
                        'role'      => $n->status->value,
                        'servable'  => $n->isServable(),
                        'health'    => $token,
                        'condition' => $condition,
                        // One word for a dashboard: disabled, unreachable, standby, or the condition.
                        'state'     => match (true) {
                            $n->status === NodeStatus::Disabled => 'disabled',
                            $condition === 'unreachable'        => 'unreachable',
                            $n->status === NodeStatus::Standby  => 'standby',
                            default                             => $condition,
                        },
                        // Whether a client asking right now would be given this node.
                        'serving'   => in_array($n->id, $serving, true),
                    ];
                },
                $this->topology->nodes(),
            ),
            'problems' => $this->topology->problems(),
            'warnings' => $this->topology->warnings(),
        ];
    }
}
