<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\CredentialMinter;
use Aicountly\Ice\HealthStore;
use Aicountly\Ice\NodeStatus;
use Aicountly\Ice\TurnNode;

require_once __DIR__ . '/IceKitTestCase.php';

/** Reading the environment, minting, and the health file — each failure mode biased towards serving. */
final class TopologyAndHealthTest extends IceKitTestCase
{
    public function testNodesAreReadFromTheSharedKeys(): void
    {
        $topology = $this->topology($this->productionEnv());
        $mum      = $topology->node('mum');

        $this->assertSame('nodes', $topology->source);
        $this->assertNotNull($mum);
        $this->assertSame(NodeStatus::Active, $mum->status);
        $this->assertSame(10, $mum->priority);
        $this->assertSame('in-west', $mum->region);
        $this->assertSame(443, $mum->tlsPort);
        $this->assertSame(['udp', 'tcp', 'tls'], $mum->transports);
        $this->assertSame(NodeStatus::Disabled, $topology->node('ncr')?->status);
        $this->assertSame([], $topology->problems());
    }

    public function testANodeWithNoStatusIsDisabled(): void
    {
        $topology = $this->topology($this->productionEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => null]));

        $this->assertSame(NodeStatus::Disabled, $topology->node('ncr')?->status);
        $this->assertSame(NodeStatus::Disabled, NodeStatus::parse('enabled-ish'));
    }

    public function testTransportsCanBeNarrowed(): void
    {
        $node = $this->topology($this->productionEnv(['WEBRTC_TURN_NODE_MUM_TRANSPORTS' => 'tls']))->node('mum');

        $this->assertSame(['turns:turn-mum.aicountly.com:443?transport=tcp'], $node?->turnUrls());
        $this->assertNull($node?->stunUrl(), 'No UDP, so no STUN on that node.');
    }

    public function testAMisconfiguredNodeIsReportedAndDoesNotTakeTheOthersDown(): void
    {
        $topology = $this->topology($this->productionEnv([
            'WEBRTC_TURN_NODES'          => 'mum, broken, ncr',
            'WEBRTC_TURN_NODE_BROKEN_HOST' => 'not a host!',
        ]));

        $this->assertSame(['mum', 'ncr'], array_map(static fn (TurnNode $n) => $n->id, $topology->nodes()));
        $this->assertStringContainsString("'broken' has no usable host", implode(' ', $topology->problems()));
    }

    public function testLegacySinglePoolRestConfigurationStillWorks(): void
    {
        $topology = $this->topology([
            'WEBRTC_TURN_URLS'               => 'turn:turn.example.com:3478?transport=udp, turns:turn.example.com:5349?transport=tcp',
            'WEBRTC_TURN_STATIC_AUTH_SECRET' => 'legacy-secret',
            'WEBRTC_TURN_TTL'                => '600',
        ]);
        $grant = $this->issuer($topology)->issue('user-42');
        $turn  = $grant->iceServers()[0];

        $this->assertSame('legacy', $topology->source);
        $this->assertSame(['turn:turn.example.com:3478?transport=udp', 'turns:turn.example.com:5349?transport=tcp'], $turn['urls']);
        $this->assertSame((self::NOW + 600) . ':user-42', $turn['username']);
        $this->assertSame(base64_encode(hash_hmac('sha1', $turn['username'], 'legacy-secret', true)), $turn['credential']);
        $this->assertSame(600, $grant->expiresIn());
    }

    public function testLegacyStaticPairIsPassedThroughAndNeverClaimsToExpire(): void
    {
        $grant = $this->issuer($this->topology([
            'WEBRTC_TURN_URLS'       => 'turn:relay.provider.example:3478',
            'WEBRTC_TURN_USERNAME'   => 'connect',
            'WEBRTC_TURN_CREDENTIAL' => 'sekrit',
        ]))->issue('p');

        $this->assertSame('connect', $grant->iceServers()[0]['username']);
        $this->assertSame('static', $grant->relayNodes()[0]['credential']);
        $this->assertNull($grant->expiresIn());
        $this->assertNull($grant->refreshAfter());
    }

    public function testTtlIsClamped(): void
    {
        $this->assertSame(60, CredentialMinter::clampTtl(5));
        $this->assertSame(86400, CredentialMinter::clampTtl(10 ** 7));
        $this->assertSame(60, $this->topology(['WEBRTC_TURN_TTL' => '1'])->ttlSeconds);
    }

    public function testMintingWithoutASecretIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CredentialMinter::mint('', 'p', 600, self::NOW);
    }

    public function testAnEmptySubjectFallsBackToTheProductName(): void
    {
        $this->assertSame('connect', CredentialMinter::subject('!!!', 'connect'));
        $this->assertSame(64, strlen(CredentialMinter::subject(str_repeat('a', 200))));
    }

    public function testDebugOutputNeverShowsTheSecret(): void
    {
        $node = $this->topology($this->productionEnv())->node('mum');

        $this->assertStringNotContainsString('shared-coturn-secret', print_r($node, true));
        $this->assertStringNotContainsString('shared-coturn-secret', var_export($node?->describe(), true));
    }

    public function testIpv6HostsAreBracketedInUrls(): void
    {
        $node = new TurnNode('v6', '2001:db8::1', NodeStatus::Active, secret: 's');

        $this->assertSame('turn:[2001:db8::1]:3478?transport=udp', $node->turnUrls()[0]);
    }

    public function testHealthNeedsRepeatedFailuresAndResetsOnSuccess(): void
    {
        $store = new HealthStore($this->tempPath(), 900, 2);

        $this->assertSame('unknown', $store->state('mum', self::NOW), 'No file is no information, not an outage.');

        $store->record(['mum' => ['ok' => false, 'error' => 'timeout']], self::NOW);
        $this->assertSame('failing', $store->state('mum', self::NOW));
        $this->assertFalse($store->isDown('mum', self::NOW));

        $store->record(['mum' => ['ok' => false, 'error' => 'timeout']], self::NOW + 300);
        $this->assertTrue($store->isDown('mum', self::NOW + 300));

        $store->record(['ncr' => ['ok' => true]], self::NOW + 400);
        $this->assertTrue($store->isDown('mum', self::NOW + 400), 'A run that did not probe a node keeps its entry.');

        $store->record(['mum' => ['ok' => true]], self::NOW + 600);
        $this->assertSame('up', $store->state('mum', self::NOW + 600));
        $this->assertSame('stale', $store->state('mum', self::NOW + 600 + 901));
    }

    public function testACorruptHealthFileMeansNoInformation(): void
    {
        $path = $this->tempPath();
        file_put_contents($path, '{not json');

        $this->assertSame([], (new HealthStore($path))->states(self::NOW));
    }

    public function testHealthLocationIsConfigurable(): void
    {
        $env   = ['WEBRTC_TURN_HEALTH_FILE' => '/tmp/x.json', 'WEBRTC_TURN_HEALTH_FAILURES' => '3'];
        $store = HealthStore::fromEnvironment(static fn ($k) => $env[$k] ?? null, '/default.json');

        $this->assertSame('/tmp/x.json', $store->path());
        $this->assertSame(3, $store->failureThreshold);
        $this->assertSame('/default.json', HealthStore::fromEnvironment(static fn () => null, '/default.json')->path());
    }

    public function testNodesSharingASecretAreFlaggedWithoutRevealingIt(): void
    {
        $shared = $this->topology($this->productionEnv());
        $this->assertCount(1, $shared->warnings());
        $this->assertStringContainsString("'mum' and 'ncr' use the same secret", $shared->warnings()[0]);
        $this->assertStringNotContainsString('shared-coturn-secret', implode(' ', $shared->warnings()));

        $separate = $this->topology($this->productionEnv([
            'WEBRTC_TURN_STATIC_AUTH_SECRET' => null,
            'WEBRTC_TURN_NODE_MUM_SECRET'    => 'mumbai-only-secret',
            'WEBRTC_TURN_NODE_NCR_SECRET'    => 'noida-only-secret',
        ]));
        $this->assertSame([], $separate->warnings());
        $this->assertSame([], $separate->problems());
    }

    public function testASingleNodeOrAStaticPairCannotShareASecretWithAnything(): void
    {
        $this->assertSame([], $this->topology([
            'WEBRTC_TURN_URLS' => 'turn:t.example:3478', 'WEBRTC_TURN_STATIC_AUTH_SECRET' => 's',
        ])->warnings());
    }

    public function testHealthDistinguishesAPartiallyWorkingNodeFromAHealthyOneAndADeadOne(): void
    {
        $store = new HealthStore($this->tempPath(), 900, 2);

        $store->record(['mum' => ['ok' => true, 'transports' => [
            'udp' => ['binding' => true, 'allocation' => true],
            'tls' => ['binding' => true, 'allocation' => false],
        ]]], self::NOW);
        $this->assertSame('degraded', $store->state('mum', self::NOW));
        $this->assertSame('degraded', HealthStore::condition('degraded'));

        $store->record(['mum' => ['ok' => true, 'transports' => ['udp' => ['binding' => true, 'allocation' => true]]]], self::NOW + 300);
        $this->assertSame('up', $store->state('mum', self::NOW + 300));
        $this->assertSame('healthy', HealthStore::condition('up'));

        $this->assertSame('degraded', HealthStore::condition('failing'));
        $this->assertSame('unreachable', HealthStore::condition('down'));
        $this->assertSame('unknown', HealthStore::condition('stale'));
        $this->assertSame('unknown', HealthStore::condition('unknown'));
    }
}
