<?php

declare(strict_types=1);

namespace Tests\Device;

use App\Domain\Audit\EventType;
use App\Domain\Device\DevicePrincipal;
use App\Domain\Device\DevicePresenceService;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Session\ControlService;
use App\Domain\Support\ApiException;
use App\Domain\Support\Clock;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * Unattended access as its own security workflow, not a remembered approval.
 *
 * @internal
 */
final class UnattendedAccessTest extends RemoteTestCase
{
    private int $companyId = 1000;

    /**
     * @return array{owner: \App\Domain\Auth\RemoteIdentity, device: array<string, mixed>, secretKey: string}
     */
    private function enrolledDevice(array $policy = [], bool $unattendedEntitlement = true): array
    {
        $owner = $this->makeIdentity('Machine Owner');
        $this->makeDesktopCompany($this->companyId, 'Unattended Co', $policy, $unattendedEntitlement);
        $this->grantCompanyAccess($owner, $this->companyId, 'MEMBER', true);
        $this->setUserPermission($owner, $this->companyId, PermissionCatalog::UNATTENDED_ACCESS, 'ALLOW');
        $this->setUserPermission($owner, $this->companyId, PermissionCatalog::CONTROL_REQUEST, 'ALLOW');

        ['device' => $device, 'secretKey' => $secretKey] = $this->enrolDevice($owner, $this->companyId);

        return ['owner' => $owner, 'device' => $device, 'secretKey' => $secretKey];
    }

    // ------------------------------------------------------------ enablement

    public function testEnablingUnattendedAccessNeedsAnExplicitConfirmation(): void
    {
        ['owner' => $owner, 'device' => $device] = $this->enrolledDevice();

        try {
            Services::deviceService()->enableUnattended($owner, (string) $device['uuid'], false);
            $this->fail('Unattended access must not be switched on without confirmation.');
        } catch (ApiException $exception) {
            $this->assertSame('UNATTENDED_CONFIRMATION_REQUIRED', $exception->errorCode());
        }

        $stored = Services::deviceService()->findByUuidOrFail((string) $device['uuid']);
        $this->assertFalse($stored['unattended_access_enabled']);
    }

    public function testEnablingUnattendedAccessIsRecordedWithWhoAndWhen(): void
    {
        ['owner' => $owner, 'device' => $device] = $this->enrolledDevice();

        $enabled = Services::deviceService()->enableUnattended($owner, (string) $device['uuid'], true);

        $this->assertTrue($enabled['unattended_access_enabled']);
        $this->assertNotNull($enabled['unattended_enabled_at']);
        $this->assertSame($owner->id, (int) $enabled['unattended_enabled_by_user_id']);
        $this->assertHasAudit(EventType::UNATTENDED_ACCESS_ENABLED);
    }

    public function testEnablingUnattendedAccessNeedsItsOwnPermission(): void
    {
        ['owner' => $owner, 'device' => $device] = $this->enrolledDevice();
        $this->setUserPermission($owner, $this->companyId, PermissionCatalog::UNATTENDED_ACCESS, 'DENY');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('do not have permission to enable unattended access');

        Services::deviceService()->enableUnattended($owner, (string) $device['uuid'], true);
    }

    public function testEnablingUnattendedAccessNeedsTheCompanySwitch(): void
    {
        ['owner' => $owner, 'device' => $device] = $this->enrolledDevice(['allow_unattended_access' => false]);

        try {
            Services::deviceService()->enableUnattended($owner, (string) $device['uuid'], true);
            $this->fail('Expected the company switch to refuse this.');
        } catch (ApiException $exception) {
            $this->assertSame('UNATTENDED_ACCESS_NOT_ALLOWED', $exception->errorCode());
        }
    }

    public function testEnablingUnattendedAccessNeedsItsOwnEntitlement(): void
    {
        ['owner' => $owner, 'device' => $device] = $this->enrolledDevice([], false);

        try {
            Services::deviceService()->enableUnattended($owner, (string) $device['uuid'], true);
            $this->fail('Expected the plan gate to refuse this.');
        } catch (ApiException $exception) {
            $this->assertSame('UNATTENDED_ACCESS_NOT_ALLOWED', $exception->errorCode());
        }
    }

