<?php

declare(strict_types=1);

namespace Tests\Lifecycle;

use Aicountly\Lifecycle\PurgeEngine;
use Aicountly\Lifecycle\PurgeManifest;
use Aicountly\Lifecycle\Signature;
use App\Domain\Auth\RemoteIdentity;
use App\Libraries\CompanyLifecycle;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FakeIdentityResolver;
use Tests\Support\RemoteTestCase;

/**
 * Manage's company lifecycle in Remote (lib/company-lifecycle, docs/COMPANY_LIFECYCLE.md).
 *
 * The reviewed manifest has no problems for the company and user scopes on the migrated schema (strict
 * mode: a migration that adds a table with company_id without listing it fails here), every allow-list
 * entry is a real route, the receiver takes no session, the write gate answers 423 after the company
 * check (403 first) while ending a session still works, companyinfo is written through, and a signed
 * company purge and account purge run end to end through the worker with their signed acks.
 */
final class CompanyLifecycleTest extends RemoteTestCase
{
    use FeatureTestTrait;

    private const SECRET = 'test-lifecycle-secret-0123456789abcdef0123';

    private FakeIdentityResolver $identities;

    protected function setUp(): void
    {
        parent::setUp();

        // The kit works on its own PDO connection. A transaction an earlier test left open on the suite's
        // connection (it then holds the TRUNCATE locks of RemoteTestCase::setUp) would block it, so it is
        // committed here; the suite's single connection never noticed such a transaction before.
        $this->db->simpleQuery('COMMIT');

        foreach (['LIFECYCLE_WEBHOOK_SECRET' => self::SECRET, 'MANAGE_API_URL' => 'https://manage.test.invalid', 'MANAGE_PRODUCT_SERVICE_KEY' => ''] as $k => $v) {
            putenv($k . '=' . $v);
            $_ENV[$k]    = $v;
            $_SERVER[$k] = $v;
        }
        CompanyLifecycle::resetForTesting();
        require_once ROOTPATH . 'lib/company-lifecycle/autoload.php';
        $this->db->query('DELETE FROM aic_lifecycle_acks');
        $this->db->query('DELETE FROM aic_lifecycle_jobs');
        $this->db->query('DELETE FROM aic_lifecycle_events');
        $this->db->query('DELETE FROM aic_lifecycle_state');

        $this->identities = new FakeIdentityResolver(Services::portalClient(), $this->db);
        Services::injectMock('identityResolver', $this->identities);
    }

    protected function tearDown(): void
    {
        foreach ($this->db->query("SELECT nspname FROM pg_namespace WHERE nspname LIKE 'aic\\_lifecycle\\_archive\\_%'")->getResultArray() as $row) {
            $this->db->query('DROP SCHEMA "' . $row['nspname'] . '" CASCADE');
        }
        CompanyLifecycle::resetForTesting();
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function asUser(RemoteIdentity $identity, string $sesKey): array
    {
        $this->identities->register($sesKey, $identity);

        return ['Authorization' => 'Bearer ' . $sesKey];
    }

    /** @param array<string, mixed> $event */
    private function send(array $event, bool $sign = true): \CodeIgniter\Test\TestResponse
    {
        $raw     = json_encode($event, JSON_THROW_ON_ERROR);
        $ts      = time();
        $headers = $sign ? ['X-Manage-Timestamp' => (string) $ts, 'X-Manage-Signature' => Signature::sign(self::SECRET, $ts, $raw)] : [];

        return $this->withHeaders($headers)->withBody($raw)->post('internal/manage/company-lifecycle');
    }

    /** @return array<string, mixed> */
    private function event(string $event, array $extra = []): array
    {
        static $n = 0;
        $n++;

        return ['event_id' => sprintf('%08x-0000-4000-8000-%012d', random_int(0, 0x7FFFFFFF), $n), 'event' => $event, 'app_key' => 'remote',
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 1000 + $n)] + $extra;
    }

    /** @param list<array<string, mixed>> $acks */
    private function runWorker(array &$acks): array
    {
        return CompanyLifecycle::build(['http' => static function (string $m, string $url, array $h, ?string $b) use (&$acks): array {
            $acks[] = json_decode((string) $b, true);

            return ['status' => 200, 'body' => '{}', 'error' => null];
        }])->worker()->run();
    }

