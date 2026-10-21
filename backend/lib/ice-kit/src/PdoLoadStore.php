<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * Measured node load in the shared ledger: one reading per node, written by whichever collector job reports
 * it and read by every product, so Remote and Connect charge the same unexplained traffic.
 */
final class PdoLoadStore implements LoadSource
{
    public function __construct(private readonly LedgerConnection $connection, public readonly int $maxAgeSeconds = 180)
    {
    }

    public function ingest(array $raw, int $now, ?array $known = null): array
    {
        $parsed = LoadReport::parse($raw, $now, $known);
        if (! $parsed['ok']) {
            return ['ok' => false, 'error' => $parsed['error']];
        }

        $e = $parsed['entry'];
        $this->connection->pdo()->prepare(
            'INSERT INTO turn_node_load (node_id, at, allocations, rx_bps, tx_bps, by_tier)
             VALUES (:n, :at, :a, :rx, :tx, :bt)
             ON CONFLICT (node_id) DO UPDATE SET at = EXCLUDED.at, allocations = EXCLUDED.allocations,
                rx_bps = EXCLUDED.rx_bps, tx_bps = EXCLUDED.tx_bps, by_tier = EXCLUDED.by_tier',
        )->execute([
            'n' => $parsed['node'], 'at' => $e['at'], 'a' => $e['allocations'], 'rx' => $e['rxBps'], 'tx' => $e['txBps'],
            'bt' => isset($e['byTier']) ? json_encode($e['byTier']) : null,
        ]);

        return ['ok' => true];
    }

    public function fresh(int $now): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT node_id, at, allocations, rx_bps, tx_bps FROM turn_node_load WHERE at + :max >= :now');
        $stmt->execute(['max' => $this->maxAgeSeconds, 'now' => $now]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['node_id']] = ['allocations' => (int) $r['allocations'], 'bps' => (int) $r['rx_bps'] + (int) $r['tx_bps'], 'ageSeconds' => max(0, $now - (int) $r['at'])];
        }

        return $out;
    }
}
