<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * One client's reservation on one node: the unit the books are kept in.
 *
 * Four things are deliberately kept apart, because capacity is exhausted by different ones at different
 * times and conflating them is how limits end up wrong:
 *
 *   * a **product session** (`sessionRef`) — a Remote session, a Connect call or room: what a customer
 *     understands. It can hold several leases (one per node it was offered, one per participant).
 *   * a **lease** — this record: a participant's claim on a node, lasting as long as its credential.
 *   * **allocations** — what the lease is expected to open on the node: a browser makes one per TURN URL.
 *     This is what coturn's `total-quota` and `user-quota` count.
 *   * **bandwidth** — what the lease is expected to carry (`bps`, up plus down), charged to the node the
 *     client actually relays through. It is an estimate until telemetry says otherwise, and zero once a
 *     client reports a direct path: an allocation that carries nothing still occupies a slot.
 *
 * What the node is *actually* carrying is a fifth thing again, and lives in the LoadStore.
 */
final class Lease
{
    public function __construct(
        public readonly string $key,
        public readonly string $nodeId,
        public readonly Tier $tier,
        public readonly string $product,
        public readonly string $profile,
        public readonly string $subject,
        public readonly string $sessionRef,
        public readonly int $allocations,
        public readonly int $bps,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
        /** Whether the client has said how it actually connected (noteConnection), so `bps` is a report, not an estimate. */
        public readonly bool $reported = false,
        /** Whether this was the client's first-choice node when the lease was written — the one a renewal prefers to stay on. */
        public readonly bool $primary = false,
    ) {
    }

    public static function keyFor(string $product, string $sessionRef, string $subject, string $nodeId): string
    {
        return substr(hash('sha256', implode("\0", [$product, $sessionRef, $subject, $nodeId])), 0, 24);
    }

    /** This lease after the client has reported: `bps` is now what it said, not what was assumed. */
    public function reportedWith(int $bps): self
    {
        return new self($this->key, $this->nodeId, $this->tier, $this->product, $this->profile, $this->subject, $this->sessionRef, $this->allocations, max(0, $bps), $this->issuedAt, $this->expiresAt, true, $this->primary);
    }

    public function renewed(Tier $tier, string $profile, int $allocations, int $bps, int $expiresAt, bool $reported, bool $primary): self
    {
        return new self($this->key, $this->nodeId, $tier, $this->product, $profile, $this->subject, $this->sessionRef, $allocations, $bps, $this->issuedAt, $expiresAt, $reported, $primary);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'node' => $this->nodeId, 'tier' => $this->tier->value, 'product' => $this->product, 'profile' => $this->profile,
            'subject' => $this->subject, 'session' => $this->sessionRef, 'allocations' => $this->allocations,
            'bps' => $this->bps, 'issuedAt' => $this->issuedAt, 'expiresAt' => $this->expiresAt, 'reported' => $this->reported, 'primary' => $this->primary,
        ];
    }

    /**
     * A lease from a stored row, or null when the row is not one. The file is ours, but a half-written or
     * hand-edited one must cost a row, not every request after it.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(string $key, array $row): ?self
    {
        foreach (['node', 'product', 'profile', 'subject', 'session', 'tier'] as $field) {
            if (isset($row[$field]) && ! is_string($row[$field])) {
                return null;
            }
        }
        foreach (['allocations', 'bps', 'issuedAt', 'expiresAt'] as $field) {
            if (isset($row[$field]) && ! is_int($row[$field]) && ! is_float($row[$field])) {
                return null;
            }
        }
        if (! isset($row['node'], $row['expiresAt']) || $row['node'] === '') {
            return null;
        }

        return new self(
            $key,
            (string) $row['node'],
            Tier::parse($row['tier'] ?? null),
            (string) ($row['product'] ?? ''),
            (string) ($row['profile'] ?? 'default'),
            (string) ($row['subject'] ?? ''),
            (string) ($row['session'] ?? ''),
            max(0, (int) ($row['allocations'] ?? 0)),
            max(0, (int) ($row['bps'] ?? 0)),
            (int) ($row['issuedAt'] ?? 0),
            (int) $row['expiresAt'],
            (bool) ($row['reported'] ?? false),
            (bool) ($row['primary'] ?? false),
        );
    }
}
