<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\ClientContext;
use Aicountly\Ice\IceIssuer;
use Aicountly\Ice\LoadStore;
use Aicountly\Ice\MemoryLeaseStore;
use Aicountly\Ice\Tier;

require_once __DIR__ . '/IceKitTestCase.php';

/**
 * Regional peers: both nodes serve, each client gets the best of them, and the paid reserve holds on
 * every node and across fallback. Everything here is arithmetic over the lease books and the health file
 * — no relay is involved, and none of it says anything about the real Mumbai or Noida nodes.
 */
final class ActiveActiveTest extends IceKitTestCase
{
    /** @return list<string> */
    private function ids(\Aicountly\Ice\IceGrant $grant): array
    {
        return array_map(static fn (array $n): string => $n['id'], $grant->relayNodes());
    }

    /** Issue $n grants for fresh clients of a tier; returns how many were admitted a relay. */
    private function fill(IceIssuer $issuer, Tier $tier, int $n, string $region = 'in-west', string $prefix = 'c'): int
    {
        $admitted = 0;
        for ($i = 0; $i < $n; $i++) {
            $grant = $issuer->issue($prefix . $tier->value . $i, [
                'tier' => $tier, 'sessionRef' => 's-' . $prefix . $tier->value . $i, 'client' => ClientContext::fromRequest(['region' => $region]),
            ]);
            $admitted += $grant->relayAvailable() ? 1 : 0;
        }

        return $admitted;
    }

