<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

/**
 * Brings the cache up to date from the central backend: a full snapshot when none has been read (or on demand), then the
 * changes feed — which is also the REVOCATION channel. Run every minute or two by a scheduled job.
 *
 * Defences, each tested:
 *   * an envelope whose signature does not verify is dropped and counted — never stored;
 *   * an envelope for another product is dropped (tenant isolation holds even if the backend or a proxy misbehaves);
 *   * a revocation is recorded BEFORE the refreshed document is fetched, so even a failed refetch leaves the customer
 *     off the paid reserve;
 *   * the heartbeat (`lastSyncAt`) advances only after a complete pass, so a half-failed run reads as a stopped feed.
 */
final class EntitlementSync
{
    private readonly \Closure $clock;

    public function __construct(
        private readonly CentralClient $client,
        private readonly EntitlementCache $cache,
        private readonly EntitlementVerifier $verifier,
        private readonly string $product,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array{full: bool, stored: int, rejected: int, revoked: int, cursor: int}
     * @throws CentralUnavailable when the backend cannot be read; the cache and heartbeat are left as they were
     */
    public function run(bool $full = false): array
    {
        $now   = ($this->clock)();
        $state = $this->cache->syncState($this->product);
        $stats = ['full' => false, 'stored' => 0, 'rejected' => 0, 'revoked' => 0, 'cursor' => $state['cursor']];

        if ($full || $state['lastFullAt'] === null) {
            $stats['full'] = true;
            $cursor        = null;
            $seq           = 0;
            do {
                $page = $this->client->snapshot($this->product, $cursor);
                $seq  = max($seq, $page['seq']);
                foreach ($page['items'] as $envelope) {
                    $this->store($envelope, $now, $stats);
                }
                $cursor = $page['nextCursor'];
            } while ($cursor !== null);
            $stats['cursor'] = $seq;
        }

        do {
            $feed = $this->client->changes($this->product, $stats['cursor']);
            foreach ($feed['changes'] as $change) {
                $this->applyChange($change, $now, $stats);
            }
            $advanced        = $feed['nextSeq'] > $stats['cursor'];
            $stats['cursor'] = max($stats['cursor'], $feed['nextSeq']);
        } while ($advanced && $feed['changes'] !== []);

        $this->cache->saveSyncState($this->product, $stats['cursor'], $now, $stats['full']);

        return $stats;
    }

    /** @param array<string, mixed> $change {customer_id, product_id, revision, revoked?} */
    private function applyChange(array $change, int $now, array &$stats): void
    {
        $customer = $change['customer_id'] ?? null;
        $revision = $change['revision'] ?? null;
        if (($change['product_id'] ?? null) !== $this->product || ! is_string($customer) || ! is_int($revision)) {
            $stats['rejected']++;

            return;
        }
        if (($change['revoked'] ?? false) === true) {
            $this->cache->revoke($customer, $this->product, $revision, $now);
            $stats['revoked']++;
        }
        $this->store($this->client->customer($customer, $this->product), $now, $stats);
    }

    /** @param array<string, mixed> $envelope */
    private function store(array $envelope, int $now, array &$stats): void
    {
        try {
            $e = Entitlement::fromDocument((array) ($envelope['document'] ?? []));
        } catch (\InvalidArgumentException) {
            $stats['rejected']++;

            return;
        }
        if ($e->productId !== $this->product || ! $this->verifier->verify($envelope)) {
            $stats['rejected']++;

            return;
        }
        $scope = $e->subject === null ? EntitlementCache::CUSTOMER : EntitlementCache::SUBJECT;
        $this->cache->put($scope, $e->subject ?? $e->customerId, $this->product, $envelope, $now);
        $stats['stored']++;
    }
}