    /**
     * Somebody at the keyboard must be able to stop their machine being
     * reachable without signing in to a web console first — and without
     * needing the permission that granting it required.
     */
    public function testTheDeviceCanSwitchItsOwnUnattendedAccessOff(): void
    {
        ['owner' => $owner, 'device' => $device] = $this->enrolledDevice();
        $devices = Services::deviceService();

        $devices->enableUnattended($owner, (string) $device['uuid'], true);
        $this->setUserPermission($owner, $this->companyId, PermissionCatalog::UNATTENDED_ACCESS, 'DENY');

        $principal = new DevicePrincipal(
            (string) $device['uuid'],
            $this->companyId,
            DevicePrincipal::agentScopes(),
            time() + 300,
        );

        $disabled = $devices->disableUnattendedByDevice($principal);

        $this->assertFalse($disabled['unattended_access_enabled']);
        $this->assertNull($disabled['unattended_enabled_at']);
        $this->assertHasAudit(EventType::UNATTENDED_ACCESS_DISABLED);
    }

    // ------------------------------------------------------------ connecting

    public function testAnUnattendedConnectionCreatesAnOrdinarySessionWithItsOwnEvent(): void
    {
        ['owner' => $owner, 'device' => $device] = $this->enrolledDevice();
        Services::deviceService()->enableUnattended($owner, (string) $device['uuid'], true);
        $this->markDeviceOnline((string) $device['uuid']);

        $result = Services::deviceSessionService()->startUnattended($owner, (string) $device['uuid'], 'Payroll run stuck');

        $session = $result['session'];

        $this->assertSame('UNATTENDED', $session['access_mode']);
        $this->assertSame((int) $device['id'], (int) $session['device_id']);
        // Everything an ordinary session has, because it is one.
        $this->assertNotEmpty($session['uuid']);
        $this->assertNotEmpty($session['display_id']);
        $this->assertSame('COMPANY', $session['scope_type']);
        $this->assertSame($this->companyId, (int) $session['company_id']);

        $this->assertHasEvent($session, EventType::SESSION_CREATED);
        $this->assertHasEvent($session, EventType::UNATTENDED_SESSION_STARTED);
        $this->assertHasAudit(EventType::UNATTENDED_SESSION_STARTED);

        // The machine is present as a participant that can be controlled, and
        // it is not the connecting person — even though they own it.
        $host = $result['hostParticipant'];
        $this->assertSame('DESKTOP_AGENT', $host['client_type']);
        $this->assertSame((int) $device['id'], (int) $host['device_id']);

        $capabilities = json_decode((string) $host['capabilities'], true);
        $this->assertTrue($capabilities['remote_control']);

        // The connecting person is a viewer with control, not a sharer.
        $participant = $result['participant'];
        $this->assertSame('VIEWER', $participant['participant_role']);
        $this->assertSame(ControlService::STATE_GRANTED, $participant['control_state']);
        $this->assertHasEvent($session, EventType::CONTROL_GRANTED);

        $refreshed = Services::deviceService()->findByUuidOrFail((string) $device['uuid']);
        $this->assertNotNull($refreshed['unattended_last_used_at']);
    }

    public function testConnectingIsRefusedWhenTheDeviceHasNotEnabledUnattendedAccess(): void
    {
        ['owner' => $owner, 'device' => $device] = $this->enrolledDevice();
        $this->markDeviceOnline((string) $device['uuid']);

        try {
            Services::deviceSessionService()->startUnattended($owner, (string) $device['uuid']);
            $this->fail('Expected a device without unattended access to refuse.');
        } catch (ApiException $exception) {
            $this->assertSame('UNATTENDED_NOT_ENABLED', $exception->errorCode());
        }
    }

    public function testConnectingIsRefusedWithoutThePermission(): void
    {
        ['owner' => $owner, 'device' => $device] = $this->enrolledDevice();
        Services::deviceService()->enableUnattended($owner, (string) $device['uuid'], true);
        $this->markDeviceOnline((string) $device['uuid']);

        $this->setUserPermission($owner, $this->companyId, PermissionCatalog::UNATTENDED_ACCESS, 'DENY');

        try {
            Services::deviceSessionService()->startUnattended($owner, (string) $device['uuid']);
            $this->fail('Expected the permission check to refuse.');
        } catch (ApiException $exception) {
            $this->assertSame('UNATTENDED_ACCESS_DENIED', $exception->errorCode());
        }
    }

