<?php

declare(strict_types=1);

namespace App\Domain\Session;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\EventType;
use App\Domain\Support\Clock;
use App\Domain\Support\Ids;
use CodeIgniter\Database\BaseConnection;

/**
 * The control-companion links of a session, read and presented the same way everywhere
 * (docs/desktop/CONTROL_COMPANION.md).
 *
 * A link is how a person's own desktop app serves the screen they share from a browser: PENDING until the
 * machine confirms it, JOINED once its input-only participant is in the session, ACTIVE once it knows which
 * monitor the shared picture is. Everything that decides something about a link lives in
 * {@see \App\Domain\Device\CompanionService}; this class only finds them, and finishes the ones that have
 * run out on their own — a PENDING link past its five minutes, or any live link in a session that is over —
 * the moment anyone looks, exactly as sessions expire on read.
 */
class CompanionLinks
{
    public const STATUS_PENDING  = 'PENDING';
    public const STATUS_JOINED   = 'JOINED';
    public const STATUS_ACTIVE   = 'ACTIVE';
    public const STATUS_DECLINED = 'DECLINED';
    public const STATUS_ENDED    = 'ENDED';
    public const STATUS_EXPIRED  = 'EXPIRED';

    /** The statuses the partial unique indexes cover: at most one per session and one per device. */
    public const LIVE = [self::STATUS_PENDING, self::STATUS_JOINED, self::STATUS_ACTIVE];

    /** How long a machine has to confirm a link before it lapses. */
    public const PENDING_MINUTES = 5;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * The session's live link, if it has one.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>|null
     */
    public function liveFor(array $session): ?array
    {
        $this->sweep($session);

        return $this->first($this->joined()->where('l.session_id', $session['id'])->whereIn('l.status', self::LIVE));
    }

    /**
     * The session's most recent link, live or not — what the session screen shows ("waiting for the
     * computer", "the computer said no", …).
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>|null
     */
    public function latestFor(array $session, ?int $deviceId = null): ?array
    {
        $this->sweep($session);

        $builder = $this->joined()->where('l.session_id', $session['id']);
        if ($deviceId !== null) {
            $builder->where('l.device_id', $deviceId);
        }

        return $this->first($builder->orderBy('l.created_at', 'DESC')->orderBy('l.id', 'DESC'));
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        if (! Ids::isUuid($uuid)) {
            return null;
        }

        return $this->first($this->joined()->where('l.uuid', $uuid));
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->first($this->joined()->where('l.id', $id));
    }

    /**
     * The ACTIVE link this companion participant serves, if any.
     *
     * @return array<string, mixed>|null
     */
    public function activeForCompanion(int $companionParticipantId): ?array
    {
        return $this->first(
            $this->joined()
                ->where('l.companion_participant_id', $companionParticipantId)
                ->where('l.status', self::STATUS_ACTIVE),
        );
    }

    /**
     * Finish what has run out on its own: a PENDING link past its expiry, and every live link of a session
     * that is no longer live. Recorded as the system acting, because nobody did.
     *
     * @param array<string, mixed> $session
     */
    public function sweep(array $session): void
    {
        $rows = $this->db->table('remote_control_companions')
            ->select('id, uuid, status, expires_at, sharer_participant_id')
            ->where('session_id', $session['id'])
            ->whereIn('status', self::LIVE)
            ->get()
            ->getResultArray();

        $sessionOver = ! SessionStatus::isLive((string) $session['status']);

        foreach ($rows as $row) {
            if ($sessionOver) {
                $this->finish($session, $row, self::STATUS_ENDED, 'SESSION_ENDED', null, 'SYSTEM');

                continue;
            }

            if ((string) $row['status'] === self::STATUS_PENDING && Clock::hasPassed($row['expires_at'])) {
                $this->finish($session, $row, self::STATUS_EXPIRED, 'EXPIRED', null, 'SYSTEM');
            }
        }
    }

    /**
     * Move a live link to a finished status, once, and record it.
     *
     * Guarded on the status it was read in, so two callers finishing the same link produce one row change
     * and one audit entry. The companion participant, if there is one, leaves with it.
     *
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $link
     * @return bool whether this call was the one that finished it
     */
    public function finish(
        array $session,
        array $link,
        string $status,
        string $reason,
        ?int $actorUserId,
        string $actorType,
    ): bool {
        $this->db->table('remote_control_companions')
            ->where('id', $link['id'])
            ->whereIn('status', self::LIVE)
            ->update([
                'status'     => $status,
                'end_reason' => mb_substr($reason, 0, 40),
                'ended_at'   => Clock::now(),
                'updated_at' => Clock::now(),
            ]);

        if ($this->db->affectedRows() === 0) {
            return false;
        }

        $current = $this->findById((int) $link['id']);

        if ($current !== null && $current['companion_participant_id'] !== null) {
            $this->closeCompanionParticipant($session, (int) $current['companion_participant_id']);
        }

        $this->audit->record(
            $session,
            $status === self::STATUS_DECLINED ? EventType::CONTROL_COMPANION_DECLINED : EventType::CONTROL_COMPANION_ENDED,
            $actorUserId,
            $actorType,
            $current !== null && $current['companion_participant_id'] !== null ? (int) $current['companion_participant_id'] : null,
            $current['companion_participant_uuid'] ?? null,
            [
                'companionUuid' => (string) $link['uuid'],
                'status'        => $status,
                'reason'        => $reason,
                'deviceUuid'    => $current['device_uuid'] ?? null,
            ],
        );

        return true;
    }

