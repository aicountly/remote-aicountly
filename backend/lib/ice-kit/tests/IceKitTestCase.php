<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\HealthStore;
use Aicountly\Ice\IceIssuer;
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

    /** @param array<string, string|null> $env */
    protected function topology(array $env, array $defaults = []): Topology
    {
        return Topology::fromEnvironment(static fn (string $key): ?string => $env[$key] ?? null, $defaults);
    }

    protected function issuer(Topology $topology, ?HealthStore $health = null): IceIssuer
    {
        return new IceIssuer($topology, $health, static fn (): int => self::NOW);
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
