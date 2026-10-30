<?php

declare(strict_types=1);

namespace Tests\Device;

use App\Domain\Audit\EventType;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Device\DevicePrincipal;
use App\Domain\Device\DeviceService;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Support\Ids;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\FakeIdentityResolver;
use Tests\Support\RemoteTestCase;

/**
 * "Sign this computer out": the machine revoking itself with its own device
 * credential, `POST /devices/me/revoke`.
 *
 * Through HTTP rather than the service alone, because the properties that
 * matter belong to the route as much as to the revocation: the device revoked
 * is the one the credential names and no other, and the credential that did it
 * stops working the moment it has.
 *
 * @internal
 */
final class DeviceSignOutTest extends RemoteTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing here signs a person in. It stands in for the portal so that
        // a device credential presented on a person's route is refused here,
        // rather than sent to my.aicountly.com to be asked about.
        Services::injectMock('identityResolver', new FakeIdentityResolver(Services::portalClient(), $this->db));
    }

    /**
     * A company device with unattended access switched on and reporting in —
     * everything a revocation has to take away.
     *
     * @return array{owner: RemoteIdentity, enrolled: array{device: array<string, mixed>, publicKey: string, secretKey: string}}
     */
    private function companyDevice(int $companyId, string $deviceName = 'Front desk PC'): array
    {
        $owner = $this->makeIdentity('Device Owner');
        $this->makeDesktopCompany($companyId);
        $this->grantCompanyAccess($owner, $companyId, 'MEMBER', true);
        $this->setUserPermission($owner, $companyId, PermissionCatalog::UNATTENDED_ACCESS, 'ALLOW');

        $enrolled = $this->enrolDevice($owner, $companyId, ['deviceName' => $deviceName]);
        $uuid     = (string) $enrolled['device']['uuid'];

        $this->assertTrue(Services::deviceService()->enableUnattended($owner, $uuid, true)['unattended_access_enabled']);
        $this->markDeviceOnline($uuid);

        return ['owner' => $owner, 'enrolled' => $enrolled];
    }

    /**
     * The `Authorization` header the agent sends, holding a credential obtained
     * the way the agent obtains one: by signing a challenge with its own key.
     *
     * @param  array{device: array<string, mixed>, secretKey: string} $enrolled
     * @return array<string, string>
     */
    private function asDevice(array $enrolled): array
    {
        $uuid = (string) $enrolled['device']['uuid'];

        $challenge = $this->withHeaders([])->withBodyFormat('json')
            ->post('v1/remote/devices/auth/challenge', ['deviceUuid' => $uuid]);
        $challenge->assertStatus(200);
        $challenge = $this->data($challenge);

        $verify = $this->withHeaders([])->withBodyFormat('json')->post('v1/remote/devices/auth/verify', [
            'deviceUuid' => $uuid,
            'nonce'      => $challenge['nonce'],
            'issuedAt'   => $challenge['issuedAt'],
            'signature'  => $this->signChallenge($enrolled['secretKey'], $uuid, $challenge['nonce'], $challenge['issuedAt']),
        ]);
        $verify->assertStatus(200);

        return ['Authorization' => 'Bearer ' . $this->data($verify)['token']];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    private function signOut(array $headers, array $body = []): TestResponse
    {
        return $this->withHeaders($headers)->withBodyFormat('json')->post('v1/remote/devices/me/revoke', $body);
    }

    /** @return array<string, mixed> */
    private function data(TestResponse $result): array
    {
        return json_decode((string) $result->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
    }

    private function errorCode(TestResponse $result): string
    {
        return json_decode((string) $result->getJSON(), true, 512, JSON_THROW_ON_ERROR)['error']['code'];
    }

    /** @return array<string, mixed> */
    private function row(string $uuid): array
    {
        return Services::deviceService()->findByUuidOrFail($uuid);
    }

    /** @return list<array<string, mixed>> */
    private function revocations(): array
    {
        return $this->db->table('remote_audit_logs')
            ->where('event', EventType::DEVICE_REVOKED)
            ->get()
            ->getResultArray();
    }

    /**
     * Signing out takes away what an owner's revoke from the console takes
     * away — the status, unattended access, presence — and records it with the
     * machine as the actor, against the person it belongs to, as every
     * decision a device reports is recorded.
     */
    public function testADeviceRevokesItself(): void
    {
        ['owner' => $owner, 'enrolled' => $enrolled] = $this->companyDevice(900);
        $uuid = (string) $enrolled['device']['uuid'];

        $result = $this->signOut($this->asDevice($enrolled));

        $result->assertStatus(200);
        $device = $this->data($result)['device'];
        $this->assertSame($uuid, $device['uuid']);
        $this->assertSame(DeviceService::STATUS_REVOKED, $device['status']);
        $this->assertFalse($device['unattendedAccessEnabled']);
        $this->assertNotNull($device['revokedAt']);

        $row = $this->row($uuid);
        $this->assertSame(DeviceService::STATUS_REVOKED, $row['status']);
        $this->assertFalse($row['unattended_access_enabled']);
        $this->assertNull($row['unattended_enabled_at']);
        $this->assertSame('OFFLINE', $row['presence_state']);
        $this->assertNotNull($row['revoked_at']);

        $audit = $this->revocations();
        $this->assertCount(1, $audit);
        $this->assertSame('DEVICE', $audit[0]['actor_type']);
        $this->assertSame($owner->id, (int) $audit[0]['actor_user_id']);
        $this->assertSame(900, (int) $audit[0]['company_id']);
        $this->assertSame('device', $audit[0]['entity_type']);
        $this->assertSame($uuid, $audit[0]['entity_id']);
        $this->assertSame('security', $audit[0]['severity']);

        $metadata = json_decode((string) $audit[0]['metadata'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('DEVICE', $metadata['by']);
        $this->assertSame('Signed out from the desktop app', $metadata['reason']);
    }

    /**
     * The route takes no device: the signed credential names it. A body naming
     * another machine — the same person's, in the same organisation, as close
     * as another device gets — is ignored, and nor can the credential reach
     * one through the console's revoke, because a machine is not a person.
     */
    public function testADeviceCannotRevokeAnotherDevice(): void
    {
        ['owner' => $owner, 'enrolled' => $laptop] = $this->companyDevice(901, 'Laptop');
        $desktop = $this->enrolDevice($owner, 901, ['deviceName' => 'Desktop']);

        $laptopUuid  = (string) $laptop['device']['uuid'];
        $desktopUuid = (string) $desktop['device']['uuid'];
        $laptopAuth  = $this->asDevice($laptop);
        $desktopAuth = $this->asDevice($desktop);

        $this->withHeaders($laptopAuth)->withBodyFormat('json')
            ->post("v1/remote/devices/{$desktopUuid}/revoke", ['reason' => 'Not mine to remove'])
            ->assertStatus(401);

        $result = $this->signOut($laptopAuth, ['deviceUuid' => $desktopUuid, 'uuid' => $desktopUuid]);

        $result->assertStatus(200);
        $this->assertSame($laptopUuid, $this->data($result)['device']['uuid']);
        $this->assertSame(DeviceService::STATUS_REVOKED, $this->row($laptopUuid)['status']);
        $this->assertSame(DeviceService::STATUS_ACTIVE, $this->row($desktopUuid)['status']);
        $this->assertCount(1, $this->revocations());

        // Still a working machine, not merely a row that says so.
        $this->withHeaders($desktopAuth)->get('v1/remote/devices/me')->assertStatus(200);
    }

    /**
     * The credential that signed the computer out was the last thing it was
     * good for. Every device route re-reads the row, so the same unexpired
     * token is refused everywhere at once — its sessions and companion links
     * included — and the key that minted it cannot mint another.
     *
     * A personal device, because that is the ordinary case.
     */
    public function testARevokedDevicesCredentialIsRefusedAfterwards(): void
    {
        $enrolled = $this->enrolDevice($this->makeIdentity('Rahul'), null);
        $uuid     = (string) $enrolled['device']['uuid'];
        $auth     = $this->asDevice($enrolled);

        $this->withHeaders($auth)->get('v1/remote/devices/me')->assertStatus(200);
        $this->signOut($auth)->assertStatus(200);

        $refused = $this->withHeaders($auth)->get('v1/remote/devices/me');
        $refused->assertStatus(401);
        $this->assertSame('UNAUTHENTICATED', $this->errorCode($refused));

        foreach ([
            'v1/remote/devices/me/presence',
            'v1/remote/devices/me/presence-token',
            'v1/remote/devices/me/sessions/' . Ids::uuid4() . '/join',
            'v1/remote/devices/me/companions/' . Ids::uuid4() . '/leave',
            'v1/remote/devices/me/unattended/disable',
        ] as $path) {
            $refused = $this->withHeaders($auth)->withBodyFormat('json')->post($path, []);

            $refused->assertStatus(401);
            $this->assertSame('UNAUTHENTICATED', $this->errorCode($refused), $path);
        }

        $challenge = $this->withHeaders([])->withBodyFormat('json')
            ->post('v1/remote/devices/auth/challenge', ['deviceUuid' => $uuid]);
        $challenge->assertStatus(403);
        $this->assertSame('DEVICE_NOT_ACTIVE', $this->errorCode($challenge));
    }

    /**
     * Twice is the same as once. Over HTTP the second attempt — a retry, a
     * second click — is refused at the door, since the credential no longer
     * works; the service, asked again as two requests racing on one credential
     * would ask it, changes nothing; and nor does the owner removing the
     * computer from the console afterwards. One change to the row, one entry
     * in the trail.
     */
    public function testSigningOutTwiceIsHarmless(): void
    {
        ['owner' => $owner, 'enrolled' => $enrolled] = $this->companyDevice(902);
        $uuid = (string) $enrolled['device']['uuid'];
        $auth = $this->asDevice($enrolled);

        $this->signOut($auth)->assertStatus(200);
        $revokedAt = $this->row($uuid)['revoked_at'];

        $again = $this->signOut($auth);
        $again->assertStatus(401);
        $this->assertSame('UNAUTHENTICATED', $this->errorCode($again));

        $devices   = Services::deviceService();
        $principal = new DevicePrincipal($uuid, 902, DevicePrincipal::agentScopes(), time() + 300);

        $this->assertSame(DeviceService::STATUS_REVOKED, $devices->revokeByDevice($principal)['status']);
        $this->assertSame(DeviceService::STATUS_REVOKED, $devices->revoke($owner, $uuid, 'Tidying up')['status']);

        $this->assertSame($revokedAt, $this->row($uuid)['revoked_at']);
        $this->assertCount(1, $this->revocations());
    }
}
