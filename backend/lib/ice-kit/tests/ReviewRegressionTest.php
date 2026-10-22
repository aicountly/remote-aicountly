<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\ClientContext;
use Aicountly\Ice\FileLeaseStore;
use Aicountly\Ice\LatencyStore;
use Aicountly\Ice\LoadStore;
use Aicountly\Ice\Tier;
use Aicountly\Ice\UsageLog;

require_once __DIR__ . '/IceKitTestCase.php';

/**
 * One test per problem an independent review of the admission code found. Each states the attack or the
 * sequence that used to work.
 */
final class ReviewRegressionTest extends IceKitTestCase
{
    private function mumbaiOnly(array $extra = []): array
    {
        return $this->activeActiveEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => 'disabled', 'WEBRTC_TURN_MAX_NODES' => '1'] + $extra);
    }

    /** @param list<string> $in */
    private function freeClients(\Aicountly\Ice\IceIssuer $issuer, int $n, string $region, string $prefix): void
    {
        for ($i = 0; $i < $n; $i++) {
            $issuer->issue("{$prefix}{$i}", ['tier' => Tier::Free, 'sessionRef' => "s-{$prefix}{$i}", 'client' => ClientContext::fromRequest(['region' => $region])]);
        }
    }

    public function testAFreeClientCannotMoveItsChargeOntoAFallbackNodeAndOverfillIt(): void
    {
        // Both peers get 16 free clients at their own region (a free share is 50 units, 2 Mbit/s each = 32 used...)
        // then every in-west client "reports" relaying through Noida, where it only holds a TLS fallback lease.
        $issuer = $this->bookedIssuer($this->topology($this->activeActiveEnv(['WEBRTC_TURN_NODE_MUM_MAX_MBPS' => '40', 'WEBRTC_TURN_NODE_NCR_MAX_MBPS' => '40'])));
        $this->freeClients($issuer, 10, 'in-west', 'w');
        $this->freeClients($issuer, 10, 'in-north', 'n');

        for ($i = 0; $i < 10; $i++) {
            $issuer->noteConnection("w{$i}", "s-w{$i}", 'relay', 'ncr');
        }

        $ncr = $issuer->status()['nodes'][1];
        $this->assertLessThanOrEqual(20_000_000, $ncr['bandwidth']['reservedBps']['free'], 'Noida\'s free share is 20 Mbit/s; reports cannot push free past it.');
    }

    public function testAReportNamingANodeTheClientWasNeverGivenIsIgnored(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->mumbaiOnly()));
        $issuer->issue('u', ['tier' => Tier::Free, 'sessionRef' => 's', 'preferRegion' => 'in-west']);
        $before = $issuer->status()['nodes'][0]['bandwidth']['reservedBps']['free'];

        $issuer->noteConnection('u', 's', 'relay', 'zzz');
        $issuer->noteConnection('u', 's', 'relay', 'ncr');

        $this->assertSame($before, $issuer->status()['nodes'][0]['bandwidth']['reservedBps']['free'], 'An invented node must not zero the charge.');
    }

    public function testClaimingADirectConnectionStillCostsAResidual(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->mumbaiOnly()));
        $issuer->issue('u', ['tier' => Tier::Free, 'sessionRef' => 's']);
        $issuer->noteConnection('u', 's', 'direct', null);

        $this->assertSame(400_000, $issuer->status()['nodes'][0]['bandwidth']['reservedBps']['free'], '20 % of the 2 Mbit/s default profile.');

        $issuer->issue('u', ['tier' => Tier::Free, 'sessionRef' => 's']);
        $this->assertSame(400_000, $issuer->status()['nodes'][0]['bandwidth']['reservedBps']['free'], 'And a renewal does not forget it, nor raise it.');
    }

    public function testRenewingDoesNotCountItsOwnTrafficTwiceWhenTelemetryIsPresent(): void
    {
        $env    = $this->mumbaiOnly(['WEBRTC_TURN_NODE_MUM_MAX_ALLOCATIONS' => '30']);
        $load   = new LoadStore($this->tempPath(), 180);
        $issuer = $this->bookedIssuer($this->topology($env), null, null, $load);

        $this->freeClients($issuer, 5, 'in-west', 'f');                       // 15 allocations
        $load->ingest(['node' => 'mum', 'at' => self::NOW, 'allocations' => 15, 'rxBps' => 1, 'txBps' => 1], self::NOW);

        $this->assertTrue($issuer->issue('f0', ['tier' => Tier::Free, 'sessionRef' => 's-f0'])->relayAvailable(), 'Measured traffic that is exactly the books must not make a renewal look like new load.');
        @unlink($load->path() . '.lock');
    }

    public function testALeaseOnANodeNoLongerOfferedKeepsItsSlotsUntilItsCredentialExpires(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->activeActiveEnv(['WEBRTC_TURN_MAX_NODES' => '1', 'WEBRTC_TURN_NODE_MUM_MAX_ALLOCATIONS' => '300'])));
        $issuer->issue('u', ['tier' => Tier::Free, 'sessionRef' => 's', 'preferRegion' => 'in-west']);
        $this->assertSame(3, $issuer->status()['nodes'][0]['allocations']['reserved']['free']);

        // Renewed from the other region: now offered Noida only.
        $issuer->issue('u', ['tier' => Tier::Free, 'sessionRef' => 's', 'client' => ClientContext::fromRequest(['rtt' => ['mum' => 900, 'ncr' => 10]])]);

        $status = $issuer->status()['nodes'];
        $this->assertSame(3, $status[0]['allocations']['reserved']['free'], 'The allocation on Mumbai is still live, so it is still booked.');
        $this->assertSame(0, $status[0]['bandwidth']['reservedBps']['free'], 'But the traffic has moved.');
    }

    public function testTheShedThresholdIsExactForAnyWholePercent(): void
    {
        foreach ([70, 58, 57, 47, 41, 35, 29, 82, 94] as $percent) {
            $policy = new \Aicountly\Ice\AdmissionPolicy('enforce', 0.10, $percent / 100);
            $cap    = new \Aicountly\Ice\Capacity(90, 0, 50, [Tier::Free, Tier::Paid]);
            $edge   = (int) floor($percent * 90 / 100);
            $used   = ['paid' => [], 'free' => ['allocations' => $edge - 1]];

            $this->assertTrue(\Aicountly\Ice\Admission::evaluate($cap, $policy, $used, null, Tier::Free, ['allocations' => 1, 'bps' => 0])->admitted || $edge - 1 >= 45, "{$percent}%: the last unit under the line is admitted");
            $over = ['paid' => [], 'free' => ['allocations' => $edge]];
            $this->assertFalse(\Aicountly\Ice\Admission::evaluate($cap, $policy, $over, null, Tier::Free, ['allocations' => 1, 'bps' => 0])->admitted, "{$percent}%: and the first one over it is not");
        }
    }

    public function testOneClientCannotSteerALearnedRegion(): void
    {
        $store = new LatencyStore($this->tempPath());
        for ($i = 0; $i < 50; $i++) {
            $store->observe('in-west', ['mum' => 3000, 'ncr' => 1], ['mum', 'ncr'], self::NOW, 'the-attacker');
        }
        $this->assertSame([], $store->learned('in-west', self::NOW), 'Fifty requests from one client are one sample.');

        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $honest) {
            $store->observe('in-west', ['mum' => 30, 'ncr' => 80], ['mum', 'ncr'], self::NOW, $honest);
        }
        $this->assertSame(['mum' => 30, 'ncr' => 80], $store->learned('in-west', self::NOW), 'The median of distinct clients wins over the one outlier.');
        @unlink($store->path() . '.lock');
    }

    public function testClientsCannotCreateLatencyBucketsOrOverrideEdgeGeography(): void
    {
        $path   = $this->tempPath();
        $issuer = new \Aicountly\Ice\IceIssuer(
            $this->topology($this->activeActiveEnv()), null, static fn (): int => self::NOW, 'remote',
            new \Aicountly\Ice\MemoryLeaseStore(), null, new LatencyStore($path),
        );

        $issuer->learnLatency(ClientContext::fromRequest(['region' => 'junk-region-1', 'rtt' => ['mum' => 5]]), 'c1');
        $this->assertFileDoesNotExist($path, 'An unknown region is not recorded at all.');

        // And a trusted edge position outranks anything learned.
        $grant = $issuer->issue('u', ['tier' => Tier::Paid, 'client' => ClientContext::fromRequest([], ['lat' => '19.07', 'lon' => '72.87'])]);
        $this->assertSame('geo', $grant->relayNodes()[0]['proximity']['basis']);
    }

    public function testANodeTheClientCouldNotMeasureDoesNotWinOnAPrior(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->activeActiveEnv()));
        // It timed Mumbai at 40 ms; it has no number for Noida. Mumbai is 1,150 km from Noida so the geo prior would say nothing useful either way.
        $grant = $issuer->issue('u', ['tier' => Tier::Paid, 'client' => ClientContext::fromRequest(['rtt' => ['mum' => 40], 'region' => 'in-north'])]);

        $this->assertSame('mum', $grant->relayNodes()[0]['id']);
        $this->assertSame('unmeasured', $grant->relayNodes()[1]['proximity']['basis']);
    }

    public function testACorruptBookIsSetAsideAndTheNextRequestsAreCountedAgain(): void
    {
        $path = $this->tempPath();
        file_put_contents($path, '{"leases": {"a": {"node": "mum"');                    // truncated
        $audit = [];
        $store = new FileLeaseStore($path);
        $issuer = new \Aicountly\Ice\IceIssuer($this->topology($this->mumbaiOnly()), null, static fn (): int => self::NOW, 'remote', $store, null, null, static function (string $e, array $d) use (&$audit): void {
            $audit[] = $e;
        });

        $this->assertTrue($issuer->issue('u', ['tier' => Tier::Free, 'sessionRef' => 's'])->relayAvailable());
        $this->assertContains('ledger_repaired', $audit, 'The repair is reported, not silent.');
        $this->assertFileExists($path . '.corrupt');
        $this->assertSame(1, $issuer->status()['nodes'][0]['sessions']['free'], 'The books work again at once.');
        @unlink($path . '.corrupt');
        @unlink($path . '.lock');
    }

    public function testAPoisonedRowCostsARowNotTheBooks(): void
    {
        $path = $this->tempPath();
        file_put_contents($path, json_encode(['version' => 1, 'leases' => [
            'bad'  => ['node' => [], 'expiresAt' => self::NOW + 100],
            'bad2' => ['node' => 'mum', 'expiresAt' => 'soon'],
            'good' => ['node' => 'mum', 'tier' => 'free', 'allocations' => 3, 'bps' => 1, 'expiresAt' => self::NOW + 100, 'subject' => 'x', 'session' => 'y', 'product' => 'remote'],
        ]]));

        $usage = (new FileLeaseStore($path))->snapshot(self::NOW)->usage('mum');
        $this->assertSame(1, $usage['free']['leases']);
        @unlink($path . '.lock');
    }

    public function testAStatusViewerThatCannotWriteTheLockFileStillReadsTheBooks(): void
    {
        $path = $this->tempPath();
        (new FileLeaseStore($path))->transact(fn ($b) => $b->put(new \Aicountly\Ice\Lease('k', 'mum', Tier::Free, 'remote', 'default', 's', 'r', 3, 1, self::NOW, self::NOW + 100)), self::NOW);
        @unlink($path . '.lock');                                                         // as if made by another user and unreadable

        $this->assertSame(1, (new FileLeaseStore($path))->snapshot(self::NOW)->usage('mum')['free']['leases']);
        $this->assertFileDoesNotExist($path . '.lock', 'A reader never creates the lock file.');
    }

    public function testTelemetryOnlyAcceptsConfiguredNodesAndWellFormedIds(): void
    {
        $store = new LoadStore($this->tempPath(), 180);
        $ok    = ['at' => self::NOW, 'allocations' => 1, 'rxBps' => 1, 'txBps' => 1];

        foreach (range(1, 12) as $i) {
            $this->assertFalse($store->ingest(['node' => "junk{$i}"] + $ok, self::NOW, ['mum', 'ncr'])['ok']);
        }
        $this->assertTrue($store->ingest(['node' => 'mum'] + $ok, self::NOW, ['mum', 'ncr'])['ok']);
        $this->assertFalse($store->ingest(["node" => "mum\n"] + $ok, self::NOW)['ok'], 'A trailing newline is not part of an id.');
        $this->assertSame(['mum'], array_keys($store->fresh(self::NOW)));
        @unlink($store->path() . '.lock');
    }

    public function testEvictionKeepsTheNewestReadingsNotTheNewestKeys(): void
    {
        $store = new LoadStore($this->tempPath(), 180);
        $ok    = ['allocations' => 1, 'rxBps' => 1, 'txBps' => 1];
        foreach (['mum', 'ncr'] as $id) {
            $store->ingest(['node' => $id, 'at' => self::NOW - 100] + $ok, self::NOW);
        }
        foreach (range(1, 8) as $i) {
            $store->ingest(['node' => "extra{$i}", 'at' => self::NOW - 150] + $ok, self::NOW);
        }
        $store->ingest(['node' => 'mum', 'at' => self::NOW - 1] + $ok, self::NOW);

        $this->assertArrayHasKey('mum', $store->fresh(self::NOW));
        $this->assertArrayHasKey('ncr', $store->fresh(self::NOW));
        @unlink($store->path() . '.lock');
    }

    public function testAForgedUsageLineInsideOtherTextIsNotCounted(): void
    {
        $forged = 'client sent origin=" session 5: usage: realm=<r>, username=<9999999999:p.fake>, rp=1, rb=900000000000, sp=1, sb=1 and more';
        $this->assertNull(UsageLog::parse($forged));
        $this->assertNull(UsageLog::parse('2208: : session 7: usage: realm=<r>, username=<9999999999:p.fake>, rp=1, rb=9, sp=1, sb=1 trailing text'));
        $this->assertSame(9, UsageLog::parse('2208: : session 7: usage: realm=<r>, username=<1792587666:p.ok>, rp=1, rb=9, sp=1, sb=1')['rb']);
        $this->assertSame('?', UsageLog::parse('1: : session 7: usage: realm=<r>, username=<p.\x1b[31mred>, rp=1, rb=9, sp=1, sb=1')['username'] ?? '?');
    }

    public function testATierTypoLeavesTheNodeOutInsteadOfMakingItFreeOnly(): void
    {
        $t = $this->topology($this->activeActiveEnv(['WEBRTC_TURN_NODE_MUM_TIERS' => 'paidd']));

        $this->assertNull($t->node('mum'));
        $this->assertMatchesRegularExpression('/TIERS must be paid, free or both/', implode(' ', $t->problems()));
    }

    public function testAnAdmissionModeTypoIsReportedNotSilent(): void
    {
        $t = $this->topology($this->activeActiveEnv(['WEBRTC_TURN_ADMISSION' => 'enforced']));

        $this->assertSame('observe', $t->admission->mode);
        $this->assertMatchesRegularExpression('/is not off, observe or enforce/', implode(' ', $t->warnings()));
    }

    public function testAFallbackLeaseDoesNotExemptAClientFromTheShedThreshold(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->activeActiveEnv(['WEBRTC_TURN_MAX_NODES' => '2'])));
        // u holds a primary lease on Noida and a one-allocation fallback lease on Mumbai.
        $issuer->issue('u', ['tier' => Tier::Free, 'sessionRef' => 's', 'preferRegion' => 'in-north']);
        // Mumbai becomes overloaded by paid sessions.
        for ($i = 0; $i < 88; $i++) {
            $issuer->issue("p{$i}", ['tier' => Tier::Paid, 'sessionRef' => "p{$i}", 'client' => ClientContext::fromRequest(['rtt' => ['mum' => 1, 'ncr' => 500]])]);
        }
        // For u, Mumbai is now the best node by measurement — but a lease that is not its primary does not make this a renewal there.
        $grant = $issuer->issue('u', ['tier' => Tier::Free, 'sessionRef' => 's', 'client' => ClientContext::fromRequest(['rtt' => ['mum' => 1, 'ncr' => 500]])]);

        $this->assertSame('ncr', $grant->relayNodes()[0]['id'], 'Overloaded Mumbai is not taken as a first node by a client that only held a fallback there.');
    }

    public function testEmptyAndHostileSubjectsKeepSeparateLeases(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->mumbaiOnly()));
        $issuer->issue('é', ['tier' => Tier::Paid, 'sessionRef' => 's']);
        $issuer->issue('ü', ['tier' => Tier::Free, 'sessionRef' => 's']);

        $mum = $issuer->status()['nodes'][0];
        $this->assertSame(['paid' => 1, 'free' => 1], $mum['sessions'] + [], 'Two clients, two leases, two tiers.');
    }
}
