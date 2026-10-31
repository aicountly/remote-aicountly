<?php

declare(strict_types=1);

namespace Tests\Device;

use App\Domain\Audit\EventType;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Device\DevicePrincipal;
use App\Domain\Session\ControlService;
use App\Domain\Session\SessionStatus;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\DeviceApiRequests;
use Tests\Support\RemoteTestCase;

/**
 * "Give your code, admit the helper": a session the desktop app starts and hosts.
 *
 * Through HTTP end to end, with two people and one machine: Meera at her computer starts a session there and
 * reads the code out; Ravi joins by it, waits, is admitted at the computer, asks for control and is given it
 * there; the computer ends it. Then every way that must be refused.
 *
 * @internal
 */
final class DeviceHostedSessionTest extends RemoteTestCase
{
    use FeatureTestTrait;
    use DeviceApiRequests;

    private RemoteIdentity $owner;
    private RemoteIdentity $helper;

    /** @var array{device: array<string, mixed>, publicKey: string, secretKey: string} */
    private array $computer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner    = $this->makeIdentity('Meera at home');
        $this->helper   = $this->makeIdentity('Ravi helping');
        $this->computer = $this->enrolDevice($this->owner, null, ['deviceName' => 'Meera laptop']);
        $this->markDeviceOnline((string) $this->computer['device']['uuid']);
    }

    /** @return array<string, mixed> the `session` resource */
    private function startAtTheComputer(?string $issue = 'The printer will not print'): array
    {
        return $this->dataOf(
            $this->postJson($this->asDevice($this->computer), 'devices/me/sessions', $issue === null ? [] : ['issueSummary' => $issue]),
            201,
        )['session'];
    }

    private function codeOf(string $sessionUuid): string
    {
        return (string) $this->db->table('remote_sessions')->where('uuid', $sessionUuid)->get()->getRow()->session_code;
    }

    /** @return array<string, mixed> the helper's participant resource */
    private function helperJoinsByCode(string $sessionUuid, ?RemoteIdentity $helper = null): array
    {
        return $this->dataOf(
            $this->postJson($this->asPerson($helper ?? $this->helper), 'join/code', ['code' => $this->codeOf($sessionUuid)]),
            201,
        )['participant'];
    }

    public function testTheWholeFlowFromCodeToControlToEnd(): void
    {
        $device = $this->asDevice($this->computer);

        // 1. Start it at the computer: an ordinary attended personal session, the owner's, sharing a whole screen.
        $session = $this->startAtTheComputer();
        $uuid    = (string) $session['uuid'];

        $this->assertSame('PERSONAL', $session['scopeType']);
        $this->assertNull($session['companyId']);
        $this->assertSame('ASSISTANCE', $session['sessionType']);
        $this->assertSame('ENTIRE_MONITOR', $session['requestedShareMode']);
        $this->assertSame(SessionStatus::WAITING, $session['status']);
        $this->assertSame('The printer will not print', $session['issueSummary']);
        $this->assertMatchesRegularExpression('/^\d{3} \d{3} \d{3}$/', (string) $session['sessionCode']);

        $row = Services::sessionService()->findByUuidOrFail($uuid);
        $this->assertSame('ATTENDED', $row['access_mode']);
        $this->assertSame((int) $this->computer['device']['id'], (int) $row['device_id']);
        $this->assertSame($this->owner->id, (int) $row['initiator_user_id']);
        $this->assertSame($this->owner->id, (int) $row['owner_user_id']);
        $this->assertHasEvent($row, EventType::SESSION_CREATED);
        $this->assertHasEvent($row, EventType::DEVICE_SESSION_STARTED);
        $started = $this->db->table('remote_audit_logs')->where('event', EventType::DEVICE_SESSION_STARTED)->get()->getRowArray();
        $this->assertSame('DEVICE', $started['actor_type']);
        $this->assertSame($this->owner->id, (int) $started['actor_user_id']);

        // The owner is at the machine, not in a browser: their browser seat is not the sharing host.
        $seat = Services::participantService()->findByUser((int) $row['id'], $this->owner->id);
        $this->assertSame('VIEWER', $seat['participant_role']);
        $this->assertFalse($seat['is_host']);
        $this->assertSame('LEFT', $seat['status']);
        $this->assertNull($seat['joined_at']);

        // It is not an unattended request, so the agent's pending list does not offer it back.
        $me = $this->dataOf($this->getJson($device, 'devices/me'));
        $this->assertSame([], $me['pendingSessions']);
        $this->assertSame([], Services::deviceSessionService()->pendingFor(
            new DevicePrincipal((string) $this->computer['device']['uuid'], null, DevicePrincipal::agentScopes(), time() + 300),
        ));

        // 2. The agent joins it as its host, through the existing route.
        $joined = $this->dataOf($this->postJson($device, "devices/me/sessions/{$uuid}/join"));
        $this->assertSame('SHARER', $joined['participant']['role']);
        $this->assertTrue($joined['participant']['isHost']);
        $this->assertSame('DESKTOP_AGENT', $joined['participant']['clientType']);
        $this->assertTrue($joined['participant']['capabilities']['remote_control']);
        $this->assertNotEmpty($joined['signalling']['token']);
        $hostUuid = (string) $joined['participant']['uuid'];

        $nobody = $this->dataOf($this->getJson($device, "devices/me/sessions/{$uuid}/participants"));
        $this->assertSame(['waiting' => [], 'joined' => []], $nobody, 'the computer itself and the owner\'s empty seat are in neither list');

        // 3. Ravi joins by the code and waits.
        $ravi = $this->helperJoinsByCode($uuid);
        $this->assertSame('REQUESTED', $ravi['status']);

        $waiting = $this->dataOf($this->getJson($device, "devices/me/sessions/{$uuid}/participants"));
        $this->assertCount(1, $waiting['waiting']);
        $this->assertSame([], $waiting['joined']);
        $this->assertSame([
            'uuid'        => (string) $ravi['uuid'],
            'displayName' => 'Ravi helping',
            'role'        => 'VIEWER',
            'status'      => 'REQUESTED',
            'requestedAt' => $waiting['waiting'][0]['requestedAt'],
            'joinedAt'    => null,
        ], $waiting['waiting'][0]);
        $this->assertNotNull($waiting['waiting'][0]['requestedAt']);

        // Still waiting: he cannot ask for control yet.
        $this->errorOf($this->postJson($this->asPerson($this->helper), "sessions/{$uuid}/control/request"), 403);

        // 4. Meera admits him at the computer — as herself, exactly as the browser host's Admit.
        $admitted = $this->dataOf($this->postJson($device, "devices/me/sessions/{$uuid}/participants/{$ravi['uuid']}/admit"));
        $this->assertSame('APPROVED', $admitted['participant']['status']);
        $this->assertSame((string) $ravi['uuid'], $admitted['participant']['uuid']);

        $approval = $this->db->table('remote_audit_logs')->where('event', EventType::PARTICIPANT_APPROVED)->get()->getRowArray();
        $this->assertSame('USER', $approval['actor_type']);
        $this->assertSame($this->owner->id, (int) $approval['actor_user_id']);

        $in = $this->dataOf($this->getJson($device, "devices/me/sessions/{$uuid}/participants"));
        $this->assertSame([], $in['waiting']);
        $this->assertSame(['Ravi helping'], array_column($in['joined'], 'displayName'));

        // Admitting twice is the browser's answer too: nothing changes.
        $again = $this->dataOf($this->postJson($device, "devices/me/sessions/{$uuid}/participants/{$ravi['uuid']}/admit"));
        $this->assertSame('APPROVED', $again['participant']['status']);

        // 5. Ravi asks for control. The computer is the session's controllable host, as in any session it shares.
        $request = $this->dataOf($this->postJson($this->asPerson($this->helper), "sessions/{$uuid}/control/request"));
        $this->assertSame(ControlService::STATE_REQUESTED, $request['participant']['controlState']);
        $this->assertSame($hostUuid, $request['control']['controllableHostUuid']);
        $this->assertNull($request['control']['controlBlockedReason']);
        $this->assertNull($request['control']['controllerUuid'], 'attended: nothing is granted until the computer says so');

        // 6. The computer decides, through the existing control route.
        $state = $this->dataOf($this->getJson($device, "devices/me/sessions/{$uuid}/control"));
        $this->assertSame([(string) $ravi['uuid']], array_column($state['pendingRequests'], 'participantUuid'));
        $this->assertTrue($state['allowRemoteControl']);

        $granted = $this->dataOf($this->postJson($device, "devices/me/sessions/{$uuid}/control", [
            'participantUuid' => (string) $ravi['uuid'],
            'decision'        => 'GRANT',
        ]));
        $this->assertSame(ControlService::STATE_GRANTED, $granted['participant']['controlState']);

        $seen = $this->dataOf($this->getJson($this->asPerson($this->helper), "sessions/{$uuid}/control"));
        $this->assertSame((string) $ravi['uuid'], $seen['controllerUuid']);
        $this->assertSame($hostUuid, $seen['controllableHostUuid']);
        $this->assertNotNull($seen['grantId']);

        $grant = $this->db->table('remote_audit_logs')->where('event', EventType::CONTROL_GRANTED)->get()->getRowArray();
        $this->assertSame('DEVICE', $grant['actor_type'], 'decided at the machine');

        // 7. The computer ends it, as its owner.
        $ended = $this->dataOf($this->postJson($device, "devices/me/sessions/{$uuid}/end"));
        $this->assertSame(SessionStatus::ENDED, $ended['session']['status']);
        $this->assertSame('ENDED_BY_USER', $ended['session']['endReason']);
        $this->assertNull($ended['session']['sessionCode'], 'the code stops working with the session');

        $over = Services::sessionService()->findByUuidOrFail($uuid);
        $this->assertSame($this->owner->id, (int) $over['ended_by_user_id']);
        $this->assertHasEvent($over, EventType::SESSION_ENDED);
        $this->assertSame(
            0,
            $this->db->table('remote_participants')->where('session_id', $over['id'])->whereIn('status', ['REQUESTED', 'APPROVED', 'JOINED'])->countAllResults(),
            'everybody is out, the computer included',
        );

        // Ending again is fine; nothing is ended twice.
        $this->assertSame(SessionStatus::ENDED, $this->dataOf($this->postJson($device, "devices/me/sessions/{$uuid}/end"))['session']['status']);
        $this->assertSame(1, $this->db->table('remote_session_events')->where('session_id', $over['id'])->where('event_type', EventType::SESSION_ENDED)->countAllResults());
    }

    /** The helper's answers in a desktop-hosted session are the same as in one the computer joined from a browser start. */
    public function testAHelperGetsTheSameControlAnswersAsInABrowserStartedSessionTheComputerShares(): void
    {
        $device = $this->asDevice($this->computer);

        // The browser-started equivalent: Meera starts it in her browser and her computer joins it as the screen.
        $browserStarted = $this->makeSession($this->owner, 'PERSONAL', null, ['requestedShareMode' => 'ENTIRE_MONITOR']);
        $this->dataOf($this->postJson($device, "devices/me/sessions/{$browserStarted['uuid']}/join"));
        $other = $this->makeIdentity('Ravi in the browser case');
        $joining = $this->helperJoinsByCode((string) $browserStarted['uuid'], $other);
        Services::participantService()->approve($this->reload($browserStarted), (string) $joining['uuid'], $this->owner);
        $fromBrowser = $this->dataOf($this->postJson($this->asPerson($other), "sessions/{$browserStarted['uuid']}/control/request"));

        $hosted = $this->startAtTheComputer();
        $this->dataOf($this->postJson($device, "devices/me/sessions/{$hosted['uuid']}/join"));
        $ravi = $this->helperJoinsByCode((string) $hosted['uuid']);
        $this->dataOf($this->postJson($device, "devices/me/sessions/{$hosted['uuid']}/participants/{$ravi['uuid']}/admit"));
        $fromDesktop = $this->dataOf($this->postJson($this->asPerson($this->helper), "sessions/{$hosted['uuid']}/control/request"));

        $this->assertSame(ControlService::STATE_REQUESTED, $fromBrowser['participant']['controlState']);
        $this->assertSame($fromBrowser['participant']['controlState'], $fromDesktop['participant']['controlState']);
        foreach (['controllerUuid', 'controlBlockedReason', 'grantId', 'companion'] as $field) {
            $this->assertSame($fromBrowser['control'][$field], $fromDesktop['control'][$field], $field);
        }
        $this->assertNotNull($fromBrowser['control']['controllableHostUuid']);
        $this->assertNotNull($fromDesktop['control']['controllableHostUuid']);
        $this->assertCount(1, $fromBrowser['control']['pendingRequests']);
        $this->assertCount(1, $fromDesktop['control']['pendingRequests']);
    }

    public function testADeclinedHelperIsKeptOut(): void
    {
        $device  = $this->asDevice($this->computer);
        $session = $this->startAtTheComputer(null);
        $uuid    = (string) $session['uuid'];
        $this->dataOf($this->postJson($device, "devices/me/sessions/{$uuid}/join"));

        $ravi = $this->helperJoinsByCode($uuid);

        $declined = $this->dataOf($this->postJson($device, "devices/me/sessions/{$uuid}/participants/{$ravi['uuid']}/decline"));
        $this->assertSame('DENIED', $declined['participant']['status']);
        $this->assertHasAudit(EventType::PARTICIPANT_DENIED);

        $after = $this->dataOf($this->getJson($device, "devices/me/sessions/{$uuid}/participants"));
        $this->assertSame(['waiting' => [], 'joined' => []], $after);

        $this->assertSame('JOIN_DENIED', $this->errorOf($this->postJson($this->asPerson($this->helper), "sessions/{$uuid}/control/request"), 403));
        $this->assertSame('JOIN_DENIED', $this->errorOf($this->postJson($this->asPerson($this->helper), 'join/code', ['code' => $this->codeOf($uuid)]), 403));

        // Declined is final: admitting afterwards is refused as it is in the browser.
        $this->assertSame(
            'PARTICIPANT_NOT_WAITING',
            $this->errorOf($this->postJson($device, "devices/me/sessions/{$uuid}/participants/{$ravi['uuid']}/admit"), 409),
        );
    }

    /** Another of the owner's own computers is not the one hosting it: not found, for every route. */
    public function testAnotherComputerCannotActOnIt(): void
    {
        $session = $this->startAtTheComputer();
        $uuid    = (string) $session['uuid'];
        $ravi    = $this->helperJoinsByCode($uuid);

        $second   = $this->enrolDevice($this->owner, null, ['deviceName' => 'Meera desktop']);
        $stranger = $this->enrolDevice($this->makeIdentity('Stranger'), null, ['deviceName' => 'Stranger PC']);

        foreach ([$second, $stranger] as $enrolled) {
            $other = $this->asDevice($enrolled);

            $this->errorOf($this->getJson($other, "devices/me/sessions/{$uuid}/participants"), 404);
            $this->errorOf($this->postJson($other, "devices/me/sessions/{$uuid}/participants/{$ravi['uuid']}/admit"), 404);
            $this->errorOf($this->postJson($other, "devices/me/sessions/{$uuid}/participants/{$ravi['uuid']}/decline"), 404);
            $this->errorOf($this->postJson($other, "devices/me/sessions/{$uuid}/end"), 404);
        }

        // The stranger's machine cannot even join it as its screen.
        $this->errorOf($this->postJson($this->asDevice($stranger), "devices/me/sessions/{$uuid}/join"), 404);

        $this->assertSame('REQUESTED', Services::participantService()->findByUuidOrFail((string) $ravi['uuid'])['status']);
        $this->assertTrue(SessionStatus::isLive((string) Services::sessionService()->findByUuidOrFail($uuid)['status']));
    }

    /** Nobody is at the computer in an unattended session, so it admits nobody; it can still list and end. */
    public function testAnUnattendedSessionIsNotTheComputersToAdmitPeopleTo(): void
    {
        $device = $this->asDevice($this->computer);
        $uuid   = (string) $this->computer['device']['uuid'];
        Services::deviceService()->enableUnattended($this->owner, $uuid, true);
        $unattended = Services::deviceSessionService()->startUnattended($this->owner, $uuid)['session'];
        $sessionUuid = (string) $unattended['uuid'];

        $ravi = $this->helperJoinsByCode($sessionUuid);

        $listed = $this->dataOf($this->getJson($device, "devices/me/sessions/{$sessionUuid}/participants"));
        $this->assertSame(['Ravi helping'], array_column($listed['waiting'], 'displayName'));
        $this->assertSame(['Meera at home'], array_column($listed['joined'], 'displayName'), 'the owner who connected');

        $this->assertSame('SESSION_NOT_ATTENDED', $this->errorOf($this->postJson($device, "devices/me/sessions/{$sessionUuid}/participants/{$ravi['uuid']}/admit"), 403));
        $this->assertSame('SESSION_NOT_ATTENDED', $this->errorOf($this->postJson($device, "devices/me/sessions/{$sessionUuid}/participants/{$ravi['uuid']}/decline"), 403));
        $this->assertSame('REQUESTED', Services::participantService()->findByUuidOrFail((string) $ravi['uuid'])['status']);

        $this->assertSame(SessionStatus::ENDED, $this->dataOf($this->postJson($device, "devices/me/sessions/{$sessionUuid}/end"))['session']['status']);
    }

    /** A browser session the owner started is theirs to run from the browser, even with their computer in it. */
    public function testABrowserStartedSessionIsNotHostedByTheComputer(): void
    {
        $device  = $this->asDevice($this->computer);
        $session = $this->makeSession($this->owner, 'PERSONAL', null, ['requestedShareMode' => 'ENTIRE_MONITOR']);
        $this->dataOf($this->postJson($device, "devices/me/sessions/{$session['uuid']}/join"));
        $ravi = $this->helperJoinsByCode((string) $session['uuid']);

        $this->errorOf($this->getJson($device, "devices/me/sessions/{$session['uuid']}/participants"), 404);
        $this->errorOf($this->postJson($device, "devices/me/sessions/{$session['uuid']}/participants/{$ravi['uuid']}/admit"), 404);
        $this->errorOf($this->postJson($device, "devices/me/sessions/{$session['uuid']}/end"), 404);
    }

    /** Only people are admitted or declined: never the computer's own participant, nor a stray uuid. */
    public function testTheComputersOwnParticipantAndNonsenseAreNotFound(): void
    {
        $device  = $this->asDevice($this->computer);
        $session = $this->startAtTheComputer();
        $uuid    = (string) $session['uuid'];
        $host    = $this->dataOf($this->postJson($device, "devices/me/sessions/{$uuid}/join"))['participant'];

        $this->errorOf($this->postJson($device, "devices/me/sessions/{$uuid}/participants/{$host['uuid']}/decline"), 404);
        $this->assertSame('APPROVED', Services::participantService()->findByUuidOrFail((string) $host['uuid'])['status'], 'the shared screen stays in its session');

        $this->errorOf($this->postJson($device, "devices/me/sessions/{$uuid}/participants/not-a-uuid/admit"), 404);
        $this->errorOf($this->getJson($device, 'devices/me/sessions/not-a-uuid/participants'), 404);
        $this->errorOf($this->postJson($device, 'devices/me/sessions/00000000-0000-4000-8000-000000000000/end'), 404);

        // A participant of a different session, named against this one.
        $elsewhere = $this->makeSession($this->makeIdentity('Someone else'));
        $theirs    = $this->helperJoinsByCode((string) $elsewhere['uuid']);
        $this->errorOf($this->postJson($device, "devices/me/sessions/{$uuid}/participants/{$theirs['uuid']}/admit"), 404);
    }

    /** A person's credential cannot reach these, and a device credential without `device.self` cannot either. */
    public function testOnlyTheDevicesOwnCredentialStartsOne(): void
    {
        $this->errorOf($this->postJson($this->asPerson($this->owner), 'devices/me/sessions'), 401);

        $presenceOnly = DevicePrincipal::issue(
            Services::remoteConfig(),
            (string) $this->computer['device']['uuid'],
            null,
            [DevicePrincipal::SCOPE_PRESENCE, DevicePrincipal::SCOPE_SESSION],
            time() + 300,
        );
        $this->errorOf($this->postJson(['Authorization' => 'Bearer device.' . $presenceOnly], 'devices/me/sessions'), 403);

        $this->assertSame(0, $this->db->table('remote_sessions')->countAllResults());
    }
}
