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

        $this->assertSame((self::NOW + 3600) . ':f.participant-1', $turn['username']);
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

        // Not removed — one dropped probe is not an outage — but no longer served alone: the standby
        // (never probed, so no information against it) joins until the primary proves itself again.
        $this->assertSame(['ncr', 'mum'], array_column($grant->relayNodes(), 'id'), 'The node with no failures leads.');
        $this->assertSame('failing', $grant->relayNodes()[1]['health']);
        $this->assertTrue($grant->failover);
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
        $this->assertSame('f.eviluserexample.com', explode(':', $turn['username'], 2)[1]);
    }

    // ------------------------------------------------------------------ conditions and failover

    private function nodes(\Aicountly\Ice\IceGrant $grant): array
    {
        return array_column($grant->relayNodes(), 'id');
    }

    private function mumActiveNcrStandby(array $extra = []): \Aicountly\Ice\Topology
    {
        return $this->topology($this->productionEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => 'standby'] + $extra));
    }

    public function testAHealthyPrimaryAloneIsServedAndTheStandbyStaysOut(): void
    {
        $grant = $this->issuer($this->mumActiveNcrStandby(), $this->healthWith(
            ['mum' => 0, 'ncr' => 0],
            self::NOW,
            ['mum' => self::onlyWorking('udp', 'tcp', 'tls'), 'ncr' => self::onlyWorking('udp', 'tcp', 'tls')],
        ))->issue('p');

        $this->assertSame(['mum'], $this->nodes($grant));
        $this->assertFalse($grant->failover);
        $this->assertFalse($grant->degraded);
    }

    /** TLS on 443 is what restrictive networks depend on: a primary that has lost it is not "healthy". */
    public function testAPrimaryThatHasLostATransportIsDegradedAndTheStandbyJoinsIt(): void
    {
        $issuer = $this->issuer($this->mumActiveNcrStandby(), $this->healthWith(
            ['mum' => 0, 'ncr' => 0],
            self::NOW,
            ['mum' => self::onlyWorking('udp', 'tcp'), 'ncr' => self::onlyWorking('udp', 'tcp', 'tls')],
        ));
        $grant = $issuer->issue('p');

        $this->assertSame(['ncr', 'mum'], $this->nodes($grant), 'The fully working node leads; the degraded one still serves UDP and TCP.');
        $this->assertTrue($grant->failover);
        $this->assertFalse($grant->degraded);

        $byId = array_column($issuer->status()['nodes'], null, 'id');
        $this->assertSame('degraded', $byId['mum']['condition']);
        $this->assertSame('healthy', $byId['ncr']['condition']);
    }

    public function testAPrimaryWithOneFailedProbeBringsTheStandbyInBeforeItIsDeclaredDown(): void
    {
        // Two 5-minute probes to declare a node down is up to ten minutes of dead relay for new
        // sessions; one failure is enough to start offering the standby beside it.
        $grant = $this->issuer($this->mumActiveNcrStandby(), $this->healthWith(['mum' => 1, 'ncr' => 0]))->issue('p');

        $this->assertSame(['ncr', 'mum'], $this->nodes($grant));
        $this->assertTrue($grant->failover);
    }

    public function testAPrimaryDeclaredDownIsReplacedByTheStandby(): void
    {
        $issuer = $this->issuer($this->mumActiveNcrStandby(), $this->healthWith(['mum' => 3, 'ncr' => 0]));
        $grant  = $issuer->issue('p');

        $this->assertSame(['ncr'], $this->nodes($grant), 'A known-down relay is not handed out beside a working one.');
        $this->assertTrue($grant->failover);
        $this->assertFalse($grant->degraded);

        $byId = array_column($issuer->status()['nodes'], null, 'id');
        $this->assertSame('unreachable', $byId['mum']['state']);
        $this->assertSame('standby', $byId['ncr']['state']);
        $this->assertSame([false, true], [$byId['mum']['serving'], $byId['ncr']['serving']]);
    }

    public function testWhenEveryRelayIsKnownDownBothAreOfferedNotJustThePrimary(): void
    {
        $grant = $this->issuer($this->mumActiveNcrStandby(), $this->healthWith(['mum' => 4, 'ncr' => 4]))->issue('p');

        $this->assertSame(['mum', 'ncr'], $this->nodes($grant), 'Either may have recovered since the last probe.');
        $this->assertTrue($grant->degraded);
    }

    public function testTheCapNeverCutsTheWorkingStandbyInFavourOfTheDegradedPrimary(): void
    {
        $grant = $this->issuer(
            $this->mumActiveNcrStandby(['WEBRTC_TURN_MAX_NODES' => '1']),
            $this->healthWith(['mum' => 1, 'ncr' => 0]),
        )->issue('p');

        $this->assertSame(['ncr'], $this->nodes($grant));
    }

    public function testRecoveryReturnsServiceToThePrimaryAlone(): void
    {
        $topology = $this->mumActiveNcrStandby();

        $down      = $this->issuer($topology, $this->healthWith(['mum' => 3, 'ncr' => 0]))->issue('p');
        $recovered = $this->issuer($topology, $this->healthWith(['mum' => 0, 'ncr' => 0]))->issue('p');

        $this->assertSame(['ncr'], $this->nodes($down));
        $this->assertSame(['mum'], $this->nodes($recovered));
        $this->assertFalse($recovered->failover);
    }

    public function testADisabledNodeIsNeverAFailoverTargetWhateverHappensToThePrimary(): void
    {
        $grant = $this->issuer($this->topology($this->productionEnv()), $this->healthWith(['mum' => 9, 'ncr' => 0]))->issue('p');

        $this->assertSame(['mum'], $this->nodes($grant));
        $this->assertTrue($grant->degraded);
    }

    public function testConditionsAreReportedForEveryNodeWithoutCredentials(): void
    {
        $status = $this->issuer($this->mumActiveNcrStandby(), $this->healthWith(
            ['mum' => 3],
            self::NOW,
        ))->status();
        $byId = array_column($status['nodes'], null, 'id');

        $this->assertSame(['unreachable', 'unknown'], [$byId['mum']['condition'], $byId['ncr']['condition']]);
        $this->assertSame(['active', 'standby'], [$byId['mum']['role'], $byId['ncr']['role']]);
        $this->assertStringNotContainsString('shared-coturn-secret', json_encode($status, JSON_THROW_ON_ERROR));
    }

    // ------------------------------------------------------------------ session-bound credentials

    public function testASessionBoundCredentialCoversTheRestOfTheSessionAndNoMore(): void
    {
        $topology = $this->topology($this->productionEnv());

        $this->assertSame(3600, $topology->sessionBoundTtl(600), 'A short session still gets the ordinary lifetime.');
        $this->assertSame(7500 + 300, $topology->sessionBoundTtl(7500), 'Two hours left: two hours and five minutes.');
        $this->assertSame(28800, $topology->sessionBoundTtl(86400), 'Capped at WEBRTC_TURN_SESSION_TTL_MAX.');
        $this->assertSame(3600, $topology->sessionBoundTtl(-50));

        $raised = $this->topology($this->productionEnv(['WEBRTC_TURN_SESSION_TTL_MAX' => '86400']));
        $this->assertSame(86400, $raised->sessionBoundTtl(86400));
    }

    public function testATtlOptionLengthensACredentialWithoutTouchingTheOrdinaryOne(): void
    {
        $issuer = $this->issuer($this->topology($this->productionEnv()));

        $ordinary = $issuer->issue('p');
        $bound    = $issuer->issue('p', ['ttl' => 7800]);

        $this->assertSame(3600, $ordinary->expiresIn());
        $this->assertSame(7800, $bound->expiresIn());
        $this->assertSame((self::NOW + 7800) . ':f.p', $bound->iceServers()[1]['username']);
        $this->assertSame(86400, $issuer->issue('p', ['ttl' => 10 ** 7])->expiresIn(), 'Never beyond the absolute ceiling.');
    }

    public function testRenewalRestartsAreOnByDefaultAndSwitchableOff(): void
    {
        $this->assertTrue($this->issuer($this->topology($this->productionEnv()))->issue('p')->toArray()['restartOnRenewal']);
        $this->assertFalse(
            $this->issuer($this->topology($this->productionEnv(['WEBRTC_TURN_RESTART_ON_RENEWAL' => 'false'])))->issue('p')->toArray()['restartOnRenewal'],
        );
    }
}
