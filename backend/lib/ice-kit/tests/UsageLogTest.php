<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\UsageLog;

require_once __DIR__ . '/IceKitTestCase.php';

/** Lines copied from a real coturn 4.6.1 log; the real nodes' version must be checked against them (runbook F). */
final class UsageLogTest extends IceKitTestCase
{
    private const LOG = [
        '2208: : session 002000000000000003: usage: realm=<turn.aicountly.test>, username=<1792587666:p.9f2c>, rp=3, rb=26000, sp=3, sb=30800',
        '2208: : session 002000000000000003: peer usage: realm=<turn.aicountly.test>, username=<1792587666:p.9f2c>, rp=0, rb=0, sp=0, sb=0',
        '2208: : session 002000000000000004: usage: realm=<turn.aicountly.test>, username=<1792587700:f.aa11>, rp=10, rb=1000, sp=10, sb=3000',
        '2208: : session 002000000000000005: usage: realm=<turn.aicountly.test>, username=<1792587701:f.bb22>, rp=1, rb=100, sp=1, sb=100',
        '2208: : session 002000000000000006: usage: realm=<turn.aicountly.test>, username=<1792587666:probe>, rp=3, rb=260, sp=3, sb=308',
        '2208: : session 002000000000000007: usage: realm=<turn.aicountly.test>, username=<>, rp=1, rb=48, sp=1, sb=88',
        '2208: : session 002000000000000008: closed (2nd stage), user <1792587701:f.bb22> realm <turn.aicountly.test> origin <>, reason: x',
        'not a coturn line at all',
    ];

    public function testBytesAreAttributedToTheTierInTheSignedUsername(): void
    {
        $summary = UsageLog::summarize(self::LOG);

        $this->assertSame(['allocations' => 1, 'bytesIn' => 26000, 'bytesOut' => 30800, 'bytes' => 56800], $summary['tiers']['paid']);
        $this->assertSame(['allocations' => 2, 'bytesIn' => 1100, 'bytesOut' => 3100, 'bytes' => 4200], $summary['tiers']['free']);
        $this->assertSame(['allocations' => 1, 'bytesIn' => 260, 'bytesOut' => 308, 'bytes' => 568], $summary['tiers']['untiered'], 'A probe credential has no tier.');
        $this->assertSame(1, $summary['ignored'], 'An allocation without any credential is counted apart.');
    }

    public function testPeerLegsAndOtherLinesAreNotCounted(): void
    {
        $this->assertNull(UsageLog::parse(self::LOG[1]));
        $this->assertNull(UsageLog::parse(self::LOG[6]));
        $this->assertSame(26000, UsageLog::parse(self::LOG[0])['rb']);
    }

    public function testTheCliPrintsNoUsernames(): void
    {
        $file = $this->tempPath();
        file_put_contents($file, implode("\n", self::LOG));

        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/summarize-usage.php') . ' ' . escapeshellarg($file) . ' --window=100');

        $this->assertStringContainsString('paid', $out);
        $this->assertStringNotContainsString('9f2c', $out);
        $this->assertStringNotContainsString('realm', $out);
        $this->assertMatchesRegularExpression('/paid\s+1\s+26000\s+30800\s+92\.3%\s+4544/', $out);
    }
}
