<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

/**
 * One JSON file under flock: the cache for a product on its own host (no shared ledger configured). Fine for the
 * thousands of customers a product has; the shared ledger's PdoEntitlementCache is the multi-host form.
 */
final class FileEntitlementCache implements EntitlementCache
{
    public function __construct(private readonly string $path)
    {
    }

    public function get(string $scope, string $ref, string $product): ?array
    {
        return $this->read()['entries']["{$scope}|{$ref}|{$product}"] ?? null;
    }

    public function put(string $scope, string $ref, string $product, array $envelope, int $now): void
    {
        $this->update(static function (array &$d) use ($scope, $ref, $product, $envelope, $now): void {
            $key = "{$scope}|{$ref}|{$product}";
            if (isset($d['entries'][$key]) && MemoryEntitlementCache::revision($d['entries'][$key]['envelope']) > MemoryEntitlementCache::revision($envelope)) {
                return;
            }
            $d['entries'][$key] = ['envelope' => $envelope, 'fetchedAt' => $now];
        });
    }

    public function revokedRevision(string $customerId, string $product): int
    {
        return (int) ($this->read()['revoked']["{$customerId}|{$product}"] ?? -1);
    }

    public function revoke(string $customerId, string $product, int $revision, int $now): void
    {
        $this->update(static function (array &$d) use ($customerId, $product, $revision): void {
            $key                = "{$customerId}|{$product}";
            $d['revoked'][$key] = max((int) ($d['revoked'][$key] ?? -1), $revision);
        });
    }

    public function syncState(string $product): array
    {
        $s = $this->read()['sync'][$product] ?? [];

        return ['cursor' => (int) ($s['cursor'] ?? 0), 'lastSyncAt' => isset($s['lastSyncAt']) ? (int) $s['lastSyncAt'] : null, 'lastFullAt' => isset($s['lastFullAt']) ? (int) $s['lastFullAt'] : null];
    }

    public function saveSyncState(string $product, int $cursor, int $now, bool $full): void
    {
        $this->update(static function (array &$d) use ($product, $cursor, $now, $full): void {
            $prev              = $d['sync'][$product] ?? [];
            $d['sync'][$product] = ['cursor' => $cursor, 'lastSyncAt' => $now, 'lastFullAt' => $full ? $now : ($prev['lastFullAt'] ?? null)];
        });
    }

    /** @return array{entries: array<string, array{envelope: array<string, mixed>, fetchedAt: int}>, revoked: array<string, int>, sync: array<string, array<string, mixed>>} */
    private function read(): array
    {
        $raw  = is_file($this->path) ? @file_get_contents($this->path) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return [
            'entries' => is_array($data['entries'] ?? null) ? $data['entries'] : [],
            'revoked' => is_array($data['revoked'] ?? null) ? $data['revoked'] : [],
            'sync'    => is_array($data['sync'] ?? null) ? $data['sync'] : [],
        ];
    }

    /** @param \Closure(array<string, mixed>): void $change */
    private function update(\Closure $change): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create the licence cache directory {$dir}.");
        }
        $lock = fopen($this->path . '.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the licence cache.');
        }
        try {
            $data = $this->read();
            $change($data);
            $tmp = $this->path . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false || ! @rename($tmp, $this->path)) {
                @unlink($tmp);
                throw new \RuntimeException('Cannot write the licence cache.');
            }
            @chmod($this->path, 0600);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
