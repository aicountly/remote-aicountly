<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * Decides which relays a client gets and mints its credentials. The one entry point a product calls.
 *
 * Nodes are **regional peers**: every `active` node serves, simultaneously, and each client is offered
 * the best of them for it — the nearest, unless that one is unhealthy, busy or out of room for the
 * client's licence tier, in which case the next. Fallback is therefore reciprocal by construction:
 * there is no primary whose failure is a special case. `standby` remains for a node that should serve
 * only when no active one can.
 *
 * Per request, for a client of a tier (`paid` | `free`) and a usage profile:
 *
 *   1. **Candidates** — servable nodes (not `disabled`, with credentials and URLs) that serve the tier.
 *   2. **Admission** — each candidate's committed capacity comes from the lease books (and, when fresh,
 *      measured traffic); `Admission::evaluate` says whether this client may be added. Enforced only in
 *      `enforce` mode; `observe` computes and audits the same verdicts and denies nothing.
 *   3. **Pool** — active nodes that are not down and (when enforcing) admit the client. If none of them
 *      is fully working, usable standby nodes join (`failover`). If everything admissible is known
 *      down, all of it is offered flagged `degraded`: a probe's opinion is not proof. Capacity is never
 *      overridden this way — a full node stays out.
 *   4. **Rank** — fully working nodes first; then by cost = proximity (measured RTT, learned RTT, geo,
 *      region hint) + a load penalty that grows from `busyAt` + a health penalty − a stickiness bonus
 *      for the node a renewing client is already on. Costs are quantised to the balance band, and
 *      nodes in the same band are ordered by utilization, so nearly-equidistant nodes share load.
 *   5. **Cap** — at most `maxRelayNodes` (every TURN URL is an allocation a browser makes).
 *   6. **Reserve** — the books are updated in the same locked step as the decision, so two requests can
 *      never both take the last slot.
 *
 * If nothing can be offered because of capacity, the client gets STUN only and `admission.outcome =
 * denied` with the reason; a product says "relay capacity is limited" rather than "no relay configured".
 *
 * Each node gets its own credential, minted with its own secret, so nodes need not share one. The
 * username carries the tier (`<expiry>:p.<subject>`), so the relay's own logs attribute traffic to a
 * tier independently of these books.
 */
