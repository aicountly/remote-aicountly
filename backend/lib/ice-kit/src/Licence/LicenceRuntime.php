<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

use Aicountly\Ice\LedgerConnection;

/** Builds the adapter's parts from the product's environment, like IceRuntime does for the relay stores. */
final class LicenceRuntime
{
    /** @param callable(string): (string|null) $read */
    public static function cache(callable $read, string $dir): EntitlementCache
    {
        $ledger = LedgerConnection::fromEnvironment($read);
        if ($ledger !== null) {
            return new PdoEntitlementCache($ledger);
        }
        $path = trim((string) ($read('WEBRTC_LICENCE_CACHE_FILE') ?? ''));

        return new FileEntitlementCache($path !== '' ? $path : $dir . 'ice/licence-cache.json');
    }

    /** @param callable(string): (string|null) $read */
    public static function resolver(callable $read, string $dir, ?\Closure $clock = null, ?EntitlementCache $cache = null): LicenceResolver
    {
        return new LicenceResolver($cache ?? self::cache($read, $dir), EntitlementVerifier::fromEnvironment($read), LicencePolicy::fromEnvironment($read), $clock);
    }

    /**
     * The product's licence readiness check, shaped for IceRuntime::issuer().
     *
     * @param callable(string): (string|null) $read
     * @return \Closure(): list<string>
     */
    public static function readiness(callable $read, string $dir, string $product): \Closure
    {
        return static fn (): array => LicenceReadiness::blockers(
            $read,
            self::cache($read, $dir),
            EntitlementVerifier::fromEnvironment($read),
            LicencePolicy::fromEnvironment($read),
            $product,
        );
    }
}
