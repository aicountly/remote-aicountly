<?php

declare(strict_types=1);

namespace Tests\Session;

use App\Domain\Audit\EventType;
use App\Domain\Session\CompanionLinks;
use App\Domain\Session\ControlService;
use App\Domain\Session\ParticipantService;
use App\Domain\Support\ApiException;
use Config\Services;
use Tests\Support\CompanionScenario;
use Tests\Support\RemoteTestCase;

/**
 * Control through a control companion (docs/desktop/CONTROL_COMPANION.md): when it is offered, who may
 * grant it, and everything that takes it away again without anybody pressing Stop.
 *
 * @internal
 */
final class CompanionControlTest extends RemoteTestCase
{
    use CompanionScenario;

    private function expectApiError(string $code, callable $call): ApiException
    {
        try {
            $call();
        } catch (ApiException $exception) {
            $this->assertSame($code, $exception->errorCode(), $exception->getMessage());

            return $exception;
        }

        $this->fail("Expected {$code}.");
    }

    /** @param array<string, mixed> $session */
    private function state(array $session): array
    {
        return Services::controlService()->stateFor($this->reload($session));
    }

    // ------------------------------------------------------- when control exists

    public function testABrowserSessionWithoutACompanionSaysSo(): void
    {
        $fixture = $this->companionScenario();
        $state   = $this->state($fixture['session']);

        $this->assertNull($state['controllableHostUuid']);
        $this->assertSame(ControlService::BLOCKED_NO_COMPANION, $state['controlBlockedReason']);
        $this->assertNull($state['companion']);
        $this->assertSame(['displaySurface' => 'monitor', 'width' => 1920, 'height' => 1080, 'verified' => true], $state['sharedSurface']);
        $this->assertNull($state['grantId']);
    }

    public function testTheCompanionIsControllableOnlyOnceItIsActive(): void
    {
        $fixture    = $this->companionScenario();
        $companions = Services::companionService();

        $link = $companions->link($fixture['session'], $fixture['sharer'], $this->policyOf($fixture['sharer']), (string) $fixture['device']['uuid'])['link'];
        $state = $this->state($fixture['session']);
        $this->assertSame(ControlService::BLOCKED_COMPANION_NOT_READY, $state['controlBlockedReason'], 'PENDING is not ready');
        $this->assertSame('PENDING', $state['companion']['status']);

        $participant = $companions->join($fixture['principal'], (string) $link['uuid'])['participant'];
        $this->assertSame(ControlService::BLOCKED_COMPANION_NOT_READY, $this->state($fixture['session'])['controlBlockedReason'], 'JOINED is not ready');

        $companions->selectMonitor($fixture['principal'], (string) $link['uuid'], $this->monitor(), 'AUTO_SINGLE');
        $state = $this->state($fixture['session']);

        $this->assertNull($state['controlBlockedReason']);
        $this->assertSame((string) $participant['uuid'], $state['controllableHostUuid']);
        $this->assertTrue($state['companion']['ready']);
        $this->assertSame((string) $fixture['sharerParticipant']['uuid'], $state['companion']['linkedParticipantUuid']);
        $this->assertSame('Asha MacBook', $state['companion']['deviceName']);
        $this->assertSame(1512, $state['companion']['monitor']['width']);
    }

    public function testAWindowShareIsNeverControllable(): void
    {
        $fixture = $this->companionScenario([], 'window');
        $this->activeCompanion($fixture);

        $state = $this->state($fixture['session']);
        $this->assertNull($state['controllableHostUuid']);
        $this->assertSame(ControlService::BLOCKED_SURFACE_NOT_MONITOR, $state['controlBlockedReason']);
    }

    public function testABrowserThatDoesNotReportItsSurfaceIsNeverControllable(): void
    {
        // Firefox and Safari report no displaySurface: recorded as 'unknown', and never verified (decision 6).
        $fixture = $this->companionScenario([], 'unknown');
        $this->activeCompanion($fixture);

        $state = $this->state($fixture['session']);
        $this->assertSame(ControlService::BLOCKED_SURFACE_NOT_MONITOR, $state['controlBlockedReason']);
        $this->assertFalse($state['sharedSurface']['verified']);
    }

