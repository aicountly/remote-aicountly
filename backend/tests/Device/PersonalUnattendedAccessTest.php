<?php

declare(strict_types=1);

namespace Tests\Device;

use App\Database\Migrations\EntitleUnattendedAccessByDefault;
use App\Database\Seeds\RemotePlatformDefaultsSeeder;
use App\Domain\Audit\EventType;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Session\ControlService;
use App\Domain\Support\ApiException;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use Tests\Support\DeviceApiRequests;
use Tests\Support\RemoteTestCase;

/**
 * Unattended access to a person's own computer — the AnyDesk-style "reach my
 * own PC" — and everything that still stands between it and anybody else.
 *
 * Personal scope permits it, Basic entitles it and a personal account holds
 * the permission. None of that reaches a machine: each computer stays
 * unreachable until somebody at it turns unattended access on and confirms it,
 * and only its own owner can ever connect to it.
 *
 * @internal
 */
final class PersonalUnattendedAccessTest extends RemoteTestCase
{
    use FeatureTestTrait;
    use DeviceApiRequests;

    /** The ordinary case, through the API: switch it on with confirmation, then connect from elsewhere. */
    public function testTheOwnerSwitchesItOnForTheirOwnComputerAndConnectsWithNobodyThere(): void
    {
        $owner    = $this->makeIdentity('Meera');
        $enrolled = $this->enrolDevice($owner, null, ['deviceName' => 'Meera home PC']);
        $uuid     = (string) $enrolled['device']['uuid'];
        $this->markDeviceOnline($uuid);

        $listing = $this->dataOf($this->getJson($this->asPerson($owner), 'devices'));
        $this->assertTrue($listing['canConnectUnattended'], 'the Computers page may offer it');
        $this->assertTrue($listing['policy']['allowUnattendedAccess']);
        $this->assertFalse($listing['policy']['allowDeviceReboot'], 'restarting stays off');

        // Nothing is switched on by registering: the computer's own switch is off.
        $this->assertFalse($listing['devices'][0]['unattendedAccessEnabled']);

        $enabled = $this->dataOf($this->postJson($this->asPerson($owner), "devices/{$uuid}/unattended/enable", ['confirm' => true]));
        $this->assertTrue($enabled['device']['unattendedAccessEnabled']);
        $this->assertHasAudit(EventType::UNATTENDED_ACCESS_ENABLED);

        $connected = $this->dataOf($this->postJson($this->asPerson($owner), "devices/{$uuid}/connect", ['issueSummary' => 'From the office']), 201);

        $this->assertSame('PERSONAL', $connected['session']['scopeType']);
        $this->assertNull($connected['session']['companyId']);
        $this->assertSame('ENTIRE_MONITOR', $connected['session']['requestedShareMode']);
        $this->assertSame('DESKTOP_AGENT', $connected['host']['clientType']);

        // Unattended sessions keep granting control with the session, as they always have.
        $this->assertSame(ControlService::STATE_GRANTED, $connected['participant']['controlState']);

        $session = Services::sessionService()->findByUuidOrFail((string) $connected['session']['uuid']);
        $this->assertSame('UNATTENDED', $session['access_mode']);
        $this->assertHasEvent($session, EventType::UNATTENDED_SESSION_STARTED);
        $this->assertHasEvent($session, EventType::CONTROL_GRANTED);

        // The computer finds the request waiting for it.
        $me = $this->dataOf($this->getJson($this->asDevice($enrolled), 'devices/me'));
        $this->assertTrue($me['policy']['allowUnattendedAccess']);
        $this->assertSame([(string) $connected['session']['uuid']], array_column($me['pendingSessions'], 'uuid'));
    }

