<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\IceGrant;

require_once __DIR__ . '/IceKitTestCase.php';

/**
 * Which relays a client is given. The two properties that matter most are asserted first: a node that
 * has not been validated never reaches a client, and the shared secret never does either.
 */
final class IceIssuerTest extends IceKitTestCase
{
    public function testADisabledNodeIsNeverServed(): void
    {
        $grant = $this->issuer($this->topology($this->productionEnv()))->issue('participant-1');

        $this->assertSame(['mum'], array_column($grant->relayNodes(), 'id'));
        $this->assertStringNotContainsString('turn-ncr', json_encode($grant->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testADisabledNodeStaysOutEvenWhenEveryOtherNodeIsDown(): void
    {
        $grant = $this->issuer(
            $this->topology($this->productionEnv()),
            $this->healthWith(['mum' => 5, 'ncr' => 0]),
        )->issue('participant-1');

        $this->assertSame(['mum'], array_column($grant->relayNodes(), 'id'), 'Fail-open serves the unhealthy active node, never the unvalidated one.');
        $this->assertTrue($grant->degraded);
    }

    public function testTheSharedSecretNeverReachesTheClient(): void
    {
        $encoded = json_encode(
            $this->issuer($this->topology($this->productionEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => 'active'])))->issue('p')->toArray(),
            JSON_THROW_ON_ERROR,
        );

        $this->assertStringNotContainsString('shared-coturn-secret', $encoded);
    }

    public function testTheGrantIsTheBrowserShapePlusMetadata(): void
    {
        $grant = $this->issuer($this->topology($this->productionEnv()))->issue('participant-1')->toArray();

        $this->assertSame(IceGrant::CONTRACT, $grant['contract']);
        $this->assertSame(['urls' => ['stun:turn-mum.aicountly.com:3478']], $grant['iceServers'][0]);
        $this->assertSame([
            'turn:turn-mum.aicountly.com:3478?transport=udp',
            'turn:turn-mum.aicountly.com:3478?transport=tcp',
            'turns:turn-mum.aicountly.com:443?transport=tcp',
        ], $grant['iceServers'][1]['urls']);
        $this->assertSame(['urls', 'username', 'credential'], array_keys($grant['iceServers'][1]), 'Nothing a browser would not expect inside RTCIceServer.');
        $this->assertSame('all', $grant['iceTransportPolicy']);
        $this->assertTrue($grant['relayAvailable']);
        $this->assertSame(3600, $grant['expiresIn']);
        $this->assertSame(2880, $grant['refreshAfter']);
        $this->assertSame(gmdate('c', self::NOW + 3600), $grant['expiresAt']);
        $this->assertSame('Mumbai', $grant['relayNodes'][0]['label']);
        $this->assertArrayNotHasKey('username', $grant['relayNodes'][0]);
    }

    public function testCredentialsFollowTheCoturnRestScheme(): void
    {
        $turn = $this->issuer($this->topology($this->productionEnv()))->issue('participant-1')->iceServers()[1];

        $this->assertSame((self::NOW + 3600) . ':participant-1', $turn['username']);
        $this->assertSame(base64_encode(hash_hmac('sha1', $turn['username'], 'shared-coturn-secret', true)), $turn['credential']);
    }

    public function testEachNodeIsMintedWithItsOwnSecret(): void
    {
        $grant = $this->issuer($this->topology($this->productionEnv([
            'WEBRTC_TURN_NODE_NCR_STATUS' => 'active',
            'WEBRTC_TURN_NODE_NCR_SECRET' => 'ncr-only-secret',
        ])))->issue('p');

        [, $mum, $ncr] = $grant->iceServers();

        $this->assertSame(base64_encode(hash_hmac('sha1', $mum['username'], 'shared-coturn-secret', true)), $mum['credential']);
        $this->assertSame(base64_encode(hash_hmac('sha1', $ncr['username'], 'ncr-only-secret', true)), $ncr['credential']);
    }

    public function testNodesAreOrderedByPriorityAndThenByRegionHint(): void
    {
        $issuer = $this->issuer($this->topology($this->productionEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => 'active'])));

