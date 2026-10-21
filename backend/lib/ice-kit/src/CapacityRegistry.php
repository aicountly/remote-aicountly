<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * Each product records the capacity it believes each node has, in the books both products share, so a
 * disagreement is found by a tool instead of by an over-admitted node. Two products configured with different
 * `MAX_ALLOCATIONS` for the same node cannot both be right, and neither guarantee holds until they agree.
 */
interface CapacityRegistry
{
    /** @param list<TurnNode> $nodes */
    public function register(string $product, array $nodes, int $now): void;

    /** @return list<array{product: string, node: string, maxAllocations: int, maxBps: int, reservePercent: int, tiers: string, updatedAt: int}> */
    public function registered(): array;
}