    /** The confirmation still has to be given, and it says who could connect: you, not "a colleague". */
    public function testSwitchingItOnStillNeedsTheConfirmationWordedForAPerson(): void
    {
        $owner = $this->makeIdentity('Meera');
        $uuid  = (string) $this->enrolDevice($owner, null)['device']['uuid'];

        $response = $this->postJson($this->asPerson($owner), "devices/{$uuid}/unattended/enable", []);
        $this->assertSame('UNATTENDED_CONFIRMATION_REQUIRED', $this->errorOf($response, 400));

        $message = (string) json_decode((string) $response->getJSON(), true)['error']['message'];
        $this->assertStringContainsString('lets you connect to this computer', $message);
        $this->assertStringNotContainsStringIgnoringCase('colleague', $message);
        $this->assertStringNotContainsStringIgnoringCase('organisation', $message);

        $this->assertFalse(Services::deviceService()->findByUuidOrFail($uuid)['unattended_access_enabled']);
    }

    /**
     * Somebody else's computer, even switched on: not found, whichever way they ask — including a person a
     * platform-wide rule handed `remote.device.manage`, which means an organisation's devices and none of a
     * person's own.
     */
    public function testAStrangerCanNeitherSwitchItOnNorConnect(): void
    {
        $owner    = $this->makeIdentity('Meera');
        $stranger = $this->makeIdentity('Stranger');
        $uuid     = (string) $this->enrolDevice($owner, null)['device']['uuid'];
        $this->markDeviceOnline($uuid);

        $this->errorOf($this->postJson($this->asPerson($stranger), "devices/{$uuid}/unattended/enable", ['confirm' => true]), 404);

        Services::deviceService()->enableUnattended($owner, $uuid, true);

        $this->errorOf($this->postJson($this->asPerson($stranger), "devices/{$uuid}/connect"), 404);

        $this->setRolePermission(null, 'MEMBER', PermissionCatalog::DEVICE_MANAGE, 'ALLOW');
        $this->assertTrue(
            Services::policyResolver()->resolve($stranger, 'PERSONAL', null)->can(PermissionCatalog::DEVICE_MANAGE),
            'the rule does reach the stranger\'s own policy',
        );

        $this->errorOf($this->getJson($this->asPerson($stranger), "devices/{$uuid}"), 404);
        $this->errorOf($this->postJson($this->asPerson($stranger), "devices/{$uuid}/unattended/disable"), 404);
        $this->errorOf($this->postJson($this->asPerson($stranger), "devices/{$uuid}/revoke"), 404);
        $this->errorOf($this->postJson($this->asPerson($stranger), "devices/{$uuid}/connect"), 404);

        $this->assertSame(0, $this->db->table('remote_sessions')->countAllResults(), 'no session was created for anybody');
        $this->assertTrue(Services::deviceService()->findByUuidOrFail($uuid)['unattended_access_enabled'], 'and nothing the stranger did changed it');
    }

    /** Restarting a personal computer stays off — and is refused without inventing an organisation. */
    public function testRebootStaysOffForAPersonalComputer(): void
    {
        $owner = $this->makeIdentity('Meera');
        $uuid  = (string) $this->enrolDevice($owner, null)['device']['uuid'];
        Services::deviceService()->enableUnattended($owner, $uuid, true);
        $this->markDeviceOnline($uuid);
        $session = Services::deviceSessionService()->startUnattended($owner, $uuid)['session'];

        try {
            Services::devicePresenceService()->requestReboot($owner, $uuid, (string) $session['uuid']);
            $this->fail('A personal computer must not be restarted remotely.');
        } catch (ApiException $exception) {
            $this->assertSame('DEVICE_REBOOT_NOT_ALLOWED', $exception->errorCode());
            $this->assertStringNotContainsStringIgnoringCase('organisation', $exception->getMessage());
        }
    }

