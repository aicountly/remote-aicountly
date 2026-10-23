<?php

declare(strict_types=1);

namespace Tests\Plans;

use App\Domain\Directory\ManageClient;
use App\Domain\Policy\PlanEntitlements;
use App\Domain\Support\ApiException;
use Config\Remote as RemoteConfig;
use Config\Services;
use Tests\Support\FakeManageClient;
use Tests\Support\RemoteTestCase;
use Tests\Support\StandInServer;

/**
 * Plans (CONTRACTS §3.5/§3.6): the company's plan for Remote comes with Manage's companyinfo answer
 * (asked with app=remote when a membership is confirmed), is kept on the company's directory row, and
 * holds the company to remote.max_sessions_per_month and remote.max_devices with 402
 * PLAN_LIMIT_REACHED {code, limit, used}. No plan, {} or an absent code = no limit. Workspace-wide
 * access (app_access_source workspace) is a member's access: never a company administrator.
 *
 * @internal
 */
final class PlanEntitlementsTest extends RemoteTestCase
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

    /** @param array<string, mixed>|list<array<string, mixed>> $entitlements */
    private function planOf(int $companyId, array $entitlements): void
    {
        PlanEntitlements::remember($this->db, $companyId, [
            'code' => 'remote-basic', 'label' => 'Basic', 'is_default' => true, 'status' => 'active', 'ends_at' => null,
            'entitlements' => $entitlements,
        ]);
    }

    private function refusal(callable $call): ?ApiException
    {
        try {
            $call();
        } catch (ApiException $e) {
            return $e;
        }

        return null;
    }

    public function testTheMonthlySessionLimitIsTheContracts402AndRemotesOwnQuotaStillApplies(): void
    {
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481);
        $this->grantCompanyAccess($identity, $company);
        $this->planOf($company, ['remote.max_sessions_per_month' => 2, 'max_companies' => 1]);

        $this->makeSession($identity, 'COMPANY', $company);
        $this->makeSession($identity, 'COMPANY', $company);
        $e = $this->refusal(fn () => $this->makeSession($identity, 'COMPANY', $company));

        $this->assertNotNull($e, 'the third session is over the plan');
        $this->assertSame('PLAN_LIMIT_REACHED', $e->errorCode());
        $this->assertSame(402, $e->status());
        $this->assertSame(['code' => 'remote.max_sessions_per_month', 'limit' => 2, 'used' => 2], $e->details());
        $this->assertSame('Your plan allows 2 sessions a month — ask the account owner to upgrade in Manage.', $e->getMessage());
        $this->assertSame(['error' => ['code' => 'PLAN_LIMIT_REACHED', 'message' => $e->getMessage(), 'details' => $e->details()]],
            json_decode((string) $e->getResponse()->getBody(), true), 'the contract envelope');
        $this->assertSame(402, $e->getResponse()->getStatusCode());
        $this->assertSame(2, $this->db->table('remote_sessions')->where('company_id', $company)->countAllResults(), 'nothing written');

        // A personal session is not held to the company's plan.
        $this->makeSession($identity);

        // Remote's own entitlement row (403 SESSION_QUOTA_REACHED) still applies under a roomier plan.
        $this->planOf($company, ['remote.max_sessions_per_month' => 10]);
        $this->setEntitlement($company, ['max_monthly_sessions' => 2]);
        $this->assertSame('SESSION_QUOTA_REACHED', $this->refusal(fn () => $this->makeSession($identity, 'COMPANY', $company))?->errorCode());
    }

    public function testTheDeviceLimitIsTheContracts402AndReEnrollingAKnownMachineNeverCounts(): void
    {
        $user    = $this->makeIdentity();
        $company = $this->makeDesktopCompany(700, 'Northwind');
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);
        $this->planOf($company, [['code' => 'remote.max_devices', 'value' => 1]]);

        ['publicKey' => $publicKey] = $this->enrolDevice($user, $company);
        $e = $this->refusal(fn () => $this->enrolDevice($user, $company));
        $this->assertNotNull($e, 'a second computer is over the plan (entitlements as a list)');
        $this->assertSame(['PLAN_LIMIT_REACHED', 402], [$e->errorCode(), $e->status()]);
        $this->assertSame(['code' => 'remote.max_devices', 'limit' => 1, 'used' => 1], $e->details());
        $this->assertSame('Your plan allows 1 registered computer — ask the account owner to upgrade in Manage.', $e->getMessage());

        // The same machine again is its existing row, not a new device.
        $this->enrolDevice($user, $company, ['publicKey' => $publicKey]);
        $this->assertSame(1, $this->db->table('remote_devices')->where('company_id', $company)->countAllResults());
    }

    public function testNoPlanAnEmptyOneOrAnAbsentCodeIsUnlimited(): void
    {
        $identity = $this->makeIdentity();
        foreach ([482 => null, 483 => [], 484 => ['max_companies' => 1]] as $companyId => $entitlements) {
            $company = $this->makeCompany($companyId);
            $this->grantCompanyAccess($identity, $company);
            if ($entitlements !== null) {
                $this->planOf($company, $entitlements);
            }
            for ($i = 0; $i < 3; $i++) {
                $this->makeSession($identity, 'COMPANY', $company);
            }
        }
        $this->assertSame(9, $this->db->table('remote_sessions')->whereIn('company_id', [482, 483, 484])->countAllResults());

        $this->assertNull(PlanEntitlements::limit(PlanEntitlements::normalize(['entitlements' => ['remote.max_devices' => -1]]), 'remote.max_devices'));
        $this->assertSame(4, PlanEntitlements::limit(PlanEntitlements::normalize(['entitlements' => ['remote.max_devices' => '4']]), 'remote.max_devices'));
        $this->assertTrue(PlanEntitlements::allows(null, 'feature.remote.x'));
        $this->assertFalse(PlanEntitlements::allows(PlanEntitlements::normalize(['entitlements' => ['feature.remote.x' => false]]), 'feature.remote.x'));
    }

    public function testManagesCompanyinfoCarriesThePlanAndTheWorkspaceAccess(): void
    {
        $this->configureRemote(static function (RemoteConfig $config): void {
            $config->manageBase = self::$base;
        });
        StandInServer::forget();
        $manage = Services::manageClient();

        $answer = $manage->companyAccess('mgr-ws-481', 481);
        $this->assertSame(ManageClient::GRANTED, $answer['state'], 'workspace-wide access opens the company');
        $this->assertTrue($answer['planKnown']);
        $this->assertSame(3, PlanEntitlements::limit(PlanEntitlements::normalize($answer['plan']), 'remote.max_sessions_per_month'));

        $older = $manage->companyAccess('mgr-ok-481', 481);
        $this->assertSame([ManageClient::GRANTED, false, null], [$older['state'], $older['planKnown'], $older['plan']], 'an older Manage sends no plan');
    }

    public function testConfirmingAMembershipKeepsThePlanAndWorkspaceAccessIsNeverAdministration(): void
    {
        $this->configureRemote(static function (RemoteConfig $config): void {
            $config->membershipEnforcement   = true;
            $config->membershipVerifySeconds = 300;
            $config->membershipGraceSeconds  = 86400;
        });
        $manage = new FakeManageClient();
        Services::injectMock('manageClient', $manage);

        $user    = $this->makeIdentity('Asha Verma');
        $company = $this->makeCompany(481, 'ABC');
        $this->grantCompanyAccess($user, $company);
        $this->db->query("UPDATE remote_user_company_access SET verified_at = NOW() - INTERVAL '1 hour' WHERE user_id = ?", [$user->id]);
        Services::requestContext()->setIdentity($user);
        Services::requestContext()->setSesKey('key-a');
        $manage->allow('key-a', 481, 'ABC');
        $manage->setPlan(481, ['code' => 'remote-basic', 'entitlements' => ['remote.max_sessions_per_month' => 1]]);

        $this->makeSession($user, 'COMPANY', $company);
        $this->assertContains('companyinfo:481', $manage->asked, 'Manage was asked');
        $this->assertSame(1, PlanEntitlements::limit(PlanEntitlements::companyPlan($this->db, 481), 'remote.max_sessions_per_month'), 'the plan is kept');
        $this->assertSame('PLAN_LIMIT_REACHED', $this->refusal(fn () => $this->makeSession($user, 'COMPANY', $company))?->errorCode());

        $row = $this->db->table('remote_user_company_access')->where('user_id', $user->id)->where('company_id', 481)->get()->getRowArray();
        $this->assertSame('MEMBER', $row['role_key'], 'Remote derives no role from Manage');
        $this->assertFalse(in_array($row['is_company_admin'], [true, 't', '1', 1], true), 'never a company administrator');
    }
}
