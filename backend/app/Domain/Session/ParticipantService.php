<?php

declare(strict_types=1);

namespace App\Domain\Session;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\EventType;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Support\ApiException;
use App\Domain\Support\Clock;
use App\Domain\Support\Ids;
use App\Domain\Support\Presenter;
use CodeIgniter\Database\BaseConnection;

/**
 * Who is in a session, and how they got there (§22, §71).
 *
 * The rule this service protects: **nothing is transmitted before the host
 * approves.** A join request creates a participant in REQUESTED, and only an
 * approval moves it to APPROVED — which is also the only state in which the
 * signalling token endpoint will mint a token. A viewer who is not approved
 * cannot reach the signalling room at all, so there is no path by which frames
 * reach an unapproved peer.
 */
class ParticipantService
{
    public const ROLE_SHARER  = 'SHARER';
    public const ROLE_VIEWER  = 'VIEWER';
    public const ROLE_SUPPORT = 'SUPPORT_TECHNICIAN';
    public const ROLE_OBSERVER = 'OBSERVER';
    public const ROLE_GUEST   = 'GUEST';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * The session creator: host, sharer, already approved and joined.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function createHost(array $session, RemoteIdentity $identity, ?string $userAgent, ?string $ip): array
    {
        $uuid = Ids::uuid4();

        $this->db->table('remote_participants')->insert([
            'uuid'             => $uuid,
            'session_id'       => $session['id'],
            'user_id'          => $identity->id,
            'participant_role' => self::ROLE_SHARER,
            'client_type'      => 'BROWSER',
            'capabilities'     => json_encode(ClientCapabilities::browser()),
            'display_name'     => $identity->displayName,
            'email'            => $identity->email,
            'status'           => 'JOINED',
            'is_host'          => true,
            'joined_at'        => Clock::now(),
            'last_seen_at'     => Clock::now(),
            'ip'               => $ip,
            'user_agent'       => $userAgent,
        ]);

        return $this->findByUuidOrFail($uuid);
    }