    /** One node serving, one lease per client, so the counts are exact. */
    private function mumbaiOnly(array $extra = []): array
    {
        return $this->activeActiveEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => 'disabled', 'WEBRTC_TURN_MAX_NODES' => '1'] + $extra);
    }

    // ----------------------------------------------------------------- geographic routing

    public function testEachRegionIsServedByItsNearestPeerWithTheOtherAsFallback(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->activeActiveEnv()));

        $west  = $issuer->issue('a', ['tier' => Tier::Paid, 'preferRegion' => 'in-west']);
        $north = $issuer->issue('b', ['tier' => Tier::Paid, 'preferRegion' => 'in-north']);

        $this->assertSame(['mum', 'ncr'], $this->ids($west));
        $this->assertSame(['ncr', 'mum'], $this->ids($north));
        $this->assertFalse($west->failover, 'Peers are not a primary and a standby: nothing here is a failover.');
    }

    public function testCoarseEdgeGeographyRoutesWithoutAnyHint(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->activeActiveEnv()));

        $pune  = $issuer->issue('a', ['client' => ClientContext::fromRequest([], ['lat' => '18.52', 'lon' => '73.86'])]);
        $delhi = $issuer->issue('b', ['client' => ClientContext::fromRequest([], ['lat' => '28.61', 'lon' => '77.21'])]);

        $this->assertSame('mum', $this->ids($pune)[0]);
        $this->assertSame('ncr', $this->ids($delhi)[0]);
        $this->assertSame('geo', $delhi->relayNodes()[0]['proximity']['basis']);
    }

    public function testAClientsOwnMeasurementBeatsGeography(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->activeActiveEnv()));

        // Geographically Delhi — but its network reaches Mumbai in 18 ms and Noida in 95.
        $grant = $issuer->issue('a', ['client' => ClientContext::fromRequest(['rtt' => ['mum' => 18, 'ncr' => 95]], ['lat' => '28.61', 'lon' => '77.21'])]);

        $this->assertSame('mum', $this->ids($grant)[0]);
        $this->assertSame('measured', $grant->relayNodes()[0]['proximity']['basis']);
    }

    public function testNonsenseHintsAndMeasurementsAreIgnored(): void
    {
        $client = ClientContext::fromRequest(
            ['region' => "in-west'; DROP", 'rtt' => ['mum' => -5, 'ncr' => 'fast', '../x' => 10, 'zzz' => 99999]],
            ['lat' => '999', 'lon' => 'abc'],
        );

        $this->assertSame('', $client->region);
        $this->assertSame([], $client->rtt);
        $this->assertNull($client->lat);
    }

    public function testNearestIsKeptUntilItIsBusyAndThenTheServiceIsShared(): void
    {
        $topology = $this->topology($this->activeActiveEnv());
        $issuer   = $this->bookedIssuer($topology);

        // 30 clients in Mumbai: Mumbai is comfortable, so the next Mumbai client still starts there.
        $this->fill($issuer, Tier::Paid, 30);
        $this->assertSame('mum', $this->ids($issuer->issue('probe', ['tier' => Tier::Paid, 'preferRegion' => 'in-west']))[0]);

        // Push Mumbai to ~85%: it is now worth 19 ms of extra distance to use Noida.
        $this->fill($issuer, Tier::Paid, 55, 'in-west', 'x');
        $next = $issuer->issue('late', ['tier' => Tier::Paid, 'preferRegion' => 'in-west']);
        $this->assertSame('ncr', $this->ids($next)[0], 'A loaded Mumbai hands new Mumbai-region clients to Noida first.');
        $this->assertContains('mum', $this->ids($next), 'Mumbai stays in the list: it is a fallback, not excluded.');
    }

    // ----------------------------------------------------------------- health, reciprocal

    public function testEitherNodeFailingLeavesTheOtherServingEveryone(): void
    {
        foreach ([['mum', 'ncr'], ['ncr', 'mum']] as [$dead, $alive]) {
            $issuer = $this->bookedIssuer($this->topology($this->activeActiveEnv()), $this->healthWith([$dead => 2, $alive => 0]));

            foreach (['in-west', 'in-north'] as $region) {
                $grant = $issuer->issue('c', ['tier' => Tier::Free, 'preferRegion' => $region]);
                $this->assertSame([$alive], $this->ids($grant), "{$dead} down, {$region} client");
                $this->assertFalse($grant->degraded);
            }
        }
    }

    public function testOneFailedProbeMakesTheOtherPeerLead(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->activeActiveEnv()), $this->healthWith(['mum' => 1, 'ncr' => 0]));

        $this->assertSame(['ncr', 'mum'], $this->ids($issuer->issue('c', ['tier' => Tier::Paid, 'preferRegion' => 'in-west'])));
    }

    public function testWhenBothAreDownBothAreOfferedAndFlagged(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->activeActiveEnv()), $this->healthWith(['mum' => 3, 'ncr' => 3]));
        $grant  = $issuer->issue('c', ['tier' => Tier::Paid]);

        $this->assertTrue($grant->degraded);
        $this->assertEqualsCanonicalizing(['mum', 'ncr'], $this->ids($grant));
    }

    public function testAFullNodeIsNotADeadOneAndIsRankedBehindAWorkingPeer(): void
    {
        $path = $this->tempPath();
        file_put_contents($path, json_encode(['version' => 1, 'nodes' => [
            'mum' => ['ok' => false, 'checkedAt' => self::NOW, 'consecutiveFailures' => 2, 'error' => 'insufficient_capacity'],
            'ncr' => ['ok' => true, 'checkedAt' => self::NOW, 'consecutiveFailures' => 0],
        ]]));
        $health = new \Aicountly\Ice\HealthStore($path, 900, 2);

        $this->assertSame('full', $health->state('mum', self::NOW));
        $this->assertSame('overloaded', \Aicountly\Ice\HealthStore::condition('full'));
        $this->assertSame(['ncr', 'mum'], $this->ids($this->bookedIssuer($this->topology($this->activeActiveEnv()), $health)->issue('c', ['tier' => Tier::Paid, 'preferRegion' => 'in-west'])));
    }

    // ----------------------------------------------------------------- the paid reserve

    public function testFreeUsersFillTheSharedHalfOfANodeAndNoMore(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->mumbaiOnly()));

        $this->assertSame(50, $this->fill($issuer, Tier::Free, 80), 'Half of 100 units.');

        $denied = $issuer->issue('one-more', ['tier' => Tier::Free, 'sessionRef' => 'x']);
        $this->assertFalse($denied->relayAvailable());
        $this->assertSame('denied', $denied->admission['outcome']);
        $this->assertSame('free_pool_full', $denied->admission['reason']);
        $this->assertSame([], array_filter($denied->iceServers(), static fn (array $s): bool => isset($s['credential'])), 'No credential for a denied client.');
    }

    public function testPaidCustomersStillGetTheirWholeReserveAfterFreeIsFull(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->mumbaiOnly()));
        $this->fill($issuer, Tier::Free, 80);

        $this->assertSame(50, $this->fill($issuer, Tier::Paid, 80), 'The reserve was never touched.');

        $full = $issuer->issue('over', ['tier' => Tier::Paid, 'sessionRef' => 'y']);
        $this->assertSame('node_full', $full->admission['reason']);
    }

    public function testPaidUsingTheSharedHalfSqueezesFreeAndNothingAlreadyAdmittedIsCut(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->mumbaiOnly()));

        $this->assertSame(70, $this->fill($issuer, Tier::Paid, 70));
        $freeRoom = $this->fill($issuer, Tier::Free, 80);

        $this->assertSame(15, $freeRoom, '85% shed threshold minus the 70 paid units.');
    }

    public function testFallbackCannotBeUsedToBypassTheReserve(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->activeActiveEnv(['WEBRTC_TURN_MAX_NODES' => '1'])));

        // Free users fill BOTH nodes' shared halves (50 + 50), starting from Mumbai.
        $this->assertSame(100, $this->fill($issuer, Tier::Free, 150, 'in-west'));

        // Mumbai's free pool is full, Noida's too: a Mumbai-region free client gets nothing...
        $denied = $issuer->issue('late-free', ['tier' => Tier::Free, 'preferRegion' => 'in-west', 'sessionRef' => 'z']);
        $this->assertFalse($denied->relayAvailable());

        // ...while a paid client in the same region is served from the reserve, on either node.
        $paid = $issuer->issue('late-paid', ['tier' => Tier::Paid, 'preferRegion' => 'in-west', 'sessionRef' => 'w']);
        $this->assertTrue($paid->relayAvailable());
    }

    public function testAFullNearNodeSendsFreeUsersToTheOtherRegionInsteadOfRefusingThem(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->activeActiveEnv(['WEBRTC_TURN_MAX_NODES' => '1'])));

        $this->assertSame(50, $this->fill($issuer, Tier::Free, 50, 'in-west'));
        $next = $issuer->issue('spill', ['tier' => Tier::Free, 'preferRegion' => 'in-west', 'sessionRef' => 'spill']);

        $this->assertSame(['ncr'], $this->ids($next), 'Reciprocal fallback: Mumbai has no free room, Noida does.');
        $this->assertSame('admitted', $next->admission['outcome']);
    }

    public function testRenewalDoesNotCountAgainstItself(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->mumbaiOnly()));
        $this->fill($issuer, Tier::Free, 50);

        $renewed = $issuer->issue('cfree0', ['tier' => Tier::Free, 'sessionRef' => 's-cfree0']);
        $this->assertTrue($renewed->relayAvailable(), 'A client already inside the pool keeps its place when it renews.');

        $this->assertFalse($issuer->issue('stranger', ['tier' => Tier::Free, 'sessionRef' => 'new'])->relayAvailable());
    }

    public function testOverloadClosesTheDoorToNewFreeSessionsButNeverEvictsTheOnesInside(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->mumbaiOnly()));

        $this->assertSame(40, $this->fill($issuer, Tier::Free, 40));
        $this->assertSame(46, $this->fill($issuer, Tier::Paid, 46), 'Paid takes 46 of its 50, and 40 + 46 = 86% is past the 85% shed line.');

        $this->assertFalse($issuer->issue('newcomer', ['tier' => Tier::Free, 'sessionRef' => 'new'])->relayAvailable(), 'A new free session is refused: the node is overloaded.');
        $this->assertSame('node_overloaded', $issuer->issue('newcomer', ['tier' => Tier::Free, 'sessionRef' => 'new'])->admission['reason']);

        $renewed = $issuer->issue('cfree3', ['tier' => Tier::Free, 'sessionRef' => 's-cfree3']);
        $this->assertTrue($renewed->relayAvailable(), 'But a free session already inside keeps its place when it renews.');
        $this->assertTrue($issuer->issue('cpaid3', ['tier' => Tier::Paid, 'sessionRef' => 's-cpaid3'])->relayAvailable());
    }

    public function testReleasingASessionReturnsItsCapacity(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->mumbaiOnly()));
        $this->fill($issuer, Tier::Free, 50);
        $this->assertFalse($issuer->issue('n1', ['tier' => Tier::Free, 'sessionRef' => 'n1'])->relayAvailable());

        $this->assertSame(1, $issuer->release('s-cfree7', 'cfree7'));
        $this->assertTrue($issuer->issue('n1', ['tier' => Tier::Free, 'sessionRef' => 'n1'])->relayAvailable());
        $this->assertSame(0, $issuer->release('s-cfree7', 'cfree7'), 'Releasing twice is harmless.');
    }

    public function testADirectConnectionFreesBandwidthButNotTheAllocationSlots(): void
    {
        $env    = $this->mumbaiOnly(['WEBRTC_TURN_NODE_MUM_MAX_ALLOCATIONS' => '1000', 'WEBRTC_TURN_NODE_MUM_MAX_MBPS' => '200']);
        $issuer = $this->bookedIssuer($this->topology($env));

        // Bandwidth is the binding limit here: 50 free clients at 2 Mbit/s fill the free half.
        $this->assertSame(50, $this->fill($issuer, Tier::Free, 60));

        foreach (range(0, 24) as $i) {
            $issuer->noteConnection('cfree' . $i, 's-cfree' . $i, 'direct', null);
        }

        // A direct connection cannot be verified, so it is still charged a residual (20 % by default):
        // 25 × (2 − 0.4) Mbit/s = 40 Mbit/s of the free half comes back, room for twenty more clients.
        $this->assertSame(20, $this->fill($issuer, Tier::Free, 60, 'in-west', 'later'));
    }

    public function testBandwidthFollowsTheNodeTheClientReportsRelayingThrough(): void
    {
        $store  = new MemoryLeaseStore();
        $issuer = $this->bookedIssuer($this->topology($this->activeActiveEnv()), null, $store);
        $issuer->issue('u', ['tier' => Tier::Paid, 'sessionRef' => 'sess', 'preferRegion' => 'in-west']);

        $bps = static fn (): array => array_map(
            static fn ($lease): int => $lease->bps,
            array_column(array_map(static fn ($l) => ['node' => $l->nodeId, 'l' => $l], array_values($store->snapshot(self::NOW)->all())), 'l', 'node'),
        );

        $this->assertSame(['mum' => 2_000_000, 'ncr' => 0], $bps(), 'Expected on the first node until the client says otherwise.');

        $issuer->noteConnection('u', 'sess', 'relay', 'ncr');
        $this->assertSame(['mum' => 0, 'ncr' => 2_000_000], $bps());

        $issuer->issue('u', ['tier' => Tier::Paid, 'sessionRef' => 'sess', 'preferRegion' => 'in-west']);
        $this->assertSame(['mum' => 0, 'ncr' => 2_000_000], $bps(), 'A renewal keeps what the client reported.');
    }

    public function testObserveModeRecordsButDeniesNothing(): void
    {
        $audit  = [];
        $issuer = $this->bookedIssuer($this->topology($this->mumbaiOnly(['WEBRTC_TURN_ADMISSION' => 'observe'])), null, null, null, $audit);

        $this->assertSame(80, $this->fill($issuer, Tier::Free, 80), 'Nobody is refused while observing.');

        $last = end($audit);
        $this->assertSame('issue', $last[0]);
        $this->assertTrue($last[1]['wouldDeny'], 'But the audit trail says enforce mode would have refused them.');
    }

    public function testOffModeKeepsNoBooksAtAll(): void
    {
        $store  = new MemoryLeaseStore();
        $issuer = $this->bookedIssuer($this->topology($this->mumbaiOnly(['WEBRTC_TURN_ADMISSION' => 'off'])), null, $store);
        $this->fill($issuer, Tier::Free, 5);

        $this->assertSame([], $store->snapshot(self::NOW)->all());
    }

    public function testMeasuredTrafficTheBooksMissTightensAdmission(): void
    {
        $load = new LoadStore($this->tempPath(), 180);
        $load->ingest(['node' => 'mum', 'at' => self::NOW, 'allocations' => 280, 'rxBps' => 90_000_000, 'txBps' => 90_000_000], self::NOW);

        $issuer = $this->bookedIssuer($this->topology($this->mumbaiOnly()), null, null, $load);

        $this->assertFalse($issuer->issue('free', ['tier' => Tier::Free, 'sessionRef' => 'a'])->relayAvailable(), 'The relay is carrying 180 of 200 Mbit/s the books never heard of.');
        $this->assertTrue($issuer->issue('paid', ['tier' => Tier::Paid, 'sessionRef' => 'b'])->relayAvailable());
    }

    public function testStaleTelemetryIsIgnored(): void
    {
        $load = new LoadStore($this->tempPath(), 180);
        $load->ingest(['node' => 'mum', 'at' => self::NOW - 250, 'allocations' => 300, 'rxBps' => 100_000_000, 'txBps' => 100_000_000], self::NOW - 250);

        $this->assertTrue($this->bookedIssuer($this->topology($this->mumbaiOnly()), null, null, $load)->issue('free', ['tier' => Tier::Free, 'sessionRef' => 'a'])->relayAvailable());
    }

    public function testATierRestrictedNodeIsNeverGivenToTheOtherTier(): void
    {
        $env    = $this->activeActiveEnv(['WEBRTC_TURN_NODE_MUM_TIERS' => 'paid', 'WEBRTC_TURN_NODE_MUM_PAID_RESERVE_PERCENT' => '0']);
        $issuer = $this->bookedIssuer($this->topology($env));

        $this->assertSame(['ncr'], $this->ids($issuer->issue('f', ['tier' => Tier::Free, 'preferRegion' => 'in-west'])));
        $this->assertSame(['mum', 'ncr'], $this->ids($issuer->issue('p', ['tier' => Tier::Paid, 'preferRegion' => 'in-west'])));
    }

    public function testTheReserveCannotBeConfiguredBelowFiftyUnlessTheNodeIsSplitByTier(): void
    {
        $reserve = fn (array $env, string $id): int => $this->topology($this->activeActiveEnv($env))->node($id)->capacity->effectiveReservePercent;

        $this->assertSame(50, $reserve(['WEBRTC_TURN_NODE_MUM_PAID_RESERVE_PERCENT' => '10'], 'mum'));
        $this->assertSame(50, $reserve(['WEBRTC_TURN_PAID_RESERVE_PERCENT' => '20'], 'mum'));
        $this->assertSame(50, $reserve(['WEBRTC_TURN_NODE_MUM_TIERS' => 'paid,free', 'WEBRTC_TURN_NODE_MUM_PAID_RESERVE_PERCENT' => '0'], 'mum'), 'Naming both tiers does not lower the floor.');
        $this->assertSame(70, $reserve(['WEBRTC_TURN_PAID_RESERVE_PERCENT' => '70'], 'ncr'));
        $this->assertSame(0, $reserve(['WEBRTC_TURN_NODE_MUM_TIERS' => 'paid'], 'mum'), 'A single-tier node has no reserve to speak of.');
    }

    // ----------------------------------------------------------------- stickiness, failure of the books, the username

    public function testARenewingClientStaysOnItsNodeWhenTheAlternativeIsOnlyMarginallyBetter(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->activeActiveEnv()));

        // A client in between, slightly closer to Noida by measurement, that was started on Mumbai.
        $client = ClientContext::fromRequest(['rtt' => ['mum' => 40, 'ncr' => 32]]);
        $first  = $issuer->issue('u', ['tier' => Tier::Paid, 'sessionRef' => 's', 'client' => ClientContext::fromRequest(['rtt' => ['mum' => 30, 'ncr' => 60]])]);
        $this->assertSame('mum', $this->ids($first)[0]);

        $renewed = $issuer->issue('u', ['tier' => Tier::Paid, 'sessionRef' => 's', 'client' => $client]);
        $this->assertSame('mum', $this->ids($renewed)[0], 'No ICE restart onto another node for an 8 ms difference.');

        $fresh = $issuer->issue('v', ['tier' => Tier::Paid, 'sessionRef' => 't', 'client' => $client]);
        $this->assertSame('ncr', $this->ids($fresh)[0], 'A new client with the same measurements takes the closer node.');
    }

    public function testUnavailableBooksFailOpenAndSayWhy(): void
    {
        $broken = new class implements \Aicountly\Ice\LeaseStore {
            public function transact(\Closure $fn, int $now): mixed
            {
                throw new \RuntimeException('disk full');
            }

            public function snapshot(int $now): \Aicountly\Ice\LeaseBook
            {
                throw new \RuntimeException('disk full');
            }
        };
        $audit  = [];
        $issuer = $this->bookedIssuer($this->topology($this->mumbaiOnly()), null, $broken, null, $audit);

        $this->assertTrue($issuer->issue('c', ['tier' => Tier::Free, 'sessionRef' => 'a'])->relayAvailable());
        $this->assertSame('ledger_error', $audit[0][0]);
        $this->assertSame([], $issuer->status()['nodes'][0]['sessions'] === ['paid' => 0, 'free' => 0] ? [] : ['unexpected'], 'status() survives broken books too.');
    }

    public function testTheUsernameCarriesTheTierAndIsStillSigned(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->activeActiveEnv()));

        $paid = $issuer->issue('abc', ['tier' => Tier::Paid])->iceServers();
        $free = $issuer->issue('abc', ['tier' => Tier::Free])->iceServers();
        $user = static fn (array $servers): string => (string) array_values(array_filter($servers, static fn (array $s): bool => isset($s['username'])))[0]['username'];

        $this->assertSame(Tier::Paid, Tier::fromUsername($user($paid)));
        $this->assertSame(Tier::Free, Tier::fromUsername($user($free)));
        $this->assertSame(Tier::Free, Tier::parse('anything-else'), 'Unknown means free.');
        $this->assertSame(Tier::Free, Tier::parse(null));
        $this->assertNull(Tier::fromUsername('1800000000:legacy'));
    }

    public function testStatusKeepsAllocationsSessionsBandwidthAndTrafficApart(): void
    {
        $load = new LoadStore($this->tempPath(), 180);
        $load->ingest(['node' => 'mum', 'at' => self::NOW - 10, 'allocations' => 21, 'rxBps' => 3_000_000, 'txBps' => 2_000_000], self::NOW);
        $issuer = $this->bookedIssuer($this->topology($this->mumbaiOnly()), null, null, $load);

        $this->fill($issuer, Tier::Paid, 4);
        $this->fill($issuer, Tier::Free, 3);

        $mum = $issuer->status()['nodes'][0];

        $this->assertSame(['paid' => 4, 'free' => 3], $mum['sessions']);
        $this->assertSame(['paid' => 12, 'free' => 9], $mum['allocations']['reserved']);
        $this->assertSame(21, $mum['allocations']['measured']);
        $this->assertSame(['paid' => 8_000_000, 'free' => 6_000_000], $mum['bandwidth']['reservedBps']);
        $this->assertSame(5_000_000, $mum['bandwidth']['measuredBps']);
        $this->assertSame(10, $mum['telemetryAgeSeconds']);
        $this->assertArrayNotHasKey('secret', $mum);
    }
}
