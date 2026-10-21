<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\ClientContext;
use Aicountly\Ice\LatencyStore;
use Aicountly\Ice\LoadStore;
use Aicountly\Ice\Proximity;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/IceKitTestCase.php';

final class LoadAndLatencyTest extends IceKitTestCase
{
    public function testAReportIsAcceptedAndExpires(): void
    {
        $store = new LoadStore($this->tempPath(), 180);
        $this->assertTrue($store->ingest(['node' => 'mum', 'at' => self::NOW, 'allocations' => 120, 'rxBps' => 4_000_000, 'txBps' => 5_000_000], self::NOW)['ok']);

        $this->assertSame(['mum' => ['allocations' => 120, 'bps' => 9_000_000, 'ageSeconds' => 0]], $store->fresh(self::NOW));
        $this->assertSame([], $store->fresh(self::NOW + 181), 'A silent collector must not freeze a node at its last reading.');
        @unlink($store->path() . '.lock');
    }

    #[DataProvider('badReports')]
    public function testMalformedOrImplausibleReportsAreRefused(array $report, string $error): void
    {
        $store = new LoadStore($this->tempPath());
        $this->assertSame(['ok' => false, 'error' => $error], $store->ingest($report, self::NOW));
        $this->assertSame([], $store->fresh(self::NOW));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badReports(): array
    {
        $ok = ['node' => 'mum', 'at' => self::NOW, 'allocations' => 1, 'rxBps' => 1, 'txBps' => 1];

        return [
            'node id'         => [['node' => '../etc'] + $ok, 'bad_node'],
            'future'          => [['at' => self::NOW + 4000] + $ok, 'timestamp_out_of_range'],
            'ancient'         => [['at' => self::NOW - 4000] + $ok, 'timestamp_out_of_range'],
            'no timestamp'    => [['at' => 'soon'] + $ok, 'bad_timestamp'],
            'negative'        => [['rxBps' => -1] + $ok, 'bad_rxBps'],
            'absurd'          => [['txBps' => 1e18] + $ok, 'bad_txBps'],
            'missing'         => [['node' => 'mum', 'at' => self::NOW], 'bad_allocations'],
        ];
    }

    public function testLatencyIsLearnedOnlyAfterEnoughSamplesAndByRegion(): void
    {
        $store = new LatencyStore($this->tempPath());
        for ($i = 0; $i < 4; $i++) {
            $store->observe('in-south', ['mum' => 30, 'ncr' => 90], ['mum', 'ncr'], self::NOW);
        }
        $this->assertSame([], $store->learned('in-south', self::NOW), 'Four samples are not evidence.');

        $store->observe('in-south', ['mum' => 30, 'ncr' => 90], ['mum', 'ncr'], self::NOW);
        $this->assertSame(['mum' => 30, 'ncr' => 90], $store->learned('in-south', self::NOW));
        $this->assertSame([], $store->learned('in-north', self::NOW));
        $this->assertSame([], $store->learned('in-south', self::NOW + LatencyStore::MAX_AGE + 1));
        @unlink($store->path() . '.lock');
    }

    public function testOneOutlierMovesTheAverageByAtMostAFifth(): void
    {
        $store = new LatencyStore($this->tempPath());
        for ($i = 0; $i < 10; $i++) {
            $store->observe('in-south', ['mum' => 40], ['mum'], self::NOW);
        }
        $store->observe('in-south', ['mum' => 3000], ['mum'], self::NOW);

        $this->assertLessThanOrEqual(40 + 0.2 * 2960 + 1, $store->learned('in-south', self::NOW)['mum']);
        $this->assertSame([], (new LatencyStore($this->tempPath()))->learned('anywhere', self::NOW));
        @unlink($store->path() . '.lock');
    }

    public function testUnknownNodesAreNotLearned(): void
    {
        $store = new LatencyStore($this->tempPath());
        $store->observe('in-south', ['evil' => 5], ['mum', 'ncr'], self::NOW);
        $this->assertSame([], $store->read());
        @unlink($store->path() . '.lock');
    }

    public function testProximityUsesTheBestEvidenceAvailable(): void
    {
        $topology = $this->topology($this->activeActiveEnv());
        $mum      = $topology->node('mum');
        $nodes    = $topology->nodes();

        $this->assertSame('measured', Proximity::estimate($mum, ClientContext::fromRequest(['rtt' => ['mum' => 22]]), $nodes, ['mum' => 70])['basis']);
        $this->assertSame('learned', Proximity::estimate($mum, new ClientContext(), $nodes, ['mum' => 70])['basis']);
        $this->assertSame('geo', Proximity::estimate($mum, new ClientContext('', 19.0, 72.8), $nodes)['basis']);
        $this->assertSame(['ms' => 10.0, 'basis' => 'region'], Proximity::estimate($mum, new ClientContext('in-west'), $nodes));
        $this->assertSame('region', Proximity::estimate($topology->node('ncr'), new ClientContext('in-west'), $nodes)['basis']);
        $this->assertSame(['ms' => 60.0, 'basis' => 'unknown'], Proximity::estimate($mum, new ClientContext(), $nodes));

        // Mumbai ↔ Greater Noida is about 1,150 km: roughly 29 ms of round trip in this model.
        $this->assertEqualsWithDelta(29, Proximity::estimate($topology->node('ncr'), new ClientContext('in-west'), $nodes)['ms'], 4);
    }

    public function testNewConfigurationKeysAreParsedAndBounded(): void
    {
        $t = $this->topology($this->activeActiveEnv([
            'WEBRTC_TURN_NODE_MUM_MAX_MBPS' => '400.5', 'WEBRTC_TURN_BUSY_AT' => '999', 'WEBRTC_TURN_SHED_FREE_AT' => '1',
            'WEBRTC_TURN_ADMISSION' => 'whatever', 'WEBRTC_TURN_PROFILE_REMOTE_KBPS' => '8000', 'WEBRTC_TURN_FALLBACK_TRANSPORTS' => 'tcp,tls',
        ]));

        $this->assertSame(400_500_000, $t->node('mum')->capacity->maxBps);
        $this->assertSame('observe', $t->admission->mode, 'An unknown mode falls back to the non-denying one.');
        $this->assertGreaterThan($t->admission->busyAt, $t->admission->shedFreeAt);
        $this->assertSame(8_000_000, $t->admission->bpsFor('remote'));
        $this->assertSame(2_000_000, $t->admission->bpsFor('no-such-profile'));
        $this->assertSame(['tcp', 'tls'], $t->fallbackTransports);
        $this->assertEqualsWithDelta(19.076, $t->node('mum')->lat, 0.001);
    }

    public function testHalfACoordinateIsAConfigurationProblemNotAFatalError(): void
    {
        $t = $this->topology($this->activeActiveEnv(['WEBRTC_TURN_NODE_MUM_LON' => '']));
        $this->assertNull($t->node('mum')->lat);
        $t2 = $this->topology($this->activeActiveEnv(['WEBRTC_TURN_NODE_MUM_LAT' => '95']));
        $this->assertNull($t2->node('mum'), 'An impossible latitude leaves the node out and names it.');
        $this->assertNotEmpty($t2->problems());
    }

    public function testASecondChoiceNodeIsOfferedOnlyItsFallbackTransports(): void
    {
        $grant = $this->bookedIssuer($this->topology($this->activeActiveEnv()))->issue('c', ['preferRegion' => 'in-west']);
        $nodes = $grant->relayNodes();

        $this->assertCount(3, $nodes[0]['urls']);
        $this->assertSame(['turns:turn-ncr.aicountly.com:443?transport=tcp'], $nodes[1]['urls']);
    }

    public function testEdgeGeographyIsOffUnlessHeadersAreNamed(): void
    {
        $off = \Aicountly\Ice\GeoHeaders::fromEnvironment(static fn (string $k): ?string => null);
        $this->assertFalse($off->enabled());
        $this->assertSame([], $off->extract(static fn (string $h): string => '19.07'));
    }

    public function testEdgeGeographyMapsRegionCodesAndRejectsStrangers(): void
    {
        $env = ['WEBRTC_GEO_LAT_HEADER' => 'x-lat', 'WEBRTC_GEO_LON_HEADER' => 'x-lon', 'WEBRTC_GEO_REGION_HEADER' => 'x-region', 'WEBRTC_GEO_REGION_MAP' => 'mh=in-west, DL=in-north,XX=Bad_Region'];
        $geo = \Aicountly\Ice\GeoHeaders::fromEnvironment(static fn (string $k): ?string => $env[$k] ?? null);

        $this->assertTrue($geo->enabled());
        $this->assertSame(['MH' => 'in-west', 'DL' => 'in-north'], $geo->regionMap, 'An invalid region name is dropped, codes are case-insensitive.');
        $this->assertSame(
            ['lat' => '28.6', 'lon' => '77.2', 'region' => 'in-north'],
            $geo->extract(static fn (string $h): string => ['x-lat' => '28.6', 'x-lon' => '77.2', 'x-region' => 'dl'][$h] ?? ''),
        );
        $this->assertSame([], $geo->extract(static fn (string $h): string => ['x-region' => 'ZZ'][$h] ?? ''), 'An unmapped code says nothing.');
    }

    public function testTheRuntimeBuildsEveryStoreUnderTheWritableDirectory(): void
    {
        $dir    = sys_get_temp_dir() . '/ice-runtime-' . bin2hex(random_bytes(4)) . '/';
        $env    = $this->activeActiveEnv(['WEBRTC_TURN_LATENCY_FILE' => '/elsewhere/lat.json']);
        $issuer = \Aicountly\Ice\IceRuntime::issuer(static fn (string $k): ?string => $env[$k] ?? null, $dir, 'remote');
        $grant  = $issuer->issue('u', ['tier' => 'paid', 'sessionRef' => 's']);

        $this->assertTrue($grant->relayAvailable());
        $this->assertFileExists($dir . 'ice/turn-leases.json');
        $stores = \Aicountly\Ice\IceRuntime::stores(static fn (string $k): ?string => $env[$k] ?? null, $dir);
        $this->assertSame('/elsewhere/lat.json', $stores['latency']->path());
        $this->assertSame($dir . 'ice/turn-health.json', $stores['health']->path());

        @unlink($dir . 'ice/turn-leases.json');
        @unlink($dir . 'ice/turn-leases.json.lock');
        @rmdir($dir . 'ice');
        @rmdir($dir);
    }
}
