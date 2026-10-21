<?php

declare(strict_types=1);

namespace Tests\Feature;

use Aicountly\Ice\Topology;
use App\Domain\Auth\RemoteIdentity;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Remote as RemoteConfig;
use Config\Services;
use Tests\Support\FakeIdentityResolver;
use Tests\Support\RemoteTestCase;

/**
 * Regional peers and licence-aware admission, over HTTP: Mumbai and Greater Noida both active, a company's
 * plan decides which tier its sessions are accounted to, and the paid reserve holds on both nodes.
 *
 * Capacity is deliberately tiny (6 allocations per node, 3 URLs per client = one primary lease each way) so
 * the arithmetic is visible. None of this says anything about the real nodes.
 *
 * @internal
 */
final class ActiveActiveIceApiTest extends RemoteTestCase
{
    use FeatureTestTrait;

    private FakeIdentityResolver $identities;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->identities = new FakeIdentityResolver(Services::portalClient(), $this->db);
        Services::injectMock('identityResolver', $this->identities);

        // The books live in files the environment names; one set per test.
        $tag = bin2hex(random_bytes(4));
        foreach (['LEASE' => 'leases', 'LOAD' => 'load', 'LATENCY' => 'latency', 'HEALTH' => 'health'] as $key => $name) {
            $path = WRITEPATH . "ice/test-{$tag}-{$name}.json";
            $_ENV["WEBRTC_TURN_{$key}_FILE"] = $path;
            $this->files[] = $path;
            $this->files[] = $path . '.lock';
        }

        $env = [
            'WEBRTC_TURN_NODES'                    => 'mum,ncr',
            'WEBRTC_TURN_TTL'                      => '1200',
            'WEBRTC_TURN_MAX_NODES'                => '1',
            'WEBRTC_TURN_ADMISSION'                => 'enforce',
            'WEBRTC_TURN_NODE_MUM_HOST'            => 'turn-mum.aicountly.com',
            'WEBRTC_TURN_NODE_MUM_STATUS'          => 'active',
            'WEBRTC_TURN_NODE_MUM_REGION'          => 'in-west',
            'WEBRTC_TURN_NODE_MUM_LAT'             => '19.0760',
            'WEBRTC_TURN_NODE_MUM_LON'             => '72.8777',
            'WEBRTC_TURN_NODE_MUM_TLS_PORT'        => '443',
            'WEBRTC_TURN_NODE_MUM_SECRET'          => 'mumbai-secret-for-tests-000',
            'WEBRTC_TURN_NODE_MUM_MAX_ALLOCATIONS' => '6',
            'WEBRTC_TURN_NODE_NCR_HOST'            => 'turn-ncr.aicountly.com',
            'WEBRTC_TURN_NODE_NCR_STATUS'          => 'active',
            'WEBRTC_TURN_NODE_NCR_REGION'          => 'in-north',
            'WEBRTC_TURN_NODE_NCR_LAT'             => '28.4744',
            'WEBRTC_TURN_NODE_NCR_LON'             => '77.5040',
            'WEBRTC_TURN_NODE_NCR_TLS_PORT'        => '443',
            'WEBRTC_TURN_NODE_NCR_SECRET'          => 'noida-secret-for-tests-0000',
            'WEBRTC_TURN_NODE_NCR_MAX_ALLOCATIONS' => '6',
        ];