    public function testARequestWhileTheCompanionIsNotReadyIsRefusedWithTheReason(): void
    {
        $fixture = $this->companionScenario();
        Services::companionService()->link($fixture['session'], $fixture['sharer'], $this->policyOf($fixture['sharer']), (string) $fixture['device']['uuid']);

        $error = $this->expectApiError('HOST_NOT_CONTROLLABLE', fn () => Services::controlService()->request(
            $this->reload($fixture['session']),
            $fixture['viewer'],
            $this->policyOf($fixture['viewer']),
        ));

        $this->assertSame(['controlBlockedReason' => 'COMPANION_NOT_READY'], $error->details());
    }

    public function testThePersonSharingCannotRequestControlOfTheirOwnComputer(): void
    {
        $fixture = $this->companionScenario();
        $this->activeCompanion($fixture);
        $this->setUserPermission($fixture['sharer'], $this->companyId, \App\Domain\Policy\PermissionCatalog::CONTROL_REQUEST, 'ALLOW');

        $this->expectApiError('CONTROL_SELF', fn () => Services::controlService()->request(
            $this->reload($fixture['session']),
            $fixture['sharer'],
            $this->policyOf($fixture['sharer']),
        ));
    }

    // ------------------------------------------------------------- who grants

    public function testTheLinkedSharerGrantsAndTheGrantIsAudited(): void
    {
        $fixture   = $this->companionScenario();
        $companion = $this->activeCompanion($fixture);

        $granted = $this->grantViewer($fixture);

        $this->assertSame(ControlService::STATE_GRANTED, $granted['control_state']);
        $this->assertFalse($granted['clipboard_enabled']);

        $audit = $this->lastAudit(EventType::CONTROL_GRANTED);
        $this->assertSame('USER', $audit['actor_type']);
        $this->assertSame($fixture['sharer']->id, (int) $audit['actor_user_id']);
        $this->assertSame((string) $companion['uuid'], $audit['metadata']['companionParticipantUuid']);

        $state = $this->state($fixture['session']);
        $this->assertSame((string) $fixture['viewerParticipant']['uuid'], $state['controllerUuid']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $state['grantId']);
    }

    public function testNoClipboardThroughACompanionWhateverIsAskedFor(): void
    {
        $fixture = $this->companionScenario();
        $this->activeCompanion($fixture);
        $control = Services::controlService();
        $session = $this->reload($fixture['session']);

        $control->request($session, $fixture['viewer'], $this->policyOf($fixture['viewer']));
        $granted = $control->grant($session, $fixture['sharer'], (string) $fixture['viewerParticipant']['uuid'], $this->policyOf($fixture['sharer']), true);
        $this->assertFalse($granted['clipboard_enabled'], 'decision 3: the clipboard is off for companions in v1');

        $this->expectApiError('CLIPBOARD_SYNC_NOT_ALLOWED', fn () => $control->setClipboard(
            $session,
            $fixture['sharer'],
            (string) $fixture['viewerParticipant']['uuid'],
            true,
            $this->policyOf($fixture['sharer']),
        ));
    }

