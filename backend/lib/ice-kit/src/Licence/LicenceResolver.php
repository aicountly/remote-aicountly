<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

/**
 * Turns cached central envelopes into a capacity class for ONE user in ONE company context — the single place a licence
 * becomes `paid`. Licensing is contextual, never global: a user is paid in company A only because A's subscription permits
 * that user's access, and acquires nothing from company B (or from being "a member of some paying company").
 *
 * What is looked up, for a LicenceContext (user, active company, product):
 *   * a member-level entitlement for (company, user): states current membership and — for seat licences — the seat;
 *   * else the company-level entitlement, which only company-wide licences can satisfy, and then only with membership evidence;
 *   * with NO company (a personal context), the user's own personal entitlement, and nothing else.
 *
 * Paid is granted only when EVERY one of these holds, each failing to `free` capacity:
 *   1. an envelope exists for exactly this company, user and product — tenant isolation;
 *   2. its Ed25519 signature verifies against the central public key — the cache and database are not trusted;
 *   3. it is well-formed and its state is `paid` with a paid plan class and an allowed kind;
 *   4. it was issued within the maximum age and not in the future — a stopped feed or a replayed answer expires;
 *   5. no revocation of that (company, user) pair — or of the company — at or above its revision is recorded;
 *   6. now is inside valid_from … valid_until;
 *   7. the user is a CURRENT member (the document says so, and the product's own evidence does not contradict it) and, for a seat
 *      licence, the seat is `assigned` — not unassigned, not revoked.
 *
 * Nothing a client sends is trusted: the user is the authenticated one, the product is fixed, and the company is only a claim
 * that the central document (membership, seat) must confirm. A false claim finds no eligibility and is simply free.
 */
final class LicenceResolver
{
    private readonly \Closure $clock;

