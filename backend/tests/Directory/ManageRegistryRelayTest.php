<?php

declare(strict_types=1);

namespace Tests\Directory;

use App\Domain\Auth\PortalClient;
use App\Domain\Directory\ManageRegistryRelay;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Remote as RemoteConfig;
use Config\Services;

/**
 * The browser reaches Manage's company registry only through Remote's relay (/api/manage/v1/*),
 * and the portal's sign-out only through /api/global/logout.
 *
 * Production hosting answers a browser it distrusts (common Indian CGNAT broadband) with a
 * challenge page that carries no CORS headers, so a cross-site call to manage.aicountly.com or
 * my.aicountly.com fails with no response at all. The relay forwards exactly the calls the shared
 * client (web/src/company-access/companyRegistryClient.ts) makes, and nothing else. No database.
 *
 * @internal
 */
final class ManageRegistryRelayTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function tearDown(): void
    {
        Services::reset(true);
        parent::tearDown();
    }

    /** @return list<array{0: string, 1: string}> method and concrete path of every call the shared client makes */
    private static function clientCalls(): array
    {
        $src = (string) file_get_contents(dirname(ROOTPATH) . '/web/src/company-access/companyRegistryClient.ts');
        preg_match_all("/call<.*?>\\('(GET|POST|PUT|PATCH|DELETE)',\\s*[`']([^`']+)[`']/", $src, $m, PREG_SET_ORDER);
        self::assertSame(substr_count($src, 'await call<'), count($m), 'every call in the shared client was read');
        self::assertGreaterThanOrEqual(20, count($m), 'the shared client was found');
        $calls = [];
        foreach ($m as [, $method, $path]) {
            $concrete = (string) preg_replace('/\$\{query\([^}]*\}\)?\}?/', '', $path);
            $concrete = str_replace('${action}', 'accept', $concrete);
            $concrete = (string) preg_replace('/\$\{enc\([^)]*\)\}/', '1f2e-uuid:remote', $concrete);
            $concrete = (string) preg_replace('/\$\{[^}]*\}/', '74', $concrete);
            $calls[] = [$method, ltrim(explode('?', $concrete)[0], '/')];
        }

        return $calls;
    }

    /** A relay whose Manage is scripted: records what it was sent and answers `$answer`. */
    private static function scripted(?array $answer = null): ManageRegistryRelay
    {
        $config             = config(RemoteConfig::class);
        $config->manageBase = 'https://manage.example.test';

        return new class ($config, $answer) extends ManageRegistryRelay {
            /** @var list<array{method: string, url: string, headers: list<string>, body: ?string, timeout: int}> */
            public array $sent = [];

            public function __construct(RemoteConfig $config, private readonly ?array $answer)
            {
                parent::__construct($config);
            }

            protected function send(string $method, string $url, array $headers, ?string $body, int $timeout): array
            {
                $this->sent[] = compact('method', 'url', 'headers', 'body', 'timeout');

                return $this->answer ?? ['status' => 0, 'contentType' => '', 'body' => '', 'correlationId' => ''];
            }
        };
    }

    public function testEveryCallTheSharedClientMakesIsRelayed(): void
    {
        foreach (self::clientCalls() as [$method, $path]) {
            $this->assertTrue(ManageRegistryRelay::isRegistryRoute($method, $path), "{$method} {$path} is relayed");
        }
    }

    public function testTheAllowListIsNoWiderThanTheClient(): void
    {
        // The plan reads are relayed by contract (CONTRACTS §3.5) although the client does not call them.
        $calls = array_merge(self::clientCalls(), array_map(static fn (string $c): array => explode(' ', $c, 2), ManageRegistryRelay::CONTRACT_ONLY));
        foreach (ManageRegistryRelay::ROUTES as $method => $patterns) {
            foreach ($patterns as $pattern) {
                $used = array_filter($calls, static fn (array $c): bool => $c[0] === $method && preg_match($pattern, $c[1]) === 1);
                $this->assertNotEmpty($used, "{$method} {$pattern} is a call the client makes");
            }
        }
    }

    public function testCompanyAccess140AndTheFleetContractRoutesAreRelayed(): void
    {
        // 1.4.0 adds no call: setting the same team permissions for several people, or moving several Company users
        // to the team, makes the per-person calls below (PATCH …/members/{ref}, POST …/move), one after another.
        $this->assertSame('1.4.0', trim((string) file_get_contents(dirname(ROOTPATH) . '/web/src/company-access/VERSION')));
        foreach ([['GET', 'v1/app-roles'], ['GET', 'v1/company-creation-targets'], ['PATCH', 'v1/workspaces/12'],
            ['PATCH', 'v1/workspaces/12/members/1f2e-uuid'], ['POST', 'v1/companies/74/creator-access/retry'],
            ['GET', 'v1/companies/74/plan'], ['GET', 'v1/me/plan'], ['GET', 'v1/me/pending'],
            // 1.3.0: Workspace team and Company users.
            ['GET', 'v1/workspaces/12/company-users'], ['POST', 'v1/workspaces/12/members/1f2e-uuid/move'],
            ['POST', 'v1/workspaces/12/members/1f2e-uuid:remote/move'], ['DELETE', 'v1/companies/74/members/1f2e-uuid']] as [$method, $path]) {
            $this->assertTrue(ManageRegistryRelay::isRegistryRoute($method, $path), "{$method} {$path} is relayed");
        }
    }

    public function testOnlyTheRegistryCallsAreRelayed(): void
    {
        $this->assertFalse(ManageRegistryRelay::isRegistryRoute('DELETE', 'v1/companies/74/permissions'), 'right path, wrong method');
        $this->assertFalse(ManageRegistryRelay::isRegistryRoute('POST', 'v1/workspaces'));
        $this->assertFalse(ManageRegistryRelay::isRegistryRoute('PATCH', 'v1/workspaces'), 'no workspace id');
        $this->assertFalse(ManageRegistryRelay::isRegistryRoute('PATCH', 'v1/workspaces/3/members'), 'no member');
        $this->assertFalse(ManageRegistryRelay::isRegistryRoute('POST', 'v1/companies/74/plan'), 'plans are read-only');
        $this->assertFalse(ManageRegistryRelay::isRegistryRoute('GET', 'v1/audit-log'));
        $this->assertFalse(ManageRegistryRelay::isRegistryRoute('GET', 'v1/companies/74/permissions/extra/level'));
        $this->assertFalse(ManageRegistryRelay::isRegistryRoute('OPTIONS', 'v1/workspaces'));
    }

    /** company-access 1.3.0's three calls are relayed with their own method and shape only. */
    public function testTheWorkspaceTeamAndCompanyUsersCallsAreNoWider(): void
    {
        foreach ([
            // The wrong method.
            ['POST', 'v1/workspaces/3/company-users'], ['DELETE', 'v1/workspaces/3/company-users'],
            ['GET', 'v1/workspaces/3/members/1f2e-uuid/move'], ['PATCH', 'v1/workspaces/3/members/1f2e-uuid/move'],
            ['DELETE', 'v1/workspaces/3/members/1f2e-uuid/move'], ['GET', 'v1/companies/74/members/1f2e-uuid'],
            ['PATCH', 'v1/companies/74/members/1f2e-uuid'], ['POST', 'v1/companies/74/members/1f2e-uuid'],
            // Extra or missing path segments.
            ['GET', 'v1/workspaces/3/company-users/1f2e-uuid'], ['GET', 'v1/workspaces/company-users'],
            ['POST', 'v1/workspaces/3/members/1f2e-uuid/move/now'], ['POST', 'v1/workspaces/3/members/move'],
            ['POST', 'v1/workspaces/3/members/a/b/move'], ['DELETE', 'v1/companies/74/members'],
            ['DELETE', 'v1/companies/74/members/1f2e-uuid/extra'], ['DELETE', 'v1/companies/74/members/../../permissions'],
            // Other ids: a workspace or company is a number; a person is never `.` / `..`.
            ['GET', 'v1/workspaces/abc/company-users'], ['GET', 'v1/workspaces/-3/company-users'],
            ['POST', 'v1/workspaces/x/members/1f2e-uuid/move'], ['POST', 'v1/workspaces/3/members/../move'],
            ['DELETE', 'v1/companies/x/members/1f2e-uuid'], ['DELETE', 'v1/companies/74/members/..'],
            // Another resource under the same shape.
            ['GET', 'v1/companies/74/company-users'], ['POST', 'v1/companies/74/members/1f2e-uuid/move'],
        ] as [$method, $path]) {
            $this->assertFalse(ManageRegistryRelay::isRegistryRoute($method, $path), "{$method} {$path} is not relayed");
        }
    }

    public function testAWriteGoesOnWithThePersonsTokenAndNothingElseOfTheBrowsers(): void
    {
        $relay  = self::scripted(['status' => 200, 'contentType' => 'application/json', 'body' => '{"data":{}}', 'correlationId' => 'corr-up']);
        $answer = $relay->relay('PATCH', ['v1', 'companies', '74', 'permissions', '1f2e%3Aremote'], 'app=remote', [
            'Authorization' => 'Bearer ses-1', 'Content-Type' => 'application/json', 'Accept' => 'application/json',
            'Idempotency-Key' => 'idem-1', 'X-Correlation-Id' => 'corr-1',
            'Cookie' => 'aic=1', 'X-Service-Key' => 'stolen', 'X-Actor-Uuid' => 'someone', 'X-Saas-Origin' => 'books',
        ], '{"role_key":"viewer"}');

        $this->assertCount(1, $relay->sent);
        $this->assertSame('https://manage.example.test/api/v1/companies/74/permissions/1f2e%3Aremote?app=remote', $relay->sent[0]['url']);
        $this->assertSame([
            'Authorization: Bearer ses-1', 'Content-Type: application/json', 'Accept: application/json',
            'Idempotency-Key: idem-1', 'X-Correlation-Id: corr-1', 'X-Saas-Origin: remote',
        ], $relay->sent[0]['headers']);
        $this->assertSame('{"role_key":"viewer"}', $relay->sent[0]['body']);
        $this->assertSame(45, $relay->sent[0]['timeout'], 'a write waits 45 s');
        $this->assertSame(['status' => 200, 'contentType' => 'application/json', 'body' => '{"data":{}}', 'correlationId' => 'corr-up'], $answer);

        $read = self::scripted(['status' => 200, 'contentType' => 'application/json', 'body' => '[]', 'correlationId' => '']);
        $read->relay('GET', ['v1', 'company-directory'], 'app=remote', ['Authorization' => 'Bearer ses-1'], 'ignored');
        $this->assertSame([null, 20], [$read->sent[0]['body'], $read->sent[0]['timeout']], 'a read sends no body and waits 20 s');
    }

    /** @return iterable<string, array{0: string, 1: list<string>}> */
    public static function refused(): iterable
    {
        yield 'a parent segment' => ['DELETE', ['v1', 'companies', '74', 'permissions', '..']];
        yield 'an encoded parent segment' => ['DELETE', ['v1', 'companies', '74', 'permissions', '%2e%2e']];
        yield 'an encoded slash' => ['DELETE', ['v1', 'workspaces', '3', 'members', 'a%2Fb']];
        yield 'a climb out of a company member' => ['DELETE', ['v1', 'companies', '3', 'members', '%2E%2E']];
        yield 'a climb out of a move' => ['POST', ['v1', 'workspaces', '3', 'members', '..', 'move']];
        yield 'a move read' => ['GET', ['v1', 'workspaces', '3', 'members', '1f2e', 'move']];
        yield 'a current-directory segment' => ['GET', ['v1', '.', 'workspaces']];
        yield 'an empty segment' => ['GET', ['v1', '', 'workspaces']];
        yield 'an odd character' => ['DELETE', ['v1', 'companies', '74', 'permissions', 'a b']];
        yield 'the wrong method' => ['DELETE', ['v1', 'companies', '74', 'permissions']];
    }

    /**
     * @dataProvider refused
     *
     * @param list<string> $segments
     */
    public function testRefusedRequestsAre404AndNeverReachManage(string $method, array $segments): void
    {
        $relay  = self::scripted(['status' => 200, 'contentType' => 'application/json', 'body' => '{}', 'correlationId' => '']);
        $answer = $relay->relay($method, $segments, '', ['Authorization' => 'Bearer ses-1'], '');

        $this->assertSame(404, $answer['status']);
        $this->assertSame([], $relay->sent);
    }

    public function testManageNotAnsweringIs504AndAWriteSaysTheOutcomeIsUnknown(): void
    {
        $read = json_decode(self::scripted()->relay('GET', ['v1', 'workspaces'], '', [], '')['body'], true);
        $this->assertSame('manage_unavailable', $read['error']['code']);

        $write = self::scripted()->relay('POST', ['v1', 'workspaces', '3', 'accept'], '', [], '{}');
        $this->assertSame(504, $write['status']);
        $this->assertStringContainsString('not known whether the change was made', json_decode($write['body'], true)['error']['message']);
    }

    public function testTheRouteRelaysEveryMethodTheClientUses(): void
    {
        $relay = self::scripted(['status' => 201, 'contentType' => 'application/json', 'body' => '{"data":{"ok":true}}', 'correlationId' => 'corr-up']);
        Services::injectMock('manageRegistryRelay', $relay);

        $result = $this->withHeaders(['Authorization' => 'Bearer ses-1', 'Content-Type' => 'application/json'])
            ->withBody('{"role_key":"viewer"}')
            ->call('patch', 'manage/v1/companies/74/permissions/1f2e:remote');
        $result->assertStatus(201);
        $this->assertSame('corr-up', $result->response()->getHeaderLine('X-Correlation-Id'));
        $this->assertSame('{"data":{"ok":true}}', (string) $result->response()->getBody());
        $this->assertSame('https://manage.example.test/api/v1/companies/74/permissions/1f2e%3Aremote', $relay->sent[0]['url']);

        $this->withHeaders(['Authorization' => 'Bearer ses-1'])->call('delete', 'manage/v1/workspaces/3/members/1f2e')->assertStatus(201);
        $this->withHeaders(['Authorization' => 'Bearer ses-1'])->call('get', 'manage/v1/workspaces')->assertStatus(201);
        $this->withHeaders(['Authorization' => 'Bearer ses-1', 'Content-Type' => 'application/json'])
            ->withBody('{"to":"company_users"}')
            ->call('post', 'manage/v1/workspaces/3/members/1f2e:remote/move')
            ->assertStatus(201);
        $this->assertSame('https://manage.example.test/api/v1/workspaces/3/members/1f2e%3Aremote/move', $relay->sent[3]['url']);
        $this->assertSame('{"to":"company_users"}', $relay->sent[3]['body']);
        $this->assertCount(4, $relay->sent);

        $this->call('get', 'manage/v1/audit-log')->assertStatus(404);
        $this->assertCount(4, $relay->sent, 'nothing else reaches Manage');
    }

    /** CodeIgniter throws for a status it has no reason phrase for (a CDN's 522, a host's 509): a 502 instead. */
    public function testAnUpstreamStatusCodeIgniterDoesNotKnowIsA502(): void
    {
        Services::injectMock('manageRegistryRelay', self::scripted(['status' => 522, 'contentType' => 'application/json', 'body' => '{"error":"origin_timeout"}', 'correlationId' => '']));

        $result = $this->withHeaders(['Authorization' => 'Bearer ses-1'])->call('get', 'manage/v1/workspaces');
        $result->assertStatus(502);
        $this->assertSame('{"error":"origin_timeout"}', (string) $result->response()->getBody());
    }

    public function testSignOutIsRelayedToThePortal(): void
    {
        $portal = new class (Services::remoteConfig()) extends PortalClient {
            /** @var list<array{method: string, path: string, headers: array<int, string>, body: string}> */
            public array $calls = [];

            public function forward(string $method, string $path, array $headers, string $body): array
            {
                $this->calls[] = ['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body];

                return ['status' => 200, 'body' => '{"status":1}', 'contentType' => 'application/json', 'retryAfter' => ''];
            }
        };
        Services::injectMock('portalClient', $portal);

        $this->withHeaders(['Authorization' => 'Bearer auth-1'])->post('global/logout')->assertStatus(200);

        $this->assertCount(1, $portal->calls);
        $this->assertSame(['POST', 'logout'], [strtoupper($portal->calls[0]['method']), $portal->calls[0]['path']]);
        $this->assertContains('Authorization: Bearer auth-1', $portal->calls[0]['headers']);
    }
}
