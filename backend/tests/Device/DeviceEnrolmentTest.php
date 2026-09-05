<?php

declare(strict_types=1);

namespace Tests\Device;

use App\Domain\Audit\EventType;
use App\Domain\Device\DeviceService;
use App\Domain\Device\DeviceSignature;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Support\ApiException;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * Enrolling a device, and every way it must refuse to.
 *
 * @internal
 */
final class DeviceEnrolmentTest extends RemoteTestCase
{
    public function testAnAuthorisedUserEnrolsADeviceIntoTheirCompany(): void
    {
        $user    = $this->makeIdentity('Nadia Rahman');
        $company = $this->makeDesktopCompany(700, 'Northwind');
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);
        $this->setUserPermission($user, $company, PermissionCatalog::DEVICE_ENROL, 'ALLOW');

        ['device' => $device, 'publicKey' => $publicKey] = $this->enrolDevice($user, $company);

        $this->assertSame(DeviceService::STATUS_ACTIVE, $device['status']);
        $this->assertSame($company, (int) $device['company_id']);
        $this->assertSame($user->id, (int) $device['user_id']);
        $this->assertSame($user->id, (int) $device['enrolled_by_user_id']);
        $this->assertSame('ED25519', $device['key_algorithm']);
        $this->assertSame(
            DeviceSignature::fingerprint($publicKey),
            $device['public_key_fingerprint'],
        );

        // Off until somebody deliberately turns it on, whatever the plan and
        // the policy allow.
        $this->assertFalse($device['unattended_access_enabled']);

