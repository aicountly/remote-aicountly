<?php

declare(strict_types=1);

namespace Tests\Session;

use App\Domain\Audit\EventType;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Session\ClientCapabilities;
use App\Domain\Session\ControlService;
use App\Domain\Session\ParticipantService;
use App\Domain\Support\ApiException;
use App\Domain\Support\Clock;
use App\Domain\Support\Ids;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * Attended remote control: the consent, the grant, and the instant revocation.
 *
 * @internal
 */
final class RemoteControlTest extends RemoteTestCase
{
    private int $companyId = 950;

    /**
     * A session whose host is a Windows agent and whose viewer is a browser.
     *
     * @return array{session: array<string, mixed>, host: \App\Domain\Auth\RemoteIdentity,
     *               viewer: \App\Domain\Auth\RemoteIdentity, agent: array<string, mixed>,
     *               viewerParticipant: array<string, mixed>}
     */
    private function scenario(array $policy = [], bool $controllableHost = true): array
    {
        $host   = $this->makeIdentity('Priya at the desk');
        $viewer = $this->makeIdentity('Sam in support');

        $company = $this->makeDesktopCompany($this->companyId, 'Control Co', $policy);
        $this->grantCompanyAccess($host, $company, 'MEMBER', true);
        $this->grantCompanyAccess($viewer, $company, 'MEMBER');
        $this->setUserPermission($viewer, $company, PermissionCatalog::CONTROL_REQUEST, 'ALLOW');

        $session = $this->makeSession($host, 'COMPANY', $company);

        // The host participant is the machine: a DESKTOP_AGENT that reports it
        // can be controlled. In production this row is written by
        // DeviceSessionService; here it is written directly so the test is
        // about control rather than about enrolment.
        $capabilities = $controllableHost
            ? ClientCapabilities::desktopAgent()
            : ClientCapabilities::browser();

        $hostParticipant = Services::participantService()->findByUser((int) $session['id'], $host->id);
        $this->db->table('remote_participants')->where('id', $hostParticipant['id'])->update([
            'client_type'  => $controllableHost ? 'DESKTOP_AGENT' : 'BROWSER',
            'capabilities' => json_encode($capabilities),
        ]);

        $viewerParticipant = Services::participantService()->requestJoin(
            $session,
            $viewer,
            $viewer->displayName,
            ParticipantService::ROLE_VIEWER,
        );
        Services::participantService()->approve($session, (string) $viewerParticipant['uuid'], $host);

        return [
            'session'           => $session,
            'host'              => $host,
            'viewer'            => $viewer,
            'agent'             => $capabilities,
            'viewerParticipant' => Services::participantService()->findByUuidOrFail((string) $viewerParticipant['uuid']),
        ];
    }

    private function policyFor($identity): \App\Domain\Policy\EffectivePolicy
    {
        return Services::policyResolver()->resolve($identity, 'COMPANY', $this->companyId);
    }

    public function testTheFullRequestGrantRevokeCycle(): void
    {
        ['session' => $session, 'host' => $host, 'viewer' => $viewer] = $this->scenario();
        $control = Services::controlService();

        $requested = $control->request($session, $viewer, $this->policyFor($viewer));
        $this->assertSame(ControlService::STATE_REQUESTED, $requested['control_state']);
        $this->assertHasEvent($session, EventType::CONTROL_REQUESTED);

        $granted = $control->grant($session, $host, (string) $requested['uuid'], $this->policyFor($host));
        $this->assertSame(ControlService::STATE_GRANTED, $granted['control_state']);
        $this->assertNotNull($granted['control_granted_at']);
        // Clipboard is not a side effect of control. It was not asked for.
        $this->assertFalse($granted['clipboard_enabled']);
        $this->assertHasEvent($session, EventType::CONTROL_GRANTED);

        $state = $control->stateFor($session);
        $this->assertSame((string) $requested['uuid'], $state['controllerUuid']);

        $revoked = $control->revoke($session, $host, (string) $requested['uuid']);
        $this->assertSame(ControlService::STATE_REVOKED, $revoked['control_state']);
        $this->assertFalse($revoked['clipboard_enabled']);
        $this->assertHasEvent($session, EventType::CONTROL_REVOKED);

        $this->assertNull($control->stateFor($session)['controllerUuid']);
    }

