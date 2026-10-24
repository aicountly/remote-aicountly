<?php

declare(strict_types=1);

namespace Tests\Device;

use App\Domain\Device\DevicePrincipal;
use App\Domain\Support\ApiException;
use App\Domain\Policy\PermissionCatalog;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * Which sessions a desktop device may join as the sharing host.
 *
 * Being in the same company is not enough: a device joins only the session it was asked to host (an unattended session
 * names it) or an attended session its own owner started. Before this, any enrolled machine in a company could join any
 * live session of that company as its controllable sharer.
 *
 * @internal
 */
final class DeviceJoinScopeTest extends RemoteTestCase
{
    private int $companyId = 1000;

    /** @return array{owner: \App\Domain\Auth\RemoteIdentity, device: array<string, mixed>, principal: DevicePrincipal} */
    private function device(string $ownerName, bool $makeCompany = true): array
    {
        $owner = $this->makeIdentity($ownerName);
        if ($makeCompany) {
            $this->makeDesktopCompany($this->companyId, 'Desk Co', [], true);
        }
        $this->grantCompanyAccess($owner, $this->companyId, 'MEMBER', true);
        $this->setUserPermission($owner, $this->companyId, PermissionCatalog::UNATTENDED_ACCESS, 'ALLOW');
        $this->setUserPermission($owner, $this->companyId, PermissionCatalog::CONTROL_REQUEST, 'ALLOW');

        ['device' => $device] = $this->enrolDevice($owner, $this->companyId);

        return [
            'owner'     => $owner,
            'device'    => $device,
            'principal' => new DevicePrincipal((string) $device['uuid'], $this->companyId, DevicePrincipal::agentScopes(), time() + 300),
        ];
    }

    private function assertRefusedAsNotFound(callable $join): void
    {
        try {
            $join();
            $this->fail('The device must not be able to join this session.');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->status(), 'Refused as not found, so the answer does not confirm the session exists.');
        }
    }

    public function testADeviceJoinsAnAttendedSessionItsOwnerStarted(): void
    {
        ['owner' => $owner, 'principal' => $principal] = $this->device('Machine Owner');
        $session = $this->makeSession($owner, 'COMPANY', $this->companyId);

        $participant = Services::deviceSessionService()->joinAsHost($session, $principal);

        $this->assertNotEmpty($participant['uuid']);
    }

    public function testADeviceCannotJoinAColleaguesSessionInTheSameCompany(): void
    {
        ['principal' => $principal] = $this->device('Machine Owner');

        $colleague = $this->makeIdentity('Colleague');
        $this->grantCompanyAccess($colleague, $this->companyId, 'MEMBER', true);
        $session = $this->makeSession($colleague, 'COMPANY', $this->companyId);

        $this->assertRefusedAsNotFound(static fn () => Services::deviceSessionService()->joinAsHost($session, $principal));
        $this->assertSame(
            0,
            $this->db->table('remote_participants')->where('session_id', (int) $session['id'])->where('device_id IS NOT NULL')->countAllResults(),
            'No device participant is created.',
        );
    }

    public function testADeviceJoinsTheUnattendedSessionThatNamesIt(): void
    {
        ['owner' => $owner, 'device' => $device, 'principal' => $principal] = $this->device('Machine Owner');
        Services::deviceService()->enableUnattended($owner, (string) $device['uuid'], true);
        $this->markDeviceOnline((string) $device['uuid']);
        $session = Services::deviceSessionService()->startUnattended($owner, (string) $device['uuid'])['session'];

        $participant = Services::deviceSessionService()->joinAsHost($session, $principal);

        $this->assertNotEmpty($participant['uuid']);
    }

    public function testADeviceCannotJoinAnUnattendedSessionMeantForAnotherDevice(): void
    {
        ['owner' => $owner, 'device' => $target] = $this->device('Machine Owner');
        Services::deviceService()->enableUnattended($owner, (string) $target['uuid'], true);
        $this->markDeviceOnline((string) $target['uuid']);
        $session = Services::deviceSessionService()->startUnattended($owner, (string) $target['uuid'])['session'];

        // A second machine in the same company, even one belonging to the person who started the session.
        ['device' => $other] = $this->enrolDevice($owner, $this->companyId);
        $principal = new DevicePrincipal((string) $other['uuid'], $this->companyId, DevicePrincipal::agentScopes(), time() + 300);

        $this->assertRefusedAsNotFound(static fn () => Services::deviceSessionService()->joinAsHost($session, $principal));
    }
}
