<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

use Aicountly\Ice\Tier;

/**
 * The answer to "which capacity class is this user in, in THIS context, and on what evidence?".
 *
 * `evidence` says how much to trust it: `central` (a signature-verified, fresh, current document from the central
 * backend), `compat` (a product's own legacy mapping, development-time only) or `none` (nothing usable). Only a
 * `paid` state yields Tier::Paid, and a `compat` paid is honoured only while LicencePolicy::allowCompat is on.
 *
 * `basis` says which kind of entitlement made it paid (or would have): `company_wide` (every current member of the
 * company), `seat` (an individually assigned seat), `personal` (the user's own licence) or `host_meeting` (an external
 * guest served under a paid host's licensed meeting — set by GuestPolicy, never by an account lookup). The state is
 * about THIS user in THIS context: a company's paid licence leaves a non-member, or a member without a seat, `free`.
 *
 * `entitlements` carries the document's feature entitlements (`meeting.max_guests`, …) so a product can apply limits.
 */
final class Resolution
{
    /** @param array<string, string|int|bool> $entitlements */
    public function __construct(
        public readonly LicenceState $state,
        public readonly string $reason,
        public readonly string $evidence,
        public readonly ?string $planCode = null,
        public readonly ?int $revision = null,
        public readonly ?string $basis = null,
        public readonly array $entitlements = [],
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

    public function entitlement(string $code): string|int|bool|null
    {
        return $this->entitlements[$code] ?? null;
    }

    /** What may be logged: no customer, subject or licence identifier. @return array<string, mixed> */
    public function audit(): array
    {
        return ['licence' => $this->state->value, 'licenceReason' => $this->reason, 'licenceEvidence' => $this->evidence, 'licenceBasis' => $this->basis, 'plan' => $this->planCode];
    }
}
