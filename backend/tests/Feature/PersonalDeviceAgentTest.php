<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Auth\RemoteIdentity;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FakeIdentityResolver;
use Tests\Support\RemoteTestCase;

/**
 * A person's own computer — registered with no organisation, the ordinary
 * case — doing exactly what the desktop agent does after it is registered:
 * prove possession of its key, read itself, report presence, and listen for
 * invitations. Each of these used to assume the device belonged to a company.
 *
 * @internal
 */
final class PersonalDeviceAgentTest extends RemoteTestCase
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
    private function asUser(RemoteIdentity $identity): array
    {
        $this->identities->register('test-ses-key', $identity);

        return ['Authorization' => 'Bearer test-ses-key'];
    }

    /** @return array<string, mixed> */
    private function data($result): array
    {
        return json_decode($result->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
    }

    public function testAPersonalDeviceAuthenticatesAndStaysOnline(): void
    {
        $owner    = $this->makeIdentity('Rahul');
        $enrolled = $this->enrolDevice($owner, null);
        $uuid     = (string) $enrolled['device']['uuid'];

        $challenge = $this->withBodyFormat('json')->post('v1/remote/devices/auth/challenge', ['deviceUuid' => $uuid]);
        $challenge->assertStatus(200);
        $challenge = $this->data($challenge);

        $verify = $this->withBodyFormat('json')->post('v1/remote/devices/auth/verify', [
            'deviceUuid' => $uuid,
            'nonce'      => $challenge['nonce'],
            'issuedAt'   => $challenge['issuedAt'],
            'signature'  => $this->signChallenge($enrolled['secretKey'], $uuid, $challenge['nonce'], $challenge['issuedAt']),
        ]);
        $verify->assertStatus(200);
        $token = $this->data($verify)['token'];

        $auth = ['Authorization' => 'Bearer ' . $token];

        $me = $this->withHeaders($auth)->get('v1/remote/devices/me');
        $me->assertStatus(200);
        $this->assertNull($this->data($me)['device']['companyId']);

        $this->withHeaders($auth)->withBodyFormat('json')
            ->post('v1/remote/devices/me/presence', ['state' => 'ONLINE', 'agentVersion' => '1.0.0'])
            ->assertStatus(200);

        $this->withHeaders($auth)->withBodyFormat('json')
            ->post('v1/remote/devices/me/presence-token', [])
            ->assertStatus(200);
    }

    /**
     * The credential itself: a personal device's names no company, one minted
     * as 0 before the fix is read the same way, and anything else is refused.
     */
    public function testAPersonalDeviceCredentialNamesNoCompany(): void
    {
        $config = Services::remoteConfig();

        $personal = \App\Domain\Device\DevicePrincipal::verify(
            $config,
            \App\Domain\Device\DevicePrincipal::issue($config, 'device-1', null, ['device.presence'], time() + 60),
        );
        $this->assertNotNull($personal);
        $this->assertNull($personal->companyId);

        $legacy = \App\Domain\Device\DevicePrincipal::verify(
            $config,
            \App\Domain\Device\DevicePrincipal::issue($config, 'device-1', 0, ['device.presence'], time() + 60),
        );
        $this->assertNotNull($legacy);
        $this->assertNull($legacy->companyId);

        $company = \App\Domain\Device\DevicePrincipal::verify(
            $config,
            \App\Domain\Device\DevicePrincipal::issue($config, 'device-1', 42, ['device.presence'], time() + 60),
        );
        $this->assertSame(42, $company?->companyId);

        $this->assertNull(\App\Domain\Device\DevicePrincipal::verify(
            $config,
            \App\Domain\Device\DevicePrincipal::issue($config, 'device-1', -3, ['device.presence'], time() + 60),
        ));
    }

    /** The web console's own view of a person's computer. */
    public function testTheOwnerCanOpenTheirPersonalDeviceInTheConsole(): void
    {
        $owner    = $this->makeIdentity('Rahul');
        $enrolled = $this->enrolDevice($owner, null);

        $this->withHeaders($this->asUser($owner))
            ->get('v1/remote/devices/' . $enrolled['device']['uuid'])
            ->assertStatus(200);
    }
}
