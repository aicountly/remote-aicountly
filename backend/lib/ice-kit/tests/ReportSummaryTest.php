<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\ReportSummary;

require_once __DIR__ . '/IceKitTestCase.php';

final class ReportSummaryTest extends IceKitTestCase
{
    private function line(string $product, array $report): string
    {
        return "INFO - 2026-10-21 09:00:00 --> {$product}.ice.report " . json_encode($report + ['session' => 's', 'participant' => 'p']);
    }

    public function testItAggregatesRelayUseNodesTransportsFailuresAndTimings(): void
    {
        $log = implode("\n", [
            'DEBUG - something unrelated',
            $this->line('remote', ['outcome' => 'connected', 'path' => 'relay', 'relayNode' => 'mum', 'relayProtocol' => 'udp', 'timeToConnectMs' => 900, 'restarts' => 0, 'credentialRefreshes' => 1]),
            $this->line('remote', ['outcome' => 'connected', 'path' => 'relay', 'relayNode' => 'mum', 'relayProtocol' => 'tls', 'timeToConnectMs' => 2100]),
            $this->line('connect', ['outcome' => 'connected', 'path' => 'direct', 'timeToConnectMs' => 300]),
            $this->line('connect', ['outcome' => 'recovered', 'path' => 'relay', 'relayNode' => 'ncr', 'relayProtocol' => 'udp', 'restarts' => 2]),
            $this->line('remote', ['outcome' => 'failed', 'path' => 'unknown', 'iceErrors' => [['category' => 'turn_unreachable', 'node' => 'mum', 'code' => 701]]]),
            'a line mentioning remote.ice.report but with broken json {nope',
        ]);

        $summary = new ReportSummary();
        $this->assertSame(5, $summary->addLog($log));
        $s = $summary->summary();

        $this->assertSame(5, $s['reports']);
        $this->assertSame(['connect' => 2, 'remote' => 3], $s['products']);
        $this->assertSame(['connected' => 3, 'failed' => 1, 'recovered' => 1], $s['outcomes']);
        $this->assertSame(['direct' => 1, 'relay' => 3, 'unknown' => 1], $s['paths']);
        $this->assertSame(0.6, $s['relayedShare']);
        $this->assertSame(['mum/udp' => 1, 'mum/tls' => 1, 'ncr/udp' => 1], $s['relayByNodeProtocol']);
        $this->assertSame(['turn_unreachable@mum' => 1], $s['iceErrors']);
        $this->assertSame(['n' => 2, 'p50' => 900, 'p95' => 2100], $s['timeToConnectMs']['relay']);
        $this->assertSame(['n' => 1, 'p50' => 300, 'p95' => 300], $s['timeToConnectMs']['direct']);
        $this->assertSame(2, $s['iceRestarts']);
        $this->assertSame(1, $s['credentialRenewals']);
        $this->assertSame(1, $s['failed']);
    }

    public function testAnEmptyLogSummarisesToNothingRatherThanDividingByZero(): void
    {
        $s = (new ReportSummary())->summary();

        $this->assertSame(0, $s['reports']);
        $this->assertNull($s['relayedShare']);
        $this->assertSame(['n' => 0, 'p50' => null, 'p95' => null], $s['timeToConnectMs']['relay']);
    }

    public function testPercentilesAreNearestRankAndNeverInvented(): void
    {
        $this->assertSame(['n' => 4, 'p50' => 20, 'p95' => 40], ReportSummary::percentiles([40, 10, 30, 20]));
    }
}