    public function testConnectingIsRefusedWhenTheDeviceIsOffline(): void
    {
        ['owner' => $owner, 'device' => $device] = $this->enrolledDevice();
        Services::deviceService()->enableUnattended($owner, (string) $device['uuid'], true);

        try {
            Services::deviceSessionService()->startUnattended($owner, (string) $device['uuid']);
            $this->fail('Expected an offline device to refuse.');
        } catch (ApiException $exception) {
            $this->assertSame('DEVICE_OFFLINE', $exception->errorCode());
        }
    }

    /**
     * A device whose agent stopped reporting is offline whatever its stored
     * state says: a crashed agent gets no chance to write OFFLINE on the way
     * out, so the timestamp is what decides.
     */
    public function testAStalePresenceHeartbeatCountsAsOffline(): void
    {
        ['device' => $device] = $this->enrolledDevice();

        $this->db->table('remote_devices')->where('uuid', $device['uuid'])->update([
            'presence_state' => 'ONLINE',
            'last_seen_at'   => Clock::in(-3600),
        ]);

        $stale = Services::deviceService()->findByUuidOrFail((string) $device['uuid']);

        $this->assertFalse(Services::deviceService()->isOnline($stale));
    }

    public function testConnectingFromAnotherCompanyIsNotFound(): void
    {
        ['owner' => $owner, 'device' => $device] = $this->enrolledDevice();
        Services::deviceService()->enableUnattended($owner, (string) $device['uuid'], true);
        $this->markDeviceOnline((string) $device['uuid']);

        $outsider = $this->makeIdentity('Outsider');
        $other    = $this->makeDesktopCompany(1001, 'Other Co');
        $this->grantCompanyAccess($outsider, $other, 'MEMBER', true);
        $this->setUserPermission($outsider, $other, PermissionCatalog::UNATTENDED_ACCESS, 'ALLOW');

        try {
            Services::deviceSessionService()->startUnattended($outsider, (string) $device['uuid']);
            $this->fail('A device in another tenant must not be reachable.');
        } catch (ApiException $exception) {
            $this->assertSame(404, $exception->status());
        }
    }

    /**
     * With unattended access enabled but remote control refused for this
     * person, the connection is a view of the screen — an honest outcome
     * rather than a silent grant.
     */
    public function testWithoutControlPermissionAnUnattendedSessionIsViewOnly(): void
    {
        ['owner' => $owner, 'device' => $device] = $this->enrolledDevice();
        Services::deviceService()->enableUnattended($owner, (string) $device['uuid'], true);
        $this->markDeviceOnline((string) $device['uuid']);

        $this->setUserPermission($owner, $this->companyId, PermissionCatalog::CONTROL_REQUEST, 'DENY');

        $result = Services::deviceSessionService()->startUnattended($owner, (string) $device['uuid']);

        $this->assertSame(ControlService::STATE_NONE, $result['participant']['control_state']);
    }

    /** Revoking the device server-side stops the next connection, not the next hour. */
    public function testRevokingTheDeviceStopsFurtherUnattendedConnections(): void
    {
        ['owner' => $owner, 'device' => $device] = $this->enrolledDevice();
        $devices = Services::deviceService();
        $devices->enableUnattended($owner, (string) $device['uuid'], true);
        $this->markDeviceOnline((string) $device['uuid']);

        $devices->revoke($owner, (string) $device['uuid'], 'Machine decommissioned');

        try {
            Services::deviceSessionService()->startUnattended($owner, (string) $device['uuid']);
            $this->fail('Expected a revoked device to refuse.');
        } catch (ApiException $exception) {
            $this->assertSame('DEVICE_NOT_ACTIVE', $exception->errorCode());
        }
    }

    /** The agent's pending list is what makes a dropped socket recoverable. */
    public function testTheAgentSeesItsPendingUnattendedSession(): void
    {
        ['owner' => $owner, 'device' => $device] = $this->enrolledDevice();
        Services::deviceService()->enableUnattended($owner, (string) $device['uuid'], true);
        $this->markDeviceOnline((string) $device['uuid']);

        $result = Services::deviceSessionService()->startUnattended($owner, (string) $device['uuid']);

        $principal = new DevicePrincipal(
            (string) $device['uuid'],
            $this->companyId,
            DevicePrincipal::agentScopes(),
            time() + 300,
        );

        $pending = Services::deviceSessionService()->pendingFor($principal);

        $this->assertCount(1, $pending);
        $this->assertSame($result['session']['uuid'], $pending[0]['uuid']);
    }

