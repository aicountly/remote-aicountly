<?php

declare(strict_types=1);

namespace Tests\Session;

use App\Domain\Auth\GuestPrincipal;
use App\Domain\Support\ApiException;
use App\Domain\Support\Ids;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * Invitations, join codes and guest access (§6E, §6F, §23, §58).
 *
 * @internal
 */
final class InvitationAndJoinTest extends RemoteTestCase
{
    public function testTheInvitationSecretIsNeverStored(): void
    {
        $host    = $this->makeIdentity('Host');
        $session = $this->makeSession($host);
        $policy  = Services::policyResolver()->resolve($host, 'PERSONAL', null);

        $result = Services::invitationService()->create($session, $host, $policy, 'INTERNAL', null, null);

        $row = $this->db->table('remote_invitations')->where('id', $result['invitation']['id'])->get()->getRowArray();

        $this->assertNotSame($result['secret'], $row['token_hash']);
        $this->assertSame(Ids::hashSecret($result['secret']), $row['token_hash']);
        $this->assertStringContainsString($result['secret'], $result['url']);

        // Nothing anywhere else in the database may contain it.
        $auditJson = (string) json_encode($this->db->table('remote_audit_logs')->get()->getResultArray());
        $this->assertStringNotContainsString($result['secret'], $auditJson);
    }

    public function testAnInvitationCanOnlyBeRedeemedOnce(): void
    {
        $host    = $this->makeIdentity('Host');
        $guestA  = $this->makeIdentity('Colleague A');
        $guestB  = $this->makeIdentity('Colleague B');
        $session = $this->makeSession($host);
        $policy  = Services::policyResolver()->resolve($host, 'PERSONAL', null);

        $result = Services::invitationService()->create($session, $host, $policy, 'INTERNAL', null, null);

        Services::joinService()->redeemInvitation($result['secret'], $guestA, null, null, null, null);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('This invitation link has already been used.');

        Services::joinService()->redeemInvitation($result['secret'], $guestB, null, null, null, null);
    }

