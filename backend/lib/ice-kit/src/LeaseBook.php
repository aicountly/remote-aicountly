<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * The set of live leases, as seen inside one transaction of a LeaseStore. Mutations here are written
 * back by the store when the transaction ends; nothing else touches the files.
 */
final class LeaseBook
{
    /** Seconds past its credential's expiry a lease is still counted: an allocation outlives the credential. */
    public const GRACE_SECONDS = 120;

    private bool $dirty = false;

    /** @var array<string, true> keys written or removed during this transaction, for stores that write rows */
    private array $touched = [];

    /** @var array<string, true> */
    private array $removed = [];

    /** @param array<string, Lease> $leases by key */
    public function __construct(private array $leases = [], private readonly int $now = 0)
    {
        foreach ($this->leases as $key => $lease) {
            if ($lease->expiresAt + self::GRACE_SECONDS < $this->now) {
                unset($this->leases[$key]);
                $this->dirty = true;
            }
        }
    }

    public function dirty(): bool
    {
        return $this->dirty;
    }

    /** @return array<string, Lease> */
    public function all(): array
    {
        return $this->leases;
    }

    public function put(Lease $lease): void
    {
        $this->leases[$lease->key] = $lease;
        $this->touched[$lease->key] = true;
        unset($this->removed[$lease->key]);
        $this->dirty = true;
    }

    public function remove(string $key): void
    {
        if (isset($this->leases[$key])) {
            unset($this->leases[$key], $this->touched[$key]);
            $this->removed[$key] = true;
            $this->dirty = true;
        }
    }

    /**
     * What this transaction changed, for a store that writes rows instead of a whole file.
     *
     * @return array{put: list<Lease>, removed: list<string>}
     */
    public function changes(): array
    {
        return [
            'put'     => array_values(array_intersect_key($this->leases, $this->touched)),
            'removed' => array_keys($this->removed),
        ];
    }

    /** @return list<Lease> */
    public function forClient(string $product, string $sessionRef, string $subject): array
    {
        return array_values(array_filter(
            $this->leases,
            static fn (Lease $l): bool => $l->product === $product && $l->sessionRef === $sessionRef && $l->subject === $subject,
        ));
    }

    /** Remove every lease of a product session (optionally one participant's). Returns how many. */
    public function releaseSession(string $product, string $sessionRef, ?string $subject = null): int
    {
        $n = 0;
        foreach ($this->leases as $key => $lease) {
            if ($lease->product === $product && $lease->sessionRef === $sessionRef && ($subject === null || $lease->subject === $subject)) {
                unset($this->leases[$key], $this->touched[$key]);
                $this->removed[$key] = true;
                $n++;
            }
        }
        $this->dirty = $this->dirty || $n > 0;

        return $n;
    }

    /**
     * Committed capacity on a node by tier.
     *
     * @param list<string> $excludeKeys leases not to count — the ones being renewed, which would otherwise
     *                                  count against themselves
     * @return array{paid: array{allocations: int, bps: int, leases: int, sessions: int}, free: array{allocations: int, bps: int, leases: int, sessions: int}}
     */
    public function usage(string $nodeId, array $excludeKeys = []): array
    {
        $out      = ['paid' => ['allocations' => 0, 'bps' => 0, 'leases' => 0], 'free' => ['allocations' => 0, 'bps' => 0, 'leases' => 0]];
        $sessions = ['paid' => [], 'free' => []];

        foreach ($this->leases as $lease) {
            if ($lease->nodeId !== $nodeId || in_array($lease->key, $excludeKeys, true)) {
                continue;
            }
            $t = $lease->tier->value;
            $out[$t]['allocations'] += $lease->allocations;
            $out[$t]['bps']         += $lease->bps;
            $out[$t]['leases']++;
            $sessions[$t][$lease->product . '/' . $lease->sessionRef] = true;
        }

        $out['paid']['sessions'] = count($sessions['paid']);
        $out['free']['sessions'] = count($sessions['free']);

        return $out;
    }
}