    // -------------------------------------------------------------- presence

    /** The room is inside the signed token, so a device cannot ask for another's. */
    public function testAPresenceTokenNamesExactlyOneDeviceRoom(): void
    {
        ['device' => $device] = $this->enrolledDevice();

        $principal = new DevicePrincipal(
            (string) $device['uuid'],
            $this->companyId,
            DevicePrincipal::agentScopes(),
            time() + 300,
        );

        $token = Services::devicePresenceService()->presenceToken($principal);

        $this->assertSame(DevicePresenceService::roomFor((string) $device['uuid']), $token['room']);

        $claims = json_decode(
            (string) base64_decode(strtr(explode('.', $token['token'])[1], '-_', '+/'), true),
            true,
        );

        $this->assertSame(DevicePresenceService::roomFor((string) $device['uuid']), $claims['room']);
        $this->assertSame('device', $claims['knd']);
        // Within the ceiling the relay itself enforces, so a configuration
        // mistake here cannot produce a token the relay silently refuses.
        $this->assertLessThanOrEqual(600, $claims['exp'] - $claims['iat']);
    }

    public function testAPresenceTokenIsRefusedForARevokedDevice(): void
    {
        ['owner' => $owner, 'device' => $device] = $this->enrolledDevice();
        Services::deviceService()->revoke($owner, (string) $device['uuid']);

        $principal = new DevicePrincipal(
            (string) $device['uuid'],
            $this->companyId,
            DevicePrincipal::agentScopes(),
            time() + 300,
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('not active');

        Services::devicePresenceService()->presenceToken($principal);
    }

    /** A credential scoped to presence must not open a session endpoint. */
    public function testADeviceCredentialIsRefusedOutsideItsScopes(): void
    {
        ['device' => $device] = $this->enrolledDevice();

        $presenceOnly = new DevicePrincipal(
            (string) $device['uuid'],
            $this->companyId,
            [DevicePrincipal::SCOPE_PRESENCE],
            time() + 300,
        );

        $this->assertTrue($presenceOnly->hasScope(DevicePrincipal::SCOPE_PRESENCE));
        $this->assertFalse($presenceOnly->hasScope(DevicePrincipal::SCOPE_SESSION));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('does not cover that operation');

        $presenceOnly->assertScope(DevicePrincipal::SCOPE_SESSION);
    }

    // ---------------------------------------------------------------- reboot

    public function testRebootNeedsControlOfALiveSessionOnThatDevice(): void
    {
        ['owner' => $owner, 'device' => $device] = $this->enrolledDevice();
        Services::deviceService()->enableUnattended($owner, (string) $device['uuid'], true);
        $this->markDeviceOnline((string) $device['uuid']);

        $presence = Services::devicePresenceService();

        // No session at all.
        try {
            $presence->requestReboot($owner, (string) $device['uuid'], null);
            $this->fail('Expected a reboot without a session to be refused.');
        } catch (ApiException $exception) {
            $this->assertSame('SESSION_REQUIRED', $exception->errorCode());
        }

        $result   = Services::deviceSessionService()->startUnattended($owner, (string) $device['uuid']);
        $accepted = $presence->requestReboot($owner, (string) $device['uuid'], (string) $result['session']['uuid']);

        $this->assertTrue($accepted['accepted']);
        $this->assertSame('reboot', $accepted['command']['type']);
        $this->assertHasAudit(EventType::DEVICE_REBOOT_REQUESTED);
    }

    public function testRebootIsRefusedWhenThePolicyForbidsIt(): void
    {
        ['owner' => $owner, 'device' => $device] = $this->enrolledDevice(['allow_device_reboot' => false]);
        Services::deviceService()->enableUnattended($owner, (string) $device['uuid'], true);
        $this->markDeviceOnline((string) $device['uuid']);

        $result = Services::deviceSessionService()->startUnattended($owner, (string) $device['uuid']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('not enabled for this organisation');

        Services::devicePresenceService()->requestReboot(
            $owner,
            (string) $device['uuid'],
            (string) $result['session']['uuid'],
        );
    }
}
