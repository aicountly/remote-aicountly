<?php

declare(strict_types=1);

namespace Tests\Device;

use App\Domain\Audit\EventType;
use App\Domain\Device\DevicePrincipal;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Session\ControlService;
use App\Domain\Session\ParticipantService;
use App\Domain\Support\ApiException;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * The machine answering for itself.
 *
 * A desktop agent's gate is local: pressing Stop control stops input on the
 * next message, with no network involved. What this covers is the *other*
 * half — telling the server, so the browser stops sending, the session screen
 * updates and the audit trail records who decided.
 *
 * The properties asserted here are the ones that keep that from becoming a way
 * around the policy:
 *
 *   * a machine cannot consent more widely than the person it belongs to may;
 *   * a machine cannot answer for a session it is not in, or for a session in
 *     another organisation;
 *   * ending control needs no permission at all, in either direction.
 *
 * @internal
 */
final class DeviceControlDecisionTest extends RemoteTestCase
{
    private int $companyId = 1050;

    /**
     * A live session hosted by an enrolled machine, with a viewer waiting.
     *
     * @return array{session: array<string, mixed>, owner: \App\Domain\Auth\RemoteIdentity,
     *               viewer: \App\Domain\Auth\RemoteIdentity, device: array<string, mixed>,
     *               principal: DevicePrincipal, viewerParticipant: array<string, mixed>}
     */
    private function scenario(array $policy = []): array
    {
        $owner  = $this->makeIdentity('Priya at the desk');
        $viewer = $this->makeIdentity('Sam in support');

        $this->makeDesktopCompany($this->companyId, 'Agent Control Co', $policy);
        $this->grantCompanyAccess($owner, $this->companyId, 'MEMBER', true);
        $this->grantCompanyAccess($viewer, $this->companyId, 'MEMBER');
        $this->setUserPermission($owner, $this->companyId, PermissionCatalog::UNATTENDED_ACCESS, 'ALLOW');
        $this->setUserPermission($viewer, $this->companyId, PermissionCatalog::CONTROL_REQUEST, 'ALLOW');

        ['device' => $device] = $this->enrolDevice($owner, $this->companyId);

        Services::deviceService()->enableUnattended($owner, (string) $device['uuid'], true);
        $this->markDeviceOnline((string) $device['uuid']);

        // An unattended connection is an ordinary session, and it is the one
        // place a device participant is created by the real service — which is
        // exactly the row this test needs to be about.
        $result  = Services::deviceSessionService()->startUnattended($owner, (string) $device['uuid']);
        $session = $result['session'];

        $viewerParticipant = Services::participantService()->requestJoin(
            $session,
            $viewer,
            $viewer->displayName,
            ParticipantService::ROLE_VIEWER,
        );
        Services::participantService()->approve($session, (string) $viewerParticipant['uuid'], $owner);

        $principal = new DevicePrincipal(
            (string) $device['uuid'],
            $this->companyId,
            DevicePrincipal::agentScopes(),
            time() + 300,
        );

        return [
            'session'           => $session,
            'owner'             => $owner,
            'viewer'            => $viewer,
            'device'            => $device,
            'principal'         => $principal,
            'viewerParticipant' => Services::participantService()
                ->findByUuidOrFail((string) $viewerParticipant['uuid']),
        ];
    }

    private function request(array $session, $viewer): array
    {
        return Services::controlService()->request(
            $session,
            $viewer,
            Services::policyResolver()->resolve($viewer, 'COMPANY', $this->companyId),
        );
    }

    // ----------------------------------------------------------- the decision

    public function testTheMachineCanGrantControlAndTheGrantIsRecordedAgainstIt(): void
    {
        [
            'session'   => $session,
            'viewer'    => $viewer,
            'principal' => $principal,
        ] = $this->scenario();

        $this->request($session, $viewer);

        $result = Services::deviceSessionService()->decideControl(
            $session,
            $principal,
            (string) Services::participantService()->findByUser((int) $session['id'], $viewer->id)['uuid'],
            'GRANT',
        );

        $this->assertSame(ControlService::STATE_GRANTED, $result['participant']['control_state']);
        $this->assertHasEvent($session, EventType::CONTROL_GRANTED);

        // Recorded as the machine deciding, not as the person who happens to
        // own it having clicked something in a browser.
        $event = $this->db->table('remote_session_events')
            ->where('session_id', $session['id'])
            ->where('event_type', EventType::CONTROL_GRANTED)
            ->get()
            ->getRowArray();

        $this->assertSame('DEVICE', $event['actor_type']);
    }

