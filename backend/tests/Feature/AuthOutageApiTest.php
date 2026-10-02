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
}
