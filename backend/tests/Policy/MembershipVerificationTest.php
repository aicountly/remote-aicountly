<?php

declare(strict_types=1);

namespace Tests\Policy;

use App\Domain\Auth\RemoteIdentity;
use App\Domain\Support\ApiException;
use Config\Remote as RemoteConfig;
use Config\Services;
use Tests\Support\FakeManageClient;
use Tests\Support\RemoteTestCase;

/**
 * G28#4 — Remote's company membership was add-only.
 *
 * A launch token, a directory sync or a seed wrote a row and nothing ever took
 * it back, so someone removed from a company in Aicountly Manage kept their
 * access (and their seat in the company's live sessions) here for ever. The row
 * is now relied on only while Manage has confirmed it recently, and Manage's
 * "no" removes it.
 *
 * @internal
 */
final class MembershipVerificationTest extends RemoteTestCase
{
    private FakeManageClient $manage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enforce();
    }

    private function enforce(?callable $tune = null): void
    {
        $this->configureRemote(static function (RemoteConfig $config) use ($tune): void {
            $config->membershipEnforcement  = true;
            $config->membershipVerifySeconds = 300;
            $config->membershipGraceSeconds  = 86400;
            if ($tune !== null) {
                $tune($config);
            }
        });

        $this->manage = new FakeManageClient();
        Services::injectMock('manageClient', $this->manage);
    }

    /** The caller: the signed-in person and the ses_key of their request. */
    private function signedInAs(RemoteIdentity $identity, string $sesKey = 'key-a'): void
    {
        Services::requestContext()->setIdentity($identity);
        Services::requestContext()->setSesKey($sesKey);
    }

    private function confirmedAgo(RemoteIdentity $identity, int $companyId, ?string $interval): void
    {
        $this->db->query(
            'UPDATE remote_user_company_access SET verified_at = ' . ($interval === null ? 'NULL' : "NOW() - INTERVAL '{$interval}'") . ' WHERE user_id = ? AND company_id = ?',
            [$identity->id, $companyId],
        );
    }

    private function accessRow(RemoteIdentity $identity, int $companyId): ?array
    {
        return $this->db->table('remote_user_company_access')
            ->where('user_id', $identity->id)
            ->where('company_id', $companyId)
            ->get()
            ->getRowArray();
    }

    private function deniedAs(callable $call): string
    {
        try {
            $call();
        } catch (ApiException $e) {
            return $e->errorCode();
        }

        return 'NO_EXCEPTION';
    }

    public function testARowManageHasNeverConfirmedIsConfirmedBeforeItIsRelied(): void
    {
        $user    = $this->makeIdentity('Asha Verma');
        $company = $this->makeCompany(481, 'ABC');
        $this->grantCompanyAccess($user, $company);
        $this->confirmedAgo($user, $company, null);

        $this->manage->allow('key-a', 481, 'ABC Private Limited');
        $this->signedInAs($user);

        $policy = Services::policyResolver()->resolve($user, 'COMPANY', $company);

        $this->assertSame(481, $policy->companyId);
        $this->assertNotNull($this->accessRow($user, $company)['verified_at'], 'Manage said yes, so the row now carries the confirmation.');
        $this->assertSame(['companyinfo:481'], $this->manage->asked);
    }

    public function testAPositiveAnswerIsTrustedForTheVerifyWindowAndThenAskedAgain(): void
    {
        $user    = $this->makeIdentity();
        $company = $this->makeCompany(481);
        $this->grantCompanyAccess($user, $company);
        $this->confirmedAgo($user, $company, null);
        $this->manage->allow('key-a', 481);
        $this->signedInAs($user);

        $resolver = Services::policyResolver();
        $resolver->resolve($user, 'COMPANY', $company);
        $resolver->resolve($user, 'COMPANY', $company);
        $resolver->resolve($user, 'COMPANY', $company);
        $this->assertCount(1, $this->manage->asked, 'a fresh confirmation is not re-asked on every request');

        $this->confirmedAgo($user, $company, '10 minutes');
        $resolver->resolve($user, 'COMPANY', $company);
        $this->assertCount(2, $this->manage->asked, 'past the window the person is asked about again');
    }

    public function testAPersonManageHasRemovedLosesTheCompany(): void
    {
        $user    = $this->makeIdentity('Ravi Menon');
        $company = $this->makeCompany(481);
        $this->grantCompanyAccess($user, $company);
        $this->confirmedAgo($user, $company, '1 hour');

        // Manage no longer lists the company for this session.
        $this->signedInAs($user);

        $code = $this->deniedAs(static fn () => Services::policyResolver()->resolve($user, 'COMPANY', $company));

        $this->assertSame('COMPANY_ACCESS_DENIED', $code);
        $this->assertNull($this->accessRow($user, $company), 'the stale row is gone, not merely ignored');
    }

    public function testRemovalAlsoEndsTheirSeatInTheCompanysLiveSessions(): void
    {
        $host    = $this->makeIdentity('Host');
        $leaver  = $this->makeIdentity('Leaver');
        $company = $this->makeCompany(481);
        $this->grantCompanyAccess($host, $company, 'COMPANY_ADMIN', true);
        $this->grantCompanyAccess($leaver, $company);
        $this->confirmedAgo($leaver, $company, '1 hour');

        $session = $this->makeSession($host, 'COMPANY', $company);
        $joined  = Services::joinService()->joinAuthenticated($session, $leaver, null, null);
        Services::participantService()->approve($session, (string) $joined['participant']['uuid'], $host);

        $this->signedInAs($leaver, 'key-leaver');
        $this->deniedAs(static fn () => Services::policyResolver()->resolve($leaver, 'COMPANY', $company));

        $participant = Services::participantService()->findByUser((int) $session['id'], $leaver->id);
        $this->assertSame('REMOVED', $participant['status']);
        $this->assertNotNull($participant['left_at']);

        $this->assertSame(
            'JOINED',
            $this->db->table('remote_participants')->where('session_id', $session['id'])->where('is_host', true)->get()->getRowArray()['status'],
            'the host is not touched',
        );
    }

    public function testAnOutageNeitherGrantsNorRemoves(): void
    {
        $user    = $this->makeIdentity();
        $company = $this->makeCompany(481);
        $this->grantCompanyAccess($user, $company);
        $this->manage->down();
        $this->signedInAs($user);

        // Confirmed an hour ago: inside the grace period, so work carries on.
        $this->confirmedAgo($user, $company, '1 hour');
        $this->assertSame(481, Services::policyResolver()->resolve($user, 'COMPANY', $company)->companyId);
        $this->assertNotNull($this->accessRow($user, $company), 'an outage removes nothing');

        // Confirmed two days ago: too old to rely on, and Manage cannot say.
        $this->confirmedAgo($user, $company, '2 days');
        $e = null;
        try {
            Services::policyResolver()->resolve($user, 'COMPANY', $company);
        } catch (ApiException $caught) {
            $e = $caught;
        }
        $this->assertNotNull($e);
        $this->assertSame('MEMBERSHIP_UNAVAILABLE', $e->errorCode());
        $this->assertSame(503, $e->status());
        $this->assertNotNull($this->accessRow($user, $company), 'still not removed: nobody said no');
    }

    public function testAHintNeverConfirmedGrantsNothingWhileManageCannotAnswer(): void
    {
        $user    = $this->makeIdentity();
        $company = $this->makeCompany(481);
        $this->grantCompanyAccess($user, $company);
        $this->confirmedAgo($user, $company, null);
        $this->manage->down();
        $this->signedInAs($user);

        $this->assertSame(
            'MEMBERSHIP_UNAVAILABLE',
            $this->deniedAs(static fn () => Services::policyResolver()->resolve($user, 'COMPANY', $company)),
        );
    }

    public function testWithNoSessionInHandARowIsHonouredOnlyWithinTheGracePeriod(): void
    {
        $owner   = $this->makeIdentity('Device owner');
        $company = $this->makeCompany(481);
        $this->grantCompanyAccess($owner, $company);

        // Nobody is signed in as the owner: a desktop device acting for them.
        $this->confirmedAgo($owner, $company, '2 hours');
        $this->assertSame(481, Services::policyResolver()->resolve($owner, 'COMPANY', $company)->companyId);

        $this->confirmedAgo($owner, $company, '3 days');
        $this->assertSame('COMPANY_ACCESS_DENIED', $this->deniedAs(static fn () => Services::policyResolver()->resolve($owner, 'COMPANY', $company)));

        $this->confirmedAgo($owner, $company, null);
        $this->assertSame('COMPANY_ACCESS_DENIED', $this->deniedAs(static fn () => Services::policyResolver()->resolve($owner, 'COMPANY', $company)));

        $this->assertSame([], $this->manage->asked, 'someone else\'s session is never used to ask about this person');
    }

    public function testAnotherPersonsKeyIsNotUsedToAskAboutThisOne(): void
    {
        $admin   = $this->makeIdentity('Admin');
        $other   = $this->makeIdentity('Other');
        $company = $this->makeCompany(481);
        $this->grantCompanyAccess($other, $company);
        $this->confirmedAgo($other, $company, '2 days');

        $this->manage->allow('key-admin', 481);
        $this->signedInAs($admin, 'key-admin');

        $this->assertSame('COMPANY_ACCESS_DENIED', $this->deniedAs(static fn () => Services::policyResolver()->resolve($other, 'COMPANY', $company)));
        $this->assertSame([], $this->manage->asked);
    }

    public function testEnforcementOffTrustsTheProjectionAsBefore(): void
    {
        $this->enforce(static function (RemoteConfig $config): void {
            $config->membershipEnforcement = false;
        });
        $user    = $this->makeIdentity();
        $company = $this->makeCompany(481);
        $this->grantCompanyAccess($user, $company);
        $this->confirmedAgo($user, $company, null);
        $this->signedInAs($user);

        $this->assertSame(481, Services::policyResolver()->resolve($user, 'COMPANY', $company)->companyId);
        $this->assertSame([], $this->manage->asked);
    }

    public function testTheCompanyPickerDropsWhatManageNoLongerLists(): void
    {
        $user = $this->makeIdentity('Asha');
        $kept = $this->makeCompany(481, 'Kept Ltd');
        $gone = $this->makeCompany(902, 'Gone Ltd');
        $this->grantCompanyAccess($user, $kept);
        $this->grantCompanyAccess($user, $gone);
        $this->confirmedAgo($user, $kept, '1 hour');
        $this->confirmedAgo($user, $gone, '1 hour');

        $this->manage->allow('key-a', 481, 'Kept Ltd');
        $this->signedInAs($user);

        $companies = Services::platformDirectory()->companiesFor($user);

        $this->assertSame([481], array_column($companies, 'companyId'));
        $this->assertNull($this->accessRow($user, $gone));
        $this->assertSame(['companies'], $this->manage->asked, 'one question for all of the person\'s companies');
    }

    public function testTheCompanyPickerShowsNothingItCannotConfirmBeyondTheGracePeriod(): void
    {
        $user = $this->makeIdentity('Asha');
        $a    = $this->makeCompany(481, 'Recent Ltd');
        $b    = $this->makeCompany(902, 'Old Ltd');
        $c    = $this->makeCompany(903, 'Hint Ltd');
        foreach ([$a, $b, $c] as $company) {
            $this->grantCompanyAccess($user, $company);
        }
        $this->confirmedAgo($user, $a, '1 hour');
        $this->confirmedAgo($user, $b, '3 days');
        $this->confirmedAgo($user, $c, null);

        $this->manage->down();
        $this->signedInAs($user);

        $companies = Services::platformDirectory()->companiesFor($user);

        $this->assertSame([481], array_column($companies, 'companyId'));
        $this->assertNotNull($this->accessRow($user, $b), 'an outage removes nothing');
        $this->assertNotNull($this->accessRow($user, $c));
    }

    public function testReconcilingACompanyRemovesWhoManageNoLongerListsAndLearnsNames(): void
    {
        $admin   = $this->makeIdentity('Admin');
        $stayer  = $this->makeIdentity('AICOUNTLY user', 'x');
        $leaver  = $this->makeIdentity('Leaver');
        $company = $this->makeCompany(481);

        // Identities as Remote knows them: the stayer without a real name or e-mail.
        $this->db->table('remote_identities')->where('id', $stayer->id)->update(['platform_uuid' => 'uuid-ravi', 'display_name' => 'AICOUNTLY user', 'email' => null]);
        foreach ([$admin, $stayer, $leaver] as $person) {
            $this->grantCompanyAccess($person, $company);
        }
        $this->db->table('remote_identities')->where('id', $admin->id)->update(['platform_uuid' => 'uuid-asha']);

        $this->manage->allow('key-admin', 481);
        $this->manage->setMembers(481, [
            ['uuid' => 'uuid-asha', 'name' => 'Asha Verma', 'email' => 'asha.verma@example.test'],
            ['uuid' => 'uuid-ravi', 'name' => 'Ravi Menon', 'email' => 'ravi.menon@example.test'],
        ]);

        $result = Services::membershipVerifier()->reconcileCompany(481, 'key-admin');

        $this->assertSame(1, $result['removed']);
        $this->assertNull($this->accessRow($leaver, $company));
        $this->assertNotNull($this->accessRow($stayer, $company)['verified_at']);

        $row = $this->db->table('remote_identities')->where('id', $stayer->id)->get()->getRowArray();
        $this->assertSame('Ravi Menon', $row['display_name'], 'a name Remote lacked is learned from Manage');
        $this->assertSame('ravi.menon@example.test', $row['email']);

        $row = $this->db->table('remote_identities')->where('id', $admin->id)->get()->getRowArray();
        $this->assertSame('Admin', $row['display_name'], 'a name Remote already had is never overwritten');
    }

    public function testReconcilingDuringAnOutageChangesNothing(): void
    {
        $admin   = $this->makeIdentity('Admin');
        $other   = $this->makeIdentity('Other');
        $company = $this->makeCompany(481);
        $this->grantCompanyAccess($admin, $company);
        $this->grantCompanyAccess($other, $company);
        $this->manage->down();

        $result = Services::membershipVerifier()->reconcileCompany(481, 'key-admin');

        $this->assertSame(0, $result['removed']);
        $this->assertNotNull($this->accessRow($other, $company));
    }

    public function testALaunchTokenOnlyHintsAndIsNotAConfirmation(): void
    {
        $user = $this->makeIdentity();
        $context = new \App\Domain\Auth\SourceContext($user->uuid, 481, null, null, 'BOOKS', null, null, null, null, null, null, 'jti-1');

        Services::platformDirectory()->rememberFromContext($user, $context);

        $row = $this->accessRow($user, 481);
        $this->assertNotNull($row, 'the company is offered');
        $this->assertNull($row['verified_at'], 'but nothing has confirmed it');

        // Manage does not know this session for that company: the hint is dropped.
        $this->signedInAs($user);
        $this->assertSame('COMPANY_ACCESS_DENIED', $this->deniedAs(static fn () => Services::policyResolver()->resolve($user, 'COMPANY', 481)));
        $this->assertNull($this->accessRow($user, 481));
    }
}