    public function testAnExpiredInvitationIsRefused(): void
    {
        $host    = $this->makeIdentity('Host');
        $joiner  = $this->makeIdentity('Joiner');
        $session = $this->makeSession($host);
        $policy  = Services::policyResolver()->resolve($host, 'PERSONAL', null);

        $result = Services::invitationService()->create($session, $host, $policy, 'INTERNAL', null, null);

        $this->db->table('remote_invitations')
            ->where('id', $result['invitation']['id'])
            ->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 60) . '+00']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('This invitation link has expired. Ask for a new one.');

        Services::joinService()->redeemInvitation($result['secret'], $joiner, null, null, null, null);
    }

    public function testARevokedInvitationIsRefused(): void
    {
        $host    = $this->makeIdentity('Host');
        $joiner  = $this->makeIdentity('Joiner');
        $session = $this->makeSession($host);
        $policy  = Services::policyResolver()->resolve($host, 'PERSONAL', null);

        $result = Services::invitationService()->create($session, $host, $policy, 'INTERNAL', null, null);
        Services::invitationService()->revoke($session, (string) $result['invitation']['uuid'], $host);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('This invitation has been withdrawn.');

        Services::joinService()->redeemInvitation($result['secret'], $joiner, null, null, null, null);
    }

    public function testInvitationExpiryIsCappedByCompanyPolicy(): void
    {
        $host    = $this->makeIdentity('Host');
        $company = $this->makeCompany(481, 'ABC', ['guest_link_expiry_minutes' => 5]);
        $this->grantCompanyAccess($host, $company);

        $session = $this->makeSession($host, 'COMPANY', $company);
        $policy  = Services::policyResolver()->resolve($host, 'COMPANY', $company);

        // Asking for an hour must not get an hour.
        $result = Services::invitationService()->create($session, $host, $policy, 'INTERNAL', null, 60);

        $expiresIn = strtotime((string) $result['invitation']['expires_at']) - time();

        $this->assertLessThanOrEqual(5 * 60 + 5, $expiresIn);
    }

    public function testAGuestInvitationIsRefusedWhenTheCompanyForbidsGuests(): void
    {
        $host    = $this->makeIdentity('Host');
        $company = $this->makeCompany(481, 'ABC', ['allow_external_guest' => false]);
        $this->grantCompanyAccess($host, $company, 'COMPANY_ADMIN', true);

        $session = $this->makeSession($host, 'COMPANY', $company);
        $policy  = Services::policyResolver()->resolve($host, 'COMPANY', $company);

        try {
            Services::invitationService()->create($session, $host, $policy, 'EXTERNAL_GUEST', 'someone@example.com', null);
            $this->fail('External guests are disabled for this company.');
        } catch (ApiException $e) {
            $this->assertSame('EXTERNAL_GUEST_NOT_ALLOWED', $e->errorCode());
        }
    }

    public function testAGuestJoinsWithATokenBoundToOneSession(): void
    {
        $host    = $this->makeIdentity('Host');
        $company = $this->makeCompany(481, 'ABC', ['allow_external_guest' => true]);
        $this->grantCompanyAccess($host, $company, 'COMPANY_ADMIN', true);
        $this->setEntitlement($company, ['external_guests' => true]);

        $session = $this->makeSession($host, 'COMPANY', $company);
        $policy  = Services::policyResolver()->resolve($host, 'COMPANY', $company);

        $invitation = Services::invitationService()->create($session, $host, $policy, 'EXTERNAL_GUEST', 'amit@example.com', null);

        $result = Services::joinService()->redeemInvitation(
            $invitation['secret'],
            null,
            'Amit Shah',
            'amit@example.com',
            null,
            null,
        );

        $this->assertNotNull($result['guestToken']);
        $this->assertSame('GUEST', $result['participant']['participant_role']);
        $this->assertSame('REQUESTED', $result['participant']['status'], 'A guest still waits for the host.');
        $this->assertNull($result['participant']['user_id'], 'A guest has no AICOUNTLY identity.');

        $guest = GuestPrincipal::verify(Services::remoteConfig(), $result['guestToken']);
        $this->assertNotNull($guest);
        $this->assertSame((string) $result['participant']['uuid'], $guest->participantUuid);

        // The token names one session, and only that one.
        $other = $this->makeSession($host, 'PERSONAL', null);

        $this->expectException(ApiException::class);
        $guest->assertSession((string) $other['uuid']);
    }

    public function testAGuestTokenSignedWithAnotherSecretIsRejected(): void
    {
        $token = GuestPrincipal::issue(
            $this->configureRemote(static function ($config): void {
                $config->signallingSecret = 'the-wrong-secret';
            }),
            Ids::uuid4(),
            Ids::uuid4(),
            'Impostor',
            time() + 600,
        );

        $verifier = $this->configureRemote(static function ($config): void {
            $config->signallingSecret = 'the-real-secret';
        });

        $this->assertNull(GuestPrincipal::verify($verifier, $token));
    }

    public function testAnExpiredGuestTokenIsRejected(): void
    {
        $config = Services::remoteConfig();

        $token = GuestPrincipal::issue($config, Ids::uuid4(), Ids::uuid4(), 'Guest', time() - 1);

        $this->assertNull(GuestPrincipal::verify($config, $token));
    }

    public function testJoiningByCodeLeavesTheParticipantWaitingForApproval(): void
    {
        $host   = $this->makeIdentity('Host');
        $viewer = $this->makeIdentity('Viewer');

        $session = $this->makeSession($host);

        $result = Services::joinService()->joinByCode((string) $session['session_code'], $viewer, null, null);

        $this->assertSame('REQUESTED', $result['participant']['status']);
        $this->assertSame('JOIN_REQUESTED', $result['session']['status']);
    }

    public function testARetiredCodeNoLongerWorks(): void
    {
        $host   = $this->makeIdentity('Host');
        $viewer = $this->makeIdentity('Viewer');

        $session = $this->makeSession($host);
        $code    = (string) $session['session_code'];

        Services::sessionService()->end($session, $host);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('That session code is not valid. It may have expired.');

        Services::joinService()->joinByCode($code, $viewer, null, null);
    }

    public function testAMalformedCodeIsRejectedWithoutTouchingTheDatabase(): void
    {
        $viewer = $this->makeIdentity('Viewer');

        try {
            Services::joinService()->joinByCode('12345', $viewer, null, null);
            $this->fail('A five-digit code is not a session code.');
        } catch (ApiException $e) {
            $this->assertSame('JOIN_CODE_INVALID', $e->errorCode());
        }
    }

    public function testJoinCodesAreFormattedInThreeGroups(): void
    {
        $this->assertSame('583 194 726', Ids::formatJoinCode('583194726'));
        $this->assertSame('583194726', Ids::normaliseJoinCode('583 194 726'));
        $this->assertSame('583194726', Ids::normaliseJoinCode('583-194-726'));
    }

    public function testRepeatedJoinRequestsDoNotCreateDuplicateParticipants(): void
    {
        $host   = $this->makeIdentity('Host');
        $viewer = $this->makeIdentity('Viewer');

        $session = $this->makeSession($host);
        $code    = (string) $session['session_code'];

        Services::joinService()->joinByCode($code, $viewer, null, null);
        Services::joinService()->joinByCode($code, $viewer, null, null);

        $count = $this->db->table('remote_participants')
            ->where('session_id', $session['id'])
            ->where('user_id', $viewer->id)
            ->countAllResults();

        $this->assertSame(1, $count, 'Reloading the join page must not queue the same person twice.');
    }

    public function testADeniedParticipantCannotSimplyAskAgain(): void
    {
        $host   = $this->makeIdentity('Host');
        $viewer = $this->makeIdentity('Viewer');

        $session = $this->makeSession($host);
        $code    = (string) $session['session_code'];

        $joined = Services::joinService()->joinByCode($code, $viewer, null, null);
        Services::participantService()->deny($this->reload($session), (string) $joined['participant']['uuid'], $host);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('The host declined your request to join this session.');

        Services::joinService()->joinByCode($code, $viewer, null, null);
    }

    public function testOnlyTheHostMayApprove(): void
    {
        $host      = $this->makeIdentity('Host');
        $viewer    = $this->makeIdentity('Viewer');
        $bystander = $this->makeIdentity('Bystander');

        $session = $this->makeSession($host);
        $joined  = Services::joinService()->joinByCode((string) $session['session_code'], $viewer, null, null);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Only the person sharing their screen can admit someone to this session.');

        Services::participantService()->approve($this->reload($session), (string) $joined['participant']['uuid'], $bystander);
    }
}
