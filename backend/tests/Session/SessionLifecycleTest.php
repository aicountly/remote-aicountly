<?php

declare(strict_types=1);

namespace Tests\Session;

use App\Domain\Audit\EventType;
use App\Domain\Session\SessionStatus;
use App\Domain\Support\ApiException;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * Session creation, sharing, surface enforcement and ending (§21, §16, §26).
 *
 * @internal
 */
final class SessionLifecycleTest extends RemoteTestCase
{
    public function testCreatingASessionProducesAHostAndAJoinCode(): void
    {
        $identity = $this->makeIdentity('Rahul Gupta');

        $session = $this->makeSession($identity);

        $this->assertSame(SessionStatus::WAITING, $session['status']);
        $this->assertMatchesRegularExpression('/^AR-\d+$/', (string) $session['display_id']);
        $this->assertMatchesRegularExpression('/^\d{9}$/', (string) $session['session_code']);
        $this->assertNotSame((string) $session['id'], (string) $session['uuid']);

        $participants = Services::participantService()->forSession((int) $session['id']);
        $this->assertCount(1, $participants);
        $this->assertSame('SHARER', $participants[0]['participant_role']);
        $this->assertSame('JOINED', $participants[0]['status']);

        $this->assertHasEvent($session, EventType::SESSION_CREATED);
        $this->assertHasAudit(EventType::SESSION_CREATED);
    }

    public function testPersonalSessionCarriesNoCompanyContext(): void
    {
        $identity = $this->makeIdentity();

        $session = $this->makeSession($identity, 'PERSONAL', null, [
            'branchId'        => 12,
            'financialYearId' => 2026,
        ]);

        // The database constraint backs this up, but the service must not even
        // attempt it: a personal session with a branch is a contradiction (§5).
        $this->assertNull($session['company_id']);
        $this->assertNull($session['branch_id']);
        $this->assertNull($session['financial_year_id']);
    }

    public function testMonitorSurfaceIsRejectedWhenPolicyForbidsIt(): void
    {
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481, 'ABC', ['allow_entire_monitor' => false]);
        $this->grantCompanyAccess($identity, $company, 'COMPANY_ADMIN', true);

        $session = $this->makeSession($identity, 'COMPANY', $company);
        $policy  = Services::policyResolver()->resolve($identity, 'COMPANY', $company);

        try {
            Services::sessionService()->recordShareStarted($session, $identity, 'monitor', $policy);
            $this->fail('A forbidden surface must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('SURFACE_NOT_ALLOWED', $e->errorCode());
            $this->assertSame(403, $e->status());
        }

        $reloaded = $this->reload($session);
        $this->assertNull($reloaded['actual_display_surface'], 'Nothing may be recorded as shared after a refusal.');
        $this->assertNotSame(SessionStatus::ACTIVE, $reloaded['status']);

        $this->assertHasEvent($session, EventType::POLICY_REJECTED);
    }

    public function testPermittedSurfaceStartsSharingAndRecordsTheSurface(): void
    {
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481, 'ABC', ['allow_browser_tab' => true]);
        $this->grantCompanyAccess($identity, $company);

        $session = $this->makeSession($identity, 'COMPANY', $company, ['requestedShareMode' => 'BROWSER_TAB']);
        $policy  = Services::policyResolver()->resolve($identity, 'COMPANY', $company);

        $updated = Services::sessionService()->recordShareStarted($session, $identity, 'browser', $policy);

        $this->assertSame(SessionStatus::ACTIVE, $updated['status']);
        $this->assertSame('browser', $updated['actual_display_surface']);
        $this->assertNotNull($updated['started_at']);

