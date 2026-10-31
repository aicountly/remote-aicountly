<?php

declare(strict_types=1);

namespace App\Domain\Device;

use App\Domain\Audit\EventType;
use App\Domain\Session\ParticipantService;
use App\Domain\Session\SessionService;
use App\Domain\Session\SessionStatus;
use App\Domain\Support\Clock;
use CodeIgniter\Database\BaseConnection;

/**
 * What a computer has been through: the sessions it took part in, and the
 * things that were done to it.
 *
 * Read by the desktop app about itself (`GET /devices/me/history`) and by the
 * owner in the web console (`GET /devices/{uuid}/history`). Neither caller
 * chooses what is read: both hand this class a device row they were already
 * allowed to have — the device's own, from its credential, or one
 * `DeviceService::findForUser()` returned — and every query below is keyed on
 * that row and nothing else.
 *
 * Two lists, kept apart on purpose. A session is something people did *with*
 * the computer and has people in it; an event is something done *to* it —
 * registered, renamed, suspended, signed out, unattended access on or off. The
 * session list does not repeat itself in the event list, so an unattended
 * connection appears once, as a session, and not a second time as the audit
 * row that started it.
 *
 * What a history never shows is anybody's email address. A peer is named by
 * the name they joined with; a guest who joined with nothing but an invitation
 * address is "Guest". The person reading this may be the computer's owner,
 * but the people in its sessions did not give their addresses to be listed on
 * somebody's desktop.
 */
