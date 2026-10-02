<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Domain\Auth\PortalClient;
use App\Domain\Auth\PortalUnavailableException;
use Config\Services;
use Tests\Support\RemoteTestCase;
use Tests\Support\StandInServer;

/**
 * Remote's real portal client against a real HTTP stand-in of the portal
 * (tests/_support/standin).
 *
 * What is pinned here is the *difference between "no" and "no answer"* (I-16):
 * the portal's 401 ends a session, its 503 does not. And that the signed-in
 * person's name and e-mail come from the portal's own profile (G28#1).
 *
 * @internal
 */
final class PortalContractTest extends RemoteTestCase
{
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$base = StandInServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        StandInServer::stop();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureRemote(static function ($config): void {
            $config->portalAuthBase = self::$base;
        });
        StandInServer::forget();
    }


    public function testALiveKeyIsValidADeadKeyIsInvalid(): void
    {
        $portal = Services::portalClient();

        $this->assertSame(PortalClient::SESSION_VALID, $portal->checkSesKey('live:uuid-1')['state']);
        $this->assertSame(PortalClient::SESSION_INVALID, $portal->checkSesKey('dead')['state']);
    }

    public function testAnOutageIsNotADeadSession(): void
    {
        $portal = Services::portalClient();

        $this->assertSame(PortalClient::SESSION_UNAVAILABLE, $portal->checkSesKey('boom')['state'], '503 is an outage');
        $this->assertSame(PortalClient::SESSION_UNAVAILABLE, $portal->checkSesKey('html')['state'], 'a maintenance page is an outage');

        $this->configureRemote(static function ($config): void {
            $config->portalAuthBase = 'http://127.0.0.1:9'; // nothing listens
        });
        $this->assertSame(PortalClient::SESSION_UNAVAILABLE, Services::portalClient()->checkSesKey('live:uuid-1')['state'], 'an unreachable portal is an outage');
    }

    public function testTheResolverSaysNoToADeadKeyAndThrowsOnAnOutage(): void
    {
        $resolver = Services::identityResolver();

        $this->assertNull($resolver->resolveFromSesKey('dead'));

        $this->expectException(PortalUnavailableException::class);
        $resolver->resolveFromSesKey('boom');
    }

    /** @dataProvider answers */
    public function testClassifySessionAnswer(int $status, string $body, string $expected): void
    {
        $this->assertSame($expected, PortalClient::classifySessionAnswer($status, $body)['state']);
    }

    /** @return array<string, array{int, string, string}> */
    public static function answers(): array
    {
        return [
            '200 live'            => [200, '{"status":1,"uuid_aictly":"u"}', PortalClient::SESSION_VALID],
            '200 status 0'        => [200, '{"status":0}', PortalClient::SESSION_INVALID],
            '401'                 => [401, '{"status":0}', PortalClient::SESSION_INVALID],
            '403'                 => [403, '', PortalClient::SESSION_INVALID],
            '500'                 => [500, '', PortalClient::SESSION_UNAVAILABLE],
            '503'                 => [503, '{"message":"x"}', PortalClient::SESSION_UNAVAILABLE],
            '429'                 => [429, '', PortalClient::SESSION_UNAVAILABLE],
            '404'                 => [404, '', PortalClient::SESSION_UNAVAILABLE],
            '504 from the relay'  => [504, '', PortalClient::SESSION_UNAVAILABLE],
            '200 not json'        => [200, '<html>', PortalClient::SESSION_UNAVAILABLE],
            '200 empty'           => [200, '', PortalClient::SESSION_UNAVAILABLE],
            '200 json, no status' => [200, '{"ok":true}', PortalClient::SESSION_UNAVAILABLE],
        ];
    }

    // ------------------------------------------------- names and e-mails: G28#1

    public function testTheNameAndEmailComeFromTheSignedInPersonsOwnProfile(): void
    {
        $identity = Services::identityResolver()->resolveFromSesKey('live:uuid-priya');

        $this->assertNotNull($identity);
        $this->assertSame('Priya Nair', $identity->displayName);
        $this->assertSame('priya.nair@example.test', $identity->email);

        $row = $this->db->table('remote_identities')->where('platform_uuid', 'uuid-priya')->get()->getRowArray();
        $this->assertSame('Priya Nair', $row['display_name']);
        $this->assertSame('priya.nair@example.test', $row['email']);

        // The profile is fetched with the person's own key, nobody else's.
        $asked = array_filter(StandInServer::requests(), static fn (string $r) => str_contains($r, '/api/userprofile'));
        $this->assertNotEmpty($asked);
        foreach ($asked as $request) {
            $this->assertStringEndsWith('live:uuid-priya', $request);
        }
    }

    public function testAMissingProfileLeavesTheFallbackNameAndDoesNotFailSignIn(): void
    {
        $identity = Services::identityResolver()->resolveFromSesKey('live:uuid-noname:noprofile');

        $this->assertNotNull($identity, 'A profile that cannot be read must not block sign-in.');
        $this->assertSame('AICOUNTLY user', $identity->displayName);
        $this->assertNull($identity->email);
    }
}
