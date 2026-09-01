<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Auth\RemoteIdentity;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Remote as RemoteConfig;
use Config\Services;

/**
 * Base for every Remote test that touches the database.
 *
 * Migrations run **once** for the whole suite and the tables are truncated
 * between tests. The alternative — rolling back and re-migrating per test —
 * costs a second a test against Postgres for no extra confidence, since the
 * migrations themselves are exercised by the first run either way.
 *
 * Each test builds only the rows it needs through the helpers below, so a test
 * that fails says something about the behaviour rather than about a shared
 * fixture two other tests also depend on.
 */
abstract class RemoteTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    /** Tables emptied before each test, children first so the FKs are happy. */
    private const TABLES = [
        'remote_session_feedback',
        'remote_recordings',
        'remote_file_transfers',
        'remote_messages',
        'remote_session_events',
        'remote_audit_logs',
        'remote_invitations',
        'remote_participants',
        'remote_sessions',
        'remote_support_requests',
        'remote_context_tokens',
        'remote_devices',
        'remote_user_permissions',
        'remote_role_permissions',
        'remote_entitlements',
        'remote_company_policies',
        'remote_user_company_access',
        'remote_company_directory',
        'remote_identities',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->query('TRUNCATE ' . implode(', ', self::TABLES) . ' RESTART IDENTITY CASCADE');
        $this->db->query('ALTER SEQUENCE remote_session_display_seq RESTART WITH 10001');

        // Every shared service caches a connection and a config; a stale one
        // from the previous test would silently answer with the previous
        // test's configuration.
        Services::reset(true);

        // The rate limiter's buckets live in the file cache and outlive a
        // single test, so a test that exhausts one would otherwise start the
        // next one already throttled.
        cache()->clean();

        $this->configureRemote(static function (RemoteConfig $config): void {
            $config->contextSecret     = 'test-context-secret';
            $config->signallingSecret  = 'test-signalling-secret';
            $config->appUrl            = 'https://remote.aicountly.test';
            $config->signalUrl         = 'wss://remote.aicountly.test/signal';
        });
    }

    /**
     * Replace the shared Remote config for this test.
     *
     * @param callable(RemoteConfig): void $mutate
     */
    protected function configureRemote(callable $mutate): RemoteConfig
    {
        $config = new RemoteConfig();
        $mutate($config);

        Services::injectMock('remoteConfig', $config);

        // Anything already built from the old config has to go, or a service
        // resolved earlier in the test keeps the previous secret.
        foreach ([
            'policyResolver', 'sessionService', 'joinService', 'invitationService',
            'supportRequestService', 'signallingTokenService', 'iceConfigService',
            'sourceContextVerifier', 'platformDirectory', 'portalClient',
        ] as $service) {
            Services::injectMock($service, null);
        }

        return $config;
    }

    // ---------------------------------------------------------------- people

    protected function makeIdentity(
        string $name = 'Test User',
        ?string $email = null,
        bool $isSupportAgent = false,
        bool $isPlatformAdmin = false,
    ): RemoteIdentity {
        $uuid = 'uuid-' . bin2hex(random_bytes(6));

        $row = $this->db->query(
            'INSERT INTO remote_identities (platform_uuid, display_name, email, is_support_agent, is_platform_admin, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW()) RETURNING id',
            [$uuid, $name, $email ?? strtolower(str_replace(' ', '.', $name)) . '@example.test', $isSupportAgent, $isPlatformAdmin],
        )->getRowArray();

        return new RemoteIdentity((int) $row['id'], $uuid, $name, $email, $isSupportAgent, $isPlatformAdmin);
    }

    // ------------------------------------------------------------- companies

    /**
     * A company with a policy. `$policy` overrides individual columns, so a
     * test states only the switch it cares about.
     *
     * @param array<string, bool|int|string> $policy
     */
    protected function makeCompany(int $companyId, string $name = 'Test Company', array $policy = []): int
    {
        $this->db->query(
            'INSERT INTO remote_company_directory (company_id, name, synced_at, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW(), NOW()) ON CONFLICT (company_id) DO NOTHING',
            [$companyId, $name],
        );

        $this->db->query(
            'INSERT INTO remote_company_policies (company_id) VALUES (?) ON CONFLICT (company_id) DO NOTHING',
            [$companyId],
        );

        if ($policy !== []) {
            $this->db->table('remote_company_policies')->where('company_id', $companyId)->update($policy);
        }

        return $companyId;
    }

    protected function grantCompanyAccess(
        RemoteIdentity $identity,
        int $companyId,
        string $roleKey = 'MEMBER',
        bool $isAdmin = false,
    ): void {
        $this->db->query(
            'INSERT INTO remote_user_company_access (user_id, company_id, role_key, is_company_admin, source, synced_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, \'SEED\', NOW(), NOW(), NOW())
             ON CONFLICT (user_id, company_id) DO UPDATE SET role_key = EXCLUDED.role_key, is_company_admin = EXCLUDED.is_company_admin',
            [$identity->id, $companyId, $roleKey, $isAdmin],
        );
    }

    // ----------------------------------------------------------- permissions

    protected function setUserPermission(RemoteIdentity $identity, ?int $companyId, string $permission, string $effect): void
    {
        $this->db->query(
            'INSERT INTO remote_user_permissions (company_id, user_id, permission, effect, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())
             ON CONFLICT (COALESCE(company_id, 0), user_id, permission) DO UPDATE SET effect = EXCLUDED.effect',
            [$companyId, $identity->id, $permission, $effect],
        );
    }

    protected function setRolePermission(?int $companyId, string $roleKey, string $permission, string $effect): void
    {
        $this->db->query(
            'INSERT INTO remote_role_permissions (company_id, role_key, permission, effect, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())
             ON CONFLICT (COALESCE(company_id, 0), role_key, permission) DO UPDATE SET effect = EXCLUDED.effect',
            [$companyId, $roleKey, $permission, $effect],
        );
    }

    /** @param array<string, bool|int|null> $values */
    protected function setEntitlement(?int $companyId, array $values): void
    {
        $columns = array_merge([
            'plan_code'                    => 'REMOTE_TEST',
            'max_monthly_sessions'         => null,
            'max_session_duration_minutes' => null,
            'external_guests'              => true,
            'recording'                    => false,
            'file_transfer'                => true,
            'advanced_audit'               => true,
            'desktop_devices'              => false,
            'unattended_access'            => false,
        ], $values);

        $this->db->table('remote_entitlements')->insert(array_merge(['company_id' => $companyId], $columns));
    }

    // -------------------------------------------------------------- sessions

    /**
     * A session created through the real service, so every test exercises the
     * same validation, snapshotting and audit path the API does.
     *
     * @param  array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function makeSession(RemoteIdentity $identity, string $scopeType = 'PERSONAL', ?int $companyId = null, array $input = []): array
    {
        $policy = Services::policyResolver()->resolve($identity, $scopeType, $companyId);

        return Services::sessionService()->create($identity, array_merge([
            'scopeType' => $scopeType,
            'companyId' => $companyId,
        ], $input), $policy);
    }

    /** @param array<string, mixed> $session */
    protected function reload(array $session): array
    {
        return Services::sessionService()->findByUuidOrFail((string) $session['uuid']);
    }

    /** @param array<string, mixed> $session */
    protected function assertHasEvent(array $session, string $eventType, string $message = ''): void
    {
        $count = $this->db->table('remote_session_events')
            ->where('session_id', $session['id'])
            ->where('event_type', $eventType)
            ->countAllResults();

        $this->assertGreaterThan(0, $count, $message !== '' ? $message : "Expected a {$eventType} event on the session timeline.");
    }

    protected function assertHasAudit(string $event, string $message = ''): void
    {
        $count = $this->db->table('remote_audit_logs')->where('event', $event)->countAllResults();

        $this->assertGreaterThan(0, $count, $message !== '' ? $message : "Expected a {$event} entry in the audit log.");
    }
}