    /**
     * @param (\Closure(string, string, string): array<string, mixed>)|null $fetchMember optional fetch-through for a missing or stale
     *        member-level entitlement: (customerId, subject, product) → envelope; may throw. Off by default.
     */
    public function __construct(
        private readonly EntitlementCache $cache,
        private readonly EntitlementVerifier $verifier,
        public readonly LicencePolicy $policy = new LicencePolicy(),
        ?\Closure $clock = null,
        private readonly ?\Closure $fetchMember = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param (\Closure(): (Resolution|null))|null $compat the product's legacy mapping, consulted ONLY when the central backend has
     *        said nothing and the policy allows it
     */
    public function forContext(LicenceContext $ctx, ?\Closure $compat = null): Resolution
    {
        try {
            $central = $this->central($ctx);
        } catch (\Throwable) {
            return Resolution::unknown('lookup_failed');
        }

        if ($central->reason !== 'no_entitlement' || $compat === null || ! $this->policy->allowCompat) {
            return $central;
        }

        try {
            $legacy = $compat();
        } catch (\Throwable) {
            $legacy = null;
        }

        return $legacy ?? $central;
    }

    /** A company-level question with no individual user (Remote: the company that owns a session). @param (\Closure(): (Resolution|null))|null $compat */
    public function forCustomer(string $customerId, string $product, ?\Closure $compat = null): Resolution
    {
        return $this->forContext(new LicenceContext($customerId, $product), $compat);
    }

    private function central(LicenceContext $ctx): Resolution
    {
        if ($ctx->customerId === null) {
            if ($ctx->subject === null) {
                return Resolution::unknown('no_context');
            }
            [$res] = $this->inspect(EntitlementCache::USER, $ctx->subject, $ctx->product, false);

            return $res;
        }

        $customer = $ctx->customerId;
        [$companyRes, $company] = $this->inspect(EntitlementCache::CUSTOMER, $customer, $ctx->product, false);

        if ($ctx->subject !== null) {
            [$memberRes, $member] = $this->inspect(EntitlementCache::MEMBER, Entitlement::memberRef($customer, $ctx->subject), $ctx->product, true);

            if ($memberRes->reason !== 'no_entitlement') {
                $final = $member === null ? $memberRes : $this->memberEligibility($memberRes, $member, $ctx);
                // A company-level licence that has lapsed or been revoked beats a seat document that has not yet caught up.
                if ($final->state === LicenceState::Paid && $companyRes->evidence === 'central' && in_array($companyRes->state, [LicenceState::Expired, LicenceState::Suspended], true)) {
                    return $companyRes;
                }

                return $final;
            }
        }

        return $company === null ? $companyRes : $this->companyEligibility($companyRes, $company, $ctx);
    }

    /** Member-level document: current membership and, for a seat licence, an assigned seat. */
    private function memberEligibility(Resolution $r, Entitlement $e, LicenceContext $ctx): Resolution
    {
        if ($r->state !== LicenceState::Paid) {
            return $r;
        }
        if ($ctx->membershipVerified === false || $e->member !== true) {
            return $this->downgrade($r, 'not_a_member');
        }
        if ($e->model === 'seat' && $e->seat !== 'assigned') {
            return $this->downgrade($r, 'seat_' . $e->seat);
        }

        return $r;
    }

    /** Company-level document (no individual membership evidence in it): only a company-wide licence, and only for a verified member. */
    private function companyEligibility(Resolution $r, Entitlement $e, LicenceContext $ctx): Resolution
    {
        if ($r->state !== LicenceState::Paid) {
            return $r;
        }
        if ($e->model === 'seat') {
            return $this->downgrade($r, $ctx->subject === null ? 'seat_requires_subject' : 'seat_not_assigned');
        }
        if ($ctx->subject === null) {
            return $r;
        }

        return match ($ctx->membershipVerified) {
            true    => $r,
            false   => $this->downgrade($r, 'not_a_member'),
            default => $this->downgrade($r, 'membership_unverified'),
        };
    }

    private function downgrade(Resolution $r, string $reason): Resolution
    {
        return new Resolution(LicenceState::Free, $reason, $r->evidence, $r->planCode, $r->revision, $r->basis, $r->entitlements);
    }

    /**
     * The intrinsic standing of one cached envelope — its signature, identity, freshness, revocation, state and validity — before
     * any question of whether THIS user is eligible under it.
     *
     * @return array{0: Resolution, 1: Entitlement|null}
     */
    private function inspect(string $scope, string $ref, string $product, bool $fetch): array
    {
        $entry = $this->cache->get($scope, $ref, $product);

        if ($fetch && $this->fetchMember !== null && $scope === EntitlementCache::MEMBER && ($entry === null || $this->isStale($entry))) {
            $entry = $this->fetchThrough($ref, $product) ?? $entry;
        }
        if ($entry === null) {
            return [Resolution::unknown('no_entitlement'), null];
        }
        $envelope = $entry['envelope'];

        if (! $this->verifier->available()) {
            return [Resolution::unknown('verifier_unavailable'), null];
        }
        if (! $this->verifier->verify($envelope)) {
            return [Resolution::unknown('signature_invalid'), null];
        }

        try {
            $e = Entitlement::fromDocument((array) $envelope['document']);
        } catch (\InvalidArgumentException) {
            return [Resolution::unknown('malformed'), null];
        }

        if (! $this->identityMatches($e, $scope, $ref, $product)) {
            return [Resolution::unknown('identity_mismatch'), null];
        }

        $now = ($this->clock)();
        if ($e->issuedAt > $now + LicencePolicy::CLOCK_SKEW_SECONDS) {
            return [Resolution::unknown('issued_in_future'), null];
        }
        if ($now - $e->issuedAt > $this->policy->maxAgeSeconds) {
            return [Resolution::unknown('stale'), null];
        }

        $ents = [];
        foreach ($e->entitlements as $x) {
            $ents[$x['code']] = $x['value'];
        }
        $mk = static fn (LicenceState $s, string $reason): Resolution => new Resolution($s, $reason, 'central', $e->planCode, $e->revision, $e->model, $ents);

        if ($this->cache->revokedRevision($e->customerId, $product, $e->subject) >= $e->revision) {
            return [$mk(LicenceState::Suspended, 'revoked'), $e];
        }

        $res = match ($e->state) {
            LicenceState::Suspended => $mk(LicenceState::Suspended, 'suspended'),
            LicenceState::Expired   => $mk(LicenceState::Expired, 'expired'),
            LicenceState::Unknown   => $mk(LicenceState::Unknown, 'central_unknown'),
            LicenceState::Free      => $mk(LicenceState::Free, 'free'),
            LicenceState::Paid      => match (true) {
                $e->validFrom !== null && $e->validFrom > $now  => $mk(LicenceState::Unknown, 'not_yet_valid'),
                $e->validUntil !== null && $e->validUntil <= $now => $mk(LicenceState::Expired, 'valid_until_passed'),
                ! in_array($e->kind, $this->policy->paidKinds, true) => $mk(LicenceState::Free, 'kind_not_paid'),
                default => $mk(LicenceState::Paid, 'paid'),
            },
        };

        return [$res, $e];
    }

    private function identityMatches(Entitlement $e, string $scope, string $ref, string $product): bool
    {
        if ($e->productId !== $product) {
            return false;
        }

        return match ($scope) {
            EntitlementCache::CUSTOMER => $e->scope === Entitlement::COMPANY && $e->customerId === $ref && $e->subject === null,
            EntitlementCache::MEMBER   => $e->scope === Entitlement::COMPANY && $e->subject !== null && $e->customerId !== null
                && Entitlement::memberRef($e->customerId, $e->subject) === $ref,
            EntitlementCache::USER     => $e->scope === Entitlement::USER && $e->subject === $ref,
            default                    => false,
        };
    }

    /** @param array{envelope: array<string, mixed>, fetchedAt: int} $entry */
    private function isStale(array $entry): bool
    {
        $issued = $entry['envelope']['document']['issued_at'] ?? null;
        $t      = is_string($issued) ? strtotime($issued) : false;

        return $t === false || ($this->clock)() - $t > $this->policy->maxAgeSeconds;
    }

    /** @return array{envelope: array<string, mixed>, fetchedAt: int}|null */
    private function fetchThrough(string $memberRef, string $product): ?array
    {
        [$customer, $subject] = explode('|', $memberRef, 2) + [1 => ''];
        try {
            $envelope = ($this->fetchMember)($customer, $subject, $product);
            if (! $this->verifier->verify($envelope)) {
                return null;
            }
            $this->cache->put(EntitlementCache::MEMBER, $memberRef, $product, $envelope, ($this->clock)());

            return $this->cache->get(EntitlementCache::MEMBER, $memberRef, $product);
        } catch (\Throwable) {
            return null;
        }
    }

    /** A product's legacy mapping, as a Resolution that carries its own (weaker) evidence label. */
    public static function compat(LicenceState $state, string $reason, ?string $planCode = null): Resolution
    {
        return new Resolution($state, $reason, 'compat', $planCode);
    }
}
