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
 * TURN credentials and connectivity reports over HTTP (lib/ice-kit, docs/ICE_CONTRACT.md).
 *
 * The infrastructure under test is the production plan: Mumbai active on TLS 443, Greater Noida
 * configured but disabled. What these pin: credentials go only to admitted participants, the disabled
 * node never reaches a client, the secret never does either, and a report cannot smuggle a candidate
 * or an address into the log.
 *
 * @internal
 */
final class IceApiTest extends RemoteTestCase
{
    use FeatureTestTrait;

    private const SECRET = 'coturn-shared-secret-for-tests';

    private FakeIdentityResolver $identities;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identities = new FakeIdentityResolver(Services::portalClient(), $this->db);
        Services::injectMock('identityResolver', $this->identities);

        $env = [
            'WEBRTC_TURN_NODES'              => 'mum,ncr',
            'WEBRTC_TURN_STATIC_AUTH_SECRET' => self::SECRET,
            'WEBRTC_TURN_TTL'                => '1200',
            'WEBRTC_TURN_NODE_MUM_HOST'      => 'turn-mum.aicountly.com',
            'WEBRTC_TURN_NODE_MUM_STATUS'    => 'active',
            'WEBRTC_TURN_NODE_MUM_REGION'    => 'in-west',
            'WEBRTC_TURN_NODE_MUM_TLS_PORT'  => '443',
            'WEBRTC_TURN_NODE_NCR_HOST'      => 'turn-ncr.aicountly.com',
            'WEBRTC_TURN_NODE_NCR_STATUS'    => 'disabled',
            'WEBRTC_TURN_NODE_NCR_REGION'    => 'in-north',
        ];