    public function testOnlyThePersonTheCompanionServesMayGrant(): void
    {
        $fixture = $this->companionScenario();
        $this->activeCompanion($fixture);
        $control = Services::controlService();
        $session = $this->reload($fixture['session']);

        $control->request($session, $fixture['viewer'], $this->policyOf($fixture['viewer']));

        // A second host in the session — still not the person whose screen it is.
        $other = $this->makeIdentity('Chandra co-host');
        $this->grantCompanyAccess($other, $this->companyId);
        $joining = Services::participantService()->requestJoin($session, $other, $other->displayName, ParticipantService::ROLE_VIEWER);
        Services::participantService()->approve($session, (string) $joining['uuid'], $fixture['sharer']);
        $this->db->table('remote_participants')->where('uuid', $joining['uuid'])->update(['is_host' => true, 'status' => 'JOINED']);

        $this->expectApiError('NOT_SESSION_HOST', fn () => $control->grant(
            $session,
            $other,
            (string) $fixture['viewerParticipant']['uuid'],
            $this->policyOf($other),
        ));

        // …and the viewer cannot grant themselves.
        $this->expectApiError('NOT_SESSION_HOST', fn () => $control->grant(
            $session,
            $fixture['viewer'],
            (string) $fixture['viewerParticipant']['uuid'],
            $this->policyOf($fixture['viewer']),
        ));

        $this->assertSame('REQUESTED', Services::participantService()->findByUuidOrFail((string) $fixture['viewerParticipant']['uuid'])['control_state']);
    }

    public function testTheCompanionCanStopControlButNeverGrantIt(): void
    {
        $fixture = $this->companionScenario();
        $this->activeCompanion($fixture);
        $control = Services::controlService();
        $session = $this->reload($fixture['session']);
        $viewerUuid = (string) $fixture['viewerParticipant']['uuid'];

        $control->request($session, $fixture['viewer'], $this->policyOf($fixture['viewer']));

        $this->expectApiError('COMPANION_CANNOT_GRANT', fn () => Services::deviceSessionService()
            ->decideControl($session, $fixture['principal'], $viewerUuid, 'GRANT'));
        $this->assertSame('REQUESTED', Services::participantService()->findByUuidOrFail($viewerUuid)['control_state']);

        $control->grant($session, $fixture['sharer'], $viewerUuid, $this->policyOf($fixture['sharer']));

        $result = Services::deviceSessionService()->decideControl($session, $fixture['principal'], $viewerUuid, 'REVOKE');
        $this->assertSame(ControlService::STATE_REVOKED, $result['participant']['control_state']);
        $this->assertSame('DEVICE', $this->lastAudit(EventType::CONTROL_REVOKED)['actor_type']);
    }

    public function testNothingIsGrantableOnceTheCompanionIsNoLongerReady(): void
    {
        $fixture = $this->companionScenario();
        $this->activeCompanion($fixture);
        $control = Services::controlService();

        $control->request($this->reload($fixture['session']), $fixture['viewer'], $this->policyOf($fixture['viewer']));

        // The person switches to sharing a window before answering.
        $this->share($fixture['session'], $fixture['sharer'], 'window');

        $this->expectApiError('HOST_NOT_CONTROLLABLE', fn () => $control->grant(
            $this->reload($fixture['session']),
            $fixture['sharer'],
            (string) $fixture['viewerParticipant']['uuid'],
            $this->policyOf($fixture['sharer']),
        ));
    }

    public function testEachGrantHasItsOwnGrantId(): void
    {
        $fixture = $this->companionScenario();
        $this->activeCompanion($fixture);

        $this->grantViewer($fixture);
        $first = $this->state($fixture['session'])['grantId'];

        Services::controlService()->revoke($this->reload($fixture['session']), $fixture['sharer']);
        $this->assertNull($this->state($fixture['session'])['grantId']);

        // Granted again a second later: a different grant, so a different id.
        sleep(1);
        $this->grantViewer($fixture);
        $second = $this->state($fixture['session'])['grantId'];

        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
    }

    // ------------------------------------------------------ the three auto-revokes

    public function testSwitchingToAWindowRevokesControlAndAsksForTheMonitorAgain(): void
    {
        $fixture = $this->companionScenario();
        $this->activeCompanion($fixture);
        $this->grantViewer($fixture);

        $this->share($fixture['session'], $fixture['sharer'], 'window');

        $viewer = Services::participantService()->findByUuidOrFail((string) $fixture['viewerParticipant']['uuid']);
        $this->assertSame(ControlService::STATE_REVOKED, $viewer['control_state']);

        $audit = $this->lastAudit(EventType::CONTROL_REVOKED);
        $this->assertSame('SYSTEM', $audit['actor_type']);
        $this->assertSame('SURFACE_CHANGED', $audit['metadata']['reason']);

        $state = $this->state($fixture['session']);
        $this->assertSame('JOINED', $state['companion']['status'], 'a different picture is matched again');
        $this->assertSame(ControlService::BLOCKED_SURFACE_NOT_MONITOR, $state['controlBlockedReason']);
    }

