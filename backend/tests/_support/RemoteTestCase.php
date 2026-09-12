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
        'remote_device_challenges',
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
            'fileTransferService', 'controlService',
            'deviceService', 'deviceAuthenticationService', 'deviceSessionService',
            'devicePresenceService',
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

    // --------------------------------------------------------------- devices

    /**
     * An Ed25519 keypair, as the desktop agent would generate one locally.
     *
     * The test holds both halves because it has to *be* the agent; nothing in
     * the product ever does. `publicKey` is base64 of the raw 32 bytes, which
     * is the one form `DeviceSignature` accepts.
     *
     * @return array{publicKey: string, secretKey: string}
     */
    protected function makeDeviceKeypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'publicKey' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secretKey' => sodium_crypto_sign_secretkey($pair),
        ];
    }

    /**
     * Sign a challenge exactly as `remote-security`'s `sign_challenge()` does
     * in the agent — over the canonical payload, never over JSON.
     */
    protected function signChallenge(string $secretKey, string $deviceUuid, string $nonce, int $issuedAt): string
    {
        return base64_encode(sodium_crypto_sign_detached(
            \App\Domain\Device\DeviceSignature::challengePayload($deviceUuid, $nonce, $issuedAt),
            $secretKey,
        ));
    }

    /**
     * A device enrolled through the real service, so every test exercises the
     * same permission, uniqueness and audit path the API does.
     *
     * @param  array<string, mixed> $input
     * @return array{device: array<string, mixed>, publicKey: string, secretKey: string}
     */
    protected function enrolDevice(RemoteIdentity $identity, int $companyId, array $input = []): array
    {
        $keys   = $this->makeDeviceKeypair();
        $device = Services::deviceService()->enrol($identity, $companyId, array_merge([
            'deviceName'      => 'Test Workstation',
            'publicKey'       => $keys['publicKey'],
            'operatingSystem' => 'Windows',
            'osVersion'       => '11 24H2',
            'architecture'    => 'x86_64',
            'hostname'        => 'WS-TEST-01',
            'agentVersion'    => '1.0.0',
            'capabilities'    => \App\Domain\Session\ClientCapabilities::desktopAgent(),
        ], $input));

        return ['device' => $device, 'publicKey' => $keys['publicKey'], 'secretKey' => $keys['secretKey']];
    }

    /**
     * A company whose plan and policy permit the desktop capabilities.
     *
     * Everything desktop defaults OFF, in the entitlement and in the policy, so
     * a test that wants remote control has to say so — which is the point.
     *
     * @param array<string, bool> $policy
     */
    protected function makeDesktopCompany(
        int $companyId,
        string $name = 'Desktop Company',
        array $policy = [],
        bool $unattendedEntitlement = true,
    ): int {
        $switches = array_merge([
            'allow_remote_control'    => true,
            'allow_unattended_access' => true,
            'allow_clipboard_sync'    => true,
            'allow_device_reboot'     => true,
        ], $policy);

        // Unattended access and reboot depend on remote control, and the table
        // says so with a CHECK. A test that switches control off is asking for
        // the whole group off, not for a row the database will refuse.
        if ($switches['allow_remote_control'] === false) {
            $switches['allow_unattended_access'] = false;
            $switches['allow_clipboard_sync']    = false;
            $switches['allow_device_reboot']     = false;
        }

        $this->makeCompany($companyId, $name, $switches);

        $this->setEntitlement($companyId, [
            'desktop_devices'   => true,
            'unattended_access' => $unattendedEntitlement,
        ]);

        return $companyId;
    }

    /** Pretend the agent reported in just now, so the device counts as online. */
    protected function markDeviceOnline(string $deviceUuid): void
    {
        $this->db->table('remote_devices')->where('uuid', $deviceUuid)->update([
            'presence_state' => 'ONLINE',
            'last_seen_at'   => \App\Domain\Support\Clock::now(),
        ]);
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
