<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\ClientContext;
use Aicountly\Ice\Tier;

require_once __DIR__ . '/IceKitTestCase.php';

/**
 * Hard separation by tier: each physical host runs a paid-only coturn instance and a shared one. The reserve is then
 * not an accounting rule inside one node but the existence of the paid-only instance — and Topology refuses to serve
 * the shared instance unless the paid-only partner really is at least half of the host.
 */
final class TierSplitTest extends IceKitTestCase
{
    /** Host capacity 300 allocations / 200 Mbit/s: 150 / 100 paid-only, 150 / 100 shared. */
    private function split(array $extra = []): array
    {
        $env = $this->activeActiveEnv([
            'WEBRTC_TURN_NODES' => 'mum-paid,mum-shared,ncr-paid,ncr-shared',
            'WEBRTC_TURN_MAX_NODES' => '1',
        ]);
        foreach (['MUM' => ['mum', 'turn-mum', 'in-west', '19.0760', '72.8777', 'mumbai-secret-0123456789'], 'NCR' => ['ncr', 'turn-ncr', 'in-north', '28.4744', '77.5040', 'noida-secret-0123456789']] as $K => [$g, $host, $region, $lat, $lon, $secret]) {
            foreach (['PAID' => ['paid', 'turn-' . $g . '.aicountly.com', null], 'SHARED' => ['paid,free', 'turn-' . $g . '-shared.aicountly.com', 'true']] as $kind => [$tiers, $hostname, $structural]) {
                $p = "WEBRTC_TURN_NODE_{$K}_{$kind}_";
                $env += [
                    $p . 'HOST' => $hostname, $p . 'STATUS' => 'active', $p . 'REGION' => $region, $p . 'LAT' => $lat, $p . 'LON' => $lon, $p . 'SECRET' => $secret . $kind,
                    $p . 'TIERS' => $tiers, $p . 'GROUP' => $g, $p . 'MAX_ALLOCATIONS' => '150', $p . 'MAX_MBPS' => '100', $p . 'TLS_PORT' => '443',
                ];
                if ($structural !== null) {
                    $env[$p . 'STRUCTURAL_RESERVE'] = $structural;
                }
            }
        }

        return $extra + $env;
    }

    /** @return list<string> */
    private function ids(\Aicountly\Ice\IceGrant $g): array
    {
        return array_map(static fn (array $n): string => $n['id'], $g->relayNodes());
    }

    public function testFreeClientsAreOnlyEverGivenTheSharedInstance(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->split()));
        $free   = $issuer->issue('f', ['tier' => Tier::Free, 'client' => ClientContext::fromRequest(['region' => 'in-west'])]);

        $this->assertSame(['mum-shared'], $this->ids($free), 'The paid-only instance is not a node a free client can ever be offered.');
        $both = $this->bookedIssuer($this->topology($this->split(['WEBRTC_TURN_MAX_NODES' => '4'])))->issue('f', ['tier' => Tier::Free, 'client' => ClientContext::fromRequest(['region' => 'in-west'])]);
        $this->assertSame(['mum-shared', 'ncr-shared'], $this->ids($both));
    }

    public function testPaidClientsPreferTheirOwnInstanceAndOverflowIntoTheSharedOne(): void
    {
        $issuer = $this->bookedIssuer($this->topology($this->split()));
        $paid   = $issuer->issue('p', ['tier' => Tier::Paid, 'client' => ClientContext::fromRequest(['region' => 'in-west'])]);
        $this->assertSame('mum-paid', $this->ids($paid)[0]);

        // Fill Mumbai's paid-only instance (150 allocations = 50 leases of 3 URLs): paid then spills.
        for ($i = 0; $i < 60; $i++) {
            $issuer->issue("pp{$i}", ['tier' => Tier::Paid, 'sessionRef' => "p{$i}", 'client' => ClientContext::fromRequest(['region' => 'in-west'])]);
        }
        $next = $issuer->issue('late', ['tier' => Tier::Paid, 'sessionRef' => 'late', 'client' => ClientContext::fromRequest(['region' => 'in-west'])]);
        $this->assertNotSame('mum-paid', $this->ids($next)[0], 'A full paid-only instance sends paid clients to the next best node.');
    }

    public function testTheSharedInstanceHasNoReserveOfItsOwn(): void
    {
        $t = $this->topology($this->split());

        $this->assertSame(0, $t->node('mum-shared')->capacity->effectiveReservePercent);
        $this->assertSame([], $t->problems());
        // Free users may use the whole shared instance up to the shed line (85 % of 150 allocations / 3 = 42 leases).
        $issuer = $this->bookedIssuer($t);
        $ok = 0;
        for ($i = 0; $i < 120; $i++) {
            $ok += $issuer->issue("f{$i}", ['tier' => Tier::Free, 'sessionRef' => "f{$i}", 'client' => ClientContext::fromRequest(['region' => 'in-west'])])->relayAvailable() ? 1 : 0;
        }
        $this->assertSame(84, $ok, 'Two shared instances, 42 free clients each — and not one of them on a paid-only instance.');
        $status = $issuer->status()['nodes'];
        foreach ($status as $n) {
            if (str_ends_with($n['id'], '-paid')) {
                $this->assertSame(0, $n['sessions']['free'] + $n['sessions']['paid']);
            }
        }
    }

    public function testASharedInstanceWithoutEnoughPaidOnlyPartnerIsLeftOut(): void
    {
        // Paid-only is 60 of 210 on Mumbai: under the 50 % reserve.
        $t = $this->topology($this->split(['WEBRTC_TURN_NODE_MUM_PAID_MAX_ALLOCATIONS' => '60']));

        $this->assertNull($t->node('mum-shared'), 'Without a big enough reserve instance the shared one would hand free users the paid half.');
        $this->assertNotNull($t->node('mum-paid'));
        $this->assertNotNull($t->node('ncr-shared'), 'Noida\'s group is fine and is unaffected.');
        $this->assertMatchesRegularExpression("/group 'mum'.*under the 50% reserve/", implode(' ', $t->problems()));
    }

    public function testEveryMeteredDimensionMustSatisfyTheReserve(): void
    {
        $t = $this->topology($this->split(['WEBRTC_TURN_NODE_NCR_PAID_MAX_MBPS' => '40']));

        $this->assertNull($t->node('ncr-shared'), 'Allocations are fine but bandwidth is not.');
    }

    public function testStructuralReserveNeedsAGroup(): void
    {
        $t = $this->topology($this->split(['WEBRTC_TURN_NODE_MUM_SHARED_GROUP' => '']));

        $this->assertNull($t->node('mum-shared'));
        $this->assertMatchesRegularExpression('/STRUCTURAL_RESERVE but has no GROUP/', implode(' ', $t->problems()));
    }

    public function testNamingBothTiersWithoutDeclaringAStructuralReserveStillKeepsTheFloor(): void
    {
        $env = $this->split();
        unset($env['WEBRTC_TURN_NODE_MUM_SHARED_STRUCTURAL_RESERVE']);

        $this->assertSame(50, $this->topology($env)->node('mum-shared')->capacity->effectiveReservePercent);
    }
}
