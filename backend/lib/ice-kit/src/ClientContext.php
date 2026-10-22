<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * What the API knows about where a client is, reduced to what node selection may use.
 *
 * Three independent signals, any of which may be missing:
 *
 *   * `region` — a hint naming a node region ("in-north"), from the client's request or an edge header.
 *   * `lat`/`lon` — a coarse position, from a trusted edge's geo headers (a CDN or load balancer that
 *     adds them). Not from the browser: precise client location is not collected.
 *   * `rtt` — the client's own measurement of its round trip to each node's STUN endpoint, by node id
 *     (web/src/ice-kit/latency.js). The best signal there is, because it is the real network path.
 *
 * Every one is advice. Spoofing any of them changes which node a client is *offered first* and nothing
 * else: tier, admission and credentials do not depend on it, so a wrong hint cannot buy capacity.
 */
final class ClientContext
{
    public const MAX_RTT_ENTRIES = 8;

    /** @param array<string, int> $rtt round trip in ms by node id */
    public function __construct(
        public readonly string $region = '',
        public readonly ?float $lat = null,
        public readonly ?float $lon = null,
        public readonly array $rtt = [],
    ) {
    }

    /**
     * @param array<string, mixed>              $body    a request body: `region`, `rtt: {nodeId: ms}`
     * @param array{lat?: mixed, lon?: mixed, region?: mixed} $edge what trusted edge headers said
     */
    public static function fromRequest(array $body = [], array $edge = []): self
    {
        $region = self::region($body['region'] ?? null) ?: self::region($edge['region'] ?? null);

        $lat = self::coordinate($edge['lat'] ?? null, 90.0);
        $lon = self::coordinate($edge['lon'] ?? null, 180.0);
        if ($lat === null || $lon === null) {
            $lat = $lon = null;
        }

        $rtt = [];
        if (isset($body['rtt']) && is_array($body['rtt'])) {
            foreach ($body['rtt'] as $id => $ms) {
                if (is_string($id) && preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id) === 1 && is_numeric($ms) && $ms >= 0.5 && $ms <= 3000) {
                    $rtt[$id] = max(1, (int) round((float) $ms));
                }
                if (count($rtt) >= self::MAX_RTT_ENTRIES) {
                    break;
                }
            }
        }

        return new self($region, $lat, $lon, $rtt);
    }

    public static function region(mixed $value): string
    {
        $region = is_string($value) ? strtolower(trim($value)) : '';

        return preg_match('/^[a-z0-9][a-z0-9-]{0,23}$/', $region) === 1 ? $region : '';
    }

    private static function coordinate(mixed $value, float $limit): ?float
    {
        return is_numeric($value) && abs((float) $value) <= $limit ? round((float) $value, 2) : null;
    }

    /** Great-circle distance in km. */
    public static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p = M_PI / 180;
        $a = sin(($lat2 - $lat1) * $p / 2) ** 2 + cos($lat1 * $p) * cos($lat2 * $p) * sin(($lon2 - $lon1) * $p / 2) ** 2;

        return 12742.0 * asin(min(1.0, sqrt($a)));
    }
}
