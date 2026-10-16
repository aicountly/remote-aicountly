<?php

declare(strict_types=1);

namespace Tests\Audit;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\AuditTrailReader;
use App\Domain\Audit\EventType;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Policy\PermissionCatalog;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FakeIdentityResolver;
use Tests\Support\RemoteTestCase;

/**
 * The audit trail standard (A-F) for `remote_audit_logs`: what the writer
 * records, that nothing can rewrite it, that business mutations leave a row,
 * and that the read API is scoped, gated and exportable.
 *
 * @internal
 */
final class AuditTrailTest extends RemoteTestCase
{
    use FeatureTestTrait;

    private FakeIdentityResolver $identities;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identities = new FakeIdentityResolver(Services::portalClient(), $this->db);
        Services::injectMock('identityResolver', $this->identities);
    }

    /** @return array<string, string> */
    private function asUser(RemoteIdentity $identity, string $sesKey = 'audit-key'): array
    {
        $this->identities->register($sesKey, $identity);

        return ['Authorization' => 'Bearer ' . $sesKey];
    }

    /** @return array<string, mixed> */
    private function lastRow(string $event): array
    {
        $row = $this->db->table('remote_audit_logs')->where('event', $event)->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertNotNull($row, "Expected an audit row for {$event}.");

        return $row;
    }

    /** @return array{0: RemoteIdentity, 1: int} */
    private function adminInCompany(int $companyId = 481, string $name = 'Admin'): array
    {
        $admin   = $this->makeIdentity($name);
        $company = $this->makeCompany($companyId, 'ABC');
        $this->grantCompanyAccess($admin, $company, 'COMPANY_ADMIN', true);

        return [$admin, $company];
    }

    // ------------------------------------------------------------ B. writer

    public function testTheWriterRecordsActorEntityAndRequestContext(): void
    {
        $admin = $this->makeIdentity('Asha Rao');

        Services::auditService()->recordAudit(
            EventType::POLICY_UPDATED,
            $admin->id,
            'USER',
            481,
            null,
            null,
            null,
            ['changes' => []],
            ['type' => 'company_policy', 'id' => '481', 'label' => 'Custom'],
            'Changed Remote policy',
        );

        $row = $this->lastRow('POLICY_UPDATED');
        $this->assertSame($admin->uuid, $row['actor_uuid']);
        $this->assertSame('Asha Rao', $row['actor_label']);
        $this->assertSame('company_policy', $row['entity_type']);
        $this->assertSame('Custom', $row['entity_label']);
        $this->assertSame('Changed Remote policy', $row['summary']);
        $this->assertSame('security', $row['severity']);
        $this->assertSame(481, (int) $row['company_id']);
    }

    public function testASystemActorIsNamedAndASessionRowDerivesItsEntity(): void
    {
        $user    = $this->makeIdentity('Host');
        $session = $this->makeSession($user, 'PERSONAL');

        $row = $this->lastRow('SESSION_CREATED');
        $this->assertSame('session', $row['entity_type']);
        $this->assertSame($session['uuid'], $row['entity_id']);
        $this->assertSame('material', $row['severity']);
        $this->assertNotSame('', (string) $row['summary']);

        Services::auditService()->recordAudit(EventType::UNATTENDED_ACCESS_DISABLED, null, 'SYSTEM', 481);
        $this->assertSame('system:remote', $this->lastRow('UNATTENDED_ACCESS_DISABLED')['actor_uuid']);
    }

    public function testDiffSkipsUnchangedAndSecretFields(): void
    {
        $changes = AuditService::diff(
            ['name' => 'A', 'same' => 1, 'flag' => false, 'api_key' => 'old', 'password' => 'x', 'otp' => '1'],
            ['name' => 'B', 'same' => '1', 'flag' => true, 'api_key' => 'new', 'password' => 'y', 'otp' => '2'],
            ['name' => 'Display name'],
        );

        $this->assertSame(['name', 'flag'], array_column($changes, 'field'));
        $this->assertSame(['field' => 'name', 'label' => 'Display name', 'old' => 'A', 'new' => 'B'], $changes[0]);
        $this->assertFalse($changes[1]['old']);
        $this->assertTrue($changes[1]['new']);
    }

    public function testSecretMetadataNeverReachesTheTrail(): void
    {
        Services::auditService()->recordAudit(
            EventType::DEVICE_UPDATED,
            null,
            'SYSTEM',
            481,
            null,
            null,
            null,
            ['api_token' => 'abc', 'password' => 'p', 'kept' => 'yes'],
        );

        $metadata = (string) $this->lastRow('DEVICE_UPDATED')['metadata'];
        $this->assertStringNotContainsString('abc', $metadata);
        $this->assertStringContainsString('yes', $metadata);
    }

    public function testMaterialFailsClosedAndInfoStaysBestEffort(): void
    {
        $audit = Services::auditService();

        // An actor type the table's CHECK refuses stands in for "cannot be written".
        $audit->recordAudit('X_BEST_EFFORT', null, 'NOBODY', 481);
        $this->assertSame(0, $this->db->table('remote_audit_logs')->where('event', 'X_BEST_EFFORT')->countAllResults());

        $this->expectException(DatabaseException::class);
        $audit->recordMaterial('X_MATERIAL', null, 'NOBODY', 481);
    }

    public function testAFailedMaterialRowRollsTheBusinessChangeBack(): void
    {
        [$admin, $company] = $this->adminInCompany();
        $before = Services::policyResolver()->companyPolicy($company);

        // A policy change whose audit row cannot be written must not stand.
        $this->db->query('ALTER TABLE remote_audit_logs DISABLE TRIGGER remote_audit_logs_append_only');
        $this->db->query('ALTER TABLE remote_audit_logs ADD CONSTRAINT zz_refuse CHECK (event <> \'POLICY_UPDATED\') NOT VALID');

        try {
            $result = $this->withHeaders($this->asUser($admin))
                ->withBodyFormat('json')
                ->put("v1/remote/company/{$company}/policy", ['allowEntireMonitor' => true]);
            $failed = $result->response()->getStatusCode() >= 400;
        } catch (\Throwable) {
            $failed = true;
        } finally {
            while ($this->db->transDepth > 0) {
                $this->db->transRollback();
            }
            $this->db->query('ALTER TABLE remote_audit_logs DROP CONSTRAINT zz_refuse');
            $this->db->query('ALTER TABLE remote_audit_logs ENABLE TRIGGER remote_audit_logs_append_only');
        }

        // The request died with its transaction still open (the process would end
        // there in production); close it the way the connection closing would.
        while ($this->db->transDepth > 0) {
            $this->db->transRollback();
        }
        $this->db->resetTransStatus();

        $this->assertTrue($failed, 'The request must fail when its audit row cannot be written.');
        Services::reset(true);
        $after = Services::policyResolver()->companyPolicy($company);
        $this->assertSame($before['allow_entire_monitor'], $after['allow_entire_monitor']);
    }

    // ------------------------------------------------------- A. immutability

    public function testTheTrailRefusesUpdateAndDelete(): void
    {
        Services::auditService()->recordAudit(EventType::SESSION_CREATED, null, 'SYSTEM', 481);

        try {
            $this->db->query("UPDATE remote_audit_logs SET summary = 'rewritten'");
            $this->fail('UPDATE must be refused.');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $this->db->query('DELETE FROM remote_audit_logs');
            $this->fail('DELETE must be refused.');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame(1, $this->db->table('remote_audit_logs')->countAllResults());
    }

    // ----------------------------------------------------------- C. coverage

    public function testAPolicyChangeRecordsFieldChangesOldToNew(): void
    {
        [$admin, $company] = $this->adminInCompany();

        $this->withHeaders($this->asUser($admin))
            ->withBodyFormat('json')
            ->put("v1/remote/company/{$company}/policy", ['allowEntireMonitor' => true])
            ->assertStatus(200);

        $row      = $this->lastRow('POLICY_UPDATED');
        $metadata = json_decode((string) $row['metadata'], true);
        $byField  = array_column($metadata['changes'], null, 'field');

        $this->assertArrayHasKey('allow_entire_monitor', $byField);
        $this->assertFalse((bool) $byField['allow_entire_monitor']['old']);
        $this->assertTrue((bool) $byField['allow_entire_monitor']['new']);
        $this->assertSame($admin->uuid, $row['actor_uuid']);
        $this->assertSame('security', $row['severity']);
        $this->assertArrayHasKey('allow_entire_monitor', $metadata['after']);
    }

    public function testAPermissionChangeRecordsTheGrantAndWhoReceivedIt(): void
    {
        [$admin, $company] = $this->adminInCompany();
        $member = $this->makeIdentity('Meera Iyer');
        $this->grantCompanyAccess($member, $company);

        $this->withHeaders($this->asUser($admin))
            ->withBodyFormat('json')
            ->put("v1/remote/company/{$company}/permissions/{$member->uuid}", [
                'permissions' => [PermissionCatalog::MONITOR_SHARE => 'ALLOW'],
            ])->assertStatus(200);

        $row = $this->lastRow('PERMISSION_UPDATED');
        $this->assertSame('user', $row['entity_type']);
        $this->assertSame($member->uuid, $row['entity_id']);
        $this->assertSame('Meera Iyer', $row['entity_label']);

        $changes = json_decode((string) $row['metadata'], true)['changes'];
        $this->assertSame(PermissionCatalog::MONITOR_SHARE, $changes[0]['field']);
        $this->assertSame('INHERIT', $changes[0]['old']);
        $this->assertSame('ALLOW', $changes[0]['new']);
    }

    public function testDeviceEnrolUpdateAndRevokeLeaveRows(): void
    {
        $company = $this->makeDesktopCompany(481);
        $owner   = $this->makeIdentity('Owner');
        $this->grantCompanyAccess($owner, $company, 'COMPANY_ADMIN', true);

        $enrolled = $this->enrolDevice($owner, $company)['device'];
        $this->assertSame('Test Workstation', $this->lastRow('DEVICE_ENROLLED')['entity_label']);

        Services::deviceService()->update($owner, (string) $enrolled['uuid'], ['deviceName' => 'Front desk PC']);
        $changes = json_decode((string) $this->lastRow('DEVICE_UPDATED')['metadata'], true)['changes'];
        $this->assertSame('Test Workstation', $changes[0]['old']);
        $this->assertSame('Front desk PC', $changes[0]['new']);

        Services::deviceService()->revoke($owner, (string) $enrolled['uuid'], 'lost');
        $row = $this->lastRow('DEVICE_REVOKED');
        $this->assertSame('device', $row['entity_type']);
        $this->assertSame('security', $row['severity']);
    }

    public function testSessionFeedbackRecordsThatItWasLeftNotWhatItSaid(): void
    {
        [$admin, $company] = $this->adminInCompany();
        $session = $this->makeSession($admin, 'COMPANY', $company);

        $this->withHeaders($this->asUser($admin))
            ->withBodyFormat('json')
            ->post("v1/remote/sessions/{$session['uuid']}/feedback", ['resolution' => 'YES', 'rating' => 5, 'comments' => 'private words'])
            ->assertStatus(200);

        $metadata = (string) $this->lastRow('SESSION_FEEDBACK_RECORDED')['metadata'];
        $this->assertStringNotContainsString('private words', $metadata);
        $this->assertStringContainsString('"hasComment": true', $metadata);
    }

    // ------------------------------------------------------------ D. read API

    public function testTheListFiltersByEventEntityActorTextAndDate(): void
    {
        [$admin, $company] = $this->adminInCompany();
        $other   = $this->makeIdentity('Other Person');
        $session = $this->makeSession($admin, 'COMPANY', $company);
        $this->makeSession($other, 'PERSONAL');

        $this->withHeaders($this->asUser($admin))->withBodyFormat('json')
            ->put("v1/remote/company/{$company}/policy", ['allowEntireMonitor' => true]);

        $get = fn (string $query): array => json_decode(
            $this->withHeaders($this->asUser($admin))->get("v1/remote/company/{$company}/audit?{$query}")->getJSON(),
            true,
        );

        $all = $get('');
        $this->assertSame(2, $all['meta']['total']);

        $this->assertSame(['POLICY_UPDATED'], array_column($get('action=policy_updated')['data'], 'event'));
        $this->assertSame(['POLICY_UPDATED'], array_column($get('severity=security')['data'], 'event'));
        $this->assertSame(1, $get('entity_type=session&entity_id=' . $session['uuid'])['meta']['total']);
        $this->assertSame(2, $get('actor_uuid=' . $admin->uuid)['meta']['total']);
        $this->assertSame(0, $get('actor_uuid=nobody')['meta']['total']);
        $this->assertSame(1, $get('q=policy')['meta']['total']);
        $this->assertSame(2, $get('from=' . gmdate('Y-m-d') . '&to=' . gmdate('Y-m-d'))['meta']['total']);
        $this->assertSame(0, $get('to=2001-01-01')['meta']['total']);
        $this->assertSame(1, $get('limit=1')['meta']['total'] > 1 ? 1 : 0);
        $this->assertCount(1, $get('limit=1')['data']);
        $this->assertSame('Admin', $all['data'][0]['actorName']);
        $this->assertArrayHasKey('summary', $all['data'][0]);
    }

    public function testAnInvalidDateIsA400(): void
    {
        [$admin, $company] = $this->adminInCompany();

        $this->withHeaders($this->asUser($admin))
            ->get("v1/remote/company/{$company}/audit?from=not-a-date")
            ->assertStatus(400);
    }

    public function testTheTrailIsScopedToTheCompanyAndGatedByPermission(): void
    {
        [$admin, $company] = $this->adminInCompany(481);
        $this->makeCompany(482, 'Other Co');
        $outsider = $this->makeIdentity('Outsider');
        $this->grantCompanyAccess($outsider, 482, 'COMPANY_ADMIN', true);

        $this->makeSession($admin, 'COMPANY', $company);
        $this->makeSession($outsider, 'COMPANY', 482);

        foreach (['', '/export', '/entity/session/x'] as $suffix) {
            // Not a member of 481: refused outright.
            $this->withHeaders($this->asUser($outsider, 'outsider-key'))
                ->get("v1/remote/company/{$company}/audit{$suffix}")
                ->assertStatus(403);
        }

        $rows = json_decode($this->withHeaders($this->asUser($admin))->get("v1/remote/company/{$company}/audit")->getJSON(), true);
        $this->assertSame(1, $rows['meta']['total']);
        $this->assertSame(481, $rows['data'][0]['companyId']);

        $member = $this->makeIdentity('Plain Member');
        $this->grantCompanyAccess($member, $company);
        foreach (['', '/export', '/entity/session/x'] as $suffix) {
            $this->withHeaders($this->asUser($member, 'member-key'))
                ->get("v1/remote/company/{$company}/audit{$suffix}")
                ->assertStatus(403);
        }
    }

    public function testEntityHistoryReturnsOnlyThatRecordsRows(): void
    {
        [$admin, $company] = $this->adminInCompany();
        $one = $this->makeSession($admin, 'COMPANY', $company);
        $two = $this->makeSession($admin, 'COMPANY', $company);

        $body = json_decode(
            $this->withHeaders($this->asUser($admin))->get("v1/remote/company/{$company}/audit/entity/session/{$one['uuid']}")->getJSON(),
            true,
        );

        $this->assertSame(1, $body['meta']['total']);
        $this->assertSame($one['uuid'], $body['data'][0]['entityId']);
        $this->assertNotSame($two['uuid'], $body['data'][0]['entityId']);
    }

    public function testExportIsCsvFormulaSafeAndItselfRecorded(): void
    {
        [$admin, $company] = $this->adminInCompany(481, '=cmd|calc');
        $this->makeSession($admin, 'COMPANY', $company);

        $result = $this->withHeaders($this->asUser($admin))->get("v1/remote/company/{$company}/audit/export?action=session_created");
        $result->assertStatus(200);
        $this->assertStringContainsString('text/csv', $result->response()->getHeaderLine('Content-Type'));
        $this->assertSame('false', $result->response()->getHeaderLine('X-Audit-Export-Partial'));

        $csv = $result->response()->getBody();
        $this->assertStringContainsString('Summary', $csv);
        $this->assertStringContainsString('SESSION_CREATED', $csv);
        $this->assertStringContainsString("'=cmd|calc", $csv, 'A leading = must be neutralised.');
        $this->assertStringNotContainsString(',=cmd', $csv);

        $this->assertSame('AUDIT_EXPORTED', $this->lastRow('AUDIT_EXPORTED')['event']);
    }

    public function testCsvCellsAreNeutralised(): void
    {
        foreach (['=1+1', '+1', '-1', '@x', "\tx"] as $dangerous) {
            $this->assertSame("'" . $dangerous, AuditTrailReader::cell($dangerous));
        }
        $this->assertSame('plain', AuditTrailReader::cell('plain'));
        $this->assertSame('', AuditTrailReader::cell(null));
    }

    public function testAnExportOverTheCapIsFlaggedPartial(): void
    {
        [$admin, $company] = $this->adminInCompany();
        $this->db->query(
            "INSERT INTO remote_audit_logs (uuid, event, actor_type, company_id, summary)
             SELECT gen_random_uuid(), 'SESSION_CREATED', 'SYSTEM', ?, 'row ' || g FROM generate_series(1, ?) g",
            [$company, AuditTrailReader::EXPORT_CAP + 5],
        );

        $result = $this->withHeaders($this->asUser($admin))->get("v1/remote/company/{$company}/audit/export");
        $result->assertStatus(200);
        $this->assertSame('true', $result->response()->getHeaderLine('X-Audit-Export-Partial'));
        $this->assertStringContainsString('PARTIAL', $result->response()->getBody());
    }
}