    /**
     * The rule the desktop story rests on: the UI grows control because a
     * participant *reported* it can be controlled, never because some branch
     * looked at `clientType`. A browser host reports `remote_control: false`,
     * so control of one is refused rather than left pending forever.
     */
    public function testControlOfABrowserHostIsRefused(): void
    {
        ['session' => $session, 'viewer' => $viewer] = $this->scenario([], false);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('cannot be controlled');

        Services::controlService()->request($session, $viewer, $this->policyFor($viewer));
    }

    public function testRequestingControlNeedsTheCompanySwitch(): void
    {
        ['session' => $session, 'viewer' => $viewer] = $this->scenario(['allow_remote_control' => false]);

        try {
            Services::controlService()->request($session, $viewer, $this->policyFor($viewer));
            $this->fail('Expected remote control to be refused.');
        } catch (ApiException $exception) {
            $this->assertSame('REMOTE_CONTROL_NOT_ALLOWED', $exception->errorCode());
        }
    }

    public function testRequestingControlNeedsThePermission(): void
    {
        ['session' => $session, 'viewer' => $viewer] = $this->scenario();
        $this->setUserPermission($viewer, $this->companyId, PermissionCatalog::CONTROL_REQUEST, 'DENY');

        try {
            Services::controlService()->request($session, $viewer, $this->policyFor($viewer));
            $this->fail('Expected the request to be refused.');
        } catch (ApiException $exception) {
            $this->assertSame('CONTROL_REQUEST_DENIED', $exception->errorCode());
        }
    }

    /** Only the person at the machine grants. Not another viewer, not an admin. */
    public function testOnlyTheHostCanGrantControl(): void
    {
        ['session' => $session, 'viewer' => $viewer] = $this->scenario();
        $control = Services::controlService();

        $requested = $control->request($session, $viewer, $this->policyFor($viewer));

        $bystander = $this->makeIdentity('Company Admin');
        $this->grantCompanyAccess($bystander, $this->companyId, 'MEMBER', true);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Only the person at the computer');

        $control->grant($session, $bystander, (string) $requested['uuid'], $this->policyFor($bystander));
    }

    public function testDenyingControlLeavesNoGrant(): void
    {
        ['session' => $session, 'host' => $host, 'viewer' => $viewer] = $this->scenario();
        $control = Services::controlService();

        $requested = $control->request($session, $viewer, $this->policyFor($viewer));
        $denied    = $control->deny($session, $host, (string) $requested['uuid']);

        $this->assertSame(ControlService::STATE_DENIED, $denied['control_state']);
        $this->assertNull($control->stateFor($session)['controllerUuid']);
        $this->assertHasEvent($session, EventType::CONTROL_DENIED);
    }

    /**
     * Two people typing into one desktop is not a feature, and deciding whose
     * keystroke won afterwards is not a decision anybody should have to make.
     */
    public function testOnlyOnePersonCanHoldControlAtATime(): void
    {
        ['session' => $session, 'host' => $host, 'viewer' => $viewer] = $this->scenario();
        $control = Services::controlService();

        $first = $control->request($session, $viewer, $this->policyFor($viewer));
        $control->grant($session, $host, (string) $first['uuid'], $this->policyFor($host));

        $second = $this->makeIdentity('Second Viewer');
        $this->grantCompanyAccess($second, $this->companyId, 'MEMBER');
        $this->setUserPermission($second, $this->companyId, PermissionCatalog::CONTROL_REQUEST, 'ALLOW');

        $participant = Services::participantService()->requestJoin(
            $session,
            $second,
            $second->displayName,
            ParticipantService::ROLE_VIEWER,
        );
        Services::participantService()->approve($session, (string) $participant['uuid'], $host);

        $requested = $control->request($session, $second, $this->policyFor($second));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('already controlling');

        $control->grant($session, $host, (string) $requested['uuid'], $this->policyFor($host));
    }