    public function testTheSameMonitorAtAnotherSizeRevokesControlToo(): void
    {
        $fixture = $this->companionScenario();
        $this->activeCompanion($fixture);
        $this->grantViewer($fixture);

        $this->share($fixture['session'], $fixture['sharer'], 'monitor', 2560, 1440);

        $this->assertSame(ControlService::STATE_REVOKED, Services::participantService()->findByUuidOrFail((string) $fixture['viewerParticipant']['uuid'])['control_state']);
        $this->assertSame('SURFACE_CHANGED', $this->lastAudit(EventType::CONTROL_REVOKED)['metadata']['reason']);
        $this->assertSame(ControlService::BLOCKED_COMPANION_NOT_READY, $this->state($fixture['session'])['controlBlockedReason']);
    }

    public function testTheSameShareReportedAgainChangesNothing(): void
    {
        $fixture = $this->companionScenario();
        $this->activeCompanion($fixture);
        $this->grantViewer($fixture);

        $this->share($fixture['session'], $fixture['sharer'], 'monitor', 1920, 1080);

        $this->assertSame(ControlService::STATE_GRANTED, Services::participantService()->findByUuidOrFail((string) $fixture['viewerParticipant']['uuid'])['control_state']);
        $this->assertNull($this->state($fixture['session'])['controlBlockedReason']);
    }

    public function testStoppingTheShareRevokesControl(): void
    {
        $fixture = $this->companionScenario();
        $this->activeCompanion($fixture);
        $this->grantViewer($fixture);

        Services::sessionService()->recordShareStopped($this->reload($fixture['session']), $fixture['sharer']);

        $this->assertSame(ControlService::STATE_REVOKED, Services::participantService()->findByUuidOrFail((string) $fixture['viewerParticipant']['uuid'])['control_state']);
        $audit = $this->lastAudit(EventType::CONTROL_REVOKED);
        $this->assertSame('SYSTEM', $audit['actor_type']);
        $this->assertSame('SHARE_STOPPED', $audit['metadata']['reason']);

        $state = $this->state($fixture['session']);
        $this->assertSame(ControlService::BLOCKED_NOT_SHARING, $state['controlBlockedReason']);
        $this->assertSame(['displaySurface' => null, 'width' => null, 'height' => null, 'verified' => false], $state['sharedSurface']);
    }

    public function testTheSharerLeavingEndsTheLinkAndControl(): void
    {
        $fixture   = $this->companionScenario();
        $companion = $this->activeCompanion($fixture);
        $this->grantViewer($fixture);

        Services::participantService()->leave($this->reload($fixture['session']), (string) $fixture['sharerParticipant']['uuid']);

        $this->assertSame(ControlService::STATE_REVOKED, Services::participantService()->findByUuidOrFail((string) $fixture['viewerParticipant']['uuid'])['control_state']);
        $this->assertSame('SHARER_LEFT', $this->lastAudit(EventType::CONTROL_REVOKED)['metadata']['reason']);

        $state = $this->state($fixture['session']);
        $this->assertSame('ENDED', $state['companion']['status']);
        $this->assertSame('SHARER_LEFT', $state['companion']['endReason']);
        $this->assertSame('LEFT', Services::participantService()->findByUuidOrFail((string) $companion['uuid'])['status']);

        $ended = $this->lastAudit(EventType::CONTROL_COMPANION_ENDED);
        $this->assertSame('SYSTEM', $ended['actor_type']);
        $this->assertSame('SHARER_LEFT', $ended['metadata']['reason']);
    }

