<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * The admission rule: may one more client of this tier be given this node?
 *
 * Pure arithmetic over numbers it is handed, so the rule that protects the paid reserve can be read and
 * tested without a relay, a file or a clock.
 *
 * For each metered dimension (allocations, bits/s), with `cap` the node's limit and `R` the paid
 * reserve of it (≥ 50% by default):
 *
 *   used       = what the books say is committed, plus any traffic the node is measurably carrying that
 *                the books do not account for ("unattributed": leases that expired while sessions
 *                lived on, or load that arrived some other way). Unattributed load is charged to
 *                BOTH tiers' admission, so a measurement that disagrees with the books can only make
 *                admission stricter, never looser.
 *   paid user  admitted  iff  used_free + used_paid + unattributed + request            ≤ cap
 *   free user  admitted  iff  used_free + unattributed + request + max(used_paid, R)   ≤ cap     (pool)
 *                        and  used_total + unattributed + request                        ≤ shedFreeAt × cap
 *
 * The `max(used_paid, R)` term is the whole reservation: whatever paid customers are not using is still
 * theirs, and whatever they use beyond R comes out of the shared part, so free capacity shrinks as paid
 * grows. The shed threshold keeps headroom for paid growth and bursts above what the books predict.
 * Nothing already admitted is cut off by this rule. A client that is RENEWING a lease it already holds
 * is exempt from the shed threshold — overload closes the door to new free sessions, it does not evict
 * the ones inside — and its own lease is not counted against it; only the hard limits (the pool rule and
 * the capacity) can still refuse it, and the client then simply keeps the credentials it has.
 */
final class Admission
{
    /**
     * @param array{paid: array{allocations?: int, bps?: int}, free: array{allocations?: int, bps?: int}} $used     committed by the books,
     *        excluding the lease being renewed, if any
     * @param array{allocations?: int, bps?: int}|null $measured what the node is measurably carrying now (fresh telemetry), or null
     * @param array{allocations: int, bps: int}        $request  what this client would add
     * @param bool                                     $renewal  the client already holds a charged lease on this node
     * @param array{allocations?: int, bps?: int}      $own      what that lease accounts for — already left out of `$used`, so it
     *        must be added back when comparing with measured traffic, which includes it
     */
    public static function evaluate(
        Capacity $capacity,
        AdmissionPolicy $policy,
        array $used,
        ?array $measured,
        Tier $tier,
        array $request,
        bool $renewal = false,
        array $own = [],
    ): AdmissionDecision {
        if (! $capacity->serves($tier)) {
            return new AdmissionDecision(false, AdmissionDecision::TIER_NOT_SERVED, 'normal', 0.0);
        }

        if (! $capacity->metered()) {
            return new AdmissionDecision(true, AdmissionDecision::UNMETERED, 'normal', 0.0);
        }

        $limits = ['allocations' => $capacity->maxAllocations, 'bps' => $capacity->maxBps];

        $utilization = 0.0;
        $reason      = AdmissionDecision::OK;
        $headroom    = ['paid' => [], 'free' => []];

        foreach ($limits as $dimension => $cap) {
            if ($cap <= 0) {
                continue;
            }

            $paid  = (int) ($used['paid'][$dimension] ?? 0);
            $free  = (int) ($used['free'][$dimension] ?? 0);
            $ask   = (int) ($request[$dimension] ?? 0);
            $total = $paid + $free;

            $unattributed = $measured === null ? 0 : max(0, (int) ($measured[$dimension] ?? 0) - $total - (int) ($own[$dimension] ?? 0));
            $busy         = $total + $unattributed;
            $reserve      = $capacity->reserved($cap);

            $utilization = max($utilization, $busy / $cap);

            $freeRoom = $cap - $busy - max(0, $reserve - $paid);       // the pool rule, as headroom
            // The epsilon: 0.70 × 90 is 62.999… in floating point, and the threshold is a whole percent.
            $shedRoom = (int) floor($policy->shedFreeAt * $cap + 1e-9) - $busy;
            $paidRoom = $cap - $busy;

            $headroom['free'][$dimension] = max(0, min($freeRoom, $shedRoom));
            $headroom['paid'][$dimension] = max(0, $paidRoom);

            if ($tier === Tier::Paid) {
                if ($ask > $paidRoom) {
                    $reason = AdmissionDecision::NODE_FULL;
                }
                continue;
            }

            if ($ask > $freeRoom) {
                $reason = AdmissionDecision::FREE_POOL_FULL;
            } elseif (! $renewal && $ask > $shedRoom && $reason === AdmissionDecision::OK) {
                $reason = AdmissionDecision::NODE_OVERLOADED;
            }
        }

        return new AdmissionDecision(
            $reason === AdmissionDecision::OK,
            $reason,
            self::load($utilization, $policy),
            $utilization,
            $headroom,
        );
    }

    /** normal | busy | overloaded | full — the words dashboards and clients see for a utilization. */
    public static function load(float $utilization, AdmissionPolicy $policy): string
    {
        return match (true) {
            $utilization >= 1.0                => 'full',
            $utilization >= $policy->shedFreeAt => 'overloaded',
            $utilization >= $policy->busyAt     => 'busy',
            default                            => 'normal',
        };
    }

    /**
     * How much a node's load costs it in routing, in milliseconds of "distance": nothing while it is
     * comfortable, a gentle slope once it is busy (so a near node starts sharing with a slightly farther
     * one), and a steep one past the free-shed threshold (so a far node is preferred to an overloaded one).
     */
    public static function loadPenaltyMs(float $utilization, AdmissionPolicy $policy): float
    {
        if ($utilization < $policy->busyAt) {
            return 0.0;
        }

        if ($utilization < $policy->shedFreeAt) {
            return 40.0 * ($utilization - $policy->busyAt) / ($policy->shedFreeAt - $policy->busyAt);
        }

        return 40.0 + 80.0 * min(1.0, ($utilization - $policy->shedFreeAt) / max(0.01, 1.0 - $policy->shedFreeAt));
    }
}
