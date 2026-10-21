<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

/** Process-local cache for tests and one-shot tools. */
final class MemoryEntitlementCache implements EntitlementCache
{
    /** @var array<string, array{envelope: array<string, mixed>, fetchedAt: int}> */
    public array $entries = [];
    /** @var array<string, int> */
    public array $revoked = [];
    /** @var array<string, array{cursor: int, lastSyncAt: int|null, lastFullAt: int|null}> */
    public array $sync = [];

    public function get(string $scope, string $ref, string $product): ?array
    {
        return $this->entries["{$scope}|{$ref}|{$product}"] ?? null;
    }

    public function put(string $scope, string $ref, string $product, array $envelope, int $now): void
    {
        $key = "{$scope}|{$ref}|{$product}";
        if (isset($this->entries[$key]) && self::revision($this->entries[$key]['envelope']) > self::revision($envelope)) {
            return;
        }
        $this->entries[$key] = ['envelope' => $envelope, 'fetchedAt' => $now];
    }

    public function revokedRevision(string $customerId, string $product): int
    {
        return $this->revoked["{$customerId}|{$product}"] ?? -1;
    }

    public function revoke(string $customerId, string $product, int $revision, int $now): void
    {
        $key                = "{$customerId}|{$product}";
        $this->revoked[$key] = max($this->revoked[$key] ?? -1, $revision);
    }

    public function syncState(string $product): array
    {
        return $this->sync[$product] ?? ['cursor' => 0, 'lastSyncAt' => null, 'lastFullAt' => null];
    }

    public function saveSyncState(string $product, int $cursor, int $now, bool $full): void
    {
        $prev              = $this->syncState($product);
        $this->sync[$product] = ['cursor' => $cursor, 'lastSyncAt' => $now, 'lastFullAt' => $full ? $now : $prev['lastFullAt']];
    }

    /** @param array<string, mixed> $envelope */
    public static function revision(array $envelope): int
    {
        $r = $envelope['document']['revision'] ?? -1;

        return is_int($r) ? $r : -1;
    }
}