    /**
     * Ask to join. Creates, or re-opens, this person's participant row.
     *
     * Idempotent by design (§59): reloading the join page, or two tabs racing,
     * must not produce two rows or reset an approval that was already given.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function requestJoin(
        array $session,
        ?RemoteIdentity $identity,
        string $displayName,
        string $role,
        ?int $invitationId = null,
        ?string $email = null,
        ?string $userAgent = null,
        ?string $ip = null,
    ): array {
        if (! SessionStatus::isLive((string) $session['status'])) {
            throw ApiException::conflict('SESSION_ALREADY_ENDED', 'This Remote session has already finished.');
        }

        if ($identity !== null) {
            $existing = $this->findByUser((int) $session['id'], $identity->id);

            if ($existing !== null) {
                if ($existing['status'] === 'DENIED') {
                    throw ApiException::forbidden('JOIN_DENIED', 'The host declined your request to join this session.');
                }

                // Someone who left may come back; an approval already granted
                // stands, so a reconnect does not need a second approval.
                if (in_array($existing['status'], ['LEFT', 'REMOVED'], true)) {
                    $this->db->table('remote_participants')->where('id', $existing['id'])->update([
                        'status'       => 'REQUESTED',
                        'left_at'      => null,
                        'requested_at' => Clock::now(),
                        'updated_at'   => Clock::now(),
                    ]);

                    return $this->findByIdOrFail((int) $existing['id']);
                }

                return $existing;
            }
        }

        $uuid = Ids::uuid4();

        $this->db->table('remote_participants')->insert([
            'uuid'             => $uuid,
            'session_id'       => $session['id'],
            'user_id'          => $identity?->id,
            'invitation_id'    => $invitationId,
            'participant_role' => $role,
            'client_type'      => 'BROWSER',
            'capabilities'     => json_encode(ClientCapabilities::browser()),
            'display_name'     => $displayName,
            'email'            => $email ?? $identity?->email,
            'status'           => 'REQUESTED',
            'is_host'          => false,
            'requested_at'     => Clock::now(),
            'ip'               => $ip,
            'user_agent'       => $userAgent,
        ]);

        $participant = $this->findByUuidOrFail($uuid);

        $this->audit->record(
            $session,
            EventType::PARTICIPANT_JOIN_REQUESTED,
            $identity?->id,
            $identity === null ? 'GUEST' : 'USER',
            (int) $participant['id'],
            $uuid,
            ['role' => $role, 'displayName' => $displayName],
        );

        return $participant;
    }

    /**
     * Host approves a waiting participant.
     *
     * The UPDATE is guarded on `status = 'REQUESTED'` so two hosts clicking
     * Allow at the same moment produce one approval and one no-op, rather than
     * two audit entries claiming both approved it.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function approve(array $session, string $participantUuid, RemoteIdentity $host): array
    {
        $participant = $this->findByUuidInSession((int) $session['id'], $participantUuid);
        $this->assertCanModerate($session, $host);

        if ($participant['status'] === 'APPROVED' || $participant['status'] === 'JOINED') {
            return $participant;
        }

        if ($participant['status'] !== 'REQUESTED') {
            throw ApiException::conflict('PARTICIPANT_NOT_WAITING', 'That person is no longer waiting to join.');
        }

        $this->db->table('remote_participants')
            ->where('id', $participant['id'])
            ->where('status', 'REQUESTED')
            ->update([
                'status'              => 'APPROVED',
                'approved_by_user_id' => $host->id,
                'updated_at'          => Clock::now(),
            ]);

        if ($this->db->affectedRows() === 0) {
            return $this->findByIdOrFail((int) $participant['id']);
        }

        $updated = $this->findByIdOrFail((int) $participant['id']);

        $this->audit->record(
            $session,
            EventType::PARTICIPANT_APPROVED,
            $host->id,
            'USER',
            (int) $updated['id'],
            (string) $updated['uuid'],
            ['role' => $updated['participant_role']],
        );

        return $updated;
    }

    /**
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function deny(array $session, string $participantUuid, RemoteIdentity $host): array
    {
        $participant = $this->findByUuidInSession((int) $session['id'], $participantUuid);
        $this->assertCanModerate($session, $host);

        $this->db->table('remote_participants')
            ->where('id', $participant['id'])
            ->whereIn('status', ['REQUESTED', 'APPROVED'])
            ->update([
                'status'     => 'DENIED',
                'updated_at' => Clock::now(),
            ]);

        $updated = $this->findByIdOrFail((int) $participant['id']);

        $this->audit->record(
            $session,
            EventType::PARTICIPANT_DENIED,
            $host->id,
            'USER',
            (int) $updated['id'],
            (string) $updated['uuid'],
        );

        return $updated;
    }

    /**
     * Approved → joined, once the peer connection is actually up.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function markJoined(array $session, string $participantUuid): array
    {
        $participant = $this->findByUuidInSession((int) $session['id'], $participantUuid);

        if (! in_array($participant['status'], ['APPROVED', 'JOINED'], true)) {
            throw ApiException::forbidden('PARTICIPANT_NOT_APPROVED', 'The host has not approved you for this session yet.');
        }

        if ($participant['status'] === 'APPROVED') {
            $this->db->table('remote_participants')->where('id', $participant['id'])->update([
                'status'           => 'JOINED',
                'joined_at'        => Clock::now(),
                'last_seen_at'     => Clock::now(),
                'connection_state' => 'CONNECTED',
                'updated_at'       => Clock::now(),
            ]);

            $updated = $this->findByIdOrFail((int) $participant['id']);

            $this->audit->record(
                $session,
                EventType::PARTICIPANT_JOINED,
                $updated['user_id'] !== null ? (int) $updated['user_id'] : null,
                $updated['user_id'] === null ? 'GUEST' : 'USER',
                (int) $updated['id'],
                (string) $updated['uuid'],
            );

            return $updated;
        }

        return $participant;
    }

    /** @param array<string, mixed> $session */
    public function leave(array $session, string $participantUuid): void
    {
        $participant = $this->findByUuidInSession((int) $session['id'], $participantUuid);

        $this->db->table('remote_participants')->where('id', $participant['id'])->update([
            'status'           => 'LEFT',
            'left_at'          => Clock::now(),
            'connection_state' => 'CLOSED',
            'is_sharing'       => false,
            'updated_at'       => Clock::now(),
        ]);

        $this->audit->record(
            $session,
            EventType::PARTICIPANT_LEFT,
            $participant['user_id'] !== null ? (int) $participant['user_id'] : null,
            $participant['user_id'] === null ? 'GUEST' : 'USER',
            (int) $participant['id'],
            (string) $participant['uuid'],
        );
    }

