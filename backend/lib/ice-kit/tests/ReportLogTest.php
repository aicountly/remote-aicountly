<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\ConnectivityReport;
use Aicountly\Ice\ReportLog;
use Aicountly\Ice\ReportSummary;

/**
 * Reports are kept in their own daily file whatever the framework's log level, in the format the summariser reads.
 *
 * @internal
 */
final class ReportLogTest extends IceKitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ice-report-log-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testAReportIsWrittenToTheDaysFileAndTheSummariserReadsIt(): void
    {
        $now    = gmmktime(16, 20, 1, 10, 24, 2026);
        $report = ConnectivityReport::sanitize([
            'outcome' => 'recovered', 'path' => 'relay', 'relayNode' => 'mum', 'relayProtocol' => 'udp', 'restarts' => 2,
            'timeToConnectMs' => 374, 'sdp' => 'v=0 must not survive', 'candidate' => '203.0.113.9',
        ]);

        $this->assertTrue(ReportLog::append($this->dir, 'remote', ['session' => 's-1', 'participant' => 'p-1'] + $report, $now));
        $this->assertTrue(ReportLog::append($this->dir, 'connect', ['call' => 'c-1'] + $report, $now + 5));

        $file = $this->dir . '/ice-reports-2026-10-24.log';
        $this->assertFileExists($file);
        $text = (string) file_get_contents($file);
        $this->assertStringStartsWith('2026-10-24T16:20:01Z remote.ice.report {', $text);
        $this->assertStringNotContainsString('203.0.113.9', $text);
        $this->assertStringNotContainsString('v=0', $text);

        $summary = new ReportSummary();
        $this->assertSame(2, $summary->addLog($text));
        $s = $summary->summary();
        $this->assertSame(['connect' => 1, 'remote' => 1], $s['products']);
        $this->assertSame(['mum/udp' => 2], $s['relayByNodeProtocol']);
    }

    public function testFilesRollOverByUtcDay(): void
    {
        ReportLog::append($this->dir, 'remote', ['outcome' => 'connected'], gmmktime(23, 59, 59, 10, 24, 2026));
        ReportLog::append($this->dir, 'remote', ['outcome' => 'connected'], gmmktime(0, 0, 1, 10, 25, 2026));

        $this->assertFileExists($this->dir . '/ice-reports-2026-10-24.log');
        $this->assertFileExists($this->dir . '/ice-reports-2026-10-25.log');
    }

    public function testPruneRemovesOnlyExpiredReportFiles(): void
    {
        mkdir($this->dir, 0750, true);
        $now = gmmktime(12, 0, 0, 10, 24, 2026);
        foreach (['2026-09-01', '2026-09-23', '2026-09-24', '2026-10-24'] as $day) {
            touch($this->dir . "/ice-reports-{$day}.log");
        }
        touch($this->dir . '/log-2026-09-01.log');

        $this->assertSame(2, ReportLog::prune($this->dir, $now));
        $this->assertFileDoesNotExist($this->dir . '/ice-reports-2026-09-01.log');
        $this->assertFileDoesNotExist($this->dir . '/ice-reports-2026-09-23.log');
        $this->assertFileExists($this->dir . '/ice-reports-2026-09-24.log');
        $this->assertFileExists($this->dir . '/ice-reports-2026-10-24.log');
        $this->assertFileExists($this->dir . '/log-2026-09-01.log', 'the framework log is not ours to prune');
    }

    public function testABadProductNameOrUnwritableDirectoryIsRefusedQuietly(): void
    {
        $this->assertFalse(ReportLog::append($this->dir, '../etc', ['outcome' => 'connected']));
        $this->assertFalse(ReportLog::append('/proc/no-such-place', 'remote', ['outcome' => 'connected']));
    }
}
