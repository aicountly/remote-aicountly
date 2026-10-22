<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

use Aicountly\Ice\Tier;

/**
 * The state of a customer's licence for one product, as the central licensing backend states it
 * (docs/LICENCE_ENTITLEMENT_CONTRACT.md §3). Five states, because "is this customer paid?" has more than two
 * honest answers:
 *
 *   free       a valid FREE plan (or no paid licence): free capacity only.
 *   paid       a valid, current PAID licence: the only state that may use the protected paid reserve.
 *   expired    a licence that has ended (valid_until passed, or the subscription lapsed).
 *   suspended  administratively stopped or revoked (non-payment, abuse, legal hold, explicit revocation).
 *   unknown    no trustworthy answer: nothing cached, a bad signature, a stale document, a mismatch, a failed lookup.
 *
 * Only `paid` maps to Tier::Paid. Every other state — and every doubt — is Tier::Free, so a missing or failing
 * licence source can only ever make capacity tighter for a customer, never hand out the reserve.
 */
enum LicenceState: string
{
    case Free = 'free';
    case Paid = 'paid';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Unknown = 'unknown';

    public static function parse(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom(strtolower(trim($value))) ?? self::Unknown) : self::Unknown;
    }

    public function tier(): Tier
    {
        return $this === self::Paid ? Tier::Paid : Tier::Free;
    }
}