    public function closeAll(int $sessionId): void
    {
        $this->db->table('remote_participants')
            ->where('session_id', $sessionId)
            ->whereIn('status', ['REQUESTED', 'APPROVED', 'JOINED'])
            ->update([
                'status'           => 'LEFT',
                'left_at'          => Clock::now(),
                'connection_state' => 'CLOSED',
                'is_sharing'       => false,
                'updated_at'       => Clock::now(),
            ]);
    }

    public function setSharing(int $sessionId, int $userId, bool $sharing): void
    {
        $this->db->table('remote_participants')
            ->where('session_id', $sessionId)
            ->where('user_id', $userId)
            ->update(['is_sharing' => $sharing, 'updated_at' => Clock::now()]);
    }

    public function setMicrophone(int $sessionId, int $participantId, bool $enabled): void
    {
        $this->db->table('remote_participants')
            ->where('session_id', $sessionId)
            ->where('id', $participantId)
            ->update(['microphone_enabled' => $enabled, 'updated_at' => Clock::now()]);
    }

    public function touch(int $participantId, string $connectionState): void
    {
        $this->db->table('remote_participants')->where('id', $participantId)->update([
            'connection_state' => $connectionState,
            'last_seen_at'     => Clock::now(),
            'updated_at'       => Clock::now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function forSession(int $sessionId): array
    {
        return array_map([$this, 'castRow'], $this->db->table('remote_participants')
            ->where('session_id', $sessionId)
            ->orderBy('is_host', 'DESC')
            ->orderBy('created_at', 'ASC')
            ->get()
            ->getResultArray());
    }

    /**
     * Normalise a participant row's booleans (see SessionService::castRow for
     * why `(bool)` on a Postgres boolean is a trap).
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function castRow(array $row): array
    {
        foreach (['is_host', 'is_sharing', 'microphone_enabled'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = Presenter::bool($row[$column]);
            }
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function waitingFor(int $sessionId): array
    {
        return array_map([$this, 'castRow'], $this->db->table('remote_participants')
            ->where('session_id', $sessionId)
            ->where('status', 'REQUESTED')
            ->orderBy('requested_at', 'ASC')
            ->get()
            ->getResultArray());
    }

    /** @return array<string, mixed>|null */
    public function findByUser(int $sessionId, int $userId): ?array
    {
        $row = $this->db->table('remote_participants')
            ->where('session_id', $sessionId)
            ->where('user_id', $userId)
            ->get()
            ->getRowArray();

        return $row === null ? null : $this->castRow($row);
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        if (! Ids::isUuid($uuid)) {
            return null;
        }

        $row = $this->db->table('remote_participants')->where('uuid', $uuid)->get()->getRowArray();

        return $row === null ? null : $this->castRow($row);
    }

    /** @return array<string, mixed> */
    public function findByUuidOrFail(string $uuid): array
    {
        $row = $this->findByUuid($uuid);
        if ($row === null) {
            throw ApiException::notFound('That participant could not be found.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    public function findByIdOrFail(int $id): array
    {
        $row = $this->db->table('remote_participants')->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            throw ApiException::notFound('That participant could not be found.');
        }

        return $this->castRow($row);
    }

    /** @return array<string, mixed> */
    private function findByUuidInSession(int $sessionId, string $uuid): array
    {
        if (! Ids::isUuid($uuid)) {
            throw ApiException::notFound('That participant could not be found.');
        }

        $row = $this->db->table('remote_participants')
            ->where('session_id', $sessionId)
            ->where('uuid', $uuid)
            ->get()
            ->getRowArray();

        if ($row === null) {
            throw ApiException::notFound('That participant could not be found.');
        }

        return $this->castRow($row);
    }

    /**
     * Only the host decides who sees their screen. Not a company administrator,
     * not a support technician, not another viewer (§71).
     *
     * @param array<string, mixed> $session
     */
    private function assertCanModerate(array $session, RemoteIdentity $identity): void
    {
        if ((int) $session['owner_user_id'] === $identity->id) {
            return;
        }

        $self = $this->findByUser((int) $session['id'], $identity->id);
        if ($self !== null && $self['is_host'] === true) {
            return;
        }

        throw ApiException::forbidden(
            'NOT_SESSION_HOST',
            'Only the person sharing their screen can admit someone to this session.',
        );
    }

}
