<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

/**
 * Where signed entitlement envelopes wait between the central backend and a credential request.
 *
 * The cache is NOT trusted: every envelope is re-verified against the central public key when it is used
 * (LicenceResolver), so a tampered row is simply ignored. What the cache does own is local facts the central
 * document cannot carry: when an envelope was fetched, which revisions have been revoked, and how far the
 * changes feed has been read. Keyed by (scope, ref, product), one scope per kind of entitlement so that no
 * lookup in one tenant's context can ever read another's:
 *
 *   customer   the company-level entitlement, ref = customer id
 *   member     one user's eligibility INSIDE one company (company-wide membership or an assigned seat), ref = "customer|subject"
 *   user       a personal, user-scoped entitlement, ref = subject
 */
interface EntitlementCache
{
    public const CUSTOMER = 'customer';
    public const MEMBER   = 'member';
    public const USER     = 'user';

    /** @return array{envelope: array<string, mixed>, fetchedAt: int}|null */
    public function get(string $scope, string $ref, string $product): ?array;

    /**
     * Store an envelope. Never replaces one with a higher `revision` (a replayed older answer cannot roll back a newer).
     *
     * @param array<string, mixed> $envelope
     */
    public function put(string $scope, string $ref, string $product, array $envelope, int $now): void;

    /**
     * The highest revision revoked for this customer (null = a personal entitlement), product and — for member-level and
     * personal entitlements — subject, or -1. A document at or below it is not trusted.
     */
    public function revokedRevision(?string $customerId, string $product, ?string $subject = null): int;

    public function revoke(?string $customerId, string $product, int $revision, int $now, ?string $subject = null): void;

    /** @return array{cursor: int, lastSyncAt: int|null, lastFullAt: int|null} */
    public function syncState(string $product): array;

    public function saveSyncState(string $product, int $cursor, int $now, bool $full): void;
}
