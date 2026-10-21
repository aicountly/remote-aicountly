<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * Where measured node load is kept: a file for one product on one host, the shared database when several
 * products share the same relays (a node's traffic is one number, whoever caused it).
 */
interface LoadSource
{
    /**
     * Accept one report from a node collector.
     *
     * @param array<string, mixed> $raw
     * @param list<string>|null    $known node ids that exist; others are refused
     * @return array{ok: bool, error?: string}
     */
    public function ingest(array $raw, int $now, ?array $known = null): array;

    /**
     * Fresh readings by node; `bps` is rx + tx on the node's NIC.
     *
     * @return array<string, array{allocations: int, bps: int, ageSeconds: int}>
     */
    public function fresh(int $now): array;
}