        $this->configureRemote(static function (RemoteConfig $config) use ($env): void {
            $config->contextSecret    = 'test-context-secret';
            $config->signallingSecret = 'test-signalling-secret';
            $config->signalUrl        = 'wss://remote.aicountly.test/signal';
            $config->turnTopology     = Topology::fromEnvironment(static fn (string $k): ?string => $env[$k] ?? null, ['stunUrls' => []]);
            $config->turnPaidPlanCodes = ['REMOTE_BUSINESS'];
        });
    }

    protected function tearDown(): void
    {
        foreach (['LEASE', 'LOAD', 'LATENCY', 'HEALTH'] as $key) {
            unset($_ENV["WEBRTC_TURN_{$key}_FILE"]);
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * A session owned by a company on a plan, with one admitted viewer.
     *
     * @return array{session: array<string, mixed>, host: array<string, string>, viewer: array<string, string>, viewerUuid: string}
     */
    private function sessionOn(int $companyId, string $plan, string $tag): array
    {
        $host   = $this->makeIdentity('Host ' . $tag);
        $viewer = $this->makeIdentity('Viewer ' . $tag);

        $company = $this->makeCompany($companyId, 'Company ' . $tag);
        $this->db->table('remote_entitlements')->insert(['company_id' => $companyId, 'plan_code' => $plan]);
        $this->grantCompanyAccess($host, $company);
        $this->grantCompanyAccess($viewer, $company);

        $hostHeaders   = $this->identified($host, 'host-' . $tag);
        $viewerHeaders = $this->identified($viewer, 'viewer-' . $tag);
        $session       = $this->makeSession($host, 'COMPANY', $company);

        $joined = $this->withHeaders($viewerHeaders)->withBodyFormat('json')
            ->post('v1/remote/join/code', ['code' => str_replace(' ', '', (string) $session['session_code'])]);
        $joined->assertStatus(201);
        $viewerUuid = json_decode($joined->getJSON(), true)['data']['participant']['uuid'];

        $this->withHeaders($hostHeaders)->post("v1/remote/sessions/{$session['uuid']}/participants/{$viewerUuid}/approve")->assertStatus(200);

        return ['session' => $session, 'host' => $hostHeaders, 'viewer' => $viewerHeaders, 'viewerUuid' => $viewerUuid];
    }

    /** @return array<string, string> */
    private function identified(RemoteIdentity $identity, string $sesKey): array
    {
        $this->identities->register($sesKey, $identity);

        return ['Authorization' => 'Bearer ' . $sesKey];
    }

    /** @return array<string, mixed> */
    private function iceFor(array $s, array $body = []): array
    {
        $response = $this->withHeaders($s['viewer'])->withBodyFormat('json')->post("v1/remote/sessions/{$s['session']['uuid']}/ice-servers", $body);
        $response->assertStatus(200);

        return json_decode($response->getJSON(), true)['data'];
    }

    private function turnUsername(array $grant): string
    {
        foreach ($grant['iceServers'] as $server) {
            if (isset($server['username'])) {
                return $server['username'];
            }
        }

        return '';
    }

    /** @return array<string, mixed> */
    private function node(string $id): array
    {
        foreach (Services::iceConfigService()->status()['nodes'] as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }
        $this->fail("No node {$id}");
    }

    public function testACompanysPlanDecidesTheTierItsSessionsAreAccountedTo(): void
    {
        $paid = $this->sessionOn(481, 'REMOTE_BUSINESS', 'paid');
        $free = $this->sessionOn(482, 'REMOTE_FREE', 'free');

        $this->assertMatchesRegularExpression('/^\d+:p\./', $this->turnUsername($this->iceFor($paid)));
        $this->assertSame('paid', $this->iceFor($paid)['admission']['tier']);
        $this->assertMatchesRegularExpression('/^\d+:f\./', $this->turnUsername($this->iceFor($free)));
        $this->assertSame('free', $this->iceFor($free)['admission']['tier']);
    }

    public function testWithNoPaidPlansConfiguredEveryoneIsFree(): void
    {
        $this->configureRemote(static function (RemoteConfig $config): void {
            $config->turnPaidPlanCodes = [];
        });
        $paid = $this->sessionOn(483, 'REMOTE_BUSINESS', 'unlisted');

        $this->assertSame('free', $this->iceFor($paid)['admission']['tier'], 'The reserve is handed out only on positive evidence.');
    }

    public function testRegionalPeersServeTheirOwnRegionFirst(): void
    {
        // Separate sessions: a participant who is already on a node stays on it (no ICE restart for a few ms).
        $west  = $this->sessionOn(481, 'REMOTE_BUSINESS', 'west');
        $north = $this->sessionOn(484, 'REMOTE_BUSINESS', 'north');
        $delhi = $this->sessionOn(485, 'REMOTE_BUSINESS', 'delhi');

        $this->assertSame(['mum'], array_column($this->iceFor($west, ['region' => 'in-west'])['relayNodes'], 'id'));
        $this->assertSame(['ncr'], array_column($this->iceFor($north, ['region' => 'in-north'])['relayNodes'], 'id'));
        $this->assertSame(['mum'], array_column($this->iceFor($delhi, ['region' => 'in-north', 'rtt' => ['mum' => 20, 'ncr' => 90]])['relayNodes'], 'id'), 'What the browser measured outranks the region it is said to be in.');
    }

    public function testAFullFreePoolRefusesFreeSessionsAndNeverTheReserve(): void
    {
        // Each node: 6 allocations, 3 reserved for paid, 3 for free — one primary lease (3 URLs).
        $ice = Services::iceConfigService();
        foreach (['mum', 'ncr'] as $i => $node) {
            $ice->issuer()->issue("hog-{$i}", ['tier' => 'free', 'sessionRef' => "hog-{$i}", 'preferRegion' => $node === 'mum' ? 'in-west' : 'in-north', 'profile' => 'remote']);
        }
        $this->assertSame(3, $this->node('mum')['allocations']['reserved']['free']);
        $this->assertSame(3, $this->node('ncr')['allocations']['reserved']['free']);

        $free = $this->sessionOn(482, 'REMOTE_FREE', 'free');
        $deny = $this->iceFor($free);
        $this->assertFalse($deny['relayAvailable']);
        $this->assertSame('denied', $deny['admission']['outcome']);
        $this->assertSame('free_pool_full', $deny['admission']['reason']);
        $this->assertSame('', $this->turnUsername($deny), 'A refused client is handed no credential at all.');

        $paid = $this->sessionOn(481, 'REMOTE_BUSINESS', 'paid');
        $ok   = $this->iceFor($paid);
        $this->assertTrue($ok['relayAvailable']);
        $this->assertSame('admitted', $ok['admission']['outcome']);
    }

    public function testEndingASessionReturnsItsCapacity(): void
    {
        $s = $this->sessionOn(482, 'REMOTE_FREE', 'ends');
        $this->iceFor($s, ['region' => 'in-west']);
        $this->assertSame(1, $this->node('mum')['sessions']['free']);

        $this->withHeaders($s['host'])->post("v1/remote/sessions/{$s['session']['uuid']}/end")->assertStatus(200);

        $this->assertSame(0, $this->node('mum')['sessions']['free']);
        $this->assertSame(0, $this->node('mum')['allocations']['reserved']['free']);
    }

    public function testAParticipantWhoLeavesReleasesOnlyTheirOwnLease(): void
    {
        $s = $this->sessionOn(482, 'REMOTE_FREE', 'leaves');
        $this->iceFor($s, ['region' => 'in-west']);
        $hostGrant = Services::iceConfigService()->grant('host-participant', 'in-west', null, ['tier' => \Aicountly\Ice\Tier::Free, 'sessionRef' => (string) $s['session']['uuid']]);
        $this->assertTrue($hostGrant->relayAvailable());
        // Mumbai's free share holds one client, so the second took Noida's: reciprocal fallback.
        $reserved = fn (): int => $this->node('mum')['allocations']['reserved']['free'] + $this->node('ncr')['allocations']['reserved']['free'];
        $this->assertSame(6, $reserved());

        $this->withHeaders($s['viewer'])->post("v1/remote/sessions/{$s['session']['uuid']}/participants/{$s['viewerUuid']}/leave");

        $this->assertSame(3, $reserved(), 'Only the participant who left is released; the other still holds a relay.');
    }

    public function testAReportedDirectConnectionFreesMostOfTheBandwidthChargeButNotTheSlots(): void
    {
        $s = $this->sessionOn(481, 'REMOTE_BUSINESS', 'direct');
        $this->iceFor($s, ['region' => 'in-west']);
        $this->assertSame(6_000_000, $this->node('mum')['bandwidth']['reservedBps']['paid'], 'Expected on the first node: the Remote profile.');

        $this->withHeaders($s['viewer'])->withBodyFormat('json')
            ->post("v1/remote/sessions/{$s['session']['uuid']}/connectivity-report", ['outcome' => 'connected', 'path' => 'direct'])
            ->assertStatus(200);

        $this->assertSame(1_200_000, $this->node('mum')['bandwidth']['reservedBps']['paid'], 'A direct claim cannot be verified, so a residual (20 %) stays booked.');
        $this->assertSame(3, $this->node('mum')['allocations']['reserved']['paid']);
    }

    public function testTheHealthEndpointShowsLoadWordsAndNoCapacities(): void
    {
        $raw = $this->get('health')->getJSON();
        $this->assertStringContainsString('"load"', (string) $raw);
        $this->assertStringContainsString('servingBy', (string) $raw);
        foreach (['maxAllocations', 'maxMbps', 'reservedBps', 'mumbai-secret', 'noida-secret', 'sessions'] as $private) {
            $this->assertStringNotContainsString($private, (string) $raw, "{$private} must not be public.");
        }
    }

    public function testAnUnadmittedViewerStillGetsNoLeaseAndNoCredential(): void
    {
        $this->sessionOn(482, 'REMOTE_FREE', 'owner');
        $this->assertSame(0, $this->node('mum')['sessions']['free'] + $this->node('ncr')['sessions']['free']);
    }
}
