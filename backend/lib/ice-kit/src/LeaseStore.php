<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * Where the lease books live. One implementation ships (a locked JSON file, right for one API host);
 * a deployment with several API hosts sharing one set of relays needs a shared store behind this same
 * interface (a database row lock) — see docs/ACTIVE_ACTIVE_TURN.md.
 *
 * `transact` is the only way to change the books: the closure runs with the store locked against every
 * other process, receives the live LeaseBook, and whatever it did is committed when it returns. An
 * admission decision and the lease that records it are therefore one atomic step — two requests can
 * never both be admitted into the last free slot.
 */
interface LeaseStore
{
    /**
     * @template T
     * @param \Closure(LeaseBook): T $fn
     * @return T
     */
    public function transact(\Closure $fn, int $now): mixed;

    /** A consistent read-only copy, for status pages. */
    public function snapshot(int $now): LeaseBook;
}
