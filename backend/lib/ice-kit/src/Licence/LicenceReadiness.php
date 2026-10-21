<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

/**
 * Whether the central licence source is operational and validated — the condition for hard paid/free enforcement.
 * The reasons it is NOT; empty means ready. While any stands the issuer runs `enforce` as `observe`.
 *
 * Deliberately independent of any plan list or product table: once the central backend classifies plans, a product
 * has no mapping left to get wrong. What remains to check is that the source exists, is trusted, is being read, and
 * that an operator has attested to validating it against real customers.
 */
final class LicenceReadiness
{
    /**
     * @param callable(string): (string|null) $read
     * @return list<string>
     */
    public static function blockers(callable $read, EntitlementCache $cache, EntitlementVerifier $verifier, LicencePolicy $policy, string $product, ?int $now = null): array
    {
        $now ??= time();
        $why = [];

        if (CentralClient::fromEnvironment($read, $product) === null) {
            $why[] = 'the central licence API is not configured (WEBRTC_LICENCE_API_URL, _KEY_ID, _ENVIRONMENT and the _S2S_SECRET): it does not exist yet or this product has not been connected to it';
        }
        if (! $verifier->available()) {
            $why[] = 'entitlement signatures cannot be verified (WEBRTC_LICENCE_PUBLIC_KEYS empty or libsodium missing)';
        }
        if (! $policy->explicitMaxAge) {
            $why[] = 'WEBRTC_TURN_ENTITLEMENT_MAX_AGE_HOURS is not set: a stopped feed would leave customers paid for the default period unreviewed';
        }

        try {
            $sync = $cache->syncState($product);
            if ($sync['lastSyncAt'] === null) {
                $why[] = 'the entitlement feed has never been read (bin/sync-entitlements.php has not completed a run)';
            } elseif ($now - $sync['lastSyncAt'] > $policy->maxAgeSeconds) {
                $why[] = 'the entitlement feed has not completed a run within the maximum age: it has stopped';
            }
        } catch (\Throwable) {
            $why[] = 'the entitlement cache could not be read';
        }

        if ($policy->allowCompat) {
            $why[] = 'legacy compatibility mapping is still accepted as evidence (set WEBRTC_LICENCE_COMPAT=deny once the central source is validated)';
        }
        if (strtolower(trim((string) ($read('WEBRTC_TURN_LICENCE_FEED') ?? ''))) !== 'confirmed') {
            $why[] = 'WEBRTC_TURN_LICENCE_FEED is not "confirmed": nobody has attested that the central source was validated against real customers';
        }

        return $why;
    }
}
