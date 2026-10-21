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
 *   WEBRTC_TURN_NODE_MUM_SECRET          =                          per-node secret; default is the shared one
 *   WEBRTC_TURN_STATIC_AUTH_SECRET       = <coturn static-auth-secret, shared by every node>
 *   WEBRTC_TURN_TTL                      = 3600                     credential lifetime, 60..86400
 *   WEBRTC_TURN_MAX_NODES                = 2                        relay nodes per client, 1..4
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
    ) {
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
        $pool     = max(0, min(10, (int) ($get('WEBRTC_ICE_CANDIDATE_POOL') ?? 0)));
        $shared   = $get('WEBRTC_TURN_STATIC_AUTH_SECRET') ?? '';

        $ids = $get('WEBRTC_TURN_NODES');

        if ($ids === null) {
            $legacy = self::legacyNode(
                self::urlList($get('WEBRTC_TURN_URLS') ?? $get('WEBRTC_TURN_URL') ?? '', 'turns?'),
                $shared,
                $get('WEBRTC_TURN_USERNAME') ?? '',
                $get('WEBRTC_TURN_CREDENTIAL') ?? '',
            );

            return new self($legacy === null ? [] : [$legacy], $stunUrls, $ttl, $maxNodes, $force, $pool, 'legacy');
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

        return new self($nodes, $stunUrls, $ttl, $maxNodes, $force, $pool, 'nodes', $problems);
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