    public function testTheReviewedManifestHasNoProblemsOnTheMigratedSchema(): void
    {
        $manifest = PurgeManifest::load(CompanyLifecycle::manifestPath());
        $this->assertSame('remote', $manifest->appKey());
        $engine = new PurgeEngine(CompanyLifecycle::pdo(), $manifest);
        $this->assertSame([], $engine->problems('company'), 'company scope');
        $this->assertSame([], $engine->problems('user'), 'user scope');
        $this->assertSame([], $manifest->warnings(), 'kit 1.1: nothing retained, so no period is missing');
        $this->assertSame(['redeemed_participant_id'], $manifest->breakCycle('public.remote_invitations'), 'the cycle is broken by break_cycle');
    }

    public function testKit11SchemaAndTheRestoredForeignKey(): void
    {
        $this->assertSame(
            hash_file('sha256', ROOTPATH . 'lib/company-lifecycle/schema-1.1.sql'),
            hash_file('sha256', APPPATH . 'Database/Sql/2026-10-16-000200_aic_company_lifecycle_1_1.sql'),
            'the kit\'s schema-1.1.sql, verbatim',
        );
        $this->assertTrue($this->db->tableExists('aic_lifecycle_expiries', false));
        $fk = $this->db->query("SELECT confdeltype, condeferrable, condeferred FROM pg_constraint WHERE conname = 'remote_invitations_redeemed_participant_id_fkey'")->getRowArray();
        $this->assertNotNull($fk, 'remote_invitations.redeemed_participant_id → remote_participants is back');
        $this->assertSame(['n', 't', 'f'], [$fk['confdeltype'], $fk['condeferrable'] === true || $fk['condeferrable'] === 't' ? 't' : 'f', $fk['condeferred'] === true || $fk['condeferred'] === 't' ? 't' : 'f'],
            'ON DELETE SET NULL, DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function testADeviceCredentialIsAMachineCallerWithNothingMachineAllowed(): void
    {
        $this->assertSame([], CompanyLifecycle::MACHINE_ALLOW);
        $this->send($this->event('company_restricted', ['cmp_id' => 7110, 'reason' => 'subscription_lapsed', 'restricted_until' => '2026-12-01T00:00:00Z']))->assertStatus(202);
        $refusal = CompanyLifecycle::refusal(7110, 'POST', '/v1/remote/devices/me/sessions/x1/join', true);
        $this->assertSame(423, $refusal[0] ?? null, 'a device joining a session of a read-only company');
        $this->assertSame('COMPANY_RESTRICTED', $refusal[1]['code'] ?? null);
        $this->assertNull(CompanyLifecycle::refusal(7110, 'POST', '/v1/remote/devices/me/presence', true), 'presence stays open (ALLOW)');
        $this->assertNull(CompanyLifecycle::refusal(7110, 'POST', '/internal/manage/company-lifecycle', true), 'Manage\'s event is always open to a machine');
    }

    public function testACompanyPurgeBreaksTheInvitationCycleAndARestoreRelinksIt(): void
    {
        $company = $this->makeCompany(7111, 'Cycle Co');
        $host    = $this->makeIdentity('Host');
        $this->grantCompanyAccess($host, $company, 'COMPANY_ADMIN', true);
        $session = $this->makeSession($host, 'COMPANY', $company);
        $participant = (int) $this->db->table('remote_participants')->where('session_id', $session['id'])->get()->getRow()->id;
        $this->db->query("INSERT INTO remote_invitations (uuid, session_id, token_hash, created_by_user_id, expires_at, redeemed_participant_id)
            VALUES (gen_random_uuid(), ?, repeat('a', 64), ?, now() + interval '1 day', ?)", [$session['id'], $host->id, $participant]);
        $invitation = (int) $this->db->table('remote_invitations')->where('session_id', $session['id'])->get()->getRow()->id;
        $this->db->table('remote_participants')->where('id', $participant)->update(['invitation_id' => $invitation]);

        $this->send($this->event('company_purge_requested', ['cmp_id' => $company, 'reason' => 'subscription_lapsed']))->assertStatus(202);
        $acks    = [];
        $summary = $this->runWorker($acks);
        $this->assertSame(1, $summary['purged'], json_encode($acks));
        $this->assertSame(0, $this->db->table('remote_invitations')->where('id', $invitation)->countAllResults());
        $archive = (string) $this->db->query("SELECT archive_name FROM aic_lifecycle_jobs WHERE kind = 'company' AND target_cmp_id = ?", [$company])->getRow()->archive_name;
        $this->assertSame($participant, (int) $this->db->query('SELECT redeemed_participant_id FROM "' . $archive . '".remote_invitations')->getRow()->redeemed_participant_id, 'archived before it was unlinked');

        $restore = CompanyLifecycle::build()->engine()->restore($archive, true);
        $this->assertTrue($restore['ok'], json_encode($restore));
        $this->assertSame($participant, (int) $this->db->table('remote_invitations')->where('id', $invitation)->get()->getRow()->redeemed_participant_id, 'the restore relinks it');
        $this->assertSame($invitation, (int) $this->db->table('remote_participants')->where('id', $participant)->get()->getRow()->invitation_id);
    }

    public function testEveryAllowListEntryMatchesARealRoute(): void
    {
        $routes = Services::routes();
        $routes->loadRoutes();
        foreach (CompanyLifecycle::ALLOW as $entry) {
            [$method, $glob] = preg_split('/\s+/', $entry, 2);
            $sample = trim(str_replace('*', 'x1', $glob), '/');
            $found  = false;
            foreach (array_keys($routes->getRoutes($method)) as $from) {
                $re = '#^' . str_replace(['(:segment)', '(:num)', '(:any)'], ['[^/]+', '[0-9]+', '.+'], $from) . '$#';
                if (preg_match($re, $sample) === 1) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, $entry . ' matches a route');
        }
        $this->assertArrayHasKey('internal/manage/company-lifecycle', $routes->getRoutes('POST'), 'the receiver route');
    }

    public function testTheReceiverTakesNoSession(): void
    {
        $this->send($this->event('company_restricted', ['company_id' => 1]), false)->assertStatus(401);
        $this->send(['app_key' => 'books'] + $this->event('company_restricted', ['cmp_id' => 7101]))->assertStatus(422);
        $result = $this->send($this->event('company_restricted', ['cmp_id' => 7101, 'reason' => 'subscription_lapsed', 'restricted_until' => '2026-12-01T00:00:00Z']));
        $result->assertStatus(202);
        $this->assertSame('applied', json_decode($result->getJSON(), true)['outcome']);
    }

    public function testARestrictedCompanyRefusesNewWorkAfterTheCompanyCheckButLetsASessionEnd(): void
    {
        $company = $this->makeCompany(7102, 'Restricted Co');
        $host    = $this->makeIdentity('Host');
        $this->grantCompanyAccess($host, $company, 'COMPANY_ADMIN', true);
        $session = $this->makeSession($host, 'COMPANY', $company);

        $this->send($this->event('company_restricted', ['cmp_id' => $company, 'reason' => 'subscription_lapsed', 'restricted_until' => '2026-12-01T00:00:00Z']))->assertStatus(202);

        $refused = $this->withHeaders($this->asUser($host, 'lc-host'))->withBodyFormat('json')->post('v1/remote/sessions', [
            'scopeType' => 'COMPANY', 'companyId' => $company, 'requestedShareMode' => 'SAFE_SHARE',
        ]);
        $refused->assertStatus(423);
        $body = json_decode($refused->getJSON(), true);
        $this->assertSame('COMPANY_RESTRICTED', $body['error']['code']);
        $this->assertSame('2026-12-01T00:00:00Z', $body['error']['details']['restricted_until']);

        $this->withHeaders($this->asUser($host, 'lc-host'))->get('v1/remote/bootstrap')->assertStatus(200);
        $policy = $this->withHeaders($this->asUser($host, 'lc-host'))->get('v1/remote/policy/effective?scopeType=COMPANY&companyId=' . $company);
        $this->assertSame('restricted', json_decode($policy->getJSON(), true)['data']['lifecycle']['lifecycle'], 'the SPA is told');

        $ended = $this->withHeaders($this->asUser($host, 'lc-host'))->withBodyFormat('json')->post('v1/remote/sessions/' . $session['uuid'] . '/end', []);
        $this->assertNotSame(423, $ended->response()->getStatusCode(), 'ending a session is allowed');

        $stranger = $this->makeIdentity('Stranger');
        $this->withHeaders($this->asUser($stranger, 'lc-stranger'))->withBodyFormat('json')->post('v1/remote/sessions', [
            'scopeType' => 'COMPANY', 'companyId' => $company, 'requestedShareMode' => 'SAFE_SHARE',
        ])->assertStatus(403);
    }

    public function testCompanyinfoIsTheSourceOfTruth(): void
    {
        CompanyLifecycle::syncFromCompanyInfo(7103, 200, (string) json_encode(['data' => ['comp_id' => 7103, 'lifecycle' => 'purge_pending']]));
        $this->assertSame('purge_pending', CompanyLifecycle::lifecycle(7103)['lifecycle']);
        CompanyLifecycle::syncFromCompanyInfo(7103, 200, (string) json_encode(['data' => ['comp_id' => 7103, 'lifecycle' => 'active']]));
        $this->assertSame('active', CompanyLifecycle::lifecycle(7103)['lifecycle']);
        CompanyLifecycle::syncFromCompanyInfo(7103, 200, (string) json_encode(['data' => ['comp_id' => 9, 'lifecycle' => 'restricted']]));
        $this->assertSame('active', CompanyLifecycle::lifecycle(7103)['lifecycle'], 'an answer about another company is not believed');
    }

    public function testASignedCompanyPurgeRemovesEverythingTheCompanyHasHere(): void
    {
        $company = $this->makeCompany(7104, 'Purged Co');
        $kept    = $this->makeCompany(7105, 'Kept Co');
        $host    = $this->makeIdentity('Host');
        $this->grantCompanyAccess($host, $company, 'COMPANY_ADMIN', true);
        $this->grantCompanyAccess($host, $kept, 'COMPANY_ADMIN', true);
        $this->makeSession($host, 'COMPANY', $company);
        $this->makeSession($host, 'COMPANY', $kept);

        $queued = $this->send($this->event('company_purge_requested', ['cmp_id' => $company, 'reason' => 'subscription_lapsed']));
        $this->assertSame('queued', json_decode($queued->getJSON(), true)['outcome']);
        $acks    = [];
        $summary = $this->runWorker($acks);
        $this->assertSame(1, $summary['purged']);
        $this->assertSame('purged', $acks[0]['status']);
        $this->assertSame([], $acks[0]['retained']);
        foreach (['remote_sessions', 'remote_company_policies', 'remote_user_company_access', 'remote_company_directory'] as $table) {
            $this->assertSame(0, $this->db->table($table)->where('company_id', $company)->countAllResults(), $table);
        }
        $this->assertSame(1, $this->db->table('remote_sessions')->where('company_id', $kept)->countAllResults(), 'another company untouched');
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) AS n FROM remote_participants p WHERE NOT EXISTS (SELECT 1 FROM remote_sessions s WHERE s.id = p.session_id)')->getRow()->n, 'no orphaned participants');
    }

    public function testASignedAccountPurgeRemovesThePersonsOwnDataAndKeepsTheCompanys(): void
    {
        $company = $this->makeCompany(7106, 'Co');
        $person  = $this->makeIdentity('Asha Rao', 'asha@example.test');
        $this->db->table('remote_identities')->where('id', $person->id)->update(['platform_uuid' => '1001']);
        $person = new RemoteIdentity($person->id, '1001', 'Asha Rao', 'asha@example.test', false, false);
        $this->grantCompanyAccess($person, $company);
        $this->makeSession($person, 'PERSONAL');
        $this->makeSession($person, 'COMPANY', $company);

        $this->send($this->event('account_purge_requested', ['uuid' => '1001', 'reason' => 'account_closed']))->assertStatus(202);
        $acks    = [];
        $summary = $this->runWorker($acks);
        $this->assertSame(1, $summary['purged']);
        $this->assertSame('1001', $acks[0]['uuid']);
        $this->assertStringContainsString('1 personal sessions deleted', (string) $acks[0]['detail']);
        $this->assertSame(0, $this->db->table('remote_sessions')->where('scope_type', 'PERSONAL')->where('owner_user_id', $person->id)->countAllResults(), 'personal session gone');
        $this->assertSame(1, $this->db->table('remote_sessions')->where('company_id', $company)->countAllResults(), 'the company session stays');
        $row = $this->db->table('remote_identities')->where('id', $person->id)->get()->getRowArray();
        $this->assertSame('Former AICOUNTLY user', $row['display_name']);
        $this->assertNull($row['email']);

        // Kit 1.1: everything the hook deleted or changed was archived first, so a restore puts it back.
        $archive = (string) $this->db->query("SELECT archive_name FROM aic_lifecycle_jobs WHERE kind = 'account' ORDER BY id DESC LIMIT 1")->getRow()->archive_name;
        $this->assertStringContainsString('archived first', (string) $acks[0]['detail']);
        $restore = CompanyLifecycle::build()->engine()->restore($archive, true);
        $this->assertTrue($restore['ok'], json_encode($restore));
        $this->assertSame(1, $this->db->table('remote_sessions')->where('scope_type', 'PERSONAL')->where('owner_user_id', $person->id)->countAllResults(), 'the personal session is back');
        $this->assertGreaterThan(0, $this->db->query("SELECT COUNT(*) AS n FROM remote_participants p JOIN remote_sessions s ON s.id = p.session_id WHERE s.scope_type = 'PERSONAL' AND s.owner_user_id = ?", [$person->id])->getRow()->n, 'with its participants');
        $row = $this->db->table('remote_identities')->where('id', $person->id)->get()->getRowArray();
        $this->assertSame(['Asha Rao', 'asha@example.test'], [$row['display_name'], $row['email']], 'and the identity as it was');
    }
}
