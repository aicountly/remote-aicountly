<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\RemoteTestCase;
use Tests\Support\StandInServer;

/**
 * I-16 and G28#1 over HTTP, through the real router, filters, controllers and
 * the real portal client (against tests/_support/standin):
 *
 *   * an outage of the portal is a 503 the browser can retry, not a 401 that
 *     signs the person out;
 *   * the name and e-mail shown to a host come from the portal's own profile.
 *
 * @internal
 */
final class AuthOutageApiTest extends RemoteTestCase
{
    use FeatureTestTrait;

    private static string $standIn = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$standIn = StandInServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        StandInServer::stop();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureRemote(function ($config): void {
            $config->portalAuthBase = self::$standIn;
        });
    }

    /** @return array<string, mixed> */
    private function json(\CodeIgniter\Test\TestResponse $result): array
    {
        return json_decode((string) $result->getJSON(), true) ?? [];
    }

    public function testAnOutageOfThePortalIsA503ThatKeepsTheSessionNotA401(): void
    {
        $result = $this->withHeaders(['Authorization' => 'Bearer boom'])->get('v1/remote/bootstrap');

        $result->assertStatus(503);
        $result->assertHeader('Retry-After', '30');
        $result->assertHeader('X-Content-Type-Options', 'nosniff');
        $error = $this->json($result)['error'];
        $this->assertSame('AUTH_UNAVAILABLE', $error['code']);
        $this->assertTrue($error['details']['retryable']);
    }

    public function testADeadKeyIsStillA401(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer dead'])->get('v1/remote/bootstrap')->assertStatus(401);
    }

    public function testTheNameAndEmailTheHostSeesAreTheOnesThePortalKnows(): void
    {
        $result = $this->withHeaders(['Authorization' => 'Bearer live:uuid-priya'])->get('v1/remote/bootstrap');

        $result->assertStatus(200);
        $user = $this->json($result)['data']['user'];
        $this->assertSame('Priya Nair', $user['displayName']);
        $this->assertSame('priya.nair@example.test', $user['email']);

        $row = $this->db->table('remote_identities')->where('platform_uuid', 'uuid-priya')->get()->getRowArray();
        $this->assertSame('Priya Nair', $row['display_name'], 'stored, so a host deciding who may watch their screen sees a name');
        $this->assertSame('priya.nair@example.test', $row['email']);
    }

    // ------------------------------------- answered by something, not the portal

    public function testTheRelayDoesNotPassAnInterceptorsPageOffAsThePortalsAnswer(): void
    {
        // The hosting in front of the portal answers a caller it cannot vouch
        // for with an HTML "checking your browser" page — carrying HTTP 200. It
        // used to go straight back to the browser as a successful sign-in with
        // no token in it, and the app could only report that something was
        // unavailable. It is an outage, and says so, with the detail that makes
        // it safe to act on: the portal never saw the request, so nothing was
        // spent and the same call may be made again.
        $result = $this->withHeaders(['Authorization' => 'Bearer html'])->post('global/seskey');

        $result->assertStatus(503);
        $result->assertHeader('Retry-After', '5');
        $body = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame('auth_unavailable', $body['error']);
        $this->assertTrue($body['retryable']);
        $this->assertStringNotContainsString('<html', (string) $result->response()->getBody(), 'the page itself never reaches the browser');
    }

    public function testThePortalIsAskedForJsonByACallerThatSaysWhoItIs(): void
    {
        // A request with no Accept and no User-Agent is exactly what a WAF
        // serves its challenge page to, and this one is a server, not a browser
        // — it can never pass a JavaScript check. Both headers go on every
        // relayed and server-to-server call so the portal's hosting has no
        // reason to challenge it in the first place.
        $seen = Services::portalClient()->forward('GET', 'echo-request', [], '');

        $this->assertSame(200, $seen['status']);
        $headers = json_decode($seen['body'], true)['headers'];
        $this->assertSame('application/json', $headers['accept']);
        $this->assertStringContainsString('AICOUNTLY-Remote-API', $headers['user-agent']);
    }

    public function testTheRelayNamesTheVisitorByTheConnectingAddressOnly(): void
    {
        // Every relayed call reaches the portal from this server, so without a
        // name for the visitor the portal's per-IP throttles would be shared by
        // every Remote user. The name is REMOTE_ADDR; whatever the browser says
        // about itself is neither forwarded nor believed.
        $saved = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        try {
            $result = $this->withHeaders([
                'Authorization'         => 'Bearer echo',
                'X-Aicountly-Client-Ip' => '198.51.100.1',
                'X-Forwarded-For'       => '198.51.100.2',
                'X-Real-IP'             => '198.51.100.3',
                'Forwarded'             => 'for=198.51.100.4',
                'Client-IP'             => '198.51.100.5',
            ])->post('global/sso/exchange');
        } finally {
            if ($saved === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $saved;
            }
        }

        $result->assertStatus(200);
        $headers = json_decode((string) $result->response()->getBody(), true)['headers'];
        $this->assertSame('203.0.113.7', $headers['x-aicountly-client-ip'] ?? null);
        $this->assertSame('Bearer echo', $headers['authorization'] ?? null);
        foreach (['x-forwarded-for', 'x-real-ip', 'forwarded', 'client-ip'] as $name) {
            $this->assertArrayNotHasKey($name, $headers, $name . ' is not forwarded');
        }
    }

    public function testNoVisitorIsNamedWhenTheConnectingAddressIsNotAnIp(): void
    {
        $saved = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = 'unix:/run/php.sock';

        try {
            $result = $this->withHeaders([
                'Authorization'         => 'Bearer echo',
                'X-Aicountly-Client-Ip' => '198.51.100.1',
            ])->post('global/seskey');
        } finally {
            if ($saved === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $saved;
            }
        }

        $result->assertStatus(200);
        $headers = json_decode((string) $result->response()->getBody(), true)['headers'];
        $this->assertArrayNotHasKey('x-aicountly-client-ip', $headers, 'not REMOTE_ADDR, and not the browser\'s claim either');
    }

    public function testACallerThatSetItsOwnAcceptKeepsIt(): void
    {
        $seen = Services::portalClient()->forward('GET', 'echo-request', ['Accept: text/csv'], '');

        $headers = json_decode($seen['body'], true)['headers'];
        $this->assertSame('text/csv', $headers['accept']);
    }
}
