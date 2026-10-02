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

    public function testTheMemberDirectoryListsNamesAndEmailsForAMember(): void
    {
        $members = Services::manageClient()->companyMembers('mgr-ok-481', 481);

        $this->assertSame(ManageClient::GRANTED, $members['state']);
        $this->assertSame('uuid-asha', $members['members'][0]['uuid']);
        $this->assertSame('asha.verma@example.test', $members['members'][0]['email']);

        $this->assertSame(ManageClient::DENIED, Services::manageClient()->companyMembers('mgr-ok-481', 902)['state']);
    }
}