<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * Every TURN node a deployment knows about, and the settings that apply to all of them.
 *
 * Read from the environment with the same keys in every AICOUNTLY product, so the infrastructure team
 * configures a relay once and both Remote and Connect agree on it:
 *
 *   WEBRTC_TURN_NODES                    = mum, ncr                 the nodes, by id
 *   WEBRTC_TURN_NODE_MUM_HOST            = turn-mum.aicountly.com   required
 *   WEBRTC_TURN_NODE_MUM_STATUS          = active                   active | standby | disabled (default)
 *   WEBRTC_TURN_NODE_MUM_PRIORITY        = 10                       lower is preferred (default 100)
 *   WEBRTC_TURN_NODE_MUM_REGION          = in-west                  matched against a client's region hint
 *   WEBRTC_TURN_NODE_MUM_LABEL           = Mumbai                   shown in diagnostics
 *   WEBRTC_TURN_NODE_MUM_TRANSPORTS      = udp, tcp, tls            default all three
 *   WEBRTC_TURN_NODE_MUM_PORT            = 3478                     TURN over UDP/TCP
 *   WEBRTC_TURN_NODE_MUM_TLS_PORT        = 443                      TURN over TLS (default 5349)
 *   WEBRTC_TURN_NODE_MUM_STUN            = true                     also offer stun:<host>:<port>
 *   WEBRTC_TURN_NODE_MUM_LAT / _LON      = 19.08 / 72.88            where the node is, for proximity routing
 *   WEBRTC_TURN_NODE_MUM_MAX_ALLOCATIONS = 4000                     concurrent allocations it sustains (0 = unmetered)
 *   WEBRTC_TURN_NODE_MUM_MAX_MBPS        = 400                      relayed rx+tx it sustains, Mbit/s (0 = unmetered)
 *   WEBRTC_TURN_NODE_MUM_TIERS           = paid,free                tiers it may serve (default both)
 *   WEBRTC_TURN_NODE_MUM_PAID_RESERVE_PERCENT                       50..90; below 50 only with an explicit _TIERS
 *   WEBRTC_TURN_ADMISSION and the rest  see AdmissionPolicy         off | observe (default) | enforce
 *   WEBRTC_TURN_NODE_MUM_SECRET          =                          per-node secret; default is the shared one
 *   WEBRTC_TURN_STATIC_AUTH_SECRET       = <coturn static-auth-secret, shared by every node>
 *   WEBRTC_TURN_TTL                      = 3600                     credential lifetime, 60..86400
 *   WEBRTC_TURN_MAX_NODES                = 2                        relay nodes per client, 1..4
 *   WEBRTC_TURN_SESSION_TTL_MAX          = 28800                    ceiling for a session-bound credential (a
 *                                                                   desktop agent, which cannot renew), 60..86400
 *   WEBRTC_TURN_FALLBACK_TRANSPORTS      = tls                      transports offered on a client's second-choice node
 *                                                                   (tls | all | a list); one allocation instead of three
 *   WEBRTC_TURN_CLIENT_REGIONS           = in-south,in-east          client regions (beside the nodes' own) whose measured
 *                                                                   latency may be learned
 *   WEBRTC_TURN_RESTART_ON_RENEWAL       = true                     whether a relayed connection is restarted
 *                                                                   onto renewed credentials (see expirySemantics)
 *   WEBRTC_STUN_URLS                     = stun:…                   extra STUN, after the nodes' own
 *   WEBRTC_FORCE_RELAY                   = false                    diagnostics: relay-only ICE
 *   WEBRTC_ICE_CANDIDATE_POOL            = 0                        0..10
 *
 * With no WEBRTC_TURN_NODES the single-pool keys (WEBRTC_TURN_URLS with WEBRTC_TURN_STATIC_AUTH_SECRET,
 * or WEBRTC_TURN_USERNAME/WEBRTC_TURN_CREDENTIAL) still work, as one node called `default`. A node that
 * is misconfigured is left out and named in problems() — a typo in one node's host must not take the
 * API, or the other node, down with it.
 */
final class Topology
{
    public const MAX_NODES_CEILING = 4;

    /**
     * @param list<TurnNode> $nodes
     * @param list<string>   $stunUrls  fallback STUN, after the selected nodes' own
     * @param list<string>   $problems  configuration errors, safe to show an operator
     */
    public function __construct(
        private readonly array $nodes,
        private readonly array $stunUrls = [],
        public readonly int $ttlSeconds = 3600,
        public readonly int $maxRelayNodes = 2,
        public readonly bool $forceRelay = false,
        public readonly int $candidatePoolSize = 0,
        public readonly string $source = 'nodes',
        private readonly array $problems = [],
        public readonly bool $restartOnRenewal = true,
        public readonly int $sessionTtlMax = 28800,
        public readonly AdmissionPolicy $admission = new AdmissionPolicy(),
        /** @var list<string> transports a client's second-choice node is offered over */
        public readonly array $fallbackTransports = ['tls'],
        /** @var list<string> client regions whose measured latency may be learned (nodes' own regions are always allowed) */
        public readonly array $clientRegions = [],
    ) {
    }

    /**
     * The credential lifetime for a client that cannot renew (a desktop agent): the rest of its session
     * plus five minutes of slack, never shorter than the ordinary lifetime and never longer than
     * `sessionTtlMax`. Short-lived is still the rule — it is just measured in sessions, not in hours.
     */
    public function sessionBoundTtl(int $secondsRemaining): int
    {
        $wanted = max($this->ttlSeconds, $secondsRemaining + 300);

        return CredentialMinter::clampTtl(min($wanted, max($this->ttlSeconds, $this->sessionTtlMax)));
    }

    /**
     * @param callable(string): (string|null) $read  reads one environment key; null or '' means unset
     * @param array{stunUrls?: list<string>}  $defaults the product's own fallbacks
     */
    public static function fromEnvironment(callable $read, array $defaults = []): self
    {
        $get = static function (string $key) use ($read): ?string {
            $value = $read($key);
            if ($value === null || $value === false) {
                return null;
            }
            $value = trim((string) $value);

            return $value === '' ? null : $value;
        };

        $stunRaw  = $get('WEBRTC_STUN_URLS');
        $stunUrls = $stunRaw !== null ? self::urlList($stunRaw, 'stuns?') : ($defaults['stunUrls'] ?? []);
        $ttl      = CredentialMinter::clampTtl((int) ($get('WEBRTC_TURN_TTL') ?? 3600));
        $maxNodes = max(1, min(self::MAX_NODES_CEILING, (int) ($get('WEBRTC_TURN_MAX_NODES') ?? 2)));
        $force    = self::bool($get('WEBRTC_FORCE_RELAY'), false);
        $restart  = self::bool($get('WEBRTC_TURN_RESTART_ON_RENEWAL'), true);
        $sessMax  = CredentialMinter::clampTtl((int) ($get('WEBRTC_TURN_SESSION_TTL_MAX') ?? 28800));
        $pool     = max(0, min(10, (int) ($get('WEBRTC_ICE_CANDIDATE_POOL') ?? 0)));
        $shared   = $get('WEBRTC_TURN_STATIC_AUTH_SECRET') ?? '';
        $fbRaw    = strtolower($get('WEBRTC_TURN_FALLBACK_TRANSPORTS') ?? 'tls');
        $fallback = $fbRaw === 'all' ? TurnNode::TRANSPORTS : array_values(array_intersect(TurnNode::TRANSPORTS, preg_split('/[\s,]+/', $fbRaw) ?: []));
        $fallback = $fallback === [] ? ['tls'] : $fallback;

        $admission = AdmissionPolicy::fromEnvironment($get);
        $ids       = $get('WEBRTC_TURN_NODES');

        if ($ids === null) {
            $legacy = self::legacyNode(
                self::urlList($get('WEBRTC_TURN_URLS') ?? $get('WEBRTC_TURN_URL') ?? '', 'turns?'),
                $shared,
                $get('WEBRTC_TURN_USERNAME') ?? '',
                $get('WEBRTC_TURN_CREDENTIAL') ?? '',
            );

            return new self($legacy === null ? [] : [$legacy], $stunUrls, $ttl, $maxNodes, $force, $pool, 'legacy', [], $restart, $sessMax, AdmissionPolicy::fromEnvironment($get), $fallback, self::regionList($get('WEBRTC_TURN_CLIENT_REGIONS')));
        }

        $nodes    = [];
        $problems = [];
        $seen     = [];

        foreach (preg_split('/[\s,]+/', strtolower($ids)) ?: [] as $id) {
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $key = static fn (string $field): string => 'WEBRTC_TURN_NODE_' . strtoupper(str_replace('-', '_', $id)) . '_' . $field;

            try {
                $tiers    = $get($key('TIERS'));
                $reserved = (int) ($get($key('PAID_RESERVE_PERCENT')) ?? $admission->paidReservePercent);

                $nodes[] = new TurnNode(
                    id: $id,
                    host: strtolower($get($key('HOST')) ?? ''),
                    status: NodeStatus::parse($get($key('STATUS'))),
                    priority: (int) ($get($key('PRIORITY')) ?? 100),
                    region: strtolower($get($key('REGION')) ?? ''),
                    label: $get($key('LABEL')) ?? '',
                    transports: preg_split('/[\s,]+/', $get($key('TRANSPORTS')) ?? 'udp,tcp,tls') ?: [],
                    port: (int) ($get($key('PORT')) ?? 3478),
                    tlsPort: (int) ($get($key('TLS_PORT')) ?? 5349),
                    offersStun: self::bool($get($key('STUN')), true),
                    secret: $get($key('SECRET')) ?? $shared,
                    lat: ($get($key('LAT')) !== null && $get($key('LON')) !== null) ? (float) $get($key('LAT')) : null,
                    lon: ($get($key('LAT')) !== null && $get($key('LON')) !== null) ? (float) $get($key('LON')) : null,
                    capacity: new Capacity(
                        max(0, (int) ($get($key('MAX_ALLOCATIONS')) ?? 0)),
                        (int) round(max(0.0, (float) ($get($key('MAX_MBPS')) ?? 0)) * 1_000_000),
                        $reserved,
                        $tiers === null ? null : self::tiers($id, $tiers),
                    ),
                );
            } catch (\InvalidArgumentException $e) {
                $problems[] = $e->getMessage();
            }
        }

        foreach ($nodes as $node) {
            if ($node->status !== NodeStatus::Disabled && ! $node->hasCredentials()) {
                $problems[] = "TURN node '{$node->id}' is {$node->status->value} but has no secret; it will not be served.";
            }
        }

        return new self($nodes, $stunUrls, $ttl, $maxNodes, $force, $pool, 'nodes', $problems, $restart, $sessMax, $admission, $fallback, self::regionList($get('WEBRTC_TURN_CLIENT_REGIONS')));
    }

    /**
     * `paid`, `free` or both. Anything else is a typo, and a typo here must not quietly make a node free-only
     * (which would hand the paid reserve to nobody) — so it leaves the node out and names it.
     *
     * @return list<Tier>
     */
    private static function tiers(string $nodeId, string $raw): array
    {
        $tiers = [];
        foreach (preg_split('/[\s,]+/', strtolower($raw)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            $tier = Tier::tryFrom($token);
            if ($tier === null) {
                throw new \InvalidArgumentException("TURN node '{$nodeId}': TIERS must be paid, free or both, not '" . substr(preg_replace('/[^a-z0-9_-]/', '', $token) ?? '', 0, 20) . "'.");
            }
            $tiers[] = $tier;
        }

        return $tiers;
    }

    /**
     * The single-pool configuration both products had before nodes existed, as one node.
     *
     * @param list<string> $turnUrls
     */
    public static function legacyNode(
        array $turnUrls,
        #[\SensitiveParameter] string $secret,
        string $username = '',
        #[\SensitiveParameter] string $credential = '',
    ): ?TurnNode {
        if ($turnUrls === []) {
            return null;
        }

        return new TurnNode(
            id: 'default',
            host: '',
            status: NodeStatus::Active,
            secret: $secret,
            staticUsername: $secret === '' ? $username : '',
            staticCredential: $secret === '' ? $credential : '',
            explicitUrls: $turnUrls,
        );
    }

    /** @return list<string> */
    private static function regionList(?string $raw): array
    {
        return array_values(array_unique(array_filter(array_map([ClientContext::class, 'region'], preg_split('/[\s,]+/', strtolower((string) $raw)) ?: []))));
    }

    /**
     * The regions a client's measured latency may be filed under: where the nodes are, where the edge says
     * clients are, and any the operator lists. Anything else is ignored, so a client cannot create buckets.
     *
     * @return list<string>
     */
    public function knownRegions(): array
    {
        return array_values(array_unique(array_filter([
            ...array_map(static fn (TurnNode $n): string => $n->region, $this->nodes),
            ...$this->clientRegions,
        ])));
    }

    /** @return list<TurnNode> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function node(string $id): ?TurnNode
    {
        foreach ($this->nodes as $node) {
            if ($node->id === $id) {
                return $node;
            }
        }

        return null;
    }

    /** @return list<TurnNode> */
    public function servableNodes(): array
    {
        return array_values(array_filter($this->nodes, static fn (TurnNode $n): bool => $n->isServable()));
    }

    /** @return list<string> */
    public function stunUrls(): array
    {
        return $this->stunUrls;
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * Things that work but are unsafe or probably unintended. Never fatal, never containing a secret.
     *
     * Two nodes sharing a secret is the one that matters: a credential minted for one is valid at the
     * other, so a relay that is compromised — or simply run by someone else — can replay at its
     * sibling the credentials it is handed. Disabled nodes are included on purpose: the time to find
     * out is before a node is switched on, not after.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];
        $rest     = array_values(array_filter($this->nodes, static fn (TurnNode $n): bool => $n->credentialMode() === 'rest'));

        for ($i = 0; $i < count($rest); $i++) {
            for ($j = $i + 1; $j < count($rest); $j++) {
                if ($rest[$i]->sharesSecretWith($rest[$j])) {
                    $warnings[] = "TURN nodes '{$rest[$i]->id}' and '{$rest[$j]->id}' use the same secret: credentials minted for one are "
                        . 'valid at the other. If each coturn has its own static-auth-secret, set WEBRTC_TURN_NODE_<ID>_SECRET for each '
                        . 'node (a node without its own inherits WEBRTC_TURN_STATIC_AUTH_SECRET).';
                }
            }
        }

        if ($this->admission->invalidMode !== '') {
            $warnings[] = "WEBRTC_TURN_ADMISSION '{$this->admission->invalidMode}' is not off, observe or enforce: running as observe (nothing is denied).";
        }

        if ($this->admission->active()) {
            $enforcing = $this->admission->enforcing();
            $metered = array_filter($this->nodes, static fn (TurnNode $n): bool => $n->status !== NodeStatus::Disabled && $n->capacity->metered());
            $served  = array_filter($this->nodes, static fn (TurnNode $n): bool => $n->status !== NodeStatus::Disabled);

            if ($enforcing && $served !== [] && $metered === []) {
                $warnings[] = 'Admission control is on but no active node has a capacity (WEBRTC_TURN_NODE_<ID>_MAX_ALLOCATIONS / _MAX_MBPS): '
                    . 'the paid reserve cannot be enforced, and nothing is limited.';
            }
            foreach ($served as $node) {
                if (! $node->capacity->metered() && ($enforcing || $metered !== [])) {
                    $warnings[] = "TURN node '{$node->id}' has no capacity set: it is not metered, so it can neither be protected from overload nor reserve capacity for paid users.";
                } elseif ($node->capacity->metered() && ($node->capacity->maxAllocations === 0 || $node->capacity->maxBps === 0)) {
                    $warnings[] = "TURN node '{$node->id}' sets only one of MAX_ALLOCATIONS and MAX_MBPS: the other dimension is unlimited.";
                }
            }
        }

        $active = array_filter($this->nodes, static fn (TurnNode $n): bool => $n->status === NodeStatus::Active);
        foreach ($active as $node) {
            if (($node->lat === null) && count($active) > 1) {
                $warnings[] = "TURN node '{$node->id}' has no LAT/LON: with several active nodes it is routed by region hint and measured latency only.";
            }
        }

        return $warnings;
    }

    /**
     * Split a comma/space separated URL list, keeping only the schemes asked for.
     *
     * @return list<string>
     */
    public static function urlList(string $raw, string $schemePattern = '(stuns?|turns?)'): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];

        return array_values(array_unique(array_filter(
            array_map('trim', $parts),
            static fn (string $u): bool => $u !== '' && preg_match('/^' . $schemePattern . ':\S+$/i', $u) === 1,
        )));
    }

    private static function bool(?string $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
