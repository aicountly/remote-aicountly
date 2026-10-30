<?php

declare(strict_types=1);

namespace Tests\Device;

use App\Domain\Audit\EventType;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Session\CompanionLinks;
use App\Domain\Session\ParticipantService;
use App\Domain\Support\ApiException;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\CompanionScenario;
use Tests\Support\FakeIdentityResolver;
use Tests\Support\RemoteTestCase;

/**
 * Linking a person's own desktop app to the screen they share from a browser
 * (docs/desktop/CONTROL_COMPANION.md): eligibility, tenant isolation, one live link per session and per
 * device, the machine's confirmation, expiry, the audit trail, the database's own rules, and the whole
 * thing over HTTP.
 *
 * @internal
 */
final class ControlCompanionTest extends RemoteTestCase
{
    use CompanionScenario;
    use FeatureTestTrait;

    private FakeIdentityResolver $identities;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identities = new FakeIdentityResolver(Services::portalClient(), $this->db);
        Services::injectMock('identityResolver', $this->identities);
    }

    private function expectApiError(string $code, callable $call, ?int $status = null): ApiException
    {
        try {
            $call();
        } catch (ApiException $exception) {
            $this->assertSame($code, $exception->errorCode(), $exception->getMessage());
            if ($status !== null) {
                $this->assertSame($status, $exception->status());
            }

            return $exception;
        }

        $this->fail("Expected {$code}.");
    }

    /** @param array<string, mixed> $fixture */
    private function link(array $fixture, ?RemoteIdentity $as = null, ?string $deviceUuid = null): array
    {
        $as ??= $fixture['sharer'];

        return Services::companionService()->link(
            $this->reload($fixture['session']),
            $as,
            Services::policyResolver()->resolve($as, 'COMPANY', $this->companyId),
            $deviceUuid ?? (string) $fixture['device']['uuid'],
        )['link'];
    }

    // ------------------------------------------------------------ eligibility

    public function testOnlyTheSharersOwnOnlineControllableDevicesAreListed(): void
    {
        $fixture = $this->companionScenario();

        // The viewer's machine, an offline machine of the sharer's, and a browser-only one.
        $this->enrolDevice($fixture['viewer'], $this->companyId, ['deviceName' => 'Bala PC']);
        $this->enrolDevice($fixture['sharer'], $this->companyId, ['deviceName' => 'Asha old laptop']);
        ['device' => $viewOnly] = $this->enrolDevice($fixture['sharer'], $this->companyId, [
            'deviceName'   => 'Asha kiosk',
            'capabilities' => ['screen_share' => true, 'screen_view' => true],
        ]);
        $this->markDeviceOnline((string) $viewOnly['uuid']);

        $result = Services::companionService()->eligibleDevices($fixture['session'], $fixture['sharer'], $this->policyOf($fixture['sharer']));

        $this->assertSame(['Asha MacBook'], array_map(static fn (array $d) => $d['device_name'], $result['devices']));
        $this->assertSame([], $result['restrictions']);
    }

    /**
     * AICOUNTLY Remote is used with a personal account, so this is the case that has to work: somebody
     * shares their own screen from the browser in a personal session, links their own computer, it
     * confirms, and the person they admitted controls it once they say so.
     */
    public function testAPersonalSessionControlsThePersonsOwnComputer(): void
    {
        $sharer = $this->makeIdentity('Priya at home');
        $viewer = $this->makeIdentity('Ravi helping');
        $policy = static fn (RemoteIdentity $who) => Services::policyResolver()->resolve($who, 'PERSONAL', null);

        ['device' => $device] = $this->enrolDevice($sharer, null, ['deviceName' => 'Priya laptop']);
        $this->markDeviceOnline((string) $device['uuid']);

        $session      = $this->makeSession($sharer);
        $participants = Services::participantService();
        $joining      = $participants->requestJoin($session, $viewer, $viewer->displayName, ParticipantService::ROLE_VIEWER);
        $participants->approve($session, (string) $joining['uuid'], $sharer);
        $participants->markJoined($session, (string) $joining['uuid']);

        // An entire screen: the only share a companion can serve, and a personal account's own to share.
        Services::sessionService()->recordShareStarted($this->reload($session), $sharer, 'monitor', $policy($sharer), 1920, 1080);

        $companions = Services::companionService();
        $eligible   = $companions->eligibleDevices($this->reload($session), $sharer, $policy($sharer));
        $this->assertSame(['Priya laptop'], array_map(static fn (array $d) => $d['device_name'], $eligible['devices']));
        $this->assertSame([], $eligible['restrictions']);

        $link      = $companions->link($this->reload($session), $sharer, $policy($sharer), (string) $device['uuid'])['link'];
        $principal = $this->principalFor((string) $device['uuid'], null);

        $pending = $companions->pendingFor($principal);
        $this->assertSame([(string) $link['uuid']], array_column($pending, 'companionUuid'));
        $this->assertNull($pending[0]['companyName'], 'nobody\'s organisation is named for a personal session');

        $companions->join($principal, (string) $link['uuid']);
        $companions->selectMonitor($principal, (string) $link['uuid'], $this->monitor(), 'AUTO_SINGLE');

        $control = Services::controlService();
        $control->request($this->reload($session), $viewer, $policy($viewer));
        $granted = $control->grant($this->reload($session), $sharer, (string) $joining['uuid'], $policy($sharer));

        $this->assertSame('GRANTED', (string) $granted['control_state']);
    }

    /** A personal session reaches its owner's personal computers and nothing an organisation holds. */
    public function testAPersonalSessionNeverReachesAnOrganisationsComputer(): void
    {
        $fixture  = $this->companionScenario();
        $personal = $this->makeSession($fixture['sharer']);
        $policy   = Services::policyResolver()->resolve($fixture['sharer'], 'PERSONAL', null);

        Services::sessionService()->recordShareStarted($this->reload($personal), $fixture['sharer'], 'monitor', $policy, 1920, 1080);

        // The scenario's machine is enrolled in the company: not listed here, and not linkable by uuid.
        $result = Services::companionService()->eligibleDevices($this->reload($personal), $fixture['sharer'], $policy);
        $this->assertSame([], $result['devices']);
        $this->assertSame(['NO_ELIGIBLE_DEVICE'], $result['restrictions']);

        $this->expectApiError('NOT_FOUND', fn () => Services::companionService()->link(
            $this->reload($personal),
            $fixture['sharer'],
            $policy,
            (string) $fixture['device']['uuid'],
        ));
    }

    /** A support session is neither the person's own nor their organisation's. */
    public function testASupportSessionHasNoCompanionsAndSaysWhy(): void
    {
        $fixture = $this->companionScenario();
        $support = ['scope_type' => 'AICOUNTLY_SUPPORT'] + $fixture['session'];

        $result = Services::companionService()->eligibleDevices($support, $fixture['sharer'], $this->policyOf($fixture['sharer']));
        $this->assertSame([], $result['devices']);
        $this->assertSame(['COMPANION_SCOPE'], $result['restrictions']);

        $this->expectApiError('COMPANION_SCOPE', fn () => Services::companionService()->link(
            $support,
            $fixture['sharer'],
            $this->policyOf($fixture['sharer']),
            (string) $fixture['device']['uuid'],
        ));
    }

    public function testTheOrganisationMustPermitControl(): void
    {
        $fixture = $this->companionScenario(['allow_remote_control' => false]);

        $result = Services::companionService()->eligibleDevices($fixture['session'], $fixture['sharer'], $this->policyOf($fixture['sharer']));
        $this->assertContains('REMOTE_CONTROL_NOT_ALLOWED', $result['restrictions']);

        $this->expectApiError('REMOTE_CONTROL_NOT_ALLOWED', fn () => $this->link($fixture), 403);
    }

    public function testThePlanMustIncludeDesktopDevices(): void
    {
        $fixture = $this->companionScenario();
        $this->db->table('remote_entitlements')->where('company_id', $this->companyId)->update(['desktop_devices' => false]);

        $this->expectApiError('REMOTE_CONTROL_NOT_ALLOWED', fn () => $this->link($fixture), 403);
    }

    public function testTheSharerMustHoldControlAccept(): void
    {
        $fixture = $this->companionScenario();
        $this->setUserPermission($fixture['sharer'], $this->companyId, PermissionCatalog::CONTROL_ACCEPT, 'DENY');

        $this->expectApiError('CONTROL_ACCEPT_DENIED', fn () => $this->link($fixture), 403);
    }

    public function testOnlyTheJoinedSharingHostLinks(): void
    {
        $fixture = $this->companionScenario([], null);

        // Not sharing yet.
        $this->expectApiError('NOT_SESSION_HOST', fn () => $this->link($fixture), 403);

        $this->share($fixture['session'], $fixture['sharer']);

        // The viewer, with their own enrolled machine, is not the host.
        ['device' => $viewersOwn] = $this->enrolDevice($fixture['viewer'], $this->companyId);
        $this->markDeviceOnline((string) $viewersOwn['uuid']);
        $this->expectApiError('NOT_SESSION_HOST', fn () => $this->link($fixture, $fixture['viewer'], (string) $viewersOwn['uuid']), 403);
    }

    // ------------------------------------------------------- tenant isolation

    public function testAnotherPersonsDeviceIsNotFound(): void
    {
        $fixture = $this->companionScenario();
        ['device' => $theirs] = $this->enrolDevice($fixture['viewer'], $this->companyId);
        $this->markDeviceOnline((string) $theirs['uuid']);

        $this->expectApiError('NOT_FOUND', fn () => $this->link($fixture, null, (string) $theirs['uuid']), 404);
    }

    public function testADeviceInAnotherCompanyIsNotFound(): void
    {
        $fixture = $this->companionScenario();

        $this->makeDesktopCompany(1301, 'Elsewhere Ltd');
        $this->grantCompanyAccess($fixture['sharer'], 1301);
        ['device' => $elsewhere] = $this->enrolDevice($fixture['sharer'], 1301);
        $this->markDeviceOnline((string) $elsewhere['uuid']);

        $this->expectApiError('NOT_FOUND', fn () => $this->link($fixture, null, (string) $elsewhere['uuid']), 404);
        $this->expectApiError('NOT_FOUND', fn () => $this->link($fixture, null, 'not-a-uuid'), 404);
    }

    public function testAnotherMachineCannotActOnThisLink(): void
    {
        $fixture = $this->companionScenario();
        $link    = $this->link($fixture);

        ['device' => $other] = $this->enrolDevice($fixture['sharer'], $this->companyId, ['deviceName' => 'Asha desktop']);
        $stranger = $this->principalFor((string) $other['uuid']);

        foreach (['join', 'decline', 'leave'] as $action) {
            $this->expectApiError('NOT_FOUND', fn () => Services::companionService()->{$action}($stranger, (string) $link['uuid']), 404);
        }
        $this->expectApiError('NOT_FOUND', fn () => Services::companionService()->selectMonitor($stranger, (string) $link['uuid'], $this->monitor(), 'USER'), 404);

        $this->assertSame([], Services::companionService()->pendingFor($stranger));
        $this->assertSame('PENDING', $this->linkRow((string) $link['uuid'])['status']);
    }

    // ---------------------------------------------------- device state checks

    public function testAnOfflineOrSuspendedDeviceIsRefused(): void
    {
        $fixture = $this->companionScenario();

        $this->db->table('remote_devices')->where('id', $fixture['device']['id'])->update(['presence_state' => 'OFFLINE']);
        $this->expectApiError('DEVICE_OFFLINE', fn () => $this->link($fixture), 409);

        $this->markDeviceOnline((string) $fixture['device']['uuid']);
        $this->db->table('remote_devices')->where('id', $fixture['device']['id'])->update(['status' => 'SUSPENDED', 'unattended_access_enabled' => false]);
        $this->expectApiError('DEVICE_NOT_ACTIVE', fn () => $this->link($fixture), 409);
    }

    // ------------------------------------------------------------- uniqueness

    public function testOneLiveLinkPerSession(): void
    {
        $fixture = $this->companionScenario();
        $this->link($fixture);

        ['device' => $second] = $this->enrolDevice($fixture['sharer'], $this->companyId, ['deviceName' => 'Asha desktop']);
        $this->markDeviceOnline((string) $second['uuid']);

        $this->expectApiError('COMPANION_ALREADY_LINKED', fn () => $this->link($fixture, null, (string) $second['uuid']), 409);
    }

    public function testOneLiveLinkPerDevice(): void
    {
        $fixture = $this->companionScenario();
        $this->link($fixture);

        // The same person shares in a second session and tries the same machine.
        $other = $this->makeSession($fixture['sharer'], 'COMPANY', $this->companyId);
        $this->share($other, $fixture['sharer']);

        $this->expectApiError('DEVICE_BUSY', fn () => Services::companionService()->link(
            $this->reload($other),
            $fixture['sharer'],
            $this->policyOf($fixture['sharer']),
            (string) $fixture['device']['uuid'],
        ), 409);
    }

    public function testAnEndedLinkFreesTheDeviceAndTheSameRowIsReused(): void
    {
        $fixture = $this->companionScenario();
        $first   = $this->link($fixture);
        $joined  = Services::companionService()->join($fixture['principal'], (string) $first['uuid'])['participant'];
        Services::companionService()->leave($fixture['principal'], (string) $first['uuid']);

        $second = $this->link($fixture);
        $again  = Services::companionService()->join($fixture['principal'], (string) $second['uuid'])['participant'];

        $this->assertNotSame($first['uuid'], $second['uuid']);
        $this->assertSame($joined['uuid'], $again['uuid'], 'one machine is one participant in a session');
        $this->assertSame('JOINED', $again['status']);
    }

    // ------------------------------------------------------- the machine's part

    public function testTheMachineSeesThePendingRequestWithWhoAndWhere(): void
    {
        $fixture = $this->companionScenario();
        $link    = $this->link($fixture);

        $pending = Services::companionService()->pendingFor($fixture['principal']);

        $this->assertCount(1, $pending);
        $this->assertSame((string) $link['uuid'], $pending[0]['companionUuid']);
        $this->assertSame((string) $fixture['session']['uuid'], $pending[0]['sessionUuid']);
        $this->assertSame((string) $fixture['session']['display_id'], $pending[0]['displayId']);
        $this->assertSame('Companion Co', $pending[0]['companyName']);
        $this->assertSame('Asha sharing', $pending[0]['requestedByName']);
        $this->assertNotNull($pending[0]['expiresAt']);

        $audit = $this->lastAudit(EventType::CONTROL_COMPANION_REQUESTED);
        $this->assertSame('USER', $audit['actor_type']);
        $this->assertSame('security', $audit['severity']);
        $this->assertSame((string) $fixture['device']['uuid'], $audit['metadata']['deviceUuid']);
    }

    public function testJoiningCreatesAnInputOnlyParticipantLinkedToTheSharer(): void
    {
        $fixture = $this->companionScenario();
        $link    = $this->link($fixture);

        $result      = Services::companionService()->join($fixture['principal'], (string) $link['uuid']);
        $participant = $result['participant'];

        $this->assertSame(ParticipantService::ROLE_COMPANION, $participant['participant_role']);
        $this->assertSame('DESKTOP_AGENT', $participant['client_type']);
        $this->assertSame('JOINED', $participant['status']);
        $this->assertFalse($participant['is_host']);
        $this->assertSame($fixture['sharer']->id, (int) $participant['user_id']);
        $this->assertSame((int) $fixture['sharerParticipant']['id'], (int) $participant['companion_of_participant_id']);
        $this->assertSame('Asha MacBook (control)', $participant['display_name']);

        $capabilities = json_decode((string) $participant['capabilities'], true);
        $this->assertTrue($capabilities['remote_control']);
        unset($capabilities['remote_control']);
        $this->assertSame([], array_filter($capabilities), 'only remote_control — no clipboard, files, sharing or reboot');

        $this->assertSame('JOINED', $result['link']['status']);
        $this->assertSame('DEVICE', $this->lastAudit(EventType::CONTROL_COMPANION_JOINED)['actor_type']);
        $this->assertSame([], Services::companionService()->pendingFor($fixture['principal']));

        // Idempotent: the same participant again, nothing more recorded.
        $before = $this->db->table('remote_audit_logs')->countAllResults();
        $again  = Services::companionService()->join($fixture['principal'], (string) $link['uuid']);
        $this->assertSame($participant['uuid'], $again['participant']['uuid']);
        $this->assertSame($before, $this->db->table('remote_audit_logs')->countAllResults());

        // It is not the person: looking for the sharer still finds the sharer's browser row.
        $this->assertSame(
            (string) $fixture['sharerParticipant']['uuid'],
            (string) Services::participantService()->findByUser((int) $fixture['session']['id'], $fixture['sharer']->id)['uuid'],
        );
    }

    public function testTheCompanionCannotJoinAsTheSharedScreen(): void
    {
        $fixture = $this->companionScenario();
        $link    = $this->link($fixture);
        Services::companionService()->join($fixture['principal'], (string) $link['uuid']);

        $this->expectApiError('DEVICE_IS_COMPANION', fn () => Services::deviceSessionService()
            ->joinAsHost($this->reload($fixture['session']), $fixture['principal']), 409);
    }

    public function testDecliningAtTheMachine(): void
    {
        $fixture = $this->companionScenario();
        $link    = $this->link($fixture);

        $result = Services::companionService()->decline($fixture['principal'], (string) $link['uuid']);

        $this->assertSame('DECLINED', $result['link']['status']);
        $this->assertSame('DEVICE', $this->lastAudit(EventType::CONTROL_COMPANION_DECLINED)['actor_type']);
        $this->expectApiError('COMPANION_NOT_PENDING', fn () => Services::companionService()->join($fixture['principal'], (string) $link['uuid']), 409);

        // The browser can ask again — the device is free.
        $this->assertSame('PENDING', $this->link($fixture)['status']);
    }

    public function testAnUnconfirmedLinkExpiresAfterFiveMinutes(): void
    {
        $fixture = $this->companionScenario();
        $link    = $this->link($fixture);

        $row = $this->linkRow((string) $link['uuid']);
        $this->assertEqualsWithDelta(time() + 300, strtotime((string) $row['expires_at']), 5);

        $this->db->table('remote_control_companions')->where('id', $row['id'])->update(['expires_at' => \App\Domain\Support\Clock::in(-1)]);

        $this->assertSame([], Services::companionService()->pendingFor($fixture['principal']));
        $this->assertSame('EXPIRED', $this->linkRow((string) $link['uuid'])['status']);

        $ended = $this->lastAudit(EventType::CONTROL_COMPANION_ENDED);
        $this->assertSame('SYSTEM', $ended['actor_type']);
        $this->assertSame('EXPIRED', $ended['metadata']['reason']);

        $this->expectApiError('COMPANION_NOT_PENDING', fn () => Services::companionService()->join($fixture['principal'], (string) $link['uuid']), 409);
    }

    public function testChoosingAMonitorMakesTheLinkActive(): void
    {
        $fixture = $this->companionScenario();
        $link    = $this->link($fixture);

        $this->expectApiError('COMPANION_NOT_JOINED', fn () => Services::companionService()
            ->selectMonitor($fixture['principal'], (string) $link['uuid'], $this->monitor(), 'USER'), 409);

        Services::companionService()->join($fixture['principal'], (string) $link['uuid']);

        $this->expectApiError('VALIDATION_FAILED', fn () => Services::companionService()
            ->selectMonitor($fixture['principal'], (string) $link['uuid'], ['id' => 1, 'width' => 0, 'height' => 900, 'x' => 0, 'y' => 0, 'scale' => 1], 'USER'), 400);
        $this->expectApiError('VALIDATION_FAILED', fn () => Services::companionService()
            ->selectMonitor($fixture['principal'], (string) $link['uuid'], $this->monitor(), 'GUESS'), 400);

        $result = Services::companionService()->selectMonitor($fixture['principal'], (string) $link['uuid'], $this->monitor(), 'AUTO_ASPECT');

        $this->assertSame('ACTIVE', $result['link']['status']);
        $this->assertSame('AUTO_ASPECT', $result['link']['monitor_match']);

        $ready = $this->lastAudit(EventType::CONTROL_COMPANION_READY);
        $this->assertSame('DEVICE', $ready['actor_type']);
        $this->assertSame('AUTO_ASPECT', $ready['metadata']['matchedBy']);
    }

    public function testAnotherMonitorWhileControlledRevokesControl(): void
    {
        $fixture = $this->companionScenario();
        $this->activeCompanion($fixture);
        $this->grantViewer($fixture);

        $uuid = (string) $this->db->table('remote_control_companions')->get()->getRowArray()['uuid'];
        Services::companionService()->selectMonitor($fixture['principal'], $uuid, array_merge($this->monitor(), ['id' => 2, 'x' => 1512]), 'USER');

        $this->assertSame('REVOKED', Services::participantService()->findByUuidOrFail((string) $fixture['viewerParticipant']['uuid'])['control_state']);
        $this->assertSame('MONITOR_CHANGED', $this->lastAudit(EventType::CONTROL_REVOKED)['metadata']['reason']);
    }

    public function testLeavingAtTheMachineEndsTheLinkAndControl(): void
    {
        $fixture   = $this->companionScenario();
        $companion = $this->activeCompanion($fixture);
        $this->grantViewer($fixture);

        $uuid   = (string) $this->db->table('remote_control_companions')->get()->getRowArray()['uuid'];
        $result = Services::companionService()->leave($fixture['principal'], $uuid);

        $this->assertSame('ENDED', $result['link']['status']);
        $this->assertSame('LEFT_AT_DEVICE', $result['link']['end_reason']);
        $this->assertSame('LEFT', Services::participantService()->findByUuidOrFail((string) $companion['uuid'])['status']);
        $this->assertSame('REVOKED', Services::participantService()->findByUuidOrFail((string) $fixture['viewerParticipant']['uuid'])['control_state']);
        $this->assertSame('DEVICE', $this->lastAudit(EventType::CONTROL_COMPANION_ENDED)['actor_type']);
    }

    public function testTheMachineReadsItsOwnLinkEvenAfterAnotherIsMade(): void
    {
        $fixture = $this->companionScenario();
        $first   = $this->link($fixture);
        Services::companionService()->join($fixture['principal'], (string) $first['uuid']);
        Services::companionService()->unlink($this->reload($fixture['session']), $fixture['sharer']);

        ['device' => $second] = $this->enrolDevice($fixture['sharer'], $this->companyId, ['deviceName' => 'Asha desktop']);
        $this->markDeviceOnline((string) $second['uuid']);
        $this->link($fixture, null, (string) $second['uuid']);

        $state = Services::deviceSessionService()->controlStateFor($this->reload($fixture['session']), $fixture['principal']);

        $this->assertSame((string) $first['uuid'], $state['companion']['uuid']);
        $this->assertSame('ENDED', $state['companion']['status']);
        $this->assertFalse($state['allowClipboardSync'], 'decision 3');
        $this->assertArrayHasKey('grantId', $state);
        $this->assertArrayHasKey('sharedSurface', $state);
    }

    // ------------------------------------------------------- database's own rules

    public function testTheDatabaseRefusesTwoLiveLinksForOneSession(): void
    {
        $fixture = $this->companionScenario();
        $link    = $this->linkRow((string) $this->link($fixture)['uuid']);

        ['device' => $second] = $this->enrolDevice($fixture['sharer'], $this->companyId, ['deviceName' => 'Asha desktop']);

        $this->expectException(DatabaseException::class);
        $this->db->table('remote_control_companions')->insert([
            'uuid' => \App\Domain\Support\Ids::uuid4(), 'session_id' => $link['session_id'], 'device_id' => $second['id'],
            'sharer_participant_id' => $link['sharer_participant_id'], 'requested_by_user_id' => $fixture['sharer']->id,
            'status' => 'PENDING', 'expires_at' => \App\Domain\Support\Clock::inMinutes(5),
        ]);
    }

    public function testTheDatabaseRefusesAnActiveLinkWithoutAMonitor(): void
    {
        $fixture = $this->companionScenario();
        $link    = $this->link($fixture);
        Services::companionService()->join($fixture['principal'], (string) $link['uuid']);

        $this->expectException(DatabaseException::class);
        $this->db->table('remote_control_companions')->where('uuid', $link['uuid'])->update(['status' => 'ACTIVE']);
    }

    public function testTheDatabaseRefusesACompanionThatServesNobody(): void
    {
        $fixture = $this->companionScenario();

        $this->expectException(DatabaseException::class);
        $this->db->table('remote_participants')->insert([
            'uuid' => \App\Domain\Support\Ids::uuid4(), 'session_id' => $fixture['session']['id'], 'user_id' => $fixture['sharer']->id,
            'device_id' => $fixture['device']['id'], 'participant_role' => 'CONTROL_COMPANION', 'client_type' => 'DESKTOP_AGENT',
            'display_name' => 'Nobody', 'status' => 'JOINED',
        ]);
    }

    public function testTheDatabaseRefusesACompanionWithoutADeviceOrAsAHost(): void
    {
        $fixture = $this->companionScenario();
        $base    = [
            'session_id' => $fixture['session']['id'], 'user_id' => $fixture['sharer']->id, 'participant_role' => 'CONTROL_COMPANION',
            'client_type' => 'DESKTOP_AGENT', 'display_name' => 'Odd', 'status' => 'JOINED',
            'companion_of_participant_id' => $fixture['sharerParticipant']['id'],
        ];

        foreach ([['device_id' => null], ['device_id' => $fixture['device']['id'], 'is_host' => true]] as $variant) {
            try {
                $this->db->table('remote_participants')->insert(array_merge($base, $variant, ['uuid' => \App\Domain\Support\Ids::uuid4()]));
                $this->fail('A companion must carry its device and never be a host.');
            } catch (DatabaseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheDatabaseRefusesAnImplausibleSurfaceSize(): void
    {
        $fixture = $this->companionScenario();

        $this->expectException(DatabaseException::class);
        $this->db->table('remote_sessions')->where('id', $fixture['session']['id'])->update(['actual_surface_width' => 20000]);
    }

    // ------------------------------------------------------------- over HTTP

    /** @return array<string, string> */
    private function asUser(RemoteIdentity $identity): array
    {
        $key = 'ses-' . $identity->id;
        $this->identities->register($key, $identity);

        return ['Authorization' => 'Bearer ' . $key];
    }

    /** @return array<string, string> */
    private function asDevice(array $fixture): array
    {
        $uuid      = (string) $fixture['device']['uuid'];
        $auth      = Services::deviceAuthenticationService();
        $challenge = $auth->challenge($uuid, '203.0.113.9');
        $token     = $auth->verify(
            $uuid,
            $challenge['nonce'],
            $challenge['issuedAt'],
            $this->signChallenge($fixture['secretKey'], $uuid, $challenge['nonce'], $challenge['issuedAt']),
        )['token'];

        return ['Authorization' => 'Bearer device.' . $token];
    }

    /** @return array<string, mixed> */
    private function data($response, int $status): array
    {
        $this->assertSame($status, $response->response()->getStatusCode(), (string) $response->getJSON());

        return json_decode((string) $response->getJSON(), true)['data'] ?? [];
    }

    public function testTheWholeFlowOverHttp(): void
    {
        $fixture = $this->companionScenario();
        $session = (string) $fixture['session']['uuid'];
        $sharer  = $this->asUser($fixture['sharer']);
        $device  = $this->asDevice($fixture);

        // The browser lists A's devices and links one.
        $listed = $this->data($this->withHeaders($sharer)->get("v1/remote/sessions/{$session}/companion/devices"), 200);
        $this->assertSame([(string) $fixture['device']['uuid']], array_column($listed['devices'], 'uuid'));

        $linked = $this->data($this->withHeaders($sharer)->withBodyFormat('json')
            ->post("v1/remote/sessions/{$session}/companion", ['deviceUuid' => (string) $fixture['device']['uuid']]), 201);
        $companionUuid = $linked['companion']['uuid'];
        $this->assertSame('PENDING', $linked['companion']['status']);
        $this->assertSame('COMPANION_NOT_READY', $linked['control']['controlBlockedReason']);
        $this->assertSame($companionUuid, $linked['deviceInvite']['companionUuid']);
        $this->assertStringStartsWith('device-', $linked['deviceInvite']['room']);

        // The machine finds it, joins after confirming, and is handed a companion signalling token.
        $me = $this->data($this->withHeaders($device)->get('v1/remote/devices/me'), 200);
        $this->assertSame($companionUuid, $me['pendingCompanions'][0]['companionUuid']);

        $joined = $this->data($this->withHeaders($device)->post("v1/remote/devices/me/companions/{$companionUuid}/join"), 200);
        $this->assertSame('CONTROL_COMPANION', $joined['participant']['role']);
        $this->assertSame('JOINED', $joined['companion']['status']);
        $this->assertSame($session, $joined['signalling']['room']);
        $claims = json_decode(base64_decode(strtr(explode('.', $joined['signalling']['token'])[1], '-_', '+/')), true);
        $this->assertSame('CONTROL_COMPANION', $claims['role']);
        $this->assertSame($joined['participant']['uuid'], $claims['sub']);
        $this->assertTrue($claims['cap']['remote_control']);
        $this->assertFalse($claims['cap']['clipboard_sync']);

        $ready = $this->data($this->withHeaders($device)->withBodyFormat('json')
            ->post("v1/remote/devices/me/companions/{$companionUuid}/monitor", ['monitor' => $this->monitor(), 'matchedBy' => 'AUTO_SINGLE']), 200);
        $this->assertTrue($ready['companion']['ready']);
        $this->assertSame($joined['participant']['uuid'], $ready['control']['controllableHostUuid']);

        // B asks, A grants in the browser, and the machine sees the grant it may arm.
        $viewer = $this->asUser($fixture['viewer']);
        $this->data($this->withHeaders($viewer)->post("v1/remote/sessions/{$session}/control/request"), 200);
        $this->data($this->withHeaders($sharer)->withBodyFormat('json')
            ->post("v1/remote/sessions/{$session}/control/grant", ['participantUuid' => (string) $fixture['viewerParticipant']['uuid']]), 200);

        $state = $this->data($this->withHeaders($device)->get("v1/remote/devices/me/sessions/{$session}/control"), 200);
        $this->assertSame((string) $fixture['viewerParticipant']['uuid'], $state['controllerUuid']);
        $this->assertNotNull($state['grantId']);
        $this->assertSame($companionUuid, $state['companion']['uuid']);

        // The machine may stop it, never grant it.
        $refused = $this->withHeaders($device)->withBodyFormat('json')
            ->post("v1/remote/devices/me/sessions/{$session}/control", ['participantUuid' => (string) $fixture['viewerParticipant']['uuid'], 'decision' => 'GRANT']);
        $refused->assertStatus(403);
        $refused->assertJSONFragment(['error' => ['code' => 'COMPANION_CANNOT_GRANT']]);

        // The browser ends it.
        $ended = $this->data($this->withHeaders($sharer)->delete("v1/remote/sessions/{$session}/companion"), 200);
        $this->assertSame('ENDED', $ended['companion']['status']);
        $this->assertNull($ended['control']['controllerUuid']);

        // The share reports its size.
        $shared = $this->data($this->withHeaders($sharer)->withBodyFormat('json')
            ->post("v1/remote/sessions/{$session}/share-started", ['displaySurface' => 'monitor', 'surfaceWidth' => 3024, 'surfaceHeight' => 1964]), 200);
        $this->assertSame(3024, $shared['actualSurfaceWidth']);
    }

    public function testStrangersAndGuestsGetNothing(): void
    {
        $fixture = $this->companionScenario();
        $session = (string) $fixture['session']['uuid'];

        $this->get("v1/remote/sessions/{$session}/companion/devices")->assertStatus(401);
        $this->post("v1/remote/sessions/{$session}/companion")->assertStatus(401);

        // Somebody in another company does not learn the session exists.
        $outsider = $this->makeIdentity('Outsider');
        $this->makeDesktopCompany(1302, 'Other Co');
        $this->grantCompanyAccess($outsider, 1302);

        $this->withHeaders($this->asUser($outsider))->get("v1/remote/sessions/{$session}/companion/devices")->assertStatus(404);
        $this->withHeaders($this->asUser($outsider))->withBodyFormat('json')
            ->post("v1/remote/sessions/{$session}/companion", ['deviceUuid' => (string) $fixture['device']['uuid']])->assertStatus(404);
        $this->withHeaders($this->asUser($outsider))->delete("v1/remote/sessions/{$session}/companion")->assertStatus(404);

        // A device cannot reach a companion route with no credential.
        $this->post('v1/remote/devices/me/companions/' . \App\Domain\Support\Ids::uuid4() . '/join')->assertStatus(401);
        $this->assertSame(0, $this->db->table('remote_control_companions')->countAllResults());
    }

    public function testTheLinkRouteIsRateLimited(): void
    {
        $fixture = $this->companionScenario();
        $session = (string) $fixture['session']['uuid'];
        $sharer  = $this->asUser($fixture['sharer']);

        $statuses = [];
        for ($i = 0; $i < 11; $i++) {
            $statuses[] = $this->withHeaders($sharer)->withBodyFormat('json')
                ->post("v1/remote/sessions/{$session}/companion", ['deviceUuid' => (string) $fixture['device']['uuid']])
                ->response()->getStatusCode();
        }

        $this->assertSame(201, $statuses[0]);
        $this->assertSame(409, $statuses[1]);
        $this->assertSame(429, $statuses[10]);
        $this->assertSame(1, $this->db->table('remote_control_companions')->whereIn('status', CompanionLinks::LIVE)->countAllResults());
    }
}