        $this->assertSame(['mum', 'ncr'], array_column($issuer->issue('p')->relayNodes(), 'id'));
        $this->assertSame(['ncr', 'mum'], array_column($issuer->issue('p', ['preferRegion' => 'IN-NORTH'])->relayNodes(), 'id'));
        $this->assertSame(['mum', 'ncr'], array_column($issuer->issue('p', ['preferRegion' => 'eu-west'])->relayNodes(), 'id'), 'An unknown region is a hint that matches nothing, not an error.');
    }

    public function testTheNumberOfRelayNodesIsCapped(): void
    {
        $grant = $this->issuer($this->topology($this->productionEnv([
            'WEBRTC_TURN_NODE_NCR_STATUS' => 'active',
            'WEBRTC_TURN_MAX_NODES'       => '1',
        ])))->issue('p');

        $this->assertSame(['mum'], array_column($grant->relayNodes(), 'id'));
    }

    public function testStandbyIsServedOnlyWhenNoActiveNodeIsHealthy(): void
    {
        $topology = $this->topology($this->productionEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => 'standby']));

        $normal = $this->issuer($topology, $this->healthWith(['mum' => 0]))->issue('p');
        $this->assertSame(['mum'], array_column($normal->relayNodes(), 'id'));
        $this->assertFalse($normal->failover);

        $failover = $this->issuer($topology, $this->healthWith(['mum' => 3, 'ncr' => 0]))->issue('p');
        $this->assertSame(['ncr'], array_column($failover->relayNodes(), 'id'));
        $this->assertTrue($failover->failover);
        $this->assertFalse($failover->degraded);
    }

    public function testOneFailedProbeIsNotAnOutage(): void
    {
        $grant = $this->issuer(
            $this->topology($this->productionEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => 'standby'])),
            $this->healthWith(['mum' => 1]),
        )->issue('p');

        $this->assertSame(['mum'], array_column($grant->relayNodes(), 'id'));
        $this->assertSame('failing', $grant->relayNodes()[0]['health']);
    }

    public function testStaleHealthIsIgnored(): void
    {
        $grant = $this->issuer(
            $this->topology($this->productionEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => 'standby'])),
            $this->healthWith(['mum' => 9], self::NOW - 3600),
        )->issue('p');

        $this->assertSame(['mum'], array_column($grant->relayNodes(), 'id'), 'A probe job that stopped running must not freeze a node out.');
        $this->assertSame('stale', $grant->relayNodes()[0]['health']);
    }

    public function testAnUnhealthyNodeIsSkippedWhenAHealthyOneExists(): void
    {
        $grant = $this->issuer(
            $this->topology($this->productionEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => 'active'])),
            $this->healthWith(['mum' => 2, 'ncr' => 0]),
        )->issue('p');

        $this->assertSame(['ncr'], array_column($grant->relayNodes(), 'id'));
        $this->assertFalse($grant->degraded);
    }

    public function testNoRelayIsReportedHonestlyAndNeverForcesRelayOnlyIce(): void
    {
        $grant = $this->issuer($this->topology([
            'WEBRTC_FORCE_RELAY' => 'true',
        ], ['stunUrls' => ['stun:stun.l.google.com:19302']]))->issue('p')->toArray();

        $this->assertFalse($grant['relayAvailable']);
        $this->assertSame('all', $grant['iceTransportPolicy'], 'Relay-only ICE with no relay can never connect.');
        $this->assertSame([['urls' => ['stun:stun.l.google.com:19302']]], $grant['iceServers']);
        $this->assertNull($grant['expiresAt']);
    }

    public function testForceRelayIsHonouredWhenARelayExists(): void
    {
        $grant = $this->issuer($this->topology($this->productionEnv(['WEBRTC_FORCE_RELAY' => '1'])))->issue('p');

        $this->assertSame('relay', $grant->toArray()['iceTransportPolicy']);
    }

    public function testAnActiveNodeWithoutASecretIsNotServedAndIsReported(): void
    {
        $topology = $this->topology($this->productionEnv(['WEBRTC_TURN_STATIC_AUTH_SECRET' => '']));
        $issuer   = $this->issuer($topology);

        $this->assertFalse($issuer->relayConfigured());
        $this->assertSame([], $issuer->issue('p')->relayNodes());
        $this->assertStringContainsString("'mum' is active but has no secret", implode(' ', $topology->problems()));
    }

    public function testStatusDescribesEveryNodeWithoutCredentials(): void
    {
        $status = $this->issuer(
            $this->topology($this->productionEnv()),
            $this->healthWith(['mum' => 0]),
        )->status();

        $this->assertSame('nodes', $status['source']);
        $this->assertSame(['mum', 'ncr'], array_column($status['nodes'], 'id'));
        $this->assertSame([true, false], array_column($status['nodes'], 'servable'));
        $this->assertSame(['up', 'unknown'], array_column($status['nodes'], 'health'));
        $this->assertStringNotContainsString('shared-coturn-secret', json_encode($status, JSON_THROW_ON_ERROR));
    }

    public function testTheSubjectCannotBreakTheUsernameFormat(): void
    {
        $turn = $this->issuer($this->topology($this->productionEnv()))->issue('evil:user@example.com')->iceServers()[1];

        $this->assertSame(1, substr_count($turn['username'], ':'));
        $this->assertSame('eviluserexample.com', explode(':', $turn['username'], 2)[1]);
    }
}
