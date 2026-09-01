<?php

declare(strict_types=1);

namespace Tests\Policy;

use App\Domain\Policy\PermissionCatalog;
use App\Domain\Support\ApiException;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * Tenant isolation (§77).
 *
 * The scenario the specification names, written out literally: one person, two
 * companies, opposite policies. Their rights at ABC must have *zero* effect
 * inside an XYZ session, and neither company's sessions may be reachable from
 * the other.
 *
 * @internal
 */
final class CompanyIsolationTest extends RemoteTestCase
{
    private const ABC = 481;
    private const XYZ = 902;

    public function testTheSameUserGetsDifferentAnswersInEachCompany(): void
    {
        $rahul = $this->makeIdentity('Rahul Gupta');

        $this->makeCompany(self::ABC, 'ABC Private Limited', ['allow_entire_monitor' => true]);
        $this->makeCompany(self::XYZ, 'XYZ Enterprises', ['allow_entire_monitor' => false]);

        $this->grantCompanyAccess($rahul, self::ABC, 'COMPANY_ADMIN', true);
        $this->grantCompanyAccess($rahul, self::XYZ, 'MEMBER');

        $atAbc = Services::policyResolver()->resolve($rahul, 'COMPANY', self::ABC);
        $atXyz = Services::policyResolver()->resolve($rahul, 'COMPANY', self::XYZ);

        $this->assertTrue($atAbc->allowsShareMode('ENTIRE_MONITOR'), 'ABC permits monitor sharing.');
        $this->assertFalse($atXyz->allowsShareMode('ENTIRE_MONITOR'), 'XYZ denies it, and ABC has no say.');

        $this->assertTrue($atAbc->can(PermissionCatalog::POLICY_MANAGE));
        $this->assertFalse($atXyz->can(PermissionCatalog::POLICY_MANAGE), 'Being an admin at ABC is not being one at XYZ.');
    }

    public function testAnAbcGrantDoesNotLeakIntoAnXyzSession(): void
    {
        $rahul = $this->makeIdentity('Rahul Gupta');

        $this->makeCompany(self::ABC, 'ABC Private Limited', ['allow_entire_monitor' => true]);
        $this->makeCompany(self::XYZ, 'XYZ Enterprises', ['allow_entire_monitor' => false]);
        $this->grantCompanyAccess($rahul, self::ABC, 'COMPANY_ADMIN', true);
        $this->grantCompanyAccess($rahul, self::XYZ, 'MEMBER');

        // An explicit ALLOW written against him at ABC.
        $this->setUserPermission($rahul, self::ABC, PermissionCatalog::MONITOR_SHARE, 'ALLOW');

        $session = $this->makeSession($rahul, 'COMPANY', self::XYZ);
        $policy  = Services::policyResolver()->resolve($rahul, 'COMPANY', (int) $session['company_id']);

        $this->assertFalse(
            $policy->allowsDisplaySurface('monitor'),
            'The ABC grant must have no effect whatsoever inside an XYZ session.',
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Entire-screen sharing is not permitted for your organisation.');

        Services::sessionService()->recordShareStarted($session, $rahul, 'monitor', $policy);
    }

    public function testAUserCannotReadAnotherCompanysSession(): void
    {
        $rahul = $this->makeIdentity('Rahul Gupta');
        $other = $this->makeIdentity('Someone Else');

        $this->makeCompany(self::ABC, 'ABC Private Limited');
        $this->makeCompany(self::XYZ, 'XYZ Enterprises');
        $this->grantCompanyAccess($rahul, self::ABC);
        $this->grantCompanyAccess($other, self::XYZ, 'COMPANY_ADMIN', true);

        $session = $this->makeSession($rahul, 'COMPANY', self::ABC);

        // Even a company administrator — of a *different* company — gets the
        // same "not found" as a stranger, so ids cannot be probed.
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('That Remote session could not be found.');

        Services::sessionService()->findForUser((string) $session['uuid'], $other);
    }

    public function testCompanyWideHistoryIsScopedToTheCompanyItWasGrantedIn(): void
    {
        $admin = $this->makeIdentity('Admin At ABC');
        $rahul = $this->makeIdentity('Rahul Gupta');

        $this->makeCompany(self::ABC, 'ABC Private Limited');
        $this->makeCompany(self::XYZ, 'XYZ Enterprises');

        // Administrator at ABC, ordinary member at XYZ.
        $this->grantCompanyAccess($admin, self::ABC, 'COMPANY_ADMIN', true);
        $this->grantCompanyAccess($admin, self::XYZ, 'MEMBER');
        $this->grantCompanyAccess($rahul, self::ABC);
        $this->grantCompanyAccess($rahul, self::XYZ);

        $abcSession = $this->makeSession($rahul, 'COMPANY', self::ABC);
        $xyzSession = $this->makeSession($rahul, 'COMPANY', self::XYZ);

        $history = Services::sessionService()->history($admin, ['limit' => 50]);
        $uuids   = array_column($history['items'], 'uuid');

        $this->assertContains((string) $abcSession['uuid'], $uuids, 'An ABC admin sees ABC sessions.');
        $this->assertNotContains(
            (string) $xyzSession['uuid'],
            $uuids,
            'Company-wide history at ABC must not reveal a session belonging to XYZ.',
        );
    }

    public function testAUserCannotJoinASessionOfACompanyTheyAreNotIn(): void
    {
        $rahul     = $this->makeIdentity('Rahul Gupta');
        $outsider  = $this->makeIdentity('Outsider');

        $this->makeCompany(self::ABC, 'ABC Private Limited');
        $this->grantCompanyAccess($rahul, self::ABC);

        $session = $this->makeSession($rahul, 'COMPANY', self::ABC);
        $code    = (string) $session['session_code'];

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('You do not have access to this organisation in AICOUNTLY.');

        Services::joinService()->joinByCode($code, $outsider, null, null);
    }

    public function testAPersonalSessionIsVisibleOnlyToItsOwnerAndParticipants(): void
    {
        $owner    = $this->makeIdentity('Owner');
        $stranger = $this->makeIdentity('Stranger');

        $session = $this->makeSession($owner, 'PERSONAL', null);

        $this->assertTrue(Services::sessionService()->canAccess($session, $owner));
        $this->assertFalse(
            Services::sessionService()->canAccess($session, $stranger),
            'A personal session belongs to exactly one person until someone is admitted.',
        );
    }

    public function testAdministeringACompanyYouAreNotInIsRefused(): void
    {
        $outsider = $this->makeIdentity('Outsider');
        $this->makeCompany(self::ABC);

        $this->expectException(ApiException::class);

        Services::policyResolver()->resolve($outsider, 'COMPANY', self::ABC);
    }
}
