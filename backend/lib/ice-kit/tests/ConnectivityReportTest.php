<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\ConnectivityReport;

require_once __DIR__ . '/IceKitTestCase.php';

/** A client report is untrusted input headed for a log. Only enumerations and counts may survive. */
final class ConnectivityReportTest extends IceKitTestCase
{
    public function testAWellFormedReportPassesThrough(): void
    {
        $clean = ConnectivityReport::sanitize([
            'outcome'             => 'recovered',
            'path'                => 'relay',
            'localCandidateType'  => 'relay',
            'remoteCandidateType' => 'srflx',
            'relayProtocol'       => 'tls',
            'relayNode'           => 'mum',
            'admission'           => 'admitted',
            'gathered'            => ['host' => 3, 'srflx' => 1, 'relay' => 2],
            'iceErrors'           => [['category' => 'turn_unreachable', 'node' => 'mum', 'code' => 701]],
            'restarts'            => 1,
            'credentialRefreshes' => 2,
            'timeToConnectMs'     => 850,
            'durationMs'          => 120000,
        ]);

        $this->assertSame('recovered', $clean['outcome']);
        $this->assertSame('mum', $clean['relayNode']);
        $this->assertSame('admitted', $clean['admission']);
        $this->assertSame(['host' => 3, 'srflx' => 1, 'prflx' => 0, 'relay' => 2], $clean['gathered']);
        $this->assertSame([['category' => 'turn_unreachable', 'node' => 'mum', 'code' => 701]], $clean['iceErrors']);
        $this->assertSame(850, $clean['timeToConnectMs']);
    }

    public function testNothingOutsideTheAllowListSurvives(): void
    {
        $clean = ConnectivityReport::sanitize([
            'outcome'            => 'connected; DROP TABLE',
            'path'               => '<script>',
            'localCandidateType' => 'candidate:1 1 udp 2122260223 192.168.1.20 54400 typ host',
            'relayNode'          => '203.0.113.9',
            'relayProtocol'      => 'quic',
            'admission'          => 'platinum',
            'sdp'                => "v=0\r\no=- 1 2 IN IP4 192.168.1.20",
            'credential'         => 'abc',
            'gathered'           => ['host' => -5, 'relay' => 10 ** 9, 'bogus' => 3],
            'iceErrors'          => array_merge(
                [['category' => 'turn_auth_failed', 'node' => '10.0.0.1', 'code' => 401, 'url' => 'turn:10.0.0.1']],
                [['category' => 'made_up']],
                array_fill(0, 20, ['category' => 'ice_failed']),
            ),
            'restarts'           => 'lots',
        ]);
        $encoded = json_encode($clean, JSON_THROW_ON_ERROR);

        $this->assertNull($clean['outcome']);
        $this->assertNull($clean['admission']);
        $this->assertSame('unknown', $clean['path']);
        $this->assertNull($clean['localCandidateType']);
        $this->assertNull($clean['relayNode']);
        $this->assertNull($clean['relayProtocol']);
        $this->assertSame(['host' => 0, 'srflx' => 0, 'prflx' => 0, 'relay' => 999], $clean['gathered']);
        $this->assertSame(['category' => 'turn_auth_failed', 'node' => null, 'code' => 401], $clean['iceErrors'][0]);
        $this->assertCount(9, $clean['iceErrors'], 'At most ten raw errors are read; the unknown category is dropped.');
        $this->assertSame(0, $clean['restarts']);

        foreach (['192.168', '203.0.113', '10.0.0.1', 'v=0', 'abc', 'script', 'DROP'] as $leak) {
            $this->assertStringNotContainsString($leak, $encoded);
        }
    }
}