    /**
     * **The rule that keeps this from being a way round the policy.** The
     * capability a device declared at enrolment was only ever an upper bound.
     */
    public function testAMachineCannotConsentWhenTheOrganisationForbidsControl(): void
    {
        [
            'session'   => $session,
            'viewer'    => $viewer,
            'principal' => $principal,
        ] = $this->scenario();

        $this->request($session, $viewer);

        $viewerUuid = (string) Services::participantService()
            ->findByUser((int) $session['id'], $viewer->id)['uuid'];

        // The organisation turns remote control off mid-session.
        $this->db->table('remote_company_policies')
            ->where('company_id', $this->companyId)
            ->update([
                'allow_remote_control'    => false,
                'allow_unattended_access' => false,
                'allow_clipboard_sync'    => false,
                'allow_device_reboot'     => false,
            ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('not enabled for this organisation');

        Services::deviceSessionService()->decideControl($session, $principal, $viewerUuid, 'GRANT');
    }

    public function testAMachineCannotConsentWhenItsOwnerLacksThePermission(): void
    {
        [
            'session'   => $session,
            'owner'     => $owner,
            'viewer'    => $viewer,
            'principal' => $principal,
        ] = $this->scenario();

        $this->request($session, $viewer);
        $this->setUserPermission($owner, $this->companyId, PermissionCatalog::CONTROL_ACCEPT, 'DENY');

        $viewerUuid = (string) Services::participantService()
            ->findByUser((int) $session['id'], $viewer->id)['uuid'];

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('not permitted to hand over control');

        Services::deviceSessionService()->decideControl($session, $principal, $viewerUuid, 'GRANT');
    }

    public function testTheClipboardIsASeparateDecisionAndOffUnlessAskedFor(): void
    {
        [
            'session'   => $session,
            'viewer'    => $viewer,
            'principal' => $principal,
        ] = $this->scenario();

        $this->request($session, $viewer);
        $viewerUuid = (string) Services::participantService()
            ->findByUser((int) $session['id'], $viewer->id)['uuid'];

        $granted = Services::deviceSessionService()
            ->decideControl($session, $principal, $viewerUuid, 'GRANT');

        $this->assertFalse($granted['participant']['clipboard_enabled']);
    }

    public function testTheClipboardStaysOffWhenTheOrganisationForbidsIt(): void
    {
        [
            'session'   => $session,
            'viewer'    => $viewer,
            'principal' => $principal,
        ] = $this->scenario(['allow_clipboard_sync' => false]);

        $this->request($session, $viewer);
        $viewerUuid = (string) Services::participantService()
            ->findByUser((int) $session['id'], $viewer->id)['uuid'];

        $granted = Services::deviceSessionService()
            ->decideControl($session, $principal, $viewerUuid, 'GRANT', true);

        $this->assertSame(ControlService::STATE_GRANTED, $granted['participant']['control_state']);
        $this->assertFalse($granted['participant']['clipboard_enabled']);
    }

    /**
     * Requiring a permission to *stop* being controlled would be an obvious
     * mistake, so there is none — even when every other switch has been turned
     * off underneath the session.
     */
    public function testStoppingControlNeedsNoPermissionAtAll(): void
    {
        [
            'session'   => $session,
            'owner'     => $owner,
            'viewer'    => $viewer,
            'principal' => $principal,
        ] = $this->scenario();

        $this->request($session, $viewer);
        $viewerUuid = (string) Services::participantService()
            ->findByUser((int) $session['id'], $viewer->id)['uuid'];

        Services::deviceSessionService()->decideControl($session, $principal, $viewerUuid, 'GRANT');

        $this->setUserPermission($owner, $this->companyId, PermissionCatalog::CONTROL_ACCEPT, 'DENY');
        $this->db->table('remote_company_policies')
            ->where('company_id', $this->companyId)
            ->update([
                'allow_remote_control'    => false,
                'allow_unattended_access' => false,
                'allow_clipboard_sync'    => false,
                'allow_device_reboot'     => false,
            ]);

        $revoked = Services::deviceSessionService()
            ->decideControl($session, $principal, $viewerUuid, 'REVOKE');

        $this->assertSame(ControlService::STATE_REVOKED, $revoked['participant']['control_state']);
        $this->assertFalse($revoked['participant']['clipboard_enabled']);
        $this->assertHasEvent($session, EventType::CONTROL_REVOKED);
    }

    public function testDecliningIsRecordedAsADenialRatherThanARevocation(): void
    {
        [
            'session'   => $session,
            'viewer'    => $viewer,
            'principal' => $principal,
        ] = $this->scenario();

        $this->request($session, $viewer);
        $viewerUuid = (string) Services::participantService()
            ->findByUser((int) $session['id'], $viewer->id)['uuid'];

        $denied = Services::deviceSessionService()
            ->decideControl($session, $principal, $viewerUuid, 'DENY');

        $this->assertSame(ControlService::STATE_DENIED, $denied['participant']['control_state']);
        $this->assertHasEvent($session, EventType::CONTROL_DENIED);
    }

    // ---------------------------------------------------------- what it sees

    /**
     * The agent polls the API for who is waiting rather than believing the
     * peer. A request that reached the API is the only kind that can be
     * granted, so it is the only kind that should put a dialog in front of
     * somebody.
     */
    public function testTheMachineSeesWhoIsWaitingAndWhatIsPermitted(): void
    {
        [
            'session'   => $session,
            'viewer'    => $viewer,
            'principal' => $principal,
        ] = $this->scenario();

        $before = Services::deviceSessionService()->controlStateFor($session, $principal);
        $this->assertSame([], $before['pendingRequests']);
        $this->assertTrue($before['allowRemoteControl']);
        $this->assertTrue($before['allowClipboardSync']);

        $this->request($session, $viewer);

        $after = Services::deviceSessionService()->controlStateFor($session, $principal);
        $this->assertCount(1, $after['pendingRequests']);
        $this->assertSame('Sam in support', $after['pendingRequests'][0]['displayName']);
        $this->assertNotNull($after['controllableHostUuid']);
    }

    public function testTheStateReflectsAPolicyTurnedOffUnderneathTheSession(): void
    {
        [
            'session'   => $session,
            'principal' => $principal,
        ] = $this->scenario();

        $this->db->table('remote_company_policies')
            ->where('company_id', $this->companyId)
            ->update([
                'allow_remote_control'    => false,
                'allow_unattended_access' => false,
                'allow_clipboard_sync'    => false,
                'allow_device_reboot'     => false,
            ]);

        $state = Services::deviceSessionService()->controlStateFor($session, $principal);

        $this->assertFalse($state['allowRemoteControl']);
        $this->assertFalse($state['allowClipboardSync']);
        $this->assertFalse($state['allowDeviceReboot']);
    }

    public function testAMachineCannotReadTheControlStateOfASessionItIsNotIn(): void
    {
        [
            'session' => $session,
            'owner'   => $owner,
        ] = $this->scenario();

        ['device' => $other] = $this->enrolDevice($owner, $this->companyId, [
            'deviceName' => 'Another Workstation',
            'hostname'   => 'WS-TEST-03',
        ]);

        $intruder = new DevicePrincipal(
            (string) $other['uuid'],
            $this->companyId,
            DevicePrincipal::agentScopes(),
            time() + 300,
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('not part of that Remote session');

        Services::deviceSessionService()->controlStateFor($session, $intruder);
    }

    // ------------------------------------------------------------- isolation

    /** A machine answering for a session it is not in is answering a question nobody asked it. */
    public function testAMachineCannotAnswerForASessionItIsNotIn(): void
    {
        [
            'session' => $session,
            'owner'   => $owner,
            'viewer'  => $viewer,
        ] = $this->scenario();

        $this->request($session, $viewer);
        $viewerUuid = (string) Services::participantService()
            ->findByUser((int) $session['id'], $viewer->id)['uuid'];

        // A second machine in the same organisation, never added to this session.
        ['device' => $other] = $this->enrolDevice($owner, $this->companyId, [
            'deviceName' => 'Another Workstation',
            'hostname'   => 'WS-TEST-02',
        ]);

        $intruder = new DevicePrincipal(
            (string) $other['uuid'],
            $this->companyId,
            DevicePrincipal::agentScopes(),
            time() + 300,
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('not part of that Remote session');

        Services::deviceSessionService()->decideControl($session, $intruder, $viewerUuid, 'GRANT');
    }

    /** Tenant isolation: another organisation's session is not found, not forbidden. */
    public function testAMachineFromAnotherOrganisationFindsNoSession(): void
    {
        [
            'session' => $session,
            'viewer'  => $viewer,
        ] = $this->scenario();

        $this->request($session, $viewer);
        $viewerUuid = (string) Services::participantService()
            ->findByUser((int) $session['id'], $viewer->id)['uuid'];

        $elsewhere = $this->makeIdentity('Someone Else');
        $otherCompany = $this->makeDesktopCompany($this->companyId + 1, 'Other Co');
        $this->grantCompanyAccess($elsewhere, $otherCompany, 'MEMBER', true);

        ['device' => $foreign] = $this->enrolDevice($elsewhere, $otherCompany, [
            'deviceName' => 'Foreign Workstation',
            'hostname'   => 'WS-OTHER-01',
        ]);

        $principal = new DevicePrincipal(
            (string) $foreign['uuid'],
            $otherCompany,
            DevicePrincipal::agentScopes(),
            time() + 300,
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('could not be found');

        Services::deviceSessionService()->decideControl($session, $principal, $viewerUuid, 'GRANT');
    }

    public function testGrantingIsRefusedForSomebodyWhoNeverAsked(): void
    {
        [
            'session'   => $session,
            'viewer'    => $viewer,
            'principal' => $principal,
        ] = $this->scenario();

        $viewerUuid = (string) Services::participantService()
            ->findByUser((int) $session['id'], $viewer->id)['uuid'];

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('not waiting for control');

        Services::deviceSessionService()->decideControl($session, $principal, $viewerUuid, 'GRANT');
    }

    public function testAnUnknownDecisionIsRefusedRatherThanGuessedAt(): void
    {
        [
            'session'   => $session,
            'viewer'    => $viewer,
            'principal' => $principal,
        ] = $this->scenario();

        $this->request($session, $viewer);
        $viewerUuid = (string) Services::participantService()
            ->findByUser((int) $session['id'], $viewer->id)['uuid'];

        $this->expectException(ApiException::class);

        Services::deviceSessionService()->decideControl($session, $principal, $viewerUuid, 'ALLOW_EVERYTHING');
    }
}