    /** Stopping control must never depend on holding a permission. */
    public function testTheControllerCanRevokeTheirOwnControl(): void
    {
        ['session' => $session, 'host' => $host, 'viewer' => $viewer] = $this->scenario();
        $control = Services::controlService();

        $requested = $control->request($session, $viewer, $this->policyFor($viewer));
        $control->grant($session, $host, (string) $requested['uuid'], $this->policyFor($host));

        $revoked = $control->revoke($session, $viewer);

        $this->assertSame(ControlService::STATE_REVOKED, $revoked['control_state']);
    }

    public function testSomebodyElseEntirelyCannotRevokeControl(): void
    {
        ['session' => $session, 'host' => $host, 'viewer' => $viewer] = $this->scenario();
        $control = Services::controlService();

        $requested = $control->request($session, $viewer, $this->policyFor($viewer));
        $control->grant($session, $host, (string) $requested['uuid'], $this->policyFor($host));

        $stranger = $this->makeIdentity('Passer By');
        $this->grantCompanyAccess($stranger, $this->companyId, 'MEMBER', true);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Only the person at the computer, or whoever is controlling it');

        $control->revoke($session, $stranger, (string) $requested['uuid']);
    }

    // ------------------------------------------------------------- clipboard

    public function testClipboardIsOffUnlessAskedForAndPermitted(): void
    {
        ['session' => $session, 'host' => $host, 'viewer' => $viewer] = $this->scenario();
        $control = Services::controlService();

        $requested = $control->request($session, $viewer, $this->policyFor($viewer));
        $granted   = $control->grant($session, $host, (string) $requested['uuid'], $this->policyFor($host), true);

        $this->assertTrue($granted['clipboard_enabled']);

        $off = $control->setClipboard($session, $host, (string) $requested['uuid'], false, $this->policyFor($host));
        $this->assertFalse($off['clipboard_enabled']);
        $this->assertHasEvent($session, EventType::CLIPBOARD_SYNCED);
    }

    public function testClipboardCannotBeEnabledWhenThePolicyForbidsIt(): void
    {
        ['session' => $session, 'host' => $host, 'viewer' => $viewer] = $this->scenario(['allow_clipboard_sync' => false]);
        $control = Services::controlService();

        $requested = $control->request($session, $viewer, $this->policyFor($viewer));

        // Asking for it in the grant simply does not get it…
        $granted = $control->grant($session, $host, (string) $requested['uuid'], $this->policyFor($host), true);
        $this->assertFalse($granted['clipboard_enabled']);

        // …and asking for it directly is refused with a reason.
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Clipboard sharing is not enabled');

        $control->setClipboard($session, $host, (string) $requested['uuid'], true, $this->policyFor($host));
    }

    public function testClipboardCannotBeEnabledWithoutActiveControl(): void
    {
        ['session' => $session, 'host' => $host, 'viewer' => $viewer, 'viewerParticipant' => $participant] = $this->scenario();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('needs an active remote control session');

        Services::controlService()->setClipboard(
            $session,
            $host,
            (string) $participant['uuid'],
            true,
            $this->policyFor($host),
        );
    }

    /**
     * The audit trail records that control happened and who had it. It never
     * records an input event, because a keystroke log is a password log.
     */
    public function testTheAuditTrailCarriesNoInputAndNoClipboardContent(): void
    {
        ['session' => $session, 'host' => $host, 'viewer' => $viewer] = $this->scenario();
        $control = Services::controlService();

        $requested = $control->request($session, $viewer, $this->policyFor($viewer));
        $control->grant($session, $host, (string) $requested['uuid'], $this->policyFor($host), true);

        $rows = $this->db->table('remote_audit_logs')->get()->getResultArray();
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $metadata = strtolower((string) $row['metadata']);
            foreach (['keystroke', 'keycode', 'clipboardtext', '"body"', 'password'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $metadata);
            }
        }
    }

    public function testControlCannotBeRequestedOnAnEndedSession(): void
    {
        ['session' => $session, 'host' => $host, 'viewer' => $viewer] = $this->scenario();

        Services::sessionService()->end($session, $host);
        $ended = $this->reload($session);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('already finished');

        Services::controlService()->request($ended, $viewer, $this->policyFor($viewer));
    }
}
