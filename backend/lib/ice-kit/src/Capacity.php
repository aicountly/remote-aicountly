<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * What one TURN node can sustainably carry, and how it is shared between the licence tiers.
 *
 * Two independent limits, because a relay runs out of either first:
 *
 *   * `maxAllocations` — concurrent TURN allocations. Memory, file descriptors and relay ports; a
 *     browser makes one per TURN URL it is given, so a connection costs several.
 *   * `maxBps` — sustainable relayed throughput, counted as every client's upload plus download through
 *     the node (what the NIC sees as rx + tx). "Sustainable" is a number the operator sets with headroom
 *     below the NIC and CPU limits; it is not the line rate.
 *
 * `0` means "not metered": that dimension is not enforced, and a node with neither limit is unmetered.
 *
 * `paidReservePercent` of each metered dimension is held for paid customers: free users are admitted only
 * into the rest, and never into the part paid customers are entitled to, however idle it is. A node that
 * serves both tiers can never be configured below the 50 % floor; a node that serves one tier has no
 * reserve to speak of (the separation of nodes by tier is the reservation).
 */
final class Capacity
{
    public const MIN_RESERVE_PERCENT = 50;
    public const MAX_RESERVE_PERCENT = 90;

    /** @var list<Tier> */
    public readonly array $tiers;

    /** The reserve actually applied: at least the floor when both tiers are served, otherwise none. */
    public readonly int $effectiveReservePercent;

    /**
     * @param list<Tier>|null $tiers which tiers this node may be given to; null = both
     */
    public function __construct(
        public readonly int $maxAllocations = 0,
        public readonly int $maxBps = 0,
        public readonly int $paidReservePercent = self::MIN_RESERVE_PERCENT,
        ?array $tiers = null,
    ) {
        $this->tiers = $tiers === null || $tiers === [] ? [Tier::Paid, Tier::Free] : array_values(array_unique($tiers, SORT_REGULAR));
        $this->effectiveReservePercent = $this->shared() ? max(self::MIN_RESERVE_PERCENT, min(self::MAX_RESERVE_PERCENT, $paidReservePercent)) : 0;
    }

    public static function unmetered(): self
    {
        return new self();
    }

    public function metered(): bool
    {
        return $this->maxAllocations > 0 || $this->maxBps > 0;
    }

    public function serves(Tier $tier): bool
    {
        return in_array($tier, $this->tiers, true);
    }

    /** Whether the node is shared between both tiers, which is what a reserve is about. */
    public function shared(): bool
    {
        return $this->serves(Tier::Paid) && $this->serves(Tier::Free);
    }

    /** The reserved part of a limit, rounded up so a small node never reserves less than asked. */
    public function reserved(int $limit): int
    {
        return $this->shared() ? (int) ceil($limit * $this->effectiveReservePercent / 100) : 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'maxAllocations'     => $this->maxAllocations,
            'maxMbps'            => $this->maxBps / 1_000_000,
            'paidReservePercent' => $this->effectiveReservePercent,
            'tiers'              => array_map(static fn (Tier $t): string => $t->value, $this->tiers),
        ];
    }
}
