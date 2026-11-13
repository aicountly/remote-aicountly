<?php

declare(strict_types=1);

namespace Tests\Session;

use App\Domain\Audit\EventType;
use App\Domain\Session\SessionStatus;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * `php spark remote:sessions-sweep` — the read that nobody made.
 *
 * A session whose time is up expires when it is next read. One that nobody opens again — a tab closed, a
 * browser that crashed — stayed "waiting" or "active" on the record: counted as active on the dashboard,
 * its participants still "joined". The sweep closes those, by the same path a read takes, and nothing else.
 *
 * @internal
 */
final class SessionSweepTest extends RemoteTestCase
{
    use StreamFilterTrait;

    public function testASessionNobodyOpenedAgainIsClosedAndAllThatWasInItWithIt(): void
    {
        $host    = $this->makeIdentity('Host');
        $helper  = $this->makeIdentity('Helper');
        $overdue = $this->makeSession($host);
        $running = $this->makeSession($host);

        // Somebody admitted and in the session, as they still are when a browser simply goes away.
        $joined = Services::joinService()->joinByCode((string) $overdue['session_code'], $helper, null, null);
        Services::participantService()->approve($overdue, (string) $joined['participant']['uuid'], $host);

        // Its time ran out a minute ago, and nobody has read it since.
        $this->db->query("UPDATE remote_sessions SET expires_at = NOW() - INTERVAL '1 minute' WHERE id = ?", [$overdue['id']]);

        $this->assertSame(2, Services::sessionService()->dashboardMetrics($host, [])['activeSessions'], 'Both count as active until somebody looks.');

        command('remote:sessions-sweep');

        $this->assertStringContainsString('1 overdue session closed.', $this->getStreamFilterBuffer());

        // Read straight from the table: a read through the service would have expired it anyway.
        $row = $this->db->table('remote_sessions')->where('id', $overdue['id'])->get()->getRowArray();
        $this->assertSame(SessionStatus::EXPIRED, $row['status']);
        $this->assertSame('EXPIRED', $row['end_reason']);
        $this->assertNull($row['session_code'], 'Its code can no longer be typed.');
        $this->assertNotNull($row['ended_at']);
        $this->assertHasEvent($overdue, EventType::SESSION_EXPIRED);

        $stillIn = $this->db->table('remote_participants')
            ->where('session_id', $overdue['id'])
            ->whereIn('status', ['REQUESTED', 'APPROVED', 'JOINED'])
            ->countAllResults();
        $this->assertSame(0, $stillIn, 'Nobody is left waiting for, or in, a session that is over.');

        $this->assertSame(
            SessionStatus::WAITING,
            $this->db->table('remote_sessions')->where('id', $running['id'])->get()->getRowArray()['status'],
            'A session with time left is not touched.',
        );
        $this->assertSame(1, Services::sessionService()->dashboardMetrics($host, [])['activeSessions']);
    }

    public function testWithNothingOverdueItClosesNothingAndASecondRunFindsNothingLeft(): void
    {
        $host    = $this->makeIdentity('Host');
        $session = $this->makeSession($host);

        command('remote:sessions-sweep');
        $this->assertStringContainsString('0 overdue sessions closed.', $this->getStreamFilterBuffer());

        $this->db->query("UPDATE remote_sessions SET expires_at = NOW() - INTERVAL '1 minute' WHERE id = ?", [$session['id']]);

        $this->assertSame(1, Services::sessionService()->sweepExpired());
        $this->assertSame(0, Services::sessionService()->sweepExpired(), 'What is closed is not closed twice.');
        $this->assertSame(
            1,
            $this->db->table('remote_session_events')->where('session_id', $session['id'])->where('event_type', EventType::SESSION_EXPIRED)->countAllResults(),
        );
    }

    public function testOneRunTakesNoMoreThanItsLimitAndTheNextTakesTheRest(): void
    {
        $host = $this->makeIdentity('Host');

        foreach (range(1, 3) as $ignored) {
            $session = $this->makeSession($host);
            $this->db->query("UPDATE remote_sessions SET expires_at = NOW() - INTERVAL '1 minute' WHERE id = ?", [$session['id']]);
        }

        $this->assertSame(2, Services::sessionService()->sweepExpired(2));
        $this->assertSame(1, Services::sessionService()->sweepExpired(2));
    }
}
