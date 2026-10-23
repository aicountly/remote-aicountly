<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Domain\Directory\ManageClient;
use Config\Services;
use Tests\Support\RemoteTestCase;
use Tests\Support\StandInServer;

/**
 * Remote's real Manage client against a real HTTP stand-in of Aicountly Manage
 * (tests/_support/standin).
 *
 * What is pinned here is the *difference between "no" and "no answer"* (G28#4):
 * Manage's 404 takes a person out of a company, its 503 does not, and a
 * companyinfo that does not say how the session relates to the company proves
 * nothing by itself.
 *
 * @internal
 */
final class ManageClientContractTest extends RemoteTestCase
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
            $config->manageBase     = self::$base;
        });
        StandInServer::forget();
    }

    // --------------------------------------------------------- Manage: G28#4

    public function testManageGrantsOnlyWhatTheSessionOwns(): void
    {
        $manage = Services::manageClient();

        $this->assertSame(ManageClient::GRANTED, $manage->companyAccess('mgr-ok-481', 481)['state']);
        $this->assertSame('ABC Private Limited', $manage->companyAccess('mgr-ok-481', 481)['name']);
        $this->assertSame(ManageClient::DENIED, $manage->companyAccess('mgr-ok-481', 902)['state'], 'Manage answers 404 for a company that is not theirs');
        $this->assertSame(ManageClient::DENIED, $manage->companyAccess('mgr-none', 481)['state']);
    }

    public function testManageOutageIsUnavailableNotDenied(): void
    {
        $manage = Services::manageClient();

        $this->assertSame(ManageClient::UNAVAILABLE, $manage->companyAccess('boom', 481)['state']);
        $this->assertNull($manage->accessibleCompanies('boom'));
        $this->assertSame(ManageClient::UNAVAILABLE, $manage->companyMembers('boom', 481)['state']);
    }

    public function testACompanyinfoThatSaysNothingAboutAccessFallsBackToTheCompanyList(): void
    {
        $manage = Services::manageClient();

        // 200 without `ownership` proves nothing by itself; the list decides.
        $this->assertSame(ManageClient::GRANTED, $manage->companyAccess('mgr-eval-481', 481)['state']);
        $this->assertSame(ManageClient::DENIED, $manage->companyAccess('mgr-eval-481', 902)['state']);
    }

    public function testAnUnknownSessionAtManageIsNotAMembershipChange(): void
    {
        $this->assertSame(ManageClient::UNAVAILABLE, ManageClient::classifyCompanyInfo(401, '{}', 481)['state']);
        $this->assertSame(ManageClient::DENIED, ManageClient::classifyCompanyInfo(403, '{}', 481)['state']);
        $this->assertSame(ManageClient::DENIED, ManageClient::classifyCompanyInfo(404, '{}', 481)['state']);
        $this->assertSame(ManageClient::UNAVAILABLE, ManageClient::classifyCompanyInfo(200, '<html>', 481)['state']);
        $this->assertSame(
            ManageClient::UNAVAILABLE,
            ManageClient::classifyCompanyInfo(200, '{"data":{"comp_id":7,"ownership":"owner"}}', 481)['state'],
            'an answer about a different company says nothing about this one',
        );
    }

    public function testEveryQuestionNamesRemoteSoManageAnswersForRemote(): void
    {
        $manage = Services::manageClient();
        $manage->companyAccess('mgr-ok-481', 481);
        $manage->accessibleCompanies('mgr-ok-481');

        $requests = StandInServer::requests();
        $this->assertNotEmpty($requests);
        foreach ($requests as $line) {
            $this->assertStringEndsWith(' app=remote', $line, 'company registry: Remote names itself to Manage');
        }
    }

    public function testManageRefusingRemoteForTheCompanyIsADenial(): void
    {
        $manage = Services::manageClient();

        // A member who may see the company, but whose access excludes Remote: 403 COMPANY_ACCESS_REQUIRED.
        $this->assertSame(ManageClient::DENIED, $manage->companyAccess('mgr-noapp-481', 481)['state']);
        $this->assertSame([], $manage->accessibleCompanies('mgr-noapp-481'), 'a row not openable in Remote is not a company Remote may use');

        // Where companyinfo says nothing, the list decides — and a row marked not openable denies.
        $this->assertSame(ManageClient::DENIED, $manage->companyAccess('mgr-evalnoapp-481', 481)['state']);
        // A row Manage marks openable (or a legacy row without the field) still grants.
        $this->assertSame(ManageClient::GRANTED, $manage->companyAccess('mgr-eval-481', 481)['state']);
    }

    // ------------------------------------------- finding F-2: the live directory

    public function testTheLiveDirectoryIncludesWorkspaceWideAccessWhichTheCompanyListDoesNot(): void
    {
        $manage = Services::manageClient();

        $this->assertSame([], $manage->accessibleCompanies('mgr-ws-481'), 'Manage\'s company list knows shares only');
        $this->assertSame([481 => 'ABC Private Limited'], $manage->openableCompanies('mgr-ws-481'), 'the directory carries the workspace owner\'s "every company" access');
        $this->assertSame([481 => 'ABC Private Limited'], $manage->openableCompanies('mgr-ok-481'), 'and a company the session owns');
        $this->assertSame([], $manage->openableCompanies('mgr-noapp-481'), 'a company that does not open in Remote is left out');
        $this->assertSame([], $manage->openableCompanies('mgr-none'));
        $this->assertNull($manage->openableCompanies('boom'), 'an outage is no answer, never "no companies"');

        $directory = array_values(array_filter(StandInServer::requests(), static fn (string $l): bool => str_contains($l, '/api/v1/company-directory')));
        $this->assertNotEmpty($directory);
        foreach ($directory as $line) {
            $this->assertStringEndsWith(' app=remote', $line, 'the directory is asked for Remote');
        }
    }

    public function testAnOlderManageWithoutTheDirectoryIsAskedTheCompanyList(): void
    {
        $manage = Services::manageClient();

        $this->assertSame([481 => 'ABC Private Limited'], $manage->openableCompanies('mgr-old-481'));
        $lines = StandInServer::requests();
        $this->assertTrue((bool) array_filter($lines, static fn (string $l): bool => str_contains($l, '/api/v1/company-directory')), 'the directory was tried first');
        $this->assertTrue((bool) array_filter($lines, static fn (string $l): bool => str_contains($l, '/api/companies ')), 'then the list');
    }

    public function testTheMemberDirectoryListsNamesAndEmailsForAMember(): void
    {
        $members = Services::manageClient()->companyMembers('mgr-ok-481', 481);

        $this->assertSame(ManageClient::GRANTED, $members['state']);
        $this->assertSame('uuid-asha', $members['members'][0]['uuid']);
        $this->assertSame('asha.verma@example.test', $members['members'][0]['email']);

        $this->assertSame(ManageClient::DENIED, Services::manageClient()->companyMembers('mgr-ok-481', 902)['state']);
    }
}