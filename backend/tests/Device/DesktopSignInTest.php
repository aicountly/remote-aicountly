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
 * Device-code sign-in: the desktop agent's alternative to holding a portal
 * credential, and to depending on the portal accepting a loopback returnUrl.
 *
 * @internal
 */
final class DesktopSignInTest extends RemoteTestCase
{
    private function start(array $overrides = []): array
    {
        $keys = $this->makeDeviceKeypair();

        $result = Services::desktopSignInService()->start(array_merge([
            'deviceName'      => 'Priya\'s laptop',
            'publicKey'       => $keys['publicKey'],
            'operatingSystem' => 'Windows',
            'osVersion'       => '11 24H2',
            'architecture'    => 'x86_64',
            'hostname'        => 'WS-TEST-02',
            'agentVersion'    => '1.0.0',
            'capabilities'    => [],
        ], $overrides), '203.0.113.5');

        return $result + ['publicKey' => $keys['publicKey'], 'secretKey' => $keys['secretKey']];
    }

    public function testStartingIssuesTwoDistinctCodesAndAPendingRow(): void
    {
        $started = $this->start();

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $started['userCode']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $started['deviceCode']);
        $this->assertStringContainsString($started['userCode'], $started['verificationUriComplete']);
        $this->assertNotSame($started['userCode'], $started['deviceCode']);

