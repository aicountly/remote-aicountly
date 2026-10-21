<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/** Books that live and die with the process. For tests, and for a deployment that wants none persisted. */
final class MemoryLeaseStore implements LeaseStore
{
    /** @var array<string, Lease> */
    private array $leases = [];

    public function transact(\Closure $fn, int $now): mixed
    {
        $book   = new LeaseBook($this->leases, $now);
        $result = $fn($book);
        $this->leases = $book->all();

        return $result;
    }

    public function snapshot(int $now): LeaseBook
    {
        return new LeaseBook($this->leases, $now);
    }
}
