<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * How far a client is from a node, in milliseconds of round trip, and on what evidence.
 *
 * Best evidence first, and the basis is reported so an operator can see why a node was chosen:
 *
 *   `measured`    the client timed its own path to this node (ClientContext::$rtt). If it timed SOME nodes and not
 *                 this one, this one is `unmeasured`: slower than the slowest it did time, not a prior that could win
 *   `geo`         the client's coarse position from a trusted edge, and the node's — 10 ms + distance/60
 *                 (light in fibre is ~100 km per ms of round trip; real routes are about 1.7× longer)
 *   `learned`     the median of what other clients in the same region measured (LatencyStore, ≥ 5 distinct clients)
 *   `region`      the client's region hint matches this node's region (10 ms); between two coordinates
 *                 the geo model is used from the hinted region's node; otherwise a neutral 60 ms
 *   `unknown`     nothing known: 60 ms for every node, so priority decides
 *
 * Distances are a prior, not a verdict — the point is that Mumbai-near users start on Mumbai and
 * Delhi-near users on Noida, not that the numbers are exact.
 */
final class Proximity
{
    public const NEUTRAL_MS = 60.0;

    /**
     * @param list<TurnNode>     $nodes  every node, to locate a hinted region
     * @param array<string, int> $learned ms by node id for the client's region
     * @return array{ms: float, basis: string}
     */
    public static function estimate(TurnNode $node, ClientContext $client, array $nodes, array $learned = []): array
    {
        if (isset($client->rtt[$node->id])) {
            return ['ms' => (float) $client->rtt[$node->id], 'basis' => 'measured'];
        }

        // The client measured other nodes and could not reach (or was not given) this one: it is no
        // better than the worst it did measure, whatever a prior says about distance.
        if ($client->rtt !== []) {
            return ['ms' => (float) max($client->rtt) + 50.0, 'basis' => 'unmeasured'];
        }

        if ($client->lat !== null && $client->lon !== null && $node->lat !== null && $node->lon !== null) {
            return ['ms' => self::geo(ClientContext::distanceKm($client->lat, $client->lon, $node->lat, $node->lon)), 'basis' => 'geo'];
        }

        if (isset($learned[$node->id])) {
            return ['ms' => (float) $learned[$node->id], 'basis' => 'learned'];
        }

        if ($client->region !== '') {
            if ($node->region === $client->region) {
                return ['ms' => 10.0, 'basis' => 'region'];
            }

            foreach ($nodes as $reference) {
                if ($reference->region === $client->region && $reference->lat !== null && $reference->lon !== null && $node->lat !== null && $node->lon !== null) {
                    return ['ms' => self::geo(ClientContext::distanceKm($reference->lat, $reference->lon, $node->lat, $node->lon)), 'basis' => 'region'];
                }
            }

            return ['ms' => self::NEUTRAL_MS, 'basis' => 'region'];
        }

        return ['ms' => self::NEUTRAL_MS, 'basis' => 'unknown'];
    }

    public static function geo(float $km): float
    {
        return 10.0 + $km / 60.0;
    }
}
