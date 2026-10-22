<?php

declare(strict_types=1);

namespace Tests\Feature;

use Aicountly\Ice\Licence\EntitlementVerifier;
use Aicountly\Ice\Licence\LicencePolicy;
use Aicountly\Ice\Licence\LicenceResolver;
use Aicountly\Ice\Licence\MemoryEntitlementCache;
use Aicountly\Ice\Topology;
use App\Domain\Signalling\IceConfigService;
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

    private MemoryEntitlementCache $central;
    private string $centralKey = '';
    private string $centralPub = '';

    /** @var list<string> what the licence readiness check reports; empty = the central source is operational and validated */
    private array $licenceBlockers = [];

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
            $config->turnEntitlementMaxAgeHours = 48;
        });

        // The common licence adapter, with a fake CENTRAL backend that signs with a throwaway key, and a readiness answer the
        // test controls. Legacy plan mapping (above) is the compatibility path while no central entitlement exists.
        $pair             = sodium_crypto_sign_keypair();
        $this->centralKey = sodium_crypto_sign_secretkey($pair);
        $this->centralPub = sodium_crypto_sign_publickey($pair);
        $this->central    = new MemoryEntitlementCache();
        Services::injectMock('iceConfigService', new IceConfigService(
            Services::remoteConfig(),
            new LicenceResolver($this->central, new EntitlementVerifier(['central-test' => sodium_crypto_sign_publickey($pair)]), new LicencePolicy(172800, ['paid'], true, true)),
            fn (): array => $this->licenceBlockers,
        ));
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

    public function testThePlatformDefaultRowNeverMakesACompanyPaid(): void
    {
        // The seeded default (company_id NULL) is a feature set for companies nobody set up. Even with its plan
        // listed as paid, a company without a row of its own is free.
        $this->db->table('remote_entitlements')->insert(['company_id' => null, 'plan_code' => 'REMOTE_BUSINESS']);
        $s = $this->sessionOn(483, 'REMOTE_FREE', 'nodefault');
        $this->db->table('remote_entitlements')->where('company_id', 483)->delete();

        $this->assertSame('free', $this->iceFor($s)['admission']['tier']);
    }

    public function testAnExpiredNotYetValidOrStaleEntitlementIsFree(): void
    {
        $now = static fn (string $mod): string => gmdate('Y-m-d H:i:s', strtotime($mod));
        $cases = [
            491 => ['valid_until' => $now('-1 day')],                                  // lapsed
            492 => ['valid_from' => $now('+1 day')],                                   // future-dated
            493 => ['updated_at' => $now('-10 days')],                                 // the feed stopped (limit: 48 h)
        ];
        foreach ($cases as $companyId => $override) {
            $s = $this->sessionOn($companyId, 'REMOTE_BUSINESS', 'c' . $companyId);
            $this->db->table('remote_entitlements')->where('company_id', $companyId)->update($override);
            $this->assertSame('free', $this->iceFor($s)['admission']['tier'], "company {$companyId}");
        }

        $live = $this->sessionOn(494, 'REMOTE_BUSINESS', 'live');
        $this->assertSame('paid', $this->iceFor($live)['admission']['tier'], 'The same plan, current, is paid.');
    }

    public function testEnforcementIsHeldBackWhileTheCentralLicenceSourceIsNotOperational(): void
    {
        $this->licenceBlockers = ['the central licence API is not configured'];
        $s = $this->sessionOn(495, 'REMOTE_BUSINESS', 'gate');

        $status = Services::iceConfigService()->status();
        $this->assertSame('observe', $status['admission']['mode'], 'Asked to enforce, but the central source is not operational: observing.');
        $this->assertSame(['the central licence API is not configured'], $status['enforceBlockedBy']);
        $this->assertTrue($this->iceFor($s)['relayAvailable']);
    }

    public function testWithNoReadinessWiredTheDefaultCheckBlocksEnforcement(): void
    {
        $service = new IceConfigService(Services::remoteConfig());

        $this->assertNotEmpty($service->licenceBlockers(), 'No central API, no keys, no sync: the real default is "not ready".');
        $this->assertSame('observe', $service->status()['admission']['mode']);
    }

    public function testAPlanNobodyMappedAsPaidIsFree(): void
    {
        $s = $this->sessionOn(496, 'REMOTE_MYSTERY', 'unmapped');

        $this->assertSame('free', $this->iceFor($s)['admission']['tier']);
        $this->assertSame('compat_plan_not_paid', Services::iceConfigService()->licenceForSession(['company_id' => 496])->reason);
    }

    /**
     * @param array<string, mixed> $over
     */
    private function centralEnvelope(int $companyId, array $over = []): array
    {
        $now = time();
        $doc = $over + [
            'contract' => 'aicountly.licence/1', 'scope' => 'company', 'customer_id' => (string) $companyId, 'product_id' => 'remote', 'subject' => null,
            'eligibility' => ['model' => 'company_wide', 'member' => null, 'seat' => 'not_applicable'],
            'licence_id' => 'LIC-000' . $companyId, 'plan_code' => 'CENTRAL_PRO', 'plan_class' => 'paid', 'kind' => 'paid', 'state' => 'paid',
            'valid_from' => gmdate('Y-m-d\TH:i:s\Z', $now - 86400), 'valid_until' => gmdate('Y-m-d\TH:i:s\Z', $now + 86400 * 30),
            'entitlements' => [], 'revision' => 3, 'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 60),
        ];

        return EntitlementVerifier::sign($doc, 'central-test', $this->centralKey);
    }

    public function testACentralPaidEntitlementMakesTheCompanyPaidWhateverItsLocalPlanSays(): void
    {
        // Remote's own row says REMOTE_FREE; the central backend (the authority) says paid under ITS plan code.
        $s = $this->sessionOn(497, 'REMOTE_FREE', 'central');
        $this->assertSame('free', $this->iceFor($s)['admission']['tier']);

        $this->central->put('customer', '497', 'remote', $this->centralEnvelope(497), time());

        $this->assertSame('paid', $this->iceFor($s)['admission']['tier']);
        $r = Services::iceConfigService()->licenceForSession(['company_id' => 497]);
        $this->assertSame(['central', 'CENTRAL_PRO'], [$r->evidence, $r->planCode]);
    }

    public function testCentralEvidenceOverridesALegacyPaidMapping(): void
    {
        // The legacy mapping says paid; the central backend says the licence is suspended. The central answer wins, every time.
        $s = $this->sessionOn(498, 'REMOTE_BUSINESS', 'suspended');
        $this->assertSame('paid', $this->iceFor($s)['admission']['tier'], 'compat, before the central backend has spoken');

        $this->central->put('customer', '498', 'remote', $this->centralEnvelope(498, ['state' => 'suspended']), time());

        $this->assertSame('free', $this->iceFor($s)['admission']['tier']);
        $this->assertSame('suspended', Services::iceConfigService()->licenceForSession(['company_id' => 498])->state->value);
    }

    public function testARevocationTakesEffectOnTheNextCredentialRequest(): void
    {
        $s = $this->sessionOn(499, 'REMOTE_FREE', 'revoked');
        $this->central->put('customer', '499', 'remote', $this->centralEnvelope(499, ['revision' => 4]), time());
        $this->assertSame('paid', $this->iceFor($s)['admission']['tier']);

        $this->central->revoke('499', 'remote', 4, time());

        $this->assertSame('free', $this->iceFor($s)['admission']['tier']);
        $this->assertSame('revoked', Services::iceConfigService()->licenceForSession(['company_id' => 499])->reason);
    }

    public function testOneCompaniesEntitlementNeverMakesAnotherPaid(): void
    {
        $other = $this->sessionOn(500, 'REMOTE_FREE', 'other');
        // A genuine, signed paid entitlement for company 497 — filed (wrongly or maliciously) under company 500.
        $this->central->put('customer', '500', 'remote', $this->centralEnvelope(497), time());

        $this->assertSame('free', $this->iceFor($other)['admission']['tier']);
        $this->assertSame('identity_mismatch', Services::iceConfigService()->licenceForSession(['company_id' => 500])->reason);
    }

    public function testAForgedOrStaleCentralEntitlementIsNotPaid(): void
    {
        $s = $this->sessionOn(501, 'REMOTE_FREE', 'forged');
        $forged = $this->centralEnvelope(501);
        $forged['document']['plan_code'] = 'CENTRAL_ENTERPRISE';
        $this->central->put('customer', '501', 'remote', $forged, time());
        $this->assertSame('free', $this->iceFor($s)['admission']['tier']);
        $this->assertSame('signature_invalid', Services::iceConfigService()->licenceForSession(['company_id' => 501])->reason);

        $stale = $this->sessionOn(502, 'REMOTE_FREE', 'stale');
        $this->central->put('customer', '502', 'remote', $this->centralEnvelope(502, ['issued_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 4 * 86400)]), time());
        $this->assertSame('free', $this->iceFor($stale)['admission']['tier']);
        $this->assertSame('stale', Services::iceConfigService()->licenceForSession(['company_id' => 502])->reason);
    }

    /** The platform uuid of the identity that owns the session — the licence subject. */
    private function ownerUuid(array $s): string
    {
        $row = $this->db->table('remote_identities')->select('platform_uuid')->where('id', (int) $s['session']['owner_user_id'])->get()->getRowArray();

        return (string) $row['platform_uuid'];
    }

    public function testASessionIsLicensedInItsCompanyContextAndOnlyForACurrentOwnerMember(): void
    {
        $s = $this->sessionOn(510, 'REMOTE_FREE', 'ctx');
        $this->central->put('customer', '510', 'remote', $this->centralEnvelope(510), time());
        $this->assertSame('paid', $this->iceFor($s)['admission']['tier'], 'a company-wide licence, and the owner is a current member');

        // The owner is removed from the company (Manage says so; the projection row is gone): the session is no longer licensed.
        $this->db->table('remote_user_company_access')->where('company_id', 510)->where('user_id', (int) $s['session']['owner_user_id'])->delete();
        $this->assertSame('free', $this->iceFor($s)['admission']['tier']);
        $this->assertSame('not_a_member', Services::iceConfigService()->licenceForSession($s['session'])->reason);
    }

    public function testAnOwnerWhoseMembershipCannotBeConfirmedIsNotTreatedAsAMember(): void
    {
        $this->configureRemote(static function (RemoteConfig $config): void {
            $config->membershipEnforcement  = true;
            $config->membershipGraceSeconds = 3600;
        });
        Services::injectMock('iceConfigService', new IceConfigService(Services::remoteConfig(), new LicenceResolver($this->central, new EntitlementVerifier(['central-test' => $this->centralPub]), new LicencePolicy(172800, ['paid'], true, true)), fn (): array => $this->licenceBlockers));

        $s = $this->sessionOn(511, 'REMOTE_FREE', 'unconfirmed');
        $this->central->put('customer', '511', 'remote', $this->centralEnvelope(511), time());
        $uid = (int) $s['session']['owner_user_id'];

        // Manage has never confirmed the owner's membership (verified_at is NULL): paid company, unverified member = not paid.
        $this->db->table('remote_user_company_access')->where('company_id', 511)->where('user_id', $uid)->update(['verified_at' => null]);
        $this->assertSame('membership_unverified', Services::iceConfigService()->licenceForSession($s['session'])->reason);

        // Confirmed just now: paid. Confirmed longer ago than the grace period: not confirmed again.
        $this->db->table('remote_user_company_access')->where('company_id', 511)->where('user_id', $uid)->update(['verified_at' => gmdate('Y-m-d H:i:s')]);
        $this->assertSame('paid', Services::iceConfigService()->licenceForSession($s['session'])->state->value);
        $this->db->table('remote_user_company_access')->where('company_id', 511)->where('user_id', $uid)->update(['verified_at' => gmdate('Y-m-d H:i:s', time() - 7200)]);
        $this->assertSame('membership_unverified', Services::iceConfigService()->licenceForSession($s['session'])->reason);
    }

    public function testASeatLicenceNeedsTheOwnerToHoldASeatAndOtherCompaniesSeatsDoNotCount(): void
    {
        $s = $this->sessionOn(512, 'REMOTE_FREE', 'seat');
        $owner = $this->ownerUuid($s);
        $seatCompany = $this->centralEnvelope(512, ['eligibility' => ['model' => 'seat', 'member' => null, 'seat' => 'not_applicable']]);
        $this->central->put('customer', '512', 'remote', $seatCompany, time());
        $this->assertSame('free', $this->iceFor($s)['admission']['tier'], 'a seat licence is not satisfied by membership alone');

        $assigned = $this->centralEnvelope(512, ['subject' => $owner, 'eligibility' => ['model' => 'seat', 'member' => true, 'seat' => 'assigned']]);
        $this->central->put('member', '512|' . $owner, 'remote', $assigned, time());
        $this->assertSame('paid', $this->iceFor($s)['admission']['tier']);
        $this->assertSame('seat', Services::iceConfigService()->licenceForSession($s['session'])->basis);

        // The same owner's seat in ANOTHER company (513) does nothing for this session, and revoking it here ends this one's.
        $other = $this->sessionOn(513, 'REMOTE_FREE', 'seat-other');
        $this->assertSame('free', $this->iceFor($other)['admission']['tier']);
        $this->central->revoke('512', 'remote', 3, time(), $owner);
        $this->assertSame('free', $this->iceFor($s)['admission']['tier'], 'seat revoked');
    }

    public function testAPersonalSessionWithNoCompanyIsFree(): void
    {
        $this->assertSame('free', Services::iceConfigService()->tierForSession(['company_id' => null])->value);
        $this->assertSame('no_customer', Services::iceConfigService()->licenceForSession(['company_id' => null])->reason);
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
        // The sessions (and so the companies' entitlement rows) exist before the first grant: the licence check that
        // lets `enforce` run reads them once, when the issuer is built.
        $free = $this->sessionOn(482, 'REMOTE_FREE', 'free');
        $paid = $this->sessionOn(481, 'REMOTE_BUSINESS', 'paid');

        // Each node: 6 allocations, 3 reserved for paid, 3 for free — one primary lease (3 URLs).
        $ice = Services::iceConfigService();
        foreach (['mum', 'ncr'] as $i => $node) {
            $ice->issuer()->issue("hog-{$i}", ['tier' => 'free', 'sessionRef' => "hog-{$i}", 'preferRegion' => $node === 'mum' ? 'in-west' : 'in-north', 'profile' => 'remote']);
        }
        $this->assertSame(3, $this->node('mum')['allocations']['reserved']['free']);
        $this->assertSame(3, $this->node('ncr')['allocations']['reserved']['free']);

        $deny = $this->iceFor($free);
        $this->assertFalse($deny['relayAvailable']);
        $this->assertSame('denied', $deny['admission']['outcome']);
        $this->assertSame('free_pool_full', $deny['admission']['reason']);
        $this->assertSame('', $this->turnUsername($deny), 'A refused client is handed no credential at all.');

        $ok = $this->iceFor($paid);
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