    /**
     * The machine's input-only participant leaves with its link: nothing it holds survives the link.
     *
     * @param array<string, mixed> $session
     */
    private function closeCompanionParticipant(array $session, int $participantId): void
    {
        $row = $this->db->table('remote_participants')->select('uuid')->where('id', $participantId)->get()->getRowArray();

        $this->db->table('remote_participants')
            ->where('id', $participantId)
            ->whereIn('status', ['REQUESTED', 'APPROVED', 'JOINED'])
            ->update([
                'status'           => 'LEFT',
                'left_at'          => Clock::now(),
                'connection_state' => 'CLOSED',
                'is_sharing'       => false,
                'updated_at'       => Clock::now(),
            ]);

        if ($row !== null && isset($session['uuid'])) {
            \Config\Services::iceConfigService()->releaseSession((string) $session['uuid'], (string) $row['uuid']);
        }
    }

    /**
     * A link as the browser and the agent render it.
     *
     * `ready` is the one thing the UI must not work out for itself: the link is ACTIVE *and* the linked
     * person is in the session sharing an entire screen right now.
     *
     * @param  array<string, mixed>|null $link
     * @param  array<string, mixed>|null $session
     * @return array<string, mixed>|null
     */
    public function present(?array $link, ?array $session = null): ?array
    {
        if ($link === null) {
            return null;
        }

        $monitor = $link['monitor'] ?? null;
        if (is_string($monitor)) {
            $monitor = json_decode($monitor, true) ?: null;
        }

        return [
            'uuid'                  => (string) $link['uuid'],
            'status'                => (string) $link['status'],
            'deviceUuid'            => $link['device_uuid'] ?? null,
            'deviceName'            => $link['device_name'] ?? null,
            'linkedParticipantUuid' => $link['sharer_participant_uuid'] ?? null,
            'participantUuid'       => $link['companion_participant_uuid'] ?? null,
            'monitor'               => $monitor,
            'monitorMatch'          => $link['monitor_match'] ?? null,
            'ready'                 => $session !== null && $this->isReady($session, $link),
            'endReason'             => $link['end_reason'] ?? null,
            'requestedAt'           => Clock::iso($link['created_at'] ?? null),
            'expiresAt'             => Clock::iso($link['expires_at'] ?? null),
            'joinedAt'              => Clock::iso($link['joined_at'] ?? null),
            'endedAt'               => Clock::iso($link['ended_at'] ?? null),
        ];
    }

    /**
     * Whether input may flow through this link right now.
     *
     * @param array<string, mixed> $session
     * @param array<string, mixed> $link
     */
    public function isReady(array $session, array $link): bool
    {
        if ((string) $link['status'] !== self::STATUS_ACTIVE) {
            return false;
        }

        return $this->blockedReason($session, $link) === null;
    }

    /**
     * Why a live link cannot carry input yet, or null when it can.
     *
     * Read fresh from the database rather than from the session array a caller holds, because the share
     * that decides it may have changed in this same request.
     *
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $link
     * @return 'NOT_SHARING'|'SURFACE_NOT_MONITOR'|'COMPANION_NOT_READY'|null
     */
    public function blockedReason(array $session, array $link): ?string
    {
        $sharer = $this->db->table('remote_participants')
            ->select('status, is_sharing')
            ->where('id', $link['sharer_participant_id'])
            ->get()
            ->getRowArray();

        $surface = $this->surfaceOf((int) $session['id']);

        $sharing = $sharer !== null
            && (string) $sharer['status'] === 'JOINED'
            && \App\Domain\Support\Presenter::bool($sharer['is_sharing'])
            && $surface['displaySurface'] !== null;

        if (! $sharing) {
            return 'NOT_SHARING';
        }

        if ($surface['displaySurface'] !== 'monitor') {
            return 'SURFACE_NOT_MONITOR';
        }

        if ((string) $link['status'] !== self::STATUS_ACTIVE) {
            return 'COMPANION_NOT_READY';
        }

        return null;
    }

    /**
     * What the browser reported it is sharing, fresh.
     *
     * @return array{displaySurface: ?string, width: ?int, height: ?int, verified: bool}
     */
    public function surfaceOf(int $sessionId): array
    {
        $row = $this->db->table('remote_sessions')
            ->select('actual_display_surface, actual_surface_width, actual_surface_height')
            ->where('id', $sessionId)
            ->get()
            ->getRowArray() ?? [];

        $surface = isset($row['actual_display_surface']) ? (string) $row['actual_display_surface'] : null;

        return [
            'displaySurface' => $surface,
            'width'          => isset($row['actual_surface_width']) ? (int) $row['actual_surface_width'] : null,
            'height'         => isset($row['actual_surface_height']) ? (int) $row['actual_surface_height'] : null,
            // 'unknown' is a browser that does not report displaySurface (Firefox, Safari): never verified.
            'verified'       => $surface !== null && $surface !== 'unknown',
        ];
    }

    // ------------------------------------------------------------- internals

    private function joined(): \CodeIgniter\Database\BaseBuilder
    {
        return $this->db->table('remote_control_companions l')
            ->select('l.*, d.uuid AS device_uuid, d.device_name, d.user_id AS device_user_id,
                      sp.uuid AS sharer_participant_uuid, sp.user_id AS sharer_user_id,
                      cp.uuid AS companion_participant_uuid')
            ->join('remote_devices d', 'd.id = l.device_id', 'left')
            ->join('remote_participants sp', 'sp.id = l.sharer_participant_id', 'left')
            ->join('remote_participants cp', 'cp.id = l.companion_participant_id', 'left');
    }

    /** @return array<string, mixed>|null */
    private function first(\CodeIgniter\Database\BaseBuilder $builder): ?array
    {
        $row = $builder->limit(1)->get()->getRowArray();

        return $row === null ? null : $row;
    }
}