        $this->configureRemote(static function (RemoteConfig $config) use ($env): void {
            $config->contextSecret    = 'test-context-secret';
            $config->signallingSecret = 'test-signalling-secret';
            $config->signalUrl        = 'wss://remote.aicountly.test/signal';
            $config->turnTopology     = Topology::fromEnvironment(static fn (string $k): ?string => $env[$k] ?? null, ['stunUrls' => []]);
            $config->turnHealthFile   = WRITEPATH . 'ice/test-' . bin2hex(random_bytes(4)) . '.json';
        });
    }

    /** @return array<string, string> */
    private function asUser(RemoteIdentity $identity, string $sesKey): array
    {
        $this->identities->register($sesKey, $identity);

        return ['Authorization' => 'Bearer ' . $sesKey];
    }

    /**
     * A session with a viewer who has asked to join, approved or not.
     *
     * @return array{session: array<string, mixed>, host: array<string, string>, viewer: array<string, string>, viewerUuid: string}
     */
    private function sessionWithViewer(bool $approve = true): array
    {
        $host   = $this->makeIdentity('Host');
        $viewer = $this->makeIdentity('Viewer');

        $company = $this->makeCompany(481, 'ABC');
        $this->grantCompanyAccess($host, $company);
        $this->grantCompanyAccess($viewer, $company);

        $hostHeaders   = $this->asUser($host, 'host-key');
        $viewerHeaders = $this->asUser($viewer, 'viewer-key');
        $session       = $this->makeSession($host, 'COMPANY', $company);

        $joined = $this->withHeaders($viewerHeaders)
            ->withBodyFormat('json')
            ->post('v1/remote/join/code', ['code' => str_replace(' ', '', (string) $session['session_code'])]);
        $joined->assertStatus(201);
        $viewerUuid = json_decode($joined->getJSON(), true)['data']['participant']['uuid'];

        if ($approve) {
            $this->withHeaders($hostHeaders)
                ->post("v1/remote/sessions/{$session['uuid']}/participants/{$viewerUuid}/approve")
                ->assertStatus(200);
        }

        return ['session' => $session, 'host' => $hostHeaders, 'viewer' => $viewerHeaders, 'viewerUuid' => $viewerUuid];
    }

    public function testAnAdmittedParticipantGetsAShortLivedGrantForTheActiveNodeOnly(): void
    {
        $s = $this->sessionWithViewer();

        $response = $this->withHeaders($s['viewer'])->post("v1/remote/sessions/{$s['session']['uuid']}/ice-servers");
        $response->assertStatus(200);
        $raw   = $response->getJSON();
        $grant = json_decode($raw, true)['data'];

        $this->assertSame('aicountly.ice/1', $grant['contract']);
        $this->assertTrue($grant['relayAvailable']);
        $this->assertSame(['mum'], array_column($grant['relayNodes'], 'id'));
        $this->assertSame(1200, $grant['expiresIn']);
        $this->assertSame(960, $grant['refreshAfter']);

        $turn = $grant['iceServers'][1];
        $this->assertContains('turns:turn-mum.aicountly.com:443?transport=tcp', $turn['urls']);
        $this->assertStringEndsWith(':' . $s['viewerUuid'], $turn['username'], 'The relay can attribute usage to a participant, never to an email address.');
        $this->assertSame(base64_encode(hash_hmac('sha1', $turn['username'], self::SECRET, true)), $turn['credential']);

        $this->assertStringNotContainsString(self::SECRET, (string) $raw);
        $this->assertStringNotContainsString('turn-ncr', (string) $raw, 'A disabled node must never reach a client.');
    }

    public function testTheSignallingTokenCarriesTheSameGrant(): void
    {
        $s = $this->sessionWithViewer();

        $data = json_decode(
            $this->withHeaders($s['viewer'])->post("v1/remote/sessions/{$s['session']['uuid']}/signalling-token")->getJSON(),
            true,
        )['data'];

        // The flat fields older clients and the desktop agent read are still there…
        $this->assertTrue($data['relayAvailable']);
        $this->assertSame($data['ice']['iceServers'], $data['iceServers']);
        // …beside the full contract.
        $this->assertSame('aicountly.ice/1', $data['ice']['contract']);
    }

    public function testAViewerTheHostHasNotAdmittedGetsNoRelayCredential(): void
    {
        $s = $this->sessionWithViewer(false);

        $refused = $this->withHeaders($s['viewer'])->post("v1/remote/sessions/{$s['session']['uuid']}/ice-servers");

        $refused->assertStatus(403);
        $this->assertSame('AWAITING_APPROVAL', json_decode($refused->getJSON(), true)['error']['code']);
    }

    public function testSomeoneOutsideTheSessionGetsNoRelayCredential(): void
    {
        $s        = $this->sessionWithViewer();
        $stranger = $this->asUser($this->makeIdentity('Stranger'), 'stranger-key');

        $response = $this->withHeaders($stranger)->post("v1/remote/sessions/{$s['session']['uuid']}/ice-servers");

        $this->assertContains($response->response()->getStatusCode(), [403, 404]);
        $this->assertStringNotContainsString('credential', (string) $response->getJSON());
    }

    public function testAnUnauthenticatedCallerGetsNothing(): void
    {
        $s = $this->sessionWithViewer();

        // FeatureTestTrait keeps the last withHeaders() for later requests; clear them so this one
        // really carries no credential.
        $this->withHeaders([])->post("v1/remote/sessions/{$s['session']['uuid']}/ice-servers")->assertStatus(401);
    }

    public function testAnEndedSessionIssuesNoMoreCredentials(): void
    {
        $s = $this->sessionWithViewer();

        $this->withHeaders($s['host'])->post("v1/remote/sessions/{$s['session']['uuid']}/end")->assertStatus(200);

        $this->withHeaders($s['viewer'])->post("v1/remote/sessions/{$s['session']['uuid']}/ice-servers")->assertStatus(409);
    }

    public function testARegionHintReordersButNeverAddsANode(): void
    {
        $s = $this->sessionWithViewer();

        $grant = json_decode(
            $this->withHeaders($s['viewer'])->withBodyFormat('json')
                ->post("v1/remote/sessions/{$s['session']['uuid']}/ice-servers", ['region' => 'in-north'])
                ->getJSON(),
            true,
        )['data'];

        $this->assertSame(['mum'], array_column($grant['relayNodes'], 'id'), 'Asking for the disabled node\'s region must not enable it.');
    }

    public function testAConnectivityReportIsLoggedWithOnlyAllowListedFields(): void
    {
        $s = $this->sessionWithViewer();

        $this->withHeaders($s['viewer'])->withBodyFormat('json')
            ->post("v1/remote/sessions/{$s['session']['uuid']}/connectivity-report", [
                'outcome'            => 'connected',
                'path'               => 'relay',
                'localCandidateType' => 'relay',
                'relayProtocol'      => 'tls',
                'relayNode'          => 'mum',
                'gathered'           => ['host' => 2, 'srflx' => 1, 'relay' => 3],
                'iceErrors'          => [['category' => 'turn_unreachable', 'node' => 'mum', 'code' => 701, 'address' => '203.0.113.9']],
                'restarts'           => 1,
                // Everything below must be dropped.
                'candidate'          => 'candidate:1 1 udp 2122260223 192.168.1.20 54400 typ host',
                'sdp'                => 'v=0',
                'relayNodeEvil'      => '10.0.0.1',
            ])
            ->assertStatus(200);

        $this->assertLogContains('info', '"relayNode":"mum"');
        $this->assertLogContains('info', '"category":"turn_unreachable"');
        $this->assertFalse(\CodeIgniter\Test\TestLogger::didLog('info', '192.168.1.20', false), 'A candidate address must never reach the log.');
        $this->assertFalse(\CodeIgniter\Test\TestLogger::didLog('info', '203.0.113.9', false));
        $this->assertFalse(\CodeIgniter\Test\TestLogger::didLog('info', 'v=0', false));
    }

    public function testHealthListsNodesWithoutHostsOrSecrets(): void
    {
        $body = (string) $this->get('health')->getJSON();
        $data = json_decode($body, true);

        $this->assertSame('configured', $data['relay']);
        $this->assertSame(
            [
                ['id' => 'mum', 'status' => 'active', 'servable' => true, 'health' => 'unknown', 'condition' => 'unknown', 'state' => 'unknown', 'serving' => true],
                ['id' => 'ncr', 'status' => 'disabled', 'servable' => false, 'health' => 'unknown', 'condition' => 'unknown', 'state' => 'disabled', 'serving' => false],
            ],
            $data['relayNodes'],
        );
        $this->assertStringNotContainsString(self::SECRET, $body);
    }

    public function testTheLegacySinglePoolKeysStillWorkWithoutNodes(): void
    {
        $this->configureRemote(static function (RemoteConfig $config): void {
            $config->signallingSecret     = 'test-signalling-secret';
            $config->turnUrls             = ['turns:turn.aicountly.test:5349'];
            $config->turnStaticAuthSecret = 'legacy-secret';
            $config->turnCredentialTtlSeconds = 600;
        });

        $grant = Services::iceConfigService()->grant('participant-1');

        $this->assertSame('legacy', Services::iceConfigService()->status()['source']);
        $this->assertSame(['default'], array_column($grant->relayNodes(), 'id'));
        $this->assertSame(600, $grant->expiresIn());
    }

    public function testADiagnosticReportFromAViewerTheHostHasNotAdmittedIsRefused(): void
    {
        $s = $this->sessionWithViewer(false);

        $refused = $this->withHeaders($s['viewer'])->withBodyFormat('json')
            ->post("v1/remote/sessions/{$s['session']['uuid']}/connectivity-report", ['outcome' => 'connected']);

        $refused->assertStatus(403);
        $this->assertFalse(\CodeIgniter\Test\TestLogger::didLog('info', $s['viewerUuid'], false), 'Nothing from an unadmitted caller reaches the log.');
    }

    public function testADiagnosticReportWithoutCredentialsIsRefused(): void
    {
        $s = $this->sessionWithViewer();

        $this->withHeaders([])->withBodyFormat('json')
            ->post("v1/remote/sessions/{$s['session']['uuid']}/connectivity-report", ['outcome' => 'connected'])
            ->assertStatus(401);
    }

    public function testCredentialIssuanceIsRateLimited(): void
    {
        $s = $this->sessionWithViewer();
        cache()->clean();

        $statuses = [];
        for ($i = 0; $i < 32; $i++) {
            $statuses[] = $this->withHeaders($s['viewer'])->post("v1/remote/sessions/{$s['session']['uuid']}/ice-servers")->response()->getStatusCode();
        }

        $this->assertSame(200, $statuses[0]);
        $this->assertSame(429, end($statuses), 'The 30-a-minute budget is enforced, not just configured.');
        $this->assertLessThanOrEqual(31, count(array_filter($statuses, static fn (int $c): bool => $c === 200)));
    }
}