    public function testUnlinkingFromTheBrowserEndsControl(): void
    {
        $fixture = $this->companionScenario();
        $this->activeCompanion($fixture);
        $this->grantViewer($fixture);

        $link = Services::companionService()->unlink($this->reload($fixture['session']), $fixture['sharer']);

        $this->assertSame('ENDED', $link['status']);
        $this->assertSame('ENDED_BY_USER', $link['end_reason']);
        $this->assertSame(ControlService::STATE_REVOKED, Services::participantService()->findByUuidOrFail((string) $fixture['viewerParticipant']['uuid'])['control_state']);
        $this->assertSame('USER', $this->lastAudit(EventType::CONTROL_REVOKED)['actor_type']);

        // Idempotent: nothing more is recorded.
        $before = $this->db->table('remote_audit_logs')->countAllResults();
        Services::companionService()->unlink($this->reload($fixture['session']), $fixture['sharer']);
        $this->assertSame($before, $this->db->table('remote_audit_logs')->countAllResults());
    }

    public function testTheViewerCannotUnlinkSomebodyElsesComputer(): void
    {
        $fixture = $this->companionScenario();
        $this->activeCompanion($fixture);

        $this->expectApiError('NOT_SESSION_HOST', fn () => Services::companionService()
            ->unlink($this->reload($fixture['session']), $fixture['viewer']));
    }

    public function testEndingTheSessionEndsTheLink(): void
    {
        $fixture = $this->companionScenario();
        $this->activeCompanion($fixture);

        Services::sessionService()->end($this->reload($fixture['session']), $fixture['sharer']);

        $this->assertSame(0, $this->db->table('remote_control_companions')->whereIn('status', CompanionLinks::LIVE)->countAllResults());
        $this->assertSame('SESSION_ENDED', $this->lastAudit(EventType::CONTROL_COMPANION_ENDED)['metadata']['reason']);
    }

    // ------------------------------------------------------------ bookkeeping

    public function testSharingMarksThePersonNotTheirMachine(): void
    {
        $fixture   = $this->companionScenario();
        $companion = $this->activeCompanion($fixture);

        $this->share($fixture['session'], $fixture['sharer'], 'monitor', 1280, 800);

        $this->assertFalse(Services::participantService()->findByUuidOrFail((string) $companion['uuid'])['is_sharing']);
        $this->assertTrue(Services::participantService()->findByUuidOrFail((string) $fixture['sharerParticipant']['uuid'])['is_sharing']);
    }

    public function testACompanionIsNeverSomebodyToSendAFileTo(): void
    {
        $fixture   = $this->companionScenario(['allow_file_transfer' => true]);
        $companion = $this->activeCompanion($fixture);
        $this->setUserPermission($fixture['viewer'], $this->companyId, \App\Domain\Policy\PermissionCatalog::FILE_SEND, 'ALLOW');

        $files   = Services::fileTransferService();
        $session = $this->reload($fixture['session']);
        $viewer  = Services::participantService()->findByUuidOrFail((string) $fixture['viewerParticipant']['uuid']);

        // Two other participants in the room, but only one person: no "choose who" for a machine's helper.
        $offer = $files->offer($session, $viewer, null, 'notes.txt', 12, 'text/plain', $this->policyOf($fixture['viewer']));
        $this->assertSame((int) $fixture['sharerParticipant']['id'], (int) $offer['to_participant_id']);

        $this->expectApiError('NOT_FOUND', fn () => $files->offer(
            $session,
            $viewer,
            (string) $companion['uuid'],
            'notes.txt',
            12,
            'text/plain',
            $this->policyOf($fixture['viewer']),
        ));
    }

    public function testTheShareSizeIsRecordedOnlyWhenItIsPlausible(): void
    {
        $fixture = $this->companionScenario([], null);

        $session = $this->share($fixture['session'], $fixture['sharer'], 'monitor', 99999, 1080);
        $this->assertNull($session['actual_surface_width']);
        $this->assertNull($session['actual_surface_height']);

        $session = $this->share($fixture['session'], $fixture['sharer'], 'monitor', 3024, 1964);
        $this->assertSame(3024, (int) $session['actual_surface_width']);
    }
}
