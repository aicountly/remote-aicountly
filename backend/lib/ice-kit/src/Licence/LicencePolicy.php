<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

/**
 * How much a product trusts what it has been told about a licence.
 *
 *   maxAgeSeconds   an envelope the central backend issued longer ago than this is `unknown`, however it was signed.
 *                   This bounds how long a stopped feed, a missed revocation or a replayed old answer can keep a customer
 *                   on the paid reserve. `explicitMaxAge` records whether the operator set it (the readiness check wants that).
 *   paidKinds       the licence kinds that may use the paid reserve. Default `paid` only: a trial or complimentary licence
 *                   is not paid capacity unless the business decides it is (WEBRTC_LICENCE_PAID_KINDS=paid,complimentary).
 *   allowCompat     whether a product's own legacy mapping (Remote's remote_entitlements, Connect's connect_license_tier) may
 *                   stand in where the central backend has said nothing. Default allow — during development — and the
 *                   readiness check refuses enforcement while it is on.
 */
final class LicencePolicy
{
    public const DEFAULT_MAX_AGE_SECONDS = 172800;
    public const CLOCK_SKEW_SECONDS      = 300;

    /** @param list<string> $paidKinds */
    public function __construct(
        public readonly int $maxAgeSeconds = self::DEFAULT_MAX_AGE_SECONDS,
        public readonly array $paidKinds = ['paid'],
        public readonly bool $allowCompat = true,
        public readonly bool $explicitMaxAge = false,
    ) {
    }

    /** @param callable(string): (string|null) $read */
    public static function fromEnvironment(callable $read): self
    {
        $hours = (float) ($read('WEBRTC_TURN_ENTITLEMENT_MAX_AGE_HOURS') ?? 0);
        $kinds = array_values(array_filter(
            array_map(static fn (string $k): string => strtolower(trim($k)), explode(',', (string) ($read('WEBRTC_LICENCE_PAID_KINDS') ?: 'paid'))),
            static fn (string $k): bool => in_array($k, ['paid', 'trial', 'complimentary'], true),
        ));

        return new self(
            $hours > 0 ? (int) round($hours * 3600) : self::DEFAULT_MAX_AGE_SECONDS,
            $kinds === [] ? ['paid'] : $kinds,
            strtolower(trim((string) ($read('WEBRTC_LICENCE_COMPAT') ?: 'allow'))) !== 'deny',
            $hours > 0,
        );
    }
}
