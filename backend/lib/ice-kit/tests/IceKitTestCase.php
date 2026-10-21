<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\HealthStore;
use Aicountly\Ice\IceIssuer;
use Aicountly\Ice\LeaseStore;
use Aicountly\Ice\LoadStore;
use Aicountly\Ice\MemoryLeaseStore;
use Aicountly\Ice\Topology;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/autoload.php';

/**
 * Shared fixtures: the production shape (Mumbai active, Greater Noida configured but disabled), built
 * from the same environment keys a deployment uses, against a fixed clock.
 */
abstract class IceKitTestCase extends TestCase
{
    protected const NOW = 1_800_000_000;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** @return array<string, string> */
    protected function productionEnv(array $overrides = []): array
    {
        return array_merge([
            'WEBRTC_TURN_NODES'                  => 'mum, ncr',
            'WEBRTC_TURN_STATIC_AUTH_SECRET'     => 'shared-coturn-secret',
            'WEBRTC_TURN_TTL'                    => '3600',
            'WEBRTC_TURN_NODE_MUM_HOST'          => 'turn-mum.aicountly.com',
            'WEBRTC_TURN_NODE_MUM_STATUS'        => 'active',
            'WEBRTC_TURN_NODE_MUM_PRIORITY'      => '10',
            'WEBRTC_TURN_NODE_MUM_REGION'        => 'in-west',
            'WEBRTC_TURN_NODE_MUM_LABEL'         => 'Mumbai',
            'WEBRTC_TURN_NODE_MUM_TLS_PORT'      => '443',
            'WEBRTC_TURN_NODE_NCR_HOST'          => 'turn-ncr.aicountly.com',
            'WEBRTC_TURN_NODE_NCR_STATUS'        => 'disabled',
            'WEBRTC_TURN_NODE_NCR_PRIORITY'      => '20',
            'WEBRTC_TURN_NODE_NCR_REGION'        => 'in-north',
            'WEBRTC_TURN_NODE_NCR_LABEL'         => 'Greater Noida',
            'WEBRTC_TURN_NODE_NCR_TLS_PORT'      => '443',
        ], $overrides);
    }

    /**
     * Two regional peers, both serving — Mumbai and Greater Noida — each with its own secret and a capacity
     * that makes the arithmetic easy: 300 allocations (100 leases of 3 URLs) and 200 Mbit/s (100 leases of
     * the 2 Mbit/s default profile), so 100 "units" per node, 50 of them the paid reserve.
     *
     * @return array<string, string>
     */
    protected function activeActiveEnv(array $overrides = []): array
    {
        return array_merge($this->productionEnv([
            'WEBRTC_TURN_STATIC_AUTH_SECRET'     => '',
            'WEBRTC_TURN_NODE_MUM_SECRET'        => 'mumbai-secret-0123456789',
            'WEBRTC_TURN_NODE_NCR_SECRET'        => 'noida-secret-0123456789',
            'WEBRTC_TURN_NODE_NCR_STATUS'        => 'active',
            'WEBRTC_TURN_NODE_MUM_LAT'           => '19.0760',
            'WEBRTC_TURN_NODE_MUM_LON'           => '72.8777',
            'WEBRTC_TURN_NODE_NCR_LAT'           => '28.4744',
            'WEBRTC_TURN_NODE_NCR_LON'           => '77.5040',
            'WEBRTC_TURN_NODE_MUM_MAX_ALLOCATIONS' => '300',
            'WEBRTC_TURN_NODE_MUM_MAX_MBPS'      => '200',
            'WEBRTC_TURN_NODE_NCR_MAX_ALLOCATIONS' => '300',
            'WEBRTC_TURN_NODE_NCR_MAX_MBPS'      => '200',
            'WEBRTC_TURN_ADMISSION'              => 'enforce',
            'WEBRTC_TURN_MAX_NODES'              => '2',
        ]), $overrides);
    }

    /** @param array<string, string|null> $env */
    protected function topology(array $env, array $defaults = []): Topology
    {
        return Topology::fromEnvironment(static fn (string $key): ?string => $env[$key] ?? null, $defaults);
    }

    protected function issuer(Topology $topology, ?HealthStore $health = null): IceIssuer
    {
        return new IceIssuer($topology, $health, static fn (): int => self::NOW);
    }

    /**
     * An issuer with books, optional telemetry, and an audit trail it hands back.
     *
     * @param list<array{0: string, 1: array<string, mixed>}> $audit filled by reference
     */
    protected function bookedIssuer(Topology $topology, ?HealthStore $health = null, ?LeaseStore $leases = null, ?LoadStore $load = null, array &$audit = [], ?\Closure $clock = null): IceIssuer
    {
        return new IceIssuer(
            $topology,
            $health,
            $clock ?? static fn (): int => self::NOW,
            'remote',
            $leases ?? new MemoryLeaseStore(),
            $load,
            null,
            static function (string $event, array $data) use (&$audit): void {
                $audit[] = [$event, $data];
            },
        );
    }

    protected function tempPath(): string
    {
        $path = sys_get_temp_dir() . '/ice-kit-test-' . bin2hex(random_bytes(6)) . '.json';
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param array<string, int>                                       $failures   node id → consecutive failures, checked at $checkedAt
     * @param array<string, array<string, array<string, bool|null>>>   $transports node id → transport → {binding, allocation}
     */
    protected function healthWith(array $failures, int $checkedAt = self::NOW, array $transports = []): HealthStore
    {
        $path = $this->tempPath();
        $nodes = [];
        foreach ($failures as $id => $count) {
            $nodes[$id] = ['ok' => $count === 0, 'checkedAt' => $checkedAt, 'consecutiveFailures' => $count, 'transports' => $transports[$id] ?? []];
        }
        file_put_contents($path, json_encode(['version' => 1, 'nodes' => $nodes]));

        return new HealthStore($path, 900, 2);
    }

    /** A probe result in which only the listed transports allocated. */
    protected static function onlyWorking(string ...$working): array
    {
        $all = [];
        foreach (['udp', 'tcp', 'tls'] as $t) {
            $ok = in_array($t, $working, true);
            $all[$t] = ['binding' => $ok, 'allocation' => $ok];
        }

        return $all;
    }
}