    /**
     * A platform-wide entitlement stored before this change says no, and it is read before the built-in
     * Basic — so the refusal must be worded for a person, and the migration must bring the row up to date.
     */
    public function testAStoredPlatformEntitlementStillDecidesAndTheMigrationBringsItUpToDate(): void
    {
        $owner = $this->makeIdentity('Meera');
        $this->setEntitlement(null, ['plan_code' => 'REMOTE_BASIC', 'desktop_devices' => true, 'unattended_access' => false]);

        // A company that was given its own entitlement keeps it.
        $this->makeCompany(4100, 'Own Plan Co');
        $this->setEntitlement(4100, ['desktop_devices' => true, 'unattended_access' => false]);

        $policy = Services::policyResolver()->resolve($owner, 'PERSONAL', null);
        $this->assertFalse($policy->allowUnattendedAccess);
        $this->assertContains('UNATTENDED_ACCESS_NOT_ENTITLED', $policy->restrictions);

        $uuid = (string) $this->enrolDevice($owner, null)['device']['uuid'];

        foreach ([
            fn () => Services::deviceService()->enableUnattended($owner, $uuid, true),
            fn () => Services::deviceSessionService()->startUnattended($owner, $uuid),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The stored entitlement says no.');
            } catch (ApiException $exception) {
                $this->assertSame('UNATTENDED_ACCESS_NOT_ALLOWED', $exception->errorCode());
                $this->assertSame('Unattended access is not available for this account.', $exception->getMessage());
            }
        }

        // A migration's file name carries its date, so it is not autoloadable; the runner includes it the same way.
        include_once APPPATH . 'Database/Migrations/2026-10-31-000100_EntitleUnattendedAccessByDefault.php';
        (new EntitleUnattendedAccessByDefault(Database::forge($this->db)))->up();

        $this->assertSame('t', (string) $this->db->query('SELECT unattended_access FROM remote_entitlements WHERE company_id IS NULL')->getRow()->unattended_access);
        $this->assertSame('f', (string) $this->db->query('SELECT unattended_access FROM remote_entitlements WHERE company_id = 4100')->getRow()->unattended_access);

        // The resolver reads the entitlement on every call, so the very next one has it.
        $this->assertTrue(Services::policyResolver()->resolve($owner, 'PERSONAL', null)->allowUnattendedAccess);
        $this->assertTrue(Services::deviceService()->enableUnattended($owner, $uuid, true)['unattended_access_enabled']);
    }

    /** The seeder's row is Basic as the code defines it, and a second run changes nothing. */
    public function testThePlatformDefaultsSeederEntitlesUnattendedAccess(): void
    {
        $seeder = new RemotePlatformDefaultsSeeder(config(Database::class), $this->db);
        $seeder->run();
        $seeder->run();

        $rows = $this->db->query('SELECT desktop_devices, unattended_access FROM remote_entitlements WHERE company_id IS NULL')->getResultArray();

        $this->assertCount(1, $rows);
        $this->assertSame('t', (string) $rows[0]['desktop_devices']);
        $this->assertSame('t', (string) $rows[0]['unattended_access']);
    }

    /**
     * Company scope is as it was: Basic entitling unattended access switches nothing on in an organisation,
     * because every preset leaves `allow_unattended_access` off — and an organisation holds no
     * `remote.unattended.access` by default either.
     */
    public function testCompanyScopeIsUnchanged(): void
    {
        $member  = $this->makeIdentity('Member');
        $company = $this->makeCompany(4200, 'Standard Co', ['allow_remote_control' => true]);
        $this->setEntitlement($company, ['desktop_devices' => true, 'unattended_access' => true]);
        $this->grantCompanyAccess($member, $company, 'MEMBER', true);

        $policy = Services::policyResolver()->resolve($member, 'COMPANY', $company);

        $this->assertTrue($policy->allowRemoteControl);
        $this->assertFalse($policy->allowUnattendedAccess, 'the organisation has not switched it on');
        $this->assertFalse($policy->can(PermissionCatalog::UNATTENDED_ACCESS));

        $withSwitch = $this->makeDesktopCompany(4300, 'Switched On Co');
        $this->grantCompanyAccess($member, $withSwitch, 'MEMBER', true);

        $this->assertFalse(
            Services::policyResolver()->resolve($member, 'COMPANY', $withSwitch)->can(PermissionCatalog::UNATTENDED_ACCESS),
            'an organisation still grants the permission deliberately',
        );
    }
}