final class IceIssuer
{
    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param \Closure(string, array<string, mixed>): void|null $audit told of every decision, as
     *        `('issue', {tier, profile, outcome, reason, nodes, …})` — a product writes it to its log.
     *        Carries no subject, credential or address.
     */
    public function __construct(
        private readonly Topology $topology,
        private readonly ?HealthStore $health = null,
        ?\Closure $clock = null,
        private readonly string $subjectFallback = 'aicountly',
        private readonly ?LeaseStore $leases = null,
        private readonly ?LoadSource $load = null,
        private readonly ?LatencyStore $latency = null,
        private readonly ?\Closure $audit = null,
        /** @var list<string> why `enforce` was held back to `observe` (see IceRuntime), shown by status() */
        private readonly array $enforceBlockedBy = [],
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
     * @param array{
     *     preferRegion?: string|null, client?: ClientContext, tier?: Tier|string, profile?: string,
     *     sessionRef?: string, product?: string, forceRelay?: bool, ttl?: int
     * } $options `ttl` overrides the credential lifetime for a client that cannot renew (see
     *        Topology::sessionBoundTtl()); `tier` is what the product's licence lookup said, and anything
     *        but an explicit `paid` is `free`; `sessionRef` names the product session the lease belongs to
     *        so it can be released when the session ends.
     */
    public function issue(string $subject, array $options = []): IceGrant
    {
        $now     = ($this->clock)();
        $clean   = CredentialMinter::subject($subject, $this->subjectFallback);
        $tier    = ($options['tier'] ?? null) instanceof Tier ? $options['tier'] : Tier::parse($options['tier'] ?? null);
        $profile = (string) ($options['profile'] ?? 'default');
        $product = (string) ($options['product'] ?? $this->subjectFallback);
        $session = (string) ($options['sessionRef'] ?? '');
        $client  = $options['client'] ?? ClientContext::fromRequest(['region' => $options['preferRegion'] ?? null]);
        $ttl     = isset($options['ttl']) ? CredentialMinter::clampTtl((int) $options['ttl']) : $this->topology->ttlSeconds;
        $policy  = $this->topology->admission;

        $request = ['tier' => $tier, 'client' => $client, 'profile' => $profile, 'now' => $now];
        $plan       = null;
        $ledgerDown = false;

        if ($policy->active() && $this->leases !== null) {
            try {
                $plan = $this->leases->transact(function (LeaseBook $book) use ($request, $clean, $product, $session, $ttl, $now): array {
                    $plan = $this->plan($request, $book, $product, $session, $clean);
                    $this->record($book, $plan, $product, $session, $clean, $request['profile'], $request['tier'], $ttl, $now);

                    return $plan;
                }, $now);
                if ($this->leases instanceof FileLeaseStore && $this->leases->repairs() !== []) {
                    $this->audit('ledger_repaired', ['repairs' => $this->leases->repairs()]);
                }
            } catch (\Throwable $e) {
                // The books are unavailable (disk full, database down). Paid clients are served unbooked; whether free
                // ones are is decided below. Say so either way.
                $this->audit('ledger_error', ['error' => $e::class]);
                $ledgerDown = true;
            }
        }

        $plan ??= $this->plan($request, null, $product, $session, $clean);

        // The books are down and admission is enforced. Paid clients are served unbooked — the reserve exists for
        // them — but free clients are refused: with no books nobody can say the free share still has room, and
        // an outage of the bookkeeping must not become a way to exceed it.
        if ($ledgerDown && $policy->enforcing() && $tier === Tier::Free && $plan['nodes'] !== []) {
            $plan = ['nodes' => [], 'urls' => [], 'degraded' => false, 'failover' => false, 'wouldDeny' => false, 'decisions' => [], 'proximity' => [], 'health' => $plan['health'],
                'admission' => ['outcome' => 'denied', 'reason' => 'ledger_unavailable']];
        }

        $iceServers = [];
        $stun       = [];
        $relayNodes = [];
        $expiresAt  = null;
        $username   = $tier->letter() . '.' . $clean;

        foreach ($plan['nodes'] as $node) {
            $credentials = $node->credentialsFor($username, $ttl, $now);
            if ($credentials === null) {
                continue;
            }

            if (($url = $node->stunUrl()) !== null) {
                $stun[] = $url;
            }

            $iceServers[] = [
                'urls'       => $plan['urls'][$node->id],
                'username'   => $credentials['username'],
                'credential' => $credentials['credential'],
            ];

            if ($credentials['expiresAt'] !== null) {
                $expiresAt = $expiresAt === null ? $credentials['expiresAt'] : min($expiresAt, $credentials['expiresAt']);
            }

            $relayNodes[] = ['urls' => $plan['urls'][$node->id]] + $node->describe() + [
                'health'    => $plan['health'][$node->id] ?? 'unknown',
                'load'      => $plan['decisions'][$node->id]->load ?? 'normal',
                'proximity' => $plan['proximity'][$node->id] ?? ['ms' => null, 'basis' => 'unknown'],
            ];
        }

        $stun = array_values(array_unique([...$stun, ...$this->topology->stunUrls()]));
        if ($stun !== []) {
            array_unshift($iceServers, ['urls' => $stun]);
        }

        $this->audit('issue', [
            'tier' => $tier->value, 'profile' => $profile, 'product' => $product, 'mode' => $policy->mode,
            'outcome' => $plan['admission']['outcome'], 'reason' => $plan['admission']['reason'],
            'wouldDeny' => $plan['wouldDeny'], 'failover' => $plan['failover'], 'degraded' => $plan['degraded'],
            'nodes' => array_map(static fn (array $n): string => (string) $n['id'], $relayNodes),
            'load' => array_map(static fn (AdmissionDecision $d): string => $d->load, $plan['decisions']),
        ]);

        return new IceGrant(
            iceServers: $iceServers,
            relayNodes: $relayNodes,
            issuedAt: $now,
            expiresAt: $expiresAt,
            // Relay-only with no relay would be a configuration that can never connect; refuse to
            // produce one rather than hand out a guaranteed failure.
            forceRelay: ($options['forceRelay'] ?? $this->topology->forceRelay) && $relayNodes !== [],
            candidatePoolSize: $this->topology->candidatePoolSize,
            degraded: $plan['degraded'],
            failover: $plan['failover'],
            restartOnRenewal: $this->topology->restartOnRenewal,
            admission: ['tier' => $tier->value] + $plan['admission'] + ['mode' => $policy->mode],
        );
    }

    /**
     * Which nodes a client is given, ignoring tiers and the books — the health-and-proximity half of
     * the decision, for callers (and tests) that predate admission. A paid client is assumed, so no
     * capacity rule can remove a node.
     *
     * @return array{nodes: list<TurnNode>, degraded: bool, failover: bool, health: array<string, string>}
     */
    public function select(?string $preferRegion, ?int $now = null): array
    {
        $now  = $now ?? ($this->clock)();
        $plan = $this->plan(
            ['tier' => Tier::Paid, 'client' => ClientContext::fromRequest(['region' => $preferRegion]), 'profile' => 'default', 'now' => $now],
            null,
            $this->subjectFallback,
            '',
            '',
        );

        return ['nodes' => $plan['nodes'], 'degraded' => $plan['degraded'], 'failover' => $plan['failover'], 'health' => $plan['health']];
    }

    /**
     * The decision itself. Pure apart from reading the health, load and latency files; it writes
     * nothing — `record()` does, inside the same locked step.
     *
     * @param array{tier: Tier, client: ClientContext, profile: string, now: int} $request
     * @return array{
     *     nodes: list<TurnNode>, urls: array<string, list<string>>, degraded: bool, failover: bool, health: array<string, string>,
     *     decisions: array<string, AdmissionDecision>, proximity: array<string, array{ms: float, basis: string}>,
     *     admission: array{outcome: string, reason: string}, wouldDeny: bool
     * }
     */
    private function plan(array $request, ?LeaseBook $book, string $product, string $session, string $subject): array
    {
        $now      = $request['now'];
        $tier     = $request['tier'];
        $client   = $request['client'];
        $policy   = $this->topology->admission;
        $servable = $this->topology->servableNodes();
        $health   = $this->health?->states($now) ?? [];
        $state    = static fn (TurnNode $n): string => $health[$n->id] ?? 'unknown';

        $empty = static fn (string $outcome, string $reason): array => [
            'nodes' => [], 'urls' => [], 'degraded' => false, 'failover' => false, 'health' => $health, 'decisions' => [],
            'proximity' => [], 'admission' => ['outcome' => $outcome, 'reason' => $reason], 'wouldDeny' => false,
        ];

        if ($servable === []) {
            return $empty('unconfigured', 'no_relay_configured');
        }

        // --- admission, per node ------------------------------------------------------------------
        $own      = $book === null ? [] : array_map(static fn (Lease $l): string => $l->key, $book->forClient($product, $session, $subject));
        $onNodes  = $book === null ? [] : array_map(static fn (Lease $l): string => $l->nodeId, array_filter($book->forClient($product, $session, $subject), static fn (Lease $l): bool => $l->primary || ($l->reported && $l->bps > 0)));
        // Leases this client already holds. Only one that carries a charge (its first node, or the node it reported
        // relaying through) makes it a *renewal* there; a one-allocation fallback lease does not, or holding one
        // would exempt the client from the shed threshold on a node it has never really used.
        $held     = $book === null ? [] : $book->forClient($product, $session, $subject);
        $ownBy    = [];
        $charged  = [];
        foreach ($held as $lease) {
            $ownBy[$lease->nodeId]['allocations'] = ($ownBy[$lease->nodeId]['allocations'] ?? 0) + $lease->allocations;
            $ownBy[$lease->nodeId]['bps']         = ($ownBy[$lease->nodeId]['bps'] ?? 0) + $lease->bps;
            if ($lease->primary || $lease->bps > 0) {
                $charged[$lease->nodeId] = true;
            }
        }
        $measured = $policy->active() && $this->load !== null ? $this->load->fresh($now) : [];
        $profile  = $policy->bpsFor($request['profile']);

        $decisions = [];
        foreach ($servable as $node) {
            $decisions[$node->id] = Admission::evaluate(
                $node->capacity,
                $policy,
                $book === null ? ['paid' => [], 'free' => []] : $book->usage($node->id, $own),
                $measured[$node->id] ?? null,
                $tier,
                ['allocations' => max(1, count($node->turnUrls())), 'bps' => $profile],
                isset($charged[$node->id]),
                $ownBy[$node->id] ?? [],
            );
        }

        $enforce  = $policy->enforcing() && $book !== null;
        $eligible = static fn (TurnNode $n): bool => ! $enforce ? $decisions[$n->id]->reason !== AdmissionDecision::TIER_NOT_SERVED
            : $decisions[$n->id]->admitted;

        // --- pool: which nodes may be offered at all ---------------------------------------------
        $usable  = static fn (TurnNode $n): bool => $state($n) !== 'down';
        // "No information" counts as working: a probe that has not run says nothing against a node.
        $working = static fn (TurnNode $n): bool => in_array($state($n), ['up', 'unknown', 'stale'], true);

        $candidates = array_values(array_filter($servable, $eligible));
        $active     = array_values(array_filter($candidates, static fn (TurnNode $n): bool => $n->status === NodeStatus::Active));
        $standby    = array_values(array_filter($candidates, static fn (TurnNode $n): bool => $n->status === NodeStatus::Standby));

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

        if ($pool === [] && $candidates !== []) {
            $pool     = [...$active, ...$standby];
            $degraded = true;
        }

        if ($pool === []) {
            // Everything servable was refused on capacity (or does not serve this tier).
            $reasons = array_map(static fn (AdmissionDecision $d): string => $d->reason, $decisions);
            $reason  = in_array(AdmissionDecision::FREE_POOL_FULL, $reasons, true) ? AdmissionDecision::FREE_POOL_FULL
                : (in_array(AdmissionDecision::NODE_OVERLOADED, $reasons, true) ? AdmissionDecision::NODE_OVERLOADED
                : (in_array(AdmissionDecision::NODE_FULL, $reasons, true) ? AdmissionDecision::NODE_FULL : AdmissionDecision::TIER_NOT_SERVED));

            return ['health' => $health, 'decisions' => $decisions] + $empty('denied', $reason);
        }

        // --- rank ---------------------------------------------------------------------------------
        $learned   = $this->latency !== null && $client->region !== '' ? $this->latency->learned($client->region, $now) : [];
        $proximity = [];
        $cost      = [];
        foreach ($pool as $node) {
            $proximity[$node->id] = Proximity::estimate($node, $client, $this->topology->nodes(), $learned);
            $cost[$node->id]      = $proximity[$node->id]['ms']
                + Admission::loadPenaltyMs($decisions[$node->id]->utilization, $policy)
                + (in_array($state($node), ['degraded', 'failing'], true) ? 30.0 : 0.0)
                // A host's paid-only instance is where a paid client belongs; the shared one is its overflow.
                - ($node->capacity->exclusiveTo($tier) && $tier === Tier::Paid ? 12.0 : 0.0)
                - (in_array($node->id, $onNodes, true) ? 20.0 : 0.0);
        }

        $band = max(1, $policy->balanceBandMs);
        usort($pool, static function (TurnNode $a, TurnNode $b) use ($working, $cost, $band, $decisions): int {
            return [(int) $working($b), (int) floor($cost[$a->id] / $band), $decisions[$a->id]->utilization, $a->priority, $a->id]
                <=> [(int) $working($a), (int) floor($cost[$b->id] / $band), $decisions[$b->id]->utilization, $b->priority, $b->id];
        });

        $nodes = array_slice($pool, 0, $this->topology->maxRelayNodes);

        // The first node gets every URL it has; any other is a way out and gets only the fallback transports.
        $urls = [];
        foreach ($nodes as $i => $node) {
            $urls[$node->id] = $i === 0 ? $node->turnUrls() : $node->urlsFor($this->topology->fallbackTransports);
        }

        // What the same request would have been in enforce mode — `observe`'s whole purpose.
        $wouldDeny = $policy->mode === 'observe' && array_filter($servable, static fn (TurnNode $n): bool => $decisions[$n->id]->admitted) === [];

        $metered = array_filter($nodes, static fn (TurnNode $n): bool => $n->capacity->metered()) !== [];

        return [
            'nodes'     => $nodes,
            'urls'      => $urls,
            'degraded'  => $degraded,
            'failover'  => $failover,
            'health'    => $health,
            'decisions' => $decisions,
            'proximity' => $proximity,
            'admission' => ['outcome' => $metered ? 'admitted' : 'unmetered', 'reason' => $decisions[$nodes[0]->id]->reason],
            'wouldDeny' => $wouldDeny,
        ];
    }

    /**
     * Write the lease for each node served, and drop this client's leases on nodes it no longer gets.
     * Called inside the lock that made the decision.
     */
    private function record(LeaseBook $book, array $plan, string $product, string $session, string $subject, string $profile, Tier $tier, int $ttl, int $now): void
    {
        if ($plan['nodes'] === []) {
            return;
        }

        $existing = [];
        foreach ($book->forClient($product, $session, $subject) as $lease) {
            $existing[$lease->nodeId] = $lease;
        }

        // Where the bandwidth is charged. Until the client reports how it connected, to the first node
        // (where it is expected to relay). Once it has, the report stands across renewals — verified against
        // the nodes the client really holds (noteConnection), and a claim of "direct" still costs a residual
        // share, because nobody can check it.
        $servedIds = array_map(static fn (TurnNode $n): string => $n->id, $plan['nodes']);
        $reported  = array_filter($existing, static fn (Lease $l): bool => $l->reported) !== [];
        $hadRelay  = array_filter($existing, static fn (Lease $l): bool => $l->reported && $l->bps > $this->topology->admission->directBps($l->profile)) !== [];
        $carrier   = null;
        foreach ($existing as $lease) {
            if ($lease->reported && $hadRelay && $lease->bps > $this->topology->admission->directBps($lease->profile) && in_array($lease->nodeId, $servedIds, true)) {
                $carrier = $lease->nodeId;
            }
        }

        $served = [];
        foreach ($plan['nodes'] as $i => $node) {
            $key      = Lease::keyFor($product, $session, $subject, $node->id);
            $served[] = $key;
            $allocs   = max(1, count($plan['urls'][$node->id]));
            $bps      = $this->topology->admission->bpsFor($profile);
            $residual = $this->topology->admission->directBps($profile);
            $charge   = match (true) {
                $reported && $carrier !== null => $node->id === $carrier ? $bps : 0,
                // It reported a direct connection: only the residual is charged, to its first node.
                $reported && ! $hadRelay       => $i === 0 ? $residual : 0,
                // It was relaying through a node it no longer gets: the first node takes over.
                default                        => $i === 0 ? $bps : 0,
            };

            $book->put(isset($existing[$node->id])
                ? $existing[$node->id]->renewed($tier, $profile, $allocs, $charge, $now + $ttl, $reported, $i === 0)
                : new Lease($key, $node->id, $tier, $product, $profile, $subject, $session, $allocs, $charge, $now, $now + $ttl, $reported, $i === 0));
        }

        // A node this client is no longer offered still holds the allocation it opened there, on a credential
        // that stays valid until it expires — so the slots stay booked until then. Only the bandwidth moves.
        foreach ($existing as $lease) {
            if (! in_array($lease->key, $served, true) && $lease->bps > 0) {
                $book->put($lease->reportedWith(0));
            }
        }
    }

    /**
     * A client reported how it connected: move the bandwidth charge to where the traffic really is.
     * `direct` means no relayed bytes at all (the allocations still occupy slots); `relay` charges the
     * node the client says it selected. Anything else leaves the estimate alone.
     */
    public function noteConnection(string $subject, string $sessionRef, string $path, ?string $nodeId, ?string $product = null): void
    {
        if (! $this->topology->admission->active() || $this->leases === null || ! in_array($path, ['direct', 'relay'], true)) {
            return;
        }

        $subject = CredentialMinter::subject($subject, $this->subjectFallback);
        $product ??= $this->subjectFallback;
        $policy  = $this->topology->admission;
        $now     = ($this->clock)();
        $nodes   = $this->topology->servableNodes();

        try {
            $moved = $this->leases->transact(function (LeaseBook $book) use ($product, $sessionRef, $subject, $path, $nodeId, $policy, $nodes): string {
                $held = $book->forClient($product, $sessionRef, $subject);

                // A report is the client's word. It may only choose among nodes it was really given.
                $target = null;
                foreach ($held as $lease) {
                    if ($path === 'relay' && $lease->nodeId === $nodeId) {
                        $target = $lease;
                    }
                }
                if ($held === [] || ($path === 'relay' && $target === null)) {
                    return 'ignored';
                }

                $profile = $held[0]->profile;
                $ask     = $path === 'direct' ? $policy->directBps($profile) : $policy->bpsFor($profile);

                // Moving the charge to another node must fit THERE, by the same rule as an admission: a client
                // cannot take a fallback node's bandwidth past the free share by saying it relays through it.
                if ($path === 'relay' && ! $target->primary && $target->bps === 0) {
                    $node = null;
                    foreach ($nodes as $candidate) {
                        $node = $candidate->id === $target->nodeId ? $candidate : $node;
                    }
                    $others = array_map(static fn (Lease $l): string => $l->key, array_filter($held, static fn (Lease $l): bool => $l->nodeId === $target->nodeId));
                    $fits = $node === null || Admission::evaluate(
                        $node->capacity,
                        $policy,
                        $book->usage($node->id, $others),
                        null,
                        $target->tier,
                        ['allocations' => $target->allocations, 'bps' => $ask],
                        true,
                    )->admitted;
                    if (! $fits) {
                        return 'refused';
                    }
                }

                $first = true;
                foreach ($held as $lease) {
                    $carries = $path === 'relay' ? $lease->nodeId === $nodeId : ($lease->primary && $first);
                    $bps     = $carries ? $ask : 0;
                    $first   = $first && ! $lease->primary;
                    if ($bps !== $lease->bps || ! $lease->reported) {
                        $book->put($lease->reportedWith($bps));
                    }
                }

                return 'moved';
            }, $now);

            if ($moved === 'refused') {
                $this->audit('charge_refused', ['reason' => 'no_room_on_reported_node']);
            }
        } catch (\Throwable $e) {
            $this->audit('ledger_error', ['error' => $e::class]);
        }
    }

    /**
     * A product session ended (or a participant left): free what it held. Safe to call twice, and
     * safe to skip — leases expire with their credentials — but until it is called capacity stays
     * reserved for sessions that have already finished.
     *
     * @return int leases released
     */
    public function release(string $sessionRef, ?string $subject = null, ?string $product = null): int
    {
        if (! $this->topology->admission->active() || $this->leases === null || $sessionRef === '') {
            return 0;
        }

        $product ??= $this->subjectFallback;
        $subject  = $subject === null ? null : CredentialMinter::subject($subject, $this->subjectFallback);

        try {
            return (int) $this->leases->transact(
                static fn (LeaseBook $book): int => $book->releaseSession($product, $sessionRef, $subject),
                ($this->clock)(),
            );
        } catch (\Throwable $e) {
            $this->audit('ledger_error', ['error' => $e::class]);

            return 0;
        }
    }

    /**
     * Fold a client's own latency measurements into what is learned for its region — only for a region this
     * deployment knows (a node's, an edge-mapped one, or one the operator lists), so a client cannot create
     * buckets, and one sample per client (`$clientKey`, kept only as a hash).
     */
    public function learnLatency(ClientContext $client, string $clientKey = ''): void
    {
        if ($this->latency === null || $client->rtt === [] || ! in_array($client->region, $this->topology->knownRegions(), true)) {
            return;
        }

        $this->latency->observe($client->region, $client->rtt, array_map(static fn (TurnNode $n): string => $n->id, $this->topology->nodes()), ($this->clock)(), $clientKey);
    }

    /**
     * What a new client of a tier in a region would be offered right now, without recording anything.
     * For `probe.php --status --explain` and tests; it reads the books but never writes them.
     *
     * @return array{nodes: list<string>, outcome: string, reason: string, basis: string}
     */
    public function wouldIssue(string $tier, string $region): array
    {
        $now  = ($this->clock)();
        $plan = $this->plan(
            ['tier' => Tier::parse($tier), 'client' => ClientContext::fromRequest(['region' => $region]), 'profile' => 'default', 'now' => $now],
            $this->snapshot($now),
            $this->subjectFallback,
            '',
            '',
        );

        return [
            'nodes'   => array_map(static fn (TurnNode $n): string => $n->id, $plan['nodes']),
            'outcome' => $plan['admission']['outcome'],
            'reason'  => $plan['admission']['reason'],
            'basis'   => $plan['nodes'] === [] ? '' : ($plan['proximity'][$plan['nodes'][0]->id]['basis'] ?? ''),
        ];
    }

    /**
     * Every configured node and what is known about it — for a health endpoint or an operator.
     * Carries no secret and no credential.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $now      = ($this->clock)();
        $policy   = $this->topology->admission;
        $health   = $this->health?->states($now) ?? [];
        $measured = $this->load?->fresh($now) ?? [];
        $serving  = [];
        foreach ([Tier::Paid, Tier::Free] as $tier) {
            $serving[$tier->value] = array_map(
                static fn (TurnNode $n): string => $n->id,
                $this->plan(['tier' => $tier, 'client' => new ClientContext(), 'profile' => 'default', 'now' => $now], $this->snapshot($now), $this->subjectFallback, '', '')['nodes'],
            );
        }

        $book = $this->snapshot($now);

        return [
            'source'          => $this->topology->source,
            'relayConfigured' => $this->relayConfigured(),
            'admission'       => $policy->toArray(),
            // Non-empty: the operator asked for enforce, but a precondition (licence mapping, feed) is unmet, so the
            // application is OBSERVING. Nothing is denied until every reason here is gone.
            'enforceBlockedBy' => $this->enforceBlockedBy,
            // `unavailable` means the usage below is absent, not zero: the lease books could not be read.
            'books'           => $this->leases === null ? 'none' : ($book === null ? 'unavailable' : 'ok'),
            'nodes'           => array_map(
                function (TurnNode $n) use ($health, $serving, $book, $measured, $policy, $now): array {
                    $token     = $health[$n->id] ?? 'unknown';
                    $condition = HealthStore::condition($token);
                    $usage     = $book?->usage($n->id);
                    $decision  = Admission::evaluate(
                        $n->capacity,
                        $policy,
                        $usage ?? ['paid' => [], 'free' => []],
                        $measured[$n->id] ?? null,
                        Tier::Free,
                        ['allocations' => max(1, count($n->turnUrls())), 'bps' => $policy->bpsFor('default')],
                    );

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
                        // Whether a client asking right now would be given this node, by tier.
                        'serving'   => in_array($n->id, $serving['paid'], true) || in_array($n->id, $serving['free'], true),
                        'servingBy' => ['paid' => in_array($n->id, $serving['paid'], true), 'free' => in_array($n->id, $serving['free'], true)],
                        'load'      => $decision->load,
                        'capacity'  => $n->capacity->toArray(),
                        // The four things capacity is counted in, kept apart (see Lease):
                        'sessions'    => ['paid' => $usage['paid']['sessions'] ?? 0, 'free' => $usage['free']['sessions'] ?? 0],
                        'allocations' => ['reserved' => ['paid' => $usage['paid']['allocations'] ?? 0, 'free' => $usage['free']['allocations'] ?? 0], 'measured' => $measured[$n->id]['allocations'] ?? null],
                        'bandwidth'   => ['reservedBps' => ['paid' => $usage['paid']['bps'] ?? 0, 'free' => $usage['free']['bps'] ?? 0], 'measuredBps' => $measured[$n->id]['bps'] ?? null],
                        'utilization' => round($decision->utilization, 3),
                        'headroom'    => $decision->headroom,
                        'telemetryAgeSeconds' => $measured[$n->id]['ageSeconds'] ?? null,
                    ];
                },
                $this->topology->nodes(),
            ),
            'problems' => $this->topology->problems(),
            'warnings' => $this->topology->warnings(),
        ];
    }

    private function snapshot(int $now): ?LeaseBook
    {
        try {
            return $this->leases?->snapshot($now);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $data */
    private function audit(string $event, array $data): void
    {
        if ($this->audit !== null) {
            try {
                ($this->audit)($event, $data);
            } catch (\Throwable) {
                // Logging must never decide whether someone gets a relay.
            }
        }
    }
}
