<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Auth\RemoteIdentity;
use App\Domain\Policy\PermissionCatalog;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FakeIdentityResolver;
use Tests\Support\RemoteTestCase;

/**
 * Device-code sign-in over HTTP — the layer {@see \Tests\Device\DesktopSignInTest}
 * does not reach, because it calls the service directly. In particular: the
 * service works in the raw storage shape, and it is the *controller*'s job to
 * present a `device` through {@see \App\Domain\Support\Presenter::device()}
 * before it reaches the wire, exactly as `DeviceController::enrol()` does —
 * a controller that forgot that is exactly what these tests would catch.
 *
 * @internal
 */
final class DesktopSignInApiTest extends RemoteTestCase
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
    private function asUser(RemoteIdentity $identity, string $sesKey = 'test-ses-key'): array
    {
        $this->identities->register($sesKey, $identity);

        return ['Authorization' => 'Bearer ' . $sesKey];
    }

    /** @return array<string, mixed> */
    private function data(\CodeIgniter\Test\TestResponse $result): array
    {
        return json_decode($result->getJSON(), true)['data'];
    }

    public function testStartNeedsNoAuthorizationAndReturnsAUsableCode(): void
    {
        $keys = $this->makeDeviceKeypair();

        $result = $this->withBodyFormat('json')->post('v1/remote/desktop-signin/start', [
            'deviceName' => 'Priya\'s laptop',
            'publicKey'  => $keys['publicKey'],
        ]);

        $result->assertStatus(201);
        $body = $this->data($result);

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $body['userCode']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['deviceCode']);
        $this->assertStringContainsString($body['userCode'], $body['verificationUriComplete']);
    }

    public function testPollNeedsNoAuthorizationEither(): void
    {
        $keys  = $this->makeDeviceKeypair();
        $start = $this->data($this->withBodyFormat('json')
            ->post('v1/remote/desktop-signin/start', ['deviceName' => 'WS-01', 'publicKey' => $keys['publicKey']]));

        $result = $this->withBodyFormat('json')->post('v1/remote/desktop-signin/poll', [
            'deviceCode' => $start['deviceCode'],
        ]);

        $result->assertStatus(200);
        $this->assertSame('pending', $this->data($result)['status']);
    }

    public function testConfirmNeedsASignedInPerson(): void
    {
        $result = $this->withBodyFormat('json')->post('v1/remote/desktop-signin/confirm', [
            'userCode'  => 'ABCD-1234',
            'companyId' => 1,
        ]);

        $result->assertStatus(401);
    }

    /**
     * The whole round trip, over the real router and filters, asserting the
     * `device` the agent receives is presented — camelCase, a formatted
     * fingerprint, the fields the desktop's `DeviceResource` type expects —
     * not the raw database row the service works with internally.
     */
    public function testTheFullRoundTripHandsTheAgentAPresentedDevice(): void
    {
        $keys    = $this->makeDeviceKeypair();
        $user    = $this->makeIdentity('Priya');
        $company = $this->makeDesktopCompany(920, 'Northwind');
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);
        $this->setUserPermission($user, $company, PermissionCatalog::DEVICE_ENROL, 'ALLOW');

        $start = $this->data($this->withBodyFormat('json')->post('v1/remote/desktop-signin/start', [
            'deviceName' => 'Priya\'s laptop',
            'publicKey'  => $keys['publicKey'],
            'hostname'   => 'WS-PRIYA',
        ]));

        $confirm = $this->withHeaders($this->asUser($user))
            ->withBodyFormat('json')
            ->post('v1/remote/desktop-signin/confirm', ['userCode' => $start['userCode'], 'companyId' => $company]);
        $confirm->assertStatus(200);

        $poll = $this->withBodyFormat('json')
            ->post('v1/remote/desktop-signin/poll', ['deviceCode' => $start['deviceCode']]);
        $poll->assertStatus(200);

        $body = $this->data($poll);
        $this->assertSame('confirmed', $body['status']);
        $this->assertSame('Northwind', $body['companyName']);

        $device = $body['device'];
        // Presented shape: camelCase keys the desktop's DeviceResource type
        // expects, not the raw snake_case row DeviceService::enrol() returns.
        $this->assertArrayHasKey('deviceName', $device);
        $this->assertArrayHasKey('companyId', $device);
        $this->assertArrayHasKey('keyFingerprint', $device);
        $this->assertArrayNotHasKey('device_name', $device);
        $this->assertArrayNotHasKey('public_key_fingerprint', $device);

        $this->assertSame($company, $device['companyId']);
        $this->assertSame('ACTIVE', $device['status']);
        $this->assertNotNull($device['keyFingerprint']);
    }
}