        $this->assertHasAudit(EventType::DEVICE_ENROLLED);
    }

    public function testEnrolmentIsRefusedWithoutThePermission(): void
    {
        $user    = $this->makeIdentity('Unprivileged');
        $company = $this->makeDesktopCompany(701);
        $this->grantCompanyAccess($user, $company);
        $this->setUserPermission($user, $company, PermissionCatalog::DEVICE_ENROL, 'DENY');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('You do not have permission to register a device');

        $this->enrolDevice($user, $company);
    }

    /**
     * The plan gate. `remote_entitlements.desktop_devices` defaults false, and
     * the resolver masks `remote.device.enrol` off when it is — so an
     * organisation that has not bought desktop agents cannot register one even
     * with an administrator doing the clicking.
     */
    public function testEnrolmentIsRefusedWhenThePlanDoesNotIncludeDesktopDevices(): void
    {
        $user    = $this->makeIdentity('Admin');
        $company = $this->makeCompany(702, 'No Desktop Plan', ['allow_remote_control' => true]);
        $this->setEntitlement($company, ['desktop_devices' => false]);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('You do not have permission to register a device');

        $this->enrolDevice($user, $company);
    }

    /**
     * Tenant isolation: the resolver refuses a company this person has no
     * membership in before it reads a single capability.
     */
    public function testEnrolmentIsRefusedForACompanyTheUserIsNotIn(): void
    {
        $user  = $this->makeIdentity('Outsider');
        $mine  = $this->makeDesktopCompany(703, 'Mine');
        $other = $this->makeDesktopCompany(704, 'Somebody Else');
        $this->grantCompanyAccess($user, $mine, 'MEMBER', true);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('You do not have access to this organisation');

        $this->enrolDevice($user, $other);
    }

    /**
     * Two devices presenting the same key would both pass the same signature
     * check, so the key identifies exactly one device platform-wide.
     */
    public function testAPublicKeyCannotBeEnrolledTwiceInDifferentCompanies(): void
    {
        $alice   = $this->makeIdentity('Alice');
        $bob     = $this->makeIdentity('Bob');
        $first   = $this->makeDesktopCompany(705, 'First');
        $second  = $this->makeDesktopCompany(706, 'Second');
        $this->grantCompanyAccess($alice, $first, 'MEMBER', true);
        $this->grantCompanyAccess($bob, $second, 'MEMBER', true);

        ['publicKey' => $publicKey] = $this->enrolDevice($alice, $first);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('already registered');

        Services::deviceService()->enrol($bob, $second, [
            'deviceName' => 'Cloned Key',
            'publicKey'  => $publicKey,
        ]);
    }

    /**
     * The same machine re-running enrolment — an agent upgrade, or a reinstall
     * that kept its key — updates its row rather than creating a second one.
     */
    public function testReEnrollingTheSameKeyIsIdempotent(): void
    {
        $user    = $this->makeIdentity('Repeat');
        $company = $this->makeDesktopCompany(707);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);

        ['device' => $first, 'publicKey' => $publicKey] = $this->enrolDevice($user, $company);

        $second = Services::deviceService()->enrol($user, $company, [
            'deviceName'   => 'Renamed Workstation',
            'publicKey'    => $publicKey,
            'agentVersion' => '1.1.0',
        ]);

        $this->assertSame($first['uuid'], $second['uuid']);
        $this->assertSame('Renamed Workstation', $second['device_name']);
        $this->assertSame('1.1.0', $second['agent_version']);
        $this->assertSame(1, $this->db->table('remote_devices')->countAllResults());
    }

    public function testARevokedDeviceCannotSimplyReEnrolWithTheSameKey(): void
    {
        $user    = $this->makeIdentity('Revoker');
        $company = $this->makeDesktopCompany(708);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);

        ['device' => $device, 'publicKey' => $publicKey] = $this->enrolDevice($user, $company);
        Services::deviceService()->revoke($user, (string) $device['uuid'], 'Laptop lost');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('revoked');

        Services::deviceService()->enrol($user, $company, [
            'deviceName' => 'Back Again',
            'publicKey'  => $publicKey,
        ]);
    }

    public function testAMalformedPublicKeyIsRefused(): void
    {
        $user    = $this->makeIdentity('Fumble');
        $company = $this->makeDesktopCompany(709);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);

        foreach (['', 'not-base64!!', base64_encode('short'), base64_encode(str_repeat("\0", 32))] as $candidate) {
            try {
                Services::deviceService()->enrol($user, $company, [
                    'deviceName' => 'Bad Key',
                    'publicKey'  => $candidate,
                ]);
                $this->fail('Expected a refusal for public key: ' . var_export($candidate, true));
            } catch (ApiException $exception) {
                $this->assertContains($exception->errorCode(), ['DEVICE_KEY_INVALID', 'VALIDATION_FAILED']);
            }
        }
    }

    /**
     * A device declares what the *software* can do. What it gets is that
     * intersected with what the organisation permits — so editing the
     * capability JSON on the machine gains nothing.
     */
    public function testDeclaredCapabilitiesAreCappedByCompanyPolicy(): void
    {
        $user    = $this->makeIdentity('Capable');
        $company = $this->makeDesktopCompany(710, 'Control Off', [
            'allow_remote_control'    => false,
            'allow_unattended_access' => false,
            'allow_clipboard_sync'    => false,
            'allow_device_reboot'     => false,
        ]);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);

        ['device' => $device] = $this->enrolDevice($user, $company);

        // The declaration is stored as it arrived — it is a fact about the
        // software, and it says remote control is possible.
        $this->assertTrue($device['capabilities']['remote_control']);

        // What the device may actually do is another matter entirely.
        $policy     = Services::policyResolver()->resolve($user, 'COMPANY', $company);
        $effective  = Services::deviceService()->effectiveCapabilities($device, $policy);

        $this->assertFalse($effective['remote_control']);
        $this->assertFalse($effective['unattended_access']);
        $this->assertFalse($effective['clipboard_sync']);
        $this->assertFalse($effective['reboot']);
        $this->assertTrue($effective['screen_share']);
    }

    public function testRevocationIsImmediateAndWithdrawsUnattendedAccess(): void
    {
        $user    = $this->makeIdentity('Owner');
        $company = $this->makeDesktopCompany(711);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);
        $this->setUserPermission($user, $company, PermissionCatalog::UNATTENDED_ACCESS, 'ALLOW');

        ['device' => $device] = $this->enrolDevice($user, $company);
        $devices = Services::deviceService();

        $enabled = $devices->enableUnattended($user, (string) $device['uuid'], true);
        $this->assertTrue($enabled['unattended_access_enabled']);

        $revoked = $devices->revoke($user, (string) $device['uuid']);

        $this->assertSame(DeviceService::STATUS_REVOKED, $revoked['status']);
        $this->assertFalse($revoked['unattended_access_enabled']);
        $this->assertNotNull($revoked['revoked_at']);
        $this->assertHasAudit(EventType::DEVICE_REVOKED);
    }

    /**
     * A device in another tenant is 404, not 403 — an id must not be probeable
     * for existence (§26).
     */
    public function testADeviceInAnotherCompanyIsNotFound(): void
    {
        $alice  = $this->makeIdentity('Alice');
        $mallory = $this->makeIdentity('Mallory');
        $first  = $this->makeDesktopCompany(712, 'Alice Ltd');
        $second = $this->makeDesktopCompany(713, 'Mallory Ltd');
        $this->grantCompanyAccess($alice, $first, 'MEMBER', true);
        $this->grantCompanyAccess($mallory, $second, 'MEMBER', true);

        ['device' => $device] = $this->enrolDevice($alice, $first);

        try {
            Services::deviceService()->findForUser($mallory, (string) $device['uuid']);
            $this->fail('Expected a device in another company to be invisible.');
        } catch (ApiException $exception) {
            $this->assertSame(404, $exception->status());
            $this->assertSame('NOT_FOUND', $exception->errorCode());
        }
    }

    public function testSuspendingADeviceWithdrawsUnattendedAccess(): void
    {
        $user    = $this->makeIdentity('Suspender');
        $company = $this->makeDesktopCompany(714);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);
        $this->setUserPermission($user, $company, PermissionCatalog::UNATTENDED_ACCESS, 'ALLOW');

        ['device' => $device] = $this->enrolDevice($user, $company);
        $devices = Services::deviceService();
        $devices->enableUnattended($user, (string) $device['uuid'], true);

        $suspended = $devices->update($user, (string) $device['uuid'], ['status' => 'SUSPENDED']);

        $this->assertSame(DeviceService::STATUS_SUSPENDED, $suspended['status']);
        $this->assertFalse($suspended['unattended_access_enabled']);
    }
}
