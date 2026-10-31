<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Auth\RemoteIdentity;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * Calling the API as a person and as a desktop agent, in one test.
 *
 * A person is signed in through the FakeIdentityResolver (one ses_key each, so
 * several people can act in the same test); a device proves possession of its
 * key exactly as the agent does — a challenge, signed — and presents the
 * credential it is given. Everything after that is the real filter, the real
 * controller and the real services.
 *
 * Needs `FeatureTestTrait` beside it, on a {@see RemoteTestCase}.
 *
 * @mixin RemoteTestCase
 */
trait DeviceApiRequests
{
    private ?FakeIdentityResolver $signedIn = null;

    /** @return array<string, string> the `Authorization` header for this person */
    protected function asPerson(RemoteIdentity $identity): array
    {
        if ($this->signedIn === null) {
            $this->signedIn = new FakeIdentityResolver(Services::portalClient(), $this->db);
            Services::injectMock('identityResolver', $this->signedIn);
        }

        $sesKey = 'ses-key-' . $identity->id;
        $this->signedIn->register($sesKey, $identity);

        return ['Authorization' => 'Bearer ' . $sesKey];
    }

    /**
     * @param  array{device: array<string, mixed>, secretKey: string} $enrolled from `enrolDevice()`
     * @return array<string, string> the `Authorization` header the agent would send
     */
    protected function asDevice(array $enrolled): array
    {
        // The identity resolver has to exist before the first request, or a
        // device credential that fell through to it would reach the portal.
        if ($this->signedIn === null) {
            $this->signedIn = new FakeIdentityResolver(Services::portalClient(), $this->db);
            Services::injectMock('identityResolver', $this->signedIn);
        }

        $uuid      = (string) $enrolled['device']['uuid'];
        $auth      = Services::deviceAuthenticationService();
        $challenge = $auth->challenge($uuid, '203.0.113.9');
        $token     = $auth->verify(
            $uuid,
            $challenge['nonce'],
            $challenge['issuedAt'],
            $this->signChallenge($enrolled['secretKey'], $uuid, $challenge['nonce'], $challenge['issuedAt']),
        )['token'];

        return ['Authorization' => 'Bearer device.' . $token];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    protected function postJson(array $headers, string $path, array $body = []): TestResponse
    {
        return $this->withHeaders($headers)->withBodyFormat('json')->post('v1/remote/' . $path, $body);
    }

    /** @param array<string, string> $headers */
    protected function getJson(array $headers, string $path): TestResponse
    {
        return $this->withHeaders($headers)->get('v1/remote/' . $path);
    }

    /**
     * The response's `data`, after asserting its status — with the body in the
     * failure message, because a bare "500 is not 200" says nothing.
     *
     * @return array<string, mixed>
     */
    protected function dataOf(TestResponse $response, int $status = 200): array
    {
        $this->assertSame($status, $response->response()->getStatusCode(), (string) $response->getJSON());

        return json_decode((string) $response->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
    }

    /** The error code, after asserting the status. */
    protected function errorOf(TestResponse $response, int $status): string
    {
        $this->assertSame($status, $response->response()->getStatusCode(), (string) $response->getJSON());

        return (string) json_decode((string) $response->getJSON(), true, 512, JSON_THROW_ON_ERROR)['error']['code'];
    }
}
