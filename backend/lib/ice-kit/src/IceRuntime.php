<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * Builds the working parts from the environment the same way in every product, so a deployment's
 * `.env` means the same thing to Remote and to Connect.
 *
 * Files (all under the product's writable directory unless an absolute path is set):
 *
 *   WEBRTC_TURN_HEALTH_FILE    turn-health.json    probe results           (written by bin/probe.php)
 *   WEBRTC_TURN_LEASE_FILE     turn-leases.json    the admission books      (written by the API)
 *   WEBRTC_TURN_LOAD_FILE      turn-load.json      measured node load       (written by bin/ingest-load.php)
 *   WEBRTC_TURN_LATENCY_FILE   turn-latency.json   learned client latency    (written by the API)
 *
 * When WEBRTC_TURN_LEDGER_DSN is set, the admission books and the measured load live in the SHARED PostgreSQL
 * ledger instead of the two files: required whenever more than one product (or API host) uses the same TURN
 * nodes, because the paid reserve is a property of the node, not of one product's file.
 */
final class IceRuntime
{
    /**
     * @param callable(string): (string|null)                       $read     one environment key
     * @param string                                                $dir      writable directory, with a trailing slash
     * @param \Closure(string, array<string, mixed>): void|null     $audit    decision log
     * @param \Closure(): list<string>|null                         $licenceReadiness the product's answer to "is who-is-paid known, current and
     *        complete?": the reasons it is NOT (empty = ready). Consulted only when enforcement is requested; while
     *        any reason stands, `enforce` runs as `observe` — the books are kept and nothing is denied — so a missing
     *        licence feed can never turn paying customers into free ones.
     */
    public static function issuer(callable $read, string $dir, string $product, ?\Closure $audit = null, ?Topology $topology = null, ?\Closure $clock = null, array $defaults = [], ?\Closure $licenceReadiness = null): IceIssuer
    {
        $stores   = self::stores($read, $dir);
        $topology ??= Topology::fromEnvironment($read, $defaults);
        $blocked  = [];

        if ($topology->admission->enforcing()) {
            try {
                $blocked = $licenceReadiness === null ? ['no licence readiness check is wired in this product'] : $licenceReadiness();
            } catch (\Throwable) {
                $blocked = ['the licence readiness check failed'];
            }
            if ($blocked !== []) {
                $topology = $topology->withAdmission($topology->admission->withMode('observe'));
                if ($audit !== null) {
                    try {
                        $audit('enforce_blocked', ['reasons' => $blocked]);
                    } catch (\Throwable) {
                    }
                }
            }
        }

        return new IceIssuer(
            $topology,
            $stores['health'],
            $clock,
            $product,
            $stores['leases'],
            $stores['load'],
            $stores['latency'],
            $audit,
            $blocked,
        );
    }

    /**
     * @param callable(string): (string|null) $read
     * @return array{health: HealthStore, leases: LeaseStore, load: LoadSource, latency: LatencyStore}
     */
    public static function stores(callable $read, string $dir): array
    {
        $path = static function (string $key, string $name) use ($read, $dir): string {
            $configured = trim((string) ($read($key) ?? ''));

            return $configured !== '' ? $configured : $dir . 'ice/' . $name;
        };

        $ledger = LedgerConnection::fromEnvironment($read);
        $maxAge = max(30, (int) ($read('WEBRTC_TURN_LOAD_MAX_AGE') ?: 180));

        return [
            'health'  => HealthStore::fromEnvironment($read, $dir . 'ice/turn-health.json'),
            'leases'  => $ledger !== null ? new PdoLeaseStore($ledger) : new FileLeaseStore($path('WEBRTC_TURN_LEASE_FILE', 'turn-leases.json')),
            'load'    => $ledger !== null ? new PdoLoadStore($ledger, $maxAge) : LoadStore::fromEnvironment($read, $dir . 'ice/turn-load.json'),
            'latency' => new LatencyStore($path('WEBRTC_TURN_LATENCY_FILE', 'turn-latency.json')),
        ];
    }
}
