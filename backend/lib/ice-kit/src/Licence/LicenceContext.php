<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

/**
 * Who is asking, and where — the inputs to a contextual licence decision. Every field is derived on the SERVER:
 * the authenticated user, the company context the product has bound the request to, and the product itself.
 *
 *   customerId          the active company (customer) the user is operating under; null = a personal context, no company.
 *   subject             the authenticated user; null for a company-level question with no individual user (e.g. a Remote
 *                       session accounted to its company).
 *   membershipVerified  the product's OWN current-membership evidence for (subject, customer): true = confirmed now,
 *                       false = known not a member, null = the product cannot say. The central document states membership too;
 *                       either one saying "not a member" ends paid eligibility.
 *
 * A request field the client chose is acceptable as `customerId` ONLY because the central backend independently states
 * whether that user is a current member under that company; a false claim simply finds no eligibility.
 */
final class LicenceContext
{
    public function __construct(
        public readonly ?string $customerId,
        public readonly string $product,
        public readonly ?string $subject = null,
        public readonly ?bool $membershipVerified = null,
    ) {
    }

    public function personal(): bool
    {
        return $this->customerId === null;
    }
}
