<?php

declare(strict_types=1);

namespace Tests\Device;

use App\Domain\Auth\RemoteIdentity;
use App\Domain\Device\DeviceHistory;
use App\Domain\Device\DevicePrincipal;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Session\ParticipantService;
use App\Domain\Session\SessionStatus;
use App\Domain\Support\Clock;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\DeviceApiRequests;
use Tests\Support\RemoteTestCase;

/**
 * A computer's history: `GET /devices/me/history` for the desktop app, `GET /devices/{uuid}/history` for its
 * owner in the console. What is in it, in what order, and whose it is.
 *
 * @internal
 */
final class DeviceHistoryTest extends RemoteTestCase
{
    use FeatureTestTrait;
    use DeviceApiRequests;

    private RemoteIdentity $owner;

    /** @var array{device: array<string, mixed>, publicKey: string, secretKey: string} */
    private array $computer;

    private string $uuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner    = $this->makeIdentity('Meera at home');
        $this->computer = $this->enrolDevice($this->owner, null, ['deviceName' => 'Meera laptop']);
        $this->uuid     = (string) $this->computer['device']['uuid'];
        $this->markDeviceOnline($this->uuid);
    }

    private function principal(): DevicePrincipal
    {
        return new DevicePrincipal($this->uuid, null, DevicePrincipal::agentScopes(), time() + 300);
    }

    /** @return array{sessions: list<array<string, mixed>>, events: list<array<string, mixed>>} */
    private function history(string $query = ''): array
    {
        return $this->dataOf($this->getJson($this->asDevice($this->computer), 'devices/me/history' . $query));
    }

    public function testTheEventsSayWhatWasDoneToThisComputer(): void
    {
        // Another of Meera's computers has a history of its own, which is not this one's.
        $other = $this->enrolDevice($this->owner, null, ['deviceName' => 'Meera desktop']);
        Services::deviceService()->enableUnattended($this->owner, (string) $other['device']['uuid'], true);

        $this->dataOf($this->withHeaders($this->asPerson($this->owner))->withBodyFormat('json')
            ->patch("v1/remote/devices/{$this->uuid}", ['deviceName' => 'Meera MacBook']));
        Services::deviceService()->enableUnattended($this->owner, $this->uuid, true);
        $this->dataOf($this->postJson($this->asDevice($this->computer), 'devices/me/unattended/disable'));
        Services::deviceService()->update($this->owner, $this->uuid, ['status' => 'SUSPENDED']);
        Services::deviceService()->update($this->owner, $this->uuid, ['status' => 'ACTIVE']);

        // The same machine registering again with the key it already has: an upgrade, a reinstall.
        Services::deviceService()->enrol($this->owner, null, [
            'deviceName' => 'Meera MacBook',
            'publicKey'  => $this->computer['publicKey'],
        ]);

        $events = $this->history()['events'];

        $this->assertSame([
            ['DEVICE_UPDATED', 'Registered again by the desktop app.', 'Meera at home'],
            ['DEVICE_UPDATED', 'Resumed.', 'Meera at home'],
            ['DEVICE_UPDATED', 'Suspended.', 'Meera at home'],
            ['UNATTENDED_ACCESS_DISABLED', 'Unattended access switched off at the computer.', 'Meera MacBook'],
            ['UNATTENDED_ACCESS_ENABLED', 'Unattended access switched on.', 'Meera at home'],
            ['DEVICE_UPDATED', 'Renamed from “Meera laptop” to “Meera MacBook”.', 'Meera at home'],
            ['DEVICE_ENROLLED', 'Registered with AICOUNTLY Remote.', 'Meera at home'],
        ], array_map(static fn (array $e): array => [$e['event'], $e['summary'], $e['actorName']], $events), 'newest first, this computer only');

        $this->assertSame(['event', 'at', 'summary', 'actorName'], array_keys($events[0]));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', (string) $events[0]['at']);

        // Every credential renewal is in the audit trail, and none of it is here.
        $this->assertGreaterThan(0, $this->db->table('remote_audit_logs')->where('event', 'DEVICE_AUTHENTICATED')->countAllResults());
        $this->assertNotContains('DEVICE_AUTHENTICATED', array_column($events, 'event'));
    }

    /** Signed out from the app: the machine can no longer ask, but its owner still reads why in the console. */
    public function testSigningOutIsTheLastThingInTheConsoleHistory(): void
    {
        $this->dataOf($this->postJson($this->asDevice($this->computer), 'devices/me/revoke'));

        $events = $this->dataOf($this->getJson($this->asPerson($this->owner), "devices/{$this->uuid}/history"))['events'];

        $this->assertSame('DEVICE_REVOKED', $events[0]['event']);
        $this->assertSame('Signed out from the desktop app.', $events[0]['summary']);
        $this->assertSame('Meera at home', $events[0]['actorName']);
    }

    public function testTheSessionsAreTheOnesThisComputerTookPartIn(): void
    {
        $ravi       = $this->makeIdentity('Ravi helping');
        $byEmail    = $this->makeIdentity('asha@example.test');
        $waitedOnly = $this->makeIdentity('Never admitted');
        $turnedAway = $this->makeIdentity('Turned away');
        $devices    = Services::deviceSessionService();
        $sessions   = Services::sessionService();
        $people     = Services::participantService();

        // 1. Meera reaches it unattended from elsewhere; control comes with the session. It ran, and ended.
        Services::deviceService()->enableUnattended($this->owner, $this->uuid, true);
        $unattended = $devices->startUnattended($this->owner, $this->uuid)['session'];
        $sessions->end($sessions->markActive($this->reload($unattended)), $this->owner);

        // 2. "Give your code": Ravi is admitted, so is somebody joined under an address; one waits and one is
        // declined, and neither of them was ever in it with the computer.
        $hosted = $devices->startAttended($this->principal());
        $devices->joinAsHost($this->reload($hosted), $this->principal());
        foreach ([$ravi, $byEmail, $waitedOnly, $turnedAway] as $person) {
            Services::joinService()->joinAuthenticated($this->reload($hosted), $person);
        }
        $devices->admit($this->principal(), (string) $hosted['uuid'], (string) $people->findByUser((int) $hosted['id'], $ravi->id)['uuid']);
        $devices->admit($this->principal(), (string) $hosted['uuid'], (string) $people->findByUser((int) $hosted['id'], $byEmail->id)['uuid']);
        $devices->decline($this->principal(), (string) $hosted['uuid'], (string) $people->findByUser((int) $hosted['id'], $turnedAway->id)['uuid']);

        // 3. Meera shares her screen from the browser, and this computer is the control companion; Ravi is
        // given control through it.
        $browser = $this->makeSession($this->owner);
        $joining = $people->requestJoin($browser, $ravi, $ravi->displayName, ParticipantService::ROLE_VIEWER);
        $people->approve($browser, (string) $joining['uuid'], $this->owner);
        $people->markJoined($browser, (string) $joining['uuid']);
        $personal = static fn (RemoteIdentity $who) => Services::policyResolver()->resolve($who, 'PERSONAL', null);
        $sessions->recordShareStarted($this->reload($browser), $this->owner, 'monitor', $personal($this->owner), 1920, 1080);
        $companions = Services::companionService();
        $link       = $companions->link($this->reload($browser), $this->owner, $personal($this->owner), $this->uuid)['link'];
        $companions->join($this->principal(), (string) $link['uuid']);
        $companions->selectMonitor($this->principal(), (string) $link['uuid'], ['id' => 1, 'name' => 'Built-in', 'x' => 0, 'y' => 0, 'width' => 1512, 'height' => 982, 'scale' => 2], 'AUTO_SINGLE');
        Services::controlService()->request($this->reload($browser), $ravi, $personal($ravi));
        Services::controlService()->grant($this->reload($browser), $this->owner, (string) $joining['uuid'], $personal($this->owner));

        // Not this computer's: a session of Meera's other computer, and a browser session it never joined.
        $other = $this->enrolDevice($this->owner, null, ['deviceName' => 'Meera desktop']);
        $devices->startAttended(new DevicePrincipal((string) $other['device']['uuid'], null, DevicePrincipal::agentScopes(), time() + 300));
        $this->makeSession($this->owner);

        $history = $this->history();

        $this->assertSame(
            [(string) $browser['uuid'], (string) $hosted['uuid'], (string) $unattended['uuid']],
            array_column($history['sessions'], 'uuid'),
            'newest first, and only the three it took part in',
        );

        [$companion, $help, $reached] = $history['sessions'];

        $this->assertSame(
            ['uuid', 'displayId', 'accessMode', 'role', 'status', 'startedAt', 'endedAt', 'durationSeconds', 'peers', 'controlled'],
            array_keys($companion),
        );

        $this->assertSame('COMPANION', $companion['role']);
        $this->assertSame('ATTENDED', $companion['accessMode']);
        $this->assertSame(['Meera at home', 'Ravi helping'], $companion['peers']);
        $this->assertTrue($companion['controlled']);
        $this->assertSame(SessionStatus::ACTIVE, $companion['status']);

        $this->assertSame('HOST', $help['role']);
        $this->assertSame('ATTENDED', $help['accessMode']);
        $this->assertSame(['Ravi helping', 'AICOUNTLY user'], $help['peers'], 'admitted people only, and never an address');
        $this->assertFalse($help['controlled']);
        $this->assertSame((string) $hosted['display_id'], $help['displayId']);
        $this->assertNotNull($help['startedAt'], 'never started, so it is shown at the moment it was created');
        $this->assertNull($help['endedAt']);
        $this->assertNull($help['durationSeconds']);

        $this->assertSame('HOST', $reached['role']);
        $this->assertSame('UNATTENDED', $reached['accessMode']);
        $this->assertSame(['Meera at home'], $reached['peers'], 'the owner, who connected');
        $this->assertTrue($reached['controlled']);
        $this->assertSame(SessionStatus::ENDED, $reached['status']);
        $this->assertNotNull($reached['endedAt']);
        $this->assertIsInt($reached['durationSeconds']);

        foreach ($history['sessions'] as $session) {
            foreach ($session['peers'] as $peer) {
                $this->assertStringNotContainsString('@', $peer);
            }
        }
    }

    /** A session left past its expiry is shown as what it is now, not as it was last written. */
    public function testAnExpiredSessionIsShownExpired(): void
    {
        $hosted = Services::deviceSessionService()->startAttended($this->principal());
        $this->db->table('remote_sessions')->where('id', $hosted['id'])->update(['expires_at' => Clock::in(-60)]);

        $sessions = $this->history()['sessions'];

        $this->assertSame(SessionStatus::EXPIRED, $sessions[0]['status']);
        $this->assertNotNull($sessions[0]['endedAt']);
        $this->assertSame(SessionStatus::EXPIRED, Services::sessionService()->findByUuidOrFail((string) $hosted['uuid'])['status']);
    }

    public function testLimitIsTwentyByDefaultAndAtMostFifty(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Services::deviceSessionService()->startAttended($this->principal());
        }
        Services::deviceService()->enableUnattended($this->owner, $this->uuid, true);

        $one = $this->history('?limit=1');
        $this->assertCount(1, $one['sessions']);
        $this->assertCount(1, $one['events']);

        $all = $this->history('?limit=nonsense');
        $this->assertCount(3, $all['sessions']);
        $this->assertCount(2, $all['events']);

        $this->assertSame(20, DeviceHistory::limit(null));
        $this->assertSame(20, DeviceHistory::limit('abc'));
        $this->assertSame(1, DeviceHistory::limit('0'));
        $this->assertSame(50, DeviceHistory::limit('5000'));
        $this->assertSame(7, DeviceHistory::limit('7'));
    }

    /** The console reads the same history — for the computer's owner, and for nobody else. */
    public function testTheConsoleShowsTheOwnerTheSameHistoryAndNobodyElseAnything(): void
    {
        Services::deviceService()->enableUnattended($this->owner, $this->uuid, true);
        Services::deviceSessionService()->startAttended($this->principal());

        $fromDevice  = $this->history();
        $fromConsole = $this->dataOf($this->getJson($this->asPerson($this->owner), "devices/{$this->uuid}/history"));

        $this->assertSame($fromDevice, $fromConsole);
        $this->assertCount(1, $fromConsole['sessions']);

        $stranger = $this->makeIdentity('Stranger');
        $this->errorOf($this->getJson($this->asPerson($stranger), "devices/{$this->uuid}/history"), 404);
        $this->errorOf($this->getJson($this->asPerson($this->owner), 'devices/00000000-0000-4000-8000-000000000000/history'), 404);
        $this->errorOf($this->getJson([], "devices/{$this->uuid}/history"), 401);

        // A platform-wide grant of the organisation's device permission is no key to a person's own computer.
        $this->setRolePermission(null, 'MEMBER', PermissionCatalog::DEVICE_MANAGE, 'ALLOW');
        $this->errorOf($this->getJson($this->asPerson($stranger), "devices/{$this->uuid}/history"), 404);

        // The stranger's own computer's history says nothing about Meera's.
        $theirs = $this->enrolDevice($stranger, null, ['deviceName' => 'Stranger PC']);
        $own    = $this->dataOf($this->getJson($this->asDevice($theirs), 'devices/me/history'));
        $this->assertSame([], $own['sessions']);
        $this->assertSame(['DEVICE_ENROLLED'], array_column($own['events'], 'event'));
    }
}
