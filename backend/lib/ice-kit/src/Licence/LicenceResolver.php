<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

/**
 * Turns cached central envelopes into a capacity class — the one place a licence becomes `paid`.
 *
 * Paid is granted only when EVERY one of these holds, each checked here and each failing to `free` capacity:
 *   1. an envelope exists for exactly this customer (or subject) and product — tenant isolation;
 *   2. its Ed25519 signature verifies against the central public key — the cache and database are not trusted;
 *   3. the document is well-formed and its state is `paid` with a paid plan class and an allowed kind;
 *   4. it was issued within the maximum age and not in the future — a stopped feed or a replayed answer expires;
 *   5. no revocation at or above its revision is recorded — revocation beats a still-valid-looking document;
 *   6. now is inside valid_from … valid_until.
 *
 * Nothing a client sends is an input here. The caller passes a customer/subject id it derived server-side (a session's
 * company, an authenticated user) — never one taken from a request field.
 */
final class LicenceResolver
{
    private readonly \Closure $clock;

    public function __construct(
        private readonly EntitlementCache $cache,
        private readonly EntitlementVerifier $verifier,
        public readonly LicencePolicy $policy = new LicencePolicy(),
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param (\Closure(): (Resolution|null))|null $compat the product's legacy mapping, consulted ONLY when the central
     *        backend has said nothing about this customer and the policy allows it
     */
    public function forCustomer(string $customerId, string $product, ?\Closure $compat = null): Resolution
    {
        return $this->resolve(EntitlementCache::CUSTOMER, $customerId, $product, $compat);
    }

    /** @param (\Closure(): (Resolution|null))|null $compat */
    public function forSubject(string $subject, string $product, ?\Closure $compat = null): Resolution
    {
        return $this->resolve(EntitlementCache::SUBJECT, $subject, $product, $compat);
    }

    /** @param (\Closure(): (Resolution|null))|null $compat */
    private function resolve(string $scope, string $ref, string $product, ?\Closure $compat): Resolution
    {
        try {
            $central = $this->evaluate($scope, $ref, $product);
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

    private function evaluate(string $scope, string $ref, string $product): Resolution
    {
        $entry = $this->cache->get($scope, $ref, $product);
        if ($entry === null) {
            return Resolution::unknown('no_entitlement');
        }
        $envelope = $entry['envelope'];

        if (! $this->verifier->available()) {
            return Resolution::unknown('verifier_unavailable');
        }
        if (! $this->verifier->verify($envelope)) {
            return Resolution::unknown('signature_invalid');
        }

        try {
            $e = Entitlement::fromDocument((array) $envelope['document']);
        } catch (\InvalidArgumentException) {
            return Resolution::unknown('malformed');
        }

        $identityOk = $e->productId === $product && ($scope === EntitlementCache::CUSTOMER ? $e->customerId === $ref && $e->subject === null : $e->subject === $ref);
        if (! $identityOk) {
            return Resolution::unknown('identity_mismatch');
        }

        $now = ($this->clock)();
        if ($e->issuedAt > $now + LicencePolicy::CLOCK_SKEW_SECONDS) {
            return Resolution::unknown('issued_in_future');
        }
        if ($now - $e->issuedAt > $this->policy->maxAgeSeconds) {
            return Resolution::unknown('stale');
        }

        $mk = static fn (LicenceState $s, string $reason): Resolution => new Resolution($s, $reason, 'central', $e->planCode, $e->revision);

        if ($this->cache->revokedRevision($e->customerId, $product) >= $e->revision) {
            return $mk(LicenceState::Suspended, 'revoked');
        }

        return match ($e->state) {
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
    }

    /** A product's legacy mapping, as a Resolution that carries its own (weaker) evidence label. */
    public static function compat(LicenceState $state, string $reason, ?string $planCode = null): Resolution
    {
        return new Resolution($state, $reason, 'compat', $planCode);
    }
}
