<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

use Aicountly\Ice\Tier;

/**
 * The answer to "which capacity class is this customer in, and on what evidence?".
 *
 * `evidence` says how much to trust it: `central` (a signature-verified, fresh, current document from the central
 * backend), `compat` (a product's own legacy mapping, development-time only) or `none` (nothing usable). Only a
 * `paid` state yields Tier::Paid, and a `compat` paid is honoured only while LicencePolicy::allowCompat is on.
 */
final class Resolution
{
    public function __construct(
        public readonly LicenceState $state,
        public readonly string $reason,
        public readonly string $evidence,
        public readonly ?string $planCode = null,
        public readonly ?int $revision = null,
    ) {
    }

    public function tier(): Tier
    {
        return $this->state->tier();
    }

    public static function unknown(string $reason): self
    {
        return new self(LicenceState::Unknown, $reason, 'none');
    }

    /** What may be logged: no customer, subject or licence identifier. @return array<string, mixed> */
    public function audit(): array
    {
        return ['licence' => $this->state->value, 'licenceReason' => $this->reason, 'licenceEvidence' => $this->evidence, 'plan' => $this->planCode];
    }
}
