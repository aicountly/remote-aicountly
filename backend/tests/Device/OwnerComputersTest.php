<?php

declare(strict_types=1);

namespace Tests\Device;

use App\Domain\Device\DevicePrincipal;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\DeviceApiRequests;
use Tests\Support\RemoteTestCase;

/**
 * `GET /devices/me/computers`: the desktop app's own list of the owner's computers.
 *
 * Asked with a device credential, so the properties that matter are about whose computers come back: the
 * owner's, in this computer's own scope, never anybody else's — and never one that was signed out for good.
 *
 * @internal
 */
final class OwnerComputersTest extends RemoteTestCase
{
    use FeatureTestTrait;
    use DeviceApiRequests;

    public function testItListsTheOwnersOwnComputersThisOneFirstThenByName(): void
    {
        $owner = $this->makeIdentity('Meera');

        $laptop = $this->enrolDevice($owner, null, ['deviceName' => 'Meera laptop', 'operatingSystem' => 'macOS', 'osVersion' => '14.5']);
        $office = $this->enrolDevice($owner, null, ['deviceName' => 'Attic server']);
        $home   = $this->enrolDevice($owner, null, ['deviceName' => 'bedroom PC']);
        $this->markDeviceOnline((string) $laptop['device']['uuid']);
        Services::deviceService()->enableUnattended($owner, (string) $home['device']['uuid'], true);

        $data = $this->dataOf($this->getJson($this->asDevice($laptop), 'devices/me/computers'));

        $this->assertSame(3, $data['count']);
        $this->assertSame(
            ['Meera laptop', 'Attic server', 'bedroom PC'],
            array_column($data['computers'], 'deviceName'),
            'this computer first, then the rest by name whatever their case',
        );
        $this->assertSame([true, false, false], array_column($data['computers'], 'isThisComputer'));

        $this->assertSame([
            'uuid'                    => (string) $laptop['device']['uuid'],
            'deviceName'              => 'Meera laptop',
            'operatingSystem'         => 'macOS',
            'osVersion'               => '14.5',
            'status'                  => 'ACTIVE',
            'online'                  => true,
            'lastSeenAt'              => $data['computers'][0]['lastSeenAt'],
            'enrolledAt'              => $data['computers'][0]['enrolledAt'],
            'unattendedAccessEnabled' => false,
            'isThisComputer'          => true,
        ], $data['computers'][0], 'exactly the contract\'s fields — no key, hostname, address or capabilities');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', (string) $data['computers'][0]['enrolledAt']);
        $this->assertNotNull($data['computers'][0]['lastSeenAt']);

        $this->assertFalse($data['computers'][1]['online'], 'never reported in');
        $this->assertTrue($data['computers'][2]['unattendedAccessEnabled']);
        $this->assertSame((string) $office['device']['uuid'], $data['computers'][1]['uuid']);
    }

    /** Another person's computers never appear, and nor do the owner's organisation's machines. */
    public function testNothingAboutAnotherPersonOrAnotherScopeAppears(): void
    {
        $owner     = $this->makeIdentity('Meera');
        $neighbour = $this->makeIdentity('Neighbour');

        $mine = $this->enrolDevice($owner, null, ['deviceName' => 'Meera laptop']);
        $this->enrolDevice($neighbour, null, ['deviceName' => 'Neighbour PC']);

        // The same person's computer in an organisation is a different scope (dormant, but still a wall).
        $this->makeDesktopCompany(5100, 'Meera Co');
        $this->grantCompanyAccess($owner, 5100, 'MEMBER', true);
        $this->enrolDevice($owner, 5100, ['deviceName' => 'Meera work PC']);

        $data = $this->dataOf($this->getJson($this->asDevice($mine), 'devices/me/computers'));

        $this->assertSame(['Meera laptop'], array_column($data['computers'], 'deviceName'));
        $this->assertSame(1, $data['count']);

        // And the neighbour's own list holds only the neighbour's.
        $theirs = Services::deviceService()->computersFor(new DevicePrincipal(
            (string) $this->db->table('remote_devices')->where('device_name', 'Neighbour PC')->get()->getRow()->uuid,
            null,
            DevicePrincipal::agentScopes(),
            time() + 300,
        ));
        $this->assertSame(['Neighbour PC'], array_column($theirs['items'], 'device_name'));
    }

    /** A computer signed out for good is not one of theirs any more; a suspended one still is. */
    public function testRevokedComputersAreLeftOutAndSuspendedOnesAreNot(): void
    {
        $owner = $this->makeIdentity('Meera');

        $laptop  = $this->enrolDevice($owner, null, ['deviceName' => 'Meera laptop']);
        $old     = $this->enrolDevice($owner, null, ['deviceName' => 'Old desktop']);
        $resting = $this->enrolDevice($owner, null, ['deviceName' => 'Spare PC']);

        Services::deviceService()->revoke($owner, (string) $old['device']['uuid'], 'Sold it');
        Services::deviceService()->update($owner, (string) $resting['device']['uuid'], ['status' => 'SUSPENDED']);

        $data = $this->dataOf($this->getJson($this->asDevice($laptop), 'devices/me/computers'));

        $this->assertSame(['Meera laptop', 'Spare PC'], array_column($data['computers'], 'deviceName'));
        $this->assertSame(['ACTIVE', 'SUSPENDED'], array_column($data['computers'], 'status'));
        $this->assertSame(2, $data['count']);
    }

    /** A person's credential is not a device's, and a device credential without `device.self` is not enough. */
    public function testOnlyTheDevicesOwnFullCredentialReadsIt(): void
    {
        $owner  = $this->makeIdentity('Meera');
        $laptop = $this->enrolDevice($owner, null);

        $this->errorOf($this->getJson($this->asPerson($owner), 'devices/me/computers'), 401);
        $this->errorOf($this->getJson([], 'devices/me/computers'), 401);

        $presenceOnly = DevicePrincipal::issue(
            Services::remoteConfig(),
            (string) $laptop['device']['uuid'],
            null,
            [DevicePrincipal::SCOPE_PRESENCE],
            time() + 300,
        );

        $this->errorOf($this->getJson(['Authorization' => 'Bearer device.' . $presenceOnly], 'devices/me/computers'), 403);
    }
}