        $this->assertHasEvent($session, EventType::SURFACE_BROWSER_SELECTED);
        $this->assertHasEvent($session, EventType::SCREEN_SHARE_STARTED);
    }

    public function testAnUnreportedSurfaceIsAllowedButRecordedAsUnverified(): void
    {
        // Firefox and Safari do not report displaySurface. Refusing outright
        // would make Remote unusable there, so the session proceeds under the
        // mode policy already authorised — and says the surface is unverified
        // rather than pretending it was checked (§15).
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481, 'ABC', ['allow_entire_monitor' => false]);
        $this->grantCompanyAccess($identity, $company);

        $session = $this->makeSession($identity, 'COMPANY', $company);
        $policy  = Services::policyResolver()->resolve($identity, 'COMPANY', $company);

        $updated = Services::sessionService()->recordShareStarted($session, $identity, '', $policy);

        $this->assertSame('unknown', $updated['actual_display_surface']);

        $event = $this->db->table('remote_session_events')
            ->where('session_id', $session['id'])
            ->where('event_type', EventType::SCREEN_SHARE_STARTED)
            ->get()
            ->getRowArray();

        $metadata = json_decode((string) $event['metadata'], true);
        $this->assertFalse($metadata['verified'], 'An unreported surface must never be recorded as verified.');
    }

    public function testStoppingSharingKeepsTheSessionOpen(): void
    {
        // §86 — the browser's own Stop Sharing must not tear down the session;
        // chat continues and the user can share again.
        $identity = $this->makeIdentity();
        $session  = $this->makeSession($identity);
        $policy   = Services::policyResolver()->resolve($identity, 'PERSONAL', null);

        $active = Services::sessionService()->recordShareStarted($session, $identity, 'browser', $policy);
        $this->assertSame(SessionStatus::ACTIVE, $active['status']);

        $stopped = Services::sessionService()->recordShareStopped($active, $identity, 'BROWSER_ENDED');

        $this->assertSame(SessionStatus::ACTIVE, $stopped['status'], 'The session stays open.');
        $this->assertNull($stopped['actual_display_surface']);
        $this->assertHasEvent($session, EventType::SCREEN_SHARE_STOPPED);
    }

    public function testEndingASessionRetiresItsJoinCodeAndInvitations(): void
    {
        $identity = $this->makeIdentity();
        $session  = $this->makeSession($identity);
        $policy   = Services::policyResolver()->resolve($identity, 'PERSONAL', null);

        Services::invitationService()->create($session, $identity, $policy, 'INTERNAL', null, null);

        $ended = Services::sessionService()->end($session, $identity);

        $this->assertSame(SessionStatus::ENDED, $ended['status']);
        $this->assertNull($ended['session_code'], 'A retired code must stop working immediately.');
        $this->assertNotNull($ended['ended_at']);

        $live = $this->db->table('remote_invitations')
            ->where('session_id', $session['id'])
            ->where('revoked_at', null)
            ->countAllResults();
        $this->assertSame(0, $live, 'Ending a session withdraws its outstanding invitations.');
    }

    public function testEndingAnEndedSessionIsIdempotent(): void
    {
        $identity = $this->makeIdentity();
        $session  = $this->makeSession($identity);

        $first  = Services::sessionService()->end($session, $identity);
        $second = Services::sessionService()->end($first, $identity);

        $this->assertSame(SessionStatus::ENDED, $second['status']);
        $this->assertSame($first['ended_at'], $second['ended_at'], 'A second end must not move the timestamp.');
    }

    public function testAnExpiredSessionIsExpiredOnTheNextRead(): void
    {
        // There is no scheduler on a cPanel host, so expiry happens on read.
        $identity = $this->makeIdentity();
        $session  = $this->makeSession($identity);

        $this->db->table('remote_sessions')
            ->where('id', $session['id'])
            ->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 60) . '+00']);

        $reloaded = $this->reload($session);

        $this->assertSame(SessionStatus::EXPIRED, $reloaded['status']);
        $this->assertNull($reloaded['session_code']);
        $this->assertHasEvent($session, EventType::SESSION_EXPIRED);
    }

    public function testPauseAndResumeAreHostOnly(): void
    {
        $host   = $this->makeIdentity('Host');
        $viewer = $this->makeIdentity('Viewer');

        $session = $this->makeSession($host);
        $active  = Services::sessionService()->markActive($session);

        $paused = Services::sessionService()->pause($active, $host);
        $this->assertSame(SessionStatus::PAUSED, $paused['status']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Only the person who started this session can resume it.');
        Services::sessionService()->resume($paused, $viewer);
    }

    public function testSessionQuotaIsEnforcedWhenThePlanSetsOne(): void
    {
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481);
        $this->grantCompanyAccess($identity, $company);
        $this->setEntitlement($company, ['max_monthly_sessions' => 1]);

        $this->makeSession($identity, 'COMPANY', $company);

        try {
            $this->makeSession($identity, 'COMPANY', $company);
            $this->fail('The second session should exceed the plan allowance.');
        } catch (ApiException $e) {
            $this->assertSame('SESSION_QUOTA_REACHED', $e->errorCode());
        }
    }

    public function testCreatingASessionIsRefusedWhenRemoteIsDisabled(): void
    {
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481, 'ABC', [
            'remote_enabled'           => false,
            'allow_safe_share'         => false,
            'allow_browser_tab'        => false,
            'allow_application_window' => false,
            'allow_entire_monitor'     => false,
        ]);
        $this->grantCompanyAccess($identity, $company);

        try {
            $this->makeSession($identity, 'COMPANY', $company);
            $this->fail('Remote is disabled for this company.');
        } catch (ApiException $e) {
            $this->assertSame('COMPANY_REMOTE_DISABLED', $e->errorCode());
        }

        $this->assertSame(0, $this->db->table('remote_sessions')->countAllResults(), 'Nothing may be written on a refusal.');
    }

    public function testSessionSnapshotsPolicyAtCreationTime(): void
    {
        // An administrator turning chat off stops the *next* session, not one
        // two people are already talking in.
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481, 'ABC', ['allow_text_chat' => true]);
        $this->grantCompanyAccess($identity, $company);

        $session = $this->makeSession($identity, 'COMPANY', $company);
        $this->assertTrue($session['allow_chat']);

        $this->db->table('remote_company_policies')->where('company_id', $company)->update(['allow_text_chat' => false]);

        $this->assertTrue($this->reload($session)['allow_chat'], 'The running session keeps its snapshot.');

        $next = $this->makeSession($identity, 'COMPANY', $company);
        $this->assertFalse($next['allow_chat'], 'The next session picks up the new policy.');
    }
}
