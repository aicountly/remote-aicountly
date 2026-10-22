<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * The lease books in PostgreSQL — one set for every product and every API host that shares the TURN nodes.
 *
 * An admission decision and the lease that records it must be one atomic step across ALL of them: two products
 * each holding half of a node's free share in their own file would together admit twice the share. Here every
 * decision runs in a transaction holding the same advisory lock, so a request from Remote and one from Connect
 * are serialised, each sees the other's leases, and the last free slot goes to exactly one of them.
 *
 * The lock is held for the milliseconds a decision takes (one SELECT of the live leases, the decision, a few
 * upserts). `lock_timeout` bounds the wait; an error (database down, lock timeout) reaches IceIssuer as an
 * exception, which fails PAID clients open and — in enforce mode — refuses FREE ones: the paid reserve is
 * not put at risk by an outage of its own bookkeeping.
 *
 * Schema: lib/ice-kit/sql/turn_capacity.sql.
 */
final class PdoLeaseStore implements LeaseStore, CapacityRegistry
{
    /** One number for the whole ledger. Any value works so long as every product uses the same one. */
    private const LOCK_KEY = 7_240_011;

    public function __construct(private readonly LedgerConnection $connection)
    {
    }

    public function transact(\Closure $fn, int $now): mixed
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $pdo->exec('SELECT pg_advisory_xact_lock(' . self::LOCK_KEY . ')');
            $book   = new LeaseBook($this->load($pdo, $now), $now);
            $result = $fn($book);
            $this->persist($pdo, $book, $now);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public function snapshot(int $now): LeaseBook
    {
        return new LeaseBook($this->load($this->connection->pdo(), $now), $now);
    }

    public function register(string $product, array $nodes, int $now): void
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('DELETE FROM turn_capacity_config WHERE product = :p')->execute(['p' => $product]);
            $insert = $pdo->prepare(
                'INSERT INTO turn_capacity_config (product, node_id, max_allocations, max_bps, reserve_percent, tiers, updated_at)
                 VALUES (:p, :n, :a, :b, :r, :t, :u)',
            );
            foreach ($nodes as $node) {
                $insert->execute([
                    'p' => $product, 'n' => $node->id, 'a' => $node->capacity->maxAllocations, 'b' => $node->capacity->maxBps,
                    'r' => $node->capacity->effectiveReservePercent,
                    't' => implode(',', array_map(static fn (Tier $t): string => $t->value, $node->capacity->tiers)),
                    'u' => $now,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public function registered(): array
    {
        $rows = $this->connection->pdo()->query('SELECT product, node_id, max_allocations, max_bps, reserve_percent, tiers, updated_at FROM turn_capacity_config ORDER BY node_id, product')->fetchAll();

        return array_map(static fn (array $r): array => [
            'product' => (string) $r['product'], 'node' => (string) $r['node_id'], 'maxAllocations' => (int) $r['max_allocations'],
            'maxBps' => (int) $r['max_bps'], 'reservePercent' => (int) $r['reserve_percent'], 'tiers' => (string) $r['tiers'], 'updatedAt' => (int) $r['updated_at'],
        ], $rows);
    }

    /** @return array<string, Lease> */
    private function load(\PDO $pdo, int $now): array
    {
        $stmt = $pdo->prepare(
            'SELECT lease_key, node_id, tier, product, profile, subject, session_ref, allocations, bps, issued_at, expires_at, reported, is_primary
               FROM turn_leases WHERE expires_at + :grace >= :now',
        );
        $stmt->execute(['grace' => LeaseBook::GRACE_SECONDS, 'now' => $now]);

        $leases = [];
        foreach ($stmt->fetchAll() as $r) {
            $leases[(string) $r['lease_key']] = new Lease(
                (string) $r['lease_key'], (string) $r['node_id'], Tier::parse($r['tier']), (string) $r['product'], (string) $r['profile'],
                (string) $r['subject'], (string) $r['session_ref'], (int) $r['allocations'], (int) $r['bps'], (int) $r['issued_at'],
                (int) $r['expires_at'], (bool) $r['reported'], (bool) $r['is_primary'],
            );
        }

        return $leases;
    }

    private function persist(\PDO $pdo, LeaseBook $book, int $now): void
    {
        $changes = $book->changes();

        if ($changes['put'] !== []) {
            $upsert = $pdo->prepare(
                'INSERT INTO turn_leases (lease_key, node_id, tier, product, profile, subject, session_ref, allocations, bps, issued_at, expires_at, reported, is_primary)
                 VALUES (:k, :n, :t, :p, :f, :s, :r, :a, :b, :i, :e, :rep, :pri)
                 ON CONFLICT (lease_key) DO UPDATE SET
                    node_id = EXCLUDED.node_id, tier = EXCLUDED.tier, product = EXCLUDED.product, profile = EXCLUDED.profile,
                    subject = EXCLUDED.subject, session_ref = EXCLUDED.session_ref, allocations = EXCLUDED.allocations, bps = EXCLUDED.bps,
                    expires_at = EXCLUDED.expires_at, reported = EXCLUDED.reported, is_primary = EXCLUDED.is_primary',
            );
            foreach ($changes['put'] as $l) {
                $upsert->bindValue(':k', $l->key);
                $upsert->bindValue(':n', $l->nodeId);
                $upsert->bindValue(':t', $l->tier->value);
                $upsert->bindValue(':p', $l->product);
                $upsert->bindValue(':f', $l->profile);
                $upsert->bindValue(':s', $l->subject);
                $upsert->bindValue(':r', $l->sessionRef);
                $upsert->bindValue(':a', $l->allocations, \PDO::PARAM_INT);
                $upsert->bindValue(':b', $l->bps, \PDO::PARAM_INT);
                $upsert->bindValue(':i', $l->issuedAt, \PDO::PARAM_INT);
                $upsert->bindValue(':e', $l->expiresAt, \PDO::PARAM_INT);
                $upsert->bindValue(':rep', $l->reported, \PDO::PARAM_BOOL);
                $upsert->bindValue(':pri', $l->primary, \PDO::PARAM_BOOL);
                $upsert->execute();
            }
        }

        if ($changes['removed'] !== []) {
            $delete = $pdo->prepare('DELETE FROM turn_leases WHERE lease_key = :k');
            foreach ($changes['removed'] as $key) {
                $delete->execute(['k' => $key]);
            }
        }

        // Leases past their credential plus grace are no longer counted; drop them as we go (indexed, tiny).
        $pdo->prepare('DELETE FROM turn_leases WHERE expires_at + :grace < :now')->execute(['grace' => LeaseBook::GRACE_SECONDS, 'now' => $now]);
    }
}