final class DeviceHistory
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT     = 50;

    /**
     * The audit events that are about the computer itself.
     *
     * An allow-list, not "every row that mentions the device". Two of the
     * rows that do are left out deliberately: DEVICE_AUTHENTICATED is written
     * every time the agent renews its credential — every few minutes, for as
     * long as it runs — and would push everything a person cares about off
     * the end of the list within the hour; DEVICE_AUTH_FAILED is a security
     * record for whoever investigates one, written as often as a stale agent
     * retries, and says nothing a person could act on from here. Rows about a
     * session (CONTROL_*, UNATTENDED_SESSION_STARTED, DEVICE_SESSION_STARTED)
     * are the session list's, not this one's.
     */
    private const EVENTS = [
        EventType::DEVICE_ENROLLED,
        EventType::DEVICE_UPDATED,
        EventType::DEVICE_REVOKED,
        EventType::UNATTENDED_ACCESS_ENABLED,
        EventType::UNATTENDED_ACCESS_DISABLED,
        EventType::DEVICE_REBOOT_REQUESTED,
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly SessionService $sessions,
    ) {
    }

    /**
     * `?limit=` as the routes read it: 20 when absent or not a number, and
     * never fewer than one or more than fifty.
     */
    public static function limit(mixed $value): int
    {
        if (! is_numeric($value)) {
            return self::DEFAULT_LIMIT;
        }

        return max(1, min(self::MAX_LIMIT, (int) $value));
    }

    /**
     * Both lists for one device, newest first, each at most `$limit` long.
     *
     * @param  array<string, mixed> $device a `remote_devices` row the caller may see
     * @return array{sessions: list<array<string, mixed>>, events: list<array<string, mixed>>}
     */
    public function for(array $device, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        return [
            'sessions' => $this->sessionsOf($device, $limit),
            'events'   => $this->eventsOf($device, $limit),
        ];
    }

    // -------------------------------------------------------------- sessions

    /**
     * Sessions the device took part in: as the session it was named for
     * (`remote_sessions.device_id` — an unattended connection, or a session
     * started from the desktop app), or as a participant (`remote_participants
     * .device_id` — the shared screen of a session it joined, or a control
     * companion). Either is enough; a session the agent was asked to host and
     * never joined is still one somebody tried to reach it in.
     *
     * @param  array<string, mixed> $device
     * @return list<array<string, mixed>>
     */
    private function sessionsOf(array $device, int $limit): array
    {
        $deviceId = (int) $device['id'];

        $rows = $this->db->table('remote_sessions s')
            ->select('s.id, s.uuid, s.display_id, s.access_mode, s.status, s.started_at, s.ended_at, s.created_at, s.expires_at')
            // Recorded, not inferred from a participant's current state: a
            // grant that was later revoked, or a controller who asked again and
            // was refused, leaves `control_granted_at` cleared on the row — the
            // session timeline keeps the grant.
            ->select(
                "EXISTS (SELECT 1 FROM remote_session_events e WHERE e.session_id = s.id AND e.event_type = '"
                . EventType::CONTROL_GRANTED . "') AS controlled",
                false,
            )
            ->groupStart()
                ->where('s.device_id', $deviceId)
                ->orWhere('s.id IN (SELECT p.session_id FROM remote_participants p WHERE p.device_id = ' . $deviceId . ')', null, false)
            ->groupEnd()
            ->orderBy('s.created_at', 'DESC')
            ->orderBy('s.id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        if ($rows === []) {
            return [];
        }

        $people = $this->participantsOf(array_map(static fn (array $row): int => (int) $row['id'], $rows));

        return array_map(function (array $row) use ($deviceId, $people): array {
            $row = $this->current($row);

            $own   = null;
            $peers = [];
            foreach ($people[(int) $row['id']] ?? [] as $participant) {
                if ($participant['device_id'] !== null && (int) $participant['device_id'] === $deviceId) {
                    $own = $participant;

                    continue;
                }

                if (! self::wasAdmitted($participant)) {
                    continue;
                }

                $name = self::personName($participant);
                if (! in_array($name, $peers, true)) {
                    $peers[] = $name;
                }
            }

            $row['role']  = $own !== null && (string) $own['participant_role'] === ParticipantService::ROLE_COMPANION
                ? 'COMPANION'
                : 'HOST';
            $row['peers'] = $peers;

            return $row;
        }, $rows);
    }

    /**
     * A session's status as it is now. One past its expiry still reads as
     * live in the row until somebody looks at it, and the history is somebody
     * looking: the session service expires it here exactly as every other read
     * does, rather than this list showing a two-day-old session as ACTIVE.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function current(array $row): array
    {
        if (SessionStatus::isTerminal((string) $row['status']) || ! Clock::hasPassed($row['expires_at'] ?? null)) {
            return $row;
        }

        $fresh = $this->sessions->findById((int) $row['id']);

        if ($fresh !== null) {
            $row['status']   = $fresh['status'];
            $row['ended_at'] = $fresh['ended_at'];
        }

        return $row;
    }

    /**
     * Everybody in these sessions, in the order they arrived, grouped by
     * session. One query for the whole page rather than one per session.
     *
     * Arrival is the row's id, not `requested_at`: the host's row takes the
     * column's default (microseconds) and a joiner's is written to the second,
     * so a helper who asked in the same second as the session was made would
     * sort before the person who made it.
     *
     * @param  list<int> $sessionIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function participantsOf(array $sessionIds): array
    {
        $rows = $this->db->table('remote_participants')
            ->select('session_id, device_id, display_name, participant_role, status, joined_at, approved_by_user_id, user_id')
            ->whereIn('session_id', $sessionIds)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['session_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * Whether somebody was ever actually in the session with this computer.
     *
     * Not their status now: everybody is LEFT once a session ends, including a
     * person who waited and was never let in. Somebody counts if they joined,
     * or were admitted, or are admitted now — and never if they were declined.
     * That also leaves out the owner's own browser seat in a session started
     * from the desktop app, which they never sat in
     * (`DeviceSessionService::startAttended`).
     *
     * @param array<string, mixed> $participant
     */
    private static function wasAdmitted(array $participant): bool
    {
        $status = (string) $participant['status'];

        if ($status === 'DENIED') {
            return false;
        }

        return $participant['joined_at'] !== null
            || $participant['approved_by_user_id'] !== null
            || in_array($status, ['APPROVED', 'JOINED'], true);
    }

    /**
     * The name somebody joined with, unless it is an address.
     *
     * A guest invited by email who typed no name joins under the address
     * (`JoinService::redeemAsGuest`), and that is fine on the host's screen at
     * the time but not in a list kept on a desktop afterwards.
     *
     * @param array<string, mixed> $participant
     */
    private static function personName(array $participant): string
    {
        $name = trim((string) $participant['display_name']);

        if ($name === '' || filter_var($name, FILTER_VALIDATE_EMAIL) !== false) {
            return $participant['user_id'] === null ? 'Guest' : 'AICOUNTLY user';
        }

        return $name;
    }

    // ---------------------------------------------------------------- events

    /**
     * The audit rows about this device, newest first.
     *
     * Matched on the record the row is about (`entity_type = 'device'`, which
     * the audit writer derives from `metadata.deviceUuid` when nobody named
     * one) or on `metadata.deviceUuid` itself, for the one listed event that
     * belongs to a session as well (a restart asked for during one). The
     * device's own scope is a predicate too — a uuid is unique on its own, but
     * a personal computer's history is read from personal rows only, and
     * saying so costs nothing.
     *
     * @param  array<string, mixed> $device
     * @return list<array<string, mixed>>
     */
    private function eventsOf(array $device, int $limit): array
    {
        $uuid = (string) $device['uuid'];

        $builder = $this->db->table('remote_audit_logs a')
            ->select('a.id, a.event, a.actor_type, a.actor_label, a.metadata, a.created_at, i.display_name AS actor_display_name')
            ->join('remote_identities i', 'i.id = a.actor_user_id', 'left')
            ->whereIn('a.event', self::EVENTS)
            ->groupStart()
                ->groupStart()
                    ->where('a.entity_type', 'device')
                    ->where('a.entity_id', $uuid)
                ->groupEnd()
                ->orWhere("a.metadata->>'deviceUuid' = " . $this->db->escape($uuid), null, false)
            ->groupEnd();

        if ($device['company_id'] === null) {
            $builder->where('a.company_id', null);
        } else {
            $builder->where('a.company_id', (int) $device['company_id']);
        }

        $rows = $builder
            ->orderBy('a.created_at', 'DESC')
            ->orderBy('a.id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        return array_map(function (array $row) use ($device): array {
            $metadata = is_string($row['metadata'] ?? null)
                ? (json_decode($row['metadata'], true) ?: [])
                : (array) ($row['metadata'] ?? []);

            return [
                'event'      => (string) $row['event'],
                'created_at' => $row['created_at'],
                'summary'    => self::summary((string) $row['event'], $metadata),
                'actor_name' => self::actorName($row, $metadata, $device),
            ];
        }, $rows);
    }

    /**
     * One short sentence about what happened, written for the owner rather
     * than for an auditor: what changed, not which column.
     *
     * @param array<string, mixed> $metadata
     */
    private static function summary(string $event, array $metadata): string
    {
        $byDevice = ($metadata['by'] ?? null) === 'DEVICE';

        return match ($event) {
            EventType::DEVICE_ENROLLED            => 'Registered with AICOUNTLY Remote.',
            EventType::DEVICE_UPDATED             => self::updateSummary($metadata),
            EventType::DEVICE_REVOKED             => $byDevice
                ? 'Signed out from the desktop app.'
                : 'Removed from AICOUNTLY Remote.',
            EventType::UNATTENDED_ACCESS_ENABLED  => 'Unattended access switched on.',
            EventType::UNATTENDED_ACCESS_DISABLED => $byDevice
                ? 'Unattended access switched off at the computer.'
                : 'Unattended access switched off.',
            EventType::DEVICE_REBOOT_REQUESTED    => 'A restart was requested during a session.',
            default                               => EventType::describe($event) . '.',
        };
    }

    /**
     * A device update says what it changed: a rename names both names, a
     * suspension says so, and the same machine registering again with the key
     * it already had (an upgrade, a reinstall) is called that.
     *
     * @param array<string, mixed> $metadata
     */
    private static function updateSummary(array $metadata): string
    {
        if (($metadata['reason'] ?? null) === 'RE_ENROLMENT') {
            return 'Registered again by the desktop app.';
        }

        $sentences = [];
        foreach ((array) ($metadata['changes'] ?? []) as $change) {
            if (! is_array($change)) {
                continue;
            }

            $sentence = match ($change['field'] ?? null) {
                'device_name' => sprintf('Renamed from “%s” to “%s”.', (string) ($change['old'] ?? ''), (string) ($change['new'] ?? '')),
                'status'      => match ($change['new'] ?? null) {
                    DeviceService::STATUS_SUSPENDED => 'Suspended.',
                    DeviceService::STATUS_ACTIVE    => 'Resumed.',
                    default                         => null,
                },
                'unattended_access_enabled' => ($change['new'] ?? null) === false ? 'Unattended access switched off.' : null,
                default => null,
            };

            if ($sentence !== null) {
                $sentences[] = $sentence;
            }
        }

        return $sentences === [] ? 'Details updated.' : implode(' ', $sentences);
    }

    /**
     * Who did it, by name: the person (by the name they have now, as the
     * company audit screen shows it), the computer itself when it acted with
     * nobody named, or AICOUNTLY Remote for something the system did. Never
     * an address — a person with no display name is recorded under their
     * email, and that is left out here like every other address.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $device
     */
    private static function actorName(array $row, array $metadata, array $device): ?string
    {
        $name = trim((string) ($row['actor_display_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['actor_label'] ?? ''));
        }

        if ((string) $row['actor_type'] === 'SYSTEM') {
            // The computer switching its own unattended access off is recorded
            // as the system with `by: DEVICE`: nobody signed in did it, the
            // machine did, at somebody's hand.
            return ($metadata['by'] ?? null) === 'DEVICE' ? (string) $device['device_name'] : 'AICOUNTLY Remote';
        }

        if ($name === '' || filter_var($name, FILTER_VALIDATE_EMAIL) !== false) {
            return null;
        }

        return $name;
    }
}
