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
 */
final class IceRuntime
{
    /**
     * @param callable(string): (string|null)                       $read     one environment key
     * @param string                                                $dir      writable directory, with a trailing slash
     * @param \Closure(string, array<string, mixed>): void|null     $audit    decision log
     */
    public static function issuer(callable $read, string $dir, string $product, ?\Closure $audit = null, ?Topology $topology = null, ?\Closure $clock = null, array $defaults = []): IceIssuer
    {
        $stores = self::stores($read, $dir);

        return new IceIssuer(
            $topology ?? Topology::fromEnvironment($read, $defaults),
            $stores['health'],
            $clock,
            $product,
            $stores['leases'],
            $stores['load'],
            $stores['latency'],
            $audit,
        );
    }

    /**
     * @param callable(string): (string|null) $read
     * @return array{health: HealthStore, leases: FileLeaseStore, load: LoadStore, latency: LatencyStore}
     */
    public static function stores(callable $read, string $dir): array
    {
        $path = static function (string $key, string $name) use ($read, $dir): string {
            $configured = trim((string) ($read($key) ?? ''));

            return $configured !== '' ? $configured : $dir . 'ice/' . $name;
        };

        return [
            'health'  => HealthStore::fromEnvironment($read, $dir . 'ice/turn-health.json'),
            'leases'  => new FileLeaseStore($path('WEBRTC_TURN_LEASE_FILE', 'turn-leases.json')),
            'load'    => LoadStore::fromEnvironment($read, $dir . 'ice/turn-load.json'),
            'latency' => new LatencyStore($path('WEBRTC_TURN_LATENCY_FILE', 'turn-latency.json')),
        ];
    }
}
