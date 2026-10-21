<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

use Aicountly\Ice\LedgerConnection;

/**
 * The entitlement cache in the shared ledger database (sql/turn_capacity.sql), so Remote and Connect — and every
 * API host of each — read one set of rows. The envelope is stored as TEXT, byte for byte, so nothing normalises
 * what a signature covers. The database is not trusted (see EntitlementCache): rows are re-verified on use.
 */
final class PdoEntitlementCache implements EntitlementCache
{
    public function __construct(private readonly LedgerConnection $connection)
    {
    }

    public function get(string $scope, string $ref, string $product): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT envelope, fetched_at FROM aic_entitlement_cache WHERE scope = :s AND ref = :r AND product_id = :p');
        $stmt->execute(['s' => $scope, 'r' => $ref, 'p' => $product]);
        $row = $stmt->fetch();
        if (! is_array($row)) {
            return null;
        }
        $envelope = json_decode((string) $row['envelope'], true);

        return is_array($envelope) ? ['envelope' => $envelope, 'fetchedAt' => (int) $row['fetched_at']] : null;
    }

    public function put(string $scope, string $ref, string $product, array $envelope, int $now): void
    {
        $this->connection->pdo()->prepare(
            'INSERT INTO aic_entitlement_cache (scope, ref, product_id, revision, envelope, fetched_at)
             VALUES (:s, :r, :p, :rev, :e, :now)
             ON CONFLICT (scope, ref, product_id) DO UPDATE
                SET revision = EXCLUDED.revision, envelope = EXCLUDED.envelope, fetched_at = EXCLUDED.fetched_at
              WHERE aic_entitlement_cache.revision <= EXCLUDED.revision',
        )->execute([
            's' => $scope, 'r' => $ref, 'p' => $product, 'rev' => MemoryEntitlementCache::revision($envelope),
            'e' => json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'now' => $now,
        ]);
    }

    public function revokedRevision(string $customerId, string $product): int
    {
        $stmt = $this->connection->pdo()->prepare('SELECT revision FROM aic_entitlement_revoked WHERE customer_id = :c AND product_id = :p');
        $stmt->execute(['c' => $customerId, 'p' => $product]);
        $v = $stmt->fetchColumn();

        return $v === false ? -1 : (int) $v;
    }

    public function revoke(string $customerId, string $product, int $revision, int $now): void
    {
        $this->connection->pdo()->prepare(
            'INSERT INTO aic_entitlement_revoked (customer_id, product_id, revision, at) VALUES (:c, :p, :r, :now)
             ON CONFLICT (customer_id, product_id) DO UPDATE SET revision = GREATEST(aic_entitlement_revoked.revision, EXCLUDED.revision), at = EXCLUDED.at',
        )->execute(['c' => $customerId, 'p' => $product, 'r' => $revision, 'now' => $now]);
    }

    public function syncState(string $product): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT cursor_seq, last_sync_at, last_full_at FROM aic_entitlement_sync WHERE product_id = :p');
        $stmt->execute(['p' => $product]);
        $r = $stmt->fetch();

        return is_array($r)
            ? ['cursor' => (int) $r['cursor_seq'], 'lastSyncAt' => $r['last_sync_at'] === null ? null : (int) $r['last_sync_at'], 'lastFullAt' => $r['last_full_at'] === null ? null : (int) $r['last_full_at']]
            : ['cursor' => 0, 'lastSyncAt' => null, 'lastFullAt' => null];
    }

    public function saveSyncState(string $product, int $cursor, int $now, bool $full): void
    {
        $this->connection->pdo()->prepare(
            'INSERT INTO aic_entitlement_sync (product_id, cursor_seq, last_sync_at, last_full_at) VALUES (:p, :c, :now, :full)
             ON CONFLICT (product_id) DO UPDATE SET cursor_seq = EXCLUDED.cursor_seq, last_sync_at = EXCLUDED.last_sync_at,
                last_full_at = COALESCE(EXCLUDED.last_full_at, aic_entitlement_sync.last_full_at)',
        )->execute(['p' => $product, 'c' => $cursor, 'now' => $now, 'full' => $full ? $now : null]);
    }
}