        $row = $this->db->table('remote_desktop_signin_codes')->where('device_code', $started['deviceCode'])->get()->getRowArray();
        $this->assertSame('PENDING', $row['status']);
    }

    public function testAMalformedPublicKeyIsRejectedImmediately(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('not a valid Ed25519 public key');

        $this->start(['publicKey' => 'not-a-key']);
    }

    public function testPollingBeforeConfirmationReportsPending(): void
    {
        $started = $this->start();

        $outcome = Services::desktopSignInService()->poll($started['deviceCode']);

        $this->assertSame(['status' => 'pending'], $outcome);
    }

    public function testPollingAnUnknownDeviceCodeIsNotFound(): void
    {
        $this->expectException(ApiException::class);

        Services::desktopSignInService()->poll(str_repeat('a', 64));
    }

    public function testConfirmingRequiresTheEnrolPermission(): void
    {
        $started = $this->start();
        $user    = $this->makeIdentity('No Permission');
        $company = $this->makeDesktopCompany(910);
        $this->grantCompanyAccess($user, $company);
        $this->setUserPermission($user, $company, PermissionCatalog::DEVICE_ENROL, 'DENY');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('You do not have permission to register a device');

        Services::desktopSignInService()->confirmByUserCode($user, $started['userCode'], $company, null);
    }

    public function testConfirmingRequiresCompanyMembership(): void
    {
        $started = $this->start();
        $user    = $this->makeIdentity('Outsider');
        $other   = $this->makeDesktopCompany(911, 'Somebody Else');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('You do not have access to this organisation');

        Services::desktopSignInService()->confirmByUserCode($user, $started['userCode'], $other, null);
    }

    public function testConfirmingAnUnknownOrMistypedCodeIsRefused(): void
    {
        $user    = $this->makeIdentity('Nadia');
        $company = $this->makeDesktopCompany(912);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);
        $this->setUserPermission($user, $company, PermissionCatalog::DEVICE_ENROL, 'ALLOW');

        $this->expectException(ApiException::class);

        Services::desktopSignInService()->confirmByUserCode($user, 'ZZZZ-ZZZZ', $company, null);
    }

    /**
     * The whole point: the agent never holds a portal credential, and the
     * device is enrolled exactly as `/devices/enrol` would enrol it.
     */
    public function testConfirmThenPollEnrolsTheDeviceExactlyOnce(): void
    {
        $started = $this->start(['deviceName' => 'Priya\'s laptop']);
        $user    = $this->makeIdentity('Priya');
        $company = $this->makeDesktopCompany(913, 'Northwind');
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);
        $this->setUserPermission($user, $company, PermissionCatalog::DEVICE_ENROL, 'ALLOW');

        $confirmed = Services::desktopSignInService()->confirmByUserCode($user, $started['userCode'], $company, '198.51.100.9');
        $this->assertSame('confirmed', $confirmed['status']);
        $this->assertSame('Priya\'s laptop', $confirmed['deviceLabel']);
        $this->assertHasAudit(EventType::DESKTOP_SIGNIN_CONFIRMED);

        $outcome = Services::desktopSignInService()->poll($started['deviceCode']);

        $this->assertSame('confirmed', $outcome['status']);
        $this->assertSame($company, (int) $outcome['device']['company_id']);
        $this->assertSame($user->id, (int) $outcome['device']['enrolled_by_user_id']);
        $this->assertSame(DeviceService::STATUS_ACTIVE, $outcome['device']['status']);
        $this->assertSame(
            DeviceSignature::fingerprint($started['publicKey']),
            $outcome['device']['public_key_fingerprint'],
        );
        $this->assertHasAudit(EventType::DEVICE_ENROLLED);

        $devices = $this->db->table('remote_devices')
            ->where('public_key_fingerprint', DeviceSignature::fingerprint($started['publicKey']))
            ->countAllResults();
        $this->assertSame(1, $devices, 'Polling again must not enrol a second device.');

        // Idempotent: a retried poll after the agent never saw the first
        // response hands back the same device rather than failing.
        $again = Services::desktopSignInService()->poll($started['deviceCode']);
        $this->assertSame('confirmed', $again['status']);
        $this->assertSame($outcome['device']['uuid'], $again['device']['uuid']);
    }

    public function testDenyingBeforeConfirmationMakesTheCodeUnusable(): void
    {
        $started = $this->start();
        $user    = $this->makeIdentity('Someone');
        $company = $this->makeDesktopCompany(914);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);

        $result = Services::desktopSignInService()->denyByUserCode($user, $started['userCode']);
        $this->assertSame(['status' => 'denied'], $result);

        $outcome = Services::desktopSignInService()->poll($started['deviceCode']);
        $this->assertSame(['status' => 'denied'], $outcome);
        $this->assertHasAudit(EventType::DESKTOP_SIGNIN_DENIED);

        $this->expectException(ApiException::class);
        Services::desktopSignInService()->confirmByUserCode($user, $started['userCode'], $company, null);
    }

    public function testOnlyThePersonWhoConfirmedMayDenyAfterConfirming(): void
    {
        $started = $this->start();
        $owner   = $this->makeIdentity('Owner');
        $bystander = $this->makeIdentity('Bystander');
        $company = $this->makeDesktopCompany(915);
        $this->grantCompanyAccess($owner, $company, 'MEMBER', true);
        $this->grantCompanyAccess($bystander, $company, 'MEMBER', true);
        $this->setUserPermission($owner, $company, PermissionCatalog::DEVICE_ENROL, 'ALLOW');

        Services::desktopSignInService()->confirmByUserCode($owner, $started['userCode'], $company, null);

        $this->expectException(ApiException::class);

        Services::desktopSignInService()->denyByUserCode($bystander, $started['userCode']);
    }

    public function testAnExpiredCodeIsReportedAsExpiredNotPending(): void
    {
        $started = $this->start();

        $this->db->table('remote_desktop_signin_codes')
            ->where('device_code', $started['deviceCode'])
            ->update(['expires_at' => \App\Domain\Support\Clock::in(-1)]);

        $outcome = Services::desktopSignInService()->poll($started['deviceCode']);

        $this->assertSame(['status' => 'expired'], $outcome);
    }

    public function testAnExpiredCodeCannotBeConfirmed(): void
    {
        $started = $this->start();
        $user    = $this->makeIdentity('Late');
        $company = $this->makeDesktopCompany(916);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);
        $this->setUserPermission($user, $company, PermissionCatalog::DEVICE_ENROL, 'ALLOW');

        $this->db->table('remote_desktop_signin_codes')
            ->where('device_code', $started['deviceCode'])
            ->update(['expires_at' => \App\Domain\Support\Clock::in(-1)]);

        $this->expectException(ApiException::class);

        Services::desktopSignInService()->confirmByUserCode($user, $started['userCode'], $company, null);
    }

    public function testUserCodeIsAcceptedWithOrWithoutTheDashAndAnyCase(): void
    {
        $started = $this->start();
        $user    = $this->makeIdentity('Casey');
        $company = $this->makeDesktopCompany(917);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);
        $this->setUserPermission($user, $company, PermissionCatalog::DEVICE_ENROL, 'ALLOW');

        $loose = strtolower(str_replace('-', '', $started['userCode']));

        $result = Services::desktopSignInService()->confirmByUserCode($user, $loose, $company, null);

        $this->assertSame('confirmed', $result['status']);
    }
}
