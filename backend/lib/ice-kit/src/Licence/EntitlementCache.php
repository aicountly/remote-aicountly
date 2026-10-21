<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

/**
 * Where signed entitlement envelopes wait between the central backend and a credential request.
 *
 * The cache is NOT trusted: every envelope is re-verified against the central public key when it is used
 * (LicenceResolver), so a tampered row is simply ignored. What the cache does own is local facts the central
 * document cannot carry: when an envelope was fetched, which revisions have been revoked, and how far the
 * changes feed has been read. Keyed by (scope, ref, product): `customer` entries by customer id, `subject`
 * entries (a product that asks "what is this user entitled to?") by the subject id.
 */
interface EntitlementCache
{
    public const CUSTOMER = 'customer';
    public const SUBJECT  = 'subject';

    /** @return array{envelope: array<string, mixed>, fetchedAt: int}|null */
    public function get(string $scope, string $ref, string $product): ?array;

    /**
     * Store an envelope. Never replaces one with a higher `revision` (a replayed older answer cannot roll back a newer).
     *
     * @param array<string, mixed> $envelope
     */
    public function put(string $scope, string $ref, string $product, array $envelope, int $now): void;

    /** The highest revision revoked for this customer and product, or -1. A document at or below it is not trusted. */
    public function revokedRevision(string $customerId, string $product): int;

    public function revoke(string $customerId, string $product, int $revision, int $now): void;

    /** @return array{cursor: int, lastSyncAt: int|null, lastFullAt: int|null} */
    public function syncState(string $product): array;

    public function saveSyncState(string $product, int $cursor, int $now, bool $full): void;
}
