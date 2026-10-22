<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * The licence class a relay allocation is accounted to.
 *
 * Two classes only, because that is the decision being enforced: paid customers have a reserve of every
 * node's capacity that free users cannot touch. Anything a product cannot positively identify as paid is
 * `Free` — a lookup that fails must never hand out the reserve.
 */
enum Tier: string
{
    case Paid = 'paid';
    case Free = 'free';

    public static function parse(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom(strtolower(trim($value))) ?? self::Free) : self::Free;
    }

    /**
     * One letter, put in front of the subject in the TURN username (`<expiry>:p.<subject>`). The username
     * is HMAC-signed, so a client cannot change it, and it is what coturn's own logs record: traffic can
     * be attributed to a tier from the relay's side, independently of this library's books.
     */
    public function letter(): string
    {
        return $this === self::Paid ? 'p' : 'f';
    }

    /** The tier a username's subject names, or null if it carries none (a credential from before tiers). */
    public static function fromUsername(string $username): ?self
    {
        if (preg_match('/^\d+:([pf])\./', $username, $m) !== 1) {
            return null;
        }

        return $m[1] === 'p' ? self::Paid : self::Free;
    }
}
